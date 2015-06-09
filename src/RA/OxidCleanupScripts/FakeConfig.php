<?php
/**
 * Created by PhpStorm.
 * User: chl
 * Date: 09.06.15
 * Time: 15:30
 */

namespace RA\OxidCleanupScripts;


class FakeConfig
{

    public function __construct($pathToOxConfig)
    {
        require $pathToOxConfig . '/config.inc.php';
        require $pathToOxConfig . '/core/oxconfk.php';
    }
} 