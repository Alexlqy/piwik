<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Piwik
 */
namespace Piwik;

use Exception;
use Piwik\Tracker\Cache;
use Piwik\Tracker\Db\DbException;
use Piwik\Tracker\Db\Mysqli;
use Piwik\Tracker\Db\Pdo\Mysql;
use Piwik\Tracker\Request;
use Piwik\Tracker\Visit;
use Piwik\Tracker\VisitInterface;

/**
 * Class used by the logging script piwik.php called by the javascript tag.
 * Handles the visitor & his/her actions on the website, saves the data in the DB,
 * saves information in the cookie, etc.
 *
 * We try to include as little files as possible (no dependency on 3rd party modules).
 *
 * @package Piwik
 * @subpackage Tracker
 */
class Tracker
{
    protected $stateValid = self::STATE_NOTHING_TO_NOTICE;
    /**
     * @var Db
     */
    protected static $db = null;

    const STATE_NOTHING_TO_NOTICE = 1;
    const STATE_LOGGING_DISABLE = 10;
    const STATE_EMPTY_REQUEST = 11;
    const STATE_NOSCRIPT_REQUEST = 13;

    // We use hex ID that are 16 chars in length, ie. 64 bits IDs
    const LENGTH_HEX_ID_STRING = 16;
    const LENGTH_BINARY_ID = 8;

    // These are also hardcoded in the Javascript
    const MAX_CUSTOM_VARIABLES = 5;
    const MAX_LENGTH_CUSTOM_VARIABLE = 200;

    static protected $forcedDateTime = null;
    static protected $forcedIpString = null;
    static protected $forcedVisitorId = null;

    static protected $pluginsNotToLoad = array();
    static protected $pluginsToLoad = array();

    /**
     * The set of visits to track.
     *
     * @var array
     */
    private $requests = array();

    /**
     * The token auth supplied with a bulk visits POST.
     *
     * @var string
     */
    private $tokenAuth = null;

    /**
     * Whether we're currently using bulk tracking or not.
     *
     * @var bool
     */
    private $usingBulkTracking = false;

    /**
     * The number of requests that have been successfully logged.
     *
     * @var int
     */
    private $countOfLoggedRequests = 0;

    protected function outputAccessControlHeaders()
    {
        $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        if ($requestMethod !== 'GET') {
            $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*';
            Common::sendHeader('Access-Control-Allow-Origin: ' . $origin);
            Common::sendHeader('Access-Control-Allow-Credentials: true');
        }
    }

    public function clear()
    {
        self::$forcedIpString = null;
        self::$forcedDateTime = null;
        self::$forcedVisitorId = null;
        $this->stateValid = self::STATE_NOTHING_TO_NOTICE;
    }

    public static function setForceIp($ipString)
    {
        self::$forcedIpString = $ipString;
    }

    public static function setForceDateTime($dateTime)
    {
        self::$forcedDateTime = $dateTime;
    }

    public static function setForceVisitorId($visitorId)
    {
        self::$forcedVisitorId = $visitorId;
    }

    /**
     * Do not load the specified plugins (used during testing, to disable Provider plugin)
     * @param array $plugins
     */
    static public function setPluginsNotToLoad($plugins)
    {
        self::$pluginsNotToLoad = $plugins;
    }

    /**
     * Get list of plugins to not load
     *
     * @return array
     */
    static public function getPluginsNotToLoad()
    {
        return self::$pluginsNotToLoad;
    }

    /**
     * @return array
     */
    static public function getPluginsToLoad()
    {
        return self::$pluginsToLoad;
    }

    /**
     * @param array $plugins
     */
    static public function setPluginsToLoad($plugins)
    {
        self::$pluginsToLoad = $plugins;
    }

    /**
     * Update Tracker config
     *
     * @param string $name Setting name
     * @param mixed $value Value
     */
    static private function updateTrackerConfig($name, $value)
    {
        $section = Config::getInstance()->Tracker;
        $section[$name] = $value;
        Config::getInstance()->Tracker = $section;
    }

    protected function initRequests($args)
    {
        $rawData = file_get_contents("php://input");
        if (!empty($rawData)) {
            $this->usingBulkTracking = strpos($rawData, '"requests"') || strpos($rawData, "'requests'");
            if ($this->usingBulkTracking) {
                return $this->authenticateBulkTrackingRequests($rawData);
            }
        }

        // Not using bulk tracking
        $this->requests = $args ? $args : (!empty($_GET) || !empty($_POST) ? array($_GET + $_POST) : array());
    }

