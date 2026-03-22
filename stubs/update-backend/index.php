<?php
declare(strict_types=1);
session_start();
@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');

// ── Paths ─────────────────────────────────────────────────────────────────────
define('BASE',     realpath(__DIR__ . '/../../'));
define('UPD_STOR', BASE . '/storage/updates');
define('VER_FILE', BASE . '/VERSION');
define('GH_REPO',  'Eluthio/eluth-community');

if (!is_dir(UPD_STOR)) mkdir(UPD_STOR, 0755, true);

// ── Read parent .env ──────────────────────────────────────────────────────────
function readEnv(): array
{
    $path = BASE . '/.env';
    if (!file_exists($path)) return [];
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
    return $out;
}

$cfg        = readEnv();
$password   = $cfg['UPDATE_BACKEND_PASSWORD'] ?? '';
$centralUrl = rtrim($cfg['CENTRAL_SERVER_URL'] ?? '', '/');
$ghToken    = $cfg['UPDATE_GITHUB_TOKEN']     ?? '';    // optional, for private repo

// ── Authentication ────────────────────────────────────────────────────────────
$loginError = null;

if (($_POST['act'] ?? '') === 'logout') {
    session_destroy();
    header('Location: ./');
    exit;
}

if (($_POST['act'] ?? '') === 'login') {
    if ($password !== '' && hash_equals($password, $_POST['pw'] ?? '')) {
        $_SESSION['ub_auth'] = true;
        header('Location: ?step=plugins');
        exit;
    }
    $loginError = 'Incorrect password.';
}

if (!($_SESSION['ub_auth'] ?? false)) {
    renderLogin($loginError);
    exit;
}

// ── Router ────────────────────────────────────────────────────────────────────
$step   = $_GET['step']   ?? 'status';
$action = $_GET['action'] ?? null;

// Streaming action endpoint — returns plain text, consumed by fetch() in the browser
if ($action) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    ob_implicit_flush(true);
    @ob_end_flush();
    match ($action) {
        'download' => actionDownload(),
        'backup'   => actionBackup(),
        'install'  => actionInstall(),
        'rollback' => actionRollback(),
        default    => print("Unknown action.\n"),
    };
    exit;
}

// Quick non-streaming actions
if (($_POST['act'] ?? '') === 'delete_backup') {
    $name = basename($_POST['name'] ?? '');
    if ($name && (str_starts_with($name, 'db-') || str_starts_with($name, 'site-'))) {
        $path = UPD_STOR . '/' . $name;
        if (file_exists($path)) unlink($path);
    }
    header('Location: ?step=status');
    exit;
}

if (($_POST['act'] ?? '') === 'enable_plugin') {
    $slug = preg_replace('/[^a-z0-9_-]/', '', $_POST['slug'] ?? '');
    if ($slug) { try { getDB()->prepare("UPDATE plugins SET is_enabled=1, updated_at=NOW() WHERE slug=?")->execute([$slug]); } catch (\Throwable $e) {} }
    header('Location: ?step=plugins&msg=' . urlencode('Plugin enabled.'));
    exit;
}

if (($_POST['act'] ?? '') === 'disable_plugin') {
    $slug = preg_replace('/[^a-z0-9_-]/', '', $_POST['slug'] ?? '');
    if ($slug) { try { getDB()->prepare("UPDATE plugins SET is_enabled=0, updated_at=NOW() WHERE slug=?")->execute([$slug]); } catch (\Throwable $e) {} }
    header('Location: ?step=plugins&msg=' . urlencode('Plugin disabled.'));
    exit;
}

if (($_POST['act'] ?? '') === 'save_plugin_settings') {
    $slug = preg_replace('/[^a-z0-9_-]/', '', $_POST['slug'] ?? '');
    if ($slug) {
        try {
            $db = getDB();
            foreach ($_POST as $k => $v) {
                if (!str_starts_with($k, 'setting_')) continue;
                $key = 'plugin_' . $slug . '_' . substr($k, 8);
                $db->prepare("INSERT INTO server_settings (`key`, `value`, created_at, updated_at) VALUES (?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE `value`=?, updated_at=NOW()")
                   ->execute([$key, $v, $v]);
            }
        } catch (\Throwable $e) {}
    }
    header('Location: ?step=plugins&msg=' . urlencode('Settings saved.'));
    exit;
}

if (($_POST['act'] ?? '') === 'install_plugin') {
    $url = trim($_POST['url'] ?? '');
    $err = installPluginFromUrl($url);
    if ($err) {
        header('Location: ?step=plugins&err=' . urlencode($err));
    } else {
        header('Location: ?step=plugins&msg=' . urlencode('Plugin installed successfully.'));
    }
    exit;
}


if (($_POST['act'] ?? '') === 'uninstall_plugin') {
    $slug = preg_replace('/[^a-z0-9_-]/', '', $_POST['slug'] ?? '');
    if ($slug) {
        try {
            $db  = getDB();
            $dir = BASE . '/storage/app/public/plugins/' . $slug;

            // Optionally run teardown.sql before deleting files
            if (!empty($_POST['run_teardown'])) {
                $teardownPath = $dir . '/teardown.sql';
                if (file_exists($teardownPath)) {
                    $sql = file_get_contents($teardownPath);
                    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                        if (preg_match('/^\s*DROP\s+(TABLE|INDEX)\s+IF\s+EXISTS\s/i', $stmt)) {
                            try { $db->exec($stmt); } catch (\Throwable $e) {}
                        }
                    }
                }
            }

            $db->prepare("DELETE FROM plugins WHERE slug=?")->execute([$slug]);
            $db->prepare("DELETE FROM server_settings WHERE `key` LIKE ?")->execute(['plugin_' . $slug . '_%']);
            if (is_dir($dir)) rmdirRecursive($dir);
        } catch (\Throwable $e) {}
    }
    header('Location: ?step=plugins&msg=' . urlencode('Plugin removed.'));
    exit;
}

if (($_POST['act'] ?? '') === 'upload_emote') {
    $name = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $_POST['emote_name'] ?? ''));
    $file = $_FILES['emote_file'] ?? null;
    $err  = null;
    if (!$name || strlen($name) < 2 || strlen($name) > 32) {
        $err = 'Emote name must be 2–32 characters (letters, numbers, _ or -)';
    } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $err = 'File upload failed (error ' . ($file['error'] ?? '?') . ')';
    } else {
        $allowed = ['image/gif' => 'gif', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime    = mime_content_type($file['tmp_name']);
        $ext     = $allowed[$mime] ?? null;
        if (!$ext) $err = 'Unsupported file type. Use GIF, PNG, or WebP.';
        elseif ($file['size'] > 524288) $err = 'File too large (max 512 KB).';
        else {
            $dir = BASE . '/storage/app/public/emotes';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $filename = $name . '.' . $ext;
            $animated = ($ext === 'gif');
            // Remove old file if name already used
            try {
                $old = getDB()->prepare("SELECT filename FROM custom_emotes WHERE name=?");
                $old->execute([$name]);
                if ($row = $old->fetch()) {
                    $oldPath = $dir . '/' . $row->filename;
                    if (file_exists($oldPath)) unlink($oldPath);
                }
            } catch (\Throwable $e) {}
            if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
                $err = 'Could not save file.';
            } else {
                try {
                    getDB()->prepare("INSERT INTO custom_emotes (name, filename, animated, created_at, updated_at) VALUES (?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE filename=?, animated=?, updated_at=NOW()")
                           ->execute([$name, $filename, $animated ? 1 : 0, $filename, $animated ? 1 : 0]);
                } catch (\Throwable $e) { $err = 'Database error: ' . $e->getMessage(); }
            }
        }
    }
    if ($err) {
        header('Location: ?step=emotes&err=' . urlencode($err));
    } else {
        header('Location: ?step=emotes&msg=' . urlencode(':' . $name . ': uploaded!'));
    }
    exit;
}

