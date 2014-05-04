<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Installation;

use Exception;
use Piwik\Access;
use Piwik\AssetManager;
use Piwik\Common;
use Piwik\Config;
use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\Db\Adapter;
use Piwik\Db;
use Piwik\DbHelper;
use Piwik\Filesystem;
use Piwik\Http;
use Piwik\Option;
use Piwik\Piwik;
use Piwik\Plugin\Manager;
use Piwik\Plugins\CoreUpdater\CoreUpdater;
use Piwik\Plugins\LanguagesManager\LanguagesManager;
use Piwik\Plugins\SitesManager\API as APISitesManager;
use Piwik\Plugins\SitesManager\API;
use Piwik\Plugins\UserCountry\LocationProvider;
use Piwik\Plugins\UsersManager\API as APIUsersManager;
use Piwik\ProxyHeaders;
use Piwik\SettingsPiwik;
use Piwik\Updater;
use Piwik\Url;
use Piwik\Version;
use Zend_Db_Adapter_Exception;

/**
 * Installation controller
 *
 */
class Controller extends \Piwik\Plugin\ControllerAdmin
{
    public $steps = array(
        'welcome'           => 'Installation_Welcome',
        'systemCheck'       => 'Installation_SystemCheck',
        'databaseSetup'     => 'Installation_DatabaseSetup',
        'tablesCreation'    => 'Installation_Tables',
        'generalSetup'      => 'Installation_SuperUser',
        'firstWebsiteSetup' => 'Installation_SetupWebsite',
        'trackingCode'      => 'General_JsTrackingTag',
        'finished'          => 'Installation_Congratulations',
    );

    /**
     * Get installation steps
     *
     * @return array installation steps
     */
    public function getInstallationSteps()
    {
        return $this->steps;
    }

    /**
     * Get default action (first installation step)
     *
     * @return string function name
     */
    function getDefaultAction()
    {
        $steps = array_keys($this->steps);
        return $steps[0];
    }

    /**
     * Installation Step 1: Welcome
     */
    function welcome($message = false)
    {
        // Delete merged js/css files to force regenerations based on updated activated plugin list
        Filesystem::deleteAllCacheOnUpdate();

        $view = new View(
            '@Installation/welcome',
            $this->getInstallationSteps(),
            __FUNCTION__
        );

        $view->newInstall = !$this->isFinishedInstallation();
        $view->errorMessage = $message;
        $view->showNextStep = $view->newInstall;
        return $view->render();
    }

    /**
     * Installation Step 2: System Check
     */
    function systemCheck()
    {
        $this->checkPiwikIsNotInstalled();

        $view = new View(
            '@Installation/systemCheck',
            $this->getInstallationSteps(),
            __FUNCTION__
        );

        $view->duringInstall = true;

        $this->setupSystemCheckView($view);

        $view->showNextStep = !$view->problemWithSomeDirectories
            && $view->infos['phpVersion_ok']
            && count($view->infos['adapters'])
            && !count($view->infos['missing_extensions'])
            && !count($view->infos['missing_functions']);

        // On the system check page, if all is green, display Next link at the top
        $view->showNextStepAtTop = $view->showNextStep;

        return $view->render();
    }

    /**
     * Installation Step 3: Database Set-up
     * @throws Exception|Zend_Db_Adapter_Exception
     */
    function databaseSetup()
    {
        $this->checkPiwikIsNotInstalled();

        $view = new View(
            '@Installation/databaseSetup',
            $this->getInstallationSteps(),
            __FUNCTION__
        );

        $view->showNextStep = false;

        $form = new FormDatabaseSetup();

        if ($form->validate()) {
            try {
                $dbInfos = $form->createDatabaseObject();

                DbHelper::checkDatabaseVersion();

                Db::get()->checkClientVersion();

                $this->deleteConfigFileIfNeeded();
                $this->createConfigFile($dbInfos);

                $this->redirectToNextStep(__FUNCTION__);
            } catch (Exception $e) {
                $view->errorMessage = Common::sanitizeInputValue($e->getMessage());
            }
        }
        $view->addForm($form);

        return $view->render();
    }

