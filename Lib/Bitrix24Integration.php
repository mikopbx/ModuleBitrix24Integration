<?php
/*
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 10 2020
 */

namespace Modules\ModuleBitrix24Integration\Lib;

use Phalcon\Mvc\Model\Manager;
use MikoPBX\Common\Models\CallQueues;
use MikoPBX\Common\Models\IncomingRoutingTable;
use MikoPBX\Common\Models\Extensions;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Modules\Logger;
use MikoPBX\Modules\PbxExtensionBase;
use MikoPBX\Modules\PbxExtensionUtils;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleBitrix24Integration\Models\{ModuleBitrix24ExternalLines,
    ModuleBitrix24Integration,
    ModuleBitrix24CDR,
    ModuleBitrix24Users
};
use MikoPBX\Core\System\Util;
use Modules\ModuleBitrix24Integration\bin\WorkerBitrix24IntegrationHTTP;


class Bitrix24Integration extends PbxExtensionBase
{
    public const API_LEAD_TYPE_ALL   = 'all';
    public const API_LEAD_TYPE_IN    = 'incoming';
    public const API_LEAD_TYPE_OUT   = 'outgoing';

    public const API_ATTACH_RECORD   = 'telephony.externalCall.attachRecord';
    public const API_SEARCH_ENTITIES = 'telephony.externalCall.searchCrmEntities';
    public const API_CALL_FINISH     = 'telephony.externalcall.finish';
    public const API_CALL_HIDE       = 'telephony.externalcall.hide';
    public const API_CALL_REGISTER   = 'telephony.externalcall.register';
    public const API_CRM_ADD_LEAD    = 'crm.lead.add';
    public const API_CRM_ADD_CONTACT = 'crm.contact.add';
    public const API_CRM_LIST_LEAD   = 'crm.lead.list';

    public const B24_INTEGRATION_CHANNEL = 'b24_integration_channel';
    public const B24_SEARCH_CHANNEL = 'b24_search_channel';

    public array $inner_numbers;
    public array $mobile_numbers;
    private $SESSION;
    private array $disabled_numbers;
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
    private Logger $requestLogger;
    private bool $mainProcess = false;
    private int $updateTokenTime;

