<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 3 2019
 */

namespace Modules\ModuleBitrix24Integration\Models;

use MikoPBX\Common\Models\Users;
use MikoPBX\Modules\Models\ModulesModelsBase;
use Phalcon\Mvc\Model\Relation;

class ModuleBitrix24Users extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * идентификатор пользователя из таблицы Users
     *
     * @Column(type="integer", nullable=true)
     */
    public $user_id;

    /**
     * статус фильтрации, если 1 то выключить передачу данных в Bitrix24
     *
     * @Column(type="integer", nullable=true)
     */
    public $disabled;

    /**
     * Returns dynamic relations between module models and common models
     * MikoPBX check it in ModelsBase after every call to keep data consistent
     *
     * There is example to describe the relation between Providers and ModuleTemplate models
     *
     * It is important to duplicate the relation alias on message field after Models\ word
     *
     * @param $calledModelObject
     *
     * @return void
     */
    public static function getDynamicRelations(&$calledModelObject): void
    {
        if (is_a($calledModelObject, Users::class)) {
            $calledModelObject->hasOne(
                'id',
                __CLASS__,
                'user_id',
                [
                    'alias'      => 'ModuleBitrix24Users',
                    'foreignKey' => [
                        'allowNulls' => 0,
                        'message'    => 'Models\ModuleBitrix24Users',
                        'action'     => Relation::ACTION_CASCADE,
                    ],
                ]
            );
        }
    }

    public function initialize(): void
    {
        $this->setSource('m_ModuleBitrix24Users');
        parent::initialize();
        $this->belongsTo(
            'user_id',
            Users::class,
            'id',
            [
                'alias'      => 'PBXUsers',
                'foreignKey' => [
                    'allowNulls' => false,
                    'action'     => Relation::NO_ACTION,
                ],
            ]
        );
    }

}