    /**
     * Installation Step 4: Table Creation
     */
    function tablesCreation()
    {
        $this->checkPiwikIsNotInstalled();

        $view = new View(
            '@Installation/tablesCreation',
            $this->getInstallationSteps(),
            __FUNCTION__
        );

        if ($this->getParam('deleteTables')) {
            Db::dropAllTables();
            $view->existingTablesDeleted = true;
        }

        $tablesInstalled = DbHelper::getTablesInstalled();
        $view->tablesInstalled = '';

        if (count($tablesInstalled) > 0) {

            // we have existing tables
            $view->tablesInstalled     = implode(', ', $tablesInstalled);
            $view->someTablesInstalled = true;

            Access::getInstance();
            Piwik::setUserHasSuperUserAccess();
            if ($this->hasEnoughTablesToReuseDb($tablesInstalled) &&
                count(APISitesManager::getInstance()->getAllSitesId()) > 0 &&
                count(APIUsersManager::getInstance()->getUsers()) > 0
            ) {
                $view->showReuseExistingTables = true;
            }
        } else {

            Manager::getInstance()->clearPluginsInstalledConfig();
            DbHelper::createTables();
            DbHelper::createAnonymousUser();

            Updater::recordComponentSuccessfullyUpdated('core', Version::VERSION);
            $view->tablesCreated = true;
            $view->showNextStep = true;
        }

        return $view->render();
    }

    function reuseTables()
    {
        $this->checkPiwikIsNotInstalled();

        $steps = $this->getInstallationSteps();
        $steps['tablesCreation'] = 'Installation_ReusingTables';

        $view = new View(
            '@Installation/reuseTables',
            $steps,
            'tablesCreation'
        );

        Access::getInstance();
        Piwik::setUserHasSuperUserAccess();

        $updater = new Updater();
        $componentsWithUpdateFile = CoreUpdater::getComponentUpdates($updater);

        if (empty($componentsWithUpdateFile)) {
            return $this->redirectToNextStep('tablesCreation');
        }

        $oldVersion = Option::get('version_core');

        $result = CoreUpdater::updateComponents($updater, $componentsWithUpdateFile);

        $view->coreError       = $result['coreError'];
        $view->warningMessages = $result['warnings'];
        $view->errorMessages   = $result['errors'];
        $view->deactivatedPlugins = $result['deactivatedPlugins'];
        $view->currentVersion  = Version::VERSION;
        $view->oldVersion  = $oldVersion;
        $view->showNextStep = true;

        return $view->render();
    }

    /**
     * Installation Step 5: General Set-up (superuser login/password/email and subscriptions)
     */
    function generalSetup()
    {
        $this->checkPiwikIsNotInstalled();

        $view = new View(
            '@Installation/generalSetup',
            $this->getInstallationSteps(),
            __FUNCTION__
        );

        $form = new FormGeneralSetup();

        if ($form->validate()) {

            try {
                $this->createSuperUser($form->getSubmitValue('login'),
                                       $form->getSubmitValue('password'),
                                       $form->getSubmitValue('email'));

                $email = $form->getSubmitValue('email');
                $newsletterSecurity = $form->getSubmitValue('subscribe_newsletter_security');
                $newsletterCommunity = $form->getSubmitValue('subscribe_newsletter_community');
                $this->registerNewsletter($email, $newsletterSecurity, $newsletterCommunity);
                $this->redirectToNextStep(__FUNCTION__);

            } catch (Exception $e) {
                $view->errorMessage = $e->getMessage();
            }
        }

        $view->addForm($form);

        return $view->render();
    }

