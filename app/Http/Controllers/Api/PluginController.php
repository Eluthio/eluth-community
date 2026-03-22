<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PluginController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $plugins = Plugin::all()->map(function (Plugin $p) {
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

    public function install(Request $request): JsonResponse
    {
        $member = $request->attributes->get('member');
        if (! $member?->isAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $url = $request->input('url', '');
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return response()->json(['message' => 'Invalid URL.'], 422);
        }

        // Download the zip
        $tmpZip = tempnam(sys_get_temp_dir(), 'eluth_plugin_') . '.zip';
        try {
            $response = \Http::timeout(30)->get($url);
            if (! $response->ok()) {
                return response()->json(['message' => 'Could not download plugin zip.'], 422);
            }
            file_put_contents($tmpZip, $response->body());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Download failed: ' . $e->getMessage()], 422);
        }

        // Extract and validate plugin.json
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            return response()->json(['message' => 'Not a valid zip file.'], 422);
        }

        $manifestJson = $zip->getFromName('plugin.json');
        if ($manifestJson === false) {
            $zip->close(); @unlink($tmpZip);
            return response()->json(['message' => 'plugin.json not found in zip.'], 422);
        }

        $manifest = json_decode($manifestJson, true);
        if (! $manifest) {
            $zip->close(); @unlink($tmpZip);
            return response()->json(['message' => 'plugin.json is not valid JSON.'], 422);
        }

        // Required fields
        foreach (['name', 'slug', 'version', 'tier', 'entry', 'zones'] as $field) {
            if (empty($manifest[$field])) {
                $zip->close(); @unlink($tmpZip);
                return response()->json(['message' => "plugin.json missing required field: {$field}"], 422);
            }
        }

        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($manifest['slug']));
        $tier = $manifest['tier'];

        if (! in_array($tier, ['approved', 'unofficial'])) {
            $zip->close(); @unlink($tmpZip);
            return response()->json(['message' => 'Only approved or unofficial plugins can be installed.'], 422);
        }

        // Verify Tier 2 key with central
        if ($tier === 'approved') {
            $eluthKey = $manifest['eluth_key'] ?? '';
            if (! $eluthKey) {
                $zip->close(); @unlink($tmpZip);
                return response()->json(['message' => 'Approved plugins must have an eluth_key.'], 422);
            }
            $centralUrl = config('services.central.url', '');
            try {
                $verify = \Http::timeout(6)->post($centralUrl . '/api/plugins/verify', [
                    'slug'      => $slug,
                    'eluth_key' => $eluthKey,
                    'manifest'  => $manifest,
                ]);
                if (! $verify->ok() || ! $verify->json('valid')) {
                    $zip->close(); @unlink($tmpZip);
                    return response()->json(['message' => 'Plugin key verification failed. This plugin has not been approved by Eluth.'], 422);
                }
            } catch (\Throwable) {
                $zip->close(); @unlink($tmpZip);
                return response()->json(['message' => 'Could not verify plugin with Eluth central server.'], 422);
            }
        }

        // Extract all files to storage
        $destDir = 'plugins/' . $slug;
        \Storage::disk('public')->makeDirectory($destDir);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name    = $zip->getNameIndex($i);
            $content = $zip->getFromIndex($i);
            // Reject path traversal attempts
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                $zip->close(); @unlink($tmpZip);
                return response()->json(['message' => 'Plugin zip contains invalid file paths.'], 422);
            }
            if ($content !== false && ! str_ends_with($name, '/')) {
                \Storage::disk('public')->put($destDir . '/' . $name, $content);
            }
        }
        $zip->close();
        @unlink($tmpZip);

        // Upsert plugin record
        Plugin::updateOrCreate(['slug' => $slug], [
            'name'       => $manifest['name'],
            'tier'       => $tier,
            'manifest'   => array_merge($manifest, ['version' => $manifest['version']]),
            'source_url' => $url,
            'is_enabled' => false,
        ]);

        return response()->json(['ok' => true, 'slug' => $slug, 'name' => $manifest['name']]);
    }

    public function uninstall(Request $request, string $slug): JsonResponse
    {
        $member = $request->attributes->get('member');
        if (! $member?->isAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $plugin = Plugin::find($slug);
        if (! $plugin) {
            return response()->json(['message' => 'Plugin not found.'], 404);
        }

        // Remove files
        \Storage::disk('public')->deleteDirectory('plugins/' . $slug);

        // Remove settings
        \DB::table('server_settings')->where('key', 'like', 'plugin_' . $slug . '_%')->delete();

        $plugin->delete();

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

    // ── Image Uploader ────────────────────────────────────────────────────────

    /**
     * POST /api/plugins/image-uploader/upload
     * Accepts an image file, stores it, returns public URL.
     */
    public function imageUpload(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'max:8192'],
        ]);

        $file = $request->file('image');
        $name = \Str::uuid() . '.' . $file->getClientOriginalExtension();
        $file->storeAs('uploads/images', $name, 'public');

        $url = \Storage::disk('public')->url('uploads/images/' . $name);

        return response()->json(['url' => $url]);
    }

    // ── 3D Model Viewer ───────────────────────────────────────────────────────

    /**
     * POST /api/plugins/model-viewer/upload
     * Accepts an OBJ, STL, or GLB file, stores it, returns public URL.
     */
    public function modelUpload(Request $request): JsonResponse
    {
        $request->validate([
            'model' => ['required', 'file', 'mimes:obj,stl,glb,gltf', 'max:51200'],
        ]);

        $file = $request->file('model');
        $ext  = strtolower($file->getClientOriginalExtension());

        // Extra safety: only allow known 3D extensions
        if (! in_array($ext, ['obj', 'stl', 'glb', 'gltf'])) {
            return response()->json(['message' => 'Unsupported file type.'], 422);
        }

        $name = \Str::uuid() . '.' . $ext;
        $file->storeAs('uploads/models', $name, 'public');

        $url = \Storage::disk('public')->url('uploads/models/' . $name);

        return response()->json(['url' => $url]);
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
