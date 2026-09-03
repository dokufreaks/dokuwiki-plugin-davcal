<?php
/**
 * Test script for subscription functionality
 */

// Initialize DokuWiki
if(!defined('DOKU_INC')) define('DOKU_INC', dirname(__FILE__).'/');
if (!defined('DOKU_DISABLE_GZIP_OUTPUT')) define('DOKU_DISABLE_GZIP_OUTPUT', 1);
require_once(DOKU_INC.'inc/init.php');

// Load the helper
$hlp = plugin_load('helper', 'davcal');
if(is_null($hlp)) {
    die('Error loading helper plugin');
}

// Test database connection
$db = $hlp->getDB();
if (!$db) {
    die('No database connection available');
}

echo "Testing subscription functionality...\n";

// Test 1: Check if calendarsubscriptions table exists
$res = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='calendarsubscriptions'");
if ($res === false) {
    echo "ERROR: Failed to check if calendarsubscriptions table exists\n";
    exit(1);
}

$row = $db->res2row($res);
if (!$row) {
    echo "ERROR: calendarsubscriptions table does not exist\n";
    exit(1);
}

echo "✓ calendarsubscriptions table exists\n";

// Test 2: Check table structure
$res = $db->query("PRAGMA table_info(calendarsubscriptions)");
if ($res === false) {
    echo "ERROR: Failed to get table structure\n";
    exit(1);
}

$columns = array();
while ($row = $db->res2row($res)) {
    $columns[] = $row['name'];
}

$required_columns = array('id', 'uri', 'principaluri', 'source', 'displayname', 'refreshrate', 'calendarorder', 'calendarcolor', 'striptodos', 'stripalarms', 'stripattachments', 'lastmodified');
$missing_columns = array_diff($required_columns, $columns);

if (!empty($missing_columns)) {
    echo "ERROR: Missing columns: " . implode(', ', $missing_columns) . "\n";
    exit(1);
}

echo "✓ All required columns exist\n";

// Test 3: Test subscription creation
$test_principal = 'principals/admin';
$test_uri = 'test_subscription';
$test_source = 'http://example.com/test.ics';

// Clean up any existing test subscription
$db->query("DELETE FROM calendarsubscriptions WHERE uri = ?", $test_uri);

// Create a test subscription
$sql = "INSERT INTO calendarsubscriptions (principaluri, uri, source, displayname, lastmodified) VALUES (?, ?, ?, ?, ?)";
$res = $db->query($sql, $test_principal, $test_uri, $test_source, 'Test Subscription', time());

if ($res === false) {
    echo "ERROR: Failed to create test subscription\n";
    exit(1);
}

echo "✓ Test subscription created\n";

// Test 4: Test subscription retrieval
$sql = "SELECT * FROM calendarsubscriptions WHERE uri = ?";
$res = $db->query($sql, $test_uri);

if ($res === false) {
    echo "ERROR: Failed to retrieve test subscription\n";
    exit(1);
}

$row = $db->res2row($res);
if (!$row) {
    echo "ERROR: Test subscription not found\n";
    exit(1);
}

echo "✓ Test subscription retrieved successfully\n";
echo "  - ID: " . $row['id'] . "\n";
echo "  - URI: " . $row['uri'] . "\n";
echo "  - Source: " . $row['source'] . "\n";

// Test 5: Test subscription update
$new_displayname = 'Updated Test Subscription';
$sql = "UPDATE calendarsubscriptions SET displayname = ?, lastmodified = ? WHERE uri = ?";
$res = $db->query($sql, $new_displayname, time(), $test_uri);

if ($res === false) {
    echo "ERROR: Failed to update test subscription\n";
    exit(1);
}

echo "✓ Test subscription updated\n";

// Test 6: Test subscription deletion
$sql = "DELETE FROM calendarsubscriptions WHERE uri = ?";
$res = $db->query($sql, $test_uri);

if ($res === false) {
    echo "ERROR: Failed to delete test subscription\n";
    exit(1);
}

echo "✓ Test subscription deleted\n";

echo "\nAll subscription tests passed! The subscription functionality should now work correctly.\n";
?> 