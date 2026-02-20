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
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleBitrix24Integration\Lib\Bitrix24Integration;
use Modules\ModuleBitrix24Integration\Lib\CacheManager;
use Modules\ModuleBitrix24Integration\Lib\Logger;
use Modules\ModuleBitrix24Integration\Lib\MikoPBXVersion;
use Modules\ModuleBitrix24Integration\Models\B24PhoneBook;
use Modules\ModuleBitrix24Integration\Models\ContactLinks;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24CDR;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24ExternalLines;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Integration;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Users;
use Throwable;

class ConnectorDb extends WorkerBase
{
    public const FUNC_GET_GENERAL_SETTINGS          = "getGeneralSettings";
    public const FUNC_UPDATE_LINKS                  = "updateLinks";
    public const FUNC_UPDATE_GENERAL_SETTINGS       = "updateGeneralSettings";
    public const FUNC_DELETE_CONTACT_DATA           = "deletePhoneContact";
    public const FUNC_UPDATE_ENT_CONTACT            = "updateEntContact";
    public const FUNC_GET_CONTACT_BY_PHONE_USER     = "getContactsByPhoneAndUser";
    public const FUNC_GET_CONTACT_BY_PHONE          = "getContactsByPhone";
    public const FUNC_GET_CONTACT_BY_PHONE_ORDER    = "getContactsByPhoneWithOrder";
    public const FUNC_FIND_CDR_BY_UID               = "findCdrByUID";
    public const FUNC_UPDATE_CDR_BY_UID             = "updateCdrByUID";
    public const FUNC_GET_CDR_BY_LINKED_ID          = "getCdrDataByLinkedId";
    public const FUNC_GET_CDR_BY_FILTER             = "getCdrByFilter";
    public const FUNC_UPDATE_FROM_ARRAY_CDR_BY_UID  = "updateCdrFromArrayByUID";
    public const FUNC_SAVE_EXTERNAL_LINES           = "saveExternalLinesData";
    public const FUNC_GET_EXTERNAL_LINES            = "getExternalLines";
    public const FUNC_GET_FIRST_EXTERNAL_LINES      = "getFirstExternalLines";
    public const FUNC_DELETE_EXTERNAL_LINE          = "deleteExternalLines";
    public const FUNC_GET_USERS                     = "getUsers";
    public const FUNC_SAVE_USERS                    = "saveUsers";

    private Logger  $logger;
    private const MODULE_ID = 'ModuleBitrix24Integration';
    private int $clearTime = 0;

    /** Redis cache key for getGeneralSettings (task 3.2). */
    private const CACHE_KEY_GENERAL_SETTINGS = 'general_settings';

    /** Adaptive timeouts per function (task 3.3). CDR — 2s, reads — 3s, writes — 10-15s. */
    private const FUNC_TIMEOUTS = [
        'getGeneralSettings'          => 3,
        'getUsers'                    => 3,
        'getExternalLines'            => 3,
        'getFirstExternalLines'       => 3,
        'getContactsByPhone'          => 3,
        'getContactsByPhoneAndUser'   => 3,
        'getContactsByPhoneWithOrder' => 3,
        'findCdrByUID'                => 2,
        'updateCdrByUID'              => 2,
        'getCdrDataByLinkedId'        => 2,
        'getCdrByFilter'              => 2,
        'updateCdrFromArrayByUID'     => 2,
        'updateGeneralSettings'       => 10,
        'updateEntContact'            => 15,
    ];

