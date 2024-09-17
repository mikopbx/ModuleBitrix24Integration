<?php
/*
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 10 2020
 */

namespace Modules\ModuleBitrix24Integration\Lib;

use Modules\ModuleBitrix24Integration\bin\ConnectorDb;
use Phalcon\Mvc\Model\Manager;
use MikoPBX\Common\Models\CallQueues;
use MikoPBX\Common\Models\IncomingRoutingTable;
use MikoPBX\Common\Models\Extensions;
use MikoPBX\Modules\Logger;
use MikoPBX\Modules\PbxExtensionBase;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Integration;
use MikoPBX\Core\System\Util;
use Modules\ModuleBitrix24Integration\Lib\Logger as MainLogger;
use Modules\ModuleBitrix24Integration\bin\WorkerBitrix24IntegrationHTTP;

class Bitrix24Integration extends PbxExtensionBase
{
    public const URI_OAUTH = 'https://oauth.bitrix.info/oauth/token/';

    public const API_LEAD_TYPE_ALL   = 'all';
    public const API_LEAD_TYPE_IN    = 'incoming';
    public const API_LEAD_TYPE_OUT   = 'outgoing';

    public const API_ATTACH_RECORD   = 'telephony.externalCall.attachRecord';
    public const API_CALL_FINISH     = 'telephony.externalcall.finish';
    public const API_CALL_HIDE       = 'telephony.externalcall.hide';
    public const API_CALL_SHOW       = 'telephony.externalcall.show';
    public const API_CALL_REGISTER   = 'telephony.externalcall.register';
    public const API_CRM_ADD_LEAD    = 'crm.lead.add';
    public const API_CRM_ADD_CONTACT = 'crm.contact.add';
    public const API_CRM_LIST_LEAD   = 'crm.lead.list';
    public const API_CRM_LIST_COMPANY= 'crm.company.list';
    public const API_CRM_LIST_CONTACT= 'crm.contact.list';

    public const OPEN_CARD_NONE      = 'NONE';
    public const OPEN_CARD_DIRECTLY  = 'DIRECTLY';
    public const OPEN_CARD_ANSWERED  = 'ANSWERED';

    public const B24_INTEGRATION_CHANNEL = 'b24_integration_channel';
    public const B24_SEARCH_CHANNEL      = 'b24_search_channel';
    public const B24_INVOKE_REST_CHANNEL = 'b24.invoke.channel';

    public array $b24Users;
    public array $inner_numbers;
    public array $mobile_numbers;
    private $SESSION;
    public array $usersSettingsB24;
    private array $mem_cache;
    private string $refresh_token;
    private string $portal;
    public bool $initialized = false;
    private string $b24_region;
    private string $client_id;
    private string $client_secret;
    private string $queueExtension = '';
    private string $queueUid = '';
    private bool $backgroundUpload = false;
    public MainLogger $mainLogger;
    private bool $mainProcess = false;
    private int $updateTokenTime = 300;

    public $lastContactId;
    public $lastCompanyId;
    public $lastLeadId;
    public $lastDealId;

    public function __construct(string $logPrefix = '')
    {
        parent::__construct();
        $this->portal = '';
        if(php_sapi_name() === "cli"){
            $this->mainProcess     = cli_get_process_title() === WorkerBitrix24IntegrationHTTP::class;
        }
        $this->mainLogger =  new MainLogger('HttpConnection'.$logPrefix, 'ModuleBitrix24Integration');
        $this->mem_cache = [];
        $data = ConnectorDb::invoke(ConnectorDb::FUNC_GET_GENERAL_SETTINGS);
        if ($data === null
            || empty($data->portal)
            || empty($data->b24_region)) {
            $this->mainLogger->writeError('Settings not set...');
            return;
        }

        $this->SESSION  = empty($data->session) ? null : json_decode($data->session, true);
        if(empty($data->refresh_token) && $this->SESSION ){
            $data->refresh_token = $this->SESSION['refresh_token']??'';
        }

        if(empty($data->refresh_token)){
            $this->mainLogger->writeError('refresh_token not set...');
            return;
        }

        $this->portal           = ''.$data->portal;
        $this->refresh_token    = ''.$data->refresh_token;
        $this->b24_region       = ''.$data->b24_region;
        $this->client_id        = ''.$data->client_id;
        $this->client_secret    = ''.$data->client_secret;
        $this->initialized      = true;

        $this->requestLogger =  new Logger('requests', $this->moduleUniqueId);
        $this->updateSettings($data);
        unset($data);
    }

    public function updateSettings($settings=null):void
    {
        if($settings === null){
            /** @var ModuleBitrix24Integration $settings */
            $settings = ConnectorDb::invoke(ConnectorDb::FUNC_GET_GENERAL_SETTINGS);
        }
        if($settings === null){
            return;
        }
        $this->b24GetPhones();
        $this->lastLeadId    = $settings->lastLeadId;
        $this->lastCompanyId = $settings->lastCompanyId;
        $this->lastContactId = $settings->lastContactId;

        $this->usersSettingsB24 = $this->getUsersSettings();
        $this->backgroundUpload = ($settings->backgroundUpload === '1');
        $default_action = IncomingRoutingTable::findFirst('priority = 9999');
        if(!empty($settings->callbackQueue)){
            $filter =  [
                'conditions' => 'extension = :id:',
                'columns'    => ['extension,uniqid'],
                'bind'       => [
                    'id' => $settings->callbackQueue,
                ]
            ];
        }elseif ($default_action !== null) {
            $data        = $default_action->toArray();
            unset($default_action);
            $filter =  [
                'conditions' => 'extension = :extension:',
                'columns'    => ['extension,uniqid'],
                'bind'       => [
                    'extension' => $data['extension'],
                ]
            ];
        }
        if(!empty($filter)){
            $extData = CallQueues::findFirst($filter);
            if($extData){
                $this->queueExtension = $extData->extension;
                $this->queueUid       = $extData->uniqid;
            }
            unset($extData);
        }
    }

    /**
     * Возвращает массив номеров запрещенных к обработке.
     *
     * @return array
     */
    private function getUsersSettings(): array
    {
        // Мы не можем использовать JOIN в разных базах данных
        $parameters            = [
            'conditions' => 'disabled <> 1',
            'columns'    => ['user_id,open_card_mode'],
        ];
        $bitrix24UsersTmp = ConnectorDb::invoke(ConnectorDb::FUNC_GET_USERS, [$parameters]);
        $uSettings = [];
        foreach ($bitrix24UsersTmp as $uData){
            $uSettings[$uData['user_id']] = $uData;
        }
        if(empty($uSettings)){
            return [];
        }
        unset($bitrix24UsersTmp);
        $parameters = [
            'conditions' => 'is_general_user_number = 1 AND type = "SIP" AND userid IN ({ids:array})',
            'columns'    => ['number,userid'],
            'bind'       => [
                'ids' => array_keys($uSettings),
            ],
        ];
        $dbData = Extensions::find($parameters)->toArray();
        $settings = [];
        foreach ($dbData as $row){
            $settings[$row['number']] = $uSettings[$row['userid']];
        }
        return $settings;
    }

