<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 5 2020
 */

namespace Modules\ModuleBitrix24Integration\Lib;

use MikoPBX\Common\Models\Extensions;
use MikoPBX\Core\System\Configs\CronConf;
use MikoPBX\Core\System\PBX;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;
use MikoPBX\Modules\Config\ConfigClass;
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleBitrix24Integration\bin\ConnectorDb;
use Modules\ModuleBitrix24Integration\bin\UploaderB24;
use Modules\ModuleBitrix24Integration\bin\WorkerBitrix24IntegrationAMI;
use Modules\ModuleBitrix24Integration\bin\WorkerBitrix24IntegrationHTTP;
use Modules\ModuleBitrix24Integration\Lib\RestAPI\GetController;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24ExternalLines;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Integration;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Users;

class Bitrix24IntegrationConf extends ConfigClass
{

    /**
     * Returns array of additional routes for PBXCoreREST interface from module
     *
     * [ControllerClass, ActionMethod, RequestTemplate, HttpMethod, RootUrl, NoAuth ]
     *
     * @return array
     * @example
     *  [[GetController::class, 'callAction', '/pbxcore/api/backup/{actionName}', 'get', '/', false],
     */
    public function getPBXCoreRESTAdditionalRoutes(): array
    {
        return [
            [GetController::class, 'getWorkerState', '/pbxcore/api/bitrix-integration/workers/state',  'get', '/', true],
        ];
    }

    /**
     * Обработчик события изменения данных в базе настроек mikopbx.db.
     *
     * @param $data
     */
    public function modelsEventChangeData($data): void
    {
        if ($data['model'] === ModuleBitrix24Integration::class) {
            $changedFields = count($data['changedFields']);
            $syncKeys = ['lastLeadId', 'lastCompanyId', 'lastContactId', 'lastDealId', 'session'];
            if ($changedFields === 1 && in_array($data['changedFields'][0],$syncKeys, true)) {
                return;
            }
        }
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
                'label'          => 'HTTP'
            ],
            [
                'type'           => WorkerSafeScriptsCore::CHECK_BY_BEANSTALK,
                'worker'         => UploaderB24::class,
                'label'          => 'UPLOAD'
            ],
            [
                'type'           => WorkerSafeScriptsCore::CHECK_BY_AMI,
                'worker'         => WorkerBitrix24IntegrationAMI::class,
                'label'          => 'AMI'
            ],
            [
                'type'           => WorkerSafeScriptsCore::CHECK_BY_BEANSTALK,
                'worker'         => ConnectorDb::class,
                'label'          => 'DB'
            ],
        ];
    }

    /**
     * Return worker state.
     * @return array[]
     */
    private function workerState(): array
    {
        $workers = $this->getModuleWorkers();
        foreach ($workers as &$workerData) {
            $pid = Processes::getPidOfProcess($workerData['worker']);
            if (empty($pid)) {
                $workerData['state'] = 'FAIL';
            }else{
                $workerData['state'] = 'OK';
            }
            unset($workerData['type'], $workerData['worker']);
        }
        return $workers;
    }

    /**
     *  Process CoreAPI requests under root rights
     *
     * @param array $request
     *
     * @return PBXApiResult An object containing the result of the API call.
     */
    public function moduleRestAPICallback(array $request): PBXApiResult
    {
        $res = new PBXApiResult();
        $action = strtoupper($request['action']);
        if($action === "STATE"){
            $res->data = $this->workerState();
            $res->success = true;
        }else{
            $res->messages[] = "API action '$action' not found in moduleRestAPICallback ModuleBitrix24Integration";
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
        $cron = new CronConf();
        $cron->reStart();
        PBX::dialplanReload();
    }

    /**
     * Process module disable request
     *
     * @return bool
     */
    public function onBeforeModuleDisable(): bool
    {
        PBX::dialplanReload();
        return true;
    }

    /**
     * Prepares additional contexts sections in the extensions.conf file
     *
     * @return string
     */
    public function extensionGenContexts(): string
    {
        $innerMobile = [];
        $mobileNumberData = Extensions::find("type='".Extensions::TYPE_EXTERNAL."'")->toArray();
        foreach ($mobileNumberData as $mData){
            $innerMobile[] = Bitrix24Integration::getPhoneIndex($mData['number']);
        }
        $innerMobile = array_unique($innerMobile);
        $conf = '[b24-inner-mobile]'.PHP_EOL;
        foreach ($innerMobile as $extension){
            $conf.=   "exten => $extension,1,NoOp()".PHP_EOL;
        }

        $disabledDid = [];
        $lines = ConnectorDb::invoke(ConnectorDb::FUNC_GET_EXTERNAL_LINES, []);
        foreach ($lines as $line){
            $aliases = explode(' ', $line['alias']);
            foreach ($aliases as $alias){
                if(empty($alias)){
                    continue;
                }
                if($line['disabled'] === '1'){
                    $disabledDid[] = $alias;
                }
            }
        }
        $disabledDid = array_unique($disabledDid);
        $conf.= PHP_EOL.'[b24-disabled-did]'.PHP_EOL;
        $pattern = '/^[0-9*#A-Za-z]+$/';
        foreach ($disabledDid as $extension){
            if (!preg_match($pattern, $extension)) {
                break;
            }
            $conf.=   "exten => $extension,1,NoOp()".PHP_EOL;
        }
        return  $conf.PHP_EOL.
                '[b24-interception]'.PHP_EOL.
                'exten => _[0-9*#+a-zA-Z]!,1,ExecIf($["${B24_RESPONSIBLE_NUMBER}x" == "x" && "${B24_RESPONSIBLE_TIMEOUT}x" == "x"]?return)'."\n\t".
	            'same => n,Dial(Local/${B24_RESPONSIBLE_NUMBER}@internal/n,${B24_RESPONSIBLE_TIMEOUT},${TRANSFER_OPTIONS}Kg)'."\n\t".
	            'same => n,return'.PHP_EOL.
                'exten => _[hit],1,Hangup()'.PHP_EOL.PHP_EOL;
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
        $len = strlen(Bitrix24Integration::getPhoneIndex(str_repeat('1', 32)));
        $scriptFile = "$this->moduleDir/agi-bin/b24CheckResponsible.php";
        return "\t".'same => n,ExecIf($["${DIALPLAN_EXISTS(b24-disabled-did,${EXTEN},1)}" == "1"]?Set(B24_DISABLE_INTERCEPTION=1))'."\n".
               "\t".'same => n,ExecIf($["${DIALPLAN_EXISTS(b24-inner-mobile,${CALLERID(num):-'.$len.'},1)}" == "1"]?Set(B24_DISABLE_INTERCEPTION=1))'."\n".
               "\t".'same => n,ExecIf($["${B24_DISABLE_INTERCEPTION}" != "1"]?AGI('.$scriptFile.'))'."\n\t";
    }

    /**
     * @param array $tasks
     */
    public function createCronTasks(array &$tasks): void
    {
        $tmpDir = $this->di->getShared('config')->path('core.tempDir') . '/ModuleBitrix24Integration';
        $findPath   = Util::which('find');
        $tasks[]    = "*/5 * * * * $findPath $tmpDir -mmin +1 -type f -delete> /dev/null 2>&1".PHP_EOL;

        $phpPath   = Util::which('php');
        $tasks[]    = "*/1 * * * * $phpPath -f {$this->moduleDir}/bin/safe.php > /dev/null 2>&1".PHP_EOL;
    }
}