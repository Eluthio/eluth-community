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

        try {
            $response = Http::timeout(8)->post($centralUrl . '/api/operators/' . $operatorId . '/ping');
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

        // Warn if subscription has lapsed
        if (isset($data['is_active']) && ! $data['is_active']) {
            Log::error('server:sync — subscription is no longer active (status: ' . ($data['subscription_status'] ?? 'unknown') . ')');
            $this->error('WARNING: This server\'s subscription is no longer active. Members may lose access.');
        } else {
            $this->info('Sync complete. Subscription active.');
        }

        return self::SUCCESS;
    }
}