    private Bitrix24Integration $b24;
    private array $b24Users = [];

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
        cli_set_process_title("SHUTDOWN_" . self::class);
        $this->logger->writeInfo('SHUTDOWN...');
    }

    /**
     * Callback for the ping to keep the connection alive.
     *
     * @param BeanstalkClient $message The received message.
     *
     * @return void
     */
    public function pingCallBack(BeanstalkClient $message): void
    {
        $this->logger->writeInfo(getmypid().': pingCallBack ...');
        $this->logger->rotate();
        parent::pingCallBack($message);
        $this->updateUsers();
    }

    /**
     * Старт работы листнера.
     *
     * @param $argv
     */
    public function start($argv):void
    {
        $this->logger =  new Logger('ConnectorDb', self::MODULE_ID);
        $this->logger->writeInfo('Starting');
        $beanstalk      = new BeanstalkClient(self::class);
        $beanstalk->subscribe(self::class, [$this, 'onEvents']);
        $beanstalk->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);

        $this->b24 = new Bitrix24Integration('_www');
        $this->updateUsers();
        while ($this->needRestart === false) {
            $beanstalk->wait();
        }
    }

    public function updateUsers()
    {
        $this->b24Users = [];
        $usersB24 = $this->b24->userGet(true)['result']??[];
        foreach ($usersB24 as $userB24){
            $this->b24Users[$userB24['ID']??''] = $userB24['UF_PHONE_INNER']??'';
        }
        $this->logger->writeInfo($this->b24Users, 'b24Users');
    }

    /**
     * Возвращает данные из кэш.
     *
     * @param $cacheKey
     *
     * @return mixed|null
     */
    public function getCache($cacheKey)
    {
        return $this->di->getManagedCache()->get($cacheKey);
    }

    /**
     * Сохраняет даныне в кэш.
     *
     * @param string $cacheKey ключ
     * @param mixed  $resData  данные
     * @param int    $ttl      время жизни кеша
     */
    public function saveCache(string $cacheKey, $resData, int $ttl = 30): void
    {
        $managedCache = $this->di->getManagedCache();
        $managedCache->set($cacheKey, $resData, $ttl);
    }

    /**
     * Получение запросов на идентификацию номера телефона.
     * @param $tube
     * @return void
     */
    public function onEvents($tube): void
    {
        try {
            $data = json_decode($tube->getBody(), true, 512, JSON_THROW_ON_ERROR);
        }catch (\Throwable $e){
            $tube->reply(false);
            return;
        }
        $res_data = [];
        $packedResult = '[]';
        if($data['action'] === 'invoke'){
            $funcName = $data['function']??'';
            if(method_exists($this, $funcName)){
                $this->logger->rotate();
                $args = $this->getArgs($data);

                $startTime = microtime(true);
                $this->logger->writeInfo($args, "REQUEST $funcName ". getmypid());
                if(count($args) === 0){
                    if(!in_array($funcName,[self::FUNC_DELETE_CONTACT_DATA, self::FUNC_UPDATE_ENT_CONTACT, self::FUNC_GET_CDR_BY_FILTER])){
                        try {
                            $res_data = $this->$funcName();
                        }catch (Throwable $e){
                            $this->logger->writeError($data, 'Function exec error');
                            $res_data = [];
                        }
                    }
                }else{
                    if(array_keys($args) !== range(0, count($args) - 1)){
                        $this->logger->writeError(['function' => $funcName, 'keys' => array_keys($args)], 'String keys in args, skip request');
                    }else{
                        $res_data = $this->$funcName(...$args);
                    }
                }
                $packedResult = self::packResult($res_data);

                $durationMs = round((microtime(true) - $startTime) * 1000, 1);
                if ($durationMs > 100) {
                    $this->logger->writeError($durationMs . 'ms', "SLOW REQUEST $funcName");
                }
            }
        }
        if(isset($data['need-ret'])){
            $tube->reply($packedResult);
            $this->logger->writeInfo($res_data, 'RESPONSE');
        }
        if( (time() - $this->clearTime) > 60){
            $this->clearTime = time();
            $findPath   = Util::which('find');
            $tmoDirName = self::getTempDir();
            exec("$findPath $tmoDirName -mmin +5 -type f -delete > /dev/null 2>&1 &");
            $this->logger->rotate();
        }
    }

    /**
     * Получает сериализованные аргументы.
     * @param $data
     * @return array
     */
    private function getArgs($data):array
    {
        $args = $data['args']??[];
        if(is_string($args)){
            try {
                $object = json_decode(file_get_contents($args), true, 512, JSON_THROW_ON_ERROR);
            }catch (\Throwable $e){
                $object = [];
            }
            unlink($args);
            $args = $object;
        }
        if(!is_array($args)){
            $args = [];
        }
        return $args;
    }

    /**
     * Порог для inline-передачи данных через Beanstalk (байт).
     * Данные меньше порога передаются в JSON напрямую, больше — через tmp-файл.
     */
    private const INLINE_THRESHOLD = 4096;

    /**
     * Сериализует данные и сохраняет их во временный файл.
     * @param $data
     * @return string
     */
    public static function saveResultInTmpFile($data):string
    {
        try {
            $res_data = json_encode($data, JSON_THROW_ON_ERROR);
        }catch (\JsonException $e){
            return '';
        }
        $tmpDir     = self::getTempDir();
        $filename = tempnam($tmpDir, 'b24-');
        if ($filename === false) {
            return '';
        }
        file_put_contents($filename, $res_data);
        chown($filename, 'www');
        return $filename;
    }

    /**
     * Упаковка аргументов для передачи через Beanstalk.
     * Малые данные передаются inline (массив), большие — через tmp-файл (строка-путь).
     * @param array $data
     * @return array|string  массив (inline) или строка (путь к файлу)
     */
    public static function packData(array $data)
    {
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return '';
        }
        if (strlen($json) < self::INLINE_THRESHOLD) {
            return $data;
        }
        return self::saveResultInTmpFile($data);
    }

    /**
     * Упаковка результата для reply через Beanstalk.
     * Малые данные — inline JSON строка, большие — путь к файлу (начинается с /).
     * @param $data
     * @return string
     */
    public static function packResult($data): string
    {
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return '';
        }
        if (strlen($json) < self::INLINE_THRESHOLD) {
            return $json;
        }
        return self::saveResultInTmpFile($data);
    }

    /**
     * Распаковка результата, полученного из Beanstalk reply.
     * Если строка начинается с / — читаем из файла, иначе — inline JSON.
     * @param string $result
     * @return array
     */
    public static function unpackResult(string $result): array
    {
        if ($result === '') {
            return [];
        }
        try {
            if ($result[0] === '/') {
                if (!file_exists($result)) {
                    return [];
                }
                $data = json_decode(file_get_contents($result), true, 512, JSON_THROW_ON_ERROR);
                unlink($result);
                return is_array($data) ? $data : [];
            }
            $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return string
     */
    public static function getTempDir():string
    {
        $di = MikoPBXVersion::getDefaultDi();
        if(!$di){
            return '/tmp/';
        }
        $dirsConfig = $di->getShared('config');
        $tmoDirName = $dirsConfig->path('core.tempDir') . '/'.self::MODULE_ID;
        Util::mwMkdir($tmoDirName, true);
        if (file_exists($tmoDirName)) {
            $tmpDir = $tmoDirName;
        }else{
            $tmpDir = '/tmp/';
        }
        return $tmpDir;
    }

    /**
     * Метод следует вызывать при работе с API из прочих процессов.
     * @param string $function
     * @param array $args
     * @param bool $retVal
     * @param int $timeout
     * @return array|bool|mixed
     */
    public static function invoke(string $function, array $args = [], bool $retVal = true, int $timeout = 0){
        // 3.2: Redis cache for getGeneralSettings — skip RPC if cached
        if (self::FUNC_GET_GENERAL_SETTINGS === $function && empty($args) && $retVal) {
            $cached = CacheManager::getCacheData(self::CACHE_KEY_GENERAL_SETTINGS);
            if (!empty($cached)) {
                return (object)$cached;
            }
        }
        // 3.3: adaptive timeout per function
        if ($timeout <= 0) {
            $timeout = self::FUNC_TIMEOUTS[$function] ?? 5;
        }
        $req = [
            'action'   => 'invoke',
            'function' => $function,
            'args'     => self::packData($args)
        ];
        $client = new BeanstalkClient(self::class);
        $object = [];
        try {
            if($retVal){
                $req['need-ret'] = true;
                $result = $client->request(json_encode($req, JSON_THROW_ON_ERROR), $timeout);
            }else{
                $client->publish(json_encode($req, JSON_THROW_ON_ERROR));
                return true;
            }
            $object = self::unpackResult($result);
        } catch (\Throwable $e) {
            $object = [];
        }

        if(self::FUNC_GET_GENERAL_SETTINGS === $function){
            if(empty($result)){
                $object = ModuleBitrix24Integration::findFirst();
            }else{
                $object = (object)$object;
            }
        }elseif (self::FUNC_FIND_CDR_BY_UID === $function){
            $object = empty($object)?null:(object)$object;
        }
        return $object;
    }

    /**
     * Метод следует вызывать при работе с API из прочих процессов.
     * @param string $function
     * @param array $args
     * @param bool $retVal
     * @param int $timeout
     * @return array|bool|mixed
     */
    public static function invokePriority(string $function, array $args = [], bool $retVal = true, int $timeout = 0){
        // 3.2: Redis cache for getGeneralSettings — skip RPC if cached
        if (self::FUNC_GET_GENERAL_SETTINGS === $function && empty($args) && $retVal) {
            $cached = CacheManager::getCacheData(self::CACHE_KEY_GENERAL_SETTINGS);
            if (!empty($cached)) {
                return (object)$cached;
            }
        }
        // 3.3: adaptive timeout per function
        if ($timeout <= 0) {
            $timeout = self::FUNC_TIMEOUTS[$function] ?? 5;
        }
        $req = [
            'action'   => 'invoke',
            'function' => $function,
            'args'     => self::packData($args)
        ];
        $client = new BeanstalkClient(self::class);
        $object = [];
        try {
            if($retVal){
                $req['need-ret'] = true;
                $result = $client->request(json_encode($req, JSON_THROW_ON_ERROR), $timeout, 1);
            }else{
                $client->publish(json_encode($req, JSON_THROW_ON_ERROR));
                return true;
            }
            $object = self::unpackResult($result);
        } catch (\Throwable $e) {
            $object = [];
        }

        if(self::FUNC_GET_GENERAL_SETTINGS === $function){
            if(empty($result)){
                $object = ModuleBitrix24Integration::findFirst();
            }else{
                $object = (object)$object;
            }
        }elseif (self::FUNC_FIND_CDR_BY_UID === $function){
            $object = empty($object)?null:(object)$object;
        }
        return $object;
    }

    // USERS

    /**
     * Возвращает список пользователей.
     * @param array $filter
     * @return array
     */
    public function getUsers(array $filter = []):array
    {
        $result = ModuleBitrix24Users::find($filter)->toArray();
        CacheManager::setCacheData(ModuleBitrix24Users::class, $result, 3600*12);
        return $result;
    }

    /**
     * Сохраняем таблицу пользователей.
     * @param $arrUsersPost
     * @return bool
     */
    public function saveUsers($arrUsersPost):bool
    {
        $result = true;

        // Чистим устаревшие данные.
        $ids = array_column($arrUsersPost,'user_id');
        $parameters   = [
            'conditions' => 'user_id NOT IN ({ids:array})',
            'bind'       => [
                'ids' => $ids,
            ],
        ];
        ModuleBitrix24Users::find($parameters)->delete();

        // Обновляем существующие данные.
        foreach ($arrUsersPost as $rowData) {
            $userId         = $rowData['user_id'];
            $parameters   = [
                'conditions' => 'user_id=:userId:',
                'bind'       => [
                    'userId' => $userId,
                ],
            ];
            $userSettings = ModuleBitrix24Users::findFirst($parameters);
            if ( ! $userSettings) {
                $userSettings          = new ModuleBitrix24Users();
                $userSettings->user_id = $userId;
            }
            $userSettings->open_card_mode = $rowData['open_card_mode'];
            $userSettings->disabled       = $rowData['disabled'] ? '1' : '0';
            if ($userSettings->save() === false) {
                $result = false;
            }
        }

        return $result;
    }

    // EXTERNAL LINES

    /**
     * Удаление линии по ID
     * @param $id
     * @return bool
     */
    public function deleteExternalLines($id):bool
    {
        $record = ModuleBitrix24ExternalLines::findFirstById($id);
        if ($record !== null) {
            return $record->delete();
        }
        return true;
    }

    /**
     * Возвращает все внешние линии.
     * @param array $filter
     * @return array
     */
    public function getExternalLines(array $filter = []):array
    {
        return ModuleBitrix24ExternalLines::find($filter)->toArray();
    }

    /**
     * Возвращает все внешние линии.
     * @param array $filter
     * @return array
     */
    public function getFirstExternalLines(array $filter= []):array
    {
        return ModuleBitrix24ExternalLines::findFirst($filter)->toArray();
    }

    /**
     * Сохранение настроек внешних линий.
     * @param array $externalLinesPost
     * @return bool
     */
    public function saveExternalLinesData(array $externalLinesPost):bool
    {
        if(empty($externalLinesPost)){
            // Больше нет внешних линий, чистим все.
            $filter = [];
        }else{
            $filter = [
                'conditions' => 'id NOT IN ({ids:array})',
                'bind' => ['ids' => array_column($externalLinesPost, 'id')]
            ];
        }
        $externalLines = ModuleBitrix24ExternalLines::find($filter);
        foreach ($externalLines as $externalLine){
            $externalLine->delete();
        }
        foreach ($externalLinesPost as $record) {
            if (!isset($record['id']) && empty($record['id'])){
                continue;
            }
            $externalLine = ModuleBitrix24ExternalLines::findFirstById($record['id']);
            if ($externalLine===null){
                $externalLine = new ModuleBitrix24ExternalLines();
            }
            foreach ($externalLine as $key => $value){
                if($key === 'id'){
                    continue;
                }
                if ( ! array_key_exists($key, $record)) {
                    $externalLine->$key = '';
                } else {
                    $externalLine->$key = $record[$key];
                }
            }
            if ($externalLine->save() === false) {
                return false;
            }
        }
        return true;
    }

    // GENERAL SETTINGS

    /**
     * Возвращает основные настройки.
     * @param array $filter
     * @return array
     */
    public function getGeneralSettings(array $filter = []):array
    {
        $settings = ModuleBitrix24Integration::findFirst($filter);
        if($settings === null){
            $settings = new ModuleBitrix24Integration();
        }
        $result = $settings->toArray();
        if (empty($filter)) {
            CacheManager::setCacheData(self::CACHE_KEY_GENERAL_SETTINGS, $result, 30);
        }
        return $result;
    }

    /**
     * Обновление настроек по данным db.
     * @param array $data
     * @return bool
     */
    public function updateGeneralSettings(array $data):bool
    {
        $record = ModuleBitrix24Integration::findFirst();
        if($record === null){
            $record = new ModuleBitrix24Integration();
        }
        foreach ($record as $key => $value) {
            if (array_key_exists($key, $data)) {
                $record->$key = $data[$key];
            }
        }
        $result = false;
        try {
            $result = $record->save();
            CacheManager::setCacheData(self::CACHE_KEY_GENERAL_SETTINGS, [], 1);
        }catch (\Throwable $e){
            $this->logger->writeError($e->getMessage());
        }
        return $result;
    }

    // CONTACTS

    /**
     * Возвращает данные контактов по номеру телефона.
     * @param $phone
     * @return array
     */
    public function getContactsByPhone($phone):array
    {
        if(!is_string($phone)){
            return [];
        }
        $filter = [
            "phoneId = :phoneId: AND statusLeadId<>'S' AND statusLeadId<>'F'",
            'bind' => [
                'phoneId'     => Bitrix24Integration::getPhoneIndex($phone)
            ],
            'order' => 'dateCreate',
        ];
        return B24PhoneBook::find($filter)->toArray();
    }

    /**
     * Возвращает данные контактов по номеру телефона.
     * С сортировкой и отбором по типу.
     * @param string $phone
     * @param array $types
     * @param bool $contactWithLink
     * @return array
     */
    public function getContactsByPhoneWithOrder(string $phone, array $types, bool $contactWithLink = false):array
    {
        if(!is_string($phone) || empty($phone)){
            return [];
        }
        $typesFiltered = [];
        foreach ($types as $type){
            if(in_array($type, ['CONTACT', 'COMPANY', 'LEAD'])){
                $typesFiltered[] = $type;
            }
        }

        $filter = [
            'conditions' => "phoneId = :phoneId: AND statusLeadId<>'S' AND statusLeadId<>'F'",
            'bind' => [
                'phoneId'   => Bitrix24Integration::getPhoneIndex($phone)
            ],
            'order' => "dateCreate",
        ];

        if(!empty($typesFiltered)){
            $filter['conditions'] .= ' AND contactType IN ({types:array})';
            $filter['bind']['types'] = $types;
        }
        $data = B24PhoneBook::find($filter)->toArray();

        ///
        // Получим идентификаторы контактов.
        if($contactWithLink){
            $filtered = array_filter($data, function ($item) {
                return $item['contactType'] === 'CONTACT';
            });
            $ids = array_map(function ($item) {return $item['b24id'];}, $filtered);
            if(!empty($ids)){
                $filter = [
                    'columns' => 'contactId',
                    'conditions' => "contactId IN ({ids:array})",
                    'bind' => [
                        'ids' => $ids
                    ]
                ];
                $links = ContactLinks::find($filter)->toArray();
                $contactIds = array_column($links, 'contactId');
                $data = array_filter($data, function ($item) use ($contactIds) {
                    return in_array($item['b24id'], $contactIds, true);
                });
            } else {
                $data = [];
            }
        }
        ///
        // Сортируем по типу контакта.
        if(!empty($typesFiltered)){
            $priorities = array_flip(array_unique($typesFiltered));
            usort($data, function ($a, $b) use ($priorities) {
                $priorityA = $priorities[$a['contactType']] ?? 4;
                $priorityB = $priorities[$b['contactType']] ?? 4;
                if ($priorityA === $priorityB) {
                    return strtotime($b['dateCreate']) - strtotime($a['dateCreate']);
                }
                return $priorityA - $priorityB;
            });
        }
        foreach ($data as $index => $rawData){
            $data[$index]['userPhone'] = $this->b24Users[$rawData['userId']]??'';
        }

        return $data;
    }

    /**
     * Возвращает данные контактов по номеру телефона.
     * @param string $phone
     * @param string $userId
     * @return array
     */
    public function getContactsByPhoneAndUser(string $phone, string $userId):array
    {
        $filter = [
            "phoneId = :phoneId: AND statusLeadId<>'S' AND statusLeadId<>'F' AND userId=:userId:",
            'bind' => [
                'phoneId'    => Bitrix24Integration::getPhoneIndex($phone),
                'userId'     => $userId
            ],
            'order' => 'contactType DESC,dateCreate',
            'limit' => 1
        ];
        $contactData = B24PhoneBook::findFirst($filter);
        if($contactData){
            $result = $contactData->toArray();
        }else{
            $result = [];
        }
        return $result;
    }

    /**
     * Удаляем контакт по ID;
     * @param string $contactType
     * @param        $b24id
     * @param array  $phoneIds
     * @return bool
     */
    public function deletePhoneContact(string $contactType, $b24id, array $phoneIds = []):bool
    {
        try {
            $record = new B24PhoneBook();
            $db = $record->getWriteConnection();
            $table = $record->getSource();

            $sql = "DELETE FROM {$table} WHERE contactType = ? AND b24id = ?";
            $params = [$contactType, $b24id];

            if (!empty($phoneIds)) {
                $placeholders = implode(',', array_fill(0, count($phoneIds), '?'));
                $sql .= " AND phoneId NOT IN ({$placeholders})";
                $params = array_merge($params, $phoneIds);
            }
            $result = $db->execute($sql, $params);
        } catch (Throwable $exception) {
            $errorDetails = [
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
                'time' => date('Y-m-d H:i:s'),
            ];
            $this->logger->writeInfo(['b24id' => $b24id, 'contactType' => $contactType, 'error' => $errorDetails], 'Fail B24PhoneBook delete...');
            $result = false;
        }
        return $result;
    }

    /**
     * Добавление контакта в телефонную книгу.
     * Использует batch INSERT в одной транзакции вместо отдельных findFirst+save.
     * @param $b24id
     * @param $contactType
     * @param $contacts
     * @return void
     */
    public function addPhoneContact($b24id, $contactType, $contacts):void
    {
        try {
            $record = new B24PhoneBook();
            $db = $record->getWriteConnection();
            $table = $record->getSource();
            $columns = ['b24id', 'userId', 'dateCreate', 'dateModify', 'statusLeadId', 'name', 'phone', 'phoneId', 'contactType'];

            $startTime = microtime(true);
            $db->begin();

            // Удаляем все записи контакта — заменим полным набором через INSERT
            $db->execute(
                "DELETE FROM {$table} WHERE contactType = ? AND b24id = ?",
                [$contactType, $b24id]
            );

            // Batch INSERT всех телефонов контакта
            if (!empty($contacts)) {
                $placeholderRow = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
                $rows = [];
                $params = [];
                foreach ($contacts as $contact) {
                    $rows[] = $placeholderRow;
                    foreach ($columns as $col) {
                        $params[] = $contact[$col] ?? '';
                    }
                }
                $sql = "INSERT INTO {$table} (" . implode(',', $columns) . ") VALUES " . implode(',', $rows);
                $db->execute($sql, $params);
            }

            $db->commit();

            $duration = microtime(true) - $startTime;
            if ($duration > 0.1) {
                $this->logger->writeInfo(round($duration, 3), "addPhoneContact batch time ($b24id, " . count($contacts) . " phones)");
            }
        } catch (Throwable $e) {
            if ($db->isUnderTransaction()) {
                $db->rollback();
            }
            $this->logger->writeInfo(
                ['b24id' => $b24id, 'contactType' => $contactType, 'error' => $e->getMessage()],
                'Fail addPhoneContact batch'
            );
        }
    }

    /**
     * Синхронизация телефонной книги.
     * @param $action
     * @param $data
     * @return array
     */
    public function updateEntContact($action, $data):array
    {
        $contactTypes = [
            Bitrix24Integration::API_CRM_LIST_CONTACT => 'CONTACT',
            Bitrix24Integration::API_CRM_LIST_COMPANY => 'COMPANY',
            Bitrix24Integration::API_CRM_LIST_LEAD    => 'LEAD',
        ];
        $idNames = [
            Bitrix24Integration::API_CRM_LIST_CONTACT => 'lastContactId',
            Bitrix24Integration::API_CRM_LIST_COMPANY => 'lastCompanyId',
            Bitrix24Integration::API_CRM_LIST_LEAD    => 'lastLeadId',
        ];
        $maxId = '';
        foreach ($data as $entData){
            $id          = $entData['ID'];
            $maxId       = max($maxId, $id);
            $userId      = $entData['ASSIGNED_BY_ID'];
            $dateCreate  = $entData['DATE_CREATE'];
            $dateModify  = $entData['DATE_MODIFY'];
            $statusLeadId= $entData['STATUS_SEMANTIC_ID']??'';
            if(Bitrix24Integration::API_CRM_LIST_CONTACT === $action){
                $name  = $entData['LAST_NAME'] ." " . $entData['NAME']. " " . $entData['SECOND_NAME'];
            }else{
                $name  = $entData['TITLE'];
            }
            $contactType = $contactTypes[$action]??'';
            $phonesArray = $entData['PHONE']??[];

            $contactsArray = [];
            foreach ($phonesArray as $phoneData){
                $phoneIndex = Bitrix24Integration::getPhoneIndex($phoneData['VALUE']);
                if(empty($phoneIndex)){
                    continue;
                }
                $pbRow = new \stdClass();
                $pbRow->b24id        = $id;
                $pbRow->userId       = $userId;
                $pbRow->dateCreate   = $dateCreate;
                $pbRow->dateModify   = $dateModify;
                $pbRow->statusLeadId = $statusLeadId;
                $pbRow->name         = $name;
                $pbRow->phone        = $phoneData['VALUE'];
                $pbRow->phoneId      = $phoneIndex;
                $pbRow->contactType  = $contactType;
                $contactsArray[]     = (array)$pbRow;
            }
            $this->addPhoneContact($id, $contactType, $contactsArray);
        }
        $filter   = ['columns'=> array_values($idNames)];
        $settings = (object)$this->getGeneralSettings($filter);
        if(!$settings){
            return [];
        }
        if(!empty($maxId)){
            $idName = $idNames[$action];
            $settings->$idName = $maxId;
            $this->updateGeneralSettings((array)$settings);
        }
        return $this->getGeneralSettings($filter);
    }

    public function updateLinks($data = []):array
    {
        $result = [];
        $record = new ContactLinks();
        $db = $record->getWriteConnection();
        $table = $record->getSource();

        foreach ($data as $key => $linkData){
            [$method, $id] = explode('_', $key);
            if($method === Bitrix24Integration::API_CRM_CONTACT_COMPANY){
                $companyIDS = array_column($linkData, 'COMPANY_ID');
                if(!empty($companyIDS)){
                    $placeholders = implode(',', array_fill(0, count($companyIDS), '?'));
                    $db->execute(
                        "DELETE FROM {$table} WHERE contactId = ? AND companyId NOT IN ({$placeholders})",
                        array_merge([$id], $companyIDS)
                    );
                }
                foreach ($companyIDS as $companyID){
                    $filter = [
                        'contactId=:contactId: AND companyId=:companyId:',
                        'bind' => [
                            'companyId' => $companyID,
                            'contactId' => $id
                        ]
                    ];
                    $link = ContactLinks::findFirst($filter);
                    if(!$link){
                        $link = new ContactLinks();
                    }
                    $link->contactId = $id;
                    $link->companyId = $companyID;
                    $link->save();
                }

            } elseif ($method === Bitrix24Integration::API_CRM_COMPANY_CONTACT){
                $contactIDS = array_column($linkData, 'CONTACT_ID');
                if(!empty($contactIDS)) {
                    $placeholders = implode(',', array_fill(0, count($contactIDS), '?'));
                    $db->execute(
                        "DELETE FROM {$table} WHERE companyId = ? AND contactId NOT IN ({$placeholders})",
                        array_merge([$id], $contactIDS)
                    );
                }
                foreach ($contactIDS as $contactID){
                    $filter = [
                        'contactId=:contactId: AND companyId=:companyId:',
                        'bind' => [
                            'companyId' => $id,
                            'contactId' => $contactID
                        ]
                    ];
                    $link = ContactLinks::findFirst($filter);
                    if(!$link){
                        $link = new ContactLinks();
                    }
                    $link->contactId = $contactID;
                    $link->companyId = $id;
                    $link->save();
                }
            }
        }

        return $result;
    }

    // B24 CDR
    /**
     * Возвращает данные CDR по UID.
     * @param $uid
     * @return mixed
     */
    public function findCdrByUID($uid)
    {
        return ModuleBitrix24CDR::findFirst([
            'conditions' => 'uniq_id = :uid:',
            'bind' => ['uid' => $uid],
        ]);
    }

    /**
     * Дополняет данные CDR.
     * @param string $uid
     * @param array $request
     * @param array $response
     * @return bool
     */
    public function updateCdrByUID(string $uid, array $request, array $response):bool
    {
        $res = $this->findCdrByUID($request['UNIQUEID']);
        if (!$res && isset($response['CALL_ID'])) {
            $res           = new ModuleBitrix24CDR();
            $res->uniq_id  = $request['UNIQUEID'];
            $res->user_id  = $request['USER_ID'];
            $res->linkedid = $request['linkedid'];
            $res->call_id  = $response['CALL_ID'];
            $res->lead_id  = $response['CRM_CREATED_LEAD'];
            foreach ($response['CRM_CREATED_ENTITIES'] as $entity){
                if($entity['ENTITY_TYPE'] === 'CONTACT'){
                    $res->contactId = $entity['ENTITY_ID'];
                }elseif($entity['ENTITY_TYPE'] === 'DEAL'){
                    $res->dealId = $entity['ENTITY_ID'];
                }
            }
            return $res->save();
        }
        return false;
    }

    /**
     * Обновление данных CDR
     * @param string $uid
     * @param array $data
     * @return bool
     */
    public function updateCdrFromArrayByUID(string $uid, array $data):bool
    {
        $record = $this->findCdrByUID($uid);
        if (!$record) {
            return false;
        }
        foreach ($record as $key => $value) {
            if (array_key_exists($key, $data)) {
                $record->$key = $data[$key];
            }
        }
        return $record->save();
    }

    /**
     * Возвращает все данне CDR по Linked id
     * @param array $options
     * @return array
     */
    private function getCdrDataByLinkedId(array $options): array
    {
        $rows = ModuleBitrix24CDR::find([
            'conditions' => 'linkedid = :linkedid:',
            'bind' => ['linkedid' => $options['linkedid']],
        ])->toArray();
        $result = [];
        foreach ($rows as $row){
            if($row['uniq_id'] === $options['UNIQUEID']){
                $result = [$row, $row['call_id']];
                break;
            }
        }
        // if(empty($result) && $options['GLOBAL_STATUS'] === 'NOANSWER'){
        if(empty($result) && !empty($rows)){
            // Пропущенный вызов.
            // Берем первую попавшуюся CDR.
            return [$rows[0], $rows[0]['call_id']];
        }
        if(empty($result)){
            return [];
        }
        foreach ($rows as $row){
            if(!empty($row['dealId'])){
                $result[0]['deal_id']      = max($row['dealId'],$result[0]['dealId']);
                $result[0]['deal_user']    = $row['user_id'];
            }
            if(!empty($row['contactId'])){
                $result[0]['contact_id']   = max($row['contactId'],$result[0]['contactId']);
                $result[0]['contact_user'] = $row['user_id'];
            }
            if(!empty($row['lead_id'])){
                $result[0]['lead_id']   = max($row['lead_id'],$result[0]['lead_id']);
                $result[0]['lead_user'] = $row['user_id'];
            }
        }
        return $result;
    }

    /**
     * Возвращает все CDR по фильтру.
     * @param array $filter
     * @return array
     */
    private function getCdrByFilter(array $filter): array
    {
        return ModuleBitrix24CDR::find($filter)->toArray();
    }

}

if(isset($argv) && count($argv) !== 1
    && Util::getFilePathByClassName(ConnectorDb::class) === $argv[0]){
    ini_set('memory_limit', '1024M');
    ConnectorDb::startWorker($argv??[]);
}
