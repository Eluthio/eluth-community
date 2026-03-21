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
        header('Location: ?step=status');
        exit;
    }
    $loginError = 'Incorrect password.';
}

if (!($_SESSION['ub_auth'] ?? false)) {
    renderLogin($loginError);
    exit;
}

// ── Router ────────────────────────────────────────────────────────────────────
$step = $_GET['step'] ?? 'status';
$act  = $_POST['act'] ?? null;

if ($act && $act !== 'login' && $act !== 'logout') {
    header('Content-Type: text/html; charset=utf-8');
    ob_implicit_flush(true);
    ob_end_flush();
    streamHeader($act);
    match ($act) {
        'download'     => actionDownload(),
        'backup_db'    => actionBackupDb(),
        'backup_files' => actionBackupFiles(),
        'install'      => actionInstall(),
        'migrate'      => actionMigrate(),
        'cleanup'      => actionCleanup(),
        'rollback'     => actionRollback(),
        'delete_backup'=> actionDeleteBackup(),
        default        => log_line('Unknown action.'),
    };
    streamFooter($act);
    exit;
}

renderPage($step);

// ══════════════════════════════════════════════════════════════════════════════
// ACTIONS
// ══════════════════════════════════════════════════════════════════════════════

function actionDownload(): void
{
    $info = latestRelease();
    if (!$info) { log_line('ERROR: Could not fetch release information from GitHub.'); return; }

    $ver     = ltrim($info['tag_name'], 'v');
    $zipUrl  = null;
    foreach ($info['assets'] ?? [] as $asset) {
        if (str_ends_with($asset['name'], '.zip')) { $zipUrl = $asset['browser_download_url']; break; }
    }
    $zipUrl ??= $info['zipball_url'] ?? null;

    if (!$zipUrl) { log_line('ERROR: No downloadable zip found in release.'); return; }

    $stagingDir = UPD_STOR . "/staging-{$ver}";
    if (is_dir($stagingDir)) { log_line("Staging directory already exists — cleaning."); rmdirRecursive($stagingDir); }
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
    log_line('NEXT:preflight');
}

function actionBackupDb(): void
{
    global $cfg;
    $ver = $_SESSION['pending_version'] ?? 'unknown';
    $ts  = date('Ymd-His');
    $out = UPD_STOR . "/db-{$ver}-{$ts}.sql";

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

    log_line('Dumping database…');
    $fh = fopen($out, 'w');
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
        $batch = 500;

        for ($offset = 0; $offset < $count; $offset += $batch) {
            $rows = $pdo->query("SELECT * FROM `{$table}` LIMIT {$batch} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row);
                fwrite($fh, "INSERT INTO `{$table}` VALUES (" . implode(',', $vals) . ");\n");
            }
        }
        fwrite($fh, "\n");
    }

    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);

    log_line('✓ Database dump saved (' . formatBytes(filesize($out)) . ').');
    log_line('NEXT:backup_files');
}

