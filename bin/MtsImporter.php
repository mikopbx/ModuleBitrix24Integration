<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2026 Alexey Portnov and Nikolay Beketov
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

namespace Modules\ModuleBitrix24Integration\bin;
require_once 'Globals.php';

use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleBitrix24Integration\Lib\Bitrix24InvokeRest;
use Modules\ModuleBitrix24Integration\Lib\Logger;

// ВАЖНО: модель ModuleMtsPbx\Models\CallHistory НЕ импортируется через `use`.
// Если соседний модуль не установлен, любое упоминание этого класса (включая
// resolve через use) приведёт к ошибке автозагрузки. Доступ — только через
// FQCN-строку после class_exists().
const MTS_CALL_HISTORY_FQCN = '\\Modules\\ModuleMtsPbx\\Models\\CallHistory';

const MAX_PER_RUN = 300;
/**
 * Размер порции, посылаемой одним invoke в HTTP-воркер.
 * ВАЖНО: значение должно совпадать с
 * WorkerBitrix24IntegrationHTTP::MAX_HISTORICAL_CALLS_PER_INVOKE — воркер
 * срезает по своему потолку, и при рассинхроне часть звонков молча
 * отбрасывается. Если меняете — поднимите оба.
 */
const BATCH_SIZE  = 10;
const MIN_PAUSE   = 2;
const MAX_PAUSE   = 5;

$logger = new Logger('MtsImporter', 'ModuleBitrix24Integration');

if (!PbxExtensionUtils::isEnabled('ModuleBitrix24Integration')) {
    exit(0);
}

