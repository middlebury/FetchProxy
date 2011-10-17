<?php

require_once('config.php');
require_once('lib.php');
$db = new PDO(DB_DSN, DB_USER, DB_PASS);

// Update our feeds
$stmt = $db->prepare(
	'SELECT id, url
	FROM feeds 
	WHERE 
		(custom_ttl IS NULL AND last_fetch < DATE_SUB(NOW(), INTERVAL '.DEFAULT_TTL.' MINUTE))
		OR (custom_ttl IS NOT NULL AND last_fetch < DATE_SUB(NOW(), INTERVAL custom_ttl MINUTE))
	');
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_OBJ);

foreach ($rows as $row) {
	try {
		fetch_url($row->id, $row->url);
	} catch (Exception $e) {
// 		print $e->getMessage()."\n";
	}
}

// Delete feeds that haven't been accessed in a very long time so we don't waste
// effort continuing to fetch them.
$stmt = $db->prepare(
	'SELECT id, url
	FROM feeds 
	WHERE 
		(last_access < DATE_SUB(NOW(), INTERVAL '.MAX_LIFE_WITHOUT_ACCESS.'))
	');
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_OBJ);

$deleteStmt = $db->prepare('DELETE FROM feeds WHERE id = ?');
foreach ($rows as $row) {
	$db->beginTransaction();
	log_event('delete', 'Feed not accessed in '.MAX_LIFE_WITHOUT_ACCESS.', deleting.', $row->id, $row->url);
	$deleteStmt->execute(array($row->id));
	$db->commit();
}