    /**
     * Installation Step 6: Configure first web-site
     */
    public function firstWebsiteSetup()
    {
        $this->checkPiwikIsNotInstalled();

        $this->initObjectsToCallAPI();

        if(count(API::getInstance()->getAllSitesId()) > 0) {
            // if there is a already a website, skip this step and trackingCode step
            $this->redirectToNextStep('trackingCode');
        }

        $view = new View(
            '@Installation/firstWebsiteSetup',
            $this->getInstallationSteps(),
            __FUNCTION__
        );

        $form = new FormFirstWebsiteSetup();

        if ($form->validate()) {
            $name = Common::unsanitizeInputValue($form->getSubmitValue('siteName'));
            $url = Common::unsanitizeInputValue($form->getSubmitValue('url'));
            $ecommerce = (int)$form->getSubmitValue('ecommerce');

            try {
                $result = APISitesManager::getInstance()->addSite($name, $url, $ecommerce);
                $params = array(
                    'site_idSite' => $result,
                    'site_name' => urlencode($name)
                );
                $this->addTrustedHosts($url);

                $this->redirectToNextStep(__FUNCTION__, $params);
            } catch (Exception $e) {
                $view->errorMessage = $e->getMessage();
            }
        } else {
            $view->displayGeneralSetupSuccess = true;
        }
        $view->addForm($form);
        return $view->render();
    }

    /**
     * Installation Step 7: Display JavaScript tracking code
     */
    public function trackingCode()
    {
        $this->checkPiwikIsNotInstalled();

        $view = new View(
            '@Installation/trackingCode',
            $this->getInstallationSteps(),
            __FUNCTION__
        );

        $siteName = Common::unsanitizeInputValue($this->getParam('site_name'));
        $idSite = $this->getParam('site_idSite');

        // Load the Tracking code and help text from the SitesManager
        $viewTrackingHelp = new \Piwik\View('@SitesManager/_displayJavascriptCode');
        $viewTrackingHelp->displaySiteName = $siteName;
        $viewTrackingHelp->jsTag = Piwik::getJavascriptCode($idSite, Url::getCurrentUrlWithoutFileName());
        $viewTrackingHelp->idSite = $idSite;
        $viewTrackingHelp->piwikUrl = Url::getCurrentUrlWithoutFileName();

        $view->trackingHelp = $viewTrackingHelp->render();
        $view->displaySiteName = $siteName;

        $view->displayfirstWebsiteSetupSuccess = true;
        $view->showNextStep = true;

        return $view->render();
    }

    /**
     * Installation Step 8: Finished!
     */
    public function finished()
    {
        $view = new View(
            '@Installation/finished',
            $this->getInstallationSteps(),
            __FUNCTION__
        );

        if (!$this->isFinishedInstallation()) {
            $this->markInstallationAsCompleted();
        }

        $view->showNextStep = false;
        $output = $view->render();

        return $output;
    }

    /**
     * Get system information
     */
    public static function getSystemInformation()
    {
        $systemCheck = new SystemCheck();
        return $systemCheck->getSystemInformation();
    }

    /**
     * This controller action renders an admin tab that runs the installation
     * system check, so people can see if there are any issues w/ their running
     * Piwik installation.
     *
     * This admin tab is only viewable by the Super User.
     */
    public function systemCheckPage()
    {
        Piwik::checkUserHasSuperUserAccess();

        $view = new View(
            '@Installation/systemCheckPage',
            $this->getInstallationSteps(),
            __FUNCTION__
        );
        $this->setBasicVariablesView($view);

        $view->duringInstall = false;

        $this->setupSystemCheckView($view);

        $infos = $view->infos;
        $infos['extra'] = SystemCheck::performAdminPageOnlySystemCheck();
        $view->infos = $infos;

        return $view->render();
    }

    /**
     * Save language selection in session-store
     */
    public function saveLanguage()
    {
        $language = $this->getParam('language');
        LanguagesManager::setLanguageForSession($language);
        Url::redirectToReferrer();
    }

