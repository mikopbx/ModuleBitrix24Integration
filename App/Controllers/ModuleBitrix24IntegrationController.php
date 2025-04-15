<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2023 Alexey Portnov and Nikolay Beketov
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

namespace Modules\ModuleBitrix24Integration\App\Controllers;

use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\Common\Models\CallQueues;
use MikoPBX\Common\Models\Users;
use MikoPBX\Core\System\Util;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleBitrix24Integration\App\Forms\ModuleBitrix24IntegrationForm;
use Modules\ModuleBitrix24Integration\bin\ConnectorDb;
use Modules\ModuleBitrix24Integration\Lib\Bitrix24Integration;
use Modules\ModuleBitrix24Integration\Lib\Bitrix24InvokeRest;
use Modules\ModuleBitrix24Integration\Lib\CacheManager;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Integration;
use MikoPBX\Common\Models\Extensions;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Users;

use function MikoPBX\Common\Config\appPath;

class ModuleBitrix24IntegrationController extends BaseController
{
    private string $moduleDir;
    private string $moduleUniqueID = 'ModuleBitrix24Integration';

    /**
     * Basic initial class
     */
    public function initialize(): void
    {
        $this->moduleDir = PbxExtensionUtils::getModuleDir($this->moduleUniqueID);
        if ($this->request->isAjax() === false) {
            $this->view->logoImagePath = "{$this->url->get()}assets/img/cache/{$this->moduleUniqueID}/logo.svg";
            $this->view->submitMode    = null;
        }
        parent::initialize();
    }

    /**
     * Форма настроек модуля
     */
    public function indexAction(): void
    {
        $footerCollection = $this->assets->collection('footerJS');
        $footerCollection
            ->addJs('js/vendor/inputmask/inputmask.js', true)
            ->addJs('js/vendor/inputmask/jquery.inputmask.js', true)
            ->addJs('js/vendor/inputmask/jquery.inputmask-multi.js', true)
            ->addJs('js/vendor/inputmask/bindings/inputmask.binding.js', true)
            ->addJs('js/vendor/datatable/dataTables.semanticui.js', true)
            ->addJs('js/pbx/Extensions/input-mask-patterns.js', true)
            ->addJs('js/pbx/main/form.js', true)
            ->addJs("js/cache/{$this->moduleUniqueID}/module-bitrix24-integration-status-worker.js", true)
            ->addJs("js/cache/{$this->moduleUniqueID}/module-bitrix24-integration-index.js", true);
        $headerCollectionCSS = $this->assets->collection('headerCSS');
        $headerCollectionCSS
            ->addCss('css/vendor/semantic/list.min.css', true)
            ->addCss('css/vendor/datatable/dataTables.semanticui.min.css', true);

        // Получим список пользователей для отображения в фильтре
        $parameters = [
            'models'     => [
                'Extensions' => Extensions::class,
            ],
            'conditions' => 'Extensions.is_general_user_number = 1',
            'columns'    => [
                'id'       => 'Extensions.id',
                'username' => 'Users.username',
                'number'   => 'Extensions.number',
                'userid'   => 'Extensions.userid',
                'type'     => 'Extensions.type',
                'avatar'   => 'Users.avatar',

            ],
            'order'      => 'number',
            'joins'      => [
                'Users' => [
                    0 => Users::class,
                    1 => 'Users.id = Extensions.userid',
                    2 => 'Users',
                    3 => 'INNER',
                ],
            ],
        ];
        $query      = $this->di->get('modelsManager')->createBuilder($parameters)->getQuery();
        $extensions = $query->execute();


        $this->view->cardMods = [
            Bitrix24Integration::OPEN_CARD_DIRECTLY,
            Bitrix24Integration::OPEN_CARD_NONE,
            Bitrix24Integration::OPEN_CARD_ANSWERED
        ];

        $usersB24 = [];
        $moduleEnable = PbxExtensionUtils::isEnabled('ModuleBitrix24Integration');
        $filter24Users       = [ 'columns' => [ 'user_id', 'disabled', 'open_card_mode'] ];
        if($moduleEnable){
            // Получим список пользователей для отображения в фильтре
            $settings       = ConnectorDb::invokePriority(ConnectorDb::FUNC_GET_GENERAL_SETTINGS);
            $bitrix24Users    = (array)ConnectorDb::invokePriority(ConnectorDb::FUNC_GET_USERS, [$filter24Users]);
            if(!empty($settings->portal)){
                $usersB24 = (new Bitrix24Integration('_www'))->userGet(true);
                if ( is_array($usersB24['result']) ) {
                    $usersB24['users'] = [];
                    foreach ($usersB24['result'] as $userB24){
                        $usersB24['users'][$userB24['UF_PHONE_INNER']] = "{$userB24['LAST_NAME']} {$userB24['NAME']}";
                    }
                    $usersB24 = $usersB24['users'];
                }
            }
        }else{
            $bitrix24Users = ModuleBitrix24Users::find($filter24Users)->toArray();
            $settings = ModuleBitrix24Integration::findFirst();
        }
        $bitrix24UsersIds = array_column($bitrix24Users, 'user_id');

        if (!$settings) {
            $settings = new ModuleBitrix24Integration();
        }

        $extensionTable = [];
        foreach ($extensions as $extension) {
            switch ($extension->type) {
                case 'SIP':
                    $extensionTable[$extension->userid]['userid']   = $extension->userid;
                    $extensionTable[$extension->userid]['number']   = $extension->number;
                    $extensionTable[$extension->userid]['status']   = '';
                    $extensionTable[$extension->userid]['id']       = $extension->id;
                    $extensionTable[$extension->userid]['username'] = $extension->username;
                    $extensionTable[$extension->userid]['b24Name']  = $usersB24[$extension->number]??'';

                    if (!array_key_exists('mobile', $extensionTable[$extension->userid])) {
                        $extensionTable[$extension->userid]['mobile'] = '';
                    }

                    $extensionTable[$extension->userid]['avatar'] = "{$this->url->get()}assets/img/unknownPerson.jpg";
                    if ($extension->avatar) {
                        $filename    = md5($extension->avatar);
                        $imgCacheDir = appPath('sites/admin-cabinet/assets/img/cache');
                        $imgFile     = "{$imgCacheDir}/{$filename}.jpg";
                        if (file_exists($imgFile)) {
                            $extensionTable[$extension->userid]['avatar'] = "{$this->url->get()}assets/img/cache/{$filename}.jpg";
                        }
                    }
                    $key = array_search($extension->userid, $bitrix24UsersIds, true);
                    if ($key !== false) {
                        $extensionTable[$extension->userid]['status'] = (intval($bitrix24Users[$key]['disabled']) === 1) ? 'disabled' : '';
                        $extensionTable[$extension->userid]['open_card_mode'] = empty($bitrix24Users[$key]['open_card_mode']) ? Bitrix24Integration::OPEN_CARD_DIRECTLY : $bitrix24Users[$key]['open_card_mode'];
                    }

                    break;
                case 'EXTERNAL':
                    $extensionTable[$extension->userid]['mobile'] = $extension->number;
                    break;
                default:
            }
        }
        $this->view->extensions     = $extensionTable;

        $options = [
            'queues' => [ '' => $this->translation->_('ex_SelectNumber') ],
            'users'  => [ '' => $this->translation->_('ex_SelectNumber') ],
        ];
        $parameters               = [
            'conditions' => 'type IN ({types:array})',
            'bind'       => [
                'types' => [Extensions::TYPE_SIP],
            ],
        ];
        $extensions               = Extensions::find($parameters);
        foreach ($extensions as $record) {
            $options['users'][$record->number] = $record ? $record->getRepresent() : '';
        }
        $queues = CallQueues::find();
        foreach ($queues as $record) {
            if(trim($settings->callbackQueue) === $record->extension){
                $settings->callbackQueue = $record->uniqid;
            }
            $options['queues'][$record->uniqid] = $record->getRepresent();
        }

        $this->view->form = new ModuleBitrix24IntegrationForm($settings, $options);
        $this->view->pick("{$this->moduleDir}/App/Views/index");
    }

