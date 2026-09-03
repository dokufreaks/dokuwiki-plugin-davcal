<?php
/**
 * DAVCal one-time maintenance script: repair RECURRENCE-ID overrides
 *
 * Two defects in earlier versions of the plugin left bad override VEVENTs in
 * the calendarobjects table:
 *
 *   1. Every single-occurrence save appended a new override instead of
 *      replacing the existing one, so an occurrence could carry several
 *      overrides all naming the same RECURRENCE-ID. Each one gets drawn.
 *
 *   2. Re-editing an occurrence that had already been moved keyed off its
 *      current start rather than the slot it replaces, producing an override
 *      whose RECURRENCE-ID matches no date the RRULE generates. Sabre treats
 *      that as an extra event and draws it alongside the real one.
 *
 * This script finds and removes both. It runs read-only by default and shows
 * you exactly what it would change. Nothing is written until you press Apply.
 *
 * Access: log in to the wiki as an admin, then load
 *   https://your-wiki/lib/plugins/davcal/repair_overrides.php
 *
 * DELETE THIS FILE FROM THE SERVER once you are finished with it.
 */

if(!defined('DOKU_INC')) define('DOKU_INC', dirname(__FILE__).'/../../../');
if(!defined('DOKU_DISABLE_GZIP_OUTPUT')) define('DOKU_DISABLE_GZIP_OUTPUT', 1);
require_once(DOKU_INC.'inc/init.php');

if(!auth_isadmin())
{
    http_status(403);
    die('This script is restricted to wiki administrators. Log in to the wiki as an admin first, then reload this page.');
}

require_once(DOKU_PLUGIN.'davcal/vendor/autoload.php');

$apply = (isset($_POST['apply']) && $_POST['apply'] === 'yes' && checkSecurityToken());

/**
 * Does the master's RRULE actually generate an occurrence at this instant?
 *
 * A fresh iterator is built per check because EventIterator is stateful.
 *
 * @param object $master The master VEVENT, the one with no RECURRENCE-ID
 * @param int    $ridTs  The RECURRENCE-ID as a Unix timestamp
 *
 * @return bool True when the RRULE produces an occurrence at $ridTs
 */
function davcal_repair_isRealOccurrence($master, $ridTs)
{
    try
    {
        $it = new \Sabre\VObject\Recur\EventIterator(array($master));
        // Start a little before the target so we do not walk the series from
        // its very first occurrence every time.
        $it->fastForward(new \DateTime('@'.($ridTs - 172800)));
    }
    catch(\Exception $e)
    {
        return false;
    }

    $guard = 0;
    while($it->valid() && $guard < 500)
    {
        $guard++;
        $ts = $it->getDtStart()->getTimestamp();
        if($ts === $ridTs)
            return true;
        if($ts > $ridTs)
            return false;
        $it->next();
    }
    return false;
}

/**
 * Collect every EXDATE instant on the master VEVENT.
 *
 * An occurrence that has been excluded will not be produced by the iterator,
 * so an override naming it would otherwise look like an orphan. Those are
 * reported but never deleted, because the right answer there is a judgement
 * call rather than a repair.
 *
 * @param object $master The master VEVENT
 *
 * @return array Unix timestamps
 */
function davcal_repair_exdates($master)
{
    $out = array();
    foreach($master->select('EXDATE') as $exdate)
    {
        try
        {
            foreach($exdate->getDateTimes() as $dt)
                $out[] = $dt->getTimestamp();
        }
        catch(\Exception $e)
        {
            // Unparseable EXDATE, skip it rather than abort the whole run.
        }
    }
    return $out;
}

/**
 * Recompute the denormalised columns the plugin keeps alongside calendardata.
 * Mirrors helper.php::getDenormalizedData, which is protected.
 *
 * @param string $calendarData The serialised VCALENDAR
 *
 * @return mixed Array with firstOccurence and lastOccurence, or false
 */
function davcal_repair_denormalize($calendarData)
{
    $vObject = \Sabre\VObject\Reader::read($calendarData);
    $component = null;
    foreach($vObject->getComponents() as $candidate)
    {
        if($candidate->name !== 'VTIMEZONE')
        {
            $component = $candidate;
            break;
        }
    }
    if($component === null || $component->name !== 'VEVENT')
        return false;

    $firstOccurence = $component->DTSTART->getDateTime()->getTimestamp();

    if(!isset($component->RRULE))
    {
        if(isset($component->DTEND))
            $lastOccurence = $component->DTEND->getDateTime()->getTimestamp();
        elseif(isset($component->DURATION))
        {
            $endDate = $component->DTSTART->getDateTime();
            $endDate->add(\Sabre\VObject\DateTimeParser::parse($component->DURATION->getValue()));
            $lastOccurence = $endDate->getTimestamp();
        }
        elseif(!$component->DTSTART->hasTime())
        {
            $endDate = $component->DTSTART->getDateTime();
            $endDate->modify('+1 day');
            $lastOccurence = $endDate->getTimestamp();
        }
        else
            $lastOccurence = $firstOccurence;
    }
    else
    {
        $it = new \Sabre\VObject\Recur\EventIterator($vObject, (string)$component->UID);
        $maxDate = new \DateTime('2038-01-01');
        if($it->isInfinite())
            $lastOccurence = $maxDate->getTimestamp();
        else
        {
            $end = $it->getDtEnd();
            $guard = 0;
            while($it->valid() && $end < $maxDate && $guard < 10000)
            {
                $guard++;
                $end = $it->getDtEnd();
                $it->next();
            }
            $lastOccurence = $end->getTimestamp();
        }
    }

    return array('firstOccurence' => $firstOccurence, 'lastOccurence' => $lastOccurence);
}

