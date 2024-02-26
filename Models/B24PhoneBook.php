<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 5 2020
 */

namespace Modules\ModuleBitrix24Integration\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;
/**
 * Class ModuleAmoLeads
 * @package Modules\ModuleBitrix24Integration\Models
 * @Indexes(
 *     [name='contactType', columns=['contactType'], type=''],
 *     [name='phoneId', columns=['phoneId'], type=''],
 *     [name='statusLeadId', columns=['statusLeadId'], type='']
 * )
 */
class B24PhoneBook extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;
    /**
     * @Column(type="string", nullable=true)
     */
    public $b24id;
    /**
     * @Column(type="string", nullable=true)
     */
    public $userId;
    /**
     * @Column(type="string", nullable=true)
     */
    public $dateCreate;
    /**
     * @Column(type="string", nullable=true)
     */
    public $dateModify;
    /**
     * @Column(type="string", nullable=true)
     */
    public $statusLeadId;
    /**
     * @Column(type="string", nullable=true)
     */
    public $name;
    /**
     * @Column(type="string", nullable=true)
     */
    public $phone;
    /**
     * @Column(type="string", nullable=true)
     */
    public $phoneId;
    /**
     * @Column(type="string", nullable=true)
     */
    public $contactType;

    public function initialize() :void{
        $this->setSource('m_B24PhoneBook');
        parent::initialize();
    }

}