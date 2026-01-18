<?php

class MadrixDeck extends IPSModule
{
    private $DataID = '{E1B4B2E6-9D1C-4D5E-9C8B-0D3B1A2C3D41}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Deck', 'A');
        $this->RegisterPropertyInteger('Storage', 1);
        $this->RegisterPropertyInteger('LayerCount', 8);

        $this->SetBuffer('Pending', json_encode(array()));
        $this->SetBuffer('DescCache', json_encode(array())); // place(string) => desc

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
        $this->EnsureLayerVars();

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

        if (substr($Ident, 0, 6) == 'Layer_') {
            $layer = (int)substr($Ident, 6);
            if ($layer <= 0) return;

            $on = ((bool)$Value) ? true : false;
            $this->SetValue($Ident, $on);

            $storage = $this->GetStorage();
            $place = $this->GetVarIntByIdent('Place', 1);
            if ($place < 1) $place = 1;
            if ($place > 256) $place = 256;

            $val = $this->PercentToByte($on ? 100 : 0);
            $this->SendToParent('SetPlaceLayerIntensity', array(
                'storage' => $storage,
                'place' => $place,
                'layer' => $layer,
                'value' => $val
            ));
            return;
        }
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

            if ($place !== null) $this->ApplyPolledWithPending('Place', $place, 0);
            if ($speed !== null) $this->ApplyPolledWithPending('Speed', $speed, 0.05);

            if ($place !== null) {
                $this->UpdatePlaceAssociation($place, $desc);
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

    private function EnsureLayerVars()
    {
        $count = $this->GetLayerCount();
        $deck = $this->GetDeck();

        $existing = array(); // layer => varId
        $children = @IPS_GetChildrenIDs($this->InstanceID);
        if (is_array($children)) {
            foreach ($children as $id) {
                $obj = @IPS_GetObject($id);
                if (!is_array($obj)) continue;
                if ((int)$obj['ObjectType'] !== 2) continue;
                $ident = (string)$obj['ObjectIdent'];
                if (strpos($ident, 'Layer_') !== 0) continue;
                $layer = (int)substr($ident, 6);
                if ($layer > 0) $existing[$layer] = (int)$id;
            }
        }

        for ($i = 1; $i <= $count; $i++) {
            $ident = 'Layer_' . $i;
            $name = 'Deck ' . $deck . ' Layer ' . $i;

            if (isset($existing[$i]) && IPS_ObjectExists((int)$existing[$i])) {
                $vid = (int)$existing[$i];
                if (IPS_GetName($vid) != $name) IPS_SetName($vid, $name);
                IPS_SetVariableCustomProfile($vid, 'MADRIX.Switch');
                $this->EnableAction($ident);
            } else {
                $this->RegisterVariableBoolean($ident, $name, 'MADRIX.Switch', 30 + $i);
                $this->EnableAction($ident);
            }
        }

        foreach ($existing as $layer => $vid) {
            if ($layer > $count && IPS_ObjectExists($vid)) {
                @IPS_DeleteObject($vid);
            }
        }
    }

    private function GetLayerCount()
    {
        $c = (int)$this->ReadPropertyInteger('LayerCount');
        if ($c < 0) $c = 0;
        if ($c > 32) $c = 32;
        return $c;
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

        if (isset($pending[$ident])) {
            $p = $pending[$ident];
            $deadline = isset($p['deadline']) ? (int)$p['deadline'] : 0;
            $desired = isset($p['desired']) ? $p['desired'] : null;

            if ($deadline > 0 && $now <= $deadline) {
                if ($this->Matches($polledValue, $desired, $epsilon)) {
                    unset($pending[$ident]);
                    $this->SetBuffer('Pending', json_encode($pending));
                    $this->SetValue($ident, $polledValue);
                } else {
                    return;
                }
            } else {
                unset($pending[$ident]);
                $this->SetBuffer('Pending', json_encode($pending));
                $this->SetValue($ident, $polledValue);
            }
        } else {
            $this->SetValue($ident, $polledValue);
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
}
