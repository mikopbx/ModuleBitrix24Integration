<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 8 2019
 */

/**
 * Created by PhpStorm.
 * User: Alexey
 * Date: 2019-02-18
 * Time: 15:47
 */

namespace Modules\ModuleBitrix24Integration\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

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

    public function initialize(): void
    {
        $this->setSource('b24_cdr_data');
        parent::initialize();
        $this->useDynamicUpdate(true);
    }
}