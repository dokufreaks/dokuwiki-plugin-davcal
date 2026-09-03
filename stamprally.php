<?php
/**
 * DokuWiki Plugin DAVCal - Stamp Rally Sheet Generator
 * Generates a printable stamp rally sheet from an appearances event page.
 *
 * URL: lib/plugins/davcal/stamprally.php?page=appearances_cons:2026-06-06_my_event
 */

if(!defined('DOKU_INC')) define('DOKU_INC', realpath(dirname(__FILE__).'/../../../').'/');
require_once(DOKU_INC.'inc/init.php');

$pageId = isset($_GET['page']) ? $_GET['page'] : '';
$paper  = isset($_GET['paper']) ? $_GET['paper'] : 'letter'; // 'letter' or 'a4'

if(empty($pageId) || !page_exists($pageId)) {
    http_response_code(404);
    echo 'Page not found.';
    exit;
}

// Check read permission
if(auth_quickaclcheck($pageId) < AUTH_READ) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$wikiContent = rawWiki($pageId);

// Extract event title
$eventTitle = '';
if(preg_match('/^======\s*(.+?)\s*======/m', $wikiContent, $m))
    $eventTitle = trim($m[1]);

// Extract date
$eventDate = '';
if(preg_match('/\*\*Date:\*\*\s*(.+)/m', $wikiContent, $m))
    $eventDate = trim($m[1]);

// Extract year only from date
$eventYear = '';
if($eventDate && preg_match('/(\d{4})/', $eventDate, $ym))
    $eventYear = $ym[1];

// Extract location
$eventLocation = '';
if(preg_match('/\*\*Location:\*\*\s*(.+)/m', $wikiContent, $m))
    $eventLocation = trim($m[1]);

// Extract members
$members = [];
if(preg_match_all('/^\|  (.+?)  \|  (.*?)  \|  (.*?)  \|  (Y|N)  \|/m', $wikiContent, $matches, PREG_SET_ORDER)) {
    foreach($matches as $match) {
        $name       = trim($match[1]);
        $table      = trim($match[2]);
        $panels     = trim($match[3]);
        $stamprally = trim($match[4]);
        if($name && $name !== 'N/A' && $stamprally === 'Y') {
            $members[] = [
                'name'   => htmlspecialchars($name),
                'table'  => htmlspecialchars($table),
                'panels' => htmlspecialchars($panels),
            ];
        }
    }
}

$count = count($members);
$cols  = ($count <= 9) ? 3 : 4;

// Paper size CSS
$paperCss = ($paper === 'a4')
    ? 'size: A4 portrait; width: 210mm; min-height: 297mm;'
    : 'size: letter portrait; width: 215.9mm; min-height: 279.4mm;';

$pageWidth  = ($paper === 'a4') ? '210mm' : '215.9mm';
$pageHeight = ($paper === 'a4') ? '297mm' : '279.4mm';

