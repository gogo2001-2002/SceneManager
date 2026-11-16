<?php

declare(strict_types=1);

class SceneManager extends IPSModule
{
    private const PROFILE_EXECUTE = 'SM.Execute';
    private const PROFILE_SAVE = 'SM.Save';
    private const PROFILE_MODE = 'SM.Mode';

    public function Create(): void
    {
        parent::Create();

        // Devices is retained for one-time migration from version 1.0.
        $this->RegisterPropertyString('Devices', '[]');
        $this->RegisterPropertyString('Scenes', '[]');
        $this->RegisterPropertyBoolean('UseHTMLTile', true);
        $this->RegisterPropertyBoolean('ResetExecutionVariable', false);
        $this->RegisterPropertyInteger('SwitchDelay', 50);

        $this->RegisterAttributeString('SceneValues', '{}');
        $this->RegisterAttributeInteger('ActiveSceneID', 0);

        $this->EnsureProfiles();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->EnsureProfiles();
        $this->MigrateLegacyDeviceTargets();

        $this->SetVisualizationType($this->ReadPropertyBoolean('UseHTMLTile') ? 1 : 0);

        foreach ($this->GetReferenceList() as $refId) {
            $this->UnregisterReference($refId);
        }
        foreach ($this->GetSceneValues() as $targets) {
            foreach ($targets as $target) {
                $varId = (int)($target['variableID'] ?? 0);
                if ($varId > 0 && IPS_ObjectExists($varId)) {
                    $this->RegisterReference($varId);
                }
            }
        }

        $this->MaintainVariable('Mode', $this->Translate('Mode'), 1, self::PROFILE_MODE, 10, true);
        $this->EnableAction('Mode');
        $this->MaintainVariable('ActiveScene', $this->Translate('Active Scene'), 3, '', 20, true);

        $scenes = $this->GetConfiguredScenes();
        $existingVars = ['Mode', 'ActiveScene'];
        $position = 100;
        foreach ($scenes as $scene) {
            $sceneId = (int)($scene['id'] ?? 0);
            if ($sceneId <= 0) {
                continue;
            }
            $ident = 'Scene_' . $sceneId;
            $existingVars[] = $ident;
            $this->MaintainVariable($ident, (string)($scene['name'] ?? ('Scene ' . $sceneId)), 0, '~Switch', $position++, true);
            $this->EnableAction($ident);

            $saveIdent = 'SaveScene_' . $sceneId;
            $createSave = !empty($scene['createSaveVariable']);
            $this->MaintainVariable($saveIdent, sprintf($this->Translate('Save %s'), (string)($scene['name'] ?? ('Scene ' . $sceneId))), 0, self::PROFILE_SAVE, $position++, $createSave);
            if ($createSave) {
                $existingVars[] = $saveIdent;
                $this->EnableAction($saveIdent);
            }

            $sceneVariableId = $this->GetIDForIdent($ident);
            if ($sceneVariableId > 0 && !empty($scene['icon'])) {
                IPS_SetIcon($sceneVariableId, (string)$scene['icon']);
            }
        }

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            $child = IPS_GetObject($childId);
            $ident = (string)($child['ObjectIdent'] ?? '');
            if ((str_starts_with($ident, 'Scene_') || str_starts_with($ident, 'SaveScene_')) && !in_array($ident, $existingVars, true)) {
                $this->UnregisterVariable($ident);
            }
        }

