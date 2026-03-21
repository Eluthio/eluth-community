<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SetupUpdater extends Command
{
    protected $signature   = 'eluth:setup-updater {--regenerate : Regenerate slug and password even if already configured}';
    protected $description = 'Create the isolated update backend at a randomised public URL.';

    public function handle(): int
    {
        $envPath = base_path('.env');
        $env     = file_get_contents($envPath);

        // ── Check existing config ─────────────────────────────────────────────
        $hasSlug = str_contains($env, 'UPDATE_BACKEND_SLUG=');
        $hasPass = str_contains($env, 'UPDATE_BACKEND_PASSWORD=');

        if (($hasSlug || $hasPass) && ! $this->option('regenerate')) {
            preg_match('/UPDATE_BACKEND_SLUG=(.+)/', $env, $sm);
            $slug = trim($sm[1] ?? '');
            $this->info("Update backend already configured at: public/{$slug}/");
            $this->line('Use --regenerate to create a new URL and password.');
            return self::SUCCESS;
        }

        // ── Generate slug + password ──────────────────────────────────────────
        $slug     = 'backend-' . bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(20));
        $destDir  = public_path($slug);

        // ── Copy stub files ───────────────────────────────────────────────────
        $stubDir = base_path('stubs/update-backend');
        if (! is_dir($stubDir)) {
            $this->error("Stub directory not found: {$stubDir}");
            $this->error('Ensure stubs/update-backend/ exists in the repository.');
            return self::FAILURE;
        }

        if (! is_dir($destDir)) mkdir($destDir, 0755, true);

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stubDir, \FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            $rel    = str_replace($stubDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $target = $destDir . DIRECTORY_SEPARATOR . $rel;
            if (! is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
            copy($file->getPathname(), $target);
        }

        if (! is_dir(storage_path('updates'))) {
            mkdir(storage_path('updates'), 0755, true);
        }

        // ── Write .env entries ────────────────────────────────────────────────
        $addOrReplace = function (string &$env, string $key, string $value) {
            if (str_contains($env, $key . '=')) {
                $env = preg_replace('/^' . $key . '=.*/m', $key . '=' . $value, $env);
            } else {
                $env = rtrim($env) . PHP_EOL . $key . '=' . $value . PHP_EOL;
            }
        };

        $addOrReplace($env, 'UPDATE_BACKEND_SLUG',     $slug);
        $addOrReplace($env, 'UPDATE_BACKEND_PASSWORD', $password);

        file_put_contents($envPath, $env);

        // ── Output ────────────────────────────────────────────────────────────
        $appUrl = config('app.url');
        $this->newLine();
        $this->info('✓ Update backend created.');
        $this->newLine();
        $this->line("  URL      : <comment>{$appUrl}/{$slug}/</comment>");
        $this->line("  Password : <comment>{$password}</comment>");
        $this->newLine();
        $this->warn('Save the password somewhere safe — it will not be shown again.');
        $this->warn('Keep the URL private. Anyone with it and the password can update your server.');
        $this->newLine();

        return self::SUCCESS;
    }
}