    private function authenticateBulkTrackingRequests($rawData)
    {
        $rawData = trim($rawData);
        $rawData = Common::sanitizeLineBreaks($rawData);

        // POST data can be array of string URLs or array of arrays w/ visit info
        $jsonData = json_decode($rawData, $assoc = true);

        if (isset($jsonData['requests'])) {
            $this->requests = $jsonData['requests'];
        }
        $tokenAuth = Common::getRequestVar('token_auth', false, null, $jsonData);
        if (empty($tokenAuth)) {
            throw new Exception("token_auth must be specified when using Bulk Tracking Import. See <a href='http://piwik.org/docs/tracking-api/reference/'>Tracking Doc</a>");
        }
        if (!empty($this->requests)) {
            $idSitesForAuthentication = array();

            foreach ($this->requests as &$request) {
                // if a string is sent, we assume its a URL and try to parse it
                if (is_string($request)) {
                    $params = array();

                    $url = @parse_url($request);
                    if (!empty($url)) {
                        @parse_str($url['query'], $params);
                        $request = $params;
                    }
                }

                // We need to check access for each single request
                if (isset($request['idsite'])
                    && !in_array($request['idsite'], $idSitesForAuthentication)
                ) {
                    $idSitesForAuthentication[] = $request['idsite'];
                }
            }

            foreach ($idSitesForAuthentication as $idSiteForAuthentication) {
                // a Bulk Tracking request that is not authenticated should fail
                if (!Request::authenticateSuperUserOrAdmin($tokenAuth, $idSiteForAuthentication)) {
                    throw new Exception("token_auth specified does not have Admin permission for site " . intval($idSiteForAuthentication));
                }
            }
        }
        return $tokenAuth;
    }

    /**
     * Main - tracks the visit/action
     *
     * @param array $args Optional Request Array
     */
    public function main($args = null)
    {
        try {
            $tokenAuth = $this->initRequests($args);
        } catch (Exception $ex) {
            $this->exitWithException($ex, true);
        }

        $this->initOutputBuffer();

        if (!empty($this->requests)) {
            foreach ($this->requests as $params) {
                $request = new Request($params, $tokenAuth);
                $isAuthenticated = $request->isAuthenticated();
                $this->init($request);

                try {
                    if ($this->isVisitValid()) {

                        self::connectDatabaseIfNotConnected();

                        $visit = $this->getNewVisitObject();
                        $request->setForcedVisitorId(self::$forcedVisitorId);
                        $request->setForceDateTime(self::$forcedDateTime);
                        $request->setForceIp(self::$forcedIpString);

                        $visit->setRequest($request);
                        $visit->handle();
                        unset($visit);
                    } else {
                        Common::printDebug("The request is invalid: empty request, or maybe tracking is disabled in the config.ini.php via record_statistics=0");
                    }
                } catch (DbException $e) {
                    Common::printDebug("<b>" . $e->getMessage() . "</b>");
                    $this->exitWithException($e, $isAuthenticated);
                } catch (Exception $e) {
                    $this->exitWithException($e, $isAuthenticated);
                }
                $this->clear();

                // increment successfully logged request count. make sure to do this after try-catch,
                // since an excluded visit is considered 'successfully logged'
                ++$this->countOfLoggedRequests;
            }

            // run scheduled task
            try {
                if (!$isAuthenticated // Do not run schedule task if we are importing logs or doing custom tracking (as it could slow down)
                    && $this->shouldRunScheduledTasks()
                ) {
                    self::runScheduledTasks();
                }
            } catch (Exception $e) {
                $this->exitWithException($e);
            }
        } else {
            $this->handleEmptyRequest(new Request($_GET + $_POST));
        }
        $this->end();

        $this->flushOutputBuffer();
    }

    protected function initOutputBuffer()
    {
        ob_start();
    }

    protected function flushOutputBuffer()
    {
        ob_end_flush();
    }

    protected function getOutputBuffer()
    {
        return ob_get_contents();
    }


    protected function shouldRunScheduledTasks()
    {
        // don't run scheduled tasks in CLI mode from Tracker, this is the case
        // where we bulk load logs & don't want to lose time with tasks
        return !Common::isPhpCliMode()
        && $this->getState() != self::STATE_LOGGING_DISABLE;
    }