if (($_POST['act'] ?? '') === 'delete_emote') {
    $name = preg_replace('/[^a-z0-9_-]/', '', $_POST['emote_name'] ?? '');
    if ($name) {
        try {
            $db  = getDB();
            $row = $db->prepare("SELECT filename FROM custom_emotes WHERE name=?");
            $row->execute([$name]);
            if ($r = $row->fetch()) {
                $path = BASE . '/storage/app/public/emotes/' . $r->filename;
                if (file_exists($path)) unlink($path);
            }
            $db->prepare("DELETE FROM custom_emotes WHERE name=?")->execute([$name]);
        } catch (\Throwable $e) {}
    }
    header('Location: ?step=emotes&msg=' . urlencode('Emote deleted.'));
    exit;
}

renderPage($step);

// ══════════════════════════════════════════════════════════════════════════════
// ACTIONS  (plain-text streaming — no HTML output)
// ══════════════════════════════════════════════════════════════════════════════

function actionDownload(): void
{
    $info = latestRelease();
    if (!$info) { log_line('ERROR: Could not fetch release information from GitHub.'); return; }

    $ver    = ltrim($info['tag_name'], 'v');
    $zipUrl = null;
    foreach ($info['assets'] ?? [] as $asset) {
        if (str_ends_with($asset['name'], '.zip')) { $zipUrl = $asset['browser_download_url']; break; }
    }
    $zipUrl ??= $info['zipball_url'] ?? null;

    if (!$zipUrl) { log_line('ERROR: No downloadable zip found in release.'); return; }

    $stagingDir = UPD_STOR . "/staging-{$ver}";
    if (is_dir($stagingDir)) { log_line('Staging directory already exists — cleaning.'); rmdirRecursive($stagingDir); }
    mkdir($stagingDir, 0755, true);

    $tmpZip = UPD_STOR . "/download-{$ver}.zip";
    log_line("Downloading release v{$ver}…");

    $fp = fopen($tmpZip, 'wb');
    $ch = curl_init($zipUrl);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_USERAGENT      => 'EluthUpdater/1.0',
        CURLOPT_HTTPHEADER     => array_filter([
            'Accept: application/octet-stream',
            $GLOBALS['ghToken'] ? "Authorization: Bearer {$GLOBALS['ghToken']}" : null,
        ]),
    ]);
    curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($err) { log_line("ERROR: Download failed: {$err}"); unlink($tmpZip); return; }

    $size = filesize($tmpZip);
    log_line('Downloaded ' . formatBytes($size) . '. Extracting…');

    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) { log_line('ERROR: Could not open zip.'); return; }
    $zip->extractTo($stagingDir);
    $zip->close();
    unlink($tmpZip);

    // GitHub zipballs wrap in a single top-level folder — unwrap it
    $entries = array_values(array_filter(scandir($stagingDir), fn($e) => !in_array($e, ['.', '..'])));
    if (count($entries) === 1 && is_dir($stagingDir . '/' . $entries[0])) {
        $inner = $stagingDir . '/' . $entries[0];
        foreach (scandir($inner) as $item) {
            if (in_array($item, ['.', '..'])) continue;
            rename($inner . '/' . $item, $stagingDir . '/' . $item);
        }
        rmdir($inner);
    }

    $_SESSION['pending_version'] = $ver;
    log_line("✓ Release v{$ver} staged successfully.");
}

