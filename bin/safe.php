<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2025 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */

use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleBitrix24Integration\Lib\Bitrix24IntegrationConf;
require_once 'Globals.php';

$moduleEnable = PbxExtensionUtils::isEnabled('ModuleBitrix24Integration');
if(!$moduleEnable){
    exit(1);
}
$conf = new Bitrix24IntegrationConf();
$workers = $conf->getModuleWorkers();
foreach ($workers as $workerData) {
    $WorkerPID = Processes::getPidOfProcess($workerData['worker']);
    if (empty($WorkerPID)) {
        Processes::processPHPWorker($workerData['worker']);
        SystemMessages::sysLogMsg('B24_SAFE', "Service {$workerData['worker']} started.", LOG_NOTICE);
    }
}
