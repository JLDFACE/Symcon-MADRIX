<?php

class MadrixMaster extends IPSModule
{
    private $DataID = '{E1B4B2E6-9D1C-4D5E-9C8B-0D3B1A2C3D41}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('GlobalColors', '[]');

        $this->RegisterAttributeInteger('CatGroups', 0);
        $this->RegisterAttributeInteger('CatColors', 0);

        $this->SetBuffer('Pending', json_encode(array()));
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();
        $this->EnsureCategories();
        $this->EnsureBaseVariables();

        $this->SyncLocal();
    }

    public function SyncLocal()
    {
        $this->EnsureCategories();
        $this->SyncColorVariablesFromConfig();
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == 'Master') {
            $v = (int)$Value;
            if ($v < 0) $v = 0;
            if ($v > 255) $v = 255;

            $this->SetValue('Master', $v);
            $this->SetPending($Ident, $v);

            $this->SendToParent('SetMaster', $v);
            return;
        }

        if ($Ident == 'Blackout') {
            $b = ((bool)$Value) ? 1 : 0;
            $this->SetValue('Blackout', (bool)$Value);
            $this->SetPending($Ident, $b);

            $this->SendToParent('SetBlackout', $b);
            return;
        }

        if (substr($Ident, 0, 6) == 'Group_') {
            $gid = (int)substr($Ident, 6);
            $v = (int)$Value;
            if ($v < 0) $v = 0;
            if ($v > 255) $v = 255;

            $vid = $this->GetObjectIDByIdentRecursive($Ident);
            if ($vid > 0) {
                $this->SetVarValueIfChanged($vid, $v);
            }

            $this->SetPending($Ident, $v);
            $this->SendToParent('SetGroupValue', array('id' => $gid, 'value' => $v));
            return;
        }

        if (substr($Ident, 0, 12) == 'GlobalColor_') {
            $cid = (int)substr($Ident, 12);
            $hexInt = (int)$Value;

            $vid = $this->GetObjectIDByIdentRecursive($Ident);
            if ($vid > 0) {
                $this->SetVarValueIfChanged($vid, $hexInt);
            }

            $this->SetPending($Ident, $hexInt);

            $r = ($hexInt >> 16) & 0xFF;
            $g = ($hexInt >> 8) & 0xFF;
            $b = ($hexInt) & 0xFF;

            $this->SendToParent('SetGlobalColorRGB', array('id' => $cid, 'r' => $r, 'g' => $g, 'b' => $b, 'hex' => $hexInt));
            return;
        }
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) return;
        if (!isset($data['DataID']) || $data['DataID'] != $this->DataID) return;
        if (!isset($data['type']) || $data['type'] != 'status') return;

        // Master
        if (isset($data['master']) && is_array($data['master'])) {
            if (isset($data['master']['master'])) {
                $this->ApplyPolledWithPending('Master', (int)$data['master']['master'], 0);
            }
            if (isset($data['master']['blackout'])) {
                $this->ApplyPolledWithPending('Blackout', ((int)$data['master']['blackout']) == 1, 0);
            }
        }

        // Groups (slow komplett, fast nur pending)
        if (isset($data['groups']) && is_array($data['groups'])) {
            $this->EnsureGroupsFromStatus($data['groups']);
        }

        // Colors (slow komplett, fast nur pending)
        if (isset($data['colors']) && is_array($data['colors'])) {
            foreach ($data['colors'] as $c) {
                if (!is_array($c)) continue;
                $id = isset($c['id']) ? (int)$c['id'] : 0;
                $hex = isset($c['hex']) ? (int)$c['hex'] : null;
                if ($id <= 0 || $hex === null) continue;
                $ident = 'GlobalColor_' . $id;
                $this->ApplyPolledWithPending($ident, $hex, 0);
            }
        }
    }

    private function EnsureProfiles()
    {
        if (!IPS_VariableProfileExists('MADRIX.Intensity255')) {
            IPS_CreateVariableProfile('MADRIX.Intensity255', 1);
            IPS_SetVariableProfileValues('MADRIX.Intensity255', 0, 255, 1);
        }
    }

    private function EnsureCategories()
    {
        $g = (int)$this->ReadAttributeInteger('CatGroups');
        if ($g == 0 || !IPS_ObjectExists($g)) {
            $g = IPS_CreateCategory();
            IPS_SetName($g, 'Groups');
            IPS_SetParent($g, $this->InstanceID);
            $this->WriteAttributeInteger('CatGroups', $g);
        }

        $c = (int)$this->ReadAttributeInteger('CatColors');
        if ($c == 0 || !IPS_ObjectExists($c)) {
            $c = IPS_CreateCategory();
            IPS_SetName($c, 'Global Colors');
            IPS_SetParent($c, $this->InstanceID);
            $this->WriteAttributeInteger('CatColors', $c);
        }
    }

    private function EnsureBaseVariables()
    {
        $this->RegisterVariableInteger('Master', 'Master', 'MADRIX.Intensity255', 10);
        $this->EnableAction('Master');

        $this->RegisterVariableBoolean('Blackout', 'Blackout', '~Switch', 11);
        $this->EnableAction('Blackout');
    }

    private function EnsureGroupsFromStatus($groups)
    {
        $cat = (int)$this->ReadAttributeInteger('CatGroups');
        if ($cat == 0 || !IPS_ObjectExists($cat)) return;

        foreach ($groups as $g) {
            if (!is_array($g)) continue;
            $id = isset($g['id']) ? (int)$g['id'] : 0;
            $name = isset($g['name']) ? trim((string)$g['name']) : '';
            $val = isset($g['val']) ? (int)$g['val'] : 0;
            if ($id <= 0) continue;
            if ($name === '') $name = 'Group ' . $id;

            $ident = 'Group_' . $id;
            $vid = @IPS_GetObjectIDByIdent($ident, $cat);

            if ($vid == 0) {
                $vid = IPS_CreateVariable(1);
                IPS_SetParent($vid, $cat);
                IPS_SetIdent($vid, $ident);
                IPS_SetName($vid, $name);
                IPS_SetVariableCustomProfile($vid, 'MADRIX.Intensity255');
                IPS_SetVariableCustomAction($vid, $this->InstanceID);
                // Initialwert setzen (ohne IPS_SetValue!)
                $this->SetVarValueIfChanged($vid, $val);
            } else {
                if (IPS_GetName($vid) != $name) IPS_SetName($vid, $name);
                $this->ApplyPolledWithPending($ident, $val, 0);
            }
        }
    }

    private function SyncColorVariablesFromConfig()
    {
        $cat = (int)$this->ReadAttributeInteger('CatColors');
        if ($cat == 0 || !IPS_ObjectExists($cat)) return;

        $cfg = json_decode($this->ReadPropertyString('GlobalColors'), true);
        if (!is_array($cfg)) $cfg = array();

        foreach ($cfg as $entry) {
            if (!is_array($entry)) continue;
            $id = isset($entry['GlobalColorId']) ? (int)$entry['GlobalColorId'] : 0;
            if ($id <= 0) continue;

            $name = isset($entry['Name']) ? trim((string)$entry['Name']) : '';
            if ($name === '') $name = 'Global Color ' . $id;

            $ident = 'GlobalColor_' . $id;
            $vid = @IPS_GetObjectIDByIdent($ident, $cat);

            if ($vid == 0) {
                $vid = IPS_CreateVariable(1);
                IPS_SetParent($vid, $cat);
                IPS_SetIdent($vid, $ident);
                IPS_SetName($vid, $name);
                IPS_SetVariableCustomProfile($vid, '~HexColor');
                IPS_SetVariableCustomAction($vid, $this->InstanceID);
                $this->SetVarValueIfChanged($vid, 0);
            } else {
                if (IPS_GetName($vid) != $name) IPS_SetName($vid, $name);
                IPS_SetVariableCustomProfile($vid, '~HexColor');
                IPS_SetVariableCustomAction($vid, $this->InstanceID);
            }
        }
    }

    private function SendToParent($cmd, $arg)
    {
        $payload = array(
            'DataID' => $this->DataID,
            'cmd' => (string)$cmd,
            'arg' => $arg
        );
        @$this->SendDataToParent(json_encode($payload));
    }

    // Pending: verhindert UI-Flipping
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
                    $this->SetValueByIdent($ident, $polledValue);
                } else {
                    return;
                }
            } else {
                unset($pending[$ident]);
                $this->SetBuffer('Pending', json_encode($pending));
                $this->SetValueByIdent($ident, $polledValue);
            }
        } else {
            $this->SetValueByIdent($ident, $polledValue);
        }
    }

    private function SetPending($ident, $desired)
    {
        $pending = $this->GetPending();
        $pending[$ident] = array(
            'desired' => $desired,
            'deadline' => time() + 10
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

    private function SetValueByIdent($ident, $value)
    {
        // Base-Variablen oder dynamische Variablen in Kategorien
        $vid = $this->GetObjectIDByIdentRecursive($ident);
        if ($vid > 0) {
            $this->SetVarValueIfChanged($vid, $value);
        }
    }

    private function SetVarValueIfChanged($varId, $value)
    {
        if ($varId <= 0 || !IPS_ObjectExists($varId)) return;

        $cur = GetValue($varId);
        if ($cur !== $value) {
            // korrekt: SetValue(VarID, Value)
            @SetValue($varId, $value);
        }
    }

    private function GetObjectIDByIdentRecursive($ident)
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id > 0) return $id;

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $id = @IPS_GetObjectIDByIdent($ident, $cid);
            if ($id > 0) return $id;
        }
        return 0;
    }
}
