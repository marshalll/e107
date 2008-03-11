<?php
/*
+ ----------------------------------------------------------------------------+
|     e107 website system
|
|    	Steve Dunstan 2001-2002
|     http://e107.org
|     jalist@e107.org
|
|     Released under the terms and conditions of the
|     GNU General Public License (http://gnu.org).
|
|     $Source: /cvs_backup/e107_0.8/class2.php,v $
|     $Revision: 1.50 $
|     $Date: 2008-03-11 01:47:01 $
|     $Author: mcfly_e107 $
+----------------------------------------------------------------------------+
*/
//
// *** Code sequence for startup ***
// IMPORTANT: These items are in a carefully constructed order. DO NOT REARRANGE
// without checking with experienced devs! Various subtle things WILL break.
//
// A Get the current CPU time so we know how long all of this takes
// B Remove output buffering so we are in control of text sent to user
// C Remove registered globals (SECURITY for all following code)
// D Setup PHP error handling (now we can see php errors ;))
// E Setup other PHP essentials
// F Grab e107_config to get directory paths
// G Retrieve Query from URI (i.e. what are the request parameters?!)
// H Initialize debug handling (NOTE: A-G cannot use debug tools!)
// I: Sanity check to ensure e107_config is ok
// J: MYSQL setup (NOTE: A-I cannot use database!)
// K: Compatibility mode
// L: Retrieve core prefs
// M: Subdomain and language selection
// N: Other misc setups (NOTE: Put most 'random' things here that don't require user session or theme
// O: Start user session
// P: Load theme
// Q: Other setups

//
// A: Honest global beginning point for processing time
//
$eTimingStart = microtime();					// preserve these when destroying globals in step C
if ( function_exists( 'getrusage' ) ) { $eTimingStartCPU = getrusage(); }
$oblev_before_start = ob_get_level();

//
// B: Remove all output buffering
//
if(!isset($_E107) || !is_array($_E107)) { $_E107 = array(); }
if($_E107['cli'] && !$_E107['debug'] && isset($_SERVER["HTTP_USER_AGENT"]))
{
	exit;
}
if(!$_E107['cli'])
{
	while (@ob_end_clean());  // destroy all ouput buffering
	ob_start();             // start our own.
	$oblev_at_start = ob_get_level(); 	// preserve when destroying globals in step C
}
//
// C: Find out if register globals is enabled and destroy them if so
// (DO NOT use the value of any variables before this point! They could have been set by the user)
//
$register_globals = true;
if(function_exists('ini_get')) {
	$register_globals = ini_get('register_globals');
}

// Destroy! (if we need to)
if($register_globals == true){
	if(isset($_REQUEST['_E107'])) { unset($_E107); }
	while (list($global) = each($GLOBALS)) {
		if (!preg_match('/^(_POST|_GET|_COOKIE|_SERVER|_FILES|_SESSION|GLOBALS|HTTP.*|_REQUEST|_E107|retrieve_prefs|eplug_admin|eTimingStart.*|oblev_.*)$/', $global)) {
			unset($$global);
		}
	}
	unset($global);
}


if(isset($_E107['minimal']))
{
	$_e107vars = array('forceuserupdate', 'online', 'theme', 'menus', 'prunetmp');
	foreach($_e107vars as $v)
	{
		$noname = 'no_'.$v;
		if(!isset($_E107[$v]))
		{
			$_E107[$noname] = 1;
		}
		unset($_E107[$v]);
	}
}


// e107 uses relative url's, which are broken by "pretty" URL's. So for now we don't support / after .php
if(($pos = strpos($_SERVER['PHP_SELF'], ".php/")) !== false) // redirect bad URLs to the correct one.
{
	$new_url = substr($_SERVER['PHP_SELF'], 0, $pos+4);
	$new_loc = ($_SERVER['QUERY_STRING']) ? $new_url."?".$_SERVER['QUERY_STRING'] : $new_url;
	Header("Location: ".$new_loc);
}
// If url contains a .php in it, PHP_SELF is set wrong (imho), affecting all paths.  We need to 'fix' it if it does.
$_SERVER['PHP_SELF'] = (($pos = strpos($_SERVER['PHP_SELF'], ".php")) !== false ? substr($_SERVER['PHP_SELF'], 0, $pos+4) : $_SERVER['PHP_SELF']);

//
// D: Setup PHP error handling
//    (Now we can see PHP errors) -- but note that DEBUG is not yet enabled!
//
$error_handler = new error_handler();
set_error_handler(array(&$error_handler, "handle_error"));

//
// E: Setup other essential PHP parameters
//
define("e107_INIT", TRUE);

// setup some php options
e107_ini_set('magic_quotes_runtime',     0);
e107_ini_set('magic_quotes_sybase',      0);
e107_ini_set('arg_separator.output',     '&amp;');
e107_ini_set('session.use_only_cookies', 1);
e107_ini_set('session.use_trans_sid',    0);


if(isset($retrieve_prefs) && is_array($retrieve_prefs)) {
	foreach ($retrieve_prefs as $key => $pref_name) {
		 $retrieve_prefs[$key] = preg_replace("/\W/", '', $pref_name);
	}
} else {
	unset($retrieve_prefs);
}

define("MAGIC_QUOTES_GPC", (ini_get('magic_quotes_gpc') ? TRUE : FALSE));

// Define the domain name and subdomain name.
if($_SERVER['HTTP_HOST'] && is_numeric(str_replace(".","",$_SERVER['HTTP_HOST']))){
	$srvtmp = "";  // Host is an IP address.
}else{
$srvtmp = explode(".",$_SERVER['HTTP_HOST']);
}

define("e_SUBDOMAIN", (count($srvtmp)>2 && $srvtmp[2] ? $srvtmp[0] : FALSE)); // needs to be available to e107_config.

if(e_SUBDOMAIN)
{
   	unset($srvtmp[0]);
}

define("e_DOMAIN",(count($srvtmp) > 1 ? (implode(".",$srvtmp)) : FALSE)); // if it's an IP it must be set to FALSE.

unset($srvtmp);


//  Ensure thet '.' is the first part of the include path
$inc_path = explode(PATH_SEPARATOR, ini_get('include_path'));
if($inc_path[0] != ".") {
	array_unshift($inc_path, ".");
	$inc_path = implode(PATH_SEPARATOR, $inc_path);
	e107_ini_set("include_path", $inc_path);
}
unset($inc_path);

//
// F: Grab e107_config, get directory paths and create $e107 object
//
@include_once(realpath(dirname(__FILE__).'/e107_config.php'));
if(!isset($ADMIN_DIRECTORY)){
	// e107_config.php is either empty, not valid or doesn't exist so redirect to installer..
	header("Location: install.php");
}

//
// clever stuff that figures out where the paths are on the fly.. no more need fo hard-coded e_HTTP :)
//
e107_require_once(realpath(dirname(__FILE__).'/'.$HANDLERS_DIRECTORY).'/e107_class.php');
$e107_paths = compact('ADMIN_DIRECTORY', 'FILES_DIRECTORY', 'IMAGES_DIRECTORY', 'THEMES_DIRECTORY', 'PLUGINS_DIRECTORY', 'HANDLERS_DIRECTORY', 'LANGUAGES_DIRECTORY', 'HELP_DIRECTORY', 'DOWNLOADS_DIRECTORY');
$e107 = new e107($e107_paths, realpath(dirname(__FILE__)));

$inArray = array("'", ";", "/**/", "/UNION/", "/SELECT/", "AS ");
if (strpos($_SERVER['PHP_SELF'], "trackback") === false) {
	foreach($inArray as $res) {
		if(stristr($_SERVER['QUERY_STRING'], $res)) {
			die("Access denied.");
		}
	}
}

