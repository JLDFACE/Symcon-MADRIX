<?php

class MadrixMaster extends IPSModule
{
    private $DataID = '{E1B4B2E6-9D1C-4D5E-9C8B-0D3B1A2C3D41}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('GlobalColors', '[]'); // [{"GlobalColorId":1},...]
        $this->RegisterPropertyBoolean('EnforceCrossfader', false);
        $this->RegisterPropertyString('CrossfadeType', 'XF');
        $this->RegisterPropertyInteger('CrossfadeValue', 0); // percent 0..100

        $this->RegisterAttributeInteger('CatGroups', 0);
        $this->RegisterAttributeInteger('CatColors', 0);

        $this->SetBuffer('GroupVarMap', json_encode(array()));  // gid => varId
        $this->SetBuffer('ColorVarMap', json_encode(array()));  // cid => varId

        $this->EnsureProfiles();
        $this->EnsureCategories();

        $this->RegisterVariableInteger('Master', 'Master', 'MADRIX.Percent', 1);
        $this->EnableAction('Master');

        $this->RegisterVariableString('FadeType', 'Fade Mode', '', 3);
        $this->RegisterVariableInteger('Crossfader', 'Crossfader', 'MADRIX.Percent', 4);

        $this->RegisterVariableBoolean('Blackout', 'Blackout', 'MADRIX.Switch', 2);
        $this->EnableAction('Blackout');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();
        $this->EnsureCategories();

        $mid = $this->GetIDForIdent('Master');
        if ($mid > 0) {
            IPS_SetVariableCustomProfile($mid, 'MADRIX.Percent');
        }

        $this->NormalizePercentVariables();
        $this->NormalizeCategories();

        $this->ApplyCrossfaderConfig();
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

    private function EnsureProfiles()
    {
        // Robust statt ~Intensity.255
        if (!IPS_VariableProfileExists('MADRIX.Intensity255')) {
            IPS_CreateVariableProfile('MADRIX.Intensity255', 1);
            IPS_SetVariableProfileValues('MADRIX.Intensity255', 0, 255, 1);
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
            $cg = $this->GetCategoryByIdentOrName($this->InstanceID, 'Groups', 'Groups');
            if ($cg == 0) {
                $cg = IPS_CreateCategory();
                @IPS_SetName($cg, 'Groups');
                @IPS_SetParent($cg, $this->InstanceID);
                @IPS_SetIdent($cg, 'Groups');
            }
            $this->WriteAttributeInteger('CatGroups', $cg);
        } else {
            if ((int)IPS_GetParent($cg) != (int)$this->InstanceID) @IPS_SetParent($cg, $this->InstanceID);
            if (IPS_GetName($cg) != 'Groups') @IPS_SetName($cg, 'Groups');
            if (@IPS_GetIdent($cg) !== 'Groups') @IPS_SetIdent($cg, 'Groups');
        }

        $cc = (int)$this->ReadAttributeInteger('CatColors');
        if ($cc == 0 || !IPS_ObjectExists($cc)) {
            $cc = $this->GetCategoryByIdentOrName($this->InstanceID, 'GlobalColors', 'Global Colors');
            if ($cc == 0) {
                $cc = IPS_CreateCategory();
                @IPS_SetName($cc, 'Global Colors');
                @IPS_SetParent($cc, $this->InstanceID);
                @IPS_SetIdent($cc, 'GlobalColors');
            }
            $this->WriteAttributeInteger('CatColors', $cc);
        } else {
            if ((int)IPS_GetParent($cc) != (int)$this->InstanceID) @IPS_SetParent($cc, $this->InstanceID);
            if (IPS_GetName($cc) != 'Global Colors') @IPS_SetName($cc, 'Global Colors');
            if (@IPS_GetIdent($cc) !== 'GlobalColors') @IPS_SetIdent($cc, 'GlobalColors');
        }
    }

    public function SyncLocal()
    {
        $this->EnsureProfiles();
        $this->EnsureCategories();
        $this->SyncColorVariablesFromConfig();
    }

