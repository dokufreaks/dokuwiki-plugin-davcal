<?php
/**
 * DokuWiki Plugin DAVCal - Public Events ICS Feed
 * Serves all events marked as public (X-DAVCAL-PUBLIC:1) as a single ICS feed.
 * This URL can be subscribed to by WordPress/The Events Calendar or any calendar app.
 *
 * URL: lib/plugins/davcal/publicfeed.php
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) define('DOKU_INC', realpath(dirname(__FILE__).'/../../../').'/');
require_once(DOKU_INC.'inc/init.php');

$hlp =& plugin_load('helper', 'davcal');
if(is_null($hlp))
{
    http_response_code(500);
    echo 'DAVCal helper not available';
    exit;
}

$calendarName = isset($_GET['name']) ? strip_tags($_GET['name']) : 'Public Calendar';
$ics = $hlp->getPublicEventsAsICS($calendarName);

if($ics === false)
{
    http_response_code(500);
    echo 'Error generating feed';
    exit;
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="public-calendar.ics"');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');
echo $ics;
exit;