//
// G: Retrieve Query data from URI
//    (Until this point, we have no idea what the user wants to do)
//
if (preg_match("#\[(.*?)](.*)#", $_SERVER['QUERY_STRING'], $matches)) {
	define("e_MENU", $matches[1]);
	$e_QUERY = $matches[2];

	if(strlen(e_MENU) == 2) // language code ie. [fr]
	{
        require_once(e_HANDLER."language_class.php");
		$lng = new language;
		define("e_LANCODE",TRUE);
		$_GET['elan'] = $lng->convert(e_MENU);
	}

}else {
	define("e_MENU", "");
	$e_QUERY = $_SERVER['QUERY_STRING'];
  	define("e_LANCODE", "");
}

//
// Start the parser; use it to grab the full query string
//

e107_require_once(e_HANDLER.'e_parse_class.php');
$tp = new e_parse;

//define("e_QUERY", $matches[2]);
//define("e_QUERY", $_SERVER['QUERY_STRING']);
$e_QUERY = str_replace("&","&amp;",$tp->post_toForm($e_QUERY));
define("e_QUERY", $e_QUERY);
//$e_QUERY = e_QUERY;

define("e_TBQS", $_SERVER['QUERY_STRING']);
$_SERVER['QUERY_STRING'] = e_QUERY;

define("e_UC_PUBLIC", 0);
define("e_UC_MAINADMIN", 250);
define("e_UC_READONLY", 251);
define("e_UC_GUEST", 252);
define("e_UC_MEMBER", 253);
define("e_UC_ADMIN", 254);
define("e_UC_NOBODY", 255);
define("ADMINDIR", $ADMIN_DIRECTORY);

//
// H: Initialize debug handling
// (NO E107 DEBUG CONSTANTS OR CODE ARE AVAILABLE BEFORE THIS POINT)
// All debug objects and constants are defined in the debug handler
// i.e. from here on you can use E107_DEBUG_LEVEL or any
// E107_DBG_* constant for debug testing.
//
	require_once(e_HANDLER.'debug_handler.php');

if(E107_DEBUG_LEVEL && isset($db_debug) && is_object($db_debug)) {
	$db_debug->Mark_Time('Start: Init ErrHandler');
}

//
// I: Sanity check on e107_config.php
//     e107_config.php upgrade check
if (!$ADMIN_DIRECTORY && !$DOWNLOADS_DIRECTORY) {
	message_handler("CRITICAL_ERROR", 8, ": generic, ", "e107_config.php");
	exit;
}

//
// J: MYSQL INITIALIZATION
//
@require_once(e_HANDLER.'traffic_class.php');
$eTraffic=new e107_traffic; // We start traffic counting ASAP
$eTraffic->Calibrate($eTraffic);

define("MPREFIX", $mySQLprefix);

e107_require_once(e_HANDLER."mysql_class.php");

$sql =& new db;
$sql->db_SetErrorReporting(FALSE);

$sql->db_Mark_Time('Start: SQL Connect');
$merror=$sql->db_Connect($mySQLserver, $mySQLuser, $mySQLpassword, $mySQLdefaultdb);
$sql2 =& new db; // create after the initial connection. 
$sql->db_Mark_Time('Start: Prefs, misc tables');


require_once(e_HANDLER.'admin_log_class.php');
$admin_log = new e_admin_log();

if ($merror == "e1") {
	message_handler("CRITICAL_ERROR", 6, ": generic, ", "class2.php");
	exit;
}
else if ($merror == "e2") {
	message_handler("CRITICAL_ERROR", 7, ": generic, ", "class2.php");
	exit;
}

//
// K: Load compatability mode.
//

/* PHP Compatabilty should *always* be on. */
e107_require_once(e_HANDLER."php_compatibility_handler.php");

//
// L: Extract core prefs from the database
//
$sql->db_Mark_Time('Start: Extract Core Prefs');
e107_require_once(e_HANDLER."pref_class.php");
$sysprefs = new prefs;

e107_require_once(e_HANDLER.'cache_handler.php');
e107_require_once(e_HANDLER.'arraystorage_class.php');
$eArrayStorage = new ArrayData();

$PrefCache = ecache::retrieve_sys('SitePrefs', 24 * 60, true);
if(!$PrefCache){
	// No cache of the prefs array, going for the db copy..
	$retrieve_prefs[] = 'SitePrefs';
	$sysprefs->ExtractPrefs($retrieve_prefs, TRUE);
	$PrefData = $sysprefs->get('SitePrefs');
	$pref = $eArrayStorage->ReadArray($PrefData);
	if(!$pref){
		$admin_log->log_event("CORE_LAN8", "CORE_LAN7", E_LOG_WARNING); // Core prefs error, core is attempting to
		// Try for the automatic backup..
		$PrefData = $sysprefs->get('SitePrefs_Backup');
		$pref = $eArrayStorage->ReadArray($PrefData);
		if(!$pref){
			// No auto backup, try for the 'old' prefs system.
			$PrefData = $sysprefs->get('pref');
			$pref = unserialize($PrefData);
			if(!is_array($pref)){
				message_handler("CRITICAL_ERROR", 3, __LINE__, __FILE__);
				// No old system, so point in the direction of resetcore :(
				message_handler("CRITICAL_ERROR", 4, __LINE__, __FILE__);
				$admin_log->log_event("CORE_LAN8", "CORE_LAN9", E_LOG_FATAL); // Core could not restore from automatic backup. Execution halted.
				exit;
			} else {
				// old prefs found, remove old system, and update core with new system
				$PrefOutput = $eArrayStorage->WriteArray($pref);
				if(!$sql->db_Update('core', "e107_value='{$PrefOutput}' WHERE e107_name='SitePrefs'")){
					$sql->db_Insert('core', "'SitePrefs', '{$PrefOutput}'");
				}
				if(!$sql->db_Update('core', "e107_value='{$PrefOutput}' WHERE e107_name='SitePrefs_Backup'")){
					$sql->db_Insert('core', "'SitePrefs_Backup', '{$PrefOutput}'");
				}
				$sql->db_Delete('core', "`e107_name` = 'pref'");
			}
		} else {
			message_handler("CRITICAL_ERROR", 3, __LINE__, __FILE__);
			// auto backup found, use backup to restore the core
			if(!$sql->db_Update('core', "`e107_value` = '".addslashes($PrefData)."' WHERE `e107_name` = 'SitePrefs'")){
				$sql->db_Insert('core', "'SitePrefs', '".addslashes($PrefData)."'");
			}
		}
	}
	// write pref cache array
	$PrefCache = $eArrayStorage->WriteArray($pref, false);
	// store the prefs in cache if cache is enabled
	ecache::set_sys('SitePrefs', $PrefCache);
} else {
	// cache of core prefs was found, so grab all the useful core rows we need
	if(!isset($sysprefs->DefaultIgnoreRows)){
    	$sysprefs->DefaultIgnoreRows = "";
	}
	$sysprefs->DefaultIgnoreRows .= '|SitePrefs';
	$sysprefs->prefVals['core']['SitePrefs'] = $PrefCache;
	if(isset($retrieve_prefs))
	{
		$sysprefs->ExtractPrefs($retrieve_prefs, TRUE);
	}
	$pref = $eArrayStorage->ReadArray($PrefCache);
}

$e107->set_base_path();

// extract menu prefs
$menu_pref = unserialize(stripslashes($sysprefs->get('menu_pref')));

$sql->db_Mark_Time('(Extracting Core Prefs Done)');


//
// M: Subdomain and Language Selection
//
define("SITEURLBASE", ($pref['ssl_enabled'] == '1' ? "https://" : "http://").$_SERVER['HTTP_HOST']);
define("SITEURL", SITEURLBASE.e_HTTP);

// let the subdomain determine the language (when enabled).