    /**
     * Tracker requests will automatically trigger the Scheduled tasks.
     * This is useful for users who don't setup the cron,
     * but still want daily/weekly/monthly PDF reports emailed automatically.
     *
     * This is similar to calling the API CoreAdminHome.runScheduledTasks (see misc/cron/archive.php)
     */
    protected static function runScheduledTasks()
    {
        $now = time();

        // Currently, there are no hourly tasks. When there are some,
        // this could be too aggressive minimum interval (some hours would be skipped in case of low traffic)
        $minimumInterval = Config::getInstance()->Tracker['scheduled_tasks_min_interval'];

        // If the user disabled browser archiving, he has already setup a cron
        // To avoid parallel requests triggering the Scheduled Tasks,
        // Get last time tasks started executing
        $cache = Cache::getCacheGeneral();
        if ($minimumInterval <= 0
            || empty($cache['isBrowserTriggerArchivingEnabled'])
        ) {
            Common::printDebug("-> Scheduled tasks not running in Tracker: Browser archiving is disabled.");
            return;
        }
        $nextRunTime = $cache['lastTrackerCronRun'] + $minimumInterval;
        if ((isset($GLOBALS['PIWIK_TRACKER_DEBUG_FORCE_SCHEDULED_TASKS']) && $GLOBALS['PIWIK_TRACKER_DEBUG_FORCE_SCHEDULED_TASKS'])
            || $cache['lastTrackerCronRun'] === false
            || $nextRunTime < $now
        ) {
            $cache['lastTrackerCronRun'] = $now;
            Cache::setCacheGeneral($cache);
            self::initCorePiwikInTrackerMode();
            Option::set('lastTrackerCronRun', $cache['lastTrackerCronRun']);
            Common::printDebug('-> Scheduled Tasks: Starting...');

            // save current user privilege and temporarily assume super user privilege
            $isSuperUser = Piwik::isUserIsSuperUser();

            // Scheduled tasks assume Super User is running
            Piwik::setUserIsSuperUser();

            // While each plugins should ensure that necessary languages are loaded,
            // we ensure English translations at least are loaded
            Translate::loadEnglishTranslation();

            $resultTasks = TaskScheduler::runTasks();

            // restore original user privilege
            Piwik::setUserIsSuperUser($isSuperUser);

            Common::printDebug($resultTasks);
            Common::printDebug('Finished Scheduled Tasks.');
        } else {
            Common::printDebug("-> Scheduled tasks not triggered.");
        }
        Common::printDebug("Next run will be from: " . date('Y-m-d H:i:s', $nextRunTime) . ' UTC');
    }

    static public $initTrackerMode = false;

    /**
     * Used to initialize core Piwik components on a piwik.php request
     * Eg. when cache is missed and we will be calling some APIs to generate cache
     */
    static public function initCorePiwikInTrackerMode()
    {
        if (!empty($GLOBALS['PIWIK_TRACKER_MODE'])
            && self::$initTrackerMode === false
        ) {
            self::$initTrackerMode = true;
            require_once PIWIK_INCLUDE_PATH . '/core/Loader.php';
            require_once PIWIK_INCLUDE_PATH . '/core/Option.php';

            $access = Access::getInstance();
            $config = Config::getInstance();

            try {
                $db = Db::get();
            } catch (Exception $e) {
                Db::createDatabaseObject();
            }

            $pluginsManager = \Piwik\Plugin\Manager::getInstance();
            $pluginsToLoad = Config::getInstance()->Plugins['Plugins'];
            $pluginsForcedNotToLoad = Tracker::getPluginsNotToLoad();
            $pluginsToLoad = array_diff($pluginsToLoad, $pluginsForcedNotToLoad);
            $pluginsToLoad = array_merge($pluginsToLoad, Tracker::getPluginsToLoad());
            $pluginsManager->loadPlugins($pluginsToLoad);
        }
    }