    /**
     * Аутентификация, активация code, получение token.
     * @return void
     * @throws \JsonException
     */
    public function activateCodeAction(): void
    {
        if (!$this->request->isPost()) {
            return;
        }
        $data   = $this->request->getPost();
        $b24 = new Bitrix24Integration('_www');
        $this->view->result = $b24->authByCode($data['code'], $data['region']);
        CacheManager::setCacheData('module_scope', []);
    }

    /**
     * Возвращает статус работы модуля
     * @return void
     */
    public function checkStateAction():void
    {
        $result = CacheManager::getCacheData('module_scope');
        $moduleEnable = PbxExtensionUtils::isEnabled('ModuleBitrix24Integration');
        if ($moduleEnable) {
            $ir = new Bitrix24InvokeRest();
            if(empty($result)){
                $result = $ir->invoke('scope', []);
            }
            $this->view->result = !empty($result);
            $this->view->data = [];
            $this->view->messages = $result;
            CacheManager::setCacheData('module_scope', $result, 30);
        } else {
            $this->view->result = false;
            $this->view->messages[] = Util::translate('mod_b24_i_NoSettings');
        }
    }

    /**
     * Аутентификация, активация code, получение token.
     * @return void
     */
    public function getAppIdAction(): void
    {
        if (!$this->request->isPost()) {
            return;
        }
        $data   = $this->request->getPost();
        $oAuthToken = ModuleBitrix24Integration::getAvailableRegions()[$data['region']];
        $this->view->client_id = $oAuthToken['CLIENT_ID']??'';
        $this->view->success = !empty($this->view->client_id);
    }