if(varset($pref['multilanguage_subdomain']) && ($pref['user_tracking'] == "session") && e_DOMAIN && MULTILANG_SUBDOMAIN !== FALSE)
{

	$mtmp = explode("\n",$pref['multilanguage_subdomain']);
	foreach($mtmp as $val)
	{
		if(e_DOMAIN == trim($val))
		{
			$domain_active = TRUE;
		}
	}

	if($domain_active || ($pref['multilanguage_subdomain'] == "1"))
	{
		e107_ini_set("session.cookie_domain",".".e_DOMAIN);
		require_once(e_HANDLER."language_class.php");
		$lng = new language;
		if(e_SUBDOMAIN == "www" || e_SUBDOMAIN === FALSE)
		{
			$GLOBALS['elan'] = $pref['sitelanguage'];
		}
		elseif($eln = $lng->convert(e_SUBDOMAIN))
		{
			$GLOBALS['elan'] = $eln;
		}
	}
}


//----------------------------
//	Load shortcode handler
//----------------------------
// ********* This is probably a bodge! Work out what to do properly. Has to be done when $pref valid
$tp->sch_load();



// if a cookie name pref isn't set, make one :)
if (!$pref['cookie_name']) {
	$pref['cookie_name'] = "e107cookie";
}

// start a session if session based login is enabled
if ($pref['user_tracking'] == "session") {
	session_start();
}

define("e_SELF", ($pref['ssl_enabled'] == '1' ? "https://".$_SERVER['HTTP_HOST'] : "http://".$_SERVER['HTTP_HOST']) . ($_SERVER['PHP_SELF'] ? $_SERVER['PHP_SELF'] : $_SERVER['SCRIPT_FILENAME']));

// if the option to force users to use a particular url for the site is enabled, redirect users there as needed
// Now matches RFC 2616 (sec 3.2): case insensitive, https/:443 and http/:80 are equivalent.
// And, this is robust against hack attacks. Malignant users can put **anything** in HTTP_HOST!
if($pref['redirectsiteurl'] && $pref['siteurl']) {
	// Find domain and port from user and from pref
	list($urlbase,$urlport) = explode(':',$_SERVER['HTTP_HOST'].':');
	if (!$urlport) { $urlport = $_SERVER['SERVER_PORT']; }
	if (!$urlport) { $urlport = 80; }
	$aPrefURL = explode('/',$pref['siteurl'],4);
	if (count($aPrefURL) > 2) { // we can do this -- there's at least http[s]://dom.ain/whatever
		$PrefRoot = $aPrefURL[2];
		list($PrefSiteBase,$PrefSitePort) = explode(':',$PrefRoot.':');
		if (!$PrefSitePort) {
			$PrefSitePort = ( $aPrefURL[0] == "https:" ) ? 443 : 80;	// no port so set port based on 'scheme'
		}

		// Redirect only if
		// -- ports do not match (http <==> https)
		// -- base domain does not match (case-insensitive)
		// -- NOT admin area
		if (($urlport != $PrefSitePort || stripos($PrefSiteBase, $urlbase) === FALSE) && strpos(e_SELF, ADMINDIR) === FALSE) 		{
			$aeSELF = explode('/',e_SELF,4);
			$aeSELF[0] = $aPrefURL[0];	// Swap in correct type of query (http, https)
			$aeSELF[1] = '';						// Defensive code: ensure http:// not http:/<garbage>/
			$aeSELF[2] = $aPrefURL[2];  // Swap in correct domain and possibly port
			$location = implode('/',$aeSELF).(e_QUERY ? "?".e_QUERY : "");

		header("Location: {$location}", true, 301); // send 301 header, not 302
		exit();
	}
}
}

$page = substr(strrchr($_SERVER['PHP_SELF'], "/"), 1);
define("e_PAGE", $page);

// sort out the users language selection
if (isset($_POST['setlanguage']) || isset($_GET['elan']) || isset($GLOBALS['elan'])) {
	if($_GET['elan'])  // query support, for language selection splash pages. etc
	{
		$_POST['sitelanguage'] = str_replace(array(".","/","%"),"",$_GET['elan']);
	}
	if($GLOBALS['elan'] && !isset($_POST['sitelanguage']))
	{
   		$_POST['sitelanguage'] = $GLOBALS['elan'];
	}

	$sql->mySQLlanguage = $_POST['sitelanguage'];
    $sql2->mySQLlanguage = $_POST['sitelanguage'];

	if ($pref['user_tracking'] == "session") {
		$_SESSION['e107language_'.$pref['cookie_name']] = $_POST['sitelanguage'];
	} else {
		setcookie('e107language_'.$pref['cookie_name'], $_POST['sitelanguage'], time() + 86400, "/");
		$_COOKIE['e107language_'.$pref['cookie_name']] = $_POST['sitelanguage'];
		if (strpos(e_SELF, ADMINDIR) === FALSE) {
			$locat = ((!$_GET['elan'] && e_QUERY) || (e_QUERY && e_LANCODE)) ? e_SELF."?".e_QUERY : e_SELF;
		  		header("Location:".$locat);
		}
	}
}

$user_language='';
// Multi-language options.
if (isset($pref['multilanguage']) && $pref['multilanguage']) {

	if ($pref['user_tracking'] == "session") {
		$user_language=(array_key_exists('e107language_'.$pref['cookie_name'], $_SESSION) ? $_SESSION['e107language_'.$pref['cookie_name']] : "");
		$sql->mySQLlanguage=($user_language) ? $user_language : "";
		$sql2->mySQLlanguage = $sql->mySQLlanguage;
	} else {
		$user_language= (isset($_COOKIE['e107language_'.$pref['cookie_name']])) ? $_COOKIE['e107language_'.$pref['cookie_name']] : "";
		$sql->mySQLlanguage=($user_language) ? $user_language : "";
		$sql2->mySQLlanguage = $sql->mySQLlanguage;
	}


}

// Get Language List for rights checking.
if(!$tmplan = getcachedvars("language-list")){
	$handle=opendir(e_LANGUAGEDIR);
	while ($file = readdir($handle)) {
		if (is_dir(e_LANGUAGEDIR.$file) && $file !="." && $file !=".." && $file !="CVS") {
				$lanlist[] = $file;
		}
	}
	closedir($handle);
	$tmplan = implode(",",$lanlist);
	cachevars("language-list", $tmplan);
}

define("e_LANLIST",(isset($tmplan) ? $tmplan : ""));

$language=(isset($_COOKIE['e107language_'.$pref['cookie_name']]) ? $_COOKIE['e107language_'.$pref['cookie_name']] : ($pref['sitelanguage'] ? $pref['sitelanguage'] : "English"));
$language = preg_replace("#\W#", "", $language);
define("USERLAN", ($user_language && (strpos(e_SELF, $PLUGINS_DIRECTORY) !== FALSE || (strpos(e_SELF, $ADMIN_DIRECTORY) === FALSE && file_exists(e_LANGUAGEDIR.$user_language."/lan_".e_PAGE)) || (strpos(e_SELF, $ADMIN_DIRECTORY) !== FALSE && file_exists(e_LANGUAGEDIR.$user_language."/admin/lan_".e_PAGE)) || file_exists(dirname($_SERVER['SCRIPT_FILENAME'])."/languages/".$user_language."/lan_".e_PAGE)    || (    (strpos(e_SELF, $ADMIN_DIRECTORY) == FALSE) && (strpos(e_SELF, $PLUGINS_DIRECTORY) == FALSE) && file_exists(e_LANGUAGEDIR.$user_language."/".$user_language.".php")  )   ) ? $user_language : FALSE));
define("e_LANGUAGE", (!USERLAN || !defined("USERLAN") ? $language : USERLAN));

e107_include(e_LANGUAGEDIR.e_LANGUAGE."/".e_LANGUAGE.".php");
e107_include_once(e_LANGUAGEDIR.e_LANGUAGE."/".e_LANGUAGE."_custom.php");

if($pref['sitelanguage'] != e_LANGUAGE && isset($pref['multilanguage']) && $pref['multilanguage'] && !$pref['multilanguage_subdomain']){
	list($clc) = explode("_",CORE_LC);
	define("e_LAN", strtolower($clc));
	define("e_LANQRY", "[".e_LAN."]");
	unset($clc);
}else{
    define("e_LAN", FALSE);
	define("e_LANQRY", FALSE);
}
$sql->db_Mark_Time('(Start: Pref/multilang done)');