    /**
     * Получает данные из кэш и чистит его
     *
     * @param  $key
     *
     * @return array | false
     */
    public function getMemCache($key)
    {
        $data = $this->mem_cache[$key] ?? false;
        if ($data) {
            unset($this->mem_cache[$key]);
        }

        return $data;
    }

    /**
     *
     */
    public function checkNeedUpdateToken(): void
    {
        $s_refresh_token = $this->SESSION["refresh_token"] ?? '';
        $s_access_token  = $this->SESSION["access_token"] ?? '';
        $expires         = (int)($this->SESSION['expires']??0);

        if (empty($s_refresh_token) || empty($s_access_token)
            || ($expires - time()) < $this->updateTokenTime) {
            $this->SESSION = ['refresh_token' => $this->refresh_token];
            $this->updateToken();
        }
        // Обновим подписки на события.
        $this->eventsBind();
    }

    /**
     * Обновление / поддержание данных сессии. Коробочная версия.
     *
     * @param null $refresh_token
     *
     * @return bool
     */
    public function updateToken($refresh_token = null): bool
    {
        if(!$this->mainProcess && !$refresh_token){
            return $this->getTokenFromSettings();
        }
        if ($refresh_token) {
            $this->SESSION                  = [];
            $this->SESSION["refresh_token"] = $refresh_token;
        }
        if ( ! isset($this->SESSION["refresh_token"])) {
            return false;
        }

        return $this->authByCode($this->SESSION["refresh_token"], $this->b24_region, 'refresh_token');
    }

    /**
     * oAuth2 аутентификация по code.
     * @param string $code
     * @param string $region
     * @param string $grantType
     * @return bool
     */
    public function authByCode(string $code, string $region, string $grantType = 'authorization_code'): bool
    {
        $tokenKey = [
            'authorization_code' => 'code',
            'refresh_token' => 'refresh_token',
        ];
        $result = false;
        $oAuthToken = ModuleBitrix24Integration::getAvailableRegions()[$region];
        if(empty($oAuthToken['CLIENT_ID'])){
            $oAuthToken['CLIENT_ID']     = $this->client_id;
            $oAuthToken['CLIENT_SECRET'] = $this->client_secret;
        }
        $params     = [
            "grant_type"          => $grantType,
            "client_id"           => $oAuthToken['CLIENT_ID'],
            "client_secret"       => $oAuthToken['CLIENT_SECRET'],
            $tokenKey[$grantType]??'-' => $code,
        ];
        $query_data = $this->query(self::URI_OAUTH, $params);
        if( ($query_data['error']??'') === 'invalid_grant' ){
            // Не корректно выбран регион.
            $oAuthToken = ModuleBitrix24Integration::getAvailableRegions()['WORLD'];
            $params['client_id']     = $oAuthToken['CLIENT_ID'];
            $params['client_secret'] = $oAuthToken['CLIENT_SECRET'];
            $query_data = $this->query(self::URI_OAUTH, $params);
        }
        if (isset($query_data["access_token"])) {
            $result = true;
            $this->updateSessionData($query_data);
            $this->mainLogger->writeInfo('The token has been successfully updated');
        }else{
            $this->mainLogger->writeError('Refresh token: '.json_encode($query_data));
        }
        return $result;
    }

    /**
     * Получение токен из настроек или кэш.
     * @return bool
     */
    private function getTokenFromSettings():bool
    {
        $result = false;
        sleep(2);
        $session = CacheManager::getCacheData('access_token');
        if(!empty($session)){
            $this->SESSION = $session;
            $result = true;
            $this->mainLogger->writeInfo('Get token from cache: '.json_encode($session));
        }else{
            // Только mainProcess процесс может обновлять информацию по токену.
            // Тк кэш пуст, получаем из базы данных.
            $data = ConnectorDb::invoke(ConnectorDb::FUNC_GET_GENERAL_SETTINGS);
            if($data){
                $this->SESSION          = empty($data->session) ? null : json_decode($data->session, true);
                $result = true;
            }
            unset($data);
        }

        return $result;
    }

    /**
     * Совершает запрос с заданными данными по заданному адресу. В ответ ожидается JSON
     * @param string $url
     * @param array  $data
     * @param        $needBuildQuery
     * @return array
     */
    private function query(string $url, array $data, $needBuildQuery = true): array
    {
        if(($data['grant_type']??'') !== 'refresh_token'){
            $expires = (int)($this->SESSION['expires']??0);
            if($expires - time() < $this->updateTokenTime){
                // Запрос обновленного token.
                $this->updateToken();
            }
        }
        if (parse_url($url) === false) {
            return [];
        }
        $q4Dump                              = json_encode($data);
        $startTime                           = microtime(true);
        $curlOptions                         = [];
        $curlOptions[CURLOPT_POST]           = true;
        $curlOptions[CURLOPT_POSTFIELDS]     = ($needBuildQuery)?http_build_query($data):$data;
        $curlOptions[CURLOPT_RETURNTRANSFER] = true;

        $status          = 0;
        $headersResponse = '';

        $totalTime = 0;
        $response  = $this->execCurl($url, $curlOptions, $status, $headersResponse, $totalTime);

         if (is_array($response)) {
            $error_name = $response['error'] ?? '';
            if ('expired_token' === $error_name) {
                $this->updateToken();
                // Обновляем параметры запроса:
                $data['auth']                    = $this->getAccessToken();
                $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($data);
                // Передышка. Сохраняем требование к кол-ву запросов в секунду (2шт.)
                usleep(500000);
                // Отправляем запрос повторно:
                $response = $this->execCurl($url, $curlOptions, $status, $headersResponse, $totalTime);
                // Проверяем наличие ошибки:
                $error_name = $response['error'] ?? '';
            } elseif ('QUERY_LIMIT_EXCEEDED' === $error_name) {
                $this->mainLogger->writeInfo('Too many requests. Sleeping 1s ... ');
                sleep(1);
                $response   = $this->execCurl($url, $curlOptions, $status, $headersResponse, $totalTime);
                $error_name = $response['error'] ?? '';
            }elseif(!empty($response['result_error'])){
                $this->mainLogger->writeInfo("RESPONSE-ERROR: ".json_encode($response['result_error']));
            }

            if (!empty($error_name)) {
                $this->mainLogger->writeError('Fail REST response ' . json_encode($response));
            }
        }
        if(isset( $data['cmd'])){
            $queues = $data['cmd'];
            unset($queues['event.get'], $queues['event.offline.get']);
            foreach ($queues as $index => $queue){
                [$apiKey] = explode('_', $index);
                if(!in_array($apiKey, [self::API_CRM_LIST_LEAD, self::API_CRM_LIST_CONTACT, self::API_CRM_LIST_COMPANY], true)){
                    continue;
                }
                if(empty($response['result']["result"][$index])){
                    unset($queues[$index]);
                }
            }

            if(!empty($queues)){
                foreach ($queues as $index => $queue){
                    $query = [];
                    parse_str(parse_url(rawurldecode($queue), PHP_URL_QUERY), $query);
                    unset($query['auth']);
                    $queues[$index] = $query;
                }
                $this->mainLogger->writeInfo("REQUEST: ".json_encode($queues, JSON_UNESCAPED_UNICODE));
                $result = $response['result']["result"]??[];
                // Чистым массив перед выводом в лог.
                if(is_array($result)){
                    foreach (['event.get', 'event.offline.get'] as $key){
                        if(isset($result[$key])){
                            unset($result[$key]);
                        }
                    }
                }
                $this->mainLogger->writeInfo("RESPONSE: ".json_encode($result, JSON_UNESCAPED_UNICODE));
            }
        }
        $this->checkErrorInResponse($response, $status);
        $delta = microtime(true) - $startTime;
        if ($delta > 5 || $totalTime > 5) {
            $this->mainLogger->writeError(
                "Slow response. PHP time:{$delta}s, cURL time: {$totalTime}, url:{$url}, Data:$q4Dump, Response: " . json_encode(
                    $response
                )
            );
        }

        $this->mainLogger->rotate();
        return $response;
    }

