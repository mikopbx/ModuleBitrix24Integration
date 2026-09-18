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

use MikoPBX\Common\Providers\CDRDatabaseProvider;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleBitrix24Integration\Lib\Bitrix24InvokeRest;
use Modules\ModuleBitrix24Integration\Lib\Logger;

/**
 * Единый импорт истории звонков операторских модулей (МТС / Beeline / Megafon)
 * в Bitrix24 из ОБЩЕЙ CDR ядра `cdr_general` через штатный
 * CDRDatabaseProvider::getCdr() (запрос исполняет WorkerCdr, соединение dbCDR
 * мы напрямую не трогаем).
 *
 * Почему один источник: анализ боевой базы показал, что все три оператора
 * дублируют историю в cdr_general с разными префиксами linkedid
 * (fs-mts-/fs-beeline-/fs-megapbx-), а их CDR там строго ОДНОСТРОЧНЫЕ
 * (1 плечо на linkedid) — схлопывание плеч не нужно, тип единый.
 * У Megafon своей таблицы истории нет вовсе — только cdr_general.
 *
 * Ограничение (принято): cdr_general чистится ModuleCleanRecords (~4 мес),
 * поэтому старая история MTS глубже ретеншна (есть только в mts_cdr) не тянется.
 *
 * Дедуп/защита от дублей — на стороне HTTP-воркера (importHistoricalCalls →
 * enqueueHistoricalCall): строгий дедуп по linkedid (FUNC_GET_EXPORTED_CALL_ID),
 * register-кэш 180с, запись b24_cdr_data только после успеха. Плюс settled-delay
 * (см. DELAY_SECONDS) — не берём свежие звонки, пока живой поток/докачка записи
 * не улеглись.
 *
 * ТРОТТЛИНГ (важно):
 *  - getCdr дёргаем НЕ чаще раза в минуту (GETCDR_MIN_INTERVAL) — WorkerCdr/CDR-БД
 *    нельзя нагружать чаще.
 *  - Импорт делит с живым трафиком общий лимит Bitrix24 (~7 запросов/сек на всю
 *    интеграцию), поэтому кормим q_req воркера мелкими чанками с паузой
 *    (INVOKE_PAUSE) и ограничиваем объём за прогон (MAX_GETCDR_PER_RUN × PAGE_SIZE).
 *    Полная выгрузка идёт медленно, за много cron-тиков — это осознанно.
 */

/** Провайдеры: префикс linkedid, флаг-галка, поле курсора (cdr_general.id). */
const PROVIDERS = [
    ['key' => 'mts',     'prefix' => 'fs-mts-',     'flag' => 'import_mts_calls',     'cursor' => 'mts_hist_cursor'],
    ['key' => 'beeline', 'prefix' => 'fs-beeline-', 'flag' => 'import_beeline_calls', 'cursor' => 'beeline_hist_cursor'],
    ['key' => 'megafon', 'prefix' => 'fs-megapbx-', 'flag' => 'import_megafon_calls', 'cursor' => 'megafon_hist_cursor'],
];

/**
 * Размер порции на один invoke в HTTP-воркер.
 * ВАЖНО: должно совпадать с WorkerBitrix24IntegrationHTTP::MAX_HISTORICAL_CALLS_PER_INVOKE —
 * воркер срезает по своему потолку, при рассинхроне часть звонков молча отбросится.
 */
const BATCH_SIZE  = 10;
/**
 * Сырых строк cdr_general за одну выборку getCdr().
 * Держим небольшой (<=1000), чтобы не раздувать result-файл WorkerCdr и не
 * слать в B24 крупными бурстами.
 */
const PAGE_SIZE   = 200;
/**
 * «Опоздание» импорта: берём только звонки, завершившиеся раньше, чем now-DELAY.
 * Даёт живому потоку/докачке MP3 (downloadRecords каждые 5 мин) время улечься.
 */