    /**
     * Prints out the CSS for installer/updater
     *
     * During installation and update process, we load a minimal Less file.
     * At this point Piwik may not be setup yet to write files in tmp/assets/
     * so in this case we compile and return the string on every request.
     */
    public function getBaseCss()
    {
        @header('Content-Type: text/css');
        return AssetManager::getInstance()->getCompiledBaseCss()->getContent();
    }

    private function getParam($name)
    {
        return Common::getRequestVar($name, false, 'string');
    }

    /**
     * Instantiate access and log objects
     */
    private function initObjectsToCallAPI()
    {
        Piwik::setUserHasSuperUserAccess();
    }

    /**
     * Write configuration file from session-store
     */
    private function createConfigFile($dbInfos)
    {
        $config = Config::getInstance();

        // make sure DB sessions are used if the filesystem is NFS
        if (Filesystem::checkIfFileSystemIsNFS()) {
            $config->General['session_save_handler'] = 'dbtable';
        }
        if (count($headers = ProxyHeaders::getProxyClientHeaders()) > 0) {
            $config->General['proxy_client_headers'] = $headers;
        }
        if (count($headers = ProxyHeaders::getProxyHostHeaders()) > 0) {
            $config->General['proxy_host_headers'] = $headers;
        }
        $config->General['salt'] = Common::generateUniqId();
        $config->General['installation_in_progress'] = 1;

        $config->database = $dbInfos;
        if (!DbHelper::isDatabaseConnectionUTF8()) {
            $config->database['charset'] = 'utf8';
        }
        $config->forceSave();

    }

    private function checkPiwikIsNotInstalled()
    {
        if(!$this->isFinishedInstallation()) {
            return;
        }
        \Piwik\Plugins\Login\Controller::clearSession();
        $message = Piwik::translate('Installation_InvalidStateError',
            array('<br /><strong>',
                  '</strong>',
                  '<a href=\'' . Common::sanitizeInputValue(Url::getCurrentUrlWithoutFileName()) . '\'>',
                  '</a>')
        );
        Piwik::exitWithErrorMessage($message);
    }

    /**
     * Write configuration file from session-store
     */
    private function markInstallationAsCompleted()
    {
        $config = Config::getInstance();
        unset($config->General['installation_in_progress']);
        $config->forceSave();
    }

    /**
     * Redirect to next step
     *
     * @param string $currentStep Current step
     * @return void
     */
    private function redirectToNextStep($currentStep, $parameters = array())
    {
        $steps = array_keys($this->steps);
        $nextStep = $steps[1 + array_search($currentStep, $steps)];
        Piwik::redirectToModule('Installation', $nextStep, $parameters);
    }


    /**
     * Extract host from URL
     *
     * @param string $url URL
     *
     * @return string|false
     */
    private function extractHost($url)
    {
        $urlParts = parse_url($url);
        if (isset($urlParts['host']) && strlen($host = $urlParts['host'])) {
            return $host;
        }

        return false;
    }

    /**
     * Add trusted hosts
     */
    private function addTrustedHosts($siteUrl)
    {
        $trustedHosts = array();

        // extract host from the request header
        if (($host = $this->extractHost('http://' . Url::getHost())) !== false) {
            $trustedHosts[] = $host;
        }

        // extract host from first web site
        if (($host = $this->extractHost(urldecode($siteUrl))) !== false) {
            $trustedHosts[] = $host;
        }

        $trustedHosts = array_unique($trustedHosts);
        if (count($trustedHosts)) {

            $general = Config::getInstance()->General;
            $general['trusted_hosts'] = $trustedHosts;
            Config::getInstance()->General = $general;

            Config::getInstance()->forceSave();
        }
    }