        $this->PushVisualizationState();
    }

    public function RequestAction($Ident, $Value)
    {
        $this->SendDebug('RequestAction', "Ident: {$Ident}, Value: " . json_encode($Value), 0);

        if ($Ident === 'Mode') {
            $this->SetValue('Mode', (int)$Value);
            $this->PushVisualizationState();
            return;
        }
        if ($Ident === 'GetTileState') {
            $this->PushVisualizationState();
            return;
        }
        if ($Ident === 'SearchDevices') {
            $query = is_string($Value) ? $Value : '';
            $this->PushVisualizationState(null, $this->SearchDevices($query));
            return;
        }
        if (preg_match('/^Scene_(\d+)$/', (string)$Ident, $match)) {
            $sceneId = (int)$match[1];
            if ((int)$this->GetValue('Mode') === 1) {
                $this->SaveScene($sceneId);
                $this->SetValue($Ident, false);
            } else {
                $this->ExecuteScene($sceneId);
            }
            return;
        }
        if (preg_match('/^SaveScene_(\d+)$/', (string)$Ident, $match)) {
            $this->SaveScene((int)$match[1]);
            $this->SetValue($Ident, false);
            return;
        }
        if ($Ident === 'ExecuteScene') {
            $this->ExecuteScene((int)$Value);
            return;
        }
        if ($Ident === 'SaveScene') {
            $this->SaveScene((int)$Value);
            return;
        }

        $payload = is_string($Value) ? json_decode($Value, true) : $Value;
        if ($Ident === 'CreateScene' && is_array($payload)) {
            $this->CreateScene(
                (string)($payload['name'] ?? ''),
                (string)($payload['icon'] ?? 'Film'),
                (array)($payload['targets'] ?? []),
                !empty($payload['createSaveVariable'])
            );
            return;
        }
        if ($Ident === 'UpdateScene' && is_array($payload) && isset($payload['sceneId'])) {
            $this->UpdateScene(
                (int)$payload['sceneId'],
                (string)($payload['name'] ?? ''),
                (string)($payload['icon'] ?? ''),
                (array)($payload['targets'] ?? []),
                isset($payload['createSaveVariable']) ? (bool)$payload['createSaveVariable'] : null
            );
            return;
        }
        if ($Ident === 'DeleteScene') {
            $this->DeleteScene((int)$Value);
            return;
        }
        if ($Ident === 'SaveSceneDetails' && is_array($payload) && isset($payload['sceneId'], $payload['targets'])) {
            $this->UpdateSceneTargets((int)$payload['sceneId'], (array)$payload['targets']);
            return;
        }

        throw new Exception("Unknown action: {$Ident}");
    }

    public function ExecuteScene(int $SceneID): void
    {
        $scenes = $this->GetConfiguredScenes();
        $scene = $this->FindScene($SceneID, $scenes);
        if ($scene === null) {
            throw new InvalidArgumentException("Scene {$SceneID} does not exist.");
        }

        $targets = $this->GetSceneValues()[(string)$SceneID] ?? [];
        $delayMs = max(0, $this->ReadPropertyInteger('SwitchDelay'));
        foreach ($targets as $key => $target) {
            $varId = (int)($target['variableID'] ?? $key);
            try {
                if ($varId <= 0 || !IPS_VariableExists($varId)) {
                    throw new RuntimeException('Variable no longer exists.');
                }
                $value = $this->ValidateActionValue($varId, $target['value'] ?? null);
                RequestAction($varId, $value);
            } catch (Throwable $e) {
                $name = (string)($target['name'] ?? ('Variable ' . $varId));
                $this->LogMessage("Error setting scene device '{$name}' ({$varId}): " . $e->getMessage(), KL_WARNING);
            }
            if ($delayMs > 0) {
                IPS_Sleep($delayMs);
            }
        }

        $sceneName = (string)($scene['name'] ?? ('Scene ' . $SceneID));
        $this->WriteAttributeInteger('ActiveSceneID', $SceneID);
        $this->SetValue('ActiveScene', $sceneName);

        foreach ($scenes as $item) {
            $sceneVariable = 'Scene_' . (int)$item['id'];
            if ($this->GetIDForIdent($sceneVariable) > 0) {
                $this->SetValue($sceneVariable, $this->ReadPropertyBoolean('ResetExecutionVariable') ? false : ((int)$item['id'] === $SceneID));
            }
        }
        $this->PushVisualizationState(sprintf($this->Translate('Scene "%s" executed'), $sceneName));
    }

    public function SaveScene(int $SceneID): void
    {
        $scene = $this->FindScene($SceneID);
        if ($scene === null) {
            throw new InvalidArgumentException("Scene {$SceneID} does not exist.");
        }
        $values = $this->GetSceneValues();
        $targets = $values[(string)$SceneID] ?? [];
        foreach ($targets as $key => &$target) {
            $varId = (int)($target['variableID'] ?? $key);
            if ($varId > 0 && IPS_VariableExists($varId)) {
                $target['variableID'] = $varId;
                $target['value'] = $this->ValidateActionValue($varId, GetValue($varId));
                $target['name'] = $this->GetObjectPath($varId);
            }
        }
        unset($target);
        $values[(string)$SceneID] = $this->NormalizeSceneTargets($targets);
        $this->WriteAttributeString('SceneValues', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->PushVisualizationState(sprintf($this->Translate('Current state saved to scene "%s"'), (string)$scene['name']));
    }

    public function CreateScene(string $Name, string $Icon = 'Film', array $Targets = [], bool $CreateSaveVariable = false): int
    {
        $Name = trim($Name);
        if ($Name === '') {
            throw new InvalidArgumentException('Scene name cannot be empty.');
        }
        $scenes = $this->GetConfiguredScenes();
        $maxId = 0;
        foreach ($scenes as $scene) {
            $maxId = max($maxId, (int)($scene['id'] ?? 0));
        }
        $newId = $maxId + 1;
        $scene = [
            'id' => $newId,
            'name' => $Name,
            'icon' => $Icon !== '' ? $Icon : 'Film',
            'createSaveVariable' => $CreateSaveVariable
        ];
        $scenes[] = $scene;
        $values = $this->GetSceneValues();
        $values[(string)$newId] = $this->NormalizeSceneTargets($Targets);
        $this->WriteAttributeString('SceneValues', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        IPS_SetProperty($this->InstanceID, 'Scenes', json_encode($scenes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        IPS_ApplyChanges($this->InstanceID);
        $this->PushVisualizationState(sprintf($this->Translate('Scene "%s" created'), $Name));
        return $newId;
    }

    public function DeleteScene(int $SceneID): void
    {
        $scenes = $this->GetConfiguredScenes();
        $scene = $this->FindScene($SceneID, $scenes);
        if ($scene === null) {
            return;
        }
        $scenes = array_values(array_filter($scenes, static fn(array $item): bool => (int)($item['id'] ?? 0) !== $SceneID));
        $values = $this->GetSceneValues();
        unset($values[(string)$SceneID]);
        $this->WriteAttributeString('SceneValues', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($this->ReadAttributeInteger('ActiveSceneID') === $SceneID) {
            $this->WriteAttributeInteger('ActiveSceneID', 0);
            $this->SetValue('ActiveScene', '');
        }
        IPS_SetProperty($this->InstanceID, 'Scenes', json_encode($scenes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        IPS_ApplyChanges($this->InstanceID);
        $this->PushVisualizationState(sprintf($this->Translate('Scene "%s" deleted'), (string)$scene['name']));
    }

    public function UpdateScene(int $SceneID, string $Name, string $Icon, array $Targets, ?bool $CreateSaveVariable = null): void
    {
        $scenes = $this->GetConfiguredScenes();
        $found = false;
        $finalName = $Name;
        foreach ($scenes as &$scene) {
            if ((int)($scene['id'] ?? 0) !== $SceneID) {
                continue;
            }
            if (trim($Name) !== '') {
                $scene['name'] = trim($Name);
                $finalName = trim($Name);
            }
            if ($Icon !== '') {
                $scene['icon'] = $Icon;
            }
            if ($CreateSaveVariable !== null) {
                $scene['createSaveVariable'] = $CreateSaveVariable;
            }
            $found = true;
            break;
        }
        unset($scene);
        if (!$found) {
            throw new InvalidArgumentException("Scene {$SceneID} does not exist.");
        }

        $values = $this->GetSceneValues();
        $values[(string)$SceneID] = $this->NormalizeSceneTargets($Targets);
        $this->WriteAttributeString('SceneValues', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        IPS_SetProperty($this->InstanceID, 'Scenes', json_encode($scenes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        IPS_ApplyChanges($this->InstanceID);
        $this->PushVisualizationState(sprintf($this->Translate('Scene "%s" updated'), $finalName !== '' ? $finalName : (string)$SceneID));
    }

    public function UpdateSceneTargets(int $SceneID, array $Targets): void
    {
        if ($this->FindScene($SceneID) === null) {
            throw new InvalidArgumentException("Scene {$SceneID} does not exist.");
        }
        $values = $this->GetSceneValues();
        $values[(string)$SceneID] = $this->NormalizeSceneTargets($Targets);
        $this->WriteAttributeString('SceneValues', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->RefreshReferences();
        $this->PushVisualizationState(sprintf($this->Translate('Scene "%s" updated'), (string)($this->FindScene($SceneID)['name'] ?? $SceneID)));
    }

    public function SetSceneDeviceValue(int $SceneID, int $DeviceID, $Value, bool $Enabled = true): void
    {
        if ($this->FindScene($SceneID) === null) {
            throw new InvalidArgumentException("Scene {$SceneID} does not exist.");
        }
        $values = $this->GetSceneValues();
        $targets = $values[(string)$SceneID] ?? [];
        if (!$Enabled) {
            unset($targets[(string)$DeviceID]);
        } else {
            $targets[(string)$DeviceID] = ['variableID' => $DeviceID, 'value' => $Value];
        }
        $values[(string)$SceneID] = $this->NormalizeSceneTargets($targets);
        $this->WriteAttributeString('SceneValues', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->RefreshReferences();
        $this->PushVisualizationState();
    }

    public function GetScenes(): array
    {
        return $this->GetConfiguredScenes();
    }

    /**
     * Returns the unique Symcon action variables currently used in scenes.
     * Kept for scripts that used SM_GetDevices() in version 1.0.
     */
    public function GetDevices(): array
    {
        $devices = [];
        foreach ($this->GetSceneValues() as $targets) {
            foreach ($targets as $target) {
                $varId = (int)($target['variableID'] ?? 0);
                if ($varId > 0 && IPS_VariableExists($varId)) {
                    $devices[(string)$varId] = $this->GetActionVariableInfo($varId);
                }
            }
        }
        return array_values($devices);
    }

    public function SearchDevices(string $Query, int $Limit = 60): array
    {
        $query = trim($Query);
        if ((function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query)) < 2) {
            return [];
        }
        $limit = max(1, min(100, $Limit));
        $matches = [];
        $needle = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
        foreach (IPS_GetVariableList() as $varId) {
            $varId = (int)$varId;
            $variable = IPS_GetVariable($varId);
            if ((int)($variable['VariableAction'] ?? 0) <= 0 || (int)IPS_GetObject($varId)['ParentID'] === $this->InstanceID) {
                continue;
            }
            $path = $this->GetObjectPath($varId);
            $haystack = function_exists('mb_strtolower') ? mb_strtolower($path, 'UTF-8') : strtolower($path);
            $position = function_exists('mb_strpos') ? mb_strpos($haystack, $needle, 0, 'UTF-8') : strpos($haystack, $needle);
            if ($position === false) {
                continue;
            }
            $info = $this->GetActionVariableInfo($varId);
            if ($info['profileReadOnly']) {
                continue;
            }
            $matches[] = $info;
            if (count($matches) >= $limit) {
                break;
            }
        }
        return $matches;
    }

    public function GetTileState(): array
    {
        return $this->BuildVisualizationState();
    }

    public function GetVisualizationTile(): string
    {
        $htmlFile = __DIR__ . '/module.html';
        return is_file($htmlFile) ? (string)file_get_contents($htmlFile) : '<div style="padding:10px;color:red;">module.html not found</div>';
    }

    private function BuildVisualizationState(?string $Toast = null, array $SearchResults = []): array
    {
        $values = $this->GetSceneValues();
        $sceneValues = [];
        foreach ($values as $sceneId => $targets) {
            $sceneValues[(string)$sceneId] = [];
            foreach ($targets as $key => $target) {
                $varId = (int)($target['variableID'] ?? $key);
                if ($varId <= 0 || !IPS_VariableExists($varId)) {
                    continue;
                }
                $info = $this->GetActionVariableInfo($varId);
                $info['value'] = $target['value'] ?? $info['currentValue'];
                $sceneValues[(string)$sceneId][(string)$varId] = $info;
            }
        }
        return [
            'type' => 'state',
            'instanceId' => $this->InstanceID,
            'mode' => (int)$this->GetValue('Mode'),
            'activeSceneId' => $this->ReadAttributeInteger('ActiveSceneID'),
            'scenes' => $this->GetConfiguredScenes(),
            'devices' => $this->GetDevices(),
            'sceneValues' => $sceneValues,
            'searchResults' => $SearchResults,
            'toast' => $Toast
        ];
    }

    private function NormalizeSceneTargets(array $Targets): array
    {
        $normalized = [];
        foreach ($Targets as $key => $target) {
            if (!is_array($target)) {
                throw new InvalidArgumentException('Invalid scene target.');
            }
            $varId = (int)($target['variableID'] ?? $key);
            if ($varId <= 0 || !IPS_VariableExists($varId)) {
                throw new InvalidArgumentException("Selected variable {$varId} does not exist.");
            }
            $value = $this->ValidateActionValue($varId, $target['value'] ?? GetValue($varId));
            $info = $this->GetActionVariableInfo($varId);
            $info['value'] = $value;
            $normalized[(string)$varId] = $info;
        }
        return $normalized;
    }

    private function ValidateActionValue(int $VariableID, $Value)
    {
        if (!IPS_VariableExists($VariableID)) {
            throw new InvalidArgumentException("Variable {$VariableID} does not exist.");
        }
        $variable = IPS_GetVariable($VariableID);
        if ((int)($variable['VariableAction'] ?? 0) <= 0) {
            throw new InvalidArgumentException("Variable {$VariableID} has no action.");
        }
        $type = (int)$variable['VariableType'];
        switch ($type) {
            case 0:
                if (is_bool($Value)) {
                    $value = $Value;
                } elseif ($Value === 0 || $Value === 1 || $Value === '0' || $Value === '1') {
                    $value = (bool)$Value;
                } else {
                    throw new InvalidArgumentException('Boolean targets must be true or false.');
                }
                break;
            case 1:
                if (!is_numeric($Value) || (float)$Value !== (float)(int)$Value) {
                    throw new InvalidArgumentException('Integer target must be a whole number.');
                }
                $value = (int)$Value;
                break;
            case 2:
                if (!is_numeric($Value) || !is_finite((float)$Value)) {
                    throw new InvalidArgumentException('Float target must be numeric.');
                }
                $value = (float)$Value;
                break;
            case 3:
                if (!is_scalar($Value)) {
                    throw new InvalidArgumentException('String target must be scalar.');
                }
                $value = (string)$Value;
                break;
            default:
                throw new InvalidArgumentException('Unsupported variable type.');
        }

        $profileName = $this->GetEffectiveProfileName($variable);
        if ($profileName !== '' && IPS_VariableProfileExists($profileName)) {
            $profile = IPS_GetVariableProfile($profileName);
            if (!empty($profile['IsReadOnly'])) {
                throw new InvalidArgumentException("Profile {$profileName} is read-only.");
            }
            $associations = $profile['Associations'] ?? [];
            if ($type === 1 || $type === 2) {
                $numeric = (float)$value;
                $min = (float)($profile['MinValue'] ?? 0);
                $max = (float)($profile['MaxValue'] ?? 0);
                if ((count($associations) === 0 || $max > $min) && ($numeric < $min || $numeric > $max)) {
                    throw new InvalidArgumentException("Target is outside the limits of profile {$profileName}.");
                }
                $step = (float)($profile['StepSize'] ?? 0);
                if ($step > 0 && abs((($numeric - $min) / $step) - round(($numeric - $min) / $step)) > 0.000001) {
                    throw new InvalidArgumentException("Target does not match the step size of profile {$profileName}.");
                }
            }
            if (count($associations) > 0) {
                $allowed = false;
                foreach ($associations as $association) {
                    $candidate = $association['Value'] ?? null;
                    if (is_numeric($candidate) && is_numeric($value)) {
                        $match = abs((float)$candidate - (float)$value) < 0.000001;
                    } else {
                        $match = (string)$candidate === (string)$value;
                    }
                    if ($match) {
                        $allowed = true;
                        break;
                    }
                }
                if (!$allowed) {
                    throw new InvalidArgumentException("Target is not an allowed association of profile {$profileName}.");
                }
            }
        }
        return $value;
    }

    private function GetActionVariableInfo(int $VariableID): array
    {
        $variable = IPS_GetVariable($VariableID);
        $profileName = $this->GetEffectiveProfileName($variable);
        $profileData = [
            'profileName' => $profileName,
            'profileMin' => null,
            'profileMax' => null,
            'profileStep' => null,
            'profileReadOnly' => false,
            'associations' => [],
            'profileIcon' => '',
            'profileSuffix' => '',
            'profilePrefix' => '',
            'profileDigits' => 0
        ];
        if ($profileName !== '' && IPS_VariableProfileExists($profileName)) {
            $profile = IPS_GetVariableProfile($profileName);
            $profileData = [
                'profileName' => $profileName,
                'profileMin' => $profile['MinValue'] ?? null,
                'profileMax' => $profile['MaxValue'] ?? null,
                'profileStep' => $profile['StepSize'] ?? null,
                'profileReadOnly' => (bool)($profile['IsReadOnly'] ?? false),
                'associations' => $profile['Associations'] ?? [],
                'profileIcon' => (string)($profile['Icon'] ?? ''),
                'profileSuffix' => (string)($profile['Suffix'] ?? ''),
                'profilePrefix' => (string)($profile['Prefix'] ?? ''),
                'profileDigits' => (int)($profile['Digits'] ?? 0)
            ];
        }
        $typeNames = ['boolean', 'integer', 'float', 'string'];
        return [
            'variableID' => $VariableID,
            'name' => IPS_GetName($VariableID),
            'path' => $this->GetObjectPath($VariableID),
            'variableType' => (int)$variable['VariableType'],
            'typeName' => $typeNames[(int)$variable['VariableType']] ?? 'unknown',
            'currentValue' => GetValue($VariableID),
            'currentFormatted' => GetValueFormatted($VariableID),
            'actionable' => (int)($variable['VariableAction'] ?? 0) > 0,
            'profileName' => $profileData['profileName'],
            'profileMin' => $profileData['profileMin'],
            'profileMax' => $profileData['profileMax'],
            'profileStep' => $profileData['profileStep'],
            'profileReadOnly' => $profileData['profileReadOnly'],
            'associations' => $profileData['associations'],
            'profileIcon' => $profileData['profileIcon'],
            'profileSuffix' => $profileData['profileSuffix'],
            'profilePrefix' => $profileData['profilePrefix'],
            'profileDigits' => $profileData['profileDigits']
        ];
    }

    private function GetEffectiveProfileName(array $Variable): string
    {
        $custom = (string)($Variable['VariableCustomProfile'] ?? '');
        return $custom !== '' ? $custom : (string)($Variable['VariableProfile'] ?? '');
    }

    private function GetObjectPath(int $ObjectID): string
    {
        $parts = [];
        $currentId = $ObjectID;
        while ($currentId > 0 && IPS_ObjectExists($currentId)) {
            $object = IPS_GetObject($currentId);
            array_unshift($parts, (string)($object['ObjectName'] ?? $currentId));
            $currentId = (int)($object['ParentID'] ?? 0);
        }
        return implode('\\', $parts);
    }

    private function FindScene(int $SceneID, ?array $Scenes = null): ?array
    {
        foreach ($Scenes ?? $this->GetConfiguredScenes() as $scene) {
            if ((int)($scene['id'] ?? 0) === $SceneID) {
                return $scene;
            }
        }
        return null;
    }

    private function GetConfiguredScenes(): array
    {
        $scenes = json_decode((string)$this->ReadPropertyString('Scenes'), true);
        return is_array($scenes) ? $scenes : [];
    }

    private function GetSceneValues(): array
    {
        $values = json_decode((string)$this->ReadAttributeString('SceneValues'), true);
        return is_array($values) ? $values : [];
    }

    private function MigrateLegacyDeviceTargets(): void
    {
        $legacyDevices = json_decode((string)$this->ReadPropertyString('Devices'), true);
        $legacyDevices = is_array($legacyDevices) ? $legacyDevices : [];
        if (count($legacyDevices) === 0) {
            return;
        }
        $deviceMap = [];
        foreach ($legacyDevices as $device) {
            $deviceMap[(string)($device['id'] ?? '')] = $device;
        }

        $allValues = $this->GetSceneValues();
        foreach ($this->GetConfiguredScenes() as $scene) {
            $sceneId = (string)(int)($scene['id'] ?? 0);
            if ($sceneId === '0') {
                continue;
            }
            $oldTargets = $allValues[$sceneId] ?? ($scene['targets'] ?? []);
            if (!is_array($oldTargets)) {
                $oldTargets = [];
            }
            $newTargets = [];
            foreach ($oldTargets as $deviceId => $target) {
                $device = $deviceMap[(string)$deviceId] ?? null;
                if (!is_array($device)) {
                    continue;
                }
                $varId = (int)($device['variableID'] ?? 0);
                if ($varId <= 0 || !IPS_VariableExists($varId) || !is_array($target)) {
                    continue;
                }
                $value = $target['value'] ?? GetValue($varId);
                if (($device['type'] ?? '') === 'shutter' && is_numeric($value)) {
                    $variable = IPS_GetVariable($varId);
                    $profileName = $this->GetEffectiveProfileName($variable);
                    if ($profileName !== '' && IPS_VariableProfileExists($profileName)) {
                        $profile = IPS_GetVariableProfile($profileName);
                        if ((int)$variable['VariableType'] === 2 && (float)$profile['MaxValue'] <= 1.0 && (float)$profile['MaxValue'] > 0 && (float)$value > 1.0) {
                            $value = (float)$value / 100.0;
                        }
                    }
                } elseif (in_array((string)($device['type'] ?? ''), ['light', 'switch', 'climate'], true) && !in_array((int)IPS_GetVariable($varId)['VariableType'], [0, 3], true) && is_bool($value)) {
                    $variable = IPS_GetVariable($varId);
                    $profileName = $this->GetEffectiveProfileName($variable);
                    if ($profileName !== '' && IPS_VariableProfileExists($profileName)) {
                        $profile = IPS_GetVariableProfile($profileName);
                        $value = $value ? $profile['MaxValue'] : $profile['MinValue'];
                    } else {
                        $value = $value ? 1 : 0;
                    }
                }
                try {
                    $newTargets[(string)$varId] = ['variableID' => $varId, 'value' => $this->ValidateActionValue($varId, $value)];
                } catch (Throwable $e) {
                    // Preserve a migrated target; execution revalidates it against the current profile.
                    $newTargets[(string)$varId] = ['variableID' => $varId, 'value' => $value];
                }
            }
            $allValues[$sceneId] = $newTargets;
        }
        $this->WriteAttributeString('SceneValues', json_encode($allValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        IPS_SetProperty($this->InstanceID, 'Devices', '[]');
    }

    private function RefreshReferences(): void
    {
        foreach ($this->GetReferenceList() as $refId) {
            $this->UnregisterReference($refId);
        }
        foreach ($this->GetSceneValues() as $targets) {
            foreach ($targets as $target) {
                $varId = (int)($target['variableID'] ?? 0);
                if ($varId > 0 && IPS_ObjectExists($varId)) {
                    $this->RegisterReference($varId);
                }
            }
        }
    }

    private function EnsureProfiles(): void
    {
        if (!IPS_VariableProfileExists(self::PROFILE_EXECUTE)) {
            IPS_CreateVariableProfile(self::PROFILE_EXECUTE, 0);
            IPS_SetVariableProfileIcon(self::PROFILE_EXECUTE, 'Play');
            IPS_SetVariableProfileAssociation(self::PROFILE_EXECUTE, true, $this->Translate('Start'), 'Play', 0x22C55E);
            IPS_SetVariableProfileAssociation(self::PROFILE_EXECUTE, false, $this->Translate('Ready'), '', -1);
        }
        if (!IPS_VariableProfileExists(self::PROFILE_SAVE)) {
            IPS_CreateVariableProfile(self::PROFILE_SAVE, 0);
            IPS_SetVariableProfileIcon(self::PROFILE_SAVE, 'FloppyDisk');
            IPS_SetVariableProfileAssociation(self::PROFILE_SAVE, true, $this->Translate('Save'), 'FloppyDisk', 0x3B82F6);
            IPS_SetVariableProfileAssociation(self::PROFILE_SAVE, false, $this->Translate('Save'), 'FloppyDisk', 0x3B82F6);
        }
        if (!IPS_VariableProfileExists(self::PROFILE_MODE)) {
            IPS_CreateVariableProfile(self::PROFILE_MODE, 1);
            IPS_SetVariableProfileIcon(self::PROFILE_MODE, 'Gear');
            IPS_SetVariableProfileAssociation(self::PROFILE_MODE, 0, $this->Translate('Recall / Execute'), 'Play', 0x22C55E);
            IPS_SetVariableProfileAssociation(self::PROFILE_MODE, 1, $this->Translate('Save Current State'), 'FloppyDisk', 0xEF4444);
        }
    }

    private function PushVisualizationState(?string $Toast = null, array $SearchResults = []): void
    {
        if (!$this->ReadPropertyBoolean('UseHTMLTile')) {
            return;
        }
        $data = $this->BuildVisualizationState($Toast, $SearchResults);
        $this->UpdateVisualizationValue(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
