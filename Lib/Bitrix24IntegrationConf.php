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
        if (in_array($data['model'], $moduleModels)){
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
     * Генерация дополнительных контекстов.
     *
     * @return string
     */
    public function extensionGenContexts():string
    {
        return   '[outgoing-b24]'."\n".
            'exten => _.!,1,ExecIf($["${b24_dst}x" != "x"]?Set(CALLERID(num)=${b24_dst}))'."\n".
            '	same => n,Goto(outgoing,${EXTEN},1)'."\n";
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
        switch ($action){
            case 'CHECK':
                $module = new Bitrix24Integration();
                if ($module->initialized){
                    $res =  $module->getScope();
                } else {
                    $res->messages[]=Util::translate('mod_b24_i_NoSettings');
                }
                break;
            default:
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
            PBX::dialplanReload();
        }
    }
}