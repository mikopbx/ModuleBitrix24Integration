<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2022 Alexey Portnov and Nikolay Beketov
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

use MikoPBX\Core\System\BeanstalkClient;
use Exception;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleBitrix24Integration\Lib\Bitrix24Integration;
use Modules\ModuleBitrix24Integration\Lib\Logger;

class UploaderB24 extends WorkerBase
{
    public const B24_UPLOADER_CHANNEL = 'b24-uploader';
    private Bitrix24Integration $b24;
    private BeanstalkClient $queueAgent;
    private Logger $logger;
    private const FILE_POLL_INTERVAL   = 2;
    private const FILE_POLL_TIMEOUT    = 180;
    private const MAX_UPLOAD_ATTEMPTS  = 8;
    private const RETRY_BASE_DELAY     = 5;
    private const RETRY_MAX_DELAY      = 600;
    private const FILE_STABLE_SECONDS  = 4;
    private const FFPROBE_MIN_DURATION = 0.01;

    /**
     * Pending upload tasks waiting for file to appear on disk.
     * Each element: ['FILENAME' => ..., 'uploadUrl' => ..., '_receivedAt' => time()]
     */
    private array $pendingTasks = [];

    private string $ffprobePath = '';

    /**
     * Начало работы демона.
     *
     * @param $argv
     */
    public function start($argv):void
    {
        $this->logger = new Logger('UploaderB24', 'ModuleBitrix24Integration');
        $this->logger->writeInfo('Start daemon...');

        $this->b24    = new Bitrix24Integration('_uploader');
        $this->ffprobePath = Util::which('ffprobe') ?: '';
        $this->logger->writeInfo("ffprobe path: '{$this->ffprobePath}'");
        $this->initBeanstalk();
        $this->logger->writeInfo('Start waiting...');
        while (!$this->needRestart) {
            try {
                $this->queueAgent->wait(self::FILE_POLL_INTERVAL);
            } catch (Exception $e) {
                $this->logger->writeError($e->getLine().';'.$e->getCode().';'.$e->getMessage());
                sleep(1);
                $this->initBeanstalk();
            }
            $this->logger->rotate();
        }
    }

    /**
     * Handles the received signal.
     *
     * @param int $signal The signal to handle.
     *
     * @return void
     */
    public function signalHandler(int $signal): void
    {
        parent::signalHandler($signal);
        cli_set_process_title('SHUTDOWN_'.cli_get_process_title());
        if (isset($this->logger)) {
            $this->logger->writeInfo("NEED SHUTDOWN ($signal)...");
        }
    }