//
// N: misc setups: online user tracking, cache
//
$sql -> db_Mark_Time('Start: Misc resources. Online user tracking, cache');

// cache class
$e107cache = new ecache;

e107_require_once(e_HANDLER."override_class.php");
$override=new override;

e107_require_once(e_HANDLER."event_class.php");
$e_event=new e107_event;

if (isset($pref['notify']) && $pref['notify'] == true) {
	e107_require_once(e_HANDLER.'notify_class.php');
}

//
// O: Start user session
//
$sql -> db_Mark_Time('Start: Init session');
init_session();

// for multi-language these definitions needs to come after the language loaded.
define("SITENAME", trim($tp->toHTML($pref['sitename'], "", "emotes_off, defs, no_make_clickable")));
define("SITEBUTTON", $tp->replaceConstants($pref['sitebutton']));
define("SITETAG", $tp->toHTML($pref['sitetag'], FALSE, "emotes_off, defs"));
define("SITEDESCRIPTION", $tp->toHTML($pref['sitedescription'], "", "emotes_off, defs"));
define("SITEADMIN", $pref['siteadmin']);
define("SITEADMINEMAIL", $pref['siteadminemail']);
define("SITEDISCLAIMER", $tp->toHTML($pref['sitedisclaimer'], "", "emotes_off, defs"));
define("SITECONTACTINFO", $tp->toHTML($pref['sitecontactinfo'], TRUE, "emotes_off, defs"));

// legacy module.php file loading.
if (isset($pref['modules']) && $pref['modules']) {
	$mods=explode(",", $pref['modules']);
	foreach ($mods as $mod) {
		if (is_readable(e_PLUGIN."{$mod}/module.php")) {
			require_once(e_PLUGIN."{$mod}/module.php");
		}
	}
}


$js_body_onload = array();			// Initialise this array in case a module wants to add to it

// Load e_modules after all the constants, but before the themes, so they can be put to use.
if(isset($pref['e_module_list']) && $pref['e_module_list']){
	foreach ($pref['e_module_list'] as $mod){
		if (is_readable(e_PLUGIN."{$mod}/e_module.php")) {
			require_once(e_PLUGIN."{$mod}/e_module.php");
 		}
	}
}

//
// P: THEME LOADING
//

$sql->db_Mark_Time('Start: Load Theme');

//###########  Module redefinable functions ###############
if (!function_exists('checkvalidtheme')) {
	function checkvalidtheme($theme_check) {
		// arg1 = theme to check
		global $ADMIN_DIRECTORY, $tp, $e107;

		if (ADMIN && strpos(e_QUERY, "themepreview") !== FALSE) {
			list($action, $id) = explode('.', e_QUERY);
			require_once(e_HANDLER."theme_handler.php");
			$themeArray = themeHandler :: getThemes("id");
			define("PREVIEWTHEME", e_THEME.$themeArray[$id]."/");
			define("PREVIEWTHEMENAME", $themeArray[$id]);
			define("THEME", e_THEME.$themeArray[$id]."/");
			define("THEME_ABS", e_THEME_ABS.$themeArray[$id]."/");
			return;
		}
		if (@fopen(e_THEME.$theme_check."/theme.php", "r")) {
			define("THEME", e_THEME.$theme_check."/");
			define("THEME_ABS", e_THEME_ABS.$theme_check."/");
			$e107->site_theme = $theme_check;
		} else {
			function search_validtheme() {
				global $e107;
				$th=substr(e_THEME, 0, -1);
				$handle=opendir($th);
				while ($file = readdir($handle)) {
					if (is_dir(e_THEME.$file) && is_readable(e_THEME.$file.'/theme.php')) {
						closedir($handle);
						$e107->site_theme = $file;
						return $file;
					}
				}
				closedir($handle);
			}
			$e107tmp_theme = search_validtheme();
			define("THEME", e_THEME.$e107tmp_theme."/");
			define("THEME_ABS", e_THEME_ABS.$e107tmp_theme."/");
			if (ADMIN && strpos(e_SELF, $ADMIN_DIRECTORY) === FALSE) {
				echo '<script>alert("'.$tp->toJS(CORE_LAN1).'")</script>';
			}
		}
		$themes_dir = $e107->e107_dirs["THEMES_DIRECTORY"];
		$e107->http_theme_dir = "{$e107->server_path}{$themes_dir}{$e107->site_theme}/";
	}
}

//
// Q: ALL OTHER SETUP CODE
//
$sql->db_Mark_Time('Start: Misc Setup');

//------------------------------------------------------------------------------------------------------------------------------------//
if (!class_exists('e107_table')) {
	class e107table {
		function tablerender($caption, $text, $mode = "default", $return = false) {
			/*
			# Render style table
			# - parameter #1:                string $caption, caption text
			# - parameter #2:                string $text, body text
			# - return                                null
			# - scope                                        public
			*/
			global $override;

			if ($override_tablerender = $override->override_check('tablerender')) {
				$result=call_user_func($override_tablerender, $caption, $text, $mode, $return);

				if ($result == "return") {
					return;
				}
				extract($result);
			}

			if ($return) {
				ob_start();
				tablestyle($caption, $text, $mode);
				$ret=ob_get_contents();
				ob_end_clean();
				return $ret;
			} else {
				tablestyle($caption, $text, $mode);
			}
		}
	}
}
//#############################################################

$ns=new e107table;

$e107->ban();

if(varset($pref['force_userupdate']) && USER && !isset($_E107['no_forceuserupdate']))
{
	if(force_userupdate()) {
		header("Location: ".e_BASE."usersettings.php?update");
	}
}

$sql->db_Mark_Time('Start: Signup/splash/admin');

define("e_SIGNUP", e_BASE.(file_exists(e_BASE."customsignup.php") ? "customsignup.php" : "signup.php"));
define("e_LOGIN", e_BASE.(file_exists(e_BASE."customlogin.php") ? "customlogin.php" : "login.php"));

if ($pref['membersonly_enabled'] && !USER && e_SELF != SITEURL.e_SIGNUP && e_SELF != SITEURL."index.php" && e_SELF != SITEURL."fpw.php" && e_SELF != SITEURL.e_LOGIN && strpos(e_PAGE, "admin") === FALSE && e_SELF != SITEURL.'membersonly.php' && e_SELF != SITEURL.'sitedown.php')
{
	if(!isset($_E107['allow_guest']))
	{
		header("Location: ".e_HTTP."membersonly.php");
		exit;
	}
}

if(!isset($_E107['no_prunetmp']))
{
	$sql->db_Delete("tmp", "tmp_time < ".(time() - 300)." AND tmp_ip!='data' AND tmp_ip!='submitted_link'");
}


if ($pref['maintainance_flag'] && ADMIN == FALSE && strpos(e_SELF, "admin.php") === FALSE && strpos(e_SELF, "sitedown.php") === FALSE) {
	header("Location: ".SITEURL."sitedown.php");
	exit;
}

$sql->db_Mark_Time('(Start: Login/logout/ban/tz)');

if (isset($_POST['userlogin']) || isset($_POST['userlogin_x'])) {
	e107_require_once(e_HANDLER."login.php");
	$usr = new userlogin($_POST['username'], $_POST['userpass'], $_POST['autologin']);
}

if (e_QUERY == 'logout') 
{
  if (USER)
  {
	if (check_class(varset($pref['user_audit_class'],'')))
	{  // Need to note in user audit trail
	  $admin_log->user_audit(USER_AUDIT_LOGOUT,'');
	}
  }

	$ip = $e107->getip();
	$udata=(USER === TRUE) ? USERID.".".USERNAME : "0";
	$sql->db_Update("online", "online_user_id = '0', online_pagecount=online_pagecount+1 WHERE online_user_id = '{$udata}' LIMIT 1");

	if ($pref['user_tracking'] == "session") {
		session_destroy();
		$_SESSION[$pref['cookie_name']]="";
	}

	cookie($pref['cookie_name'], "", (time() - 2592000));
	$e_event->trigger("logout");
	echo "<script type='text/javascript'>document.location.href = '".SITEURL."index.php'</script>\n";
	exit;
}