    /**
     * Utility function, sets up a view that will display system check info.
     *
     * @param View $view
     */
    private function setupSystemCheckView($view)
    {
        $view->infos = self::getSystemInformation();

        $view->helpMessages = array(
            'zlib'            => 'Installation_SystemCheckZlibHelp',
            'SPL'             => 'Installation_SystemCheckSplHelp',
            'iconv'           => 'Installation_SystemCheckIconvHelp',
            'mbstring'        => 'Installation_SystemCheckMbstringHelp',
            'Reflection'      => 'Required extension that is built in PHP, see http://www.php.net/manual/en/book.reflection.php',
            'json'            => 'Installation_SystemCheckWarnJsonHelp',
            'libxml'          => 'Installation_SystemCheckWarnLibXmlHelp',
            'dom'             => 'Installation_SystemCheckWarnDomHelp',
            'SimpleXML'       => 'Installation_SystemCheckWarnSimpleXMLHelp',
            'set_time_limit'  => 'Installation_SystemCheckTimeLimitHelp',
            'mail'            => 'Installation_SystemCheckMailHelp',
            'parse_ini_file'  => 'Installation_SystemCheckParseIniFileHelp',
            'glob'            => 'Installation_SystemCheckGlobHelp',
            'debug_backtrace' => 'Installation_SystemCheckDebugBacktraceHelp',
            'create_function' => 'Installation_SystemCheckCreateFunctionHelp',
            'eval'            => 'Installation_SystemCheckEvalHelp',
            'gzcompress'      => 'Installation_SystemCheckGzcompressHelp',
            'gzuncompress'    => 'Installation_SystemCheckGzuncompressHelp',
            'pack'            => 'Installation_SystemCheckPackHelp',
            'php5-json'       => 'Installation_SystemCheckJsonHelp',
        );

        $view->problemWithSomeDirectories = (false !== array_search(false, $view->infos['directories']));
    }


    private function createSuperUser($login, $password, $email)
    {
        $this->initObjectsToCallAPI();

        $api = APIUsersManager::getInstance();
        $api->addUser($login, $password, $email);

        $this->initObjectsToCallAPI();
        $api->setSuperUserAccess($login, true);
    }

    private function isFinishedInstallation()
    {
        if (!SettingsPiwik::isPiwikInstalled()) {
            return false;
        }

        $general = Config::getInstance()->General;

        $isInstallationInProgress = false;
        if (array_key_exists('installation_in_progress', $general)) {
            $isInstallationInProgress = (bool) $general['installation_in_progress'];
        }

        return !$isInstallationInProgress;
    }

    private function hasEnoughTablesToReuseDb($tablesInstalled)
    {
        if (empty($tablesInstalled) || !is_array($tablesInstalled)) {
            return false;
        }

        $archiveTables       = ArchiveTableCreator::getTablesArchivesInstalled();
        $baseTablesInstalled = count($tablesInstalled) - count($archiveTables);
        $minimumCountPiwikTables = 12;

        return $baseTablesInstalled >= $minimumCountPiwikTables;
    }

    private function deleteConfigFileIfNeeded()
    {
        $config = Config::getInstance();
        if($config->existsLocalConfig()) {
            $config->deleteLocalConfig();
        }
    }

    /**
     * @param $email
     * @param $newsletterSecurity
     * @param $newsletterCommunity
     */
    protected function registerNewsletter($email, $newsletterSecurity, $newsletterCommunity)
    {
        $url = Config::getInstance()->General['api_service_url'];
        $url .= '/1.0/subscribeNewsletter/';
        $params = array(
            'email'     => $email,
            'security'  => $newsletterSecurity,
            'community' => $newsletterCommunity,
            'url'       => Url::getCurrentUrlWithoutQueryString(),
        );
        if ($params['security'] == '1'
            || $params['community'] == '1'
        ) {
            if (!isset($params['security'])) {
                $params['security'] = '0';
            }
            if (!isset($params['community'])) {
                $params['community'] = '0';
            }
            $url .= '?' . http_build_query($params, '', '&');
            try {
                Http::sendHttpRequest($url, $timeout = 2);
            } catch (Exception $e) {
                // e.g., disable_functions = fsockopen; allow_url_open = Off
            }
        }
    }

}
