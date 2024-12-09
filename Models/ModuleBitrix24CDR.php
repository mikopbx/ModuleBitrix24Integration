<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 8 2019
 */


namespace Modules\ModuleBitrix24Integration\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * Class ModuleCdrTextData
 *
 * @package Modules\ModuleCdrTextData\Models
 * @Indexes(
 *     [name='call_id', columns=['call_id'], type=''],
 *     [name='uniq_id', columns=['uniq_id'], type=''],
 *     [name='linkedid', columns=['linkedid'], type='']
 * )
 */
class ModuleBitrix24CDR extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $call_id;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $uniq_id;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $linkedid;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $answer;

    /**
     * Link to USERS
     *
     * @Column(type="integer", nullable=true)
     */
    public $user_id;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $lead_id;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $contactId;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $dealId;

    public function initialize(): void
    {
        $this->setSource('b24_cdr_data');
        parent::initialize();
        $this->useDynamicUpdate(true);
    }
}