    /**
     * Echos an error message & other information, then exits.
     *
     * @param Exception $e
     * @param bool $authenticated
     */
    protected function exitWithException($e, $authenticated = false)
    {
        if ($this->usingBulkTracking) {
            // when doing bulk tracking we return JSON so the caller will know how many succeeded
            $result = array(
                'status'  => 'error',
                'tracked' => $this->countOfLoggedRequests
            );
            // send error when in debug mode or when authenticated (which happens when doing log importing,
            if ((isset($GLOBALS['PIWIK_TRACKER_DEBUG']) && $GLOBALS['PIWIK_TRACKER_DEBUG'])
                || $authenticated
            ) {
                $result['message'] = $this->getMessageFromException($e);
            }
            Common::sendHeader('Content-Type: application/json');
            echo Common::json_encode($result);
            exit;
        }

        if (isset($GLOBALS['PIWIK_TRACKER_DEBUG']) && $GLOBALS['PIWIK_TRACKER_DEBUG']) {
            Common::sendHeader('Content-Type: text/html; charset=utf-8');
            $trailer = '<span style="color: #888888">Backtrace:<br /><pre>' . $e->getTraceAsString() . '</pre></span>';
            $headerPage = file_get_contents(PIWIK_INCLUDE_PATH . '/plugins/Zeitgeist/templates/simpleLayoutHeader.tpl');
            $footerPage = file_get_contents(PIWIK_INCLUDE_PATH . '/plugins/Zeitgeist/templates/simpleLayoutFooter.tpl');
            $headerPage = str_replace('{$HTML_TITLE}', 'Piwik &rsaquo; Error', $headerPage);

            echo $headerPage . '<p>' . $this->getMessageFromException($e) . '</p>' . $trailer . $footerPage;
        } // If not debug, but running authenticated (eg. during log import) then we display raw errors
        elseif ($authenticated) {
            Common::sendHeader('Content-Type: text/html; charset=utf-8');
            echo $this->getMessageFromException($e);
        } else {
            $this->outputTransparentGif();
        }
        exit;
    }

    /**
     * Returns the date in the "Y-m-d H:i:s" PHP format
     *
     * @param int $timestamp
     * @return string
     */
    public static function getDatetimeFromTimestamp($timestamp)
    {
        return date("Y-m-d H:i:s", $timestamp);
    }

    /**
     * Initialization
     */
    protected function init(Request $request)
    {
        $this->handleTrackingApi($request);
        $this->loadTrackerPlugins($request);
        $this->handleDisabledTracker();
        $this->handleEmptyRequest($request);

        Common::printDebug("Current datetime: " . date("Y-m-d H:i:s", $request->getCurrentTimestamp()));
    }

    /**
     * Cleanup
     */
    protected function end()
    {
        if ($this->usingBulkTracking) {
            $result = array(
                'status'  => 'success',
                'tracked' => $this->countOfLoggedRequests
            );
            Common::sendHeader('Content-Type: application/json');
            echo Common::json_encode($result);
            exit;
        }
        switch ($this->getState()) {
            case self::STATE_LOGGING_DISABLE:
                $this->outputTransparentGif();
                Common::printDebug("Logging disabled, display transparent logo");
                break;

            case self::STATE_EMPTY_REQUEST:
                Common::printDebug("Empty request => Piwik page");
                echo "<a href='/'>Piwik</a> is a free open source web <a href='http://piwik.org'>analytics</a> that lets you keep control of your data.";
                break;

            case self::STATE_NOSCRIPT_REQUEST:
            case self::STATE_NOTHING_TO_NOTICE:
            default:
                $this->outputTransparentGif();
                Common::printDebug("Nothing to notice => default behaviour");
                break;
        }
        Common::printDebug("End of the page.");

        if ($GLOBALS['PIWIK_TRACKER_DEBUG'] === true) {
            if (isset(self::$db)) {
                self::$db->recordProfiling();
                Profiler::displayDbTrackerProfile(self::$db);
            }
        }

        self::disconnectDatabase();
    }

    /**
     * Factory to create database objects
     *
     * @param array $configDb Database configuration
     * @throws Exception
     * @return \Piwik\Tracker\Db\Mysqli|\Piwik\Tracker\Db\Pdo\Mysql
     */
    public static function factory($configDb)
    {
        switch ($configDb['adapter']) {
            case 'PDO\MYSQL':
            case 'PDO_MYSQL': // old format pre Piwik 2
                require_once PIWIK_INCLUDE_PATH . '/core/Tracker/Db/Pdo/Mysql.php';
                return new Mysql($configDb);

            case 'MYSQLI':
                require_once PIWIK_INCLUDE_PATH . '/core/Tracker/Db/Mysqli.php';
                return new Mysqli($configDb);
        }

        throw new Exception('Unsupported database adapter ' . $configDb['adapter']);
    }

