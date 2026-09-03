<?php
/**
 * DokuWiki Plugin DAVCal - Single Event ICS Download
 * Serves a single calendar event as an ICS file for import into calendar apps.
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) define('DOKU_INC', realpath(dirname(__FILE__).'/../../../').'/');
require_once(DOKU_INC.'inc/init.php');

$uid = isset($_GET['uid']) ? $_GET['uid'] : null;
if(empty($uid))
{
    http_response_code(400);
    echo 'Missing uid parameter';
    exit;
}

$hlp =& plugin_load('helper', 'davcal');
if(is_null($hlp))
{
    http_response_code(500);
    echo 'DAVCal helper not available';
    exit;
}

$event = $hlp->getEventWithUid($uid);
if($event === false || !isset($event['calendardata']))
{
    http_response_code(404);
    echo 'Event not found';
    exit;
}

// Load SabreDAV
require_once(DOKU_PLUGIN.'davcal/vendor/autoload.php');

$vcal = \Sabre\VObject\Reader::read($event['calendardata']);
$vevent = $vcal->VEVENT;

// For recurring events, we only want the specific occurrence if an
// occurrence_date was provided, otherwise just serve the master event.
$occurrenceDate = isset($_GET['occurrence_date']) ? $_GET['occurrence_date'] : null;

// Build a clean single-event ICS
$out = new \Sabre\VObject\Component\VCalendar();
$out->VERSION = '2.0';
$out->PRODID = '-//DAVCal//DAVCal for DokuWiki//EN';
$out->CALSCALE = 'GREGORIAN';
$out->METHOD = 'PUBLISH';

// Find the right VEVENT component - for a specific occurrence, find the
// exception VEVENT with matching RECURRENCE-ID, otherwise use master
$targetEvent = null;
foreach($vcal->VEVENT as $v)
{
    if($occurrenceDate && isset($v->{'RECURRENCE-ID'}))
    {
        $rid = $v->{'RECURRENCE-ID'}->getDateTime()->format('Y-m-d');
        if($rid === $occurrenceDate)
        {
            $targetEvent = $v;
            break;
        }
    }
    elseif(!isset($v->{'RECURRENCE-ID'}))
    {
        $targetEvent = $v;
    }
}

if($targetEvent === null)
    $targetEvent = $vcal->VEVENT;

// Clone the event into the new calendar, stripping RRULE for single occurrences
$newEvent = $out->add('VEVENT');
foreach($targetEvent->children() as $child)
{
    // Skip RRULE if we're exporting a specific occurrence
    if($occurrenceDate && $child->name === 'RRULE')
        continue;
    $newEvent->add(clone $child);
}

$icsContent = $out->serialize();

// Sanitize filename
$title = (string)$targetEvent->SUMMARY;
$filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $title);
$filename = trim($filename, '_') ?: 'event';
$filename .= '.ics';

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($icsContent));
header('Cache-Control: no-cache, no-store');
echo $icsContent;
exit;
