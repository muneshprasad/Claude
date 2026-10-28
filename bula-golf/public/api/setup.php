<?php
declare(strict_types=1);
// One-time setup: creates the tables (if missing) and the first manager login.
// Visit /api/setup.php?key=YOUR_SETUP_KEY once, then DELETE this file.
require __DIR__ . '/lib.php';
$c = cfg();
header('Content-Type: text/html; charset=utf-8');
if (!hash_equals((string)$c['setup_key'], (string)($_GET['key'] ?? '')) || strlen((string)$c['setup_key']) < 16) { http_response_code(403); exit('Forbidden'); }

$pdo = db();
$have = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users'")->fetchColumn();
if (!$have) {
    foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/../../sql/schema.sql')))) as $stmt) {
        if (preg_match('/^(--[^\n]*\n)*\s*$/', $stmt)) continue;
        $pdo->exec($stmt);
    }
    echo '<p>Tables created.</p>';
}
if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) { exit('<p>A manager already exists. Delete setup.php now.</p>'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $un = strtolower(trim((string)$_POST['username'])); $pw = (string)$_POST['password']; $name = trim((string)$_POST['name']);
    if (!preg_match('/^[a-z0-9._-]{3,50}$/', $un) || strlen($pw) < 8 || $name === '') exit('<p>Username 3+ chars, name required, password 8+ chars. Go back and try again.</p>');
    q('INSERT INTO users (username,name,role,pass_hash) VALUES (?,?,?,?)', [$un, $name, 'manager', password_hash($pw, PASSWORD_DEFAULT)]);
    // starter fleet: 65 carts and 40 hire sets (rename, set fees and descriptions in Settings)
    foreach (range(1, 65) as $i) q("INSERT INTO assets (kind,name,fee,sort_order) VALUES ('cart',?,40,?)", ["Cart $i", $i]);
    foreach (range(1, 40) as $i) q("INSERT INTO assets (kind,name,fee,sort_order) VALUES ('clubs',?,50,?)", ["Set $i", $i]);
    exit('<p>Manager created with 65 carts and 40 hire sets. <b>Delete api/setup.php from the server now</b>, then <a href="../">open the app</a>.</p>');
}
?>
<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup</title><body style="font-family:sans-serif;max-width:420px;margin:30px auto">
<h2>Create the first manager</h2>
<form method="post">
<p>Name<br><input name="name" required style="width:100%;padding:8px"></p>
<p>Username<br><input name="username" required style="width:100%;padding:8px"></p>
<p>Password (8+ characters)<br><input name="password" type="password" minlength="8" required style="width:100%;padding:8px"></p>
<button style="padding:10px 16px">Create</button></form></body>