    public static function connectPiwikTrackerDb()
    {
        $db = null;
        $configDb = Config::getInstance()->database;

        if (!isset($configDb['port'])) {
            // before 0.2.4 there is no port specified in config file
            $configDb['port'] = '3306';
        }

        /**
         * Triggered before a connection to the database is established in the Tracker.
         * 
         * This event can be used to dynamically change the settings used to connect to the
         * database.
         * 
         * @param array $dbInfos Reference to an array containing database connection info,
         *                       including:
         *                       - **host**: The host name or IP address to the MySQL database.
         *                       - **username**: The username to use when connecting to the
         *                                       database.
         *                       - **password**: The password to use when connecting to the
         *                                       database.
         *                       - **dbname**: The name of the Piwik MySQL database.
         *                       - **port**: The MySQL database port to use.
         *                       - **adapter**: either `'PDO_MYSQL'` or `'MYSQLI'`
         */
        Piwik::postEvent('Tracker.getDatabaseConfig', array(&$configDb));

        $db = Tracker::factory($configDb);
        $db->connect();

        return $db;
    }

    public static function connectDatabaseIfNotConnected()
    {
        if (!is_null(self::$db)) {
            return;
        }

        try {
            self::$db = self::connectPiwikTrackerDb();
        } catch (Exception $e) {
            throw new DbException($e->getMessage(), $e->getCode());
        }
    }

    /**
     * @return Db
     */
    public static function getDatabase()
    {
        return self::$db;
    }

    public static function disconnectDatabase()
    {
        if (isset(self::$db)) {
            self::$db->disconnect();
            self::$db = null;
        }
    }

    /**
     * Returns the Tracker_Visit object.
     * This method can be overwritten to use a different Tracker_Visit object
     *
     * @throws Exception
     * @return \Piwik\Tracker\Visit
     */
    protected function getNewVisitObject()
    {
        $visit = null;

        /**
         * Triggered before a new `Piwik\Tracker\Visit` object is created. Subscribers to this
         * event can force the use of a custom visit object that extends from
         * [Piwik\Tracker\VisitInterface](#).
         * 
         * @param Piwik\Tracker\VisitInterface &$visit Initialized to null, but can be set to
         *                                             a created Visit object. If it isn't
         *                                             modified Piwik uses the default class.
         */
        Piwik::postEvent('Tracker.makeNewVisitObject', array(&$visit));

        if (is_null($visit)) {
            $visit = new Visit();
        } elseif (!($visit instanceof VisitInterface)) {
            throw new Exception("The Visit object set in the plugin must implement VisitInterface");
        }
        return $visit;
    }

    protected function outputTransparentGif()
    {
        if (isset($GLOBALS['PIWIK_TRACKER_DEBUG'])
            && $GLOBALS['PIWIK_TRACKER_DEBUG']
        ) {
            return;
        }

        if (strlen($this->getOutputBuffer()) > 0) {
            // If there was an error during tracker, return so errors can be flushed
            return;
        }
        $transGifBase64 = "R0lGODlhAQABAIAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==";
        Common::sendHeader('Content-Type: image/gif');

        $this->outputAccessControlHeaders();

        print(base64_decode($transGifBase64));
    }

    protected function isVisitValid()
    {
        return $this->stateValid !== self::STATE_LOGGING_DISABLE
        && $this->stateValid !== self::STATE_EMPTY_REQUEST;
    }

    protected function getState()
    {
        return $this->stateValid;
    }

    protected function setState($value)
    {
        $this->stateValid = $value;
    }

    protected function loadTrackerPlugins(Request $request)
    {
        // Adding &dp=1 will disable the provider plugin, if token_auth is used (used to speed up bulk imports)
        $disableProvider = $request->getParam('dp');
        if (!empty($disableProvider) && $request->isAuthenticated()) {
            Tracker::setPluginsNotToLoad(array('Provider'));
        }

        try {
            $pluginsTracker = Config::getInstance()->Plugins_Tracker['Plugins_Tracker'];
            if (count($pluginsTracker) > 0) {
                $pluginsTracker = array_diff($pluginsTracker, self::getPluginsNotToLoad());
                \Piwik\Plugin\Manager::getInstance()->doNotLoadAlwaysActivatedPlugins();

                \Piwik\Plugin\Manager::getInstance()->loadPlugins($pluginsTracker);

                Common::printDebug("Loading plugins: { " . implode(",", $pluginsTracker) . " }");
            }
        } catch (Exception $e) {
            Common::printDebug("ERROR: " . $e->getMessage());
        }
    }

    protected function handleEmptyRequest(Request $request)
    {
        $countParameters = $request->getParamsCount();
        if ($countParameters == 0) {
            $this->setState(self::STATE_EMPTY_REQUEST);
        }
        if ($countParameters == 1) {
            $this->setState(self::STATE_NOSCRIPT_REQUEST);
        }
    }

