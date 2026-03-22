<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncWithCentral extends Command
{
    protected $signature   = 'server:sync';
    protected $description = 'Ping the central server, pull any updated settings, and check subscription status.';

    public function handle(): int
    {
        $operatorId = env('OPERATOR_ID');
        $centralUrl = env('CENTRAL_SERVER_URL');

        if (! $operatorId || ! $centralUrl) {
            $this->warn('OPERATOR_ID or CENTRAL_SERVER_URL not configured — skipping sync.');
            return self::SUCCESS;
        }

        $version          = trim(file_get_contents(base_path('VERSION')) ?: '0.0.0');
        $installedPlugins = DB::table('plugins')->where('is_enabled', true)->pluck('slug')->all();

        try {
            $response = Http::timeout(8)->post($centralUrl . '/api/operators/' . $operatorId . '/ping', [
                'version'           => $version,
                'installed_plugins' => $installedPlugins,
            ]);
        } catch (\Throwable $e) {
            Log::warning('server:sync — could not reach central server: ' . $e->getMessage());
            $this->error('Could not reach central server: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (! $response->successful()) {
            Log::warning('server:sync — central returned ' . $response->status());
            $this->error('Central returned HTTP ' . $response->status());
            return self::FAILURE;
        }

        $data = $response->json();

        // Cache version enforcement data from central
        if (isset($data['min_community_version'])) {
            \Illuminate\Support\Facades\Cache::put('eluth_min_version', $data['min_community_version'], now()->addDay());
        }
        if (isset($data['latest_community_version'])) {
            \Illuminate\Support\Facades\Cache::put('eluth_latest_version', $data['latest_community_version'], now()->addDay());
        }

        // Sync server name if it changed
        $currentName = DB::table('server_settings')->where('key', 'server_name')->value('value');
        $centralName = $data['server_name'] ?? null;

        if ($centralName && $centralName !== $currentName) {
            DB::table('server_settings')->upsert(
                [['key' => 'server_name', 'value' => $centralName, 'created_at' => now(), 'updated_at' => now()]],
                ['key'], ['value', 'updated_at']
            );
            Log::info("server:sync — server name updated: \"{$currentName}\" → \"{$centralName}\"");
            $this->info("Server name updated: \"{$currentName}\" → \"{$centralName}\"");
        }

        // Apply debug settings from central
        if (isset($data['debug_enabled'])) {
            \Illuminate\Support\Facades\Cache::put('eluth_debug_enabled', (bool) $data['debug_enabled'], now()->addDay());
            \Illuminate\Support\Facades\Cache::put('eluth_debug_level', $data['debug_level'] ?? null, now()->addDay());
        }

        // Cache IP lock state
        $ipLocked = (bool) ($data['ip_locked'] ?? false);
        \Illuminate\Support\Facades\Cache::put('eluth_ip_locked', $ipLocked, now()->addDay());
        if ($ipLocked) {
            Log::error('server:sync — server is IP-locked. SSO and bootstrap are disabled. Update your registered server IP at eluth.io.');
            $this->error('WARNING: This server is IP-locked. Members cannot log in via SSO. Update your registered IP at eluth.io.');
        }

        // Warn if subscription has lapsed
        if (isset($data['is_active']) && ! $data['is_active'] && ! $ipLocked) {
            Log::error('server:sync — subscription is no longer active (status: ' . ($data['subscription_status'] ?? 'unknown') . ')');
            $this->error('WARNING: This server\'s subscription is no longer active. Members may lose access.');
        } elseif (! $ipLocked) {
            $this->info('Sync complete. Subscription active.');
        }

        return self::SUCCESS;
    }
}
