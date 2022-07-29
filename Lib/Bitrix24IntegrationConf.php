<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 5 2020
 */

namespace Modules\ModuleBitrix24Integration\Lib;

use MikoPBX\Core\System\PBX;
use MikoPBX\Core\System\Util;
use MikoPBX\Modules\Config\ConfigClass;
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleBitrix24Integration\bin\UploaderB24;
use Modules\ModuleBitrix24Integration\bin\WorkerBitrix24IntegrationAMI;
use Modules\ModuleBitrix24Integration\bin\WorkerBitrix24IntegrationHTTP;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24ExternalLines;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Integration;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Users;

class Bitrix24IntegrationConf extends ConfigClass
{
    /**
     * Обработчик события изменения данных в базе настроек mikopbx.db.
     *
     * @param $data
     */
    public function modelsEventChangeData($data): void
    {
        $moduleModels = [
            ModuleBitrix24ExternalLines::class,
            ModuleBitrix24Integration::class,
            ModuleBitrix24Users::class,
        ];
        if (in_array($data['model'], $moduleModels, true)){
            $this->onAfterModuleEnable();
        }
    }

    /**
     * Returns module workers to start it at WorkerSafeScript
     * @return array
     */
    public function getModuleWorkers(): array
    {
        return [
            [
                'type'           => WorkerSafeScriptsCore::CHECK_BY_BEANSTALK,
                'worker'         => WorkerBitrix24IntegrationHTTP::class,
            ],
            [
                'type'           => WorkerSafeScriptsCore::CHECK_BY_BEANSTALK,
                'worker'         => UploaderB24::class,
            ],
            [
                'type'           => WorkerSafeScriptsCore::CHECK_BY_AMI,
                'worker'         => WorkerBitrix24IntegrationAMI::class,
            ],
        ];
    }

    /**
     * Будет вызван после старта asterisk.
     */
    public function onAfterPbxStarted(): void
    {
        $module = new Bitrix24Integration();
        if ($module->initialized) {
            $module->startAllServices(true);
        }
    }

    /**
     *  Process CoreAPI requests under root rights
     *
     * @param array $request
     *
     * @return PBXApiResult
     */
    public function moduleRestAPICallback(array $request): PBXApiResult
    {
        $res = new PBXApiResult();
        $action = strtoupper($request['action']);
        if($action === 'CHECK') {
            $module = new Bitrix24Integration();
            if ($module->initialized) {
                $res = $module->getScope();
            } else {
                $res->messages[] = Util::translate('mod_b24_i_NoSettings');
            }
        }elseif ($action === 'ACTIVATE-CODE'){
            $b24    = new Bitrix24Integration();
            $res->success = $b24->authByCode($request['data']['code'], $request['data']['region']);
        }else{
            $res->messages[] = 'API action not found in moduleRestAPICallback ModuleBitrix24Integration;';
        }
        return $res;
    }

    /**
     * Process after enable action in web interface
     *
     * @return void
     */
    public function onAfterModuleEnable(): void
    {
        $module = new Bitrix24Integration();
        if ($module->initialized) {
            $module->startAllServices(true);
        }
        PBX::dialplanReload();
    }

    /**
     * Prepares additional contexts sections in the extensions.conf file
     *
     * @return string
     */
    public function extensionGenContexts(): string
    {
        return  '[b24-interception]'.PHP_EOL.
                'exten => _[0-9*#+]!,1,ExecIf($["${B24_RESPONSIBLE_NUMBER}x" == "x" && "${B24_RESPONSIBLE_TIMEOUT}x" == "x"]?return)'."\n\t".
                'same => n,Set(M_TIMEOUT=${B24_RESPONSIBLE_TIMEOUT)'."\n\t".
	            'same => n,Dial(Local/${B24_RESPONSIBLE_NUMBER}@internal-incoming/n,${B24_RESPONSIBLE_TIMEOUT},${TRANSFER_OPTIONS}Kg)'."\n\t".
	            'same => n,return'.PHP_EOL;
    }

    /**
     * Prepares additional parameters for each incoming context for each incoming route before dial in the
     * extensions.conf file
     *
     * @param string $rout_number
     *
     * @return string
     */
    public function generateIncomingRoutBeforeDial(string $rout_number): string
    {
        $scriptFile = "$this->moduleDir/agi-bin/b24CheckResponsible.php";
        return "\t".'same => n,AGI('.$scriptFile.')' . "\n\t";
    }
}