/*
* Calculate time zone offset, based on session cookie set in e107.js.
* (Buyer beware: this may be wrong for the first pageview in a session,
* which is while the user is logged out, so not a problem...)
*
* Time offset is SECONDS. Seconds is much better than hours as a base,
* as some places have 30 and 45 minute time zones.
* It matches user clock time, instead of only time zones.
* Add the offset to MySQL/server time to get user time.
* Subtract the offset from user time to get server time.
*
*/

$e_deltaTime=0;

if (isset($_COOKIE['e107_tdOffset'])) {
	// Actual seconds of delay. See e107.js and footer_default.php
	$e_deltaTime = $_COOKIE['e107_tdOffset'];
}

if (isset($_COOKIE['e107_tzOffset'])) {
	// Relative client-to-server time zone offset in seconds.
	$e_deltaTime += (-($_COOKIE['e107_tzOffset'] * 60 + date("Z")));
}

define("TIMEOFFSET", $e_deltaTime);

$sql->db_Mark_Time('Start: Get menus');
if(!isset($_E107['no_menus']))
{
  	$menu_data = $e107cache->retrieve_sys("menus_".USERCLASS_LIST."_".md5(e_LANGUAGE));
 	$menu_data = $eArrayStorage->ReadArray($menu_data);
	$eMenuList		= array();
	$eMenuActive	= array();
	$eMenuArea		= array();
	if(!is_array($menu_data))
	{
		if ($sql->db_Select('menus', '*', "menu_location > 0 AND menu_class IN (".USERCLASS_LIST.") ORDER BY menu_order"))
		{
			while ($row = $sql->db_Fetch())
			{
				$eMenuList[$row['menu_location']][] =$row;
                $eMenuArea[$row['menu_location']][$row['menu_name']] =1;
				$eMenuActive[$row['menu_name']]	= $row['menu_name'];
			}
		}
		$menu_data['menu_area'] = $eMenuArea;
		$menu_data['menu_list'] = $eMenuList;
		$menu_data['menu_active'] = $eMenuActive;
		$menu_data = $eArrayStorage->WriteArray($menu_data, false);
		$e107cache->set_sys("menus_".USERCLASS_LIST."_".md5(e_LANGUAGE), $menu_data);
		unset($menu_data);
	}
	else
	{
		$eMenuArea 	= $menu_data['menu_area'];
		$eMenuList 	= $menu_data['menu_list'];
		$eMenuActive = $menu_data['menu_active'];
		unset($menu_data);
	}
}
$sql->db_Mark_Time('(Start: Find/Load Theme)');

if(!defined("THEME") && !isset($_E107['no_theme']))
{
	// any plugin file starting with 'admin_' is assumed to use admin theme
	// any plugin file in a folder called admin/ is assumed to use admin theme.
	// any file that specifies $eplug_admin = TRUE;
	// this test: (strpos(e_SELF,'/'.$PLUGINS_DIRECTORY) !== FALSE && strpos(e_PAGE,"admin_") === 0)
	// alternate test: match ANY file starting with 'admin_'...
	//   strpos(e_PAGE, "admin_") === 0
	//
	// here we TEST the theme (see below for deciding what theme to USE)
	//

	if((strpos(e_SELF, $ADMIN_DIRECTORY) !== FALSE || (strpos(e_SELF,'/'.$PLUGINS_DIRECTORY) !== FALSE && (strpos(e_PAGE,"admin_") === 0 || strpos(str_replace($e107->base_path, "", e_SELF), "admin/") !== FALSE)) || (isset($eplug_admin) && $eplug_admin == TRUE)) && $pref['admintheme']) {

		if (strpos(e_SELF.'?'.e_QUERY, 'menus.php?configure') !== FALSE) {
			checkvalidtheme($pref['sitetheme']);
		} else if (strpos(e_SELF, "newspost.php") !== FALSE) {
			define("MAINTHEME", e_THEME.$pref['sitetheme']."/");
			checkvalidtheme($pref['admintheme']);
		}
		else {
			checkvalidtheme($pref['admintheme']);
		}
	} else {
		if (USERTHEME !== FALSE && USERTHEME != "USERTHEME") {
			checkvalidtheme(USERTHEME);
		} else {
			checkvalidtheme($pref['sitetheme']);
		}
	}
}



// --------------------------------------------------------------

// here we USE the theme
if(!isset($_E107['no_theme']))
{
	if (strpos(e_SELF.'?'.e_QUERY, 'menus.php?configure') === FALSE && (strpos(e_SELF, $ADMIN_DIRECTORY) !== FALSE || (strpos(e_SELF,'/'.$PLUGINS_DIRECTORY) !== FALSE && strpos(e_PAGE,"admin_") === 0) || (isset($eplug_admin) && $eplug_admin == TRUE)))
	{
		if (file_exists(THEME.'admin_theme.php'))
		{
			require_once(THEME.'admin_theme.php');
		}
		else
		{
			require_once(THEME."theme.php");
		}
	}
	else
	{
		require_once(THEME."theme.php");
	}
}
$exclude_lan = array("lan_signup.php");  // required for multi-language.

if (strpos(e_SELF, $ADMIN_DIRECTORY) !== FALSE || strpos(e_SELF, "admin.php") !== FALSE) {
	e107_include_once(e_LANGUAGEDIR.e_LANGUAGE."/admin/lan_".e_PAGE);
	e107_include_once(e_LANGUAGEDIR."English/admin/lan_".e_PAGE);
} else if (!in_array("lan_".e_PAGE,$exclude_lan) && strpos(e_SELF, $PLUGINS_DIRECTORY) === FALSE) {
	e107_include_once(e_LANGUAGEDIR.e_LANGUAGE."/lan_".e_PAGE);
	e107_include_once(e_LANGUAGEDIR."English/lan_".e_PAGE);
}

if(!defined("IMODE")) define("IMODE", "lite");

if (IMODE == 'lite') {
	$imode = 'nuvola_light';
} else if (IMODE == 'dark') {
	$imode = 'nuvola_dark';
} else {
	$imode = IMODE;
}

if ($pref['anon_post'] ? define("ANON", TRUE) : define("ANON", FALSE));

if (Empty($pref['newsposts']) ? define("ITEMVIEW", 15) : define("ITEMVIEW", $pref['newsposts']));

if ($pref['antiflood1'] == 1) 
{
  define('FLOODPROTECT', TRUE);
  define('FLOODTIMEOUT', max(varset($pref['antiflood_timeout'],10),3));
}
else
{
  define('FLOODPROTECT', FALSE);
}

$layout = isset($layout) ? $layout : '_default';
define("HEADERF", e_THEME."templates/header{$layout}.php");
define("FOOTERF", e_THEME."templates/footer{$layout}.php");

if (!file_exists(HEADERF)) {
	message_handler("CRITICAL_ERROR", "Unable to find file: ".HEADERF, __LINE__ - 2, __FILE__);
}

if (!file_exists(FOOTERF)) {
	message_handler("CRITICAL_ERROR", "Unable to find file: ".FOOTERF, __LINE__ - 2, __FILE__);
}

define("LOGINMESSAGE", "");
define("OPEN_BASEDIR", (ini_get('open_basedir') ? TRUE : FALSE));
define("SAFE_MODE", (ini_get('safe_mode') ? TRUE : FALSE));
define("FILE_UPLOADS", (ini_get('file_uploads') ? TRUE : FALSE));
define("INIT", TRUE);
if(isset($_SERVER['HTTP_REFERER'])) {
	$tmp = explode("?", $_SERVER['HTTP_REFERER']);
	define("e_REFERER_SELF",($tmp[0] == e_SELF));
} else {
	define('e_REFERER_SELF', FALSE);
}

