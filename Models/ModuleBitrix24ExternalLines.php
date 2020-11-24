<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 5 2020
 */

namespace Modules\ModuleBitrix24Integration\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;
use Phalcon\Mvc\Model\Relation;

class ModuleBitrix24ExternalLines extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * Номер телефона внешней линии. Пример 79117654321.
     *
     * @Column(type="string", nullable=true)
     */
    public $number;

    /**
     * Имя внешней линии, представление пользователю портала. Необязательный.
     *
     * @Column(type="string", nullable=true)
     */
    public $name;

    /**
     * Псевдонимы (DID) для этого номера.
     *
     * @Column(type="string", nullable=true)
     */
    public $alias;


    public function initialize() :void{
        $this->setSource('m_ModuleBitrix24ExternalLines');
        parent::initialize();
    }

}