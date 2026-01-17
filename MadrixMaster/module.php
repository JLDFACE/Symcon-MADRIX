<?php

class MadrixMaster extends IPSModule
{
    private $DataID = '{E1B4B2E6-9D1C-4D5E-9C8B-0D3B1A2C3D41}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('GlobalColors', '[]'); // [{"GlobalColorId":1},...]

        $this->RegisterAttributeInteger('CatGroups', 0);
        $this->RegisterAttributeInteger('CatColors', 0);

        $this->SetBuffer('GroupVarMap', json_encode(array()));  // gid => varId
        $this->SetBuffer('ColorVarMap', json_encode(array()));  // cid => varId

        $this->EnsureProfiles();
        $this->EnsureCategories();

        $this->RegisterVariableInteger('Master', 'Master', 'MADRIX.Intensity255', 1);
        $this->EnableAction('Master');

        $this->RegisterVariableBoolean('Blackout', 'Blackout', '~Switch', 2);
        $this->EnableAction('Blackout');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();
        $this->EnsureCategories();
    }

    // Damit MADRIX_ForceNameSync($id) auf Master nicht crasht: an Controller weiterleiten
    public function ForceNameSync()
    {
        $payload = array(
            'DataID' => $this->DataID,
            'cmd'    => 'ForceNameSync',
            'arg'    => null
        );
        @ $this->SendDataToParent(json_encode($payload));
    }

    // Optional: Scan trigger (falls du Button im Master/Form nutzt)
    public function StartPlaceScan()
    {
        $payload = array(
            'DataID' => $this->DataID,
            'cmd'    => 'StartPlaceScan',
            'arg'    => null
        );
        @ $this->SendDataToParent(json_encode($payload));
    }

    private function EnsureProfiles()
    {
        // Robust statt ~Intensity.255
        if (!IPS_VariableProfileExists('MADRIX.Intensity255')) {
            IPS_CreateVariableProfile('MADRIX.Intensity255', 1);
            IPS_SetVariableProfileValues('MADRIX.Intensity255', 0, 255, 1);
        }

        // Robust statt ~HexColor
        if (!IPS_VariableProfileExists('MADRIX.HexColor')) {
            IPS_CreateVariableProfile('MADRIX.HexColor', 1);
            IPS_SetVariableProfileValues('MADRIX.HexColor', 0, 16777215, 1);
        }
    }

    private function EnsureCategories()
    {
        $cg = (int)$this->ReadAttributeInteger('CatGroups');
        if ($cg == 0 || !IPS_ObjectExists($cg)) {
            $cg = IPS_CreateCategory();
            @IPS_SetName($cg, 'Groups');
            @IPS_SetParent($cg, $this->InstanceID);
            $this->WriteAttributeInteger('CatGroups', $cg);
        } else {
            if ((int)IPS_GetParent($cg) != (int)$this->InstanceID) @IPS_SetParent($cg, $this->InstanceID);
            if (IPS_GetName($cg) != 'Groups') @IPS_SetName($cg, 'Groups');
        }

        $cc = (int)$this->ReadAttributeInteger('CatColors');
        if ($cc == 0 || !IPS_ObjectExists($cc)) {
            $cc = IPS_CreateCategory();
            @IPS_SetName($cc, 'Global Colors');
            @IPS_SetParent($cc, $this->InstanceID);
            $this->WriteAttributeInteger('CatColors', $cc);
        } else {
            if ((int)IPS_GetParent($cc) != (int)$this->InstanceID) @IPS_SetParent($cc, $this->InstanceID);
            if (IPS_GetName($cc) != 'Global Colors') @IPS_SetName($cc, 'Global Colors');
        }
    }

    public function SyncLocal()
    {
        $this->EnsureProfiles();
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
            $this->SendToParent('SetMaster', $v);
            return;
        }

        if ($Ident == 'Blackout') {
            $b = ((bool)$Value) ? true : false;
            $this->SetValue('Blackout', $b);
            $this->SendToParent('SetBlackout', $b);
            return;
        }

        if (substr($Ident, 0, 6) == 'Group_') {
            $gid = (int)substr($Ident, 6);
            $val = (int)$Value;
            if ($val < 0) $val = 0;
            if ($val > 255) $val = 255;
            $this->SetValue($Ident, $val);
            $this->SendToParent('SetGroupValue', array('id' => $gid, 'value' => $val));
            return;
        }

        if (substr($Ident, 0, 6) == 'Color_') {
            $cid = (int)substr($Ident, 6);
            $hex = (int)$Value;

            if ($hex < 0) $hex = 0;
            if ($hex > 16777215) $hex = 16777215;

            $r = ($hex >> 16) & 0xFF;
            $g = ($hex >> 8) & 0xFF;
            $b = $hex & 0xFF;

            $this->SetValue($Ident, $hex);
            $this->SendToParent('SetGlobalColorRGB', array('id' => $cid, 'r' => $r, 'g' => $g, 'b' => $b, 'hex' => $hex));
            return;
        }
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) return;
        if (!isset($data['DataID']) || $data['DataID'] != $this->DataID) return;
        if (!isset($data['type']) || $data['type'] != 'status') return;

        if (isset($data['master']) && is_array($data['master'])) {
            if (isset($data['master']['master'])) $this->SetValue('Master', (int)$data['master']['master']);
            if (isset($data['master']['blackout'])) $this->SetValue('Blackout', ((int)$data['master']['blackout'] == 1));
        }

        if (isset($data['groups']) && is_array($data['groups'])) $this->EnsureGroupVars($data['groups']);
        if (isset($data['colors']) && is_array($data['colors'])) $this->EnsureColorVars($data['colors']);
    }

    private function EnsureGroupVars($groups)
    {
        $this->EnsureCategories();
        $cat = (int)$this->ReadAttributeInteger('CatGroups');

        $map = $this->GetJsonBuffer('GroupVarMap');

        foreach ($groups as $g) {
            if (!is_array($g)) continue;
            $gid = isset($g['id']) ? (int)$g['id'] : 0;
            if ($gid <= 0) continue;

            $name = isset($g['name']) ? (string)$g['name'] : ('Group ' . $gid);
            $val = isset($g['val']) ? (int)$g['val'] : 0;

            $ident = 'Group_' . $gid;

            if (!isset($map[(string)$gid]) || !IPS_ObjectExists((int)$map[(string)$gid])) {
                $this->RegisterVariableInteger($ident, $name, 'MADRIX.Intensity255', 1000 + $gid);
                $vid = $this->GetIDForIdent($ident);
                if ($vid > 0) {
                    @IPS_SetParent($vid, $cat);
                    $this->EnableAction($ident);
                    $map[(string)$gid] = $vid;
                }
            } else {
                $vid = (int)$map[(string)$gid];
                if ($vid > 0 && IPS_ObjectExists($vid) && IPS_GetName($vid) != $name) @IPS_SetName($vid, $name);
            }

            if ($this->GetIDForIdent($ident) > 0) $this->SetValue($ident, $val);
        }

        $this->SetBuffer('GroupVarMap', json_encode($map));
    }

    private function EnsureColorVars($colors)
    {
        $this->EnsureCategories();
        $cat = (int)$this->ReadAttributeInteger('CatColors');

        $map = $this->GetJsonBuffer('ColorVarMap');

        foreach ($colors as $c) {
            if (!is_array($c)) continue;
            $cid = isset($c['id']) ? (int)$c['id'] : 0;
            if ($cid <= 0) continue;

            $hex = isset($c['hex']) ? (int)$c['hex'] : 0;
            if ($hex < 0) $hex = 0;
            if ($hex > 16777215) $hex = 16777215;

            $ident = 'Color_' . $cid;
            $name = 'Global Color ' . $cid;

            if (!isset($map[(string)$cid]) || !IPS_ObjectExists((int)$map[(string)$cid])) {
                $this->RegisterVariableInteger($ident, $name, 'MADRIX.HexColor', 2000 + $cid);
                $vid = $this->GetIDForIdent($ident);
                if ($vid > 0) {
                    @IPS_SetParent($vid, $cat);
                    $this->EnableAction($ident);
                    $map[(string)$cid] = $vid;
                }
            } else {
                $vid = (int)$map[(string)$cid];
                if ($vid > 0 && IPS_ObjectExists($vid) && IPS_GetName($vid) != $name) @IPS_SetName($vid, $name);
            }

            if ($this->GetIDForIdent($ident) > 0) $this->SetValue($ident, $hex);
        }

        $this->SetBuffer('ColorVarMap', json_encode($map));
    }

    private function SendToParent($cmd, $arg)
    {
        $payload = array('DataID' => $this->DataID, 'cmd' => (string)$cmd, 'arg' => $arg);
        @ $this->SendDataToParent(json_encode($payload));
    }

    private function GetJsonBuffer($name)
    {
        $raw = $this->GetBuffer($name);
        $arr = json_decode($raw, true);
        if (!is_array($arr)) $arr = array();
        return $arr;
    }
}