    /**
     * Инициализация Beanstalk
     * @return void
     */
    private function initBeanstalk():void
    {
        $this->logger->writeInfo('Init Beanstalk...');
        $this->queueAgent = new BeanstalkClient(self::B24_UPLOADER_CHANNEL);
        $this->queueAgent->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);
        $this->queueAgent->subscribe(self::B24_UPLOADER_CHANNEL,  [$this, 'callBack']);
        $this->queueAgent->setTimeoutHandler([$this, 'executeTasks']);
    }

    public function executeTasks(): void
    {
        if (empty($this->pendingTasks)) {
            return;
        }
        $now = time();
        $remaining = [];
        foreach ($this->pendingTasks as $task) {
            $filename   = $task['FILENAME'] ?? '';
            $receivedAt = $task['_receivedAt'] ?? $now;
            $elapsed    = $now - $receivedAt;
            if ($filename === '') {
                $this->logger->writeError('Empty filename, skipping task.');
                continue;
            }
            if (!$this->isFileReady($task, $now)) {
                if ($elapsed >= self::FILE_POLL_TIMEOUT) {
                    $this->requeueWithDelay($task, "file not ready after {$elapsed}s");
                } else {
                    $remaining[] = $task;
                }
                continue;
            }
            try {
                $this->logger->writeInfo("File ready after {$elapsed}s, uploading '$filename'.");
                $result = $this->b24->uploadRecord($task['uploadUrl'] ?? '', $filename);
                $errorName = $result['error'] ?? '';

                if (in_array($errorName, ['expired_token', 'wrong_client', 'NO_AUTH_FOUND', 'invalid_token'], true)) {
                    $this->logger->writeError("Auth error '$errorName' during upload, will retry.");
                    $this->b24->updateToken();
                    $this->requeueWithDelay($task, "auth error: $errorName");
                    continue;
                }

                if (isset($result['result']['FILE_ID'])) {
                    $this->logger->writeInfo('Upload OK. FILE_ID: ' . $result['result']['FILE_ID']);
                } else {
                    $rawResult = json_encode($result, JSON_UNESCAPED_SLASHES);
                    $this->logger->writeError("Upload failed, no FILE_ID. Response: $rawResult");
                    $this->requeueWithDelay($task, "no FILE_ID in response");
                    continue;
                }
                usleep(300000);
            } catch (Exception $e) {
                $this->logger->writeError($e->getLine().';'.$e->getCode().';'.$e->getMessage());
                $this->requeueWithDelay($task, 'exception: ' . $e->getMessage());
            }
        }
        $this->pendingTasks = $remaining;
    }

    private function requeueWithDelay(array $task, string $reason): void
    {
        $attempts = ($task['_attempts'] ?? 0) + 1;
        $filename = $task['FILENAME'] ?? '?';
        if ($attempts >= self::MAX_UPLOAD_ATTEMPTS) {
            $this->logger->writeError("Giving up on '$filename' after $attempts attempts. Last reason: $reason");
            return;
        }
        $delay = (int)min(self::RETRY_BASE_DELAY * pow(2, $attempts - 1), self::RETRY_MAX_DELAY);
        $task['_attempts'] = $attempts;
        unset($task['_receivedAt'], $task['_lastSize'], $task['_stableSince']);
        $this->queueAgent->publish(
            json_encode($task, JSON_UNESCAPED_SLASHES),
            self::B24_UPLOADER_CHANNEL,
            1024,
            $delay
        );
        $this->logger->writeInfo("Requeued '$filename', attempt $attempts, delay {$delay}s. Reason: $reason");
    }

    /**
     * Файл считается готовым только когда размер не менялся FILE_STABLE_SECONDS
     * и ffprobe подтверждает валидный Matroska контейнер. WorkerWav2Webm пишет
     * ffmpeg'ом прямо в целевой .webm без atomic rename, поэтому одного file_exists()
     * недостаточно — состояние trackается между итерациями executeTasks().
     */
    private function isFileReady(array &$task, int $now): bool
    {
        $filename = $task['FILENAME'] ?? '';
        if ($filename === '' || !file_exists($filename)) {
            return false;
        }
        $size = @filesize($filename);
        if ($size === false || $size <= 0) {
            return false;
        }
        $lastSize    = $task['_lastSize']    ?? -1;
        $stableSince = $task['_stableSince'] ?? 0;
        if ($size !== $lastSize) {
            $task['_lastSize']    = $size;
            $task['_stableSince'] = $now;
            return false;
        }
        if (($now - $stableSince) < self::FILE_STABLE_SECONDS) {
            return false;
        }
        return $this->isWebmValid($filename);
    }

    private function isWebmValid(string $filename): bool
    {
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'webm') {
            return true;
        }
        if ($this->ffprobePath === '') {
            return true;
        }
        $cmd = escapeshellcmd($this->ffprobePath)
            . ' -v error -show_entries format=duration -of default=nw=1:nk=1 '
            . escapeshellarg($filename)
            . ' 2>/dev/null';
        $out = [];
        $rc  = 0;
        exec($cmd, $out, $rc);
        if ($rc !== 0 || empty($out)) {
            return false;
        }
        return (float)trim($out[0]) >= self::FFPROBE_MIN_DURATION;
    }

    /**
     * @param BeanstalkClient $client
     */
    public function callBack(BeanstalkClient $client): void
    {
        $stringData = $client->getBody();
        $this->logger->writeInfo("Queue upload task.");
        $this->logger->writeInfo("Raw data: $stringData");
        try {
            /** @var array $data */
            $data = json_decode($stringData, true, 512, JSON_THROW_ON_ERROR);
        }catch (Exception $e){
            $data = null;
        }
        if(!is_array($data)){
            $this->logger->writeError("Data is not valid JSON.");
            return;
        }
        $data['_receivedAt'] = time();
        $this->pendingTasks[] = $data;
        $this->logger->writeInfo('Task queued, polling for file every '.self::FILE_POLL_INTERVAL.'s (max '.self::FILE_POLL_TIMEOUT.'s).');
    }
}

// Start worker process
if(isset($argv) && count($argv) !== 1) {
    UploaderB24::startWorker($argv??[]);
}