    public function ApplyCrossfaderConfig()
    {
        if (!$this->ReadPropertyBoolean('EnforceCrossfader')) {
            return;
        }

        $type = trim((string)$this->ReadPropertyString('CrossfadeType'));
        if ($type !== '') {
            $this->SendToParent('SetFadeType', $type);
        }

        $p = (int)$this->ReadPropertyInteger('CrossfadeValue');
        if ($p < 0) $p = 0;
        if ($p > 100) $p = 100;
        $v = $this->PercentToByte($p);
        $this->SendToParent('SetFadeValue', $v);
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == 'Master') {
            $p = (int)$Value;
            if ($p < 0) $p = 0;
            if ($p > 100) $p = 100;
            $v = $this->PercentToByte($p);
            $this->SetValue('Master', $p);
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
            $p = (int)$Value;
            if ($p < 0) $p = 0;
            if ($p > 100) $p = 100;
            $val = $this->PercentToByte($p);
            $this->SetValue($Ident, $p);
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
            if (isset($data['master']['master'])) {
                $this->SetValue('Master', $this->ByteToPercent((int)$data['master']['master']));
            }
            if (isset($data['master']['blackout'])) $this->SetValue('Blackout', ((int)$data['master']['blackout'] == 1));
        }

        if (isset($data['fade']) && is_array($data['fade'])) {
            if (isset($data['fade']['type'])) {
                $this->SetValue('FadeType', (string)$data['fade']['type']);
            }
            if (isset($data['fade']['value'])) {
                $this->SetValue('Crossfader', $this->ByteToPercent((int)$data['fade']['value']));
            }
        }

