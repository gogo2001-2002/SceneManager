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

        // Module Properties
        $this->RegisterPropertyString('Devices', '[]');
        $this->RegisterPropertyString('Scenes', '[]');
        $this->RegisterPropertyBoolean('UseHTMLTile', true);
        $this->RegisterPropertyBoolean('ResetExecutionVariable', false);
        $this->RegisterPropertyInteger('SwitchDelay', 50);

        // Persistent runtime attributes
        $this->RegisterAttributeString('SceneValues', '{}');
        $this->RegisterAttributeInteger('ActiveSceneID', 0);

        $this->EnsureProfiles();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();

        // Configure Visualization (HTML-SDK)
        if ($this->ReadPropertyBoolean('UseHTMLTile')) {
            $this->SetVisualizationType(1); // INSTANCE_VISUALIZATION_TYPE_HTML
        } else {
            $this->SetVisualizationType(0); // INSTANCE_VISUALIZATION_TYPE_NONE
        }

        // Manage Variable References
        $devices = $this->GetConfiguredDevices();
        foreach ($this->GetReferenceList() as $refId) {
            $this->UnregisterReference($refId);
        }
        foreach ($devices as $dev) {
            $varId = (int)($dev['variableID'] ?? 0);
            if ($varId > 0 && IPS_ObjectExists($varId)) {
                $this->RegisterReference($varId);
            }
        }

        // Mode Status Variable (0: Ausführen, 1: Speichern)
        $this->MaintainVariable('Mode', $this->Translate('Mode'), 1, self::PROFILE_MODE, 10, true);
        $this->EnableAction('Mode');

        // Active Scene Status Variable
        $this->MaintainVariable('ActiveScene', $this->Translate('Active Scene'), 3, '', 20, true);

        // Maintain Scene Variables
        $scenes = $this->GetConfiguredScenes();
        $existingVars = ['Mode', 'ActiveScene'];
        $pos = 100;

        foreach ($scenes as $scene) {
            $sceneId = (int)($scene['id'] ?? 0);
            if ($sceneId <= 0) {
                continue;
            }

            $sceneName = (string)($scene['name'] ?? ('Scene ' . $sceneId));
            $ident = 'Scene_' . $sceneId;
            $existingVars[] = $ident;

            // Required Boolean variable to start/recall scene
            $this->MaintainVariable($ident, $sceneName, 0, '~Switch', $pos++, true);
            $this->EnableAction($ident);

            if (!empty($scene['icon'])) {
                $varId = $this->GetIDForIdent($ident);
                if ($varId > 0) {
                    IPS_SetIcon($varId, (string)$scene['icon']);
                }
            }

            // Optional save variable
            $saveIdent = 'SaveScene_' . $sceneId;
            $createSave = !empty($scene['createSaveVariable']);
            $this->MaintainVariable($saveIdent, sprintf($this->Translate('Save %s'), $sceneName), 0, self::PROFILE_SAVE, $pos++, $createSave);
            if ($createSave) {
                $existingVars[] = $saveIdent;
                $this->EnableAction($saveIdent);
            }
        }

        // Clean up variables of removed scenes
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            $child = IPS_GetObject($childId);
            $childIdent = (string)($child['ObjectIdent'] ?? '');
            if (str_starts_with($childIdent, 'Scene_') || str_starts_with($childIdent, 'SaveScene_')) {
                if (!in_array($childIdent, $existingVars, true)) {
                    $this->UnregisterVariable($childIdent);
                }
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

        if (str_starts_with($Ident, 'Scene_')) {
            $sceneId = (int)substr($Ident, 6);
            $mode = (int)$this->GetValue('Mode');
            if ($mode === 1) {
                // Save mode: overwrite scene with current values
                $this->SaveScene($sceneId);
                $this->SetValue($Ident, false);
            } else {
                // Execute mode: recall scene
                $this->ExecuteScene($sceneId);
            }
            return;
        }

        if (str_starts_with($Ident, 'SaveScene_')) {
            $sceneId = (int)substr($Ident, 10);
            $this->SaveScene($sceneId);
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

        if ($Ident === 'CreateScene') {
            $payload = is_string($Value) ? json_decode($Value, true) : $Value;
            if (is_array($payload) && !empty($payload['name'])) {
                $this->CreateScene(
                    (string)$payload['name'],
                    (string)($payload['icon'] ?? 'Lightbulb'),
                    (array)($payload['targets'] ?? []),
                    !empty($payload['createSaveVariable'])
                );
            }
            return;
        }

        if ($Ident === 'UpdateScene') {
            $payload = is_string($Value) ? json_decode($Value, true) : $Value;
            if (is_array($payload) && isset($payload['sceneId'])) {
                $this->UpdateScene(
                    (int)$payload['sceneId'],
                    (string)($payload['name'] ?? ''),
                    (string)($payload['icon'] ?? ''),
                    (array)($payload['targets'] ?? []),
                    isset($payload['createSaveVariable']) ? (bool)$payload['createSaveVariable'] : null
                );
            }
            return;
        }

        if ($Ident === 'DeleteScene') {
            $this->DeleteScene((int)$Value);
            return;
        }

        if ($Ident === 'SaveSceneDetails') {
            $payload = is_string($Value) ? json_decode($Value, true) : $Value;
            if (is_array($payload) && isset($payload['sceneId'], $payload['targets'])) {
                $this->UpdateSceneTargets((int)$payload['sceneId'], (array)$payload['targets']);
            }
            return;
        }

        if ($Ident === 'GetTileState') {
            $this->PushVisualizationState();
            return;
        }

        throw new Exception("Unknown action: {$Ident}");
    }

    /**
     * Executes the specified scene.
     */
    public function ExecuteScene(int $SceneID): void
    {
        $this->SendDebug('ExecuteScene', "Triggered Scene ID: {$SceneID}", 0);

        $scenes = $this->GetConfiguredScenes();
        $targetScene = null;
        foreach ($scenes as $s) {
            if ((int)$s['id'] === $SceneID) {
                $targetScene = $s;
                break;
            }
        }

        if ($targetScene === null) {
            $this->SendDebug('ExecuteScene', "Scene ID {$SceneID} does not exist", 0);
            return;
        }

        $allSceneValues = $this->GetSceneValues();
        $sceneTargets = $allSceneValues[(string)$SceneID] ?? ($targetScene['targets'] ?? []);

        $devices = $this->GetConfiguredDevices();
        $deviceMap = [];
        foreach ($devices as $d) {
            $deviceMap[(string)$d['id']] = $d;
        }

        $delayMs = max(0, $this->ReadPropertyInteger('SwitchDelay'));

        foreach ($sceneTargets as $devId => $target) {
            if (!isset($deviceMap[(string)$devId])) {
                continue;
            }
            if (empty($target['enabled'])) {
                continue;
            }

            $dev = $deviceMap[(string)$devId];
            $varId = (int)($dev['variableID'] ?? 0);
            if ($varId <= 0 || !IPS_ObjectExists($varId)) {
                continue;
            }

            $type = (string)($dev['type'] ?? 'switch');
            $val = $target['value'] ?? null;
            $mode = (string)($target['mode'] ?? 'position');

            try {
                $this->ApplyDeviceValue($varId, $type, $val, $mode);
            } catch (Throwable $e) {
                $this->LogMessage("Error setting device '{$dev['name']}' ({$varId}): " . $e->getMessage(), KL_WARNING);
            }

            if ($delayMs > 0) {
                IPS_Sleep($delayMs);
            }
        }

        // Update active scene indicators
        $sceneName = (string)($targetScene['name'] ?? ('Scene ' . $SceneID));
        $this->WriteAttributeInteger('ActiveSceneID', $SceneID);
        $this->SetValue('ActiveScene', $sceneName);

        $resetVar = $this->ReadPropertyBoolean('ResetExecutionVariable');
        foreach ($scenes as $s) {
            $sId = (int)$s['id'];
            $varIdent = 'Scene_' . $sId;
            if (@$this->GetIDForIdent($varIdent)) {
                if ($resetVar) {
                    $this->SetValue($varIdent, false);
                } else {
                    $this->SetValue($varIdent, $sId === $SceneID);
                }
            }
        }

        $this->PushVisualizationState(sprintf($this->Translate('Scene "%s" executed'), $sceneName));
    }

    /**
     * Saves the current live states of all configured devices into the specified scene.
     */
    public function SaveScene(int $SceneID): void
    {
        $this->SendDebug('SaveScene', "Capturing live states for Scene ID: {$SceneID}", 0);

        $scenes = $this->GetConfiguredScenes();
        $targetScene = null;
        foreach ($scenes as $s) {
            if ((int)$s['id'] === $SceneID) {
                $targetScene = $s;
                break;
            }
        }

        if ($targetScene === null) {
            $this->SendDebug('SaveScene', "Scene ID {$SceneID} does not exist", 0);
            return;
        }

        $devices = $this->GetConfiguredDevices();
        $allSceneValues = $this->GetSceneValues();
        $currentTargets = $allSceneValues[(string)$SceneID] ?? ($targetScene['targets'] ?? []);

        foreach ($devices as $dev) {
            $devId = (string)$dev['id'];
            $varId = (int)($dev['variableID'] ?? 0);
            if ($varId <= 0 || !IPS_ObjectExists($varId)) {
                continue;
            }

            $type = (string)($dev['type'] ?? 'switch');
            $currentVal = GetValue($varId);

            $targetData = $currentTargets[$devId] ?? ['enabled' => true];
            $enabled = isset($targetData['enabled']) ? (bool)$targetData['enabled'] : true;

            switch ($type) {
                case 'light':
                case 'switch':
                case 'climate':
                    $boolVal = is_bool($currentVal) ? $currentVal : ($currentVal > 0);
                    $currentTargets[$devId] = [
                        'enabled' => $enabled,
                        'value'   => $boolVal
                    ];
                    break;

                case 'shutter':
                    $pos = 0;
                    if (is_bool($currentVal)) {
                        $pos = $currentVal ? 100 : 0;
                    } elseif (is_float($currentVal)) {
                        $pos = ($currentVal <= 1.0 && $currentVal >= 0.0) ? (int)round($currentVal * 100) : (int)round($currentVal);
                    } else {
                        $pos = (int)$currentVal;
                    }
                    $pos = max(0, min(100, $pos));
                    $currentTargets[$devId] = [
                        'enabled' => $enabled,
                        'mode'    => 'position',
                        'value'   => $pos
                    ];
                    break;
            }
        }

        $allSceneValues[(string)$SceneID] = $currentTargets;
        $this->WriteAttributeString('SceneValues', json_encode($allSceneValues));

        $sceneName = (string)($targetScene['name'] ?? ('Scene ' . $SceneID));
        $this->PushVisualizationState(sprintf($this->Translate('Current state saved to scene "%s"'), $sceneName));
    }

    /**
     * Creates a new scene dynamically from the visualization or script.
     */
    public function CreateScene(string $Name, string $Icon = 'Lightbulb', array $Targets = [], bool $CreateSaveVariable = false): int
    {
        $scenes = $this->GetConfiguredScenes();
        $maxId = 0;
        foreach ($scenes as $s) {
            $sId = (int)($s['id'] ?? 0);
            if ($sId > $maxId) {
                $maxId = $sId;
            }
        }
        $newId = $maxId + 1;

        $newScene = [
            'id'                 => $newId,
            'name'               => $Name !== '' ? $Name : ('Szene ' . $newId),
            'icon'               => $Icon ?: 'Lightbulb',
            'createSaveVariable' => $CreateSaveVariable
        ];

        $scenes[] = $newScene;

        // Save targets into SceneValues attribute
        $allValues = $this->GetSceneValues();
        $allValues[(string)$newId] = $Targets;
        $this->WriteAttributeString('SceneValues', json_encode($allValues));

        // Persist scenes property and apply changes so the Boolean variable is created
        IPS_SetProperty($this->InstanceID, 'Scenes', json_encode($scenes));
        IPS_ApplyChanges($this->InstanceID);

        $this->PushVisualizationState(sprintf($this->Translate('Scene "%s" created'), $newScene['name']));
        return $newId;
    }

    /**
     * Deletes a scene and cleans up its variables.
     */
    public function DeleteScene(int $SceneID): void
    {
        $scenes = $this->GetConfiguredScenes();
        $newScenes = [];
        $deletedName = '';

        foreach ($scenes as $s) {
            if ((int)$s['id'] === $SceneID) {
                $deletedName = (string)$s['name'];
                continue;
            }
            $newScenes[] = $s;
        }

        $allValues = $this->GetSceneValues();
        unset($allValues[(string)$SceneID]);
        $this->WriteAttributeString('SceneValues', json_encode($allValues));

        if ($this->ReadAttributeInteger('ActiveSceneID') === $SceneID) {
            $this->WriteAttributeInteger('ActiveSceneID', 0);
            $this->SetValue('ActiveScene', '');
        }

        IPS_SetProperty($this->InstanceID, 'Scenes', json_encode($newScenes));
        IPS_ApplyChanges($this->InstanceID);

        $this->PushVisualizationState(sprintf($this->Translate('Scene "%s" deleted'), $deletedName ?: (string)$SceneID));
    }

    /**
     * Updates an existing scene's metadata and device targets.
     */
    public function UpdateScene(int $SceneID, string $Name, string $Icon, array $Targets, ?bool $CreateSaveVariable = null): void
    {
        $scenes = $this->GetConfiguredScenes();
        $found = false;
        $finalName = $Name;

        foreach ($scenes as &$s) {
            if ((int)$s['id'] === $SceneID) {
                if ($Name !== '') {
                    $s['name'] = $Name;
                    $finalName = $Name;
                }
                if ($Icon !== '') {
                    $s['icon'] = $Icon;
                }
                if ($CreateSaveVariable !== null) {
                    $s['createSaveVariable'] = $CreateSaveVariable;
                }
                $found = true;
                break;
            }
        }

        if ($found) {
            if (!empty($Targets)) {
                $allValues = $this->GetSceneValues();
                $allValues[(string)$SceneID] = $Targets;
                $this->WriteAttributeString('SceneValues', json_encode($allValues));
            }

            IPS_SetProperty($this->InstanceID, 'Scenes', json_encode($scenes));
            IPS_ApplyChanges($this->InstanceID);

            $this->PushVisualizationState(sprintf($this->Translate('Scene "%s" updated'), $finalName ?: (string)$SceneID));
        }
    }

    /**
     * Updates target values for a scene from the visualization editor.
     */
    public function UpdateSceneTargets(int $SceneID, array $Targets): void
    {
        $allSceneValues = $this->GetSceneValues();
        $allSceneValues[(string)$SceneID] = $Targets;
        $this->WriteAttributeString('SceneValues', json_encode($allSceneValues));

        $scenes = $this->GetConfiguredScenes();
        $sceneName = "Scene {$SceneID}";
        foreach ($scenes as $s) {
            if ((int)$s['id'] === $SceneID) {
                $sceneName = (string)$s['name'];
                break;
            }
        }

        $this->PushVisualizationState(sprintf($this->Translate('Scene "%s" updated'), $sceneName));
    }

    /**
     * Programmatically sets a device value in a scene.
     */
    public function SetSceneDeviceValue(int $SceneID, int $DeviceID, $Value, bool $Enabled = true): void
    {
        $allSceneValues = $this->GetSceneValues();
        $sceneTargets = $allSceneValues[(string)$SceneID] ?? [];

        $dev = null;
        foreach ($this->GetConfiguredDevices() as $d) {
            if ((int)$d['id'] === $DeviceID) {
                $dev = $d;
                break;
            }
        }

        $type = (string)($dev['type'] ?? 'switch');
        if ($type === 'shutter') {
            $sceneTargets[(string)$DeviceID] = [
                'enabled' => $Enabled,
                'mode'    => 'position',
                'value'   => (int)$Value
            ];
        } else {
            $sceneTargets[(string)$DeviceID] = [
                'enabled' => $Enabled,
                'value'   => (bool)$Value
            ];
        }

        $allSceneValues[(string)$SceneID] = $sceneTargets;
        $this->WriteAttributeString('SceneValues', json_encode($allSceneValues));
        $this->PushVisualizationState();
    }

    /**
     * Returns list of configured scenes.
     */
    public function GetScenes(): array
    {
        return $this->GetConfiguredScenes();
    }

    /**
     * Returns list of configured devices.
     */
    public function GetDevices(): array
    {
        return $this->GetConfiguredDevices();
    }

    /**
     * Returns HTML content for the HTML-SDK visualization tile.
     */
    public function GetVisualizationTile(): string
    {
        $htmlFile = __DIR__ . '/module.html';
        if (file_exists($htmlFile)) {
            return file_get_contents($htmlFile);
        }
        return '<div style="padding:10px;color:red;">module.html not found</div>';
    }

    // --- Private Helper Methods ---

    private function ApplyDeviceValue(int $VarID, string $Type, $Value, string $Mode): void
    {
        $var = IPS_GetVariable($VarID);
        $vType = $var['VariableType'];

        switch ($Type) {
            case 'light':
                if ($vType === 0) { // Boolean
                    RequestAction($VarID, (bool)$Value);
                } elseif ($vType === 1 || $vType === 2) { // Integer / Float dimmer
                    RequestAction($VarID, $Value ? 100 : 0);
                }
                break;

            case 'switch':
            case 'climate':
                if ($vType === 0) { // Boolean
                    RequestAction($VarID, (bool)$Value);
                } elseif ($vType === 1 || $vType === 2) {
                    RequestAction($VarID, $Value ? 1 : 0);
                }
                break;

            case 'shutter':
                $targetPos = 0;
                if ($Mode === 'up') {
                    $targetPos = 0;
                } elseif ($Mode === 'down') {
                    $targetPos = 100;
                } else {
                    $targetPos = max(0, min(100, (int)$Value));
                }

                if ($vType === 0) { // Boolean Shutter
                    RequestAction($VarID, $targetPos >= 50);
                } elseif ($vType === 1) { // Integer Shutter
                    RequestAction($VarID, (int)$targetPos);
                } elseif ($vType === 2) { // Float Shutter
                    $profName = $var['VariableCustomProfile'] ?: $var['VariableProfile'];
                    $isNormalized = false;
                    if ($profName && IPS_VariableProfileExists($profName)) {
                        $prof = IPS_GetVariableProfile($profName);
                        if ($prof['MaxValue'] <= 1.0 && $prof['MaxValue'] > 0) {
                            $isNormalized = true;
                        }
                    }
                    RequestAction($VarID, $isNormalized ? ($targetPos / 100.0) : (float)$targetPos);
                }
                break;
        }
    }

    private function GetConfiguredDevices(): array
    {
        $json = (string)$this->ReadPropertyString('Devices');
        $devices = json_decode($json, true);
        return is_array($devices) ? $devices : [];
    }

    private function GetConfiguredScenes(): array
    {
        $json = (string)$this->ReadPropertyString('Scenes');
        $scenes = json_decode($json, true);
        return is_array($scenes) ? $scenes : [];
    }

    private function GetSceneValues(): array
    {
        $json = (string)$this->ReadAttributeString('SceneValues');
        $vals = json_decode($json, true);
        return is_array($vals) ? $vals : [];
    }

    private function GetLiveDeviceStates(): array
    {
        $devices = $this->GetConfiguredDevices();
        foreach ($devices as &$dev) {
            $varId = (int)($dev['variableID'] ?? 0);
            $dev['currentValue'] = null;
            if ($varId > 0 && IPS_ObjectExists($varId)) {
                $val = GetValue($varId);
                $type = (string)($dev['type'] ?? 'switch');
                if ($type === 'shutter') {
                    if (is_bool($val)) {
                        $dev['currentValue'] = $val ? 100 : 0;
                    } elseif (is_float($val)) {
                        $dev['currentValue'] = ($val <= 1.0 && $val >= 0.0) ? (int)round($val * 100) : (int)round($val);
                    } else {
                        $dev['currentValue'] = (int)$val;
                    }
                } else {
                    $dev['currentValue'] = is_bool($val) ? $val : ($val > 0);
                }
            }
        }
        return $devices;
    }

    private function PushVisualizationState(?string $Toast = null): void
    {
        if (!$this->ReadPropertyBoolean('UseHTMLTile')) {
            return;
        }

        $data = [
            'type'          => 'state',
            'instanceId'    => $this->InstanceID,
            'mode'          => @$this->GetValue('Mode') ?: 0,
            'activeSceneId' => $this->ReadAttributeInteger('ActiveSceneID'),
            'scenes'        => $this->GetConfiguredScenes(),
            'devices'       => $this->GetLiveDeviceStates(),
            'sceneValues'   => $this->GetSceneValues(),
            'toast'         => $Toast
        ];

        $this->UpdateVisualizationValue(json_encode($data));
    }

    private function EnsureProfiles(): void
    {
        // SM.Execute profile
        if (!IPS_VariableProfileExists(self::PROFILE_EXECUTE)) {
            IPS_CreateVariableProfile(self::PROFILE_EXECUTE, 0); // Boolean
            IPS_SetVariableProfileIcon(self::PROFILE_EXECUTE, 'Play');
            IPS_SetVariableProfileAssociation(self::PROFILE_EXECUTE, true, $this->Translate('Start'), 'Play', 0x22C55E);
            IPS_SetVariableProfileAssociation(self::PROFILE_EXECUTE, false, $this->Translate('Ready'), '', -1);
        }

        // SM.Save profile
        if (!IPS_VariableProfileExists(self::PROFILE_SAVE)) {
            IPS_CreateVariableProfile(self::PROFILE_SAVE, 0); // Boolean
            IPS_SetVariableProfileIcon(self::PROFILE_SAVE, 'FloppyDisk');
            IPS_SetVariableProfileAssociation(self::PROFILE_SAVE, true, $this->Translate('Save'), 'FloppyDisk', 0x3B82F6);
            IPS_SetVariableProfileAssociation(self::PROFILE_SAVE, false, $this->Translate('Save'), 'FloppyDisk', 0x3B82F6);
        }

        // SM.Mode profile
        if (!IPS_VariableProfileExists(self::PROFILE_MODE)) {
            IPS_CreateVariableProfile(self::PROFILE_MODE, 1); // Integer
            IPS_SetVariableProfileIcon(self::PROFILE_MODE, 'Gear');
            IPS_SetVariableProfileAssociation(self::PROFILE_MODE, 0, $this->Translate('Recall / Execute'), 'Play', 0x22C55E);
            IPS_SetVariableProfileAssociation(self::PROFILE_MODE, 1, $this->Translate('Save Current State'), 'FloppyDisk', 0xEF4444);
        }
    }
}
