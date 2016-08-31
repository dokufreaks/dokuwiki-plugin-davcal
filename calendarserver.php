<?php

/**
 * DokuWiki DAVCal PlugIn - DAV Calendar Server PlugIn.
 * 
 * This is heavily based on SabreDAV and features a DokuWiki connector.
 */

 // Initialize DokuWiki
if(!defined('DOKU_INC')) define('DOKU_INC', dirname(__FILE__).'/../../../');
if (!defined('DOKU_DISABLE_GZIP_OUTPUT')) define('DOKU_DISABLE_GZIP_OUTPUT', 1);
require_once(DOKU_INC.'inc/init.php');
session_write_close(); //close session

global $conf;

dbglog('---- DAVCAL calendarserver.php init');

$hlp = null;
$hlp =& plugin_load('helper', 'davcal');

if(is_null($hlp))
{
    dbglog('Error loading helper plugin');
    die('Error loading helper plugin');
}

$baseUri = DOKU_BASE.'lib/plugins/davcal/'.basename(__FILE__).'/';

if($hlp->getConfig('disable_sync') === 1)
{
    dbglog('Synchronisation is disabled');
    die('Synchronisation is disabled');
}

//Mapping PHP errors to exceptions
function exception_error_handler($errno, $errstr, $errfile, $errline) {
    dbglog('Exception occured: '.$errstr);
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
//set_error_handler("exception_error_handler");

// Files we need
require_once(DOKU_PLUGIN.'davcal/vendor/autoload.php');
require_once(DOKU_PLUGIN.'davcal/authBackendDokuwiki.php');
require_once(DOKU_PLUGIN.'davcal/principalBackendDokuwiki.php');
require_once(DOKU_PLUGIN.'davcal/calendarBackendDokuwiki.php');

// Backends - our DokuWiki backends
$authBackend = new DokuWikiSabreAuthBackend();
$calendarBackend = new DokuWikiSabreCalendarBackend($hlp);
$principalBackend = new DokuWikiSabrePrincipalBackend();

// Directory structure
$tree = array(
    new Sabre\CalDAV\Principal\Collection($principalBackend),
    new Sabre\CalDAV\CalendarRoot($principalBackend, $calendarBackend),
);

$server = new Sabre\DAV\Server($tree);

if (isset($baseUri))
    $server->setBaseUri($baseUri);

/* Server Plugins */
$authPlugin = new Sabre\DAV\Auth\Plugin($authBackend);
$server->addPlugin($authPlugin);

$aclPlugin = new Sabre\DAVACL\Plugin();
$server->addPlugin($aclPlugin);

/* CalDAV support */
$caldavPlugin = new Sabre\CalDAV\Plugin();
$server->addPlugin($caldavPlugin);

/* Calendar subscription support */
//$server->addPlugin(
//    new Sabre\CalDAV\Subscriptions\Plugin()
//);

/* Calendar scheduling support */
//$server->addPlugin(
//    new Sabre\CalDAV\Schedule\Plugin()
//);

/* WebDAV-Sync plugin */
$server->addPlugin(new Sabre\DAV\Sync\Plugin());

// Support for html frontend
$browser = new Sabre\DAV\Browser\Plugin();
$server->addPlugin($browser);

dbglog('$server->exec()');
// And off we go!
$server->exec();