    public function __construct()
    {
        parent::__construct();
        if(php_sapi_name() === "cli"){
            $this->mainProcess     = cli_get_process_title() === WorkerBitrix24IntegrationHTTP::class;
        }
        $this->updateTokenTime = $this->mainProcess?300:150;

        $this->mem_cache = [];
        $data            = ModuleBitrix24Integration::findFirst();
        if ($data === null
            || empty($data->portal)
            || empty($data->b24_region)) {
            $this->logger->writeError('Settings not set...');
            return;
        }

        $this->SESSION          = empty($data->session) ? null : json_decode($data->session, true);
        if(empty($data->refresh_token) && $this->SESSION ){
            $data->refresh_token = $this->SESSION['refresh_token']??'';
        }

        if(empty($data->refresh_token)){
            $this->logger->writeError('refresh_token not set...');
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
            $settings = ModuleBitrix24Integration::findFirst();
        }
        if($settings === null){
            return;
        }
        $this->disabled_numbers = $this->getDisabledNumbers();
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
    private function getDisabledNumbers(): array
    {
        // Мы не можем использовать JOIN в разных базах данных
        $parameters            = [
            'conditions' => 'disabled = 1',
            'columns'    => ['user_id'],
        ];
        $disabledBitrix24Users = ModuleBitrix24Users::find($parameters)->toArray();
        if (count($disabledBitrix24Users) === 0) {
            return [];
        }
        $parameters = [
            'conditions' => 'is_general_user_number = 1 AND type = "SIP" AND userid IN ({ids:array})',
            'columns'    => ['number'],
            'bind'       => [
                'ids' => array_column($disabledBitrix24Users, 'user_id'),
            ],
        ];

        $disabledExtensions = Extensions::find($parameters)->toArray();
        $result             = array_merge_recursive(...$disabledExtensions)['number'] ?? [];
        if ( ! is_array($result)) {
            $result = [$result];
        }

        return $result;
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

        if (empty($s_refresh_token) || empty($s_access_token)) {
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
        $result = false;
        if(!$this->mainProcess && !$refresh_token){
            // Только один процесс может обновлять информацию по токену.
            $data = ModuleBitrix24Integration::findFirst();
            if($data){
                $this->SESSION          = empty($data->session) ? null : json_decode($data->session, true);
                $this->refresh_token    = ''.$data->refresh_token;
                $result = true;
            }
            unset($data);
            return $result;
        }
        if ($refresh_token) {
            $this->SESSION                  = [];
            $this->SESSION["refresh_token"] = $refresh_token;
        }
        if ( ! isset($this->SESSION["refresh_token"])) {
            return false;
        }
        $oAuthToken = ModuleBitrix24Integration::getAvailableRegions()[$this->b24_region];
        if(empty($oAuthToken['CLIENT_ID'])){
            $oAuthToken['CLIENT_ID']     = $this->client_id;
            $oAuthToken['CLIENT_SECRET'] = $this->client_secret;
        }
        $params     = [
            "grant_type"    => "refresh_token",
            "client_id"     => $oAuthToken['CLIENT_ID'],
            "client_secret" => $oAuthToken['CLIENT_SECRET'],
            "refresh_token" => $this->SESSION["refresh_token"],
        ];
        $query_data = $this->query("https://oauth.bitrix.info/oauth/token/", $params);
        if( ($query_data['error']??'') === 'invalid_grant' ){
            // Не корректно выбран регион.
            $oAuthToken = ModuleBitrix24Integration::getAvailableRegions()['WORLD'];
            $params['client_id']     = $oAuthToken['CLIENT_ID'];
            $params['client_secret'] = $oAuthToken['CLIENT_SECRET'];
            $query_data = $this->query("https://oauth.bitrix.info/oauth/token/", $params);
        }

        if (isset($query_data["access_token"])) {
            $result = true;
            $this->updateSessionData($query_data);
        } else {
            $this->logger->writeError('Refresh token: '.json_encode($query_data));
        }

        return $result;
    }

    /**
     * oAuth2 аутентификация по code.
     * @param $code
     * @return bool
     */
    public function authByCode($code, $region): bool
    {
        $result = false;
        $oAuthToken = ModuleBitrix24Integration::getAvailableRegions()[$region];
        $params     = [
            "grant_type"    => "authorization_code",
            "client_id"     => $oAuthToken['CLIENT_ID'],
            "client_secret" => $oAuthToken['CLIENT_SECRET'],
            "code"          => $code,
        ];
        $query_data = $this->query("https://oauth.bitrix.info/oauth/token/", $params);
        if( ($query_data['error']??'') === 'invalid_grant' ){
            // Не корректно выбран регион.
            $oAuthToken = ModuleBitrix24Integration::getAvailableRegions()['WORLD'];
            $params['client_id']     = $oAuthToken['CLIENT_ID'];
            $params['client_secret'] = $oAuthToken['CLIENT_SECRET'];
            $query_data = $this->query("https://oauth.bitrix.info/oauth/token/", $params);
        }

        if (isset($query_data["access_token"])) {
            $result = true;
            $this->updateSessionData($query_data);
        } else {
            $this->logger->writeError('Refresh token: '.json_encode($query_data));
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
            $expires = $this->SESSION["expires"]??false;
            if($expires && $expires - time() < $this->updateTokenTime){
                // Запрос обновленного token.
                $this->updateToken();
                // Только главный процесс может обновлять токен.
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
                $this->logger->writeInfo('Too many requests. Sleeping 1s ... ');
                sleep(1);
                $response   = $this->execCurl($url, $curlOptions, $status, $headersResponse, $totalTime);
                $error_name = $response['error'] ?? '';
            }
            if ( ! empty($error_name)) {
                $this->logger->writeError('Fail REST response ' . json_encode($response));
            }
        }

        $this->checkErrorInResponse($response, $status);
        $delta = microtime(true) - $startTime;
        $this->logRequestData($url, $data, $response, $delta);
        if ($delta > 2 || $totalTime > 2) {
            $this->logger->writeError(
                "Slow response. PHP time:{$delta}s, cURL time: {$totalTime}, url:{$url}, Data:$q4Dump, Response: " . json_encode(
                    $response
                )
            );
        }

        return $response;
    }

    /**
     * Запись данных запроса в файловый лог.
     *
     * @param $url
     * @param $data
     * @param $response
     * @param $delta
     * @return void
     */
    private function logRequestData($url, $data, $response, $delta):void
    {
        if(!file_exists('/tmp/b24-req-debug')){
            return;
        }
        if(isset($this->requestLogger) && isset($data['cmd']) ){
            $logData = [
                'url' => $url,
                'data' => [],
                'delta' => $delta
            ];

            foreach ($data['cmd'] as $reqName => $reqData){
                $logData['data'][$reqName] = [
                    'request' => $reqData,
                    'response'=> $response['result']['result'][$reqName]??[],
                ];
            }

            $eventOffline = $logData['data']['event.offline.get']??[];
            if(!empty($eventOffline)
                && empty($eventOffline["response"]["events"][0]["MESSAGE_ID"]??'') && count($logData['data']) === 1){
                return;
            }
            $this->requestLogger->writeInfo(json_encode($logData, JSON_UNESCAPED_SLASHES));
        }
    }

    private function checkErrorInResponse(&$response, $status)
    {
        if ( ! is_array($response)) {
            if ($status === 0) {
                usleep(500000);
                $key        = "http_query_status_{$status}";
                $cache_data = $this->getCache($key);
                if (empty($cache_data)) {
                    $this->logger->writeError("Fail HTTP. Status: {$status}. Can not connect to host.");
                    $this->saveCache($key, '1', 60);
                }
            } else {
                $this->logger->writeError("Fail HTTP. Status: {$status}. Response: $response");
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
     * @param int $time
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
        return $this->di->getManagedCache()->get($cacheKey);
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
        $managedCache = $this->di->getManagedCache();
        $managedCache->set($cacheKey, $resData, $ttl);
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
        $data = ModuleBitrix24Integration::findFirst();
        if ($data === null) {
            return;
        }
        $data->session = json_encode($this->SESSION, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $data->save();
    }

    /**
     * Подписаться на события.
     *
     * @return bool
     */
    public function eventsBind(): bool
    {
        $eventd_res = $this->eventGet();

        usleep(500000);
        $binds  = $eventd_res['result']['result']['event.get'] ?? [];
        $events = [];
        foreach ($binds as $bind) {
            $events[] = $bind['event'];
        }
        $arg = [];
        if ( ! in_array('ONEXTERNALCALLSTART', $events, true)) {
            $paramsCall                 = [
                "event_type" => 'offline',
                "event"      => 'OnExternalCallStart',
                "auth"       => $this->getAccessToken(),
            ];
            $arg["OnExternalCallStart"] = 'event.bind?' . http_build_query($paramsCall);
        }
        if ( ! in_array('ONEXTERNALCALLBACKSTART', $events, true)) {
            $paramsCallBack                 = [
                "event_type" => 'offline',
                "event"      => 'OnExternalCallBackStart',
                "auth"       => $this->getAccessToken(),
            ];
            $arg["OnExternalCallBackStart"] = 'event.bind?' . http_build_query($paramsCallBack);
        }

        if (!empty($arg)) {
            $this->logger->writeInfo('Update event binding...');
            $arg      = array_merge($arg, $this->eventOfflineGet());
            $response = $this->sendBatch($arg);
            $result   = ! isset($response['result']['result_error']);
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
     * @throws \Exception
     */
    public function handleEvent($req_data): array
    {
        $result = [
            'result' => 'ERROR',
            'data'   => $req_data,
        ];
        if ('ONEXTERNALCALLSTART' === $req_data['event']) {
            $FROM_USER_ID = $req_data['data']['USER_ID'];
            $dst          = $req_data['data']['PHONE_NUMBER'];

            // Повторный вызов на тот же номер возможне только через N секунд.
            $cache_key = 'tmp5_' . __FUNCTION__ . "_{$FROM_USER_ID}_{$dst}";
            $res_data  = $this->getCache($cache_key);
            if ($res_data !== null) {
                $this->logger->writeInfo("Repeated calls to the number {$dst} are possible in N seconds ");

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
                $pre_call_key = "tmp5_{$phone_data['peer_number']}_" . $this->getPhoneIndex(
                        $req_data['data']['PHONE_NUMBER']
                    );
                $this->saveCache($pre_call_key, $data, 5);

                $dst = preg_replace("/[^0-9+]/", '', $dst);
                Util::amiOriginate($phone_data['peer_number'], '', $dst);
                $this->logger->writeInfo(
                    "ONEXTERNALCALLSTART: originate from user {$FROM_USER_ID} <{$phone_data['peer_number']}> to {$dst})"
                );
            }
        } elseif ('ONEXTERNALCALLBACKSTART' === $req_data['event']) {
            $PHONE_NUMBER = preg_replace("/[^0-9+]/", '', urldecode($req_data['data']['PHONE_NUMBER']));
            $data         = [
                'CRM_ENTITY_TYPE' => $req_data['data']['CRM_ENTITY_TYPE'],
                'CRM_ENTITY_ID'   => $req_data['data']['CRM_ENTITY_ID'],
                'PHONE_NUMBER'    => $PHONE_NUMBER,
            ];
            $pre_call_key = "tmp5_ONEXTERNALCALLBACKSTART_" . $this->getPhoneIndex($PHONE_NUMBER);
            $this->saveCache($pre_call_key, $data, 5);

            if(empty($this->queueUid)){
                $this->logger->writeInfo("ONEXTERNALCALLBACKSTART: default action for incoming rout is not queue)");
            }else{
                $channel        = "Local/{$this->queueExtension}@internal-originate";
                $variable       = "pt1c_cid={$PHONE_NUMBER},SRC_QUEUE={$this->queueUid}";
                $am       = Util::getAstManager('off');
                $am->Originate($channel, $PHONE_NUMBER, 'all_peers', '1', null, null, null, null, $variable, null, true);
                $this->logger->writeInfo("ONEXTERNALCALLBACKSTART: originate from queue {$data['extension']} to {$PHONE_NUMBER})");
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
                        $p_key = $this->getPhoneIndex($peer_mobile);
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
            foreach ($users_list['result'] as $value) {
                $user                    = [];
                $user['NAME']            = '' . $value['NAME'] . ' ' . $value['LAST_NAME'];
                $user['ID']              = $value['ID'];
                $user['PERSONAL_MOBILE'] = preg_replace('/(\D)/', '', $value['PERSONAL_MOBILE']);
                $user['EMAIL']           = $value['EMAIL'];
                $user['UF_PHONE_INNER']  = preg_replace('/(\D)/', '', $value['UF_PHONE_INNER']);

                if (!empty($user['PERSONAL_MOBILE'])) {
                    $mobile_key                        = $this->getPhoneIndex($user['PERSONAL_MOBILE']);
                    $this->mobile_numbers[$mobile_key] = $user;
                }
                if (!empty($value['WORK_PHONE'])) {
                    $mobile_key                        = $this->getPhoneIndex($user['WORK_PHONE']);
                    $this->mobile_numbers[$mobile_key] = $user;
                }
                if(isset($pbx_numbers[$user['UF_PHONE_INNER']])){
                    $mobile_key                        = $this->getPhoneIndex($pbx_numbers[$user['UF_PHONE_INNER']]);
                    $this->mobile_numbers[$mobile_key] = $user;
                }
                $this->inner_numbers[$user['UF_PHONE_INNER']] = $user;
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
     *
     * @return array
     */
    public function userGet(): array
    {
        // Пробуем получить закэшированные записи
        $res_data = $this->getCache(__FUNCTION__);
        if (is_array($res_data) && count($res_data) === 0) {
            $res_data = $this->getCache(__FUNCTION__);
        }
        if ($res_data === null) {
            // Кэш не =уществует / истекло время жизни.
            $res_data = [];
            $url      = 'https://' . $this->portal . '/rest/user.get';

            $next   = 0;
            $params = [
                'auth'  => $this->getAccessToken(),
                'start' => $next,
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
                $arg["userGet_$next"] = 'user.get?' . http_build_query(["start" => "$next"]);
                $next                 += $step;
            }
            if (count($arg) > 0) {
                // Пакет запросов сформирован, отправляем.
                $response = $this->sendBatch($arg);
                foreach ($arg as $key => $value) {
                    $res = $response['result']['result'][$key] ?? false;
                    if ($res) {
                        // Собираем результат в массив.
                        $res_data[] = $res;
                    }
                }
            }
            // Сохраняем их в кэше
            $this->saveCache(__FUNCTION__, $res_data, 90);
        }

        if (count($res_data) > 1) {
            $res_data = array_merge(... $res_data);
        } elseif (count($res_data) === 1) {
            $res_data = $res_data[0];
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
    public function getPhoneIndex($number)
    {
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
        $db_data = ModuleBitrix24ExternalLines::find()->toArray();
        foreach ($db_data as $line) {
            $local_lines_keys[] = $line['number'];
            if (in_array($line['number'], $external_lines_keys)) {
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
            if (in_array($line, $local_lines_keys)) {
                continue;
            }
            $params = [
                'NUMBER' => $line,
                'NAME'   => '',
            ];
            // Ключа нет на MIKOPBX. Удалим его из Б24
            $arg[uniqid('externalLine.add', true)] = 'telephony.externalLine.delete?' . http_build_query($params);
        }
        if (count($arg) > 0) {
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
            $external_lines_keys[]                    = $line_data['NUMBER'];
            $params                                   = [
                'NUMBER' => $line_data['NUMBER'],
            ];
            $arg[uniqid('externalLine.delete', true)] = 'telephony.externalLine.delete?' . http_build_query($params);
        }
        $this->sendBatch($arg);
    }

    /**
     * Получение информации правам доступа для приложения.
     *
     * @return PBXApiResult
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
            $this->getPhoneIndex($options['PHONE_NUMBER']). "_" .
            $this->getPhoneIndex($options['USER_PHONE_INNER']);

        $res_data = $this->getCache($cache_key);
        if ($res_data) {
            return [];
        }
        // Сохраним кэш.
        $this->saveCache($cache_key, '1', 180);

        $show_card = '1';
        if (in_array($options['USER_PHONE_INNER'], $this->disabled_numbers, true)) {
            $show_card = '0';
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
        $key       = self::API_CALL_REGISTER.'_' . uniqid('', true);
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
        $res = ModuleBitrix24CDR::findFirst("uniq_id='{$request['UNIQUEID']}'");
        if ($res === null && isset($response['CALL_ID'])) {
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
            $res->save();
        }
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
        $CALL_DATA = $this->getCallDataByUniqueId($options['UNIQUEID']);
        $CALL_ID   = $this->getCallIdByUniqueId($options['UNIQUEID']);
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
        $finishKey       = self::API_CALL_FINISH.'_' . uniqid('', true);
        $arg[$finishKey] = self::API_CALL_FINISH.'?' . http_build_query($params);
        if ($options['export_records']) {
            $this->telephonyExternalCallAttachRecord($options['FILE'], $CALL_ID, $arg);
        }

        if ($options['GLOBAL_STATUS'] === 'ANSWERED' && $options['disposition'] !== 'ANSWERED') {
            $this->mem_cache[$finishKey] = $CALL_DATA;
        }
        if ($options['GLOBAL_STATUS'] !== 'ANSWERED') {
            $this->mem_cache["{$finishKey}-missed"]   = $CALL_DATA;
        }
        if ($options['GLOBAL_STATUS'] === 'ANSWERED' && $options['disposition'] === 'ANSWERED') {
            $this->mem_cache["{$finishKey}-answered"] = $CALL_DATA;
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
        $cacheData = $this->getMemCache("{$finishKey}-answered");
        if($cacheData === null){
            $cacheData = $this->getMemCache("{$finishKey}-missed");
        }
        if(isset($cacheData['contact_id'])){
            $queryArray[] = $this->crmContactUpdate($cacheData['contact_id'], $response['PORTAL_USER_ID']);
        }
        if(isset($cacheData['deal_id'])){
            $queryArray[] = $this->crmDealUpdate($cacheData['deal_id'], $response['PORTAL_USER_ID']);
        }
        if(isset($cacheData['lead_id'])){
            $queryArray[] = $this->crmLeadUpdate($cacheData['lead_id'], $response['PORTAL_USER_ID']);
        }
    }

    /**
     * Обновление пользователя для Lead,
     * @param string $id
     * @param string $newUserId
     * @return array
     */
    public function crmDealUpdate(string $id, string $newUserId): array
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
        $arg['crm.deal.update_' . uniqid('', true)] = 'crm.deal.update?' . http_build_query($params);
        return $arg;
    }

    /**
     * Обновление пользователя для Lead,
     * @param string $phone
     * @param string $id
     * @param string $user
     * @return array
     */
    public function crmAddLead(string $phone, string $id = '', string  $user = ''): array
    {
        $params = [
            'fields' => [
                'TITLE' => $phone.' - Входящий звонок',
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
            $id = 'crm.lead.add_' . uniqid('', true);
        }else{
            $id = 'crm.lead.add_' . $id;
        }
        $arg[$id] = 'crm.lead.add?' . http_build_query($params);
        return $arg;
    }

    /**
     * Обновление пользователя для Lead,
     * @param string $phone
     * @param string $id
     * @param string $user
     * @return array
     */
    public function crmAddContact(string $phone, string $id = '', string  $user = ''): array
    {
        $params = [
            'fields' => [
                'NAME' => $phone,
                'OPENED' => "Y",
                "SOURCE_ID" => "SELF",
                "TYPE_ID" => "CLIENT",
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
            $id = 'crm.contact.add_' . uniqid('', true);
        }else{
            $id = 'crm.contact.add_' . $id;
        }
        $arg[$id] = 'crm.contact.add?' . http_build_query($params);
        return $arg;
    }

    public function crmLeadListByPhone(string $phone, $id): array
    {
        $params = [
            'order'=> [
                "DATE_CREATE" =>  "DESC"
            ],
            'filter' => [
                "PHONE"=> $phone,
                "STATUS_SEMANTIC_ID" => 'P'
            ],
            'select'=> [ "ID", "TITLE", "ASSIGNED_BY_ID", "STATUS_SEMANTIC_ID"],
            'auth' => $this->getAccessToken(),
        ];
        $arg = [];
        if(empty($id)){
            $id = 'crm.lead.list_' . uniqid('', true);
        }else{
            $id = 'crm.lead.list_' . $id;
        }
        $arg[$id] = 'crm.lead.list?' . http_build_query($params);
        return $arg;
    }

    /**
     * Обновление пользователя для Lead,
     * @param string $id
     * @param string $newUserId
     * @return array
     */
    public function crmContactUpdate(string $id, string $newUserId): array
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
        $arg['crm.contact.update_' . uniqid('', true)] = 'crm.contact.update?' . http_build_query($params);
        return $arg;
    }

    /**
     * Обновление пользователя для Lead,
     * @param string $id
     * @param string $newUserId
     * @return array
     */
    public function crmLeadUpdate(string $id, string $newUserId): array
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
        $arg['crm.lead.update_' . uniqid('', true)] = 'crm.lead.update?' . http_build_query($params);

        return $arg;
    }

    /**
     * Функции взаимодействия с базой данных на АТС.
     */

    /**
     * Получпение из базы данных идентификатора звонка.
     * @param $uniq_id
     * @return array
     */
    public function getCallDataByUniqueId($uniq_id): array
    {
        $CALL_DATA = [];
        /** @var ModuleBitrix24CDR $res */
        $res = ModuleBitrix24CDR::findFirst("uniq_id='{$uniq_id}'");
        if ($res !== null) {
            $CALL_DATA  = $res->toArray();
            unset($res);
            $linkedId   = $CALL_DATA['linkedid'];
            /** @var ModuleBitrix24CDR $row */
            $rows       = ModuleBitrix24CDR::find("linkedid='{$linkedId}'");
            foreach ($rows as $row){
                if(!empty($row->dealId)){
                    $CALL_DATA['deal_id']      = max($row->dealId,$CALL_DATA['deal_id']);
                    $CALL_DATA['deal_user']    = $row->user_id;
                }
                if(!empty($row->contactId)){
                    $CALL_DATA['contact_id']   = max($row->contactId,$CALL_DATA['contact_id']);
                    $CALL_DATA['contact_user'] = $row->user_id;
                }
                if(!empty($row->lead_id)){
                    $CALL_DATA['lead_id'] = max($row->lead_id,$CALL_DATA['lead_id']);
                    $CALL_DATA['lead_user'] = $row->user_id;
                }
            }
            unset($rows);
        }

        return $CALL_DATA;
    }

    /**
     * Получпение из базы данных идентификатора звонка.
     *
     * @param $uniq_id
     *
     * @return string|null
     */
    public function getCallIdByUniqueId($uniq_id): ?string
    {
        $call_id = null;
        /** @var ModuleBitrix24CDR $res */
        $res = ModuleBitrix24CDR::findFirst("uniq_id='{$uniq_id}'");
        if ($res !== null) {
            $call_id = $res->call_id;
        }

        return $call_id;
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
        $arg[self::API_CALL_HIDE.uniqid('', true)] = self::API_CALL_HIDE.'?' . http_build_query($params);

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
     * Удаление Дела по ID
     *
     * @param      $id
     *
     * @return array
     */
    public function crmLeadDelete($id): array
    {
        $params                                 = [
            'ID'   => $id,
            'auth' => $this->getAccessToken(),
        ];
        $arg                                    = [];
        $arg['lead.delete_' . uniqid('', true)] = 'crm.lead.delete?' . http_build_query($params);

        return $arg;
    }

    /**
     * Удаление Дела по ID
     *
     * @param  string $phone
     * @param  string $id
     *
     * @return array
     */
    public function searchCrmEntities(string $phone, string $id = ''): array
    {
        $params                                 = [
            'PHONE_NUMBER'   => $phone,
            'auth' => $this->getAccessToken(),
        ];
        $arg                                    = [];
        if(empty($id)){
            $id = self::API_SEARCH_ENTITIES.'_' . uniqid('', true);
        }else{
            $id = self::API_SEARCH_ENTITIES.'_' . $id;
        }
        $arg[$id] = self::API_SEARCH_ENTITIES.'?' . http_build_query($params);
        return $arg;
    }

    /**
     * @param bool $restart
     */
    public function startAllServices(bool $restart = false): void
    {
        $moduleEnabled = PbxExtensionUtils::isEnabled($this->moduleUniqueId);
        if ( ! $moduleEnabled) {
            return;
        }

        if (empty($this->getAccessToken())) {
            $this->updateToken($this->refresh_token);
        }

        $configClass      = new Bitrix24IntegrationConf();
        $workersToRestart = $configClass->getModuleWorkers();

        if ($restart) {
            foreach ($workersToRestart as $moduleWorker) {
                Processes::processPHPWorker($moduleWorker['worker']);
            }
        } else {
            $safeScript = new WorkerSafeScriptsCore();
            foreach ($workersToRestart as $moduleWorker) {
                if ($moduleWorker['type'] === WorkerSafeScriptsCore::CHECK_BY_AMI) {
                    $safeScript->checkWorkerAMI($moduleWorker['worker']);
                } else {
                    $safeScript->checkWorkerBeanstalk($moduleWorker['worker']);
                }
            }
        }
    }

    public function testUpdateToken($token){
        $this->updateToken($token);
    }

}