if (!class_exists('convert'))
{
	require_once(e_HANDLER."date_handler.php");
}





//@require_once(e_HANDLER."IPB_int.php");
//@require_once(e_HANDLER."debug_handler.php");
//------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------//
function js_location($qry){
	global $error_handler;
	if (count($error_handler->errors)) {
		echo $error_handler->return_errors();
		exit;
	} else {
	echo "<script type='text/javascript'>document.location.href='{$qry}'</script>\n"; exit;
	}
}

function check_email($email) {
	return preg_match("/^([_a-zA-Z0-9-+]+)(\.[_a-zA-Z0-9-]+)*@([a-zA-Z0-9-]+)(\.[a-zA-Z0-9-]+)*(\.[a-zA-Z]{2,6})$/" , $email) ? $email : FALSE;
}

//------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------//
function check_class($var, $userclass = USERCLASS_LIST, $uid = 0)
{
	global $tp;
	if($var == e_LANGUAGE)
	{
		return TRUE;
	}

//	if (is_numeric($var) && ($var == e_UC_PUBLIC)) return TRUE;		// Accept numeric class zero - 'PUBLIC'

	// userid has been supplied, go build that user's class list
	if(is_numeric($uid) && $uid > 0)
	{
		$userclass = class_list($uid);
	}

	if ($userclass == "")
	{
		return FALSE;
	}

	$class_array = explode(",", $userclass);

	$lans = explode(",", e_LANLIST);
	$varList = explode(",", trim($var));
	
	rsort($varList); // check the language first.(ie. numbers come last)
	foreach($varList as $v)
	{
		if (in_array($v, $lans) && strpos($v, e_LANGUAGE) === FALSE)
		{
			return FALSE;
		}
		else
		{
			if(!is_numeric($v))  //value to test is a userclass name, go get the id
			{
				$sql=new db;
				$v = trim($v);
				if($sql->db_Select("userclass_classes", "userclass_id", "userclass_name='".$tp->toDB($v)."' "))
				{
					$row = $sql->db_Fetch();
					$v = $row['userclass_id'];
				}
			}
			if (in_array($v, $class_array) || (ctype_digit($v) && ($v == 0)))
			{
				return TRUE;
			}
		}
	}
	return FALSE;
}

function getperms($arg, $ap = ADMINPERMS) {
	global $PLUGINS_DIRECTORY;
	if ($ap == "0") {
		return TRUE;
	}
	if ($ap == "") {
		return FALSE;
	}
	$ap='.'.$ap;
	if ($arg == 'P' && preg_match("#(.*?)/".$PLUGINS_DIRECTORY."(.*?)/(.*?)#", e_SELF, $matches)) {
		$psql=new db;
		if ($psql->db_Select('plugin', 'plugin_id', "plugin_path = '".$matches[2]."' ")) {
			$row=$psql->db_Fetch();
			$arg='P'.$row[0];
		}
	}
	if (strpos($ap, ".".$arg.".") !== FALSE) {
		return TRUE;
	} else {
		return FALSE;
	}
}

/**
 * Get the user data from user and user_extended tables
 *
 * @return array
 */
function get_user_data($uid, $extra = "")
{
	global $pref, $sql;
	$uid = intval($uid);
	$var = array();
	if($uid == 0) { return $var; }
	if($ret = getcachedvars("userdata_{$uid}"))
	{
		return $ret;
	}

	$qry = "
	SELECT u.*, ue.* FROM #user AS u
	LEFT JOIN #user_extended AS ue ON ue.user_extended_id = u.user_id
	WHERE u.user_id = {$uid} {$extra}
	";
	if (!$sql->db_Select_gen($qry))
	{
		$qry = "SELECT * FROM #user AS u WHERE u.user_id = {$uid} {$extra}";
		if(!$sql->db_Select_gen($qry))
		{
			return FALSE;
		}
	}

	$var = $sql->db_Fetch();
	$extended_struct = getcachedvars("extended_struct");
	if(!$extended_struct)
	{
		unset($extended_struct);
		$qry = "SHOW COLUMNS FROM #user_extended ";
		if($sql->db_Select_gen($qry))
		{
			while($row = $sql->db_Fetch())
			{
				if($row['Default'] != "")
				{
					$extended_struct[] = $row;
				}
			}
			if(isset($extended_struct))
			{
				cachevars("extended_struct", $extended_struct);
			}
		}
	}

	if(isset($extended_struct))
	{
		foreach($extended_struct as $row)
		{
			if($row['Default'] != "" && ($var[$row['Field']] == NULL || $var[$row['Field']] == "" ))
			{
				$var[$row['Field']] = $row['Default'];
			}
		}
	}
	
	//===========================================================
	// Now look up the 'inherited' user classes
	global $e_userclass;
	if (!isset($e_userclass) && !is_object($e_userclass)) 
	{
	  require_once(e_HANDLER."userclass_class.php");
	  $e_userclass = new user_class;
	}
	$var['user_class'] = $e_userclass->get_all_user_classes($var['user_class']);
	
	//===========================================================
	
	cachevars("userdata_{$uid}", $var);
	return $var;
}

//------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------//

function save_prefs($table = 'core', $uid = USERID, $row_val = '')
{
  global $pref, $user_pref, $tp, $PrefCache, $sql, $eArrayStorage;
  if ($table == 'core') 
  {
		if ($row_val == '') 
		{ 	// Save old version as a backup first
	  		$sql->db_Select_gen("REPLACE INTO `#core` (e107_name,e107_value) values ('SitePrefs_Backup', '".addslashes($PrefCache)."') ");

		  	// Now save the updated values
		  	// traverse the pref array, with toDB on everything
		  	$_pref = $tp -> toDB($pref, true, true);
		  	// Create the data to be stored
	  		if($sql->db_Select_gen("REPLACE INTO `#core` (e107_name,e107_value) values ('SitePrefs', '".$eArrayStorage->WriteArray($_pref)."') "))
			{
		  		ecache::clear('SitePrefs');
				return TRUE;
			}
			else
			{
            	return FALSE;
			}
		}
  }
  else 
  {
		$_user_pref = $tp -> toDB($user_pref);
		$tmp=addslashes(serialize($_user_pref));
		$sql->db_Update("user", "user_prefs='$tmp' WHERE user_id=".intval($uid));
		return $tmp;
  }
}


//------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------//
function cachevars($id, $var) {
	global $cachevar;
	$cachevar[$id]=$var;
}

function getcachedvars($id) {
	global $cachevar;
	return (isset($cachevar[$id]) ? $cachevar[$id] : false);
}

//------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------//
class floodprotect {
	function flood($table, $orderfield) {
		/*
		# Test for possible flood
		#
		# - parameter #1                string $table, table being affected
		# - parameter #2                string $orderfield, date entry in respective table
		# - return                                boolean
		# - scope                                        public
		*/
		$sql=new db;

		if (FLOODPROTECT == TRUE) {
			$sql->db_Select($table, "*", "ORDER BY ".$orderfield." DESC LIMIT 1", "no_where");
			$row=$sql->db_Fetch();
			return ($row[$orderfield] > (time() - FLOODTIMEOUT) ? FALSE : TRUE);
		} else {
			return TRUE;
		}
	}
}

