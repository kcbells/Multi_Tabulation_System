<?php
/**
 * One-time installer: creates the database (from .env), tables, storage folders
 * and the first administrator. Safe to re-run — data is kept, and the admin
 * form only appears while no admin account exists.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Csrf;
use App\Core\SchemaUpgrader;
use App\Storage\StorageManager;

$c = config('db');
$messages = [];
$errors = [];
$needsAdmin = false;
$pdo = null;

try {
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port']), (string) $c['user'], (string) $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $dbName = str_replace('`', '', (string) $c['name']);
    try {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Throwable $ignored) {
        // shared hosting (e.g. InfinityFree): the database is created in the control panel and cannot be created here
    }
    $pdo->exec("USE `{$dbName}`");
    // Tables, then upgrades for existing installs: new columns first, then performance indexes
    $upgrades = (new SchemaUpgrader($pdo, $dbName))->upgrade();
    \App\Core\SchemaGuard::markCurrent();
    $messages[] = 'Database “' . e($dbName) . '” and tables are ready.';
    $messages[] = $upgrades
        ? 'Database upgraded: ' . e(implode(' ', $upgrades))
        : 'Database structure and indexes are up to date.';
    $needsAdmin = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() === 0;
} catch (Throwable $ex) {
    $errors[] = 'Database error: ' . $ex->getMessage() . ' — check the DB_* values in .env and make sure MySQL is running.';
}

try {
    $messages[] = 'File server: ' . e(StorageManager::disk()->describe()) . ' (STORAGE_DRIVER=' . e(config('storage.driver')) . ').';
} catch (Throwable $ex) {
    $errors[] = 'File server configuration problem: ' . $ex->getMessage();
}

if ($pdo && $needsAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $username = strtolower(trim((string) ($_POST['username'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    if (!hash_equals(Csrf::token(), (string) ($_POST['_csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($name === '' || !preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
        $errors[] = 'Enter a name and a username of 3–60 letters, numbers, dots, dashes or underscores.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'The password must be at least 8 characters.';
    } else {
        $st = $pdo->prepare("INSERT INTO users (name, username, password_hash, role) VALUES (?, ?, ?, 'admin')");
        $st->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT)]);
        $needsAdmin = false;
        $messages[] = 'Administrator account created. You can now sign in.';
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup · <?= e(config('app.name')) ?></title>
<link rel="icon" type="image/png" href="<?= e(base_url('assets/images/app-icon.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="auth-body">
<div class="auth-bg" aria-hidden="true"></div>
<main class="auth-wrap">
  <section class="auth-card">
    <div class="auth-head">
      <img src="<?= e(base_url('assets/images/app-icon.png')) ?>" alt="PHINMA Education">
      <div><span class="auth-kicker">Multi-Event Tabulation System</span><h1 class="auth-title">System setup</h1><p class="auth-sub"><?= e(config('app.school')) ?></p></div>
    </div>
    <?php foreach ($errors as $m): ?><div class="alert alert-error"><?= e($m) ?></div><?php endforeach; ?>
    <?php foreach ($messages as $m): ?><div class="alert alert-success"><?= $m ?></div><?php endforeach; ?>

    <?php if ($pdo && $needsAdmin): ?>
      <h2 style="margin:18px 0 4px">Create the administrator</h2>
      <form method="post" class="stack">
        <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
        <label class="field"><span>Full name</span><input name="name" required value="<?= e($_POST['name'] ?? '') ?>"></label>
        <label class="field"><span>Username</span><input name="username" required autocomplete="username" value="<?= e($_POST['username'] ?? 'admin') ?>"></label>
        <label class="field"><span>Password <em>(at least 8 characters)</em></span><input name="password" type="password" required minlength="8" autocomplete="new-password"></label>
        <button class="btn btn-primary btn-block">Create admin account</button>
      </form>
    <?php elseif ($pdo): ?>
      <p class="muted">Setup is complete.</p>
      <a class="btn btn-primary btn-block" href="<?= e(base_url('index.html')) ?>">Go to sign in</a>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
