<?php
class MadrixController extends IPSModule
{
    // Kommunikation Parent<->Child
    private $DataID = '{E1B4B2E6-9D1C-4D5E-9C8B-0D3B1A2C3D41}';

    // Child Module IDs
    private $MasterModuleID = '{B9F0A0B1-0F6E-4F7B-9B44-3E3F6A1D2C11}';
    private $DeckModuleID   = '{C6C8F4A2-6D0C-4A2B-9D2B-7F2A0B1C9E31}';

    public function Create()
    {
        parent::Create();

        // Verbindung
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 80);
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('ControlPath', '/RemoteCommands'); // anpassen falls nötig

        // Polling
        $this->RegisterPropertyInteger('PollSlow', 15);
        $this->RegisterPropertyInteger('PollFast', 2);
        $this->RegisterPropertyInteger('FastAfterChange', 30);
        $this->RegisterPropertyInteger('PendingTimeout', 10);

        $this->RegisterTimer('PollTimer', 0, 'MADRIX_Poll($_IPS[\'TARGET\']);');

        // Auto Devices
        $this->RegisterAttributeInteger('DevicesCategory', 0);
        $this->RegisterAttributeInteger('MasterInstance', 0);
        $this->RegisterAttributeInteger('DeckAInstance', 0);
        $this->RegisterAttributeInteger('DeckBInstance', 0);

        // Fast-Poll Fenster + Pending
        $this->SetBuffer('FastUntil', '0');
        $this->SetBuffer('Pending', json_encode(array()));

        // Group Name Cache
        $this->SetBuffer('GroupNameCache', json_encode(array()));

        // Deck place last values (für Description Pull bei Wechsel)
        $this->SetBuffer('LastDeckAPlace', '0');
        $this->SetBuffer('LastDeckBPlace', '0');

        // One-shot Name Sync
        $this->SetBuffer('ForceNameSync', '0');

        // Occupancy Cache (optional)
        $this->SetBuffer('OccMeta', json_encode(array())); // "SxPy" => array('ts'=>..., 'name'=>...)
        $this->SetBuffer('LastLoggedError', '');

        $this->EnsureProfiles();

        // Diagnose
        $this->RegisterVariableBoolean('Online', 'Online', 'MADRIX.Online', 1);
        $this->RegisterVariableString('LastError', 'LastError', '', 2);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();
        $this->EnsureDevices();

