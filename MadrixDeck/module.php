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
        $this->SetBuffer('LastAssocPlace', '0');

        $this->EnsureProfiles();

        // Name bleibt konstant, Profil zeigt dynamische Association "Place X "Name""
        $this->RegisterVariableInteger('Place', 'Deck A Place', $this->GetPlaceProfile(), 10);
        $this->EnableAction('Place');

        $this->RegisterVariableFloat('Speed', 'Deck A Speed', 'MADRIX.Speed', 11);
        $this->EnableAction('Speed');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $deck = $this->GetDeck();

        // Konstanten Variablennamen setzen (falls Instanz kopiert/umbenannt wurde)
        $pvid = $this->GetIDForIdent('Place');
        if ($pvid > 0) {
            $pname = 'Deck ' . $deck . ' Place';
            if (IPS_GetName($pvid) != $pname) {
                IPS_SetName($pvid, $pname);
            }
            // Profil deckabhängig sicher setzen
            IPS_SetVariableCustomProfile($pvid, $this->GetPlaceProfile());
        }

        $svid = $this->GetIDForIdent('Speed');
        if ($svid > 0) {
            $sname = 'Deck ' . $deck . ' Speed';
            if (IPS_GetName($svid) != $sname) {
                IPS_SetName($svid, $sname);
            }
        }

        // Storage-Änderung soll sofort in MADRIX wirksam werden:
        // aktuellen Place erneut mit Storage schreiben
        $place = $this->GetVarIntByIdent('Place', 1);
        $storage = $this->GetStorage();
        $this->SendToParent('SetDeckPlace', array('deck' => $deck, 'storage' => $storage, 'place' => $place));

        // Association initial minimal korrekt (ohne Desc)
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

            // UI stabil: Association sofort auf Place X setzen (Name kommt später per Poll)
            $this->UpdatePlaceAssociation($place, '');

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

            // Profil-Association für aktuellen Place setzen (mit MADRIX Description)
            if ($place !== null) {
                $this->UpdatePlaceAssociation($place, $desc);
            }
        }
    }

    private function EnsureProfiles()
    {
        // Deck-spezifisches Place-Profil
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
    }

    private function GetPlaceProfile()
    {
        $deck = $this->GetDeck();
        return 'MADRIX.Place.' . $deck;
    }

    private function UpdatePlaceAssociation($place, $desc)
    {
        $pp = $this->GetPlaceProfile();
        if (!IPS_VariableProfileExists($pp)) {
            // falls Create/Apply Reihenfolge ungünstig war
            $this->EnsureProfiles();
        }

        $place = (int)$place;
        if ($place < 1) $place = 1;
        if ($place > 256) $place = 256;

        $t = trim((string)$desc);
        $label = 'Place ' . $place;
        if ($t !== '') {
            $label .= ' "' . $t . '"';
        }

        // Alte Association (für vorherigen Place) „neutralisieren“:
        // Wir überschreiben die alte Association auf reinen Zahlen-Text, damit die UI wieder "normal" ist.
        $last = (int)$this->GetBuffer('LastAssocPlace');
        if ($last > 0 && $last != $place) {
            IPS_SetVariableProfileAssociation($pp, $last, (string)$last, '', 0);
        }

        // Aktuelle Association setzen
        IPS_SetVariableProfileAssociation($pp, $place, $label, '', 0);

        $this->SetBuffer('LastAssocPlace', (string)$place);
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