// Защита от повторного запуска (cron каждые 5 мин, скрипт может занять до ~150 сек).
$lockFile = ConnectorDb::getTempDir() . '/mts_importer.lock';
$lockHandle = @fopen($lockFile, 'c');
if (!$lockHandle || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    // Уже работает другой инстанс — тихо выходим.
    if ($lockHandle) {
        @fclose($lockHandle);
    }
    exit(0);
}
register_shutdown_function(static function () use (&$lockHandle, $lockFile) {
    if (is_resource($lockHandle)) {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
    @unlink($lockFile);
});

// Проверяем доступность модели соседнего модуля.
$callHistoryClass = MTS_CALL_HISTORY_FQCN;
if (!class_exists($callHistoryClass)) {
    // ModuleMtsPbx не установлен — это штатная ситуация. Тихо выходим.
    exit(0);
}

$settings = ConnectorDb::invoke(ConnectorDb::FUNC_GET_GENERAL_SETTINGS);
if (!is_array($settings) || empty($settings)) {
    $logger->writeError('Settings unavailable, exiting');
    exit(0);
}

if (((string)($settings['import_mts_calls'] ?? '0')) !== '1') {
    // Опция выключена.
    exit(0);
}

$cursor          = (int)($settings['mts_import_last_id'] ?? 0);
$crmCreateFlag   = (((string)($settings['crmCreateLead'] ?? '0')) === '1') ? '1' : '0';
$exportRecordsFlag = (((string)($settings['export_records'] ?? '0')) === '1') ? '1' : '0';

$logger->writeInfo(
    [
        'cursor'         => $cursor,
        'crm_create'     => $crmCreateFlag,
        'export_records' => $exportRecordsFlag,
    ],
    'MTS importer started'
);

$invoker     = new Bitrix24InvokeRest();
$processed   = 0;
$todayStart  = (new \DateTime())->setTime(0, 0)->format('Y-m-d H:i:s');

while ($processed < MAX_PER_RUN) {
    $rows = $callHistoryClass::find([
        'id > :id: OR start >= :start:',
        'bind'  => [
            'id'    => $cursor,
            'start' => $todayStart,
        ],
        'order' => 'id',
        'limit' => BATCH_SIZE,
    ])->toArray();

    if (empty($rows)) {
        break;
    }

    $batch = [];
    $cursorCandidate = $cursor; // курсор сдвинется только после успешного invoke
    $stopAtPending = false;
    foreach ($rows as $row) {
        $rowId = (int)($row['id'] ?? 0);
        $recStatus = (string)($row['mts_rec_status'] ?? '');
        // Pending — отправлять преждевременно. Откладываем (не сдвигаем курсор,
        // если запись из «новой» части, id > cursor; повторно попадёт в
        // следующем тике через ветку фильтра).
        if ($recStatus === 'pending' && $rowId > $cursor) {
            $logger->writeInfo(
                ['id' => $rowId, 'linkedid' => $row['linkedid'] ?? ''],
                'Skip pending without advancing cursor'
            );
            $stopAtPending = true;
            break;
        }

        // Чужие источники в mts_cdr — пропускаем без отправки в B24, но курсор двигаем.
        $fromAccount = (string)($row['from_account'] ?? '');
        if ($fromAccount !== 'fs-mts') {
            if ($rowId > $cursorCandidate) {
                $cursorCandidate = $rowId;
            }
            continue;
        }

        $payload = [
            'linkedid'              => (string)($row['linkedid'] ?? ''),
            'src_num'               => (string)($row['src_num'] ?? ''),
            'dst_num'               => (string)($row['dst_num'] ?? ''),
            'did'                   => (string)($row['did'] ?? ''),
            'start'                 => (string)($row['start'] ?? ''),
            'duration'              => (string)($row['duration'] ?? '0'),
            'disposition'           => (string)($row['disposition'] ?? 'NOANSWER'),
            'recordingfile'         => (string)($row['recordingfile'] ?? ''),
            'mts_rec_status'        => $recStatus,
            'from_account'          => $fromAccount,
            'crm_create'            => $crmCreateFlag,
            'export_records_setting'=> $exportRecordsFlag,
        ];
        $batch[] = $payload;
        if ($rowId > $cursorCandidate) {
            $cursorCandidate = $rowId;
        }
    }

    $invokeStatus = WorkerBitrix24IntegrationHTTP::IMPORT_ACK_OK;
    if (!empty($batch)) {
        try {
            $ack = $invoker->invoke('importHistoricalCalls', ['calls' => $batch], 5);
        } catch (\Throwable $e) {
            $logger->writeError(
                ['error' => $e->getMessage(), 'batch_size' => count($batch)],
                'Invoke failed, stopping run'
            );
            // Не двигаем курсор; следующий тик cron'а вернётся к этой партии.
            break;
        }
        $invokeStatus = (string)($ack['status'] ?? '');
        if ($invokeStatus === WorkerBitrix24IntegrationHTTP::IMPORT_ACK_NOT_READY) {
            // HTTP-воркер сообщил, что его карты пользователей B24 пусты
            // (например, после рестарта). НЕ двигаем курсор, ждём следующего тика.
            $logger->writeInfo(
                ['batch_size' => count($batch)],
                'HTTP worker not ready, leaving cursor untouched'
            );
            break;
        }
        if ($invokeStatus !== WorkerBitrix24IntegrationHTTP::IMPORT_ACK_OK) {
            $logger->writeError(
                ['ack' => $ack, 'batch_size' => count($batch)],
                'Unexpected ack from HTTP worker, stopping run'
            );
            break;
        }
        $processed += count($batch);
    }

    // Курсор продвигаем только если HTTP-воркер подтвердил приём
    // (или партия была пустой — все записи фильтровались как «чужие»).
    if ($cursorCandidate > $cursor) {
        $cursor = $cursorCandidate;
        ConnectorDb::invoke(
            ConnectorDb::FUNC_UPDATE_GENERAL_SETTINGS,
            [['mts_import_last_id' => $cursor]],
            false
        );
    }

    if ($stopAtPending) {
        // Перед нами «застрявшая» pending-запись; продолжать выборку
        // бессмысленно (тот же фильтр повторно её вернёт). Завершаем прогон.
        break;
    }
    if ($processed >= MAX_PER_RUN) {
        break;
    }
    if (count($rows) < BATCH_SIZE) {
        // Получили меньше лимита — данных больше нет.
        break;
    }
    if (empty($batch) && $cursorCandidate === $cursor) {
        // Защита от бесконечного цикла: вернулись BATCH_SIZE рядов из
        // ветки «start >= today» с id <= cursor (например, не fs-mts),
        // курсор не сдвинулся и сдвинуть не сможем. Прерываем прогон.
        $logger->writeInfo(
            ['cursor' => $cursor, 'rows_fetched' => count($rows)],
            'No progress possible (all rows filtered, cursor at ceiling), stopping run'
        );
        break;
    }
    if (empty($batch)) {
        // Все строки этой выборки были отфильтрованы как «чужие» — курсор
        // уже двинут на rowId последней записи; следующая итерация
        // продолжит с этого места. Пауза не нужна.
        continue;
    }
    sleep(random_int(MIN_PAUSE, MAX_PAUSE));
}

$logger->writeInfo(['processed' => $processed, 'cursor' => $cursor], 'MTS importer finished');
