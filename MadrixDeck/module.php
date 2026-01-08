<?php

class MadrixDeck extends IPSModule
{
    private $DataID = '{E1B4B2E6-9D1C-4D5E-9C8B-0D3B1A2C3D41}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Deck', 'A');
        $this->RegisterPropertyInteger('Storage', 1);

        $this->SetBuffer('Pending', json_encode(array()));

        $this->EnsureProfiles();

        $this->RegisterVariableInteger('Place', 'Deck A Place: 1', 'MADRIX.Place', 10);
        $this->EnableAction('Place');

        $this->RegisterVariableFloat('Speed', 'Deck A Speed', 'MADRIX.Speed', 11);
        $this->EnableAction('Speed');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $deck = $this->GetDeck();

        // sichere Abfrage des aktuellen Place-Wertes
        $place = $this->GetVarIntByIdent('Place', 1);
        $this->SetDeckPlaceName($deck, $place, '');

        $sname = 'Deck ' . $deck . ' Speed';
        $svid = $this->GetIDForIdent('Speed');
        if ($svid > 0 && IPS_GetName($svid) != $sname) {
            IPS_SetName($svid, $sname);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == 'Place') {
            $place = (int)$Value;
            if ($place < 1) $place = 1;
            if ($place > 256) $place = 256;

            $this->SetValue('Place', $place);
            $this->SetPending('Place', $place, 10);

            $deck = $this->GetDeck();
            $storage = $this->GetStorage();

            $this->SendToParent('SetDeckPlace', array('deck' => $deck, 'storage' => $storage, 'place' => $place));
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
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) return;
        if (!isset($data['DataID']) || $data['DataID'] != $this->DataID) return;
        if (!isset($data['type']) || $data['type'] != 'status') return;

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

            // Name nur ändern, wenn Description geliefert wird
            if (trim($desc) !== '') {
                $curPlace = $this->GetVarIntByIdent('Place', 1);
                $this->SetDeckPlaceName($myDeck, $curPlace, $desc);
            }
        }
    }

    private function EnsureProfiles()
    {
        if (!IPS_VariableProfileExists('MADRIX.Place')) {
            IPS_CreateVariableProfile('MADRIX.Place', 1);
            IPS_SetVariableProfileValues('MADRIX.Place', 1, 256, 1);
        }

        if (!IPS_VariableProfileExists('MADRIX.Speed')) {
            IPS_CreateVariableProfile('MADRIX.Speed', 2);
            IPS_SetVariableProfileValues('MADRIX.Speed', -10, 10, 0.1);
            IPS_SetVariableProfileDigits('MADRIX.Speed', 1);
        }
    }

    private function SetDeckPlaceName($deck, $place, $desc)
    {
        // Deck A Place: 1 "Intro"
        $name = 'Deck ' . $deck . ' Place: ' . (int)$place;
        $t = trim((string)$desc);
        if ($t !== '') {
            $name .= ' "' . $t . '"';
        }

        $vid = $this->GetIDForIdent('Place');
        if ($vid > 0 && IPS_GetName($vid) != $name) {
            IPS_SetName($vid, $name);
        }
    }

    private function SendToParent($cmd, $arg)
    {
        $payload = array(
            'DataID' => $this->DataID,
            'cmd' => (string)$cmd,
            'arg' => $arg
        );
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

    // Pending-Logik (UI flippt nicht)
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
        $pending[$ident] = array(
            'desired' => $desired,
            'deadline' => time() + (int)$seconds
        );
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
}
