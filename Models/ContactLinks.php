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
 *     [name='companyId', columns=['companyId'], type=''],
 *     [name='contactId', columns=['contactId'], type='']
 * )
 */
class ContactLinks extends ModulesModelsBase
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
    public $contactId;

    /**
     * @Column(type="string", nullable=true)
     */
    public $companyId;

    public function initialize() :void{
        $this->setSource('m_ContactLinks');
        parent::initialize();
    }

}