function actionBackupFiles(): void
{
    $ver = $_SESSION['pending_version'] ?? 'unknown';
    $ts  = date('Ymd-His');
    $out = UPD_STOR . "/site-{$ver}-{$ts}.zip";

    // Directories to exclude from the backup
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
    $zip->open($out, ZipArchive::CREATE);
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(BASE, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $count = 0;
    foreach ($iter as $file) {
        $path = $file->getPathname();

        // Skip excluded paths
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

    $zip->close();
    log_line("✓ Site backup complete — {$count} files, " . formatBytes(filesize($out)) . '.');
    log_line('NEXT:install');
}

function actionInstall(): void
{
    $ver = $_SESSION['pending_version'] ?? null;
    if (!$ver) { log_line('ERROR: No pending version found. Run Download first.'); return; }

    $stagingDir = UPD_STOR . "/staging-{$ver}";
    if (!is_dir($stagingDir)) { log_line("ERROR: Staging directory not found for v{$ver}."); return; }

    // Paths never to overwrite (relative to BASE)
    $protected = [
        '.env',
        'storage/',
        'plugins/',
        'public/uploads/',
        'custom-files.json',
    ];

    // Load any custom protected paths
    $customFile = BASE . '/custom-files.json';
    if (file_exists($customFile)) {
        $extra = json_decode(file_get_contents($customFile), true);
        if (is_array($extra)) $protected = array_merge($protected, $extra);
    }

    log_line("Installing v{$ver}…");
    $copied = 0;
    $skipped = 0;

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stagingDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iter as $item) {
        $rel    = ltrim(str_replace($stagingDir, '', $item->getPathname()), DIRECTORY_SEPARATOR);
        $target = BASE . DIRECTORY_SEPARATOR . $rel;

        // Check protected
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

    // Also refresh the update backend itself
    $slug      = $GLOBALS['cfg']['UPDATE_BACKEND_SLUG'] ?? null;
    $stubDir   = $stagingDir . '/stubs/update-backend';
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
    log_line('NEXT:migrate');
}

function actionMigrate(): void
{
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

    log_line('NEXT:cleanup');
}

function actionCleanup(): void
{
    $ver = $_SESSION['pending_version'] ?? null;

    // Write new VERSION
    if ($ver) {
        file_put_contents(VER_FILE, $ver . PHP_EOL);
        log_line("Version file updated to {$ver}.");
    }

    // Clear Laravel caches
    log_line('Clearing application caches…');
    try {
        chdir(BASE);
        require_once BASE . '/vendor/autoload.php';
        $app    = require BASE . '/bootstrap/app.php';
        $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->call('optimize:clear');
        $kernel->call('optimize');
        log_line('✓ Caches cleared and rebuilt.');
    } catch (\Throwable $e) {
        log_line('Warning: Could not clear caches automatically: ' . $e->getMessage());
        log_line('Run: php artisan optimize:clear && php artisan optimize');
    }

    // Remove staging folder
    if ($ver) {
        $stagingDir = UPD_STOR . "/staging-{$ver}";
        if (is_dir($stagingDir)) { rmdirRecursive($stagingDir); log_line('Staging directory removed.'); }
    }

    // Retain only the last 3 sets of backups
    pruneBackups();

    unset($_SESSION['pending_version']);
    log_line('✓ Update complete.');
    log_line('DONE:status');
}

function actionRollback(): void
{
    global $cfg;

    $siteBackup = $_POST['site_backup'] ?? null;
    $dbBackup   = $_POST['db_backup']   ?? null;

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
            $sql = file_get_contents($path);
            $pdo->exec($sql);
            log_line('✓ Database restored.');
        } catch (\Throwable $e) {
            log_line('ERROR: Database restore failed: ' . $e->getMessage());
        }
    }
    log_line('DONE:status');
}