//------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------//
//------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------//
function init_session() {
	/*
	# Validate user
	#
	# - parameters none
	# - return boolean
	# - scope public
	*/
	global $sql, $pref, $user_pref, $tp, $currentUser, $e107, $_E107;

	define('USERIP', $e107->getip());

    if($_E107['cli'] && $_SERVER['argv'][1])
	{
		require_once(e_HANDLER."cli_class.php");
		$cli = new eCLI;
		$arg = $cli->parse_args();
		if($arg['u'] && $arg['p'])
		{
	        e107_require_once(e_HANDLER."login.php");
			$usr = new userlogin;
			$cli_log = $usr->userlogin(trim($arg['u']), trim($arg['p']), 0);
		}
	}

	if (!isset($_COOKIE[$pref['cookie_name']]) && !isset($_SESSION[$pref['cookie_name']]) && !$_E107['cli'])
	{
		define("USER", FALSE);
		define("USERTHEME", FALSE);
		define("ADMIN", FALSE);
		define("GUEST", TRUE);
		define('USERCLASS', '');
		define('USEREMAIL', '');
	}
	else
	{
		if(!$_E107['cli'])
		{
			list($uid, $upw)=(isset($_COOKIE[$pref['cookie_name']]) && $_COOKIE[$pref['cookie_name']] ? explode(".", $_COOKIE[$pref['cookie_name']]) : explode(".", $_SESSION[$pref['cookie_name']]));
        }
		else
		{
        	list($uid, $upw)= explode(".", $cli_log);
		}

		if (empty($uid) || empty($upw)) {
			cookie($pref['cookie_name'], "", (time() - 2592000));
			$_SESSION[$pref['cookie_name']] = "";
			session_destroy();
			define("ADMIN", FALSE);
			define("USER", FALSE);
			define("USERCLASS", "");
			define("LOGINMESSAGE",CORE_LAN10."<br /><br />");
			return (FALSE);
		}

		$result = get_user_data($uid);
		if(is_array($result) && md5($result['user_password']) == $upw)
		{

			define("USERID", $result['user_id']);
			define("USERNAME", $result['user_name']);
			define("USERURL", (isset($result['user_homepage']) ? $result['user_homepage'] : false));
			define("USEREMAIL", $result['user_email']);
			define("USER", TRUE);
			define("USERCLASS", $result['user_class']);
			define("USERREALM", $result['user_realm']);
			define("USERVIEWED", $result['user_viewed']);
			define("USERIMAGE", $result['user_image']);
			define("USERPHOTO", $result['user_sess']);

			$update_ip = ($result['user_ip'] != USERIP ? ", user_ip = '".USERIP."'" : "");

			if($result['user_currentvisit'] + 3600 < time() || !$result['user_lastvisit'])
			{
				$result['user_lastvisit'] = $result['user_currentvisit'];
				$result['user_currentvisit'] = time();
				$sql->db_Update("user", "user_visits = user_visits + 1, user_lastvisit = '{$result['user_lastvisit']}', user_currentvisit = '{$result['user_currentvisit']}', user_viewed = ''{$update_ip} WHERE user_id='".USERID."' ");
			}
			else
			{
				$result['user_currentvisit'] = time();
				$sql->db_Update("user", "user_currentvisit = '{$result['user_currentvisit']}'{$update_ip} WHERE user_id='".USERID."' ");
			}

			$currentUser = $result;
			$currentUser['user_realname'] = $result['user_login']; // Used by force_userupdate
			define("USERLV", $result['user_lastvisit']);

			if ($result['user_ban'] == 1) 
			{ 
			  if (isset($pref['ban_messages']))
			  {
				echo $tp->toHTML(varsettrue($pref['ban_messages'][6]));		// Show message if one set
			  }
			  exit; 
			}

			$user_pref = ($result['user_prefs']) ? unserialize($result['user_prefs']) : '';

			if (isset($_POST['settheme'])) {
				$user_pref['sitetheme'] = ($pref['sitetheme'] == $_POST['sitetheme'] ? "" : $_POST['sitetheme']);
				save_prefs("user");
			}

			define("USERTHEME", (isset($user_pref['sitetheme']) && file_exists(e_THEME.$user_pref['sitetheme']."/theme.php") ? $user_pref['sitetheme'] : FALSE));
			global $ADMIN_DIRECTORY, $PLUGINS_DIRECTORY;
			if ($result['user_admin']) {
				define("ADMIN", TRUE);
				define("ADMINID", $result['user_id']);
				define("ADMINNAME", $result['user_name']);
				define("ADMINPERMS", $result['user_perms']);
				define("ADMINEMAIL", $result['user_email']);
				define("ADMINPWCHANGE", $result['user_pwchange']);
			} else {
				define("ADMIN", FALSE);
			}
		} else {
			define("USER", FALSE);
			define("USERTHEME", FALSE);
			define("ADMIN", FALSE);
			define("CORRUPT_COOKIE", TRUE);
			define("USERCLASS", "");
		}
	}

	define('USERCLASS_LIST', class_list());
	define('e_CLASS_REGEXP', "(^|,)(".str_replace(",", "|", USERCLASS_LIST).")(,|$)");
	define('e_NOBODY_REGEXP', "(^|,)".e_UC_NOBODY."(,|$)");
}

$sql->db_Mark_Time('Start: Go online');
if(!isset($_E107['no_online']) && varset($pref['track_online']))
{
	e107_require_once(e_HANDLER."online_class.php");
	$e_online = new e_online();
	$e_online->online($pref['track_online'], $pref['flood_protect']);
}

function cookie($name, $value, $expire, $path = "/", $domain = "", $secure = 0) {
	setcookie($name, $value, $expire, $path, $domain, $secure);
}

//
// Use these to combine isset() and use of the set value. or defined and use of a constant
// i.e. to fix  if($pref['foo']) ==> if ( varset($pref['foo']) ) will use the pref, or ''.
// Can set 2nd param to any other default value you like (e.g. false, 0, or whatever)
// $testvalue adds additional test of the value (not just isset())
// Examples:
// $something = pref;  // Bug if pref not set         ==> $something = varset(pref);
// $something = isset(pref) ? pref : "";              ==> $something = varset(pref);
// $something = isset(pref) ? pref : default;         ==> $something = varset(pref,default);
// $something = isset(pref) && pref ? pref : default; ==> use varsettrue(pref,default)
//
function varset(&$val,$default='') {
	if (isset($val)) {
		return $val;
	}
	return $default;
}
function defset($str,$default='') {
	if (defined($str)) {
		return constant($str);
	}
	return $default;
}

//
// These variants are like the above, but only return the value if both set AND 'true'
//
function varsettrue(&$val,$default='') {
	if (isset($val) && $val) return $val;
	return $default;
}
function defsettrue($str,$default='') {
	if (defined($str) && constant($str)) return constant($str);
	return $default;
}

//------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------//
function message_handler($mode, $message, $line = 0, $file = "") {
	e107_require_once(e_HANDLER."message_handler.php");
	show_emessage($mode, $message, $line, $file);
}

// -----------------------------------------------------------------------------
function table_exists($check) {
	if (!$GLOBALS['mySQLtablelist']) {
		$tablist=mysql_list_tables($GLOBALS['mySQLdefaultdb']);
		while (list($temp) = mysql_fetch_array($tablist)) {
			$GLOBALS['mySQLtablelist'][] = $temp;
		}
	}

	$mltable=MPREFIX.strtolower($check);

	foreach ($GLOBALS['mySQLtablelist'] as $lang) {
		if (strpos($lang, $mltable) !== FALSE) {
			return TRUE;
		}
	}
}

function class_list($uid = '')
{
	$clist = array();

	if (is_numeric($uid) || USER === true)
	{
		if (is_numeric($uid))
		{
			if($ud = get_user_data($uid))
			{
				$admin_status = $ud['user_admin'];
				$class_list = $ud['user_class'];
				$admin_perms = $ud['user_perms'];
			}
			else
			{
				$admin_status = false;
				$class_list = "";
				$admin_perms = "";
			}
		}
		else
		{
			$admin_status = ADMIN;
			$class_list = USERCLASS;
			$admin_perms = ADMINPERMS;
		}

		if ($class_list)
		{
			$clist = explode(',', $class_list);
		}
		
		$clist[] = e_UC_MEMBER;
		
		if ($admin_status == true)
		{
			$clist[] = e_UC_ADMIN;
		}

		if ($admin_perms === '0')
		{
			$clist[] = e_UC_MAINADMIN;
		}
	}
	else
	{
		$clist[] = e_UC_GUEST;
	}
	
	$clist[] = e_UC_READONLY;
	$clist[] = e_UC_PUBLIC;
	
	return implode(',', $clist);
}