const DELAY_SECONDS = 900; // 15 минут
/** getCdr нельзя дёргать чаще раза в минуту. Минимальный интервал между вызовами. */
const GETCDR_MIN_INTERVAL = 60;
/**
 * Максимум вызовов getCdr за один прогон cron. Ограничивает длительность прогона
 * (≈ MAX_GETCDR_PER_RUN × GETCDR_MIN_INTERVAL) и объём выгрузки за тик. Остальное
 * добирается на следующих тиках cron.
 */
const MAX_GETCDR_PER_RUN = 3;
/**
 * Пауза между invoke-чанками (сек). Кормим q_req воркера постепенно, чтобы импорт
 * не выедал лимит Bitrix24 (~7 req/s на всю интеграцию) и не мешал живым звонкам.
 */
const INVOKE_PAUSE = 1;
/**
 * Сколько ждём докачку MP3, прежде чем импортировать answered-звонок БЕЗ записи.
 * Если у звонка есть путь к записи, но файла ещё нет на диске и звонок моложе
 * этого порога — откладываем (запись вот-вот докачается downloadRecords'ом).
 * Старше порога — запись уже не придёт (вычищена/недоступна), шлём без неё.
 */
const RECORD_WAIT_SECONDS = 7200; // 2 часа

$logger = new Logger('HistoryImporter', 'ModuleBitrix24Integration');

if (!PbxExtensionUtils::isEnabled('ModuleBitrix24Integration')) {
    exit(0);
}