function actionDeleteBackup(): void
{
    $name = basename($_POST['name'] ?? '');
    if ($name === '' || (!str_starts_with($name, 'db-') && !str_starts_with($name, 'site-'))) {
        log_line('ERROR: Invalid backup name.'); return;
    }
    $path = UPD_STOR . '/' . $name;
    if (file_exists($path)) { unlink($path); log_line("Deleted: {$name}"); }
    log_line('DONE:status');
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
    $out = [];
    foreach ($files as $f) {
        $out[] = [
            'name'    => basename($f),
            'size'    => formatBytes(filesize($f)),
            'bytes'   => filesize($f),
            'type'    => str_starts_with(basename($f), 'db-') ? 'Database' : 'Site files',
            'mtime'   => date('Y-m-d H:i', filemtime($f)),
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
        while (count($files) > 3) {
            unlink(array_shift($files));
        }
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
    echo '<div class="log-line">' . htmlspecialchars($msg) . '</div>';
    flush();
}

// ══════════════════════════════════════════════════════════════════════════════
// RENDERING
// ══════════════════════════════════════════════════════════════════════════════

function renderLogin(?string $error): void
{
    html_head('Update Manager — Login');
    ?>
    <div class="login-wrap">
        <div class="login-card">
            <div class="logo">⬡ Eluth</div>
            <div class="card-title">Update Manager</div>
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
    $titles = [
        'status'    => 'Status',
        'download'  => 'Download Update',
        'preflight' => 'Preflight Check',
        'backup'    => 'Backup',
        'install'   => 'Install',
        'rollback'  => 'Rollback',
    ];
    html_head('Update Manager — ' . ($titles[$step] ?? 'Update Manager'));
    ?>
    <div class="shell">
        <nav class="topbar">
            <span class="topbar-brand">⬡ Eluth Update Manager</span>
            <div class="topbar-steps">
                <?php foreach ($titles as $s => $label): ?>
                    <a href="?step=<?= $s ?>" class="step <?= $step === $s ? 'step--active' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
            <form method="POST" style="margin:0">
                <input type="hidden" name="act" value="logout">
                <button class="btn btn--ghost btn--sm">Sign out</button>
            </form>
        </nav>

        <main class="content">
            <?php match ($step) {
                'status'    => pageStatus(),
                'download'  => pageDownload(),
                'preflight' => pagePreflight(),
                'backup'    => pageBackup(),
                'install'   => pageInstall(),
                'rollback'  => pageRollback(),
                default     => pageStatus(),
            }; ?>
        </main>
    </div>
    <?php html_foot();
}

function pageStatus(): void
{
    $current = currentVersion();
    $release = latestRelease();
    $latest  = $release ? ltrim($release['tag_name'], 'v') : null;
    $notes   = $release['body'] ?? null;
    $pending = $_SESSION['pending_version'] ?? null;
    $backups = listBackups();
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
    <div class="page-title">Download Update</div>
    <?php if (!$release): ?>
        <div class="alert alert--error">Could not reach GitHub. Check your internet connection and try again.</div>
    <?php elseif (version_compare($latest, $current, '<=')):  ?>
        <div class="alert alert--info">You are already on the latest version (<?= htmlspecialchars($current) ?>).</div>
    <?php else: ?>
        <p class="body-text">This will download <strong>v<?= htmlspecialchars($latest) ?></strong> into a staging area.
            Nothing will be changed on your live site yet.</p>
        <?php if ($staged): ?>
            <div class="alert alert--info">v<?= htmlspecialchars($latest) ?> is already staged. You can proceed to the next step.</div>
        <?php endif; ?>
        <div class="action-bar">
            <button class="btn btn--primary" onclick="runAction('download')">
                <?= $staged ? 'Re-download v' . htmlspecialchars($latest) : 'Download v' . htmlspecialchars($latest) ?>
            </button>
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
    $ver = $_SESSION['pending_version'] ?? null;
    $stagingDir = $ver ? UPD_STOR . "/staging-{$ver}" : null;
    ?>
    <div class="page-title">Preflight Check</div>
    <?php if (!$ver || !$stagingDir || !is_dir($stagingDir)): ?>
        <div class="alert alert--error">No staged release found. Go back and run Download first.</div>
    <?php else:
        // Build list of changed files
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

        // Count pending migrations
        $migrationsChanged = count(array_filter($changed, fn($f) => str_starts_with($f['rel'], 'database/migrations/')));
        ?>
        <div class="card-row">
            <div class="card">
                <div class="card-label">Files to update</div>
                <div class="card-value"><?= count($changed) ?></div>
            </div>
            <div class="card">
                <div class="card-label">New migrations</div>
                <div class="card-value"><?= $migrationsChanged ?></div>
            </div>
            <div class="card">
                <div class="card-label">Protected (skipped)</div>
                <div class="card-value"><?= count($conflicts) ?></div>
            </div>
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
            <a href="?step=backup" class="btn btn--primary">Proceed to Backup →</a>
        </div>
    <?php endif; ?>
    <?php
}

function pageBackup(): void
{
    ?>
    <div class="page-title">Backup</div>
    <p class="body-text">A full database dump and site file archive will be created before anything is changed.
        This may take a few minutes depending on your site size.</p>
    <div class="steps-list">
        <div>1. Database dump → <code>storage/updates/db-{ver}-{timestamp}.sql</code></div>
        <div>2. Site archive  → <code>storage/updates/site-{ver}-{timestamp}.zip</code></div>
    </div>
    <div class="action-bar">
        <button class="btn btn--primary" onclick="runAction('backup_db')">Start Backup</button>
    </div>
    <div id="log" class="log-box" style="display:none"></div>
    <?php
}

function pageInstall(): void
{
    ?>
    <div class="page-title">Install Update</div>
    <p class="body-text">Files will be copied from the staging area to your live installation.
        Protected paths will be skipped. Database migrations will run automatically after the file copy.</p>
    <div class="action-bar">
        <button class="btn btn--primary" onclick="runAction('install')">Install Files</button>
    </div>
    <div id="log" class="log-box" style="display:none"></div>
    <?php
}

function pageRollback(): void
{
    $backups = listBackups();
    $dbs   = array_filter($backups, fn($b) => $b['type'] === 'Database');
    $sites = array_filter($backups, fn($b) => $b['type'] === 'Site files');
    ?>
    <div class="page-title">Rollback</div>
    <div class="alert alert--error">⚠ This will overwrite live files and/or your database. Make sure this is intentional.</div>
    <form method="POST">
        <input type="hidden" name="act" value="rollback">
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
            <button type="submit" class="btn btn--danger" onclick="return confirm('Are you sure? This will overwrite live data.')">Run Rollback</button>
        </div>
    </form>
    <div id="log" class="log-box" style="display:none"></div>
    <?php
}

// ── HTML shell ────────────────────────────────────────────────────────────────
function streamHeader(string $act): void
{
    $titles = ['download'=>'Downloading…','backup_db'=>'Backing up database…','backup_files'=>'Backing up files…','install'=>'Installing…','migrate'=>'Running migrations…','cleanup'=>'Finishing up…','rollback'=>'Rolling back…','delete_backup'=>'Deleting backup…'];
    $label  = $titles[$act] ?? 'Working…';
    $steps  = ['status'=>'Status','download'=>'Download Update','preflight'=>'Preflight Check','backup'=>'Backup','install'=>'Install','rollback'=>'Rollback'];
    html_head($label);
    echo '<div class="shell">';
    echo '<nav class="topbar">';
    echo '<span class="topbar-brand">⬡ Eluth Update Manager</span>';
    echo '<div class="topbar-steps">';
    foreach ($steps as $s => $slabel) {
        echo '<a href="?step=' . $s . '" class="step">' . $slabel . '</a>';
    }
    echo '</div>';
    echo '<form method="POST" style="margin:0"><input type="hidden" name="act" value="logout"><button class="btn btn--ghost btn--sm">Sign out</button></form>';
    echo '</nav>';
    echo '<main class="content"><div class="page-title">' . htmlspecialchars($label) . '</div><div id="log" class="log-box">';
}

function streamFooter(string $act): void
{
    echo '</div></main></div>';
    ?>
    <script>
    const log = document.getElementById('log');
    if (log) new MutationObserver(() => log.scrollTop = log.scrollHeight).observe(log, {childList:true});

    new MutationObserver(() => {
        const lines = log.querySelectorAll('.log-line');
        const last  = lines[lines.length - 1];
        if (!last) return;
        const txt = last.textContent;
        if (txt.startsWith('NEXT:') || txt.startsWith('DONE:')) {
            setTimeout(() => { window.location = '?step=' + txt.split(':')[1]; }, 800);
        }
    }).observe(log, {childList:true});
    </script>
    <?php html_foot();
}

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
.btn--primary{background:#22d3ee;color:#050810}.btn--primary:hover{background:#67e8f9}
.btn--ghost{background:rgba(255,255,255,.06);color:rgba(255,255,255,.8)}.btn--ghost:hover{background:rgba(255,255,255,.1)}
.btn--danger{background:rgba(248,113,113,.2);color:#f87171}.btn--danger:hover{background:rgba(248,113,113,.35)}
.btn--sm{padding:5px 12px;font-size:12px}
.action-bar{display:flex;gap:10px;align-items:center;margin-top:24px}
.log-box{background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.07);border-radius:8px;padding:16px;font-family:monospace;font-size:12px;max-height:400px;overflow-y:auto;margin-top:20px}
.log-line{padding:2px 0;color:rgba(255,255,255,.7);white-space:pre-wrap}
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
</style>
</head>
<body>
<?php }

function html_foot(): void { ?>
<script>
function runAction(act) {
    const log = document.getElementById('log');
    if (log) log.style.display = 'block';
    const form = document.createElement('form');
    form.method = 'POST';
    const inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'act'; inp.value = act;
    form.appendChild(inp);
    document.body.appendChild(form);
    form.submit();
}
</script>
</body>
</html>
<?php }