function actionBackup(): void
{
    global $cfg;
    $ver = $_SESSION['pending_version'] ?? 'unknown';
    $ts  = date('Ymd-His');

    // ── 1. Database dump ──────────────────────────────────────────────────────
    log_line('Connecting to database…');
    try {
        $pdo = new PDO(
            "mysql:host={$cfg['DB_HOST']};port={$cfg['DB_PORT']};dbname={$cfg['DB_DATABASE']};charset=utf8mb4",
            $cfg['DB_USERNAME'],
            $cfg['DB_PASSWORD'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (\PDOException $e) {
        log_line('ERROR: Database connection failed: ' . $e->getMessage());
        return;
    }

    $dbOut = UPD_STOR . "/db-{$ver}-{$ts}.sql";
    log_line('Dumping database…');
    $fh = fopen($dbOut, 'w');
    fwrite($fh, "-- Eluth Community Server Database Backup\n");
    fwrite($fh, "-- Version: {$ver}\n");
    fwrite($fh, "-- Generated: " . date('Y-m-d H:i:s') . "\n\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($fh, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
    fwrite($fh, "SET CHARACTER_SET_CLIENT=utf8mb4;\n\n");

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        log_line("  — {$table}");
        $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n");
        fwrite($fh, $create[1] . ";\n\n");
        $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        for ($offset = 0; $offset < $count; $offset += 500) {
            $rows = $pdo->query("SELECT * FROM `{$table}` LIMIT 500 OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row);
                fwrite($fh, "INSERT INTO `{$table}` VALUES (" . implode(',', $vals) . ");\n");
            }
        }
        fwrite($fh, "\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
    log_line('✓ Database dump saved (' . formatBytes(filesize($dbOut)) . ').');

    // ── 2. Site file backup ───────────────────────────────────────────────────
    $siteOut = UPD_STOR . "/site-{$ver}-{$ts}.zip";
    $exclude = [
        UPD_STOR,
        BASE . '/node_modules',
        BASE . '/storage/logs',
        BASE . '/storage/framework/cache',
        BASE . '/storage/framework/sessions',
        BASE . '/storage/framework/views',
    ];

    log_line('Creating site backup zip…');
    $zip  = new ZipArchive();
    $zip->open($siteOut, ZipArchive::CREATE);
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(BASE, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $count = 0;
    foreach ($iter as $file) {
        $path = $file->getPathname();
        foreach ($exclude as $ex) {
            if (str_starts_with($path, $ex)) continue 2;
        }
        $rel = str_replace(BASE . DIRECTORY_SEPARATOR, '', $path);
        if ($file->isDir()) {
            $zip->addEmptyDir($rel);
        } else {
            $zip->addFile($path, $rel);
            $count++;
            if ($count % 200 === 0) log_line("  — {$count} files…");
        }
    }
    log_line("  — {$count} files collected. Zipping, please wait…");
    $zip->close();
    log_line("✓ Site backup complete — {$count} files, " . formatBytes(filesize($siteOut)) . '.');
}

function actionInstall(): void
{
    $ver = $_SESSION['pending_version'] ?? null;
    if (!$ver) { log_line('ERROR: No pending version found. Run Download first.'); return; }

    $stagingDir = UPD_STOR . "/staging-{$ver}";
    if (!is_dir($stagingDir)) { log_line("ERROR: Staging directory not found for v{$ver}."); return; }

    $protected = ['.env', 'storage/', 'plugins/', 'public/uploads/', 'custom-files.json'];
    $customFile = BASE . '/custom-files.json';
    if (file_exists($customFile)) {
        $extra = json_decode(file_get_contents($customFile), true);
        if (is_array($extra)) $protected = array_merge($protected, $extra);
    }

    log_line("Installing v{$ver}…");
    $copied  = 0;
    $skipped = 0;

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stagingDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iter as $item) {
        $rel    = ltrim(str_replace($stagingDir, '', $item->getPathname()), DIRECTORY_SEPARATOR);
        $target = BASE . DIRECTORY_SEPARATOR . $rel;
        foreach ($protected as $p) {
            if (str_starts_with($rel, $p)) { $skipped++; continue 2; }
        }
        if ($item->isDir()) {
            if (!is_dir($target)) mkdir($target, 0755, true);
        } else {
            copy($item->getPathname(), $target);
            $copied++;
            if ($copied % 100 === 0) log_line("  — {$copied} files copied…");
        }
    }

    // Refresh the update backend itself
    $slug    = $GLOBALS['cfg']['UPDATE_BACKEND_SLUG'] ?? null;
    $stubDir = $stagingDir . '/stubs/update-backend';
    if ($slug && is_dir($stubDir)) {
        $backendDir = BASE . '/public/' . $slug;
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stubDir, FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            $rel    = str_replace($stubDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $target = $backendDir . '/' . $rel;
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
            copy($file->getPathname(), $target);
        }
        log_line('  — Update backend refreshed.');
    }

    log_line("✓ {$copied} files installed, {$skipped} protected paths skipped.");

    // ── Migrate ───────────────────────────────────────────────────────────────
    log_line('Running database migrations…');
    $autoload  = BASE . '/vendor/autoload.php';
    $bootstrap = BASE . '/bootstrap/app.php';

    if (!file_exists($autoload) || !file_exists($bootstrap)) {
        log_line('ERROR: Cannot locate vendor/autoload.php or bootstrap/app.php.');
        return;
    }

    try {
        chdir(BASE);
        require_once $autoload;
        $app    = require $bootstrap;
        $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->call('migrate', ['--force' => true]);
        $output = trim($kernel->output());
        foreach (explode("\n", $output) as $line) {
            if (trim($line) !== '') log_line('  ' . $line);
        }
        log_line('✓ Migrations complete.');
    } catch (\Throwable $e) {
        log_line('ERROR: Migration failed: ' . $e->getMessage());
        return;
    }

    // ── Cleanup ───────────────────────────────────────────────────────────────
    file_put_contents(VER_FILE, $ver . PHP_EOL);
    log_line("Version updated to {$ver}.");

    log_line('Clearing application caches…');
    try {
        $kernel->call('optimize:clear');
        log_line('✓ Caches cleared.');
    } catch (\Throwable $e) {
        log_line('Warning: Could not clear caches: ' . $e->getMessage());
    }

    $stagingDir = UPD_STOR . "/staging-{$ver}";
    if (is_dir($stagingDir)) { rmdirRecursive($stagingDir); log_line('Staging directory removed.'); }

    pruneBackups();
    unset($_SESSION['pending_version']);
    log_line('✓ Update complete.');
}

function actionRollback(): void
{
    global $cfg;

    $siteBackup = $_POST['site_backup'] ?? null;
    $dbBackup   = $_POST['db_backup']   ?? null;

    if (!$siteBackup && !$dbBackup) { log_line('ERROR: No backup selected.'); return; }

    if ($siteBackup) {
        $path = UPD_STOR . '/' . basename($siteBackup);
        if (!file_exists($path) || !str_starts_with(basename($path), 'site-')) {
            log_line('ERROR: Invalid site backup path.'); return;
        }
        log_line('Restoring site files from backup…');
        $zip = new ZipArchive();
        $zip->open($path);
        $zip->extractTo(BASE);
        $zip->close();
        log_line('✓ Site files restored.');
    }

    if ($dbBackup) {
        $path = UPD_STOR . '/' . basename($dbBackup);
        if (!file_exists($path) || !str_starts_with(basename($path), 'db-')) {
            log_line('ERROR: Invalid DB backup path.'); return;
        }
        log_line('Restoring database from dump…');
        try {
            $pdo = new PDO(
                "mysql:host={$cfg['DB_HOST']};port={$cfg['DB_PORT']};dbname={$cfg['DB_DATABASE']};charset=utf8mb4",
                $cfg['DB_USERNAME'], $cfg['DB_PASSWORD'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->exec(file_get_contents($path));
            log_line('✓ Database restored.');
        } catch (\Throwable $e) {
            log_line('ERROR: Database restore failed: ' . $e->getMessage());
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════════════════════

function latestRelease(): ?array
{
    $headers = ['Accept: application/vnd.github+json', 'User-Agent: EluthUpdater/1.0'];
    if ($GLOBALS['ghToken']) $headers[] = "Authorization: Bearer {$GLOBALS['ghToken']}";

    $ch = curl_init('https://api.github.com/repos/' . GH_REPO . '/releases/latest');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200 || !$body) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function currentVersion(): string
{
    return file_exists(VER_FILE) ? trim(file_get_contents(VER_FILE)) : '0.0.0';
}

function listBackups(): array
{
    $files = glob(UPD_STOR . '/{db-,site-}*', GLOB_BRACE) ?: [];
    $out   = [];
    foreach ($files as $f) {
        $out[] = [
            'name'  => basename($f),
            'size'  => formatBytes(filesize($f)),
            'bytes' => filesize($f),
            'type'  => str_starts_with(basename($f), 'db-') ? 'Database' : 'Site files',
            'mtime' => date('Y-m-d H:i', filemtime($f)),
        ];
    }
    usort($out, fn($a, $b) => $b['bytes'] - $a['bytes']);
    return $out;
}

function pruneBackups(): void
{
    foreach (['db-', 'site-'] as $prefix) {
        $files = glob(UPD_STOR . '/' . $prefix . '*') ?: [];
        usort($files, fn($a, $b) => filemtime($a) - filemtime($b));
        while (count($files) > 3) unlink(array_shift($files));
    }
}

function rmdirRecursive(string $dir): void
{
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function formatBytes(int $bytes): string
{
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 1)    . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 1)       . ' KB';
    return $bytes . ' B';
}

function log_line(string $msg): void
{
    echo $msg . "\n";
    flush();
}

// ══════════════════════════════════════════════════════════════════════════════
// RENDERING
// ══════════════════════════════════════════════════════════════════════════════

function renderLogin(?string $error): void
{
    html_head('Eluth Backend — Sign In');
    ?>
    <div class="login-wrap">
        <div class="login-card">
            <div class="logo">⬡ Eluth</div>
            <div class="card-title">Server Backend</div>
            <?php if ($error): ?>
                <div class="alert alert--error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="act" value="login">
                <label class="label">Password</label>
                <input type="password" name="pw" class="input" autofocus required>
                <button type="submit" class="btn btn--primary" style="width:100%;margin-top:16px;">Sign in</button>
            </form>
        </div>
    </div>
    <?php html_foot();
}

function renderPage(string $step): void
{
    $mgmt   = ['plugins' => 'Plugins', 'emotes' => 'Emotes'];
    $update = ['status' => 'Status', 'download' => 'Download', 'preflight' => 'Preflight', 'backup' => 'Backup', 'install' => 'Install', 'rollback' => 'Rollback'];
    $all    = array_merge($mgmt, $update);
    html_head('Eluth Backend — ' . ($all[$step] ?? 'Backend'));
    ?>
    <div class="shell">
        <nav class="topbar">
            <span class="topbar-brand">⬡ Eluth</span>
            <div class="topbar-steps">
                <?php foreach ($mgmt as $s => $label): ?>
                    <a href="?step=<?= $s ?>" class="step <?= $step === $s ? 'step--active' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
                <span class="step-sep"></span>
                <?php foreach ($update as $s => $label): ?>
                    <a href="?step=<?= $s ?>" class="step step--update <?= $step === $s ? 'step--active' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
            <form method="POST" style="margin:0">
                <input type="hidden" name="act" value="logout">
                <button class="btn btn--ghost btn--sm">Sign out</button>
            </form>
        </nav>
        <main class="content">
            <?php match ($step) {
                'plugins'   => pagePlugins(),
                'emotes'    => pageEmotes(),
                'status'    => pageStatus(),
                'download'  => pageDownload(),
                'preflight' => pagePreflight(),
                'backup'    => pageBackup(),
                'install'   => pageInstall(),
                'rollback'  => pageRollback(),
                default     => pagePlugins(),
            }; ?>
        </main>
    </div>
    <?php html_foot();
}

function pageStatus(): void
{
    $current  = currentVersion();
    $release  = latestRelease();
    $latest   = $release ? ltrim($release['tag_name'], 'v') : null;
    $notes    = $release['body'] ?? null;
    $backups  = listBackups();
    $upToDate = $latest && version_compare($latest, $current, '<=');
    ?>
    <div class="page-title">Server Status</div>

    <div class="card-row">
        <div class="card">
            <div class="card-label">Installed version</div>
            <div class="card-value"><?= htmlspecialchars($current) ?></div>
        </div>
        <div class="card">
            <div class="card-label">Latest release</div>
            <div class="card-value <?= !$upToDate ? 'card-value--alert' : '' ?>">
                <?= $latest ? htmlspecialchars($latest) : '—' ?>
            </div>
        </div>
        <div class="card">
            <div class="card-label">Status</div>
            <div class="card-value"><?= $upToDate ? '✓ Up to date' : '⚠ Update available' ?></div>
        </div>
    </div>

    <?php if (!$upToDate && $latest): ?>
        <div class="alert alert--info" style="margin-bottom:24px;">
            Version <?= htmlspecialchars($latest) ?> is available.
            <a href="?step=download" class="btn btn--primary btn--sm" style="margin-left:12px;">Start update</a>
        </div>
    <?php endif; ?>

    <?php if ($notes): ?>
        <div class="section-title">Release notes — v<?= htmlspecialchars($latest ?? '') ?></div>
        <div class="notes"><?= nl2br(htmlspecialchars($notes)) ?></div>
    <?php endif; ?>

    <div class="section-title" style="margin-top:32px;">Backups
        <span class="section-sub">(last 3 of each type kept automatically)</span>
    </div>

    <?php if (empty($backups)): ?>
        <div class="empty">No backups yet.</div>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>File</th><th>Type</th><th>Created</th><th>Size</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($backups as $b): ?>
                <tr>
                    <td class="mono"><?= htmlspecialchars($b['name']) ?></td>
                    <td><?= $b['type'] ?></td>
                    <td><?= $b['mtime'] ?></td>
                    <td><?= $b['size'] ?></td>
                    <td>
                        <form method="POST" onsubmit="return confirm('Delete this backup?')" style="margin:0">
                            <input type="hidden" name="act" value="delete_backup">
                            <input type="hidden" name="name" value="<?= htmlspecialchars($b['name']) ?>">
                            <button class="btn btn--danger btn--sm">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
}

function pageDownload(): void
{
    $release = latestRelease();
    $latest  = $release ? ltrim($release['tag_name'], 'v') : null;
    $current = currentVersion();
    $staged  = $latest && is_dir(UPD_STOR . "/staging-{$latest}");
    ?>
    <div class="page-title">Step 1 — Download Update</div>
    <?php if (!$release): ?>
        <div class="alert alert--error">Could not reach GitHub. Check your internet connection and try again.</div>
    <?php elseif (version_compare($latest, $current, '<=')):  ?>
        <div class="alert alert--info">You are already on the latest version (<?= htmlspecialchars($current) ?>).</div>
        <div class="action-bar"><a href="?step=status" class="btn btn--ghost">← Back to Status</a></div>
    <?php else: ?>
        <p class="body-text">This will download <strong>v<?= htmlspecialchars($latest) ?></strong> into a staging area.
            Nothing will be changed on your live site yet.</p>
        <?php if ($staged): ?>
            <div class="alert alert--info" style="margin-bottom:16px;">v<?= htmlspecialchars($latest) ?> is already staged.</div>
        <?php endif; ?>
        <div class="action-bar">
            <button id="run-btn" class="btn btn--primary" onclick="runAction('download')">
                <?= $staged ? 'Re-download v' . htmlspecialchars($latest) : 'Download v' . htmlspecialchars($latest) ?>
            </button>
            <a id="next-btn" href="?step=preflight" class="btn btn--ghost" style="display:none">Next: Preflight →</a>
            <?php if ($staged): ?>
                <a href="?step=preflight" class="btn btn--ghost">Skip to Preflight →</a>
            <?php endif; ?>
        </div>
        <div id="log" class="log-box" style="display:none"></div>
    <?php endif; ?>
    <?php
}

function pagePreflight(): void
{
    $ver        = $_SESSION['pending_version'] ?? null;
    $stagingDir = $ver ? UPD_STOR . "/staging-{$ver}" : null;
    ?>
    <div class="page-title">Step 2 — Preflight Check</div>
    <?php if (!$ver || !$stagingDir || !is_dir($stagingDir)): ?>
        <div class="alert alert--error">No staged release found. Go back and run Download first.</div>
        <div class="action-bar"><a href="?step=download" class="btn btn--ghost">← Download</a></div>
    <?php else:
        $changed   = [];
        $conflicts = [];
        $protected = ['plugins/', '.env', 'storage/', 'public/uploads/', 'custom-files.json'];
        $custom    = file_exists(BASE . '/custom-files.json')
            ? (json_decode(file_get_contents(BASE . '/custom-files.json'), true) ?? [])
            : [];
        $protected = array_merge($protected, $custom);

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stagingDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $item) {
            if ($item->isDir()) continue;
            $rel  = ltrim(str_replace($stagingDir, '', $item->getPathname()), DIRECTORY_SEPARATOR);
            $live = BASE . '/' . $rel;
            foreach ($protected as $p) {
                if (str_starts_with($rel, $p)) { $conflicts[] = $rel; continue 2; }
            }
            if (!file_exists($live)) {
                $changed[] = ['rel' => $rel, 'status' => 'new'];
            } elseif (md5_file($item->getPathname()) !== md5_file($live)) {
                $changed[] = ['rel' => $rel, 'status' => 'changed'];
            }
        }
        $migrationsChanged = count(array_filter($changed, fn($f) => str_starts_with($f['rel'], 'database/migrations/')));
        ?>
        <div class="card-row">
            <div class="card"><div class="card-label">Files to update</div><div class="card-value"><?= count($changed) ?></div></div>
            <div class="card"><div class="card-label">New migrations</div><div class="card-value"><?= $migrationsChanged ?></div></div>
            <div class="card"><div class="card-label">Protected (skipped)</div><div class="card-value"><?= count($conflicts) ?></div></div>
        </div>

        <?php if (!empty($conflicts)): ?>
            <div class="section-title">Protected paths (will not be touched)</div>
            <div class="file-list file-list--safe">
                <?php foreach ($conflicts as $c): ?>
                    <div class="file-row"><span class="badge badge--safe">SKIP</span> <?= htmlspecialchars($c) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="section-title">Files that will change</div>
        <div class="file-list">
            <?php foreach (array_slice($changed, 0, 200) as $f): ?>
                <div class="file-row">
                    <span class="badge badge--<?= $f['status'] === 'new' ? 'new' : 'changed' ?>"><?= strtoupper($f['status']) ?></span>
                    <?= htmlspecialchars($f['rel']) ?>
                </div>
            <?php endforeach; ?>
            <?php if (count($changed) > 200): ?>
                <div class="file-row muted">… and <?= count($changed) - 200 ?> more</div>
            <?php endif; ?>
        </div>

        <div class="action-bar">
            <a href="?step=backup" class="btn btn--primary">Next: Backup →</a>
        </div>
    <?php endif; ?>
    <?php
}

function pageBackup(): void
{
    ?>
    <div class="page-title">Step 3 — Backup</div>
    <p class="body-text">A full database dump and site file archive will be created before anything is changed.
        This may take a few minutes depending on your site size.</p>
    <div class="steps-list">
        <div>1. Database dump → <code>storage/updates/db-{ver}-{timestamp}.sql</code></div>
        <div>2. Site archive  → <code>storage/updates/site-{ver}-{timestamp}.zip</code></div>
    </div>
    <div class="action-bar">
        <button id="run-btn" class="btn btn--primary" onclick="runAction('backup')">Start Backup</button>
        <a id="next-btn" href="?step=install" class="btn btn--ghost" style="display:none">Next: Install →</a>
    </div>
    <div id="log" class="log-box" style="display:none"></div>
    <?php
}

function pageInstall(): void
{
    ?>
    <div class="page-title">Step 4 — Install</div>
    <p class="body-text">Files will be copied from the staging area, database migrations will run, and caches will be rebuilt.
        Protected paths will be skipped. This may take a minute or two.</p>
    <div class="action-bar">
        <button id="run-btn" class="btn btn--primary" onclick="runAction('install')">Install Update</button>
        <a id="next-btn" href="?step=status" class="btn btn--ghost" style="display:none">Done: View Status →</a>
    </div>
    <div id="log" class="log-box" style="display:none"></div>
    <?php
}

function pageRollback(): void
{
    $backups = listBackups();
    $dbs     = array_filter($backups, fn($b) => $b['type'] === 'Database');
    $sites   = array_filter($backups, fn($b) => $b['type'] === 'Site files');
    ?>
    <div class="page-title">Rollback</div>
    <div class="alert alert--error">⚠ This will overwrite live files and/or your database. Make sure this is intentional.</div>
    <form id="rollback-form">
        <div class="form-group">
            <label class="label">Site backup to restore (optional)</label>
            <select name="site_backup" class="input">
                <option value="">— do not restore files —</option>
                <?php foreach ($sites as $b): ?>
                    <option value="<?= htmlspecialchars($b['name']) ?>"><?= htmlspecialchars($b['name']) ?> (<?= $b['size'] ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="label">Database backup to restore (optional)</label>
            <select name="db_backup" class="input">
                <option value="">— do not restore database —</option>
                <?php foreach ($dbs as $b): ?>
                    <option value="<?= htmlspecialchars($b['name']) ?>"><?= htmlspecialchars($b['name']) ?> (<?= $b['size'] ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="action-bar">
            <button id="run-btn" type="button" class="btn btn--danger" onclick="runRollback()">Run Rollback</button>
            <a id="next-btn" href="?step=status" class="btn btn--ghost" style="display:none">Done: View Status →</a>
        </div>
    </form>
    <div id="log" class="log-box" style="display:none"></div>
    <?php
}

// ══════════════════════════════════════════════════════════════════════════════
// DATABASE + PLUGIN HELPERS
// ══════════════════════════════════════════════════════════════════════════════

function getDB(): PDO
{
    global $cfg;
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $pdo = new PDO(
        "mysql:host={$cfg['DB_HOST']};port={$cfg['DB_PORT']};dbname={$cfg['DB_DATABASE']};charset=utf8mb4",
        $cfg['DB_USERNAME'], $cfg['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ]
    );
    return $pdo;
}


function installPluginFromUrl(string $url): ?string
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) return 'Invalid URL.';
    if (!str_ends_with(strtolower($url), '.zip')) return 'URL must point to a .zip file.';

    $tmp = sys_get_temp_dir() . '/eluth-plugin-' . bin2hex(random_bytes(6)) . '.zip';
    $fh  = fopen($tmp, 'wb');
    $ch  = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'EluthBackend/1.0']);
    curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if ($err) { @unlink($tmp); return 'Download failed: ' . $err; }
    if (filesize($tmp) > 20971520) { @unlink($tmp); return 'Plugin zip too large (max 20 MB).'; }

    $extract = sys_get_temp_dir() . '/eluth-plugin-ext-' . bin2hex(random_bytes(6));
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { @unlink($tmp); return 'Could not open zip.'; }
    $zip->extractTo($extract);
    $zip->close();
    @unlink($tmp);

    // Unwrap single top-level directory
    $entries = array_values(array_filter(scandir($extract), fn($e) => !in_array($e, ['.','..'])));
    $root    = (count($entries) === 1 && is_dir($extract . '/' . $entries[0]))
               ? $extract . '/' . $entries[0] : $extract;

    $manifestPath = $root . '/plugin.json';
    if (!file_exists($manifestPath)) { rmdirRecursive($extract); return 'plugin.json not found in zip.'; }

    $manifest = json_decode(file_get_contents($manifestPath), true);
    if (!$manifest) { rmdirRecursive($extract); return 'plugin.json is not valid JSON.'; }

    foreach (['name', 'slug', 'version', 'tier'] as $req) {
        if (empty($manifest[$req])) { rmdirRecursive($extract); return "plugin.json missing required field: {$req}"; }
    }

    $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($manifest['slug']));
    if (!$slug) { rmdirRecursive($extract); return 'Invalid plugin slug.'; }


    // Copy files to public plugin storage
    $dest = BASE . '/storage/app/public/plugins/' . $slug;
    if (is_dir($dest)) rmdirRecursive($dest);
    mkdir($dest, 0755, true);
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    ) as $item) {
        $rel    = str_replace($root . DIRECTORY_SEPARATOR, '', $item->getPathname());
        $target = $dest . '/' . $rel;
        if ($item->isDir()) { if (!is_dir($target)) mkdir($target, 0755, true); }
        else copy($item->getPathname(), $target);
    }
    rmdirRecursive($extract);

    // Run schema.sql if present (CREATE TABLE IF NOT EXISTS statements only)
    $schemaPath = $root . '/schema.sql';
    $db = getDB();
    if (file_exists($schemaPath)) {
        $schemaSql = file_get_contents($schemaPath);
        // Split on semicolons and run each non-empty statement
        foreach (array_filter(array_map('trim', explode(';', $schemaSql))) as $stmt) {
            // Only allow CREATE TABLE / CREATE INDEX statements for safety
            if (preg_match('/^\s*CREATE\s+(TABLE|INDEX|UNIQUE\s+INDEX)\s+IF\s+NOT\s+EXISTS\s/i', $stmt)) {
                $db->exec($stmt);
            }
        }
    }

    // Upsert plugin DB row
    $db->prepare(
        "INSERT INTO plugins (slug, name, tier, manifest, is_enabled, created_at, updated_at)
         VALUES (?, ?, ?, ?, 0, NOW(), NOW())
         ON DUPLICATE KEY UPDATE name=?, tier=?, manifest=?, updated_at=NOW()"
    )->execute([
        $slug, $manifest['name'], $manifest['tier'], json_encode($manifest),
        $manifest['name'], $manifest['tier'], json_encode($manifest),
    ]);

    return null; // success
}

// ══════════════════════════════════════════════════════════════════════════════
// MANAGEMENT PAGES
// ══════════════════════════════════════════════════════════════════════════════

function pagePlugins(): void
{
    global $cfg;
    $msg = $_GET['msg'] ?? null;
    $err = $_GET['err'] ?? null;

    // Fetch installed plugins from local DB
    try {
        $db        = getDB();
        $installed = $db->query("SELECT * FROM plugins ORDER BY name")->fetchAll();
        $installedSlugs = array_column((array)$installed, 'slug');
    } catch (\Throwable $e) {
        echo '<div class="alert alert--error">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        return;
    }

    // Fetch catalogue from central
    $catalogue    = [];
    $catalogueErr = null;
    $centralUrl   = rtrim($cfg['CENTRAL_SERVER_URL'] ?? '', '/');
    if ($centralUrl) {
        $ch = curl_init($centralUrl . '/api/plugins/catalogue');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_USERAGENT      => 'EluthBackend/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status === 200 && $body) {
            $data      = json_decode($body, true);
            $catalogue = $data['plugins'] ?? [];
        } else {
            $catalogueErr = 'Could not reach the Eluth plugin store.';
        }
    } else {
        $catalogueErr = 'CENTRAL_SERVER_URL not set in .env.';
    }

    // Split catalogue: available = not yet installed
    $available = array_values(array_filter($catalogue, fn($p) => !in_array($p['slug'], $installedSlugs)));
    usort($available, fn($a, $b) => ($b['recommended'] <=> $a['recommended']) ?: strcmp($a['name'], $b['name']));

    // Collect all unique tags across available plugins for filter bar
    $allTags = [];
    foreach ($available as $p) {
        foreach (($p['tags'] ?? []) as $tag) {
            $allTags[$tag] = true;
        }
    }
    ksort($allTags);
    $allTags = array_keys($allTags);
    ?>
    <div class="page-title">Plugin Manager</div>

    <?php if ($msg): ?><div class="alert alert--info"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert--error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <!-- ── Installed plugins ─────────────────────────────────────────────── -->
    <div class="section-title">Installed</div>

    <?php if (empty($installed)): ?>
        <div class="empty">No plugins installed yet. Browse the store below.</div>
    <?php else: foreach ($installed as $plugin):
        $manifest   = json_decode($plugin->manifest ?? '{}', true) ?? [];
        $settings   = $manifest['settings'] ?? [];
        $isEnabled  = (bool) $plugin->is_enabled;

        $settingValues = [];
        if ($settings) {
            $stmt = $db->prepare("SELECT `key`, `value` FROM server_settings WHERE `key` LIKE ?");
            $stmt->execute(['plugin_' . $plugin->slug . '_%']);
            foreach ($stmt->fetchAll() as $row) {
                $k = str_replace('plugin_' . $plugin->slug . '_', '', $row->key);
                $settingValues[$k] = $row->value;
            }
        }
    ?>
    <div class="plugin-card <?= $isEnabled ? 'plugin-card--on' : '' ?>">
        <div class="plugin-card-header">
            <div class="plugin-meta">
                <span class="plugin-name"><?= htmlspecialchars($plugin->name) ?></span>
                <span class="plugin-badge plugin-badge--<?= htmlspecialchars($plugin->tier) ?>"><?= htmlspecialchars($plugin->tier) ?></span>
                <?php if ($manifest['version'] ?? null): ?>
                    <span class="plugin-ver">v<?= htmlspecialchars($manifest['version']) ?></span>
                <?php endif; ?>
                <span class="plugin-status-dot <?= $isEnabled ? 'dot--on' : 'dot--off' ?>"></span>
                <span class="plugin-status-label"><?= $isEnabled ? 'Enabled' : 'Disabled' ?></span>
            </div>
            <?php if ($manifest['description'] ?? null): ?>
                <div class="plugin-desc"><?= htmlspecialchars($manifest['description']) ?></div>
            <?php endif; ?>
            <div class="plugin-actions">
                <?php if ($isEnabled): ?>
                    <form method="POST" style="margin:0">
                        <input type="hidden" name="act" value="disable_plugin">
                        <input type="hidden" name="slug" value="<?= htmlspecialchars($plugin->slug) ?>">
                        <button class="btn btn--ghost btn--sm">Disable</button>
                    </form>
                <?php else: ?>
                    <form method="POST" style="margin:0">
                        <input type="hidden" name="act" value="enable_plugin">
                        <input type="hidden" name="slug" value="<?= htmlspecialchars($plugin->slug) ?>">
                        <button class="btn btn--primary btn--sm">Enable</button>
                    </form>
                <?php endif; ?>
                <?php $hasTeardown = file_exists(BASE . '/storage/app/public/plugins/' . $plugin->slug . '/teardown.sql'); ?>
                <form method="POST" style="margin:0" onsubmit="return confirmUninstall(this, <?= $hasTeardown ? 'true' : 'false' ?>, '<?= htmlspecialchars(addslashes($plugin->name)) ?>')">
                    <input type="hidden" name="act" value="uninstall_plugin">
                    <input type="hidden" name="slug" value="<?= htmlspecialchars($plugin->slug) ?>">
                    <input type="hidden" name="run_teardown" value="0">
                    <button class="btn btn--danger btn--sm">Uninstall</button>
                </form>
            </div>
        </div>

        <?php if ($isEnabled && $settings): ?>
        <form method="POST" class="plugin-settings">
            <input type="hidden" name="act" value="save_plugin_settings">
            <input type="hidden" name="slug" value="<?= htmlspecialchars($plugin->slug) ?>">
            <?php foreach ($settings as $s): ?>
            <div class="setting-row">
                <label class="label"><?= htmlspecialchars($s['label']) ?></label>
                <input
                    class="input setting-input"
                    type="<?= ($s['type'] ?? 'text') === 'password' ? 'password' : 'text' ?>"
                    name="setting_<?= htmlspecialchars($s['key']) ?>"
                    value="<?= htmlspecialchars($settingValues[$s['key']] ?? '') ?>"
                    placeholder="<?= htmlspecialchars($s['placeholder'] ?? '') ?>"
                />
            </div>
            <?php endforeach; ?>
            <div style="margin-top:10px;"><button class="btn btn--ghost btn--sm">Save settings</button></div>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>

    <!-- ── Plugin Store ───────────────────────────────────────────────────── -->
    <div class="store-header">
        <div class="section-title" style="margin:0;">Plugin Store</div>
        <?php if (!$catalogueErr && !empty($available)): ?>
        <input id="store-search" class="input store-search" type="search" placeholder="Search plugins…" oninput="filterStore()" autocomplete="off" />
        <?php endif; ?>
    </div>

    <?php if ($catalogueErr): ?>
        <div class="alert alert--error"><?= htmlspecialchars($catalogueErr) ?></div>
    <?php elseif (empty($available)): ?>
        <div class="empty">All available plugins are already installed.</div>
    <?php else: ?>

        <?php if ($allTags): ?>
        <div class="tag-filter-bar">
            <button class="tag-filter active" data-tag="all" onclick="setTagFilter(this)">All</button>
            <?php foreach ($allTags as $tag): ?>
            <button class="tag-filter" data-tag="<?= htmlspecialchars($tag) ?>" onclick="setTagFilter(this)"><?= htmlspecialchars(ucfirst($tag)) ?></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="store-grid" id="store-grid">
        <?php foreach ($available as $p):
            $tags    = $p['tags'] ?? [];
            $tagsJson = htmlspecialchars(json_encode($tags), ENT_QUOTES);
        ?>
            <div class="store-card"
                 data-name="<?= htmlspecialchars(strtolower($p['name'])) ?>"
                 data-desc="<?= htmlspecialchars(strtolower($p['description'] ?? '')) ?>"
                 data-tags="<?= $tagsJson ?>">
                <div class="store-card-icon">
                    <?= strtoupper(substr($p['name'], 0, 2)) ?>
                </div>
                <div class="store-card-body">
                    <div class="store-card-top">
                        <div class="plugin-meta">
                            <span class="plugin-name"><?= htmlspecialchars($p['name']) ?></span>
                            <span class="plugin-badge plugin-badge--<?= htmlspecialchars($p['tier']) ?>"><?= htmlspecialchars($p['tier']) ?></span>
                            <?php if ($p['recommended']): ?>
                                <span class="plugin-badge plugin-badge--recommended">Recommended</span>
                            <?php endif; ?>
                            <?php if ($p['version'] ?? null): ?>
                                <span class="plugin-ver">v<?= htmlspecialchars($p['version']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($p['description'] ?? null): ?>
                            <div class="plugin-desc"><?= htmlspecialchars($p['description']) ?></div>
                        <?php endif; ?>
                        <?php if ($tags): ?>
                        <div class="store-tags">
                            <?php foreach ($tags as $tag): ?>
                            <span class="store-tag"><?= htmlspecialchars($tag) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="store-card-footer">
                        <?php if ($p['homepage'] ?? null): ?>
                            <a href="<?= htmlspecialchars($p['homepage']) ?>" target="_blank" rel="noopener" class="btn btn--ghost btn--sm">Docs</a>
                        <?php endif; ?>
                        <?php if ($p['github_zip_url'] ?? null): ?>
                            <form method="POST" style="margin:0">
                                <input type="hidden" name="act" value="install_plugin">
                                <input type="hidden" name="url" value="<?= htmlspecialchars($p['github_zip_url']) ?>">
                                <button class="btn btn--primary btn--sm">Install</button>
                            </form>
                        <?php else: ?>
                            <span class="muted" style="font-size:12px;">Coming soon</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <div id="store-empty" class="empty" style="display:none;">No plugins match your search.</div>

    <?php endif; ?>

    <!-- ── Install unofficial ────────────────────────────────────────────── -->
    <div class="section-title" style="margin-top:32px;">Install unofficial plugin</div>
    <p class="body-text">Paste a direct link to a GitHub release <code>.zip</code>. Unofficial plugins run in a sandboxed iframe and cannot access your data directly.</p>
    <form method="POST">
        <input type="hidden" name="act" value="install_plugin">
        <div class="form-group">
            <label class="label">GitHub release .zip URL</label>
            <input class="input" type="url" name="url" placeholder="https://github.com/…/releases/download/v1.0.0/plugin.zip" style="max-width:540px;" required />
        </div>
        <button class="btn btn--ghost">Install</button>
    </form>
    <?php
}

function pageEmotes(): void
{
    try {
        $db     = getDB();
        $emotes = $db->query("SELECT * FROM custom_emotes ORDER BY name")->fetchAll();
    } catch (\Throwable $e) {
        echo '<div class="alert alert--error">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        return;
    }
    $msg = $_GET['msg'] ?? null;
    $err = $_GET['err'] ?? null;
    ?>
    <div class="page-title">Server Emotes</div>
    <p class="body-text">Upload custom emotes for your server. Members type <code>:emote_name:</code> to use them. Animated GIFs are supported. Max 512 KB per file.</p>

    <?php if ($msg): ?><div class="alert alert--info"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert--error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <!-- Upload form -->
    <div class="upload-card">
        <div class="section-title" style="margin:0 0 14px;">Upload emote</div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="act" value="upload_emote">
            <div class="upload-row">
                <div class="form-group" style="margin:0;flex:0 0 200px;">
                    <label class="label">Name <span class="muted">(2–32 chars, a-z 0-9 _ -)</span></label>
                    <input class="input" type="text" name="emote_name" placeholder="e.g. pepehappy" pattern="[a-zA-Z0-9_\-]{2,32}" required />
                </div>
                <div class="form-group" style="margin:0;flex:1;">
                    <label class="label">File <span class="muted">(GIF, PNG, WebP)</span></label>
                    <input class="input" type="file" name="emote_file" accept="image/gif,image/png,image/webp" required style="padding:6px 10px;" />
                </div>
                <div style="padding-top:22px;">
                    <button class="btn btn--primary">Upload</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Emote grid -->
    <?php if (empty($emotes)): ?>
        <div class="empty">No custom emotes yet. Upload one above.</div>
    <?php else: ?>
        <div class="section-title">Emotes (<?= count($emotes) ?>)</div>
        <div class="emote-grid">
            <?php foreach ($emotes as $emote):
                $imgSrc = '../storage/emotes/' . htmlspecialchars($emote->filename);
            ?>
            <div class="emote-tile">
                <img src="<?= $imgSrc ?>" alt=":<?= htmlspecialchars($emote->name) ?>:" class="emote-img" loading="lazy" />
                <div class="emote-name">:<?= htmlspecialchars($emote->name) ?>:</div>
                <?php if ($emote->animated): ?>
                    <div class="emote-anim-badge">GIF</div>
                <?php endif; ?>
                <form method="POST" onsubmit="return confirm('Delete :<?= htmlspecialchars(addslashes($emote->name)) ?>:?')" style="margin:0">
                    <input type="hidden" name="act" value="delete_emote">
                    <input type="hidden" name="emote_name" value="<?= htmlspecialchars($emote->name) ?>">
                    <button class="btn btn--danger btn--sm" style="width:100%;">Delete</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php
}

// ── HTML shell ────────────────────────────────────────────────────────────────
function html_head(string $title): void { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($title) ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,sans-serif;font-size:14px;background:#050810;color:rgba(255,255,255,.88);min-height:100vh}
a{color:#22d3ee;text-decoration:none}
.shell{display:flex;flex-direction:column;min-height:100vh}
.topbar{display:flex;align-items:center;gap:16px;padding:0 24px;height:52px;background:rgba(5,8,16,.85);border-bottom:1px solid rgba(255,255,255,.07);flex-shrink:0}
.topbar-brand{font-weight:700;font-size:15px;color:#22d3ee;flex-shrink:0}
.topbar-steps{display:flex;gap:4px;flex:1}
.step{padding:4px 12px;border-radius:6px;font-size:13px;color:rgba(255,255,255,.5);transition:all .15s}
.step:hover{color:rgba(255,255,255,.85);background:rgba(255,255,255,.05)}
.step--active{color:#22d3ee;background:rgba(34,211,238,.1)}
.content{padding:32px 40px;max-width:900px;width:100%}
.page-title{font-size:20px;font-weight:700;margin-bottom:24px}
.section-title{font-size:13px;font-weight:600;color:rgba(255,255,255,.45);text-transform:uppercase;letter-spacing:.06em;margin:20px 0 10px}
.section-sub{font-size:11px;font-weight:400;text-transform:none;letter-spacing:0;margin-left:8px}
.body-text{color:rgba(255,255,255,.65);margin-bottom:20px;line-height:1.6}
.card-row{display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap}
.card{flex:1;min-width:160px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:8px;padding:16px 20px}
.card-label{font-size:11px;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
.card-value{font-size:22px;font-weight:700}
.card-value--alert{color:#fb923c}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:13px}
.alert--error{background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.25);color:#fca5a5}
.alert--info{background:rgba(34,211,238,.08);border:1px solid rgba(34,211,238,.2);color:#67e8f9}
.btn{display:inline-flex;align-items:center;padding:8px 18px;border-radius:7px;font-size:14px;font-weight:600;cursor:pointer;border:none;transition:all .15s;text-decoration:none}
.btn--primary{background:#22d3ee;color:#050810}.btn--primary:hover:not(:disabled){background:#67e8f9}
.btn--ghost{background:rgba(255,255,255,.06);color:rgba(255,255,255,.8)}.btn--ghost:hover{background:rgba(255,255,255,.1)}
.btn--danger{background:rgba(248,113,113,.2);color:#f87171}.btn--danger:hover:not(:disabled){background:rgba(248,113,113,.35)}
.btn--sm{padding:5px 12px;font-size:12px}
.btn:disabled{opacity:.4;cursor:not-allowed}
.action-bar{display:flex;gap:10px;align-items:center;margin-top:24px}
.log-box{background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.07);border-radius:8px;padding:16px;font-family:monospace;font-size:12px;max-height:400px;overflow-y:auto;margin-top:20px}
.log-line{padding:2px 0;color:rgba(255,255,255,.7);white-space:pre-wrap}
.log-line--error{color:#f87171}
.table{width:100%;border-collapse:collapse;font-size:13px}
.table th{text-align:left;padding:8px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:rgba(255,255,255,.35);border-bottom:1px solid rgba(255,255,255,.07)}
.table td{padding:8px 12px;border-bottom:1px solid rgba(255,255,255,.04)}
.table tr:hover td{background:rgba(255,255,255,.02)}
.mono{font-family:monospace;font-size:12px}
.file-list{background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.06);border-radius:8px;max-height:300px;overflow-y:auto;font-size:12px;font-family:monospace}
.file-row{display:flex;align-items:center;gap:8px;padding:4px 12px;border-bottom:1px solid rgba(255,255,255,.03)}
.file-row:last-child{border-bottom:none}
.badge{font-size:9px;font-weight:700;letter-spacing:.05em;padding:2px 5px;border-radius:3px;flex-shrink:0}
.badge--new{background:rgba(52,211,153,.2);color:#6ee7b7}
.badge--changed{background:rgba(251,191,36,.15);color:#fcd34d}
.badge--safe{background:rgba(148,163,184,.1);color:#94a3b8}
.muted{color:rgba(255,255,255,.3)}
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh}
.login-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:36px;width:360px}
.logo{font-size:24px;font-weight:800;color:#22d3ee;margin-bottom:8px}
.card-title{font-size:18px;font-weight:700;margin-bottom:24px;color:rgba(255,255,255,.7)}
.label{display:block;font-size:12px;color:rgba(255,255,255,.4);margin-bottom:6px}
.input{width:100%;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:6px;padding:9px 12px;color:rgba(255,255,255,.9);font-size:14px}
.input:focus{outline:none;border-color:#22d3ee}
.form-group{margin-bottom:16px}
.steps-list{display:flex;flex-direction:column;gap:8px;font-size:13px;color:rgba(255,255,255,.6);margin-bottom:20px}
.notes{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);border-radius:8px;padding:16px;font-size:13px;color:rgba(255,255,255,.6);line-height:1.7;white-space:pre-wrap}
.empty{color:rgba(255,255,255,.3);font-size:13px;padding:12px 0}
code{background:rgba(255,255,255,.06);border-radius:3px;padding:1px 5px;font-size:12px;font-family:monospace}
.step-sep{width:1px;background:rgba(255,255,255,.1);margin:8px 4px;flex-shrink:0}
.step--update{color:rgba(255,255,255,.35)}
/* Plugin manager */
.plugin-card{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:18px 20px;margin-bottom:12px;transition:border-color .15s}
.plugin-card--on{border-color:rgba(34,211,238,.25);background:rgba(34,211,238,.03)}
.plugin-card-header{display:flex;flex-direction:column;gap:8px}
.plugin-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.plugin-name{font-size:15px;font-weight:700;color:rgba(255,255,255,.9)}
.plugin-badge{font-size:10px;font-weight:700;letter-spacing:.04em;padding:2px 7px;border-radius:4px;text-transform:uppercase}
.plugin-badge--official{background:rgba(34,211,238,.15);color:#67e8f9}
.plugin-badge--approved{background:rgba(52,211,153,.15);color:#6ee7b7}
.plugin-badge--community{background:rgba(251,191,36,.12);color:#fcd34d}
.plugin-ver{font-size:11px;color:rgba(255,255,255,.3);font-family:monospace}
.plugin-desc{font-size:13px;color:rgba(255,255,255,.5);margin-top:2px}
.plugin-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px}
.plugin-settings{border-top:1px solid rgba(255,255,255,.06);margin-top:14px;padding-top:14px}
.setting-row{display:flex;align-items:center;gap:12px;margin-bottom:10px;flex-wrap:wrap}
.setting-row .label{min-width:140px;margin:0;flex-shrink:0}
.setting-input{max-width:340px}
/* Emotes */
.upload-card{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:20px;margin-bottom:24px}
.upload-row{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}
.emote-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px;margin-top:8px}
.emote-tile{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:8px;padding:10px 8px;display:flex;flex-direction:column;align-items:center;gap:6px;position:relative}
.emote-img{width:48px;height:48px;object-fit:contain;border-radius:4px}
.emote-name{font-size:11px;font-family:monospace;color:rgba(255,255,255,.55);text-align:center;word-break:break-all}
.emote-anim-badge{position:absolute;top:6px;right:6px;font-size:9px;font-weight:700;background:rgba(88,101,242,.3);color:#a5b4fc;border-radius:3px;padding:1px 4px;letter-spacing:.04em}
.plugin-status-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.dot--on{background:#34d399}.dot--off{background:rgba(255,255,255,.2)}
.plugin-status-label{font-size:11px;color:rgba(255,255,255,.35)}
/* Store */
.store-header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:32px;margin-bottom:14px;flex-wrap:wrap}
.store-search{max-width:260px;padding:7px 12px;font-size:13px}
.tag-filter-bar{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px}
.tag-filter{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:4px 12px;font-size:12px;color:rgba(255,255,255,.5);cursor:pointer;transition:all .15s}
.tag-filter:hover{border-color:rgba(34,211,238,.4);color:rgba(255,255,255,.8)}
.tag-filter.active{background:rgba(34,211,238,.15);border-color:rgba(34,211,238,.4);color:#67e8f9}
.store-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px}
.store-card{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:16px;display:flex;gap:14px;transition:border-color .15s}
.store-card:hover{border-color:rgba(34,211,238,.2)}
.store-card-icon{width:44px;height:44px;border-radius:10px;background:rgba(34,211,238,.1);border:1px solid rgba(34,211,238,.2);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#22d3ee;flex-shrink:0;letter-spacing:-.02em}
.store-card-body{display:flex;flex-direction:column;gap:8px;flex:1;min-width:0}
.store-card-top{display:flex;flex-direction:column;gap:5px}
.store-card-footer{display:flex;gap:8px;align-items:center;margin-top:4px}
.store-tags{display:flex;flex-wrap:wrap;gap:4px;margin-top:2px}
.store-tag{font-size:10px;font-weight:600;padding:2px 7px;border-radius:4px;background:rgba(255,255,255,.06);color:rgba(255,255,255,.4);text-transform:lowercase;letter-spacing:.03em}
.plugin-badge--recommended{background:rgba(251,191,36,.15);color:#fcd34d}
</style>
</head>
<body>
<?php }

function html_foot(): void { ?>
<script>
function confirmUninstall(form, hasTeardown, name) {
    if (!confirm('Uninstall ' + name + '? Plugin files and settings will be deleted.')) return false;
    if (hasTeardown) {
        const removeTables = confirm('Also remove this plugin\'s database tables?\n\nClick OK to remove tables, or Cancel to keep them.');
        form.querySelector('[name="run_teardown"]').value = removeTables ? '1' : '0';
    }
    return true;
}

function filterStore() {
    const q          = (document.getElementById('store-search')?.value || '').toLowerCase();
    const activeBtn  = document.querySelector('.tag-filter.active');
    const activeTag  = activeBtn ? activeBtn.dataset.tag : 'all';
    let   anyVisible = false;
    document.querySelectorAll('#store-grid .store-card').forEach(card => {
        const tags  = JSON.parse(card.dataset.tags || '[]');
        const name  = card.dataset.name  || '';
        const desc  = card.dataset.desc  || '';
        const matchTag    = activeTag === 'all' || tags.includes(activeTag);
        const matchSearch = !q || name.includes(q) || desc.includes(q) || tags.some(t => t.includes(q));
        const show = matchTag && matchSearch;
        card.style.display = show ? '' : 'none';
        if (show) anyVisible = true;
    });
    const empty = document.getElementById('store-empty');
    if (empty) empty.style.display = anyVisible ? 'none' : '';
}
function setTagFilter(btn) {
    document.querySelectorAll('.tag-filter').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    filterStore();
}
function streamFetch(url, body) {
    const log     = document.getElementById('log');
    const runBtn  = document.getElementById('run-btn');
    const nextBtn = document.getElementById('next-btn');

    if (runBtn)  { runBtn.disabled = true; runBtn.textContent = 'Running…'; }
    if (log)     { log.innerHTML = ''; log.style.display = 'block'; }

    fetch(url, {method: 'POST', body: body})
        .then(resp => {
            const reader = resp.body.getReader();
            const dec    = new TextDecoder();
            let   buf    = '';

            function pump() {
                return reader.read().then(({done, value}) => {
                    if (value) {
                        buf += dec.decode(value, {stream: !done});
                        const parts = buf.split('\n');
                        buf = parts.pop();
                        parts.forEach(line => appendLine(log, line));
                    }
                    if (done) {
                        if (buf.trim()) appendLine(log, buf);
                        const hasError = log.querySelector('.log-line--error');
                        if (hasError) {
                            if (runBtn) { runBtn.disabled = false; runBtn.textContent = 'Retry'; }
                        } else if (nextBtn) {
                            nextBtn.style.display = 'inline-flex';
                        }
                        return;
                    }
                    return pump();
                });
            }
            return pump();
        })
        .catch(err => appendLine(log, 'ERROR: ' + err.message, true));
}

function appendLine(log, text, forceError) {
    if (!text.trim()) return;
    const d = document.createElement('div');
    d.className = 'log-line' + (forceError || text.startsWith('ERROR:') ? ' log-line--error' : '');
    d.textContent = text;
    log.appendChild(d);
    log.scrollTop = log.scrollHeight;
}

function runAction(act) {
    streamFetch('?action=' + act, new FormData());
}

function runRollback() {
    if (!confirm('Are you sure? This will overwrite live data.')) return;
    streamFetch('?action=rollback', new FormData(document.getElementById('rollback-form')));
}
</script>
</body>
</html>
<?php }
