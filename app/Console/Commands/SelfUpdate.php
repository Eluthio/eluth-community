<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use ZipArchive;

class SelfUpdate extends Command
{
    protected $signature   = 'eluth:update {--check : Only check for updates, do not apply}
                                            {--force : Apply update even if versions match}';
    protected $description = 'Check for and apply community server updates from GitHub releases.';

    // Files and directories that must never be overwritten during an update
    const PRESERVE = [
        '.env',
        'storage',
        'VERSION',
        'public/build',    // keep existing compiled assets until new ones arrive
        'public/uploads',
    ];

    public function handle(): int
    {
        $current = trim(file_get_contents(base_path('VERSION')) ?: '0.0.0');
        $this->line("Installed version : <comment>{$current}</comment>");

        // ── 1. Fetch latest release from GitHub ───────────────────────────────
        $repo = config('eluth.update_repo', 'Eluthio/eluth-community');
        $this->line("Checking          : github.com/{$repo}");

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get("https://api.github.com/repos/{$repo}/releases/latest");
        } catch (\Throwable $e) {
            $this->error('Could not reach GitHub: ' . $e->getMessage());
            return self::FAILURE;
        }

        if ($response->status() === 404) {
            $this->error('No releases found for this repository.');
            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('GitHub API returned HTTP ' . $response->status());
            return self::FAILURE;
        }

        $release = $response->json();
        $latest  = ltrim($release['tag_name'] ?? '0.0.0', 'v');
        $this->line("Latest version    : <info>{$latest}</info>");

        if (! $this->option('force') && version_compare($latest, $current, '<=')) {
            $this->info('Already up to date.');
            return self::SUCCESS;
        }

        if ($this->option('check')) {
            $this->line("Update available  : v{$current} → v{$latest}");
            $this->line('Run <comment>php artisan eluth:update</comment> to apply.');
            return self::SUCCESS;
        }

        // ── 2. Find the zip asset ─────────────────────────────────────────────
        $zipUrl = null;
        foreach ($release['assets'] ?? [] as $asset) {
            if (str_ends_with($asset['name'], '.zip')) {
                $zipUrl = $asset['browser_download_url'];
                break;
            }
        }

        // Fall back to the auto-generated source zip
        $zipUrl ??= $release['zipball_url'] ?? null;

        if (! $zipUrl) {
            $this->error('No downloadable asset found in the release.');
            return self::FAILURE;
        }

        // ── 3. Download ───────────────────────────────────────────────────────
        $tmpZip = sys_get_temp_dir() . '/eluth-update-' . $latest . '.zip';
        $this->line("Downloading       : {$zipUrl}");

        try {
            $bytes = Http::timeout(120)->sink($tmpZip)->get($zipUrl);
        } catch (\Throwable $e) {
            $this->error('Download failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (! file_exists($tmpZip) || filesize($tmpZip) < 1024) {
            $this->error('Downloaded file appears empty or corrupt.');
            return self::FAILURE;
        }

        // ── 4. Extract ────────────────────────────────────────────────────────
        $tmpDir = sys_get_temp_dir() . '/eluth-update-' . $latest;
        if (is_dir($tmpDir)) {
            $this->rmdirRecursive($tmpDir);
        }
        mkdir($tmpDir, 0755, true);

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            $this->error('Could not open zip archive.');
            return self::FAILURE;
        }
        $zip->extractTo($tmpDir);
        $zip->close();
        unlink($tmpZip);

        // GitHub zipballs wrap everything in a single top-level directory — unwrap it
        $entries = array_values(array_filter(
            scandir($tmpDir),
            fn($e) => !in_array($e, ['.', '..'])
        ));
        $srcDir = (count($entries) === 1 && is_dir($tmpDir . '/' . $entries[0]))
            ? $tmpDir . '/' . $entries[0]
            : $tmpDir;

        // ── 5. Copy files (respecting preserve list) ──────────────────────────
        $this->line('Applying update…');
        $base = base_path();
        $this->copyRecursive($srcDir, $base, self::PRESERVE);
        $this->rmdirRecursive($tmpDir);

        // ── 6. Write new VERSION ──────────────────────────────────────────────
        file_put_contents(base_path('VERSION'), $latest . PHP_EOL);

        // ── 7. Post-update tasks ──────────────────────────────────────────────
        $this->line('Running migrations…');
        Artisan::call('migrate', ['--force' => true]);
        $this->line(Artisan::output());

        $this->line('Clearing caches…');
        Artisan::call('optimize:clear');
        Artisan::call('optimize');

        if (app()->bound('queue')) {
            Artisan::call('queue:restart');
        }

        $this->info("✓ Updated to v{$latest} successfully.");
        return self::SUCCESS;
    }

    private function copyRecursive(string $src, string $dst, array $preserve): void
    {
        $base = base_path() . '/';

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        ) as $item) {
            $relative = ltrim(str_replace($src, '', $item->getPathname()), DIRECTORY_SEPARATOR);

            // Skip anything matching the preserve list
            foreach ($preserve as $p) {
                if (str_starts_with($relative, $p)) continue 2;
            }

            $target = $dst . DIRECTORY_SEPARATOR . $relative;

            if ($item->isDir()) {
                if (! is_dir($target)) mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function rmdirRecursive(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
