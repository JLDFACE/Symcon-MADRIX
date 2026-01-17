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

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 80);
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');

        $this->RegisterPropertyInteger('PollSlow', 15);
        $this->RegisterPropertyInteger('PollFast', 2);
        $this->RegisterPropertyInteger('FastAfterChange', 30);
        $this->RegisterPropertyInteger('PendingTimeout', 10);

        // Scan (Chunked)
        $this->RegisterPropertyInteger('ScanChunk', 8);

        $this->RegisterTimer('PollTimer', 0, 'MADRIX_Poll($_IPS[\'TARGET\']);');
        $this->RegisterTimer('ScanTimer', 0, 'MADRIX_ScanTick($_IPS[\'TARGET\']);');

        $this->RegisterAttributeInteger('DevicesCategory', 0);
        $this->RegisterAttributeInteger('MasterInstance', 0);
        $this->RegisterAttributeInteger('DeckAInstance', 0);
        $this->RegisterAttributeInteger('DeckBInstance', 0);

        // Fast-Poll Zeitfenster
        $this->SetBuffer('FastUntil', '0');

        // Pending-Map im Controller:
        // keys: Master, Blackout, Group_<id>, Color_<id>, DeckA_Place, DeckA_Speed, DeckB_Place, DeckB_Speed
        $this->SetBuffer('Pending', json_encode(array()));

        // Caches
        $this->SetBuffer('GroupNameCache', json_encode(array())); // id(string) => name
        $this->SetBuffer('LastDeckAPlace', '0');
        $this->SetBuffer('LastDeckBPlace', '0');

        // Name sync Steuerung
        $this->SetBuffer('ForceNameSync', '0'); // 1 => beim nächsten Poll Descriptions/Names holen

        // Place Meta Cache: key "SxPy" => {ts:"...", desc:"...", occ:0/1}
        $this->SetBuffer('PlaceMetaCache', json_encode(array()));

        // Scan-State
        $this->SetBuffer('ScanState', json_encode(array()));

        // Log-Spam vermeiden
        $this->SetBuffer('LastLoggedError', '');

        // Eigene Profile anlegen
        $this->EnsureProfiles();

        // Diagnose
        $this->RegisterVariableBoolean('Online', 'Online', 'MADRIX.Online', 1);
        $this->RegisterVariableString('LastError', 'LastError', '', 2);

        // Scan Diagnose/UI
        $this->RegisterVariableBoolean('ScanRunning', 'Place Scan Running', 'MADRIX.Switch', 20);
        $this->RegisterVariableInteger('ScanProgress', 'Place Scan Progress (%)', 'MADRIX.Percent', 21);
        $this->RegisterVariableString('ScanInfo', 'Place Scan Info', '', 22);
        $this->RegisterVariableString('ScanLastRun', 'Place Scan Last Run', '', 23);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();
        $this->EnsureDevices();
        $this->UpdatePollInterval(false);

        if (trim($this->ReadPropertyString('Host')) === '') {
            $this->SetOnline(false, 'Host/Port nicht konfiguriert');
            $this->SetTimerInterval('PollTimer', 0);
        } else {
            $this->SetFastPollForSeconds(5);
        }
    }

    private function EnsureProfiles()
    {
        if (!IPS_VariableProfileExists('MADRIX.Online')) {
            IPS_CreateVariableProfile('MADRIX.Online', 0);
            IPS_SetVariableProfileAssociation('MADRIX.Online', 0, 'Offline', '', 0);
            IPS_SetVariableProfileAssociation('MADRIX.Online', 1, 'Online', '', 0);
        }

        if (!IPS_VariableProfileExists('MADRIX.Percent')) {
            IPS_CreateVariableProfile('MADRIX.Percent', 1);
            IPS_SetVariableProfileValues('MADRIX.Percent', 0, 100, 1);
            IPS_SetVariableProfileSuffix('MADRIX.Percent', ' %');
        }

        if (!IPS_VariableProfileExists('MADRIX.Switch')) {
            IPS_CreateVariableProfile('MADRIX.Switch', 0);
            IPS_SetVariableProfileAssociation('MADRIX.Switch', 0, 'Aus', '', 0);
            IPS_SetVariableProfileAssociation('MADRIX.Switch', 1, 'Ein', '', 0);
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
            // Parent/Name nur setzen, wenn nötig (verhindert "Root kann nicht geändert werden")
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
            $master = IPS_CreateInstance('{B9F0A0B1-0F6E-4F7B-9B44-3E3F6A1D2C11}');
            $this->WriteAttributeInteger('MasterInstance', $master);
        }
        $this->PlaceInstance($master, $cat, 'Master');
        $this->TryConnectChild($master);

        // Deck A
        $deckA = (int)$this->ReadAttributeInteger('DeckAInstance');
        if ($deckA == 0 || !IPS_ObjectExists($deckA)) {
            $deckA = IPS_CreateInstance('{C6C8F4A2-6D0C-4A2B-9D2B-7F2A0B1C9E31}');
            $this->WriteAttributeInteger('DeckAInstance', $deckA);
        }
        $this->PlaceInstance($deckA, $cat, 'Deck A');
        @IPS_SetProperty($deckA, 'Deck', 'A');
        @IPS_ApplyChanges($deckA);
        $this->TryConnectChild($deckA);

        // Deck B
        $deckB = (int)$this->ReadAttributeInteger('DeckBInstance');
        if ($deckB == 0 || !IPS_ObjectExists($deckB)) {
            $deckB = IPS_CreateInstance('{C6C8F4A2-6D0C-4A2B-9D2B-7F2A0B1C9E31}');
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
        $this->SetFastPollForSeconds(5);
        $this->Poll();
    }

    // ===== Place Scan (belegte Places) =====

    public function StartPlaceScan()
    {
        if (trim($this->ReadPropertyString('Host')) === '') {
            $this->LogMessage('Place Scan: Host/Port nicht konfiguriert', KL_WARNING);
            return;
        }

        $this->LogMessage('Place Scan startet. Warnung: Es werden Storages/Places per RemoteHTTP iteriert. Abhängig von Anzahl belegter Storages/Places kann dies von Sekunden bis Minuten dauern.', KL_WARNING);

        $state = array(
            'phase' => 'storages',
            's' => 1,
            'occupiedStorages' => array(),
            'storageIndex' => 0,
            'currentStorage' => 0,
            'p' => 1,
            'done' => 0,
            'total' => 1
        );

        $this->SetBuffer('SkipStorageFullState', '0');
        $this->SetBuffer('ScanState', json_encode($state));
        $this->SetValue('ScanRunning', true);
        $this->SetValue('ScanProgress', 0);
        $this->SetValue('ScanInfo', 'Scanning storages...');
        $this->SetTimerInterval('ScanTimer', 200);
    }

    public function ScanTick()
    {
        if (!$this->Lock()) return;

        $state = $this->GetJsonBuffer('ScanState');
        if (!is_array($state) || !isset($state['phase'])) {
            $this->StopScan('ScanState invalid');
            $this->Unlock();
            return;
        }

        $chunk = (int)$this->ReadPropertyInteger('ScanChunk');
        if ($chunk < 1) $chunk = 1;
        if ($chunk > 32) $chunk = 32;

        $ok = true;

        if ($state['phase'] == 'storages') {
            if ($this->GetBuffer('SkipStorageFullState') === '1') {
                $occ = $this->GetDeckStorages();
                if (count($occ) == 0) {
                    $this->StopScan('GetStorageFullState nicht verfuegbar und keine Deck-Storages gesetzt.');
                    $this->Unlock();
                    return;
                }

                $state['phase'] = 'places';
                $state['storageIndex'] = 0;
                $state['currentStorage'] = (int)$occ[0];
                $state['p'] = 1;
                $state['occupiedStorages'] = $occ;

                $state['done'] = 256;
                $state['total'] = 256 + (count($occ) * 256);

                $this->SetValue('ScanInfo', 'GetStorageFullState nicht verfuegbar, nutze Deck-Storages: ' . count($occ));
                $this->SetBuffer('ScanState', json_encode($state));
                $this->Unlock();
                return;
            }

            for ($i = 0; $i < $chunk; $i++) {
                $s = (int)$state['s'];
                if ($s > 256) {
                    $occ = isset($state['occupiedStorages']) && is_array($state['occupiedStorages']) ? $state['occupiedStorages'] : array();
                    $state['phase'] = 'places';
                    $state['storageIndex'] = 0;
                    $state['currentStorage'] = (count($occ) > 0) ? (int)$occ[0] : 0;
                    $state['p'] = 1;

                    $state['done'] = 0;
                    $state['total'] = 256 + (count($occ) * 256);

                    $this->SetValue('ScanInfo', 'Scanning places in occupied storages: ' . count($occ));
                    break;
                }

                $ok = true;
                $full = (int)$this->HttpGetSilent('GetStorageFullState', 'S' . $s, $ok);
                if (!$ok) {
                    $this->SetBuffer('SkipStorageFullState', '1');
                    $this->LogMessage('GetStorageFullState nicht verfuegbar, nutze Deck-Storages als Fallback.', KL_WARNING);
                    $this->Unlock();
                    return;
                }
                if ($ok && $full == 1) {
                    $state['occupiedStorages'][] = $s;
                }

                $state['s'] = $s + 1;
                $state['done'] = (int)$state['s'] - 1;

                $this->UpdateScanProgress($state);
            }

            $this->SetBuffer('ScanState', json_encode($state));
            $this->Unlock();
            return;
        }

        if ($state['phase'] == 'places') {
            $occ = isset($state['occupiedStorages']) && is_array($state['occupiedStorages']) ? $state['occupiedStorages'] : array();
            if (count($occ) == 0) {
                $this->StopScan('No occupied storages found.');
                $this->Unlock();
                return;
            }

            $idx = (int)$state['storageIndex'];
            if ($idx >= count($occ)) {
                $this->StopScan('Done');
                $this->Unlock();
                return;
            }

            $storage = (int)$state['currentStorage'];
            if ($storage <= 0) $storage = (int)$occ[$idx];

            for ($i = 0; $i < $chunk; $i++) {
                $p = (int)$state['p'];
                if ($p > 256) {
                    $idx++;
                    $state['storageIndex'] = $idx;
                    if ($idx >= count($occ)) {
                        $this->StopScan('Done');
                        $this->Unlock();
                        return;
                    }
                    $state['currentStorage'] = (int)$occ[$idx];
                    $state['p'] = 1;
                    $storage = (int)$state['currentStorage'];
                    $p = 1;
                }

                $key = 'S' . $storage . 'P' . $p;

                $fs = (int)$this->HttpGet('GetStoragePlaceFullState', $key, $ok);
                if ($ok && $fs == 1) {
                    $this->ResolvePlaceMetaInternal($storage, $p, true);
                } else {
                    $this->MarkPlaceEmpty($storage, $p);
                }

                $state['p'] = $p + 1;
                $state['done'] = 256 + ($idx * 256) + $p;

                $this->UpdateScanProgress($state);
            }

            $this->SetBuffer('ScanState', json_encode($state));
            $this->Unlock();
            return;
        }

        $this->StopScan('Unknown phase');
        $this->Unlock();
    }

    private function StopScan($msg)
    {
        $this->SetTimerInterval('ScanTimer', 0);
        $this->SetValue('ScanRunning', false);
        $this->SetValue('ScanInfo', (string)$msg);
        $this->SetValue('ScanProgress', 100);
        $this->SetValue('ScanLastRun', date('Y-m-d H:i:s'));
        $this->SetBuffer('ScanState', json_encode(array()));

        $a = (int)$this->ReadAttributeInteger('DeckAInstance');
        $b = (int)$this->ReadAttributeInteger('DeckBInstance');

        $storages = array();
        if ($a > 0 && IPS_ObjectExists($a)) {
            $sa = (int)@IPS_GetProperty($a, 'Storage');
            if ($sa > 0) $storages[] = $sa;
        }
        if ($b > 0 && IPS_ObjectExists($b)) {
            $sb = (int)@IPS_GetProperty($b, 'Storage');
            if ($sb > 0) $storages[] = $sb;
        }

        $storages = array_values(array_unique($storages));
        foreach ($storages as $s) {
            $this->PushStorageMetaInternal((int)$s);
        }
    }

    private function UpdateScanProgress($state)
    {
        $done = isset($state['done']) ? (int)$state['done'] : 0;
        $total = isset($state['total']) ? (int)$state['total'] : 1;
        if ($total <= 0) $total = 1;

        $pct = (int)round(($done / $total) * 100);
        if ($pct < 0) $pct = 0;
        if ($pct > 99 && isset($state['phase']) && $state['phase'] != 'done') $pct = 99;

        $this->SetValue('ScanProgress', $pct);
    }

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

            if ($cmd == 'ResolvePlaceMeta') {
                if (is_array($arg)) {
                    $storage = isset($arg['storage']) ? (int)$arg['storage'] : 1;
                    $place   = isset($arg['place']) ? (int)$arg['place'] : 1;
                    $storage = $this->ClampInt($storage, 1, 256);
                    $place   = $this->ClampInt($place, 1, 256);

                    $changed = $this->ResolvePlaceMetaInternal($storage, $place, false);
                    $resp = array('ok' => true, 'changed' => $changed ? 1 : 0);
                } else {
                    $resp = array('ok' => false, 'error' => 'bad arg');
                }

            } elseif ($cmd == 'PushStorageMeta') {
                if (is_array($arg)) {
                    $storage = isset($arg['storage']) ? (int)$arg['storage'] : 1;
                    $storage = $this->ClampInt($storage, 1, 256);
                    $this->PushStorageMetaInternal($storage);
                    $resp = array('ok' => true);
                } else {
                    $resp = array('ok' => false, 'error' => 'bad arg');
                }

            } elseif ($cmd == 'SetMaster') {
                $v = $this->ClampInt((int)$arg, 0, 255);
                $this->HttpSet('SetMaster', (string)$v, $ok);
                if ($ok) {
                    $this->MarkPending('Master', $v);
                    $this->AfterChange();
                }
                $resp = array('ok' => $ok);

            } elseif ($cmd == 'SetBlackout') {
                $b = ((bool)$arg) ? 1 : 0;
                $this->HttpSet('SetBlackout', (string)$b, $ok);
                if ($ok) {
                    $this->MarkPending('Blackout', $b);
                    $this->AfterChange();
                }
                $resp = array('ok' => $ok);

            } elseif ($cmd == 'SetGroupValue') {
                if (is_array($arg)) {
                    $gid = isset($arg['id']) ? (int)$arg['id'] : 0;
                    $val = isset($arg['value']) ? (int)$arg['value'] : 0;
                    if ($gid > 0) {
                        $val = $this->ClampInt($val, 0, 255);
                        $this->HttpSet('SetGroupValue', $gid . '_' . $val, $ok);
                        if ($ok) {
                            $this->MarkPending('Group_' . $gid, $val);
                            $this->AfterChange();
                        }
                        $resp = array('ok' => $ok);
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
                        if ($ok && $hex !== null) {
                            $this->MarkPending('Color_' . $id, (int)$hex);
                            $this->AfterChange();
                        }
                        $resp = array('ok' => $ok);
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
                        if ($ok) {
                            $this->MarkPending('DeckA_Place', $place);
                            $this->AfterChange();
                        }
                    } elseif ($deck == 'B') {
                        $this->HttpSet('SetStorageDeckB', $v, $ok);
                        if ($ok) {
                            $this->MarkPending('DeckB_Place', $place);
                            $this->AfterChange();
                        }
                    }
                    $resp = array('ok' => $ok);
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
                        if ($ok) {
                            $this->MarkPending('DeckA_Speed', (float)$speed);
                            $this->AfterChange();
                        }
                    } elseif ($deck == 'B') {
                        $this->HttpSet('SetStorageSpeedDeckB', $sv, $ok);
                        if ($ok) {
                            $this->MarkPending('DeckB_Speed', (float)$speed);
                            $this->AfterChange();
                        }
                    }
                    $resp = array('ok' => $ok);
                }

            } elseif ($cmd == 'SetFadeType') {
                $t = trim((string)$arg);
                if ($t !== '') {
                    $this->HttpSet('SetFadeType', $t, $ok);
                    if ($ok) {
                        $this->MarkPending('FadeType', $t);
                        $this->AfterChange();
                    }
                } else {
                    $ok = false;
                }
                $resp = array('ok' => $ok);

            } elseif ($cmd == 'SetFadeValue') {
                $v = $this->ClampInt((int)$arg, 0, 255);
                $this->HttpSet('SetFadeValue', (string)$v, $ok);
                if ($ok) {
                    $this->MarkPending('FadeValue', $v);
                    $this->AfterChange();
                }
                $resp = array('ok' => $ok);

            } else {
                $resp = array('ok' => false, 'error' => 'unknown cmd');
            }
        } catch (Exception $e) {
            $resp = array('ok' => false, 'error' => $e->getMessage());
        }

        $this->Unlock();
        return json_encode($resp);
    }

    private function ResolvePlaceMetaInternal($storage, $place, $forceOcc)
    {
        $ok = true;

        $key = 'S' . (int)$storage . 'P' . (int)$place;

        $cache = $this->GetJsonBuffer('PlaceMetaCache');

        $oldTs = '';
        $oldDesc = '';
        $oldOcc = null;

        if (isset($cache[$key]) && is_array($cache[$key])) {
            $oldTs = isset($cache[$key]['ts']) ? (string)$cache[$key]['ts'] : '';
            $oldDesc = isset($cache[$key]['desc']) ? (string)$cache[$key]['desc'] : '';
            $oldOcc = isset($cache[$key]['occ']) ? (int)$cache[$key]['occ'] : null;
        }

        $ts = (string)$this->HttpGet('GetStoragePlaceThumbTimeStamp', $key, $ok);
        $ts = trim($ts);
        if (!$ok || $ts === '') {
            return false;
        }

        $occ = $forceOcc ? 1 : (($oldOcc === null) ? 1 : (int)$oldOcc);

        if ($oldTs !== '' && $ts === $oldTs && $oldOcc !== null && (int)$oldOcc === (int)$occ) {
            return false;
        }

        $desc = (string)$this->HttpGet('GetStoragePlaceDescription', $key, $ok);
        if (!$ok) $desc = '';
        $desc = trim($desc);

        $changed = true;
        if ($oldTs !== '' && $oldTs === $ts && $oldOcc !== null && (int)$oldOcc === (int)$occ && $oldDesc === $desc) {
            $changed = false;
        }

        $cache[$key] = array('ts' => $ts, 'desc' => $desc, 'occ' => $occ);
        $this->SetBuffer('PlaceMetaCache', json_encode($cache));

        if ($changed) {
            $payload = array(
                'DataID' => $this->DataID,
                'type' => 'place_meta',
                'storage' => (int)$storage,
                'place' => (int)$place,
                'ts' => $ts,
                'desc' => $desc
            );
            @ $this->SendDataToChildren(json_encode($payload));
        }

        return $changed;
    }

    private function MarkPlaceEmpty($storage, $place)
    {
        $key = 'S' . (int)$storage . 'P' . (int)$place;
        $cache = $this->GetJsonBuffer('PlaceMetaCache');

        if (isset($cache[$key]) && is_array($cache[$key])) {
            $occ = isset($cache[$key]['occ']) ? (int)$cache[$key]['occ'] : 0;
            if ($occ != 0) {
                $cache[$key]['occ'] = 0;
                $this->SetBuffer('PlaceMetaCache', json_encode($cache));
            }
        }
    }

    private function PushStorageMetaInternal($storage)
    {
        $cache = $this->GetJsonBuffer('PlaceMetaCache');
        $items = array();

        foreach ($cache as $k => $v) {
            if (!is_array($v)) continue;
            if (strpos($k, 'S' . (int)$storage . 'P') !== 0) continue;

            $occ = isset($v['occ']) ? (int)$v['occ'] : 0;
            if ($occ != 1) continue;

            $place = $this->ParsePlaceFromKey($k);
            if ($place <= 0) continue;

            $desc = isset($v['desc']) ? (string)$v['desc'] : '';
            $items[] = array('place' => (int)$place, 'desc' => (string)$desc);
        }

        if (count($items) == 0) return;

        $payload = array(
            'DataID' => $this->DataID,
            'type' => 'place_meta_bulk',
            'storage' => (int)$storage,
            'items' => $items
        );
        @ $this->SendDataToChildren(json_encode($payload));
    }

    private function ParsePlaceFromKey($key)
    {
        $pos = strpos($key, 'P');
        if ($pos === false) return 0;
        $p = (int)substr($key, $pos + 1);
        if ($p < 1 || $p > 256) return 0;
        return $p;
    }

    public function Poll()
    {
        if (!$this->Lock()) {
            $this->SetFastPollForSeconds(5);
            return;
        }

        $forceNames = ((int)$this->GetBuffer('ForceNameSync')) === 1;
        if ($forceNames) {
            $this->SetBuffer('ForceNameSync', '0');
        }

        $isFast = $this->IsFastPoll();
        $pending = $this->GetPending();

        $ok = true;

        // Master (immer)
        $master = (int)$this->HttpGet('GetMaster', null, $ok);
        $blackout = (int)$this->HttpGet('GetBlackout', null, $ok);
        if (!$ok) {
            $this->SetOnline(false, 'Poll Master failed');
            $this->UpdatePollInterval(false);
            $this->Unlock();
            return;
        }
        $this->SetOnline(true, '');

        $this->ResolvePendingIfReached('Master', $master, 0);
        $this->ResolvePendingIfReached('Blackout', $blackout, 0);

        // Fade (immer)
        $fadeOk = true;
        $fadeType = trim((string)$this->HttpGetSilent('GetFadeType', null, $fadeOk));
        if (!$fadeOk) $fadeType = '';
        $fadeOk2 = true;
        $fadeValue = (int)$this->HttpGetSilent('GetFadeValue', null, $fadeOk2);
        if (!$fadeOk2) $fadeValue = 0;
        $this->ResolvePendingIfReached('FadeType', $fadeType, 0);
        $this->ResolvePendingIfReached('FadeValue', $fadeValue, 0);

        // Decks (immer)
        $deckAInst = (int)$this->ReadAttributeInteger('DeckAInstance');
        $deckBInst = (int)$this->ReadAttributeInteger('DeckBInstance');

        $deckA = $this->PollDeck('A', $deckAInst, $forceNames);
        $deckB = $this->PollDeck('B', $deckBInst, $forceNames);

        // Enforce Crossfader (aus Master-Konfig)
        $cfg = $this->GetCrossfaderConfigFromMaster();
        if (isset($cfg['enforce']) && $cfg['enforce']) {
            $pendingNow = $this->GetPending();

            $desiredType = isset($cfg['type']) ? (string)$cfg['type'] : '';
            if ($desiredType !== '' && !isset($pendingNow['FadeType']) && $fadeType !== '' && $fadeType !== $desiredType) {
                $setOk = true;
                $this->HttpSet('SetFadeType', $desiredType, $setOk);
                if ($setOk) {
                    $this->MarkPending('FadeType', $desiredType);
                    $this->AfterChange();
                }
            }

            $desiredValue = isset($cfg['value']) ? (int)$cfg['value'] : null;
            if ($desiredValue !== null && !isset($pendingNow['FadeValue']) && ($fadeValue !== $desiredValue)) {
                $setOk = true;
                $this->HttpSet('SetFadeValue', (string)$desiredValue, $setOk);
                if ($setOk) {
                    $this->MarkPending('FadeValue', $desiredValue);
                    $this->AfterChange();
                }
            }
        }

        // Groups/Colors je nach Mode
        $groupsPayload = array();
        $colorsPayload = array();

        $hasGroups = count($this->GetGroupNameCache()) > 0;
        if (!$isFast || $forceNames || !$hasGroups) {
            $groupsPayload = $this->PollAllGroups($forceNames);
            $colorsPayload = $this->PollAllColorsFromMasterConfig();
        } else {
            $groupsPayload = $this->PollPendingGroups($pending, $forceNames);
            $colorsPayload = $this->PollPendingColors($pending);
        }

        $payload = array(
            'DataID' => $this->DataID,
            'type' => 'status',
            'mode' => $isFast ? 'fast' : 'slow',
            'master' => array(
                'master' => $master,
                'blackout' => ($blackout == 1) ? 1 : 0
            ),
            'fade' => array(
                'type' => $fadeType,
                'value' => $fadeValue
            ),
            'groups' => $groupsPayload,
            'colors' => $colorsPayload,
            'decks' => array($deckA, $deckB)
        );

        $this->SendDataToChildren(json_encode($payload));

        $this->UpdatePollInterval(false);
        $this->Unlock();
    }

    // ===== Poll Helpers =====

    private function PollDeck($deck, $deckInstance, $forceDesc)
    {
        $ok = true;
        $place = 0;
        $speed = 0.0;

        if ($deck == 'A') {
            $place = (int)$this->HttpGet('GetStoragePlaceDeckA', null, $ok);
            $speed = (float)$this->ToFloat($this->HttpGet('GetStorageSpeedDeckA', null, $ok));
            $this->ResolvePendingIfReached('DeckA_Place', $place, 0);
            $this->ResolvePendingIfReached('DeckA_Speed', $speed, 0.05);
        } else {
            $place = (int)$this->HttpGet('GetStoragePlaceDeckB', null, $ok);
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
        $groupCount = (int)$this->HttpGet('GetGroupCount', null, $ok);
        if (!$ok || $groupCount <= 0) return array();

        $cache = $this->GetGroupNameCache();
        $result = array();

        for ($i = 1; $i <= $groupCount; $i++) {
            $gid = (int)$this->HttpGet('GetGroupId', (string)$i, $ok);
            if (!$ok || $gid <= 0) continue;

            $name = isset($cache[(string)$gid]) ? (string)$cache[(string)$gid] : '';
            if ($forceNames || $name === '') {
                $n = (string)$this->HttpGet('GetGroupDisplayName', (string)$gid, $ok);
                $n = trim($n);
                if ($n === '') $n = 'Group ' . $gid;
                $name = $n;
                $cache[(string)$gid] = $name;
            }

            $val = (int)$this->HttpGet('GetGroupValue', (string)$gid, $ok);
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
                $n = (string)$this->HttpGet('GetGroupDisplayName', (string)$gid, $ok);
                $n = trim($n);
                if ($n === '') $n = 'Group ' . $gid;
                $name = $n;
                $cache[(string)$gid] = $name;
            }

            $val = (int)$this->HttpGet('GetGroupValue', (string)$gid, $ok);
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
            $hex = (string)$this->HttpGet('GetGlobalColorRGB', (string)$cid, $ok);
            $hex = trim($hex);
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

            $hex = (string)$this->HttpGet('GetGlobalColorRGB', (string)$cid, $ok);
            $hex = trim($hex);
            if ($ok && strlen($hex) == 6) {
                $hexInt = hexdec($hex);
                $this->ResolvePendingIfReached('Color_' . $cid, $hexInt, 0);
                $result[] = array('id' => $cid, 'hex' => $hexInt);
            }
        }

        return $result;
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

    private function GetGroupNameCache()
    {
        return $this->GetJsonBuffer('GroupNameCache');
    }

    private function SetGroupNameCache($arr)
    {
        if (!is_array($arr)) $arr = array();
        $this->SetBuffer('GroupNameCache', json_encode($arr));
    }

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

    private function GetCrossfaderConfigFromMaster()
    {
        $masterInst = (int)$this->ReadAttributeInteger('MasterInstance');
        if ($masterInst <= 0 || !IPS_ObjectExists($masterInst)) {
            return array('enforce' => false);
        }

        $enforce = (bool)@IPS_GetProperty($masterInst, 'EnforceCrossfader');
        $type = trim((string)@IPS_GetProperty($masterInst, 'CrossfadeType'));
        $p = (int)@IPS_GetProperty($masterInst, 'CrossfadeValue');
        if ($p < 0) $p = 0;
        if ($p > 100) $p = 100;
        $value = (int)round(($p * 255) / 100);

        return array(
            'enforce' => $enforce,
            'type' => $type,
            'value' => $value
        );
    }

    private function MarkPending($key, $desired)
    {
        $pending = $this->GetPending();
        $timeout = (int)$this->ReadPropertyInteger('PendingTimeout');
        if ($timeout < 2) $timeout = 10;

        $pending[$key] = array('desired' => $desired, 'deadline' => time() + $timeout);
        $this->SetBuffer('Pending', json_encode($pending));
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

    private function AfterChange()
    {
        $sec = (int)$this->ReadPropertyInteger('FastAfterChange');
        if ($sec < 0) $sec = 0;
        if ($sec > 0) $this->SetFastPollForSeconds($sec);
    }

    private function HttpGet($function, $valueOrNull, &$ok)
    {
        return $this->HttpRequest($function, $valueOrNull, $ok);
    }

    private function HttpSet($function, $value, &$ok)
    {
        $this->HttpRequest($function, $value, $ok);
    }

    private function HttpGetSilent($function, $valueOrNull, &$ok)
    {
        return $this->HttpRequestInternal($function, $valueOrNull, $ok, true);
    }

    private function HttpRequest($function, $valueOrNull, &$ok)
    {
        return $this->HttpRequestInternal($function, $valueOrNull, $ok, false);
    }

    private function HttpRequestInternal($function, $valueOrNull, &$ok, $suppressOnline)
    {
        $ok = true;

        $host = trim($this->ReadPropertyString('Host'));
        $port = (int)$this->ReadPropertyInteger('Port');

        if ($host === '' || $port <= 0 || $port > 65535) {
            $ok = false;
            if (!$suppressOnline) {
                $this->SetOnline(false, 'Host/Port nicht konfiguriert');
            }
            return '';
        }

        $base = '/RemoteCommands/';
        if ($valueOrNull === null) {
            $path = $base . rawurlencode($function);
        } else {
            $path = $base . rawurlencode($function) . '=' . rawurlencode((string)$valueOrNull);
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
            if (!$suppressOnline) {
                $this->SetOnline(false, 'HTTP failed: ' . $function);
            }
            return '';
        }

        return trim((string)$data);
    }

    private function SetOnline($online, $err)
    {
        $this->SetValueIfChanged('Online', (bool)$online);

        $e = (string)$err;
        $vid = $this->GetIDForIdent('LastError');
        if ($vid > 0) {
            $cur = GetValue($vid);
            if ($cur !== $e) {
                $this->SetValue('LastError', $e);
            }
        }

        $lastLogged = (string)$this->GetBuffer('LastLoggedError');
        if ($e !== $lastLogged) {
            $this->SetBuffer('LastLoggedError', $e);
            if ($e !== '') {
                $this->LogMessage($e, KL_WARNING);
            }
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

    private function SetValueIfChanged($ident, $value)
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid == 0) return;
        $cur = GetValue($vid);
        if ($cur !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    private function TryConnectChild($childId)
    {
        if ($childId <= 0 || !IPS_ObjectExists($childId)) return;

        if (function_exists('IPS_ConnectInstance')) {
            @IPS_ConnectInstance($childId, $this->InstanceID);
        } else {
            $this->LogMessage('IPS_ConnectInstance not available. Please connect child manually.', KL_WARNING);
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

    private function ClampInt($v, $min, $max)
    {
        $x = (int)$v;
        if ($x < (int)$min) $x = (int)$min;
        if ($x > (int)$max) $x = (int)$max;
        return $x;
    }

    private function GetJsonBuffer($name)
    {
        $raw = $this->GetBuffer($name);
        $arr = json_decode($raw, true);
        if (!is_array($arr)) $arr = array();
        return $arr;
    }

    private function GetDeckStorages()
    {
        $storages = array();
        $a = (int)$this->ReadAttributeInteger('DeckAInstance');
        $b = (int)$this->ReadAttributeInteger('DeckBInstance');

        if ($a > 0 && IPS_ObjectExists($a)) {
            $sa = (int)@IPS_GetProperty($a, 'Storage');
            if ($sa > 0) $storages[] = $sa;
        }
        if ($b > 0 && IPS_ObjectExists($b)) {
            $sb = (int)@IPS_GetProperty($b, 'Storage');
            if ($sb > 0) $storages[] = $sb;
        }

        return array_values(array_unique($storages));
    }
}