        if (isset($data['groups']) && is_array($data['groups'])) $this->EnsureGroupVars($data['groups']);
        if (isset($data['colors']) && is_array($data['colors'])) $this->EnsureColorVars($data['colors']);
    }

    private function EnsureGroupVars($groups)
    {
        $this->EnsureCategories();
        $cat = (int)$this->ReadAttributeInteger('CatGroups');
        if ($cat == 0 || !IPS_ObjectExists($cat)) {
            $cat = $this->FindCategoryByName($this->InstanceID, 'Groups');
            if ($cat == 0) {
                $cat = IPS_CreateCategory();
                @IPS_SetName($cat, 'Groups');
                @IPS_SetParent($cat, $this->InstanceID);
            }
            $this->WriteAttributeInteger('CatGroups', $cat);
        }

        $map = $this->GetJsonBuffer('GroupVarMap');

        foreach ($groups as $g) {
            if (!is_array($g)) continue;
            $gid = isset($g['id']) ? (int)$g['id'] : 0;
            if ($gid <= 0) continue;

            $name = isset($g['name']) ? (string)$g['name'] : ('Group ' . $gid);
            $val = isset($g['val']) ? (int)$g['val'] : 0;

            $ident = 'Group_' . $gid;

            if (!isset($map[(string)$gid]) || !IPS_ObjectExists((int)$map[(string)$gid])) {
                $this->RegisterVariableInteger($ident, $name, 'MADRIX.Percent', 1000 + $gid);
                $vid = $this->GetIDForIdent($ident);
                if ($vid > 0) {
                    @IPS_SetParent($vid, $cat);
                    IPS_SetVariableCustomProfile($vid, 'MADRIX.Percent');
                    $this->EnableAction($ident);
                    $map[(string)$gid] = $vid;
                }
            } else {
                $vid = (int)$map[(string)$gid];
                if ($vid > 0 && IPS_ObjectExists($vid)) {
                    if ((int)IPS_GetParent($vid) != (int)$cat) @IPS_SetParent($vid, $cat);
                    if (IPS_GetName($vid) != $name) @IPS_SetName($vid, $name);
                    IPS_SetVariableCustomProfile($vid, 'MADRIX.Percent');
                }
            }

            if ($this->GetIDForIdent($ident) > 0) {
                $this->SetValue($ident, $this->ByteToPercent($val));
            }
        }

        $this->SetBuffer('GroupVarMap', json_encode($map));
    }

    private function EnsureColorVars($colors)
    {
        $this->EnsureCategories();
        $cat = (int)$this->ReadAttributeInteger('CatColors');
        if ($cat == 0 || !IPS_ObjectExists($cat)) {
            $cat = $this->FindCategoryByName($this->InstanceID, 'Global Colors');
            if ($cat == 0) {
                $cat = IPS_CreateCategory();
                @IPS_SetName($cat, 'Global Colors');
                @IPS_SetParent($cat, $this->InstanceID);
            }
            $this->WriteAttributeInteger('CatColors', $cat);
        }

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
                    IPS_SetVariableCustomProfile($vid, 'MADRIX.HexColor');
                    $this->EnableAction($ident);
                    $map[(string)$cid] = $vid;
                }
            } else {
                $vid = (int)$map[(string)$cid];
                if ($vid > 0 && IPS_ObjectExists($vid)) {
                    if ((int)IPS_GetParent($vid) != (int)$cat) @IPS_SetParent($vid, $cat);
                    if (IPS_GetName($vid) != $name) @IPS_SetName($vid, $name);
                    IPS_SetVariableCustomProfile($vid, 'MADRIX.HexColor');
                }
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

    private function GetCategoryByIdentOrName($parentId, $ident, $name)
    {
        $children = @IPS_GetChildrenIDs($parentId);
        if (!is_array($children)) return 0;

        foreach ($children as $id) {
            $obj = @IPS_GetObject($id);
            if (!is_array($obj)) continue;
            if ((int)$obj['ObjectType'] !== 0) continue;
            if ((string)$obj['ObjectIdent'] === $ident) return (int)$id;
        }

        foreach ($children as $id) {
            $obj = @IPS_GetObject($id);
            if (!is_array($obj)) continue;
            if ((int)$obj['ObjectType'] !== 0) continue;
            if ((string)$obj['ObjectName'] === $name) return (int)$id;
        }

        return 0;
    }

    private function NormalizeCategories()
    {
        $groupsCat = (int)$this->ReadAttributeInteger('CatGroups');
        $colorsCat = (int)$this->ReadAttributeInteger('CatColors');
        if ($groupsCat <= 0 || $colorsCat <= 0) return;

        $children = @IPS_GetChildrenIDs($this->InstanceID);
        if (!is_array($children)) return;

        foreach ($children as $id) {
            $obj = @IPS_GetObject($id);
            if (!is_array($obj)) continue;
            if ((int)$obj['ObjectType'] !== 0) continue;

            $name = (string)$obj['ObjectName'];
            if ($name === 'Groups' && (int)$id !== $groupsCat) {
                $this->MoveVariablesToCategory((int)$id, $groupsCat);
            } elseif ($name === 'Global Colors' && (int)$id !== $colorsCat) {
                $this->MoveVariablesToCategory((int)$id, $colorsCat);
            }
        }
    }

    private function MoveVariablesToCategory($fromCatId, $toCatId)
    {
        $children = @IPS_GetChildrenIDs($fromCatId);
        if (!is_array($children)) return;

        foreach ($children as $id) {
            $obj = @IPS_GetObject($id);
            if (!is_array($obj)) continue;
            if ((int)$obj['ObjectType'] === 2) {
                if ((int)IPS_GetParent($id) != (int)$toCatId) {
                    @IPS_SetParent($id, $toCatId);
                }
            }
        }
    }
    private function NormalizePercentVariables()
    {
        $varIds = $this->CollectVariableIds($this->InstanceID);
        $groupPrefix = 'Group_';

        foreach ($varIds as $vid) {
            if ($vid <= 0 || !IPS_ObjectExists($vid)) continue;
            $obj = @IPS_GetObject($vid);
            $ident = is_array($obj) ? (string)$obj['ObjectIdent'] : '';

            if ($ident === 'Master' || strpos($ident, $groupPrefix) === 0) {
                IPS_SetVariableCustomProfile($vid, 'MADRIX.Percent');
                $val = (int)@GetValueInteger($vid);
                if ($val > 100) {
                    $this->SetValue($ident, $this->ByteToPercent($val));
                }
            }
        }
    }

    private function CollectVariableIds($parentId)
    {
        $out = array();
        $children = @IPS_GetChildrenIDs($parentId);
        if (!is_array($children)) return $out;

        foreach ($children as $id) {
            $obj = @IPS_GetObject($id);
            if (!is_array($obj)) continue;

            if ((int)$obj['ObjectType'] === 2) {
                $out[] = (int)$id;
            } elseif ((int)$obj['ObjectType'] === 0) {
                $out = array_merge($out, $this->CollectVariableIds((int)$id));
            }
        }

        return $out;
    }

    private function PercentToByte($p)
    {
        $x = (int)$p;
        if ($x < 0) $x = 0;
        if ($x > 100) $x = 100;
        return (int)round(($x * 255) / 100);
    }

    private function ByteToPercent($b)
    {
        $x = (int)$b;
        if ($x < 0) $x = 0;
        if ($x > 255) $x = 255;
        return (int)round(($x * 100) / 255);
    }
}