    protected function handleDisabledTracker()
    {
        $saveStats = Config::getInstance()->Tracker['record_statistics'];
        if ($saveStats == 0) {
            $this->setState(self::STATE_LOGGING_DISABLE);
        }
    }

    protected function getTokenAuth()
    {
        if (!is_null($this->tokenAuth)) {
            return $this->tokenAuth;
        }

        return Common::getRequestVar('token_auth', false);
    }

    /**
     * This method allows to set custom IP + server time + visitor ID, when using Tracking API.
     * These two attributes can be only set by the Super User (passing token_auth).
     */
    protected function handleTrackingApi(Request $request)
    {
        if (!$request->isAuthenticated()) {
            return;
        }

        // Custom IP to use for this visitor
        $customIp = $request->getParam('cip');
        if (!empty($customIp)) {
            $this->setForceIp($customIp);
        }

        // Custom server date time to use
        $customDatetime = $request->getParam('cdt');
        if (!empty($customDatetime)) {
            $this->setForceDateTime($customDatetime);
        }

        // Forced Visitor ID to record the visit / action
        $customVisitorId = $request->getParam('cid');
        if (!empty($customVisitorId)) {
            $this->setForceVisitorId($customVisitorId);
        }
    }

    public static function setTestEnvironment($args = null, $requestMethod = null)
    {
        if (is_null($args)) {
            $args = $_GET + $_POST;
        }
        if (is_null($requestMethod)) {
            $requestMethod = $_SERVER['REQUEST_METHOD'];
        }

        // Do not run scheduled tasks during tests
        self::updateTrackerConfig('scheduled_tasks_min_interval', 0);

        // if nothing found in _GET/_POST and we're doing a POST, assume bulk request. in which case,
        // we have to bypass authentication
        if (empty($args) && $requestMethod == 'POST') {
            self::updateTrackerConfig('tracking_requests_require_authentication', 0);
        }

        // Tests can force the use of 3rd party cookie for ID visitor
        if (Common::getRequestVar('forceUseThirdPartyCookie', false, null, $args) == 1) {
            self::updateTrackerConfig('use_third_party_id_cookie', 1);
        }

        // Tests using window_look_back_for_visitor
        if (Common::getRequestVar('forceLargeWindowLookBackForVisitor', false, null, $args) == 1) {
            self::updateTrackerConfig('window_look_back_for_visitor', 2678400);
        }

        // Tests can force the enabling of IP anonymization
        $forceIpAnonymization = false;
        if (Common::getRequestVar('forceIpAnonymization', false, null, $args) == 1) {
            self::updateTrackerConfig('ip_address_mask_length', 2);

            $section = Config::getInstance()->Plugins_Tracker;
            $section['Plugins_Tracker'][] = "AnonymizeIP";
            Config::getInstance()->Plugins_Tracker = $section;

            $forceIpAnonymization = true;
        }

        // Custom IP to use for this visitor
        $customIp = Common::getRequestVar('cip', false, null, $args);
        if (!empty($customIp)) {
            self::setForceIp($customIp);
        }

        // Custom server date time to use
        $customDatetime = Common::getRequestVar('cdt', false, null, $args);
        if (!empty($customDatetime)) {
            self::setForceDateTime($customDatetime);
        }

        // Custom visitor id
        $customVisitorId = Common::getRequestVar('cid', false, null, $args);
        if (!empty($customVisitorId)) {
            self::setForceVisitorId($customVisitorId);
        }
        $pluginsDisabled = array('Provider');
        if (!$forceIpAnonymization) {
            $pluginsDisabled[] = 'AnonymizeIP';
        }

        // Disable provider plugin, because it is so slow to do many reverse ip lookups
        self::setPluginsNotToLoad($pluginsDisabled);

        // we load 'DevicesDetection' in tests only (disabled by default)
        self::setPluginsToLoad(array('DevicesDetection'));
    }

    /**
     * Gets the error message to output when a tracking request fails.
     *
     * @param Exception $e
     * @return string
     */
    private function getMessageFromException($e)
    {
        // Note: duplicated from FormDatabaseSetup.isAccessDenied
        // Avoid leaking the username/db name when access denied
        if ($e->getCode() == 1044 || $e->getCode() == 42000) {
            return "Error while connecting to the Piwik database - please check your credentials in config/config.ini.php file";
        } else {
            return $e->getMessage();
        }
    }
}