        $this->UpdatePollInterval(false);
        $this->SetFastPollForSeconds(5);
    }

    private function EnsureProfiles()
    {
        if (!IPS_VariableProfileExists('MADRIX.Online')) {
            IPS_CreateVariableProfile('MADRIX.Online', 0);
            IPS_SetVariableProfileAssociation('MADRIX.Online', 0, 'Offline', '', 0);
            IPS_SetVariableProfileAssociation('MADRIX.Online', 1, 'Online', '', 0);
        }
    }

    public function EnsureDevices()
    {
        $cat = (int)$this->ReadAttributeInteger('DevicesCategory');
        if ($cat == 0 || !IPS_ObjectExists($cat)) {
            $cat = IPS_CreateCategory();
            @IPS_SetName($cat, 'Devices');
            @IPS_SetParent($cat, $this->InstanceID);
            $this->WriteAttributeInteger('DevicesCategory', $cat);
        } else {
            if ((int)IPS_GetParent($cat) != (int)$this->InstanceID) {
                @IPS_SetParent($cat, $this->InstanceID);
            }
            if (IPS_GetName($cat) != 'Devices') {
                @IPS_SetName($cat, 'Devices');
            }
        }

        // Master
        $master = (int)$this->ReadAttributeInteger('MasterInstance');
        if ($master == 0 || !IPS_ObjectExists($master)) {
            $master = IPS_CreateInstance($this->MasterModuleID);
            $this->WriteAttributeInteger('MasterInstance', $master);
        }
        $this->PlaceInstance($master, $cat, 'Master');
        $this->TryConnectChild($master);

        // Deck A
        $deckA = (int)$this->ReadAttributeInteger('DeckAInstance');
        if ($deckA == 0 || !IPS_ObjectExists($deckA)) {
            $deckA = IPS_CreateInstance($this->DeckModuleID);
            $this->WriteAttributeInteger('DeckAInstance', $deckA);
        }
        $this->PlaceInstance($deckA, $cat, 'Deck A');
        @IPS_SetProperty($deckA, 'Deck', 'A');
        @IPS_ApplyChanges($deckA);
        $this->TryConnectChild($deckA);

        // Deck B
        $deckB = (int)$this->ReadAttributeInteger('DeckBInstance');
        if ($deckB == 0 || !IPS_ObjectExists($deckB)) {
            $deckB = IPS_CreateInstance($this->DeckModuleID);
            $this->WriteAttributeInteger('DeckBInstance', $deckB);
        }
        $this->PlaceInstance($deckB, $cat, 'Deck B');
        @IPS_SetProperty($deckB, 'Deck', 'B');
        @IPS_ApplyChanges($deckB);
        $this->TryConnectChild($deckB);
    }

    private function PlaceInstance($instanceId, $parentCatId, $desiredName)
    {
        if ($instanceId <= 0 || !IPS_ObjectExists($instanceId)) return;

        $curParent = (int)IPS_GetParent($instanceId);
        if ($curParent != (int)$parentCatId) {
            @IPS_SetParent($instanceId, $parentCatId);
        }

        if ($desiredName !== '' && IPS_GetName($instanceId) != $desiredName) {
            @IPS_SetName($instanceId, $desiredName);
        }
    }

    public function ForceNameSync()
    {
        $this->SetBuffer('ForceNameSync', '1');
        $this->SetFastPollForSeconds(10);
        $this->Poll();
    }

    // Child -> Parent
    public function ForwardData($JSONString)
    {
        if (!$this->Lock()) {
            $this->SetFastPollForSeconds(5);
            return json_encode(array('ok' => false, 'error' => 'lock'));
        }

        $resp = array('ok' => false);

        try {
            $data = json_decode($JSONString, true);
            if (!is_array($data)) {
                $this->Unlock();
                return json_encode(array('ok' => false, 'error' => 'bad json'));
            }
            if (!isset($data['DataID']) || $data['DataID'] != $this->DataID) {
                $this->Unlock();
                return json_encode(array('ok' => false, 'error' => 'bad dataid'));
            }

            $cmd = isset($data['cmd']) ? (string)$data['cmd'] : '';
            $arg = isset($data['arg']) ? $data['arg'] : null;

            $ok = true;

            if ($cmd == 'ForceNameSync') {
                $this->SetBuffer('ForceNameSync', '1');
                $this->SetFastPollForSeconds(10);
                $resp = array('ok' => true);

            } elseif ($cmd == 'SetMaster') {
                $v = $this->ClampInt((int)$arg, 0, 255);
                $this->HttpSet('SetMaster', (string)$v, $ok);
                $this->MarkPending('Master', $v);
                $resp = array('ok' => $ok);
                $this->AfterChange();

            } elseif ($cmd == 'SetBlackout') {
                $b = ((bool)$arg) ? 1 : 0;
                $this->HttpSet('SetBlackout', (string)$b, $ok);
                $this->MarkPending('Blackout', $b);
                $resp = array('ok' => $ok);
                $this->AfterChange();

            } elseif ($cmd == 'SetGroupValue') {
                if (is_array($arg)) {
                    $gid = isset($arg['id']) ? (int)$arg['id'] : 0;
                    $val = isset($arg['value']) ? (int)$arg['value'] : 0;
                    if ($gid > 0) {
                        $val = $this->ClampInt($val, 0, 255);
                        $this->HttpSet('SetGroupValue', $gid . '_' . $val, $ok);
                        $this->MarkPending('Group_' . $gid, $val);
                        $resp = array('ok' => $ok);
                        $this->AfterChange();
                    }
                }

            } elseif ($cmd == 'SetGlobalColorRGB') {
                if (is_array($arg)) {
                    $id = isset($arg['id']) ? (int)$arg['id'] : 0;
                    $r = isset($arg['r']) ? (int)$arg['r'] : 0;
                    $g = isset($arg['g']) ? (int)$arg['g'] : 0;
                    $b = isset($arg['b']) ? (int)$arg['b'] : 0;
                    $hex = isset($arg['hex']) ? (int)$arg['hex'] : null;
                    if ($id > 0) {
                        $r = $this->ClampInt($r, 0, 255);
                        $g = $this->ClampInt($g, 0, 255);
                        $b = $this->ClampInt($b, 0, 255);
                        $this->HttpSet('SetGlobalColorRed', $id . '_' . $r, $ok);
                        $this->HttpSet('SetGlobalColorGreen', $id . '_' . $g, $ok);
                        $this->HttpSet('SetGlobalColorBlue', $id . '_' . $b, $ok);
                        if ($hex !== null) {
                            $this->MarkPending('Color_' . $id, (int)$hex);
                        }
                        $resp = array('ok' => $ok);
                        $this->AfterChange();
                    }
                }

            } elseif ($cmd == 'SetDeckPlace') {
                if (is_array($arg)) {
                    $deck = isset($arg['deck']) ? (string)$arg['deck'] : '';
                    $storage = isset($arg['storage']) ? (int)$arg['storage'] : 1;
                    $place = isset($arg['place']) ? (int)$arg['place'] : 1;

                    $storage = $this->ClampInt($storage, 1, 256);
                    $place = $this->ClampInt($place, 1, 256);

                    $v = 'S' . $storage . 'P' . $place;

                    if ($deck == 'A') {
                        $this->HttpSet('SetStorageDeckA', $v, $ok);
                        $this->MarkPending('DeckA_Place', $place);
                    } elseif ($deck == 'B') {
                        $this->HttpSet('SetStorageDeckB', $v, $ok);
                        $this->MarkPending('DeckB_Place', $place);
                    }
                    $resp = array('ok' => $ok);
                    $this->AfterChange();
                }

            } elseif ($cmd == 'SetDeckSpeed') {
                if (is_array($arg)) {
                    $deck = isset($arg['deck']) ? (string)$arg['deck'] : '';
                    $speed = isset($arg['speed']) ? (float)$arg['speed'] : 0.0;
                    if ($speed < -10) $speed = -10;
                    if ($speed > 10) $speed = 10;
                    $sv = $this->FloatToHttp(round($speed, 1));

                    if ($deck == 'A') {
                        $this->HttpSet('SetStorageSpeedDeckA', $sv, $ok);
                        $this->MarkPending('DeckA_Speed', (float)$speed);
                    } elseif ($deck == 'B') {
                        $this->HttpSet('SetStorageSpeedDeckB', $sv, $ok);
                        $this->MarkPending('DeckB_Speed', (float)$speed);
                    }
                    $resp = array('ok' => $ok);
                    $this->AfterChange();
                }

            } else {
                $resp = array('ok' => false, 'error' => 'unknown cmd');
            }

        } catch (Exception $e) {
            $resp = array('ok' => false, 'error' => $e->getMessage());
        }

        $this->Unlock();
        return json_encode($resp);
    }

    public function Poll()
    {
        if (!$this->Lock()) {
            $this->SetFastPollForSeconds(5);
            return;
        }

        $forceNames = ((int)$this->GetBuffer('ForceNameSync')) === 1;
        if ($forceNames) $this->SetBuffer('ForceNameSync', '0');

        $isFast = $this->IsFastPoll();
        $pending = $this->GetPending();

        $ok = true;

        // Online-Check
        $ver = $this->HttpGet('GetVersionNumber', null, $ok);
        if (!$ok || $ver === '') {
            $this->SetOnline(false, 'Remote HTTP nicht erreichbar');
            $this->UpdatePollInterval(false);
            $this->Unlock();
            return;
        }
        $this->SetOnline(true, '');

        // Master
        $master = $this->ToIntSafe($this->HttpGet('GetMaster', null, $ok));
        $blackout = $this->ToIntSafe($this->HttpGet('GetBlackout', null, $ok));
        if (!$ok) {
            $this->SetOnline(false, 'Poll Master failed');
            $this->UpdatePollInterval(false);
            $this->Unlock();
            return;
        }
        $this->ResolvePendingIfReached('Master', $master, 0);
        $this->ResolvePendingIfReached('Blackout', $blackout, 0);

        // Decks
        $deckAInst = (int)$this->ReadAttributeInteger('DeckAInstance');
        $deckBInst = (int)$this->ReadAttributeInteger('DeckBInstance');

        $deckA = $this->PollDeck('A', $deckAInst, $forceNames);
        $deckB = $this->PollDeck('B', $deckBInst, $forceNames);

        // Groups/Colors
        $groupsPayload = array();
        $colorsPayload = array();

        if (!$isFast) {
            $groupsPayload = $this->PollAllGroups($forceNames);
            $colorsPayload = $this->PollAllColorsFromMasterConfig();
        } else {
            $groupsPayload = $this->PollPendingGroups($pending, $forceNames);
            $colorsPayload = $this->PollPendingColors($pending);
        }

        // Occupied Meta (nur slow)
        $occupiedMeta = array();
        if (!$isFast) {
            $occupiedMeta = $this->BuildOccupiedPlacesMeta($forceNames);
        }

        $payload = array(
            'DataID' => $this->DataID,
            'type' => 'status',
            'mode' => $isFast ? 'fast' : 'slow',
            'master' => array(
                'master' => $master,
                'blackout' => ($blackout == 1) ? 1 : 0
            ),
            'groups' => $groupsPayload,
            'colors' => $colorsPayload,
            'decks' => array($deckA, $deckB),
            'occupied' => $occupiedMeta
        );

        @ $this->SendDataToChildren(json_encode($payload));

        $this->UpdatePollInterval(false);
        $this->Unlock();
    }

    // ===== OCCUPIED META =====
    // Storage-Status: GetStoragePlaceFullState=S2
    // Place-Status:   GetStoragePlaceFullState=S2P5
    private function BuildOccupiedPlacesMeta($forceNames)
    {
        $ok = true;
        $meta = array();

        // Warnung: Das sind worst-case 256 + (occupiedStorages*256) Requests
        // Wir machen das nur im Slow-Poll und nur für belegte Storages.

        for ($s = 1; $s <= 256; $s++) {
            // Storage full state über GetStoragePlaceFullState=Sx
            $full = $this->ToIntSafe($this->HttpGet('GetStoragePlaceFullState', 'S' . $s, $ok));
            if (!$ok) continue;
            if ($full != 1) continue;

            for ($p = 1; $p <= 256; $p++) {
                // Place full state über GetStoragePlaceFullState=SxPy
                $pf = $this->ToIntSafe($this->HttpGet('GetStoragePlaceFullState', 'S' . $s . 'P' . $p, $ok));
                if (!$ok) continue;
                if ($pf != 1) continue;

                $key = 'S' . $s . 'P' . $p;

                // Thumb timestamp als Change-Trigger (nur wenn geändert)
                $ts = trim((string)$this->HttpGet('GetStoragePlaceThumbTimeStamp', $key, $ok));
                if (!$ok) $ts = '';

                $cached = $this->GetOccMeta();
                $cachedTs = isset($cached[$key]) && isset($cached[$key]['ts']) ? (string)$cached[$key]['ts'] : '';
                $cachedName = isset($cached[$key]) && isset($cached[$key]['name']) ? (string)$cached[$key]['name'] : '';

                $name = $cachedName;
                $needName = $forceNames || $cachedName === '' || ($ts !== '' && $ts !== $cachedTs);

                if ($needName) {
                    $name = trim((string)$this->HttpGet('GetStoragePlaceDescription', $key, $ok));
                    if (!$ok) $name = '';
                    if (!isset($cached[$key]) || !is_array($cached[$key])) $cached[$key] = array();
                    $cached[$key]['ts'] = $ts;
                    if ($name !== '') $cached[$key]['name'] = $name;
                    $this->SetOccMeta($cached);
                }

                $meta[] = array(
                    'storage' => $s,
                    'place' => $p,
                    'desc' => (string)$name
                );
            }
        }

        return $meta;
    }

    private function GetOccMeta()
    {
        $raw = $this->GetBuffer('OccMeta');
        $arr = json_decode($raw, true);
        if (!is_array($arr)) $arr = array();
        return $arr;
    }

    private function SetOccMeta($arr)
    {
        if (!is_array($arr)) $arr = array();
        $this->SetBuffer('OccMeta', json_encode($arr));
    }

    // ===== Poll Helpers =====

    private function PollDeck($deck, $deckInstance, $forceDesc)
    {
        $ok = true;
        $place = 0;
        $speed = 0.0;

        if ($deck == 'A') {
            $place = $this->ToIntSafe($this->HttpGet('GetStoragePlaceDeckA', null, $ok));
            $speed = (float)$this->ToFloat($this->HttpGet('GetStorageSpeedDeckA', null, $ok));
            $this->ResolvePendingIfReached('DeckA_Place', $place, 0);
            $this->ResolvePendingIfReached('DeckA_Speed', $speed, 0.05);
        } else {
            $place = $this->ToIntSafe($this->HttpGet('GetStoragePlaceDeckB', null, $ok));
            $speed = (float)$this->ToFloat($this->HttpGet('GetStorageSpeedDeckB', null, $ok));
            $this->ResolvePendingIfReached('DeckB_Place', $place, 0);
            $this->ResolvePendingIfReached('DeckB_Speed', $speed, 0.05);
        }

        $storage = 1;
        if ($deckInstance > 0 && IPS_ObjectExists($deckInstance)) {
            $storage = (int)@IPS_GetProperty($deckInstance, 'Storage');
            $storage = $this->ClampInt($storage, 1, 256);
        }

        $desc = '';
        $lastKey = ($deck == 'A') ? 'LastDeckAPlace' : 'LastDeckBPlace';
        $lastPlace = (int)$this->GetBuffer($lastKey);

        $needDesc = $forceDesc || ($place > 0 && $place != $lastPlace);
        if ($needDesc && $place > 0) {
            $desc = (string)$this->HttpGet('GetStoragePlaceDescription', 'S' . $storage . 'P' . $place, $ok);
            if (!$ok) $desc = '';
            $desc = trim($desc);
            $this->SetBuffer($lastKey, (string)$place);
        }

        return array(
            'deck' => $deck,
            'place' => $place,
            'speed' => $speed,
            'storage' => $storage,
            'desc' => $desc
        );
    }

    private function PollAllGroups($forceNames)
    {
        $ok = true;
        $groupCount = $this->ToIntSafe($this->HttpGet('GetGroupCount', null, $ok));
        if (!$ok || $groupCount <= 0) return array();

        $cache = $this->GetGroupNameCache();
        $result = array();

        for ($i = 1; $i <= $groupCount; $i++) {
            $gid = $this->ToIntSafe($this->HttpGet('GetGroupId', (string)$i, $ok));
            if (!$ok || $gid <= 0) continue;

            $name = isset($cache[(string)$gid]) ? (string)$cache[(string)$gid] : '';
            if ($forceNames || $name === '') {
                $n = trim((string)$this->HttpGet('GetGroupDisplayName', (string)$gid, $ok));
                if ($n === '') $n = 'Group ' . $gid;
                $name = $n;
                $cache[(string)$gid] = $name;
            }

            $val = $this->ToIntSafe($this->HttpGet('GetGroupValue', (string)$gid, $ok));
            if (!$ok) $val = 0;

            $this->ResolvePendingIfReached('Group_' . $gid, $val, 0);
            $result[] = array('id' => $gid, 'name' => $name, 'val' => $val);
        }

        $this->SetGroupNameCache($cache);
        return $result;
    }

    private function PollPendingGroups($pending, $forceNames)
    {
        $ok = true;
        $cache = $this->GetGroupNameCache();
        $result = array();

        foreach ($pending as $key => $p) {
            if (substr($key, 0, 6) != 'Group_') continue;
            $gid = (int)substr($key, 6);
            if ($gid <= 0) continue;

            $name = isset($cache[(string)$gid]) ? (string)$cache[(string)$gid] : '';
            if ($forceNames || $name === '') {
                $n = trim((string)$this->HttpGet('GetGroupDisplayName', (string)$gid, $ok));
                if ($n === '') $n = 'Group ' . $gid;
                $name = $n;
                $cache[(string)$gid] = $name;
            }

            $val = $this->ToIntSafe($this->HttpGet('GetGroupValue', (string)$gid, $ok));
            if (!$ok) $val = 0;

            $this->ResolvePendingIfReached('Group_' . $gid, $val, 0);
            $result[] = array('id' => $gid, 'name' => $name, 'val' => $val);
        }

        $this->SetGroupNameCache($cache);
        return $result;
    }

    private function PollAllColorsFromMasterConfig()
    {
        $ids = $this->GetGlobalColorIDsFromMaster();
        if (count($ids) == 0) return array();

        $ok = true;
        $result = array();
        foreach ($ids as $cid) {
            $hex = trim((string)$this->HttpGet('GetGlobalColorRGB', (string)$cid, $ok));
            if ($ok && strlen($hex) == 6) {
                $hexInt = hexdec($hex);
                $this->ResolvePendingIfReached('Color_' . $cid, $hexInt, 0);
                $result[] = array('id' => $cid, 'hex' => $hexInt);
            }
        }
        return $result;
    }

    private function PollPendingColors($pending)
    {
        $ok = true;
        $result = array();

        foreach ($pending as $key => $p) {
            if (substr($key, 0, 6) != 'Color_') continue;
            $cid = (int)substr($key, 6);
            if ($cid <= 0) continue;

            $hex = trim((string)$this->HttpGet('GetGlobalColorRGB', (string)$cid, $ok));
            if ($ok && strlen($hex) == 6) {
                $hexInt = hexdec($hex);
                $this->ResolvePendingIfReached('Color_' . $cid, $hexInt, 0);
                $result[] = array('id' => $cid, 'hex' => $hexInt);
            }
        }

        return $result;
    }

    // ===== Pending =====

    private function MarkPending($key, $desired)
    {
        $pending = $this->GetPending();
        $timeout = (int)$this->ReadPropertyInteger('PendingTimeout');
        if ($timeout < 2) $timeout = 10;

        $pending[$key] = array(
            'desired' => $desired,
            'deadline' => time() + $timeout
        );
        $this->SetBuffer('Pending', json_encode($pending));
    }

    private function ResolvePendingIfReached($key, $actual, $epsilon)
    {
        $pending = $this->GetPending();
        if (!isset($pending[$key])) return;

        $p = $pending[$key];
        $deadline = isset($p['deadline']) ? (int)$p['deadline'] : 0;
        $desired = isset($p['desired']) ? $p['desired'] : null;

        if ($deadline > 0 && time() > $deadline) {
            unset($pending[$key]);
            $this->SetBuffer('Pending', json_encode($pending));
            return;
        }

        if ($this->Matches($actual, $desired, $epsilon)) {
            unset($pending[$key]);
            $this->SetBuffer('Pending', json_encode($pending));
        }
    }

    private function GetPending()
    {
        $raw = $this->GetBuffer('Pending');
        $arr = json_decode($raw, true);
        if (!is_array($arr)) $arr = array();
        return $arr;
    }

    private function Matches($a, $b, $epsilon)
    {
        if ($b === null) return false;
        if ($epsilon <= 0) return ((string)$a === (string)$b);
        return abs((float)$a - (float)$b) <= (float)$epsilon;
    }

    // ===== Cache =====

    private function GetGroupNameCache()
    {
        $raw = $this->GetBuffer('GroupNameCache');
        $arr = json_decode($raw, true);
        if (!is_array($arr)) $arr = array();
        return $arr;
    }

    private function SetGroupNameCache($arr)
    {
        if (!is_array($arr)) $arr = array();
        $this->SetBuffer('GroupNameCache', json_encode($arr));
    }

    // ===== Colors Config =====

    private function GetGlobalColorIDsFromMaster()
    {
        $masterInst = (int)$this->ReadAttributeInteger('MasterInstance');
        if ($masterInst <= 0 || !IPS_ObjectExists($masterInst)) return array();

        $raw = @IPS_GetProperty($masterInst, 'GlobalColors');
        if (!is_string($raw)) $raw = '[]';

        $cfg = json_decode($raw, true);
        if (!is_array($cfg)) $cfg = array();

        $ids = array();
        foreach ($cfg as $entry) {
            if (!is_array($entry)) continue;
            $cid = isset($entry['GlobalColorId']) ? (int)$entry['GlobalColorId'] : 0;
            if ($cid > 0) $ids[] = $cid;
        }
        return array_values(array_unique($ids));
    }

    // ===== After change =====

    private function AfterChange()
    {
        $sec = (int)$this->ReadPropertyInteger('FastAfterChange');
        if ($sec < 0) $sec = 0;
        if ($sec > 0) $this->SetFastPollForSeconds($sec);
    }

    // ===== HTTP =====

    private function HttpGet($function, $valueOrNull, &$ok)
    {
        return $this->HttpRequest($function, $valueOrNull, $ok);
    }

    private function HttpSet($function, $value, &$ok)
    {
        $this->HttpRequest($function, $value, $ok);
    }

    private function HttpRequest($function, $valueOrNull, &$ok)
    {
        $ok = true;

        $host = trim($this->ReadPropertyString('Host'));
        $port = (int)$this->ReadPropertyInteger('Port');
        $basePath = trim($this->ReadPropertyString('ControlPath'));

        if ($host === '' || $port <= 0 || $port > 65535) {
            $ok = false;
            $this->SetOnline(false, 'Host/Port nicht konfiguriert');
            return '';
        }

        if ($basePath === '') $basePath = '/RemoteCommands';
        if (substr($basePath, 0, 1) != '/') $basePath = '/' . $basePath;

        if ($valueOrNull === null) {
            $path = $basePath . '/' . rawurlencode($function);
        } else {
            $path = $basePath . '/' . rawurlencode($function) . '=' . rawurlencode((string)$valueOrNull);
        }

        $url = 'http://' . $host . ':' . $port . $path;

        $headers = array('Connection: close');
        $user = trim($this->ReadPropertyString('Username'));
        $pass = (string)$this->ReadPropertyString('Password');
        if ($user !== '' || $pass !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($user . ':' . $pass);
        }

        $ctx = stream_context_create(array(
            'http' => array(
                'method'  => 'GET',
                'header'  => implode("\r\n", $headers) . "\r\n",
                'timeout' => 3
            )
        ));

        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            $ok = false;
            $this->SetOnline(false, 'HTTP failed: ' . $function);
            return '';
        }

        return trim((string)$data);
    }

    // ===== Polling/Diagnostics/Locks =====

    private function SetOnline($online, $err)
    {
        // LastError + Log nur bei Änderung (kein Spam)
        $vidOnline = $this->GetIDForIdent('Online');
        if ($vidOnline > 0) {
            $cur = GetValueBoolean($vidOnline);
            if ((bool)$online !== (bool)$cur) {
                $this->SetValue('Online', (bool)$online);
            }
        }

        $newErr = (string)$err;
        $vidErr = $this->GetIDForIdent('LastError');
        $oldErr = '';
        if ($vidErr > 0) $oldErr = (string)GetValue($vidErr);

        if ($newErr !== $oldErr) {
            if ($vidErr > 0) $this->SetValue('LastError', $newErr);
            if ($newErr !== '') $this->LogMessage($newErr, KL_WARNING);
        }
    }

    private function SetFastPollForSeconds($sec)
    {
        $s = (int)$sec;
        if ($s <= 0) return;

        $until = time() + $s;
        $cur = (int)$this->GetBuffer('FastUntil');
        if ($until > $cur) {
            $this->SetBuffer('FastUntil', (string)$until);
        }
        $this->UpdatePollInterval(false);
    }

    private function IsFastPoll()
    {
        return time() <= (int)$this->GetBuffer('FastUntil');
    }

    private function UpdatePollInterval($forceFast)
    {
        $slow = (int)$this->ReadPropertyInteger('PollSlow');
        $fast = (int)$this->ReadPropertyInteger('PollFast');
        if ($slow < 5) $slow = 5;
        if ($fast < 2) $fast = 2;

        $interval = $slow;
        if ($forceFast || $this->IsFastPoll()) $interval = $fast;

        $this->SetTimerInterval('PollTimer', $interval * 1000);
    }

    private function Lock()
    {
        $key = 'MADRIX_LOCK_' . $this->InstanceID;
        return @IPS_SemaphoreEnter($key, 100);
    }

    private function Unlock()
    {
        $key = 'MADRIX_LOCK_' . $this->InstanceID;
        @IPS_SemaphoreLeave($key);
    }

    private function TryConnectChild($childId)
    {
        if ($childId <= 0 || !IPS_ObjectExists($childId)) return;

        if (function_exists('IPS_ConnectInstance')) {
            @IPS_ConnectInstance($childId, $this->InstanceID);
        }
    }

    private function FloatToHttp($f)
    {
        return str_replace(',', '.', (string)$f);
    }

    private function ToFloat($s)
    {
        return (float)str_replace(',', '.', (string)$s);
    }

    private function ToIntSafe($s)
    {
        $t = trim((string)$s);
        if ($t === '') return 0;
        if ($t === 'true') return 1;
        if ($t === 'false') return 0;
        return (int)$t;
    }

    private function ClampInt($v, $min, $max)
    {
        $x = (int)$v;
        if ($x < (int)$min) $x = (int)$min;
        if ($x > (int)$max) $x = (int)$max;
        return $x;
    }
}