// ---------------------------------------------------------------------------
function e107_include($fname) {
	global $e107_debug;
	$ret = ($e107_debug ? include($fname) : @include($fname));
	return $ret;
}

function e107_include_once($fname) {
	global $e107_debug;
	if(is_readable($fname)){
		$ret = (!$e107_debug)? @include_once($fname) : include_once($fname);
	}
	return (isset($ret)) ? $ret : "";
}

function e107_require_once($fname) {
	global $e107_debug;
	$ret = ($e107_debug ? require_once($fname) : @require_once($fname));
	return $ret;
}

function e107_require($fname) {
	global $e107_debug;
	$ret = ($e107_debug ? require($fname) : @require($fname));
	return $ret;
}

function include_lan($path, $force = false) {
	if (!is_readable($path)) {
		$path = str_replace(e_LANGUAGE, 'English', $path);
	}
	$ret = ($force) ? include($path) : include_once($path);
	return (isset($ret)) ? $ret : "";
}

if(!function_exists("print_a")) {
	$charset = "utf-8";
	if(defined("CHARSET")) {
		$charset = CHARSET;
	}
	function print_a($var, $return = false) {
		if(!$return){
			echo '<pre>'.htmlspecialchars(print_r($var, true), ENT_QUOTES, $charset).'</pre>';
			return true;
		} else {
			return '<pre>'.htmlspecialchars(print_r($var, true), ENT_QUOTES, $charset).'</pre>';
		}
	}
}


// Check that all required user fields (including extended fields) are valid.
// Return TRUE if update required
function force_userupdate() 
{
	global $sql,$pref,$currentUser;

	if (e_PAGE == "usersettings.php" || strpos(e_SELF, ADMINDIR) == TRUE || (defined("FORCE_USERUPDATE") && (FORCE_USERUPDATE == FALSE)))
	{
		return FALSE;
	}

    $signup_option_names = array("realname", "signature", "image", "timezone", "class");

	foreach($signup_option_names as $key => $value)
	{
		if ($pref['signup_option_'.$value] == 2 && !$currentUser['user_'.$value])
		{
			return TRUE;
		}
    }

	if (!varset($pref['disable_emailcheck'],TRUE) && !trim($currentUser['user_email'])) return TRUE;

	if($sql -> db_Select("user_extended_struct", "user_extended_struct_name", "user_extended_struct_required = '1'"))
	{
		while($row = $sql -> db_Fetch())
		{
			$user_extended_struct_name = "user_{$row['user_extended_struct_name']}";

			if(!$currentUser[$user_extended_struct_name])
			{
				return TRUE;
			}
		}
	}

	return FALSE;
}

class error_handler {

	var $errors;
	var $debug = false;

	function error_handler() {
		//
		// This is initialized before the current debug level is known
		//
        global $_E107;
        if($_E107['debug'])
		{
			$this->debug = true;
			error_reporting(E_ALL);
			return;
        }

        if($_E107['cli'])
		{
         	error_reporting(E_ALL ^ E_NOTICE);
			return;
		}

		if ((isset($_SERVER['QUERY_STRING']) && strpos($_SERVER['QUERY_STRING'], 'debug=') !== FALSE) || isset($_COOKIE['e107_debug_level'])) {
			$this->debug = true;
			error_reporting(E_ALL);
		} else {
			error_reporting(E_ERROR | E_PARSE);
		}
	}

	function handle_error($type, $message, $file, $line, $context) {
		$startup_error = (!defined('E107_DEBUG_LEVEL')); // Error before debug system initialized
		switch($type) {
			case E_NOTICE:
			if ($startup_error || E107_DBG_ALLERRORS) {
				$error['short'] = "Notice: {$message}, Line {$line} of {$file}<br />\n";
				$trace = debug_backtrace();
				$backtrace[0] = (isset($trace[1]) ? $trace[1] : "");
				$backtrace[1] = (isset($trace[2]) ? $trace[2] : "");
				$error['trace'] = $backtrace;
				$this->errors[] = $error;
			}
			break;
			case E_WARNING:
			if ($startup_error || E107_DBG_BASIC) {
				$error['short'] = "Warning: {$message}, Line {$line} of {$file}<br />\n";
				$trace = debug_backtrace();
				$backtrace[0] = (isset($trace[1]) ? $trace[1] : "");
				$backtrace[1] = (isset($trace[2]) ? $trace[2] : "");
				$error['trace'] = $backtrace;
				$this->errors[] = $error;
			}
			break;
			case E_USER_ERROR:
			if ($this->debug == true) {
				$error['short'] = "&nbsp;&nbsp;&nbsp;&nbsp;Internal Error Message: {$message}, Line {$line} of {$file}<br />\n";
				$trace = debug_backtrace();
				$backtrace[0] = (isset($trace[1]) ? $trace[1] : "");
				$backtrace[1] = (isset($trace[2]) ? $trace[2] : "");
				$error['trace'] = $backtrace;
				$this->errors[] = $error;
			}
			default:
			return true;
			break;
		}
	}

	function return_errors() {
		$index = 0; $colours[0] = "#C1C1C1"; $colours[1] = "#B6B6B6";
		$ret = "<table class='fborder'>\n";
		if (E107_DBG_ERRBACKTRACE)
		{
		foreach ($this->errors as $key => $value) {
			$ret .= "\t<tr>\n\t\t<td class='forumheader3' >{$value['short']}</td><td><input class='button' type ='button' style='cursor: hand; cursor: pointer;' size='30' value='Back Trace' onclick=\"expandit('bt_{$key}')\" /></td>\n\t</tr>\n";
			$ret .= "\t<tr>\n<td style='display: none;' colspan='2' id='bt_{$key}'>".print_a($value['trace'], true)."</td></tr>\n";
			if($index == 0) { $index = 1; } else { $index = 0; }
		}
		} else {
			foreach ($this->errors as $key => $value)
			{
				$ret .= "<tr class='forumheader3'><td>{$value['short']}</td></tr>\n";
			}
		}
		$ret .= "</table>";
		return $ret;
	}

	function trigger_error($information, $level) {
		trigger_error($information);
	}
}

/**
 * Strips slashes from a var if magic_quotes_gqc is enabled
 *
 * @param mixed $data
 * @return mixed
 */
function strip_if_magic($data) {
	if (MAGIC_QUOTES_GPC == true) {
		return array_stripslashes($data);
	} else {
		return $data;
	}
}

/**
 * Strips slashes from a string or an array
 *
 * @param mixed $value
 * @return mixed
 */
function array_stripslashes($data) {
	return is_array($data) ? array_map('array_stripslashes', $data) : stripslashes($data);
}

$sql->db_Mark_Time('(After class2)');


function e107_ini_set($var, $value){
	if (function_exists('ini_set')){
		ini_set($var, $value);
	}
}

function echo_gzipped_page() {

    if( headers_sent() ){
        $encoding = false;
    }elseif( strpos($_SERVER["HTTP_ACCEPT_ENCODING"], 'x-gzip') !== false ){
        $encoding = 'x-gzip';
    }elseif( strpos($_SERVER["HTTP_ACCEPT_ENCODING"],'gzip') !== false ){
        $encoding = 'gzip';
    }else{
        $encoding = false;
    }

    if( $encoding ){
        $contents = ob_get_contents();
        ob_end_clean();
        header('Content-Encoding: '.$encoding);
        print("\x1f\x8b\x08\x00\x00\x00\x00\x00");
        $size = strlen($contents);
        $contents = gzcompress($contents, 9);
        $contents = substr($contents, 0, $size);
        print($contents);
        exit();
    }else{
        ob_end_flush();
        exit();
    }
}

?>