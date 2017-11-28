<?php

namespace RA\OxidCleanupScripts;

use RA\OxidCleanupScripts\Exception\MysqliQueryException;

class OxidCleanup
{

    /**
     * @var array
     */
    protected $moduleMetaData = array();

    protected $oConf = null;

    protected $oDbConnection = null;

    protected $_aModules = array();

    private $oxidRoot = '';

    private $outputHandler = null;

    public function __construct($oxidRootDirectory)
    {
        $this->oxidRoot = realpath($oxidRootDirectory);

        $this->loadModuleMetadata();
        $this->oConf = new FakeConfig($this->oxidRoot);
        $this->oDbConnection = new \mysqli(
            $this->oConf->dbHost
            , $this->oConf->dbUser
            , $this->oConf->dbPwd
            , $this->oConf->dbName
        );

        $this->oDbConnection->set_charset("utf8");
    }

    public function registerOutputHandler(callable $callable) {
        $this->outputHandler = $callable;
    }

    private function output($message) {
        if (is_callable($this->outputHandler)) {
            call_user_func($this->outputHandler, $message);
        }
    }

    protected function loadModuleMetadata()
    {
        $dirItr = new \RecursiveDirectoryIterator($this->oxidRoot . '/modules');
        $filterItr = new RecursiveMetadataFilterIterator($dirItr);
        $itr = new \RecursiveIteratorIterator($filterItr, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($itr as $filePath => $fileInfo) {

            $aModule = array();
            if (file_exists($fileInfo->getRealPath() . '/metadata.php')) {
                /* @var splfileinfo $fileInfo */
                $aModule = $this->parseMetaDataFile($fileInfo->getRealPath() . '/metadata.php');

                $this->moduleMetaData[$aModule['id']] = $aModule;
            }
        }
    }

    public function cleanUpModuleExtends()
    {
        $sQuery = sprintf('SELECT OXID, OXSHOPID, OXVARNAME, OXVARTYPE, DECODE(oxvarvalue, "%s") as OXVARVALUE FROM oxconfig WHERE OXVARNAME = "aModules"', $this->oConf->sConfigKey);

        $stmt = $this->oDbConnection->prepare($sQuery);

        $stmt->execute();
        $res = $stmt->get_result();

        while ($res && $conf = mysqli_fetch_assoc($res)) {
            $varValue = unserialize($conf['OXVARVALUE']);
            $bChanged = false;

            foreach ($varValue as $baseClass => $extendString) {
                $extends = explode('&', $extendString);

                foreach ($extends as $index => $extend) {
                    if (!file_exists($this->oxidRoot .'/modules/' . $extend . '.php')) {
                        unset($extends[$index]);
                        $bChanged = true;
                    }
                }

                $extendString = implode('&', $extends);

                if ($extendString == '') {
                    unset($varValue[$baseClass]);
                } else {
                    $varValue[$baseClass] = $extendString;
                }
            }

            if ($bChanged) {
                $conf['OXVARVALUE'] = serialize($varValue);

                $sUpdateSsql = sprintf('UPDATE oxconfig SET OXVARVALUE = ENCODE("%s", "%s") WHERE OXID = "%s"', $this->oDbConnection->escape_string($conf['OXVARVALUE']), $this->oConf->sConfigKey, $conf['OXID']);
                if (!$this->oDbConnection->query($sUpdateSsql)) {
                    throw new MysqliQueryException($this->oDbConnection->error);
                }
                $this->output($sUpdateSsql);

            }
        }
    }

    public function cleanUpModuleFiles()
    {
        $sQuery = sprintf('SELECT OXID, OXSHOPID, OXVARNAME, OXVARTYPE, DECODE(oxvarvalue, "%s") as OXVARVALUE FROM oxconfig WHERE OXVARNAME = "aModuleFiles"', $this->oConf->sConfigKey);

        $stmt = $this->oDbConnection->prepare($sQuery);

        $stmt->execute();
        $res = $stmt->get_result();

        while ($res && $conf = mysqli_fetch_assoc($res)) {
            $varValue = unserialize($conf['OXVARVALUE']);

            $bChanged = false;

            foreach ($varValue as $module => $files) {

                if (is_array($files)) {
                    foreach ($files as $index => $file) {
                       if (!file_exists($this->oxidRoot . '/modules/' . $file)) {
                            unset($files[$index]);
                            $bChanged = true;
                        }
                    }
                    if (count($files) == 0) {
                        unset($varValue[$module]);
                    } else {
                        $varValue[$module] = $files;
                    }
                } else {
                    unset($varValue[$module]);
                }

            }

            if ($bChanged) {
                $conf['OXVARVALUE'] = serialize($varValue);

                $sUpdateSsql = sprintf('UPDATE oxconfig SET OXVARVALUE = ENCODE("%s", "%s") WHERE OXID = "%s"', $this->oDbConnection->escape_string($conf['OXVARVALUE']), $this->oConf->sConfigKey, $conf['OXID']);
                if (!$this->oDbConnection->query($sUpdateSsql)) {
                    throw new MysqliQueryException($this->oDbConnection->error);
                }
                $this->output($sUpdateSsql);

            }
        }
    }

    public function cleanUpDisabledModules()
    {
        $sQuery = sprintf('SELECT OXID, OXSHOPID, OXVARNAME, OXVARTYPE, DECODE(oxvarvalue, "%s") as OXVARVALUE FROM oxconfig WHERE OXVARNAME = "aDisabledModules"', $this->oConf->sConfigKey);

        $stmt = $this->oDbConnection->prepare($sQuery);

        $stmt->execute();
        $res = $stmt->get_result();

        while ($res && $conf = mysqli_fetch_assoc($res)) {
            $bChanged = false;
            $varValue = unserialize($conf['OXVARVALUE']);
            foreach ($varValue as $i => $moduleId) {
                if (!isset($this->moduleMetaData[$moduleId])) {
                    unset($varValue[$i]);
                    $bChanged = true;
                }
            }

            if ($bChanged) {
                $conf['OXVARVALUE'] = serialize($varValue);

                $sUpdateSsql = sprintf('UPDATE oxconfig SET OXVARVALUE = ENCODE("%s", "%s") WHERE OXID = "%s"', $this->oDbConnection->escape_string($conf['OXVARVALUE']), $this->oConf->sConfigKey, $conf['OXID']);
                if (!$this->oDbConnection->query($sUpdateSsql)) {
                    throw new MysqliQueryException($this->oDbConnection->error);
                } else {
                    $this->output($sUpdateSsql);
                }
            }
        }
    }

    public function cleanupDuplicateBlocks()
    {
        $sQuery = "SELECT COUNT(*), oxshopid, oxmodule, oxtemplate, oxfile, oxblockname FROM oxtplblocks GROUP BY oxshopid, oxmodule, oxtemplate, oxfile, oxblockname HAVING COUNT(*) > 1";
        $stmt = $this->oDbConnection->prepare($sQuery);

        $stmt->execute();
        $res = $stmt->get_result();

        while ($res && $blockInfo = mysqli_fetch_assoc($res)) {
            $sSQL = sprintf("SELECT OXID FROM oxtplblocks WHERE oxshopid = %d AND OXMODULE = '%s' AND OXFILE = '%s' AND OXBLOCKNAME = '%s' ORDER BY OXTIMESTAMP DESC", $blockInfo['oxshopid'], $blockInfo['oxmodule'], $blockInfo['oxfile'], $blockInfo['oxblockname']);

            $subRes = $this->oDbConnection->query($sSQL);

            $latestBlock = mysqli_fetch_assoc($subRes);

            $delSql = sprintf("DELETE FROM oxtplblocks WHERE oxshopid = %d AND OXMODULE = '%s' AND OXFILE = '%s' AND OXBLOCKNAME = '%s' AND OXID <> '%s'", $blockInfo['oxshopid'], $blockInfo['oxmodule'], $blockInfo['oxfile'], $blockInfo['oxblockname'], $latestBlock['OXID']);
            $this->oDbConnection->query($delSql);
            $this->output($delSql);
        }
    }

    public function cleanupOldBlocks()
    {

        $this->getModulesFromDir($this->oxidRoot . '/modules/');

        $sQuery = "SELECT * FROM oxtplblocks";
        $stmt = $this->oDbConnection->prepare($sQuery);

        $stmt->execute();
        $res = $stmt->get_result();

        while ($res && $blockInfo = mysqli_fetch_assoc($res)) {

            $modulePath = null;
            if (isset($this->_aModules[$blockInfo['OXMODULE']])) {
                $modulePath = $this->_aModules[$blockInfo['OXMODULE']];
            }
            if (!isset($modulePath) || !file_exists($this->oxidRoot . '/modules/' . $modulePath .'/' . $blockInfo['OXFILE'])) {
                $delSql = sprintf("DELETE FROM oxtplblocks WHERE OXID = '%s'", $blockInfo['OXID']);
                $this->oDbConnection->query($delSql);
                $this->output($delSql);
            }

        }
    }

    public function cleanupOldModuleEvents()
    {
        $this->getModulesFromDir($this->oxidRoot . '/modules/');
        $sQuery = sprintf('SELECT OXID, OXSHOPID, OXVARNAME, OXVARTYPE, DECODE(oxvarvalue, "%s") as OXVARVALUE FROM oxconfig WHERE OXVARNAME = "aModuleEvents"', $this->oConf->sConfigKey);

        $stmt = $this->oDbConnection->prepare($sQuery);

        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $conf = mysqli_fetch_assoc($res)) {
            $modules = unserialize($conf['OXVARVALUE']);
            foreach ($modules as $module => $events) {
                if (!isset($this->_aModules[$module])) {
                    unset($modules[$module]);
                }
            }
            $sUpdateSsql = sprintf('UPDATE oxconfig SET OXVARVALUE = ENCODE("%s", "%s") WHERE OXVARNAME = "aModuleEvents" AND OXSHOPID = %d', $this->oDbConnection->escape_string(serialize($modules)), $this->oConf->sConfigKey, $conf['OXSHOPID']);

            if (!$this->oDbConnection->query($sUpdateSsql)) {
                throw new MysqliQueryException($this->oDbConnection->error);
            } else {
                $this->output($sUpdateSsql);
            }
        }
    }