// Защита от повторного запуска (cron каждые 5 мин, прогон может занять несколько минут).
$lockFile = ConnectorDb::getTempDir() . '/history_importer.lock';
$lockHandle = @fopen($lockFile, 'c');
if (!$lockHandle || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
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

// ConnectorDb::FUNC_GET_GENERAL_SETTINGS → stdClass (см. контракт invoke()).
$settings = ConnectorDb::invoke(ConnectorDb::FUNC_GET_GENERAL_SETTINGS);
if (!is_object($settings)) {
    $logger->writeError(['type' => gettype($settings)], 'Settings RPC returned non-object (likely timeout), exiting');
    exit(0);
}
if (empty($settings->portal)) {
    $logger->writeInfo('B24 portal is not configured yet, exiting');
    exit(0);
}

$crmCreateFlag     = (((string)($settings->crmCreateLead ?? '0')) === '1') ? '1' : '0';
$exportRecordsFlag = (((string)($settings->export_records ?? '0')) === '1') ? '1' : '0';

// Граница «улёгшихся» звонков: строковое сравнение start (Y-m-d H:i:s[.u]).
$settledBoundary = date('Y-m-d H:i:s', time() - DELAY_SECONDS);

$invoker    = new Bitrix24InvokeRest();
$processed  = 0;
$getCdrRuns = 0;     // сколько getCdr сделали за этот прогон (бюджет MAX_GETCDR_PER_RUN)
$lastGetCdr = 0;     // время последнего getCdr — для интервала GETCDR_MIN_INTERVAL

foreach (PROVIDERS as $provider) {
    // Галка провайдера.
    if (((string)($settings->{$provider['flag']} ?? '0')) !== '1') {
        continue;
    }
    if ($getCdrRuns >= MAX_GETCDR_PER_RUN) {
        break; // бюджет getCdr на прогон исчерпан
    }

    $cursorField = $provider['cursor'];
    $cursor      = (int)($settings->{$cursorField} ?? 0);
    $prefixLike  = $provider['prefix'] . '%';

    $logger->writeInfo(
        ['provider' => $provider['key'], 'cursor' => $cursor, 'boundary' => $settledBoundary],
        'History importer: provider started'
    );

    while ($getCdrRuns < MAX_GETCDR_PER_RUN) {
        // Троттлинг getCdr: не чаще раза в минуту. Первый вызов за прогон идёт
        // сразу (предыдущий тик cron был >5 мин назад).
        if ($lastGetCdr > 0) {
            $wait = GETCDR_MIN_INTERVAL - (time() - $lastGetCdr);
            if ($wait > 0) {
                sleep($wait);
            }
        }

        // Курсор по id (PK) — не пропускаем ни одной строки; свежесть проверяем
        // по start в PHP (стоп без сдвига), а не фильтром в SQL — иначе строка,
        // «свежая» сейчас и «улёгшаяся» позже, была бы пропущена навсегда.
        //
        // Числовой bind (:id:) через getCdr/WorkerCdr не срабатывает (возвращает
        // пусто) — поэтому курсор подставляем литералом. Значение уже (int),
        // инъекция невозможна. Строковый bind (:pfx:) работает штатно.
        $filter = [
            'linkedid LIKE :pfx: AND id > ' . (int)$cursor,
            'bind'    => ['pfx' => $prefixLike],
            'columns' => 'id,start,src_num,dst_num,did,billsec,duration,disposition,recordingfile,from_account,linkedid',
            'order'   => 'id',
            'limit'   => PAGE_SIZE,
        ];
        try {
            $rows = CDRDatabaseProvider::getCdr($filter);
        } catch (\Throwable $e) {
            $logger->writeError(
                ['provider' => $provider['key'], 'error' => $e->getMessage()],
                'getCdr threw, stopping provider'
            );
            $rows = [];
        }
        $lastGetCdr = time();
        $getCdrRuns++;

        if (empty($rows)) {
            // Пусто = строк больше нет ЛИБО транзиентный таймаут WorkerCdr (getCdr
            // на ошибке отдаёт []). В обоих случаях просто завершаем провайдера —
            // курсор не двигали, следующий тик cron продолжит с того же места.
            break;
        }

        $batch           = [];
        $cursorCandidate = $cursor;
        $stopHere        = false;

        foreach ($rows as $row) {
            $rid   = (int)($row['id'] ?? 0);
            $start = (string)($row['start'] ?? '');

            // Пустая дата — звонок без даты слать нельзя (CALL_START_DATE был бы
            // пуст). Пропускаем со сдвигом курсора, чтобы не застопорить провайдера.
            if ($start === '') {
                if ($rid > $cursorCandidate) {
                    $cursorCandidate = $rid;
                }
                continue;
            }
            // Свежий/незавершённый звонок — стоп, курсор за него НЕ двигаем.
            if ($start >= $settledBoundary) {
                $stopHere = true;
                break;
            }

            // Битрикс принимает звонок только с внешним номером (>6 цифр).
            if (!isExternalNumber((string)($row['src_num'] ?? '')) &&
                !isExternalNumber((string)($row['dst_num'] ?? ''))) {
                // Внутренний — пропускаем, но курсор двигаем (никогда не отправится).
                if ($rid > $cursorCandidate) {
                    $cursorCandidate = $rid;
                }
                continue;
            }

            // Гейт записи: если у отвеченного звонка есть путь к MP3, но файла ещё
            // нет на диске, а звонок свежий — ждём докачку downloadRecords'ом.
            // Стоп без сдвига курсора (как свежесть): на следующем тике запись
            // успеет докачаться и уйдёт в B24 вместе со звонком. Иначе после
            // импорта дедуп навсегда закрыл бы повторное прикрепление записи.
            // Старше RECORD_WAIT — запись уже не придёт, шлём звонок без неё.
            $disposition = (string)($row['disposition'] ?? 'NOANSWER');
            $recPath     = (string)($row['recordingfile'] ?? '');
            if ($disposition === 'ANSWERED' && $recPath !== '' && !file_exists($recPath)) {
                $callTs = strtotime($start);
                if ($callTs !== false && (time() - $callTs) < RECORD_WAIT_SECONDS) {
                    $stopHere = true;
                    break;
                }
            }

            $billsec  = (int)($row['billsec'] ?? 0);
            $duration = $billsec > 0 ? $billsec : (int)($row['duration'] ?? 0);

            $batch[] = [
                'linkedid'               => (string)($row['linkedid'] ?? ''),
                'src_num'                => (string)($row['src_num'] ?? ''),
                'dst_num'                => (string)($row['dst_num'] ?? ''),
                'did'                    => (string)($row['did'] ?? ''),
                'start'                  => $start,
                'duration'               => (string)$duration,
                'disposition'            => $disposition,
                'recordingfile'          => $recPath,
                'from_account'           => (string)($row['from_account'] ?? ''),
                'crm_create'             => $crmCreateFlag,
                'export_records_setting' => $exportRecordsFlag,
            ];
            if ($rid > $cursorCandidate) {
                $cursorCandidate = $rid;
            }
        }

        // Отправляем батч чанками по BATCH_SIZE с паузой INVOKE_PAUSE между ними —
        // троттлинг Bitrix24. Курсор двигаем только если ВСЕ чанки страницы
        // подтверждены (иначе прогон повторится — дедуп идемпотентен).
        $ackFailed = false;
        $chunks    = array_chunk($batch, BATCH_SIZE);
        foreach ($chunks as $ci => $chunk) {
            try {
                $ack = $invoker->invoke('importHistoricalCalls', ['calls' => $chunk], 5);
            } catch (\Throwable $e) {
                $logger->writeError(
                    ['provider' => $provider['key'], 'error' => $e->getMessage(), 'chunk' => count($chunk)],
                    'Invoke failed, stopping run (cursor untouched)'
                );
                $ackFailed = true;
                break;
            }
            $status = (string)($ack['status'] ?? '');
            if ($status === WorkerBitrix24IntegrationHTTP::IMPORT_ACK_NOT_READY) {
                $logger->writeInfo(
                    ['provider' => $provider['key'], 'chunk' => count($chunk)],
                    'HTTP worker not ready (cold maps), leaving cursor untouched'
                );
                $ackFailed = true;
                break;
            }
            if ($status !== WorkerBitrix24IntegrationHTTP::IMPORT_ACK_OK) {
                $logger->writeError(
                    ['provider' => $provider['key'], 'ack' => $ack, 'chunk' => count($chunk)],
                    'Unexpected ack from HTTP worker, stopping run'
                );
                $ackFailed = true;
                break;
            }
            $processed += count($chunk);
            // Пауза между чанками (кроме последнего) — троттлинг B24.
            if ($ci < count($chunks) - 1) {
                sleep(INVOKE_PAUSE);
            }
        }

        if ($ackFailed) {
            // Воркер холодный или ошибка — не двигаем курсор, ждём след. тика.
            break;
        }

        // Сдвиг курсора (в т.ч. когда батч был пустым — все строки внутренние).
        if ($cursorCandidate > $cursor) {
            $cursor = $cursorCandidate;
            ConnectorDb::invoke(
                ConnectorDb::FUNC_UPDATE_GENERAL_SETTINGS,
                [[$cursorField => $cursor]],
                false
            );
        }

        if ($stopHere) {
            // Уперлись в свежий звонок / ждём докачку записи — дальше по этому
            // провайдеру сейчас бессмысленно.
            break;
        }
        if (count($rows) < PAGE_SIZE) {
            break; // данных больше нет
        }
        // Иначе продолжаем следующей страницей (getCdr снова затроттлится на минуту).
    }
}

$logger->writeInfo(['processed' => $processed, 'getCdr_calls' => $getCdrRuns], 'History importer finished');

/**
 * Внешний номер для Bitrix24 — длиннее 6 цифр (после очистки от не-цифр).
 * @param string $number
 * @return bool
 */
function isExternalNumber(string $number): bool
{
    $digits = preg_replace('/\D+/', '', $number);
    return strlen((string)$digits) > 6;
}
