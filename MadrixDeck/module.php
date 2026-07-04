<?php

class MadrixDeck extends IPSModule
{
    private $DataID = '{E1B4B2E6-9D1C-4D5E-9C8B-0D3B1A2C3D41}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Deck', 'A');
        $this->RegisterPropertyInteger('Storage', 1);
        $this->RegisterPropertyInteger('LayerCount', 0); // 0 = Auto (Anzahl vom aktiven Place)

        $this->SetBuffer('Pending', json_encode(array()));
        $this->SetBuffer('DescCache', json_encode(array())); // place(string) => desc
        $this->SetBuffer('AutoLayerCount', '0'); // letzte vom Controller gemeldete Layer-Anzahl
        $this->SetBuffer('LayerLastMap', json_encode(array())); // layer(string) => letzter Prozentwert > 0

        $this->EnsureProfiles();

        $this->RegisterVariableInteger('Place', 'Deck A Place', $this->GetPlaceProfile(), 10);
        $this->EnableAction('Place');

        $this->RegisterVariableFloat('Speed', 'Deck A Speed', 'MADRIX.Speed', 11);
        $this->EnableAction('Speed');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();
        // Löschen überzähliger Variablen nur bei fester Anzahl (Property > 0).
        // Im Auto-Modus würde ein Place mit weniger Layern sonst Variablen löschen
        // und damit BWM-/Ereignis-Verknüpfungen zerstören.
        $this->EnsureLayerVars((int)$this->ReadPropertyInteger('LayerCount') > 0);

        $deck = $this->GetDeck();

        $pvid = $this->GetIDForIdent('Place');
        if ($pvid > 0) {
            $pname = 'Deck ' . $deck . ' Place';
            if (IPS_GetName($pvid) != $pname) IPS_SetName($pvid, $pname);
            IPS_SetVariableCustomProfile($pvid, $this->GetPlaceProfile());
        }

        $svid = $this->GetIDForIdent('Speed');
        if ($svid > 0) {
            $sname = 'Deck ' . $deck . ' Speed';
            if (IPS_GetName($svid) != $sname) IPS_SetName($svid, $sname);
        }

        $place = $this->GetVarIntByIdent('Place', 1);
        $storage = $this->GetStorage();

        $this->SendToParent('SetDeckPlace', array('deck' => $deck, 'storage' => $storage, 'place' => $place));
        $this->SendToParent('PushStorageMeta', array('storage' => $storage));
        $this->SendToParent('ResolvePlaceMeta', array('storage' => $storage, 'place' => $place));

        $this->UpdatePlaceAssociation($place, '');
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == 'Place') {
            $place = (int)$Value;
            if ($place < 1) $place = 1;
            if ($place > 256) $place = 256;

            $this->SetValue('Place', $place);
            $this->SetPending('Place', $place, 10);

            $this->UpdatePlaceAssociation($place, '');

            $deck = $this->GetDeck();
            $storage = $this->GetStorage();

            $this->SendToParent('SetDeckPlace', array('deck' => $deck, 'storage' => $storage, 'place' => $place));
            $this->SendToParent('ResolvePlaceMeta', array('storage' => $storage, 'place' => $place));
            return;
        }

        if ($Ident == 'Speed') {
            $speed = (float)$Value;
            if ($speed < -10) $speed = -10;
            if ($speed > 10) $speed = 10;

            $this->SetValue('Speed', $speed);
            $this->SetPending('Speed', $speed, 10);

            $deck = $this->GetDeck();
            $this->SendToParent('SetDeckSpeed', array('deck' => $deck, 'speed' => $speed));
            return;
        }

        if (substr($Ident, 0, 12) == 'LayerSwitch_') {
            $layer = (int)substr($Ident, 12);
            if ($layer <= 0) return;

            $on = ((bool)$Value) ? true : false;

            $target = 0;
            if ($on) {
                $current = $this->GetLayerPercent($layer);
                if ($current > 0) {
                    $target = $current;
                } else {
                    $last = $this->GetLayerLastPercent($layer);
                    $target = ($last > 0) ? $last : 100;
                }
            }

            $this->ApplyLayerTarget($layer, $target);
            return;
        }

        if (substr($Ident, 0, 6) == 'Layer_') {
            $layer = (int)substr($Ident, 6);
            if ($layer <= 0) return;

            $p = (int)$Value;
            if ($p < 0) $p = 0;
            if ($p > 100) $p = 100;

            $this->ApplyLayerTarget($layer, $p);
            return;
        }
    }

    private function ApplyLayerTarget($layer, $percent)
    {
        $ident = 'Layer_' . (int)$layer;

        $vid = $this->GetLayerVarId($layer);
        if ($vid > 0) {
            @SetValueInteger($vid, (int)$percent);
        }

        $svid = $this->GetLayerSwitchVarId($layer);
        if ($svid > 0) {
            @SetValueBoolean($svid, ((int)$percent > 0));
        }

        if ((int)$percent > 0) {
            $this->UpdateLayerLastPercent($layer, (int)$percent);
        }

        $this->SetPending($ident, (int)$percent, 10);

        // "Aus" (0%) wird als Sentinel 254 gesendet (optisch = voll hell), damit die
        // Opacity beim Ausschalten nie sichtbar auf 0 faellt. Das Layer-Makro erkennt
        // 254 als "rausfahren" und setzt die Opacity erst am Ende (dunkel) selbst auf 0.
        $byte = ((int)$percent <= 0) ? 254 : $this->PercentToByte((int)$percent);

        $this->SendToParent('SetLayerOpacity', array(
            'deck' => $this->GetDeck(),
            'layer' => (int)$layer,
            'value' => $byte
        ));
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) return;
        if (!isset($data['DataID']) || $data['DataID'] != $this->DataID) return;
        if (!isset($data['type'])) return;

        if ($data['type'] == 'place_meta') {
            $storage = isset($data['storage']) ? (int)$data['storage'] : 0;
            $place = isset($data['place']) ? (int)$data['place'] : 0;
            $desc = isset($data['desc']) ? (string)$data['desc'] : '';

            if ($storage == $this->GetStorage() && $place > 0) {
                $this->UpdatePlaceAssociation($place, $desc);
            }
            return;
        }

        if ($data['type'] == 'place_meta_bulk') {
            $storage = isset($data['storage']) ? (int)$data['storage'] : 0;
            if ($storage != $this->GetStorage()) return;

            $items = isset($data['items']) ? $data['items'] : null;
            if (!is_array($items)) return;

            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $place = isset($it['place']) ? (int)$it['place'] : 0;
                $desc = isset($it['desc']) ? (string)$it['desc'] : '';
                if ($place > 0) {
                    $this->UpdatePlaceAssociation($place, $desc);
                }
            }
            return;
        }

        if ($data['type'] != 'status') return;
        if (!isset($data['decks']) || !is_array($data['decks'])) return;

        $myDeck = $this->GetDeck();

        foreach ($data['decks'] as $d) {
            if (!is_array($d)) continue;
            $deck = isset($d['deck']) ? (string)$d['deck'] : '';
            if ($deck != $myDeck) continue;

            $place = isset($d['place']) ? (int)$d['place'] : null;
            $speed = isset($d['speed']) ? (float)$d['speed'] : null;
            $desc  = isset($d['desc']) ? (string)$d['desc'] : '';
            $layers = isset($d['layers']) && is_array($d['layers']) ? $d['layers'] : null;
            $layerNames = isset($d['layerNames']) && is_array($d['layerNames']) ? $d['layerNames'] : null;

            if (isset($d['layerCount']) && (int)$this->ReadPropertyInteger('LayerCount') <= 0) {
                $lc = (int)$d['layerCount'];
                if ($lc < 0) $lc = 0;
                if ($lc > 16) $lc = 16;
                if ($lc != (int)$this->GetBuffer('AutoLayerCount')) {
                    $this->SetBuffer('AutoLayerCount', (string)$lc);
                    if ($lc > 0) $this->EnsureLayerVars(false);
                }
            }

            if ($place !== null) $this->ApplyPolledWithPending('Place', $place, 0);
            if ($speed !== null) $this->ApplyPolledWithPending('Speed', $speed, 0.05);

            if ($place !== null) {
                $this->UpdatePlaceAssociation($place, $desc);
            }

            if (is_array($layers)) {
                foreach ($layers as $entry) {
                    if (!is_array($entry)) continue;
                    $layer = isset($entry['layer']) ? (int)$entry['layer'] : 0;
                    if ($layer <= 0) continue;
                    $opacity = isset($entry['opacity']) ? (int)$entry['opacity'] : 0;
                    $percent = $this->ByteToPercent($opacity);

                    $this->ApplyPolledWithPending('Layer_' . $layer, $percent, 0);

                    // Schalter folgt dem (ggf. durch Pending geschuetzten) Variablenwert
                    $vid = $this->GetLayerVarId($layer);
                    $eff = ($vid > 0) ? (int)@GetValueInteger($vid) : $percent;
                    $svid = $this->GetLayerSwitchVarId($layer);
                    if ($svid > 0) {
                        $on = ($eff > 0);
                        if ((bool)@GetValueBoolean($svid) !== $on) {
                            @SetValueBoolean($svid, $on);
                        }
                    }

                    if ($percent > 0) {
                        $this->UpdateLayerLastPercent($layer, $percent);
                    }
                }
            }

            if (is_array($layerNames)) {
                $this->UpdateLayerNames($layerNames);
            }
        }
    }

    private function EnsureProfiles()
    {
        $pp = $this->GetPlaceProfile();
        if (!IPS_VariableProfileExists($pp)) {
            IPS_CreateVariableProfile($pp, 1);
            IPS_SetVariableProfileValues($pp, 1, 256, 1);
        }

        if (!IPS_VariableProfileExists('MADRIX.Percent')) {
            IPS_CreateVariableProfile('MADRIX.Percent', 1);
        }
        IPS_SetVariableProfileValues('MADRIX.Percent', 0, 100, 1);
        if (function_exists('IPS_SetVariableProfileSuffix')) {
            IPS_SetVariableProfileSuffix('MADRIX.Percent', ' %');
        } else {
            IPS_SetVariableProfileText('MADRIX.Percent', '', ' %');
        }

        if (!IPS_VariableProfileExists('MADRIX.Speed')) {
            IPS_CreateVariableProfile('MADRIX.Speed', 2);
            IPS_SetVariableProfileValues('MADRIX.Speed', -10, 10, 0.1);
            IPS_SetVariableProfileDigits('MADRIX.Speed', 1);
        }

        if (!IPS_VariableProfileExists('MADRIX.Switch')) {
            IPS_CreateVariableProfile('MADRIX.Switch', 0);
            IPS_SetVariableProfileAssociation('MADRIX.Switch', 0, 'Aus', '', 0);
            IPS_SetVariableProfileAssociation('MADRIX.Switch', 1, 'Ein', '', 0);
        }
    }

    private function EnsureLayerVars($allowDelete)
    {
        $count = $this->GetLayerCount();
        $deck = $this->GetDeck();

        $existing = array();       // layer => varId (Prozent-Dimmer)
        $existingSwitch = array(); // layer => varId (Schalter)
        $children = @IPS_GetChildrenIDs($this->InstanceID);
        if (is_array($children)) {
            foreach ($children as $id) {
                $obj = @IPS_GetObject($id);
                if (!is_array($obj)) continue;
                if ((int)$obj['ObjectType'] !== 2) continue;
                $ident = (string)$obj['ObjectIdent'];
                if (strpos($ident, 'LayerSwitch_') === 0) {
                    $layer = (int)substr($ident, 12);
                    if ($layer > 0) $existingSwitch[$layer] = (int)$id;
                } elseif (strpos($ident, 'Layer_') === 0) {
                    $layer = (int)substr($ident, 6);
                    if ($layer > 0) $existing[$layer] = (int)$id;
                }
            }
        }

        for ($i = 1; $i <= $count; $i++) {
            $ident = 'Layer_' . $i;
            $name = 'Deck ' . $deck . ' Layer ' . $i;
            $switchIdent = 'LayerSwitch_' . $i;
            $switchName = $name . ' Schalter';

            // Migration: fruehere Bool-Variable durch Prozent-Dimmer ersetzen
            if (isset($existing[$i]) && IPS_ObjectExists((int)$existing[$i])) {
                $var = @IPS_GetVariable((int)$existing[$i]);
                if (is_array($var) && (int)$var['VariableType'] !== 1) {
                    @IPS_DeleteVariable((int)$existing[$i]);
                    unset($existing[$i]);
                }
            }

            if (isset($existing[$i]) && IPS_ObjectExists((int)$existing[$i])) {
                $vid = (int)$existing[$i];
                if (IPS_GetName($vid) != $name) IPS_SetName($vid, $name);
                IPS_SetVariableCustomProfile($vid, 'MADRIX.Percent');
                $this->EnableAction($ident);
            } else {
                $this->RegisterVariableInteger($ident, $name, 'MADRIX.Percent', 30 + $i);
                $this->EnableAction($ident);
            }

            if (isset($existingSwitch[$i]) && IPS_ObjectExists((int)$existingSwitch[$i])) {
                $svid = (int)$existingSwitch[$i];
                if (IPS_GetName($svid) != $switchName) IPS_SetName($svid, $switchName);
                IPS_SetVariableCustomProfile($svid, 'MADRIX.Switch');
                $this->EnableAction($switchIdent);
            } else {
                $this->RegisterVariableBoolean($switchIdent, $switchName, 'MADRIX.Switch', 60 + $i);
                $this->EnableAction($switchIdent);
            }
        }

        if ($allowDelete) {
            foreach ($existing as $layer => $vid) {
                if ($layer > $count && IPS_ObjectExists($vid)) {
                    @IPS_DeleteVariable($vid);
                }
            }
            foreach ($existingSwitch as $layer => $vid) {
                if ($layer > $count && IPS_ObjectExists($vid)) {
                    @IPS_DeleteVariable($vid);
                }
            }
        }
    }

    private function GetLayerCount()
    {
        $c = (int)$this->ReadPropertyInteger('LayerCount');
        if ($c > 16) $c = 16;
        if ($c > 0) return $c;

        // 0 = Auto: letzte vom Controller gemeldete Anzahl des aktiven Place
        $auto = (int)$this->GetBuffer('AutoLayerCount');
        if ($auto < 0) $auto = 0;
        if ($auto > 16) $auto = 16;
        return $auto;
    }

    private function UpdateLayerNames($layerNames)
    {
        $count = $this->GetLayerCount();
        if ($count <= 0) return;

        for ($i = 1; $i <= $count; $i++) {
            $name = '';
            if (isset($layerNames[(string)$i])) {
                $name = (string)$layerNames[(string)$i];
            }

            $display = $this->GetLayerDisplayName($i, $name);
            $vid = $this->GetLayerVarId($i);
            if ($vid > 0 && IPS_ObjectExists($vid)) {
                if (IPS_GetName($vid) != $display) {
                    IPS_SetName($vid, $display);
                }
            }

            $svid = $this->GetLayerSwitchVarId($i);
            if ($svid > 0 && IPS_ObjectExists($svid)) {
                $switchDisplay = $display . ' Schalter';
                if (IPS_GetName($svid) != $switchDisplay) {
                    IPS_SetName($svid, $switchDisplay);
                }
            }
        }
    }

    private function GetLayerDisplayName($layer, $name)
    {
        $deck = $this->GetDeck();
        $base = 'Deck ' . $deck . ' Layer ' . (int)$layer;
        $t = trim((string)$name);
        if ($t === '') return $base;
        return $base . ': ' . $t;
    }

    private function GetLayerVarId($layer)
    {
        return $this->FindVarByIdent('Layer_' . (int)$layer);
    }

    private function GetLayerSwitchVarId($layer)
    {
        return $this->FindVarByIdent('LayerSwitch_' . (int)$layer);
    }

    private function FindVarByIdent($ident)
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid > 0 && IPS_ObjectExists($vid)) return $vid;

        $children = @IPS_GetChildrenIDs($this->InstanceID);
        if (!is_array($children)) return 0;

        foreach ($children as $id) {
            $obj = @IPS_GetObject($id);
            if (!is_array($obj)) continue;
            if ((int)$obj['ObjectType'] !== 2) continue;
            if ((string)$obj['ObjectIdent'] === $ident) return (int)$id;
        }

        return 0;
    }

    private function GetLayerPercent($layer)
    {
        $vid = $this->GetLayerVarId($layer);
        if ($vid <= 0) return 0;
        return (int)@GetValueInteger($vid);
    }

    private function GetLayerLastPercent($layer)
    {
        $raw = $this->GetBuffer('LayerLastMap');
        $map = json_decode($raw, true);
        if (!is_array($map)) $map = array();
        $key = (string)(int)$layer;
        return isset($map[$key]) ? (int)$map[$key] : 0;
    }

    private function UpdateLayerLastPercent($layer, $percent)
    {
        $p = (int)$percent;
        if ($p <= 0) return;
        $raw = $this->GetBuffer('LayerLastMap');
        $map = json_decode($raw, true);
        if (!is_array($map)) $map = array();
        $map[(string)(int)$layer] = $p;
        $this->SetBuffer('LayerLastMap', json_encode($map));
    }

    private function ByteToPercent($b)
    {
        $x = (int)$b;
        if ($x < 0) $x = 0;
        if ($x > 255) $x = 255;
        return (int)round(($x * 100) / 255);
    }

    private function GetPlaceProfile()
    {
        return 'MADRIX.Place.' . $this->GetDeck();
    }

    private function UpdatePlaceAssociation($place, $desc)
    {
        $pp = $this->GetPlaceProfile();
        if (!IPS_VariableProfileExists($pp)) $this->EnsureProfiles();

        $place = (int)$place;
        if ($place < 1) $place = 1;
        if ($place > 256) $place = 256;

        $cache = $this->GetDescCache();

        $t = trim((string)$desc);
        if ($t !== '') {
            $cache[(string)$place] = $t;
            $this->SetDescCache($cache);
        } else {
            if (isset($cache[(string)$place])) {
                $t = (string)$cache[(string)$place];
            }
        }

        $label = 'Place ' . $place;
        if ($t !== '') {
            $label .= ' "' . $t . '"';
        }

        IPS_SetVariableProfileAssociation($pp, $place, $label, '', 0);
    }

    private function GetDescCache()
    {
        $raw = $this->GetBuffer('DescCache');
        $arr = json_decode($raw, true);
        if (!is_array($arr)) $arr = array();
        return $arr;
    }

    private function SetDescCache($arr)
    {
        if (!is_array($arr)) $arr = array();
        $this->SetBuffer('DescCache', json_encode($arr));
    }

    private function SendToParent($cmd, $arg)
    {
        $payload = array('DataID' => $this->DataID, 'cmd' => (string)$cmd, 'arg' => $arg);
        @ $this->SendDataToParent(json_encode($payload));
    }

    private function GetDeck()
    {
        $d = strtoupper(trim($this->ReadPropertyString('Deck')));
        if ($d != 'A' && $d != 'B') $d = 'A';
        return $d;
    }

    private function GetStorage()
    {
        $s = (int)$this->ReadPropertyInteger('Storage');
        if ($s < 1) $s = 1;
        if ($s > 256) $s = 256;
        return $s;
    }

    private function GetVarIntByIdent($ident, $fallback)
    {
        $vid = $this->GetIDForIdent($ident);
        if ($vid <= 0) return (int)$fallback;
        return (int)GetValueInteger($vid);
    }

    private function ApplyPolledWithPending($ident, $polledValue, $epsilon)
    {
        $pending = $this->GetPending();
        $now = time();

        $vid = $this->GetVariableIdForIdent($ident);
        if ($vid <= 0) return;

        if (isset($pending[$ident])) {
            $p = $pending[$ident];
            $deadline = isset($p['deadline']) ? (int)$p['deadline'] : 0;
            $desired = isset($p['desired']) ? $p['desired'] : null;

            if ($deadline > 0 && $now <= $deadline) {
                if ($this->Matches($polledValue, $desired, $epsilon)) {
                    unset($pending[$ident]);
                    $this->SetBuffer('Pending', json_encode($pending));
                    $this->SetValueById($vid, $polledValue);
                } else {
                    return;
                }
            } else {
                unset($pending[$ident]);
                $this->SetBuffer('Pending', json_encode($pending));
                $this->SetValueById($vid, $polledValue);
            }
        } else {
            $this->SetValueById($vid, $polledValue);
        }
    }

    private function SetPending($ident, $desired, $seconds)
    {
        $pending = $this->GetPending();
        $pending[$ident] = array('desired' => $desired, 'deadline' => time() + (int)$seconds);
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

    private function PercentToByte($p)
    {
        $x = (int)$p;
        if ($x < 0) $x = 0;
        if ($x > 100) $x = 100;
        return (int)round(($x * 255) / 100);
    }

    private function GetVariableIdForIdent($ident)
    {
        if (substr($ident, 0, 6) == 'Layer_') {
            return $this->GetLayerVarId((int)substr($ident, 6));
        }

        $vid = $this->GetIDForIdent($ident);
        if ($vid > 0 && IPS_ObjectExists($vid)) return $vid;
        return 0;
    }

    private function SetValueById($vid, $value)
    {
        if ($vid <= 0 || !IPS_ObjectExists($vid)) return;
        $obj = @IPS_GetObject($vid);
        $type = is_array($obj) ? (int)$obj['ObjectType'] : 0;
        if ($type !== 2) return;

        $var = @IPS_GetVariable($vid);
        $vt = is_array($var) ? (int)$var['VariableType'] : 0;

        if ($vt === 0) {
            @SetValueBoolean($vid, (bool)$value);
        } elseif ($vt === 1) {
            @SetValueInteger($vid, (int)$value);
        } elseif ($vt === 2) {
            @SetValueFloat($vid, (float)$value);
        } else {
            @SetValueString($vid, (string)$value);
        }
    }
}