$sqlite = new \dokuwiki\plugin\sqlite\SQLiteDB('davcal', DOKU_PLUGIN.'davcal/db/');

$rows = $sqlite->queryAll("SELECT id, calendardata, uid, uri, calendarid FROM calendarobjects");

$report = array();
$rowsChanged = 0;
$duplicatesRemoved = 0;
$orphansRemoved = 0;
$excludedSkipped = 0;

foreach($rows as $row)
{
    try
    {
        $vcal = \Sabre\VObject\Reader::read($row['calendardata']);
    }
    catch(\Exception $e)
    {
        $report[] = array(
            'uid' => $row['uid'],
            'summary' => '(unparseable calendar data)',
            'notes' => array('Skipped: '.$e->getMessage()),
            'changed' => false,
        );
        continue;
    }

    if(!isset($vcal->VEVENT))
        continue;

    $master = null;
    $overrides = array();
    foreach($vcal->VEVENT as $vevent)
    {
        if(isset($vevent->{'RECURRENCE-ID'}))
            $overrides[] = $vevent;
        else
            $master = $vevent;
    }

    if($master === null || count($overrides) === 0)
        continue;

    $summary = isset($master->SUMMARY) ? (string)$master->SUMMARY : '(no summary)';
    $notes = array();
    $doomed = array();

    $exdates = davcal_repair_exdates($master);
    $hasRrule = isset($master->RRULE);

    // Group the overrides by the instant they claim to replace.
    $groups = array();
    foreach($overrides as $override)
    {
        try
        {
            $ts = $override->{'RECURRENCE-ID'}->getDateTime()->getTimestamp();
        }
        catch(\Exception $e)
        {
            $notes[] = 'Override with an unreadable RECURRENCE-ID left alone.';
            continue;
        }
        if(!isset($groups[$ts]))
            $groups[$ts] = array();
        $groups[$ts][] = $override;
    }

    foreach($groups as $ts => $group)
    {
        $when = date('Y-m-d H:i', $ts).' UTC';

        // Duplicates: keep the most recently modified, discard the rest.
        if(count($group) > 1)
        {
            $best = null;
            $bestStamp = -1;
            foreach($group as $candidate)
            {
                $stamp = 0;
                if(isset($candidate->{'LAST-MODIFIED'}))
                {
                    try { $stamp = $candidate->{'LAST-MODIFIED'}->getDateTime()->getTimestamp(); }
                    catch(\Exception $e) { $stamp = 0; }
                }
                if($stamp >= $bestStamp)
                {
                    $bestStamp = $stamp;
                    $best = $candidate;
                }
            }
            foreach($group as $candidate)
            {
                if($candidate !== $best)
                {
                    $doomed[] = $candidate;
                    $duplicatesRemoved++;
                }
            }
            $notes[] = 'Occurrence '.$when.': '.count($group).' overrides, keeping the newest and removing '.(count($group) - 1).'.';
            $group = array($best);
        }

        // Orphans: the surviving override names an instant the RRULE never
        // produces, so nothing it could replace exists.
        $survivor = $group[0];
        if(in_array($ts, $exdates, true))
        {
            $notes[] = 'Occurrence '.$when.': override names an EXDATE-excluded slot. Left alone, this one needs a human decision.';
            $excludedSkipped++;
        }
        elseif(!$hasRrule)
        {
            $notes[] = 'Occurrence '.$when.': master has no RRULE, so this override is orphaned. Left alone, this one needs a human decision.';
            $excludedSkipped++;
        }
        elseif(!davcal_repair_isRealOccurrence($master, $ts))
        {
            $doomed[] = $survivor;
            $orphansRemoved++;
            $start = isset($survivor->DTSTART) ? $survivor->DTSTART->getDateTime()->format('Y-m-d H:i T') : '?';
            $notes[] = 'Occurrence '.$when.': RECURRENCE-ID matches no date the RRULE generates. Removing this orphan, which was drawing an extra event on '.$start.'.';
        }
    }

    if(count($doomed) === 0)
    {
        if(count($notes) > 0)
            $report[] = array('uid' => $row['uid'], 'summary' => $summary, 'notes' => $notes, 'changed' => false);
        continue;
    }

    $rowsChanged++;

    if($apply)
    {
        foreach($doomed as $victim)
            $vcal->remove($victim);

        $eventStr = $vcal->serialize();
        $extra = davcal_repair_denormalize($eventStr);
        if($extra === false)
        {
            $notes[] = 'ABORTED for this event: could not recompute occurrence bounds, nothing written.';
            $report[] = array('uid' => $row['uid'], 'summary' => $summary, 'notes' => $notes, 'changed' => false);
            $rowsChanged--;
            continue;
        }

        $now = new \DateTime();
        $sqlite->query(
            "UPDATE calendarobjects SET calendardata = ?, lastmodified = ?, firstoccurence = ?, ".
            "lastoccurence = ?, size = ?, etag = ? WHERE id = ?",
            $eventStr, $now->getTimestamp(), $extra['firstOccurence'], $extra['lastOccurence'],
            strlen($eventStr), md5($eventStr), $row['id']
        );

        // Bump the sync token so CalDAV subscribers pick the change up.
        $cal = $sqlite->queryRecord("SELECT synctoken FROM calendars WHERE id = ?", [$row['calendarid']]);
        if($cal && isset($cal['synctoken']))
        {
            $sqlite->query(
                "INSERT INTO calendarchanges (uri, synctoken, calendarid, operation) VALUES(?, ?, ?, ?)",
                $row['uri'], $cal['synctoken'], $row['calendarid'], 2
            );
            $sqlite->query("UPDATE calendars SET synctoken = ? WHERE id = ?",
                           $cal['synctoken'] + 1, $row['calendarid']);
        }
    }

    $report[] = array('uid' => $row['uid'], 'summary' => $summary, 'notes' => $notes, 'changed' => true);
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>DAVCal override repair</title>
<style>
body { font-family: sans-serif; margin: 2em auto; max-width: 60em; line-height: 1.5; color: #222; }
h1 { font-size: 1.4em; }
.mode { padding: .75em 1em; border-radius: 4px; margin: 1em 0; }
.dry { background: #eef4fb; border: 1px solid #b7cfe8; }
.done { background: #eef8ee; border: 1px solid #b2ddb2; }
.event { border: 1px solid #ddd; border-radius: 4px; padding: .75em 1em; margin: .75em 0; }
.event.changed { border-color: #e0a030; background: #fffaf0; }
.summary { font-weight: bold; }
.uid { font-family: monospace; font-size: .85em; color: #777; }
ul { margin: .5em 0 0 0; }
button { font-size: 1em; padding: .6em 1.2em; cursor: pointer; }
.counts td { padding: .2em 1em .2em 0; }
</style>
</head>
<body>

<h1>DAVCal override repair</h1>

<?php if($apply): ?>
<div class="mode done"><strong>Changes applied.</strong> The database has been updated.</div>
<?php else: ?>
<div class="mode dry"><strong>Dry run.</strong> Nothing has been written. Review the list below, then press Apply.</div>
<?php endif; ?>

<table class="counts">
<tr><td>Calendar rows affected</td><td><?php echo $rowsChanged; ?></td></tr>
<tr><td>Duplicate overrides removed</td><td><?php echo $duplicatesRemoved; ?></td></tr>
<tr><td>Orphan overrides removed</td><td><?php echo $orphansRemoved; ?></td></tr>
<tr><td>Left for manual review</td><td><?php echo $excludedSkipped; ?></td></tr>
</table>

<?php if(count($report) === 0): ?>
<p>No events carry duplicate or orphaned overrides. Nothing to do.</p>
<?php else: ?>
<?php foreach($report as $item): ?>
<div class="event<?php echo $item['changed'] ? ' changed' : ''; ?>">
  <div class="summary"><?php echo hsc($item['summary']); ?></div>
  <div class="uid"><?php echo hsc($item['uid']); ?></div>
  <ul>
  <?php foreach($item['notes'] as $note): ?>
    <li><?php echo hsc($note); ?></li>
  <?php endforeach; ?>
  </ul>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if(!$apply && $rowsChanged > 0): ?>
<form method="post" action="">
  <input type="hidden" name="apply" value="yes">
  <input type="hidden" name="sectok" value="<?php echo hsc(getSecurityToken()); ?>">
  <button type="submit">Apply these changes</button>
</form>
<?php endif; ?>

<?php if($apply): ?>
<p><strong>Delete this file from the server now.</strong> It lives at
<code>lib/plugins/davcal/repair_overrides.php</code>.</p>
<?php endif; ?>

</body>
</html>