$otherPaper = ($paper === 'a4') ? 'letter' : 'a4';
$otherLabel = ($paper === 'a4') ? 'US Letter' : 'A4';
$otherUrl   = '?page=' . urlencode($pageId) . '&paper=' . $otherPaper;

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stamp Rally — <?php echo htmlspecialchars($eventTitle); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --red: #E4504B;
    --black: #1a1a1a;
    --grey: #f0f0f0;
    --mid: #555;
    --border: #ddd;
    --font: 'Space Grotesk', sans-serif;
  }

  body {
    font-family: var(--font);
    background: #e8e8e8;
    color: var(--black);
  }

  /* ── Screen controls ─────────────────────────────── */
  #controls {
    position: fixed;
    top: 0; left: 0; right: 0;
    background: var(--black);
    color: #fff;
    padding: 10px 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    z-index: 100;
    font-size: 14px;
  }

  #controls strong { font-weight: 700; letter-spacing: 0.04em; }

  #controls a {
    color: #fff;
    text-decoration: underline;
    font-size: 13px;
    opacity: 0.7;
  }

  #controls a:hover { opacity: 1; }

  .btn-print {
    margin-left: auto;
    background: var(--red);
    color: #fff;
    border: none;
    padding: 8px 20px;
    font-family: var(--font);
    font-size: 14px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    cursor: pointer;
  }

  .btn-print:hover { background: #c0392b; }

  /* ── Page wrapper ─────────────────────────────────── */
  #page {
    width: <?php echo $pageWidth; ?>;
    min-height: <?php echo $pageHeight; ?>;
    background: #fff;
    margin: 60px auto 40px;
    padding: 14mm 14mm 10mm;
  }

  /* ── Header ───────────────────────────────────────── */
  .sheet-header {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    border-top: 3px dotted #221E20;
    border-bottom: 3px dotted #221E20;
    padding: 10px 0;
    margin-bottom: 12px;
  }

  .sheet-logo {
    height: 56px;
    width: auto;
    flex-shrink: 0;
  }

  .sheet-title-block { flex: 1; }

  .sheet-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: var(--red);
    margin-bottom: 2px;
  }

  .sheet-event-title {
    font-size: 22px;
    font-weight: 700;
    line-height: 1.15;
    color: var(--black);
  }

  .sheet-meta {
    font-size: 11px;
    color: var(--mid);
    margin-top: 4px;
    font-weight: 500;
  }

  .sheet-meta span + span::before {
    content: ' · ';
  }

  .sheet-instructions {
    font-size: 10.5px;
    color: var(--mid);
    margin-bottom: 12px;
    font-weight: 500;
    text-align: center;
    text-transform: uppercase;
    letter-spacing: 0.08em;
  }

  /* ── Grid ─────────────────────────────────────────── */
  .stamp-grid {
    display: grid;
    grid-template-columns: repeat(<?php echo $cols; ?>, 1fr);
    gap: 8px;
    justify-items: stretch;
  }

  /* ── Stamp box ────────────────────────────────────── */
  .stamp-box {
    border: 2px solid var(--black);
    display: flex;
    flex-direction: column;
    aspect-ratio: 1 / 1.1;
  }

  .stamp-info {
    background: var(--black);
    color: #fff;
    padding: 8px 10px 7px;
    flex-shrink: 0;
    text-align: center;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }

  .stamp-name {
    font-size: 13px;
    font-weight: 700;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .stamp-table {
    font-size: 11px;
    font-weight: 600;
    opacity: 0.85;
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .stamp-area {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #fff;
    position: relative;
  }

  .stamp-area-inner {
    width: 60%;
    aspect-ratio: 1;
    border: 1.5px dashed var(--border);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .stamp-area-label {
    font-size: 8px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--border);
  }

  .stamp-panels {
    font-size: 13px;
    font-weight: 500;
    color: var(--mid);
    padding: 4px 6px;
    border-top: 1px solid var(--border);
    text-align: center;
    line-height: 1.3;
    flex-shrink: 0;
  }
  .no-members {
    text-align: center;
    padding: 40px;
    color: var(--mid);
    font-size: 14px;
    font-weight: 500;
  }

  /* ── Footer ───────────────────────────────────────── */
  /* ── Print ────────────────────────────────────────── */
  @media print {
    body { background: #fff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    #controls { display: none; }
    #page {
      margin: 0;
      padding: 12mm 12mm 8mm;
      width: 100%;
      min-height: 0;
      box-shadow: none;
    }
    @page { <?php echo $paperCss; ?> margin: 0; }
  }
</style>
</head>
<body>

<div id="controls">
  <strong>Stamp Rally Sheet</strong>
  <span style="opacity:0.5;">—</span>
  <span><?php echo htmlspecialchars($eventTitle); ?></span>
  <a href="<?php echo htmlspecialchars($otherUrl); ?>">Switch to <?php echo $otherLabel; ?></a>
  <button class="btn-print" onclick="window.print()">🖨 Print</button>
</div>

<div id="page">

  <div class="sheet-header">
    <img class="sheet-logo" src="https://cartoonist.coop/wp-content/uploads/2026/03/cropped-CU-logo_4-feb-25-stroke.png" alt="Cartoonists United">
    <div class="sheet-title-block">
      <div class="sheet-label">Stamp Rally</div>
      <div class="sheet-event-title"><?php echo htmlspecialchars($eventTitle); ?></div>
      <div class="sheet-meta">
        <?php if($eventYear): ?><span><?php echo htmlspecialchars($eventYear); ?></span><?php endif; ?>
        <?php if($eventLocation): ?><span><?php echo htmlspecialchars($eventLocation); ?></span><?php endif; ?>
        <?php if($count): ?><span><?php echo $count; ?> member<?php echo $count !== 1 ? 's' : ''; ?></span><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="sheet-instructions">Visit each table &amp; collect a stamp!</div>

  <?php if(empty($members)): ?>
    <div class="no-members">No members have signed up yet.</div>
  <?php else: ?>
    <div class="stamp-grid">
      <?php foreach($members as $m): ?>
        <div class="stamp-box">
          <div class="stamp-info">
            <div class="stamp-name"><?php echo $m['name']; ?></div>
            <?php if($m['table'] && $m['table'] !== 'N/A'): ?>
              <div class="stamp-table">Table <?php echo $m['table']; ?></div>
            <?php endif; ?>
          </div>
          <div class="stamp-area">
            <div class="stamp-area-inner">
              <span class="stamp-area-label">Stamp</span>
            </div>
          </div>
          <?php if($m['panels'] && $m['panels'] !== 'N/A'): ?>
            <div class="stamp-panels"><?php echo $m['panels']; ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

</body>
</html>
<?php exit;