    private function checkErrorInResponse(&$response, $status)
    {
        if ( ! is_array($response)) {
            if ($status === 0) {
                usleep(500000);
                $key        = "http_query_status_{$status}";
                $cache_data = $this->getCache($key);
                if (empty($cache_data)) {
                    $this->mainLogger->writeError("Fail HTTP. Status: {$status}. Can not connect to host.");
                    $this->saveCache($key, '1', 60);
                }
            } else {
                $this->mainLogger->writeError("Fail HTTP. Status: {$status}. Response: $response");
            }
            $response = [];
        }

    }

    /**
     * Выполнить запрос HTTP через CURL
     *
     * @param     $url
     * @param     $curlOptions
     * @param     $status
     * @param     $headers
     * @param float $time
     *
     * @return mixed
     */
    private function execCurl($url, $curlOptions, &$status = 0, &$headers = '', float &$time = 0)
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, $curlOptions);
        curl_setopt($curl, CURLOPT_HEADER, 1);

        $result      = curl_exec($curl);
        $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $headers     = substr($result, 0, $header_size);
        $response    = trim(substr($result, $header_size));

        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $time   += (float)curl_getinfo($curl, CURLINFO_TOTAL_TIME);
        $data   = json_decode($response, true);
        if ( ! $data) {
            // Передаем ответ "КАК ЕСТЬ".
            $data = $response;
        }

        return $data;
    }

    /**
     * Возвращает значение access_token.
     *
     * @return string
     */
    public function getAccessToken(): string
    {
        return $this->SESSION['access_token'] ?? '';
    }

    /** Возвращает данные из кэш.
     *
     * @param $cacheKey
     *
     * @return mixed|null
     */
    public function getCache($cacheKey)
    {
        $value = null;
        $data = CacheManager::getCacheData($cacheKey);
        if(!empty($data) && isset($data[0])){
            $value = $data[0];
        }
        return $value;
    }

    /**
     * Функции взаимодействия с API.
     */

    /**
     * Сохраняет даныне в кэш.
     *
     * @param string $cacheKey ключ
     * @param mixed  $resData  данные
     * @param int    $ttl      время жизни кеша
     */
    public function saveCache(string $cacheKey, $resData, int $ttl = 3600): void
    {
        CacheManager::setCacheData($cacheKey, [$resData], $ttl);
    }

    /**
     * Обновление данных сессии.
     *
     * @param $query_data
     */
    private function updateSessionData($query_data): void
    {
        $query_data["ts"] = time();
        $this->SESSION    = $query_data;

        /** @var ModuleBitrix24Integration $data */
        $data = ConnectorDb::invoke(ConnectorDb::FUNC_GET_GENERAL_SETTINGS);
        if ($data === null) {
            return;
        }
        $data->session = json_encode($this->SESSION, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        ConnectorDb::invoke(ConnectorDb::FUNC_UPDATE_GENERAL_SETTINGS, [(array)$data], true, 10);
        CacheManager::setCacheData('access_token', $this->SESSION, 3600);
    }

    /**
     * Подписаться на события.
     *
     * @return bool
     */
    public function eventsBind(): bool
    {
        $eventResults = $this->eventGet();

        usleep(500000);
        $binds  = $eventResults['result']['result']['event.get'] ?? [];
        $events = [];
        foreach ($binds as $bind) {
            $events[] = $bind['event'];
        }
        $arg = [];
        $eventsAll = [
            'OnExternalCallStart',
            'OnExternalCallBackStart',
            'onCrmLeadUpdate',
            'onCrmCompanyUpdate',
            'onCrmContactUpdate',
            'onCrmContactDelete',
            'onCrmCompanyDelete',
            'onCrmLeadDelete',
        ];
        foreach ($eventsAll as $event){
            if (!in_array(strtoupper($event), $events, true)) {
                $paramsCallBack                 = [
                    "event_type" => 'offline',
                    "event"      => $event,
                    "auth"       => $this->getAccessToken(),
                ];
                $arg[$event] = 'event.bind?' . http_build_query($paramsCallBack);
            }
        }
        if (!empty($arg)) {
            $this->mainLogger->writeInfo('Update event binding...');
            $arg      = array_merge($arg, $this->eventOfflineGet());
            $response = $this->sendBatch($arg);
            $result   = empty($response['result']['result_error']??[]);
        } else {
            $result = true;
        }

        return $result;
    }

    /**
     * Получение подписок на события.
     *
     * @return array
     */
    private function eventGet(): array
    {
        $params           = [
            "auth" => $this->getAccessToken(),
        ];
        $arg["event.get"] = 'event.get?' . http_build_query($params);

        return $this->sendBatch($arg);
    }

    /**
     * Отправка файла по ссылке в b24 POST запросом.
     * @param $targetUrl
     * @param $filename
     * @return array
     */
    public function uploadRecord($targetUrl, $filename): array
    {
        if (!file_exists($filename)) {
            return [];
        }
        $post = ['file'=> curl_file_create($filename)];
        return $this->query($targetUrl, $post, false);
    }

    /**
     * Отправка пакета запросов. За раз не более 50ти.
     *
     * @param $cmd
     *
     * @return array
     */
    public function sendBatch($cmd): array
    {
        if (count($cmd) === 0) {
            return [];
        }
        $url    = "https://{$this->portal}/rest/batch";
        $params = [
            "auth" => $this->getAccessToken(),
            "halt" => 0,
            "cmd"  => $cmd,
        ];
        return $this->query($url, $params);
    }

    /**
     * Формирование команды для получения событий.
     *
     * @return mixed
     */
    public function eventOfflineGet()
    {
        $paramsCallBack           = [
            "limit" => '100',
            "auth"  => $this->getAccessToken(),
        ];
        $arg["event.offline.get"] = 'event.offline.get?' . http_build_query($paramsCallBack);

        return $arg;
    }

    /**
     * Модули: Выполнение к-либо действия.
     *
     * @param $req_data
     *
     * @return array
     */
    public function handleEvent($req_data): array
    {
        $result = [
            'result' => 'ERROR',
            'data'   => $req_data,
        ];
        $this->mainLogger->writeInfo(json_encode($req_data));
        $event = $req_data['event']??[];
        if ('ONEXTERNALCALLSTART' === $req_data['event']['EVENT_NAME']) {
            $delta = time() - strtotime($event['TIMESTAMP_X']);
            if ($delta > 15) {
                $this->mainLogger->writeInfo(
                    "An outdated response was received {$delta}s: " . json_encode($event)
                );
            }
            $FROM_USER_ID = $req_data['data']['USER_ID'];
            $dst          = $req_data['data']['PHONE_NUMBER'];

            // Повторный вызов на тот же номер возможен только через N секунд.
            $cache_key = 'tmp5_' . __FUNCTION__ . "_{$FROM_USER_ID}_$dst";
            $res_data  = $this->getCache($cache_key);
            if ($res_data !== null) {
                $this->mainLogger->writeInfo("Repeated calls to the number $dst are possible in N seconds ");

                return $result;
            }
            $this->saveCache($cache_key, $res_data, 5);

            $phone_data = $this->b24GetPhones($FROM_USER_ID);
            if ( ! empty($phone_data['peer_number'])) {
                $data         = [
                    'CALL_ID'      => $req_data['data']['CALL_ID'],
                    'USER_ID'      => $req_data['data']['USER_ID'],
                    'PHONE_NUMBER' => $req_data['data']['PHONE_NUMBER'],
                ];
                $pre_call_key = "tmp5_{$phone_data['peer_number']}_" . self::getPhoneIndex($req_data['data']['PHONE_NUMBER']);
                $this->saveCache($pre_call_key, $data, 5);
                $PHONE_NUMBER = preg_replace("/[^0-9+]/", '', urldecode($req_data['data']['PHONE_NUMBER']));
                $data         = [
                    'CRM_ENTITY_TYPE' => $req_data['data']['CRM_ENTITY_TYPE'],
                    'CRM_ENTITY_ID'   => $req_data['data']['CRM_ENTITY_ID'],
                    'PHONE_NUMBER'    => $PHONE_NUMBER,
                ];
                $pre_call_key = "tmp5_ONEXTERNALCALLBACKSTART_" . self::getPhoneIndex($PHONE_NUMBER);
                $this->saveCache($pre_call_key, $data, 5);

                Util::amiOriginate($phone_data['peer_number'], '', $PHONE_NUMBER);
                $this->mainLogger->writeInfo("ONEXTERNALCALLSTART: originate from user {$FROM_USER_ID} <{$phone_data['peer_number']}> to $dst)");
            }else{
                $this->mainLogger->writeInfo('User: '.$FROM_USER_ID." - ".json_encode($req_data));
            }
        } elseif ('ONEXTERNALCALLBACKSTART' === $req_data['event']['EVENT_NAME']) {
            $PHONE_NUMBER = preg_replace("/[^0-9+]/", '', urldecode($req_data['data']['PHONE_NUMBER']));
            $data         = [
                'CRM_ENTITY_TYPE' => $req_data['data']['CRM_ENTITY_TYPE'],
                'CRM_ENTITY_ID'   => $req_data['data']['CRM_ENTITY_ID'],
                'PHONE_NUMBER'    => $PHONE_NUMBER,
            ];
            $pre_call_key = "tmp5_ONEXTERNALCALLBACKSTART_" . self::getPhoneIndex($PHONE_NUMBER);
            $this->saveCache($pre_call_key, $data, 5);

            if(empty($this->queueUid)){
                $this->mainLogger->writeInfo("ONEXTERNALCALLBACKSTART: default action for incoming rout is not queue)");
            }else{
                $channel        = "Local/{$this->queueExtension}@internal-originate";
                $variable       = "pt1c_cid={$PHONE_NUMBER},SRC_QUEUE={$this->queueUid}";
                $am       = Util::getAstManager('off');
                $am->Originate($channel, $PHONE_NUMBER, 'all_peers', '1', null, null, null, null, $variable, null, true);
                $this->mainLogger->writeInfo("ONEXTERNALCALLBACKSTART: originate from queue {$data['extension']} to {$PHONE_NUMBER})");
            }
        }

        return $result;
    }

    /**
     * Получение массива пользователей. Ключ массива - номер телефона пользователея.
     *
     * @param null $user_id
     *
     * @return array
     */
    public function b24GetPhones($user_id = null): array
    {
        $data       = [];
        $users_list = $this->userGet();

        if ( ! isset($users_list['result']) || ! is_array($users_list['result'])) {
            return $data;
        }
        if ($user_id) {
            foreach ($users_list['result'] as $value) {
                if ($value['ID'] === $user_id) {
                    $peer_number = preg_replace('/(\D)/', '', $value['UF_PHONE_INNER']);
                    $peer_mobile = preg_replace('/(\D)/', '', $value['PERSONAL_MOBILE']);

                    // Проверим, существуют ли номера в разрешенных.
                    /** @var Extensions $ext_data */
                    $ext_data    = Extensions::findFirst("number={$peer_number}");
                    $peer_number = $ext_data !== null ? $ext_data->number : '';

                    if ( ! empty($peer_mobile)) {
                        $p_key = self::getPhoneIndex($peer_mobile);
                        /** @var Extensions $exten_mobile */
                        $exten_mobile = Extensions::findFirst("number LIKE '%{$p_key}'");
                        $peer_mobile  = $exten_mobile !== null ? $exten_mobile->number : '';
                    }

                    // Подготавливаем ответ.
                    $data = [
                        'peer_mobile' => $peer_mobile,
                        'peer_number' => $peer_number,
                    ];
                    break;
                }
            }
        } else {
            $pbx_numbers = $this->getPbxNumbers();
            $this->inner_numbers  = [];
            $this->mobile_numbers = [];
            $this->b24Users = [];
            foreach ($users_list['result'] as $value) {
                $user                    = [];
                $user['NAME']            = '' . $value['NAME'] . ' ' . $value['LAST_NAME'];
                $user['ID']              = $value['ID'];
                $user['PERSONAL_MOBILE'] = preg_replace('/(\D)/', '', $value['PERSONAL_MOBILE']);
                $user['WORK_PHONE']      = preg_replace('/(\D)/', '', $value['WORK_PHONE']);
                $user['EMAIL']           = $value['EMAIL'];
                $user['UF_PHONE_INNER']  = preg_replace('/(\D)/', '', $value['UF_PHONE_INNER']);

                if (!empty($user['PERSONAL_MOBILE'])) {
                    $mobile_key                        = self::getPhoneIndex($user['PERSONAL_MOBILE']);
                    $this->mobile_numbers[$mobile_key] = $user;
                }
                if (!empty($value['WORK_PHONE'])) {
                    $mobile_key                        = self::getPhoneIndex($user['WORK_PHONE']);
                    $this->mobile_numbers[$mobile_key] = $user;
                }
                if(isset($pbx_numbers[$user['UF_PHONE_INNER']])){
                    $mobile_key                        = self::getPhoneIndex($pbx_numbers[$user['UF_PHONE_INNER']]);
                    $this->mobile_numbers[$mobile_key] = $user;
                }
                $this->inner_numbers[$user['UF_PHONE_INNER']] = $user;
                $this->b24Users[$value['ID']] = $user['UF_PHONE_INNER'];
            }

            $data = $this->mobile_numbers;

            $this->saveCache('inner_numbers', $this->inner_numbers);
            $this->saveCache('mobile_numbers', $this->mobile_numbers);
        }

        return $data;
    }

    private function getPbxNumbers():array
    {
        $pbx_numbers = $this->getCache('pbx_numbers');

        if($pbx_numbers){
            return $pbx_numbers;
        }
        $pbx_numbers = [];
        /** @var Manager $manager */
        $manager = $this->di->get('modelsManager');
        $parameters = [
            'models'     => [
                'ExtensionsSip' => Extensions::class,
            ],
            'conditions' => 'ExtensionsSip.type = :typeSip:',
            'bind'                => ['typeSip' => Extensions::TYPE_SIP,'typeExternal' => Extensions::TYPE_EXTERNAL],
            'columns'    => [
                'number'         => 'ExtensionsSip.number',
                'mobile'         => 'ExtensionsExternal.number',
                'userid'         => 'ExtensionsSip.userid',
            ],
            'order'      => 'number',
            'joins'      => [
                'ExtensionsExternal' => [
                    0 => Extensions::class,
                    1 => 'ExtensionsSip.userid = ExtensionsExternal.userid AND ExtensionsExternal.type = :typeExternal:',
                    2 => 'ExtensionsExternal',
                    3 => 'INNER',
                ],
            ],
        ];
        $query  = $manager->createBuilder($parameters)->getQuery();
        $result = $query->execute()->toArray();
        foreach ($result as $data){
            $pbx_numbers[$data['number']] = $data['mobile'];
        }
        $this->saveCache('pbx_numbers', $pbx_numbers, 60);
        return $pbx_numbers;
    }

    /**
     * Получение списка пользователей.
     * @param bool $fromCache
     * @return array
     */
    public function userGet(bool $fromCache = false): array
    {
        // Пробуем получить кэшированные записи
        if($this->mainProcess === false){
            $res_data = max($this->getCache(__FUNCTION__."_LONG"), $this->getCache(__FUNCTION__));
        }else{
            $res_data = $this->getCache(__FUNCTION__);
        }
        if ($fromCache === false && $res_data === null && !empty($this->portal)) {
            // Кэш не пуст / истекло время жизни.
            $url      = 'https://' . $this->portal . '/rest/user.get';

            $next   = 0;
            $params = [
                'auth'  => $this->getAccessToken(),
                'start' => $next,
                'FILTER' => ['ACTIVE' => true]
            ];
            // Выполняем первичный запрос. В нем станет ясно количество сотрудников.
            $data  = $this->query($url, $params);
            $total = $data['total'] ?? 0;
            // В одну выборку попадают максимум 50 сотрудников.
            $next = $data['next'] ?? $total;
            $step = 0;
            if (isset($data['result'])) {
                $res_data[] = $data['result'];
                // Размер шага определяем размером первой выборки.
                $step = count($data['result']);
            }
            $arg = [];
            while ($next < $total) {
                // Пользователей больше 50ти, формируем пакетный запрос к b24.
                $arg["userGet_$next"] = 'user.get?' . http_build_query(["start" => (string)$next, 'FILTER' => ['ACTIVE' => true]]);
                $next                 += $step;
            }
            // Пакет запросов сформирован, отправляем.
            $response = $this->sendBatch($arg);
            foreach ($arg as $key => $value) {
                $res = $response['result']['result'][$key] ?? false;
                if ($res) {
                    // Собираем результат в массив.
                    $res_data[] = $res;
                }
            }
            // Сохраняем в кэше
            $this->saveCache(__FUNCTION__, $res_data, 90);
            $this->saveCache(__FUNCTION__."_LONG", $res_data);
        }elseif ($res_data === null ){
            $res_data = [];
        }

        if (count($res_data) > 1) {
            $res_data = array_merge(... $res_data);
        } elseif (count($res_data) === 1) {
            $res_data = $res_data[0]??[];
        }

        // Объединяем массивы данных в один и возвращаем результат.
        return ['result' => $res_data];
    }

    /**
     * Возвращает усеценный слева номер телефона.
     *
     * @param $number
     *
     * @return bool|string
     */
    public static function getPhoneIndex($number)
    {
        $number = preg_replace('/\D+/', '', $number);
        if(!is_numeric(str_replace('+', '', $number))){
            return $number;
        }
        return substr($number, -10);
    }

    /**
     * Интеграция с API телефонии.
     */

    /**
     * Синхронизация внешних линий.
     */
    public function syncExternalLines(): array
    {
        $external_lines_keys = [];
        $local_lines_keys    = [];
        $resp_data           = $this->getExternalLines();
        foreach ($resp_data as $line_data) {
            $external_lines_keys[] = $line_data['NUMBER'];
        }

        $arg = [];
        // Обход локальных данных внешних линий MIKOPX
        $db_data = ConnectorDb::invoke(ConnectorDb::FUNC_GET_EXTERNAL_LINES, []);
        foreach ($db_data as $line) {
            $local_lines_keys[] = $line['number'];
            if (in_array($line['number'], $external_lines_keys, true)) {
                continue;
            }
            // Номера нет в Б24, добавим его.
            $params                                = [
                'NUMBER' => $line['number'],
                'NAME'   => '' // $line['number']
            ];
            $arg[uniqid('externalLine.add', true)] = 'telephony.externalLine.add?' . http_build_query($params);
        }

        // Обход сохраненных в b24 внешних линий.
        foreach ($external_lines_keys as $line) {
            if (in_array($line, $local_lines_keys, true)) {
                continue;
            }
            $params = [
                'NUMBER' => $line,
                'NAME'   => '',
            ];
            // Ключа нет на MikoPBX. Удалим его из Б24
            $arg[uniqid('externalLine.add', true)] = 'telephony.externalLine.delete?' . http_build_query($params);
        }
        if (!empty($arg)) {
            $this->sendBatch($arg);
            usleep(500000);
        }

        return $db_data;
    }

    /**
     * @return array|mixed
     */
    protected function getExternalLines()
    {
        $key      = uniqid('externalLine.get', true);
        $arg      = [$key => 'telephony.externalLine.get'];
        $response = $this->sendBatch($arg);
        usleep(500000);

        return $response['result']['result'][$key] ?? [];
    }

    /**
     * Удаление всех внешних линий на портале.
     */
    public function deleteExternalLines(): void
    {
        $arg       = [];
        $resp_data = $this->getExternalLines();
        foreach ($resp_data as $line_data) {
            $params = [
                'NUMBER' => $line_data['NUMBER'],
            ];
            $arg[uniqid('externalLine.delete', true)] = 'telephony.externalLine.delete?' . http_build_query($params);
        }
        $this->sendBatch($arg);
    }

    /**
     * Получение информации правам доступа для приложения.
     *
     * @return PBXApiResult An object containing the result of the API call.
     */
    public function getScope(): PBXApiResult
    {
        $res            = new PBXApiResult();
        $res->processor = __METHOD__;
        $access_token   = $this->getAccessToken();
        if (empty($access_token)) {
            $res->messages[] = Util::translate('mod_b24_i_AuthError');

            return $res;
        }
        $res->success = true;
        $res_data     = $this->getCache(__FUNCTION__);
        if ($res_data === null || isset($res_data['error'])) {
            $params   = ["auth" => $access_token];
            $url      = "https://" . $this->portal . "/rest/scope";
            $res_data = $this->query($url, $params);
            $this->saveCache(__FUNCTION__, $res_data, 60);
        }

        if (is_array($res_data) && isset($res_data['error'])) {
            $res->messages = $res_data;
            $res->success  = false;
        } else {
            $res = $this->checkScope($res_data);
        }

        return $res;
    }

    /**
     * Скрыть карточку звонка для пользователя.
     *
     * @param string $tubeName
     *
     * @return array
     */
    public function getScopeAsync(string $tubeName=''): array
    {
        $params = [
            'auth'    => $this->getAccessToken(),
        ];
        $arg                   = [];
        $arg['scope'.'_'.uniqid('', true)."_$tubeName"] = 'scope?' . http_build_query($params);
        return $arg;
    }

    private function checkScope($res_data):PBXApiResult
    {
        $result  = $res_data['result'] ?? [];
        $res            = new PBXApiResult();
        $res->success   = true;
        $needles = [['user','user_basic'], 'telephony', 'crm'];
        foreach ($needles as $needle) {
            if(is_array($needle)){
                $found = false;
                foreach ($needle as $value){
                    if(in_array($value, $result, true)) {
                        $found = true;
                        break;
                    }
                }
                if($found !== false){
                    continue;
                }
            }elseif(in_array($needle, $result, true)) {
                continue;
            }
            $res->success    = false;
            $res->messages[] = Util::translate('mod_b24_i_CheckPermissions').' ' . implode(
                    ',',
                    $result
                );
            break;
        }
        return $res;
    }

    /**
     * Регистрация звонка на портале b24.
     *
     * @param array $options
     *
     * @return array
     */
    public function telephonyExternalCallRegister(array $options): array
    {
        // Сформируем кэш, чтобы исключить дублирующие события.
        $cache_key = "tmp180_call_register_{$options['USER_ID']}_" .
            strtotime($options['CALL_START_DATE']) . "_" .
            self::getPhoneIndex($options['PHONE_NUMBER']). "_" .
            self::getPhoneIndex($options['USER_PHONE_INNER']);

        $id = $options['linkedid']??'';
        $res_data = $this->getCache($cache_key);
        if ($res_data) {
            $this->mainLogger->writeInfo("Igonre $id 'telephonyExternalCallRegister' id dublicate...");
            return [];
        }
        // Сохраним кэш.
        $this->saveCache($cache_key, '1', 180);

        $show_card = '0';
        $userNumber = $this->b24Users[$options['USER_ID']]??'';
        if(empty($userNumber)){
            $userNumber = $options['USER_PHONE_INNER'];
        }
        $cardOpenSetting = $this->usersSettingsB24[$userNumber]['open_card_mode']??'';
        if ($cardOpenSetting === self::OPEN_CARD_DIRECTLY || $cardOpenSetting === '') {
            $show_card = '1';
        }
        $params = [
            'USER_PHONE_INNER' => '', // Внутренний номер пользователя
            'USER_ID'          => '', // Идентификатор пользователя
            'PHONE_NUMBER'     => '', // Номер телефона собеседника.
            'CALL_START_DATE'  => '', // Дата/время звонка в формате iso8601.
            'CRM_CREATE'       => '1',// Создавать или нет сущность CRMесли номер не найден
            'CRM_SOURCE'       => '', // STATUS_ID источника из справочника источников.
            'CRM_ENTITY_ID'    => '',
            'CRM_ENTITY_TYPE'  => '',
            'SHOW'             => $show_card,// [0/1] Показывать ли карточку звонка?
            'TYPE'             => '1', // 1 - исходящий 2 - входящий 3 - входящий с перенаправлением  4 - обратный
            'LINE_NUMBER'      => '',  // Номер внешней линии, через который совершался звонок
            'CALL_LIST_ID'     => '',  // Идентификатор списка обзвона, к которому должен быть привязан звонок.
            'auth'             => $this->getAccessToken(),
        ];

        $this->fillPropertyValues($options, $params);

        $arg       = [];
        $key       = self::API_CALL_REGISTER.'_'.$id . uniqid('', true);
        $arg[$key] = self::API_CALL_REGISTER.'?' . http_build_query($params);

        $options['time']       = time();
        $this->mem_cache[$key] = $options;

        return $arg;
    }

    /**
     * Запись в базу данных идентификаторов звонка.
     *
     * @param $key
     * @param $response
     */
    public function telephonyExternalCallPostRegister($key, $response): void
    {
        $request = $this->mem_cache[$key] ?? null;
        if ( ! $request) {
            return;
        }
        unset($this->mem_cache[$key]);
        ConnectorDb::invoke(ConnectorDb::FUNC_UPDATE_CDR_BY_UID, [$request['UNIQUEID'], $request, $response]);
    }

    /**
     * @param       $options
     * @param array $params
     */
    protected function fillPropertyValues($options, array &$params): void
    {
        foreach ($options as $key => $value) {
            if (array_key_exists($key, $params)) {
                $params[$key] = $value;
            }
        }
        $del_keys = [];
        foreach ($params as $key => $value) {
            if ($value === '') {
                $del_keys[] = $key;
            }
        }
        foreach ($del_keys as $key) {
            // Не будем отправлять пустые данные.
            unset($params[$key]);
        }
    }

    /**
     * Регистрация факта завершения звонка в b24.
     *
     * @param $options
     *
     * @return array|null
     */
    public function telephonyExternalCallFinish($options): array
    {
        [$CALL_DATA, $CALL_ID] = ConnectorDb::invoke(ConnectorDb::FUNC_GET_CDR_BY_LINKED_ID, [$options]);
        if (empty($CALL_ID)) {
            return [];
        }
        $userId = ($CALL_DATA['answer'] === '1') ? $CALL_DATA['user_id'] : '';
        $params = [
            'CALL_ID'       => $CALL_ID,
            'USER_ID'       => $userId,
            'DURATION'      => '',
            'COST'          => '', // Стоимость звонка.
            'COST_CURRENCY' => '', // Валюта, в которой указана стоимость звонка.
            'STATUS_CODE'   => '', // SIP-код статуса звонка.
            'FAILED_REASON' => '', // Причина несостоявшегося звонка.
            'VOTE'          => '', // Оценка звонка пользователем (если АТС поддерживает функционал оценки разговора).
            'ADD_TO_CHAT'   => 0,
            'auth'          => $this->getAccessToken(),
        ];

        $this->fillPropertyValues($options, $params);

        $arg              = [];
        $finishKey       = self::API_CALL_FINISH.'_'.$options['linkedid'].'_' . uniqid('', true);
        $arg[$finishKey] = self::API_CALL_FINISH.'?' . http_build_query($params);
        if ($options['export_records']) {
            $this->telephonyExternalCallAttachRecord($options['FILE'], $CALL_ID, $arg);
        }

        if ($options['GLOBAL_STATUS'] === 'ANSWERED' && $options['disposition'] !== 'ANSWERED') {
            $this->mem_cache[$finishKey] = $CALL_DATA;
        }
        if ($options['GLOBAL_STATUS'] !== 'ANSWERED') {
            $this->mem_cache["$finishKey-missed"]   = $CALL_DATA;
        }
        if ($options['GLOBAL_STATUS'] === 'ANSWERED' && $options['disposition'] === 'ANSWERED') {
            $this->mem_cache["$finishKey-answered"] = $CALL_DATA;
        }
        return $arg;
    }

    /**
     * Обработка действий после получения ответа на telephonyExternalCallFinish.
     * @param string $finishKey
     * @param array  $response
     * @param array  $queryArray
     */
    public function telephonyExternalCallPostFinish(string $finishKey, array $response, array &$queryArray): void{
        $cache_data = $this->getMemCache($finishKey);
        $activityId = $response['CRM_ACTIVITY_ID']??'';
        if($cache_data && !empty($activityId)){
            // Постановка задачи на удаление activity.
            $queryArray[] = $this->crmActivityDelete($activityId);
        }
        $cacheData = $this->getMemCache("$finishKey-answered");
        if($cacheData === null){
            $cacheData = $this->getMemCache("$finishKey-missed");
        }
        $id = str_replace('telephony.externalcall.finish', '', $finishKey);

        if(isset($cacheData['contact_id'])){
            $queryArray[] = $this->crmContactUpdate($cacheData['contact_id'], $response['PORTAL_USER_ID'], $id);
        }
        if(isset($cacheData['deal_id'])){
            $queryArray[] = $this->crmDealUpdate($cacheData['deal_id'], $response['PORTAL_USER_ID'], $id);
        }
        if(isset($cacheData['lead_id'])){
            $queryArray[] = $this->crmLeadUpdate($cacheData['lead_id'], $response['PORTAL_USER_ID'], $id);
        }
    }

    /**
     * Обновление пользователя для Lead,
     * @param string $id
     * @param string $newUserId
     * @param string $linkedId
     * @return array
     */
    public function crmDealUpdate(string $id, string $newUserId, string $linkedId = ''): array
    {
        $params = [
            'id'   => $id,
            'fields' => [
                'ASSIGNED_BY_ID' => $newUserId
            ],
            'auth' => $this->getAccessToken(),
            'params' => [
                'REGISTER_SONET_EVENT' => 'N'
            ]
        ];
        $arg = [];
        $arg['crm.deal.update_'.$linkedId.'_' . uniqid('', true)] = 'crm.deal.update?' . http_build_query($params);
        return $arg;
    }

    /**
     * Обновление пользователя для Lead,
     * @param string $phone
     * @param string $id
     * @param string $user
     * @param string $did
     * @return array
     */
    public function crmAddLead(string $phone, string $id = '', string  $user = '', string $did = ''): array
    {
        $params = [
            'fields' => [
                'TITLE' => "$phone - Входящий звонок | $did",
                'OPENED' => "Y",
                "STATUS_ID" => "NEW",
                "ASSIGNED_BY_ID" => $user,
                "PHONE"=> [ [ "VALUE"=> $phone, "VALUE_TYPE"=> "WORK" ] ]
            ],
            'auth' => $this->getAccessToken(),
            'params' => [
                'REGISTER_SONET_EVENT' => 'N'
            ]
        ];
        $arg = [];
        if(empty($id)){
            $id = self::API_CRM_ADD_LEAD.'_' . uniqid('', true);
        }else{
            $id = self::API_CRM_ADD_LEAD.'_' . $id;
        }
        $arg[$id] = self::API_CRM_ADD_LEAD.'?' . http_build_query($params);
        return $arg;
    }

    /**
     * Запрос синхронизации справочников.
     * @param string $type
     * @param array $arIds
     * @return array
     */
    public function crmListEnt(string $type, array $arIds = []): array
    {
        $id = '0';
        $select = [ "ID", "ASSIGNED_BY_ID", "PHONE"];
        if(self::API_CRM_LIST_CONTACT === $type){
            $id = $this->lastContactId;
            $select = [ "ID", "NAME", "LAST_NAME", "SECOND_NAME", "COMPANY_ID",  "ASSIGNED_BY_ID", "PHONE", "LEAD_ID", "DATE_CREATE", "DATE_MODIFY"];
        }elseif (self::API_CRM_LIST_COMPANY === $type){
            $id = $this->lastCompanyId;
            $select = [ "ID", "TITLE",  "ASSIGNED_BY_ID", "PHONE", "LEAD_ID", "DATE_CREATE", "DATE_MODIFY"];
        }elseif (self::API_CRM_LIST_LEAD === $type){
            $id = $this->lastLeadId;
            $select = [ "ID", "TITLE",  "ASSIGNED_BY_ID", "PHONE", "CONTACT_ID", "COMPANY_ID", "STATUS_ID", "STATUS_SEMANTIC_ID", "DATE_CREATE", "DATE_MODIFY"];
        }
        if(empty($arIds)){
            $filter = ['>ID' => $id];
            $keyId = $type . '_init';
        }else{
            $filter = ['ID' => $arIds];
            $keyId = $type . '_update_'.uniqid('', true);
        }
        $type = strtolower($type);
        $params = [
            'order'  => ["ID" =>  "ASC"],
            'filter' => $filter,
            'start'  => '-1',
            'select' => $select,
            'auth'   => $this->getAccessToken(),
        ];
        $arg = [];
        $arg[$keyId] = $type.'?' . http_build_query($params);
        return $arg;
    }

    /**
     * Сохранение данных контактов.
     * @param $action
     * @param $keyId
     * @param $data
     * @return void
     */
    public function crmListEntResults($action, $keyId, $data):void
    {
        if(empty($data)){
            return;
        }
        $settings = ConnectorDb::invoke(ConnectorDb::FUNC_UPDATE_ENT_CONTACT, [$action, $data]);
        if($keyId === 'update' || empty($settings)){
            return;
        }
        $settings = (object)$settings;
        $this->lastContactId = empty($settings->lastContactId)?"0":$settings->lastContactId;
        $this->lastCompanyId = empty($settings->lastCompanyId)?"0":$settings->lastCompanyId;
        $this->lastLeadId    = empty($settings->lastLeadId)?"0":$settings->lastLeadId;
    }

    /**
     * Обновление пользователя для Lead,
     * @param string $id
     * @param string $newUserId
     * @param string $linkedId
     * @return array
     */
    public function crmContactUpdate(string $id, string $newUserId, string $linkedId = ''): array
    {
        $params = [
            'id'   => $id,
            'fields' => [
                'ASSIGNED_BY_ID' => $newUserId,
            ],
            'auth' => $this->getAccessToken(),
            'params' => [
                'REGISTER_SONET_EVENT' => 'N'
            ]
        ];
        $arg = [];
        $arg['crm.contact.update_'.$linkedId . '_' . uniqid('', true)] = 'crm.contact.update?' . http_build_query($params);
        return $arg;
    }

    /**
     * Обновление пользователя для Lead,
     * @param string $id
     * @param string $newUserId
     * @param string $linkedId
     * @return array
     */
    public function crmLeadUpdate(string $id, string $newUserId, string $linkedId = ''): array
    {
        $params = [
            'id'   => $id,
            'fields' => [
                'ASSIGNED_BY_ID' => $newUserId
            ],
            'auth' => $this->getAccessToken(),
            'params' => [
                'REGISTER_SONET_EVENT' => 'N'
            ]
        ];

        $arg                                        = [];
        $arg['crm.lead.update_'.$linkedId.'_'. uniqid('', true)] = 'crm.lead.update?' . http_build_query($params);

        return $arg;
    }

    /**
     * Обработка ответа запроса загрузки файла.
     * @param $key
     * @param $uploadUrl
     * @return array
     */
    public function telephonyPostAttachRecord($key, $uploadUrl): array
    {
        $data = $this->getMemCache($key);
        if(empty($uploadUrl) || empty($data)){
            return [];
        }
        $data['uploadUrl'] = $uploadUrl;
        return $data;
    }

    /**
     * Прикрепление записи разговора к звонку в b24.
     * @param $filename
     * @param $callId
     * @param $arg
     * @return void
     */
    private function telephonyExternalCallAttachRecord($filename, $callId, &$arg): void
    {
        if (!file_exists($filename)) {
            return;
        }
        $FILENAME     = basename($filename);
        $params = [
            'CALL_ID'      => $callId,
            'FILENAME'     => $FILENAME,
            'auth'         => $this->getAccessToken(),
        ];
        if(!$this->backgroundUpload){
            $params['FILE_CONTENT'] = base64_encode(file_get_contents($filename));
        }
        $cmd = self::API_ATTACH_RECORD.'?'.http_build_query($params);
        $key = self::API_ATTACH_RECORD.'_'.uniqid('', true);
        $arg[$key] = $cmd;
        $this->mem_cache[$key] = [
            'CALL_ID'      => $callId,
            'FILENAME'     => $filename,
        ];
    }

    /**
     * Скрыть карточку звонка для пользователя.
     *
     * @param $options
     *
     * @return array
     */
    public function telephonyExternalCallHide($options): array
    {
        $params = [
            'CALL_ID' => '',
            'USER_ID' => '',
            'auth'    => $this->getAccessToken(),
        ];
        $this->fillPropertyValues($options, $params);

        $arg                   = [];
        $arg[self::API_CALL_HIDE.'_'.$options['linkedid'].'_'.uniqid('', true)] = self::API_CALL_HIDE.'?' . http_build_query($params);

        return $arg;
    }

    /**
     * Показать карточку звонка для пользователя.
     *
     * @param $options
     *
     * @return array
     */
    public function telephonyExternalCallShow($options): array
    {
        $params = [
            'CALL_ID' => '',
            'USER_ID' => '',
            'auth'    => $this->getAccessToken(),
        ];
        $this->fillPropertyValues($options, $params);

        $arg                   = [];
        $arg[self::API_CALL_SHOW.$options['linkedid'].'_'.uniqid('', true)] = self::API_CALL_SHOW.'?' . http_build_query($params);

        return $arg;
    }

    /**
     * Удаление Дела по ID
     *
     * @param      $id
     *
     * @return array
     */
    public function crmActivityDelete($id): array
    {
        $params = [
            'ID'   => $id,
            'auth' => $this->getAccessToken(),
        ];

        $arg                                        = [];
        $arg['activity.delete_' . uniqid('', true)] = 'crm.activity.delete?' . http_build_query($params);

        return $arg;
    }

    /**
     */
    public function startAllServices(): void
    {
        // Сервисы будут запущены по cron в течение минуты.
    }

    public function testUpdateToken($token):void
    {
        $this->updateToken($token);
    }
}
