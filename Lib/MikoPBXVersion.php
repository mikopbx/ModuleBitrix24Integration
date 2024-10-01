<?php

namespace Modules\ModuleBitrix24Integration\Lib;
use MikoPBX\Common\Models\PbxSettings;

class MikoPBXVersion
{
    /**
     * Return true if current version of PBX based on Phalcon 5+
     * @return bool
     */
    public static function isPhalcon5Version():bool
    {
        $pbxVersion = PbxSettings::getValueByKey('PBXVersion');
        return version_compare($pbxVersion, '2024.2.30', '>');
    }

    /**
     * Return Di interface for the current version of PBX
     * @return \Phalcon\Di\DiInterface|null
     */
    public static function getDefaultDi():\Phalcon\Di\DiInterface|null
    {
        if (self::isPhalcon5Version()) {
            return  \Phalcon\Di\Di::getDefault();
        } else {
            return  \Phalcon\Di::getDefault();
        }
    }

    /**
     * Return Validation class for the current version of PBX
     * @return class-string<\Phalcon\Filter\Validation>|class-string<\Phalcon\Validation>
     */
    public static function getValidationClass():string
    {
        if (self::isPhalcon5Version()) {
            return  \Phalcon\Filter\Validation::class;
        } else {
            return  \Phalcon\Validation::class;
        }
    }

    /**
     * Return Uniqueness class for the current version of PBX
     * @return class-string<\Phalcon\Filter\Validation\Validator\Uniqueness>|class-string<\Phalcon\Validation\Validator\Uniqueness>
     */
    public static function getUniquenessClass():string
    {
        if (self::isPhalcon5Version()) {
            return  \Phalcon\Filter\Validation\Validator\Uniqueness::class;
        } else {
            return  \Phalcon\Validation\Validator\Uniqueness::class;
        }
    }

    /**
     * Return Text class for the current version of PBX
     *
     * @return class-string<\MikoPBX\Common\Library\Text>|class-string<\Phalcon\Text>
     */
    public static function getTextClass():string
    {
        if (self::isPhalcon5Version()) {
            return   \MikoPBX\Common\Library\Text::class;
        } else {
            return  \Phalcon\Text::class;
        }
    }

    /**
     * Return Logger class for the current version of PBX
     *
     * @return class-string<\Phalcon\Logger\Logger>|class-string<\Phalcon\Logger>
     */
    public static function getLoggerClass():string
    {
        if (self::isPhalcon5Version()) {
            return  \Phalcon\Logger\Logger::class;
        } else {
            return  \Phalcon\Logger::class;
        }
    }
}