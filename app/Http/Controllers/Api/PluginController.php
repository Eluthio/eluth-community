<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PluginController extends Controller
{
    // Official plugin definitions — source of truth for what's available
    const OFFICIAL_PLUGINS = [
        'gif-picker' => [
            'name'     => 'GIF Picker',
            'manifest' => [
                'description' => 'Search and insert GIFs from Tenor and Giphy',
                'version'     => '1.0.0',
                'zones'       => ['input'],
                'settings'    => [
                    ['key' => 'tenor_key', 'label' => 'Tenor API Key', 'type' => 'text', 'placeholder' => 'Google Cloud API key with Tenor API enabled — console.cloud.google.com'],
                    ['key' => 'giphy_key', 'label' => 'Giphy API Key', 'type' => 'text', 'placeholder' => 'Free key at developers.giphy.com'],
                ],
            ],
        ],
    ];

    public function index(Request $request): JsonResponse
    {
        // Make sure official plugin rows exist
        Plugin::syncOfficial(self::OFFICIAL_PLUGINS);

        $plugins = Plugin::all()->map(function (Plugin $p) {
            // Merge settings values into the manifest for the frontend
            $manifest = $p->manifest;
            if (isset($manifest['settings'])) {
                foreach ($manifest['settings'] as &$setting) {
                    $setting['value'] = \DB::table('server_settings')
                        ->where('key', 'plugin_' . $p->slug . '_' . $setting['key'])
                        ->value('value') ?? '';
                }
            }
            return [
                'slug'       => $p->slug,
                'name'       => $p->name,
                'tier'       => $p->tier,
                'is_enabled' => $p->is_enabled,
                'manifest'   => $manifest,
            ];
        });

        return response()->json(['plugins' => $plugins]);
    }

    public function enable(Request $request, string $slug): JsonResponse
    {
        $member = $request->attributes->get('member');
        if (! $member?->isAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $plugin = Plugin::findOrFail($slug);
        $plugin->update(['is_enabled' => true]);

        return response()->json(['ok' => true]);
    }

    public function disable(Request $request, string $slug): JsonResponse
    {
        $member = $request->attributes->get('member');
        if (! $member?->isAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $plugin = Plugin::findOrFail($slug);
        $plugin->update(['is_enabled' => false]);

        return response()->json(['ok' => true]);
    }

    public function updateSettings(Request $request, string $slug): JsonResponse
    {
        $member = $request->attributes->get('member');
        if (! $member?->isAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $plugin = Plugin::findOrFail($slug);
        $settings = $request->input('settings', []);

        foreach ($settings as $key => $value) {
            // Sanitise key — alphanumeric and underscores only
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower($key));
            $settingKey = 'plugin_' . $slug . '_' . $key;
            \DB::table('server_settings')->updateOrInsert(
                ['key' => $settingKey],
                ['value' => $value, 'updated_at' => now()]
            );
        }

        return response()->json(['ok' => true]);
    }

    // ── GIF Picker proxy ─────────────────────────────────────────────────────

    /**
     * Proxy Giphy search — keeps the API key server-side.
     * GET /api/plugins/gif-picker/search?q={query}
     */
    public function gifSearch(Request $request): JsonResponse
    {
        return $this->giphyRequest('search', $request->query('q', ''));
    }

    /**
     * Proxy Giphy trending.
     * GET /api/plugins/gif-picker/trending
     */
    public function gifTrending(): JsonResponse
    {
        return $this->giphyRequest('trending', '');
    }

    private function giphyRequest(string $type, string $query): JsonResponse
    {
        $key = \DB::table('server_settings')->where('key', 'plugin_gif-picker_giphy_key')->value('value');

        if (! $key) {
            return response()->json(['gifs' => []]);
        }

        $base = 'https://api.giphy.com/v1/gifs/';

        if ($type === 'search' && $query !== '') {
            $url = $base . 'search?api_key=' . urlencode($key)
                 . '&q=' . urlencode($query) . '&limit=24&rating=g&lang=en';
        } else {
            $url = $base . 'trending?api_key=' . urlencode($key) . '&limit=24&rating=g';
        }

        $response = \Http::timeout(5)->get($url);

        if (! $response->ok()) {
            return response()->json(['gifs' => []]);
        }

        $gifs = collect($response->json('data', []))->map(fn ($r) => [
            'id'      => $r['id'],
            'title'   => $r['title'] ?? '',
            'preview' => $r['images']['fixed_height_small']['url'] ?? $r['images']['original']['url'] ?? '',
            'url'     => $r['images']['original']['url'] ?? '',
        ])->filter(fn ($g) => $g['url'])->values();

        return response()->json(['gifs' => $gifs]);
    }
}