    public function cleanupOldTemplates()
    {
        $this->getModulesFromDir($this->oxidRoot . '/modules/');
        $sQuery = sprintf('SELECT OXID, OXSHOPID, OXVARNAME, OXVARTYPE, DECODE(oxvarvalue, "%s") as OXVARVALUE FROM oxconfig WHERE OXVARNAME = "aModuleTemplates"', $this->oConf->sConfigKey);

        $stmt = $this->oDbConnection->prepare($sQuery);

        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $conf = mysqli_fetch_assoc($res)) {
            $modules = unserialize($conf['OXVARVALUE']);
            foreach ($modules as $module => $templates) {
                if (!isset($this->_aModules[$module])) {
                    unset($modules[$module]);
                }
            }
            $sUpdateSsql = sprintf('UPDATE oxconfig SET OXVARVALUE = ENCODE("%s", "%s") WHERE OXVARNAME = "aModuleTemplates" AND OXSHOPID = %d', $this->oDbConnection->escape_string(serialize($modules)), $this->oConf->sConfigKey, $conf['OXSHOPID']);

            if (!$this->oDbConnection->query($sUpdateSsql)) {
                throw new MysqliQueryException($this->oDbConnection->error);
            } else {
                $this->output($sUpdateSsql);
            }
        }
    }

    public function cleanUpModuleVersions()
    {
        $this->getModulesFromDir($this->oxidRoot . '/modules/');
        $sQuery = sprintf('SELECT OXID, OXSHOPID, OXVARNAME, OXVARTYPE, DECODE(oxvarvalue, "%s") as OXVARVALUE FROM oxconfig WHERE OXVARNAME = "aModuleVersions"', $this->oConf->sConfigKey);

        $stmt = $this->oDbConnection->prepare($sQuery);

        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $conf = mysqli_fetch_assoc($res)) {
            $versions = unserialize($conf['OXVARVALUE']);
            foreach ($versions as $module => $version) {
                if (!isset($this->_aModules[$module])) {
                    unset($versions[$module]);
                } elseif (isset($this->moduleMetaData[$module]) && $this->moduleMetaData[$module]['version'] != $versions[$module]) {
                    $versions[$module] = $this->moduleMetaData[$module]['version'];
                }
            }
            $sUpdateSsql = sprintf('UPDATE oxconfig SET OXVARVALUE = ENCODE("%s", "%s") WHERE OXVARNAME = "aModuleVersions" AND OXSHOPID = %d', $this->oDbConnection->escape_string(serialize($versions)), $this->oConf->sConfigKey, $conf['OXSHOPID']);

            if (!$this->oDbConnection->query($sUpdateSsql)) {
                throw new MysqliQueryException($this->oDbConnection->error);
            } else {
                $this->output($sUpdateSsql);
            }
        }
    }


    public function cleanUpModulePaths()
    {
        $this->getModulesFromDir($this->oxidRoot . '/modules/');
        $sQuery = sprintf('SELECT OXID, OXSHOPID, OXVARNAME, OXVARTYPE, DECODE(oxvarvalue, "%s") as OXVARVALUE FROM oxconfig WHERE OXVARNAME = "aModulePaths"', $this->oConf->sConfigKey);

        $stmt = $this->oDbConnection->prepare($sQuery);

        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && $conf = mysqli_fetch_assoc($res)) {



            $sUpdateSsql = sprintf('UPDATE oxconfig SET OXVARVALUE = ENCODE("%s", "%s") WHERE OXVARNAME = "aModulePaths" AND OXSHOPID = %d', $this->oDbConnection->escape_string(serialize($this->_aModules)), $this->oConf->sConfigKey, $conf['OXSHOPID']);

            if (!$this->oDbConnection->query($sUpdateSsql)) {
                throw new MysqliQueryException($this->oDbConnection->error);
            } else {
                $this->output($sUpdateSsql);
            }
        }
    }

    /**
     * Scans modules dir and returns collected modules list.
     * Recursively loads also modules that are in vendor directory.
     *
     * @param string $sModulesDir Main module dir path
     * @param string $sVendorDir  Vendor directory name
     *
     * @return array
     */
    public function getModulesFromDir($sModulesDir, $sVendorDir = null)
    {
        foreach (glob($sModulesDir . '*') as $sModuleDirPath) {

            $sModuleDirPath .= (is_dir($sModuleDirPath)) ? '/' : '';
            $sModuleDirName = basename($sModuleDirPath);

            // skipping some file
            if ((!is_dir($sModuleDirPath) && substr($sModuleDirName, -4) != ".php")) {
                continue;
            }

            if ($this->_isVendorDir($sModuleDirPath)) {
                // scanning modules vendor directory
                $this->getModulesFromDir($sModuleDirPath, basename($sModuleDirPath));
            } else {
                // loading module info
                $sModuleDirName = (!empty($sVendorDir)) ? $sVendorDir . '/' . $sModuleDirName : $sModuleDirName;

                if (file_exists($this->oxidRoot . '/modules/' . $sModuleDirName . '/metadata.php')) {
                    $aModule = $this->parseMetaDataFile($this->oxidRoot . '/modules/' . $sModuleDirName . '/metadata.php');

                    $this->_aModules[$aModule['id']] = $sModuleDirName;
                }
            }
        }
    }

    public function clearCache()
    {
        $cmd = sprintf('rm -rf %s', $this->oxidRoot . '/tmp/*');
        exec($cmd);
        $this->output($cmd);
        $cmd = sprintf('find %s -maxdepth 2 -name "*.cache" -type f -exec rm -ivf {} \;', $this->oxidRoot . '/cache');
        exec($cmd);
        $this->output($cmd);
    }

    public function insertNewModuleinModuleWatcher() {

        $sQuery = sprintf('SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = "%s" AND TABLE_NAME = "ramodulewatcher"', $this->oConf->dbName);

        $stmt = $this->oDbConnection->prepare($sQuery);

        $stmt->execute();
        $res = $stmt->get_result();

        if($res->num_rows == 1) {

            $aModuleIds = array_keys($this->moduleMetaData);
            $aFoundModuleIds = [];

            $sQuery = sprintf('SELECT DISTINCT OXMODULE FROM ramodulewatcher WHERE OXMODULE IN ("%s")', implode('","', $aModuleIds));

            $stmt = $this->oDbConnection->prepare($sQuery);

            $stmt->execute();
            $res = $stmt->get_result();

            while ($res && $conf = mysqli_fetch_assoc($res)) {
                $aFoundModuleIds[] = $conf['OXMODULE'];
            }

            $sQuery = 'SELECT OXID FROM oxshops';

            $stmt = $this->oDbConnection->prepare($sQuery);

            $stmt->execute();
            $res = $stmt->get_result();

            $aShopIds = [];

            while ($res && $conf = mysqli_fetch_assoc($res)) {
                $aShopIds[] = $conf['OXID'];
            }

            foreach ($aModuleIds as $sModuleId) {
                if (!in_array($sModuleId, $aFoundModuleIds)) {
                    foreach ($aShopIds as $iShopId) {
                        $sQuery = sprintf('INSERT INTO ramodulewatcher SET OXID = "%s", OXSHOPID = "%s", OXMODULE = "%s", OXSTATE = "0"',
                            substr(md5(uniqid('', true) . '|' . microtime()), 0, 32),
                            $iShopId,
                            $sModuleId
                        );

                        $stmt = $this->oDbConnection->prepare($sQuery);
                        $stmt->execute();
                        $this->output($sQuery);
                    }
                }
            }

        }
    }


    /**
     * Checks if directory is vendor directory.
     *
     * @param string $sModuleDir dir path
     *
     * @return bool
     */
    protected function _isVendorDir($sModuleDir)
    {
        if (is_dir($sModuleDir) && file_exists($sModuleDir . 'vendormetadata.php')) {
            return true;
        }

        return false;
    }

    protected function parseMetaDataFile($metaDataFile)
    {
        if (file_exists($metaDataFile)) {
            $metaDataContent = file_get_contents($metaDataFile);
            preg_match_all('/\$aModule\s*=\s*array\(.+?\)\s*\;/s', $metaDataContent, $matches);

            if ($matches) {
                eval($matches[0][0]);

                return $aModule;
            }
        }
    }

    public function fullCleanUp()
    {
        $this->cleanUpModulePaths();

        $this->cleanUpModuleExtends();

        $this->cleanUpDisabledModules();

        $this->cleanUpModuleFiles();

        $this->cleanupDuplicateBlocks();

        $this->cleanupOldBlocks();

        $this->cleanupOldModuleEvents();

        $this->cleanupOldTemplates();

        $this->cleanUpModuleVersions();

        $this->insertNewModuleinModuleWatcher();

        $this->clearCache();
    }
} 
