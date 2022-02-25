<?php
/*
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 10 2020
 */

namespace Modules\ModuleBitrix24Integration\App\Controllers;

use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\Common\Models\CallQueues;
use MikoPBX\Common\Models\Users;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Integration;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24Users;
use Modules\ModuleBitrix24Integration\Models\ModuleBitrix24ExternalLines;
use MikoPBX\Common\Models\Extensions;
use Modules\ModuleBitrix24Integration\App\Forms\ModuleBitrix24IntegrationForm;

use function MikoPBX\Common\Config\appPath;

class ModuleBitrix24IntegrationController extends BaseController
{
    private $moduleDir;
    private string $moduleUniqueID = 'ModuleBitrix24Integration';

    /**
     * Basic initial class
     */
    public function initialize(): void
    {
        $this->moduleDir           = PbxExtensionUtils::getModuleDir($this->moduleUniqueID);
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

        $settings = ModuleBitrix24Integration::findFirst();
        if ($settings === null) {
            $settings = new ModuleBitrix24Integration();
        }

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


        // Получим список пользователей для отображения в фильтре
        $parameters       = [
            'columns' => [
                'user_id',
                'disabled',
            ],
        ];
        $bitrix24Users    = ModuleBitrix24Users::find($parameters)->toArray();
        $bitrix24UsersIds = array_column($bitrix24Users, 'user_id');

        $extensionTable = [];
        foreach ($extensions as $extension) {
            switch ($extension->type) {
                case 'SIP':
                    $extensionTable[$extension->userid]['userid']   = $extension->userid;
                    $extensionTable[$extension->userid]['number']   = $extension->number;
                    $extensionTable[$extension->userid]['status']   = '';
                    $extensionTable[$extension->userid]['id']       = $extension->id;
                    $extensionTable[$extension->userid]['username'] = $extension->username;

                    if ( ! array_key_exists('mobile', $extensionTable[$extension->userid])) {
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
                        $extensionTable[$extension->userid]['status'] = ($bitrix24Users[$key]['disabled'] === '1') ? 'disabled' : '';
                    }

                    break;
                case 'EXTERNAL':
                    $extensionTable[$extension->userid]['mobile'] = $extension->number;
                    break;
                default:
            }
        }
        $this->view->extensions     = $extensionTable;

        $this->view->externalLines  = ModuleBitrix24ExternalLines::find();

        $options = [
            'queues' => [ '' => $this->translation->_('ex_SelectNumber') ],
            'users'  => [ '' => $this->translation->_('ex_SelectNumber') ],
        ];
        $parameters               = [
            'conditions' => 'type IN ({types:array})',
            'bind'       => [
                'types' => [Extensions::TYPE_QUEUE, Extensions::TYPE_SIP],
            ],
        ];
        $extensions               = Extensions::find($parameters);
        foreach ($extensions as $record) {
            if($record->type === Extensions::TYPE_QUEUE){
                $options['queues'][$record->number] = $record ? $record->getRepresent() ." <$record->number>": '';
            }else{
                $options['users'][$record->number] = $record ? $record->getRepresent() : '';
            }
        }
        $this->view->form = new ModuleBitrix24IntegrationForm($settings, $options);
        $this->view->pick("{$this->moduleDir}/App/Views/index");
    }

    /**
     * Сохранение настроек
     */
    public function saveAction(): void
    {
        if ( ! $this->request->isPost()) {
            return;
        }
        $data   = $this->request->getPost();
        $record = ModuleBitrix24Integration::findFirst();

        if ( ! $record) {
            $record = new ModuleBitrix24Integration();
        }
        $this->db->begin();
        $old_refresh_token = $record->refresh_token;

        // General settings
        foreach ($record as $key => $value) {
            switch ($key) {
                case 'id':
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
        if ($record->save() === false) {
            $errors = $record->getMessages();
            $this->flash->error(implode('<br>', $errors));
            $this->view->success = false;
            $this->db->rollback();

            return;
        }

        // Users filter
        foreach ($data as $key => $value) {
            if (substr_count($key, 'user-') > 0) {
                $userId       = explode('user-', $key)[1];
                $disabled     = ($data[$key] === 'on') ? '0' : '1';
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
                $userSettings->disabled = $disabled;
                if ($userSettings->save() === false) {
                    $errors = $userSettings->getMessages();
                    $this->flash->error(implode('<br>', $errors));
                    $this->view->success = false;
                    $this->db->rollback();

                    return;
                }
            }
        }

        // External lines
        $externalLines = ModuleBitrix24ExternalLines::find();
        $externalLinesPost = json_decode($data['externalLines'],true);
        // Delete all not exists in POST data
        foreach ($externalLines as $externalLine){
            if (! in_array($externalLine->id, array_column($externalLinesPost, 'id'), true)){
                $externalLine->delete();
            }
        }
        // Add or change exists
        foreach ($externalLinesPost as $record) {
            if (!isset($record['id']) && empty($record['id'])){
                continue;
            }
            $externalLine = ModuleBitrix24ExternalLines::findFirstById($record['id']);
            if ($externalLine===null){
                $externalLine = new ModuleBitrix24ExternalLines();
            }
            foreach ($externalLine as $key => $value){
                switch ($key) {
                    case 'id':
                        break;
                    default:
                        if ( ! array_key_exists($key, $record)) {
                            $externalLine->$key = '';
                        } else {
                            $externalLine->$key = $record[$key];
                        }
                }
            }
            if ($externalLine->save() === false) {
                $errors = $externalLine->getMessages();
                $this->flash->error(implode('<br>', $errors));
                $this->view->success = false;
                $this->db->rollback();

                return;
            }
        }

        $this->flash->success($this->translation->_('ms_SuccessfulSaved'));
        $this->view->success = true;
        $this->db->commit();
    }

    /**
     * Pretest to check params
     */
    public function enableAction(): bool
    {
        $result = true;
        $record = ModuleBitrix24Integration::findFirst();
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
     * Delete phonebook record
     *
     * @param string $id record ID
     */
    public function deleteExternalLineAction($id = null): void
    {
        $record = ModuleBitrix24ExternalLines::findFirstById($id);
        if ($record !== null && ! $record->delete()) {
            $this->flash->error(implode('<br>', $record->getMessages()));
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
        $recordsTotalReq       = ModuleBitrix24ExternalLines::findFirst($parameters);
        if ($recordsTotalReq !== null) {
            $recordsTotal             = $recordsTotalReq->rows;
            $this->view->recordsTotal = $recordsTotal;
        } else {
            return;
        }

        $recordsFilteredReq = ModuleBitrix24ExternalLines::findFirst($parameters);
        if ($recordsFilteredReq !== null) {
            $recordsFiltered             = $recordsFilteredReq->rows;
            $this->view->recordsFiltered = $recordsFiltered;
        }

        // Найдем все записи подходящие под заданный фильтр
        $parameters['columns'] = [
            'name',
            'number',
            'alias',
            'DT_RowId' => 'id',
        ];
        $parameters['limit']   = $recordsPerPage;
        $parameters['offset']  = $position;
        $records               = ModuleBitrix24ExternalLines::find($parameters);
        $this->view->data      = $records->toArray();
    }

}