    /**
     * Сохранение настроек
     */
    public function saveAction(): void
    {
        if (!$this->request->isPost()) {
            return;
        }
        $data = $this->request->getPost();
        $moduleEnable = PbxExtensionUtils::isEnabled('ModuleBitrix24Integration');
        if($moduleEnable){
            $record = ConnectorDb::invokePriority(ConnectorDb::FUNC_GET_GENERAL_SETTINGS);
        }else{
            $record = ModuleBitrix24Integration::findFirst();
        }
        if (!$record) {
            $record = new ModuleBitrix24Integration();
        }
        $old_refresh_token = $record->refresh_token;
        // General settings
        foreach ($record as $key => $value) {
            switch ($key) {
                case 'id':
                    break;
                case 'callbackQueue':
                    $record->$key = trim($data[$key]);
                    break;
                case 'session':
                    $refresh_token = $data['refresh_token'] ?? '';
                    if ($refresh_token !== $old_refresh_token) {
                        $record->$key = null;
                    }
                    break;
                case 'export_cdr':
                case 'use_interception':
                case 'crmCreateLead':
                case 'backgroundUpload':
                case 'export_records':
                    if (array_key_exists($key, $data)) {
                        $record->$key = ($data[$key] === 'on') ? '1' : '0';
                    } else {
                        $record->$key = '0';
                    }
                    break;
                default:
                    if ( ! array_key_exists($key, $data)) {
                        $record->$key = '';
                    } else {
                        $record->$key = $data[$key];
                    }
            }
        }
        if ($moduleEnable){
            $resultSave = ConnectorDb::invokePriority(ConnectorDb::FUNC_UPDATE_GENERAL_SETTINGS, [(array)$record], true, 10);
        }else{
            $resultSave = $record->save();
        }
        if ($resultSave === false) {
            $this->view->success = false;
            return;
        }
        $arrUsersPost = json_decode($data['arrUsers'],true);
        $resultSaveUsers = ConnectorDb::invokePriority(ConnectorDb::FUNC_SAVE_USERS, [$arrUsersPost]);
        if ($resultSaveUsers === false) {
            $this->view->success = false;
            return;
        }

        $externalLinesPost = json_decode($data['externalLines'],true);
        $resultSaveLines   = ConnectorDb::invokePriority(ConnectorDb::FUNC_SAVE_EXTERNAL_LINES, [$externalLinesPost]);
        if(!$resultSaveLines){
            $this->view->success = false;
            return;
        }
        $this->flash->success($this->translation->_('ms_SuccessfulSaved'));
        $this->view->success = true;
    }

    /**
     * Pretest to check params
     */
    public function enableAction(): bool
    {
        $result = true;
        $record = ConnectorDb::invokePriority(ConnectorDb::FUNC_GET_GENERAL_SETTINGS);
        if ( ! $record || empty($record->portal)) {
            $result = false;
            $this->flash->error($this->translation->_('mod_b24_i_ValidatePortalEmpty'));
        } elseif (empty($record->refresh_token)) {
            $result = false;
            $this->flash->error($this->translation->_('mod_b24_i_ValidateClientRefreshTokenEmpty'));
        }
        $this->view->success = $result;
        return $result;
    }

    /**
     * @param string $id record ID
     */
    public function deleteExternalLineAction($id = null): void
    {
        $record = ConnectorDb::invokePriority(ConnectorDb::FUNC_DELETE_EXTERNAL_LINE, [$id]);
        if (!$record) {
            $this->view->success = false;
            return;
        }
        $this->view->success = true;
    }

    /**
     * Запрос списка линий для DataTable JSON
     */
    public function getExternalLinesAction(): void
    {
        $currentPage                 = $this->request->getPost('draw');
        $position                    = $this->request->getPost('start');
        $recordsPerPage              = $this->request->getPost('length');
        $this->view->draw            = $currentPage;
        $this->view->recordsTotal    = 0;
        $this->view->recordsFiltered = 0;
        $this->view->data            = [];

        // Посчитаем количество уникальных записей в таблице телефонов
        $parameters['columns'] = 'COUNT(*) as rows';
        $recordsTotalReq       = (object)ConnectorDb::invokePriority(ConnectorDb::FUNC_GET_FIRST_EXTERNAL_LINES, [$parameters]);
        if ($recordsTotalReq !== null) {
            $recordsTotal             = $recordsTotalReq->rows;
            $this->view->recordsTotal = $recordsTotal;
        } else {
            return;
        }
        $recordsFilteredReq    = (object)ConnectorDb::invokePriority(ConnectorDb::FUNC_GET_FIRST_EXTERNAL_LINES, [$parameters]);
        if ($recordsFilteredReq !== null) {
            $recordsFiltered             = $recordsFilteredReq->rows;
            $this->view->recordsFiltered = $recordsFiltered;
        }

        // Найдем все записи подходящие под заданный фильтр
        $parameters['columns'] = [
            'disabled',
            'name',
            'number',
            'alias',
            'DT_RowId' => 'id',
        ];
        $parameters['limit']   = $recordsPerPage;
        $parameters['offset']  = $position;
        $records               = [];
        $tmpRecords = ConnectorDb::invokePriority(ConnectorDb::FUNC_GET_EXTERNAL_LINES, [$parameters]);
        foreach ($tmpRecords as $extLine){
            $records[] = (object) $extLine;
        }
        $this->view->data      = $records;
    }
}