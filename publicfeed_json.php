<?php
/**
 * DokuWiki Plugin DAVCal - Public Events JSON Feed
 * Serves events marked public (X-DAVCAL-PUBLIC:1) as JSON for the FullCalendar
 * embed. Recurrences are expanded server-side and times are emitted in UTC;
 * FullCalendar shifts them to the viewer's chosen time zone. Unlike the ICS
 * feed (publicfeed.php), a JSON source honours FullCalendar's timeZone setting.
 *
 * URL: lib/plugins/davcal/publicfeed_json.php
 * FullCalendar appends ?start=...&end=... for the visible range on each fetch.
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) define('DOKU_INC', realpath(dirname(__FILE__).'/../../../').'/');
require_once(DOKU_INC.'inc/init.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');

$hlp =& plugin_load('helper', 'davcal');
if(is_null($hlp))
{
    http_response_code(500);
    echo '[]';
    exit;
}

$start = isset($_GET['start']) ? $_GET['start'] : null;
$end   = isset($_GET['end'])   ? $_GET['end']   : null;

$events = $hlp->getPublicEventsAsJSON($start, $end, 'UTC');

if($events === false)
{
    http_response_code(500);
    echo '[]';
    exit;
}

echo json_encode($events);
exit;
