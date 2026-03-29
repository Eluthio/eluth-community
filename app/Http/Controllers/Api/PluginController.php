<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

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

        if (! in_array($tier, ['official', 'approved', 'unofficial'])) {
            $zip->close(); @unlink($tmpZip);
            return response()->json(['message' => 'Invalid plugin tier.'], 422);
        }

        // Verify approved plugins with central
        // plugin.json must include plugin_key (raw key issued on approval) and manifest_hash
        if ($tier === 'approved') {
            $pluginKey    = $manifest['plugin_key']    ?? '';
            $manifestHash = $manifest['manifest_hash'] ?? '';
            if (! $pluginKey || ! $manifestHash) {
                $zip->close(); @unlink($tmpZip);
                return response()->json(['message' => 'Approved plugins must include plugin_key and manifest_hash in plugin.json.'], 422);
            }
            $centralUrl = config('services.central.url', '');
            try {
                $verify = \Http::timeout(6)->post($centralUrl . '/api/plugins/verify', [
                    'slug'          => $slug,
                    'plugin_key'    => $pluginKey,
                    'manifest_hash' => $manifestHash,
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

        // Run any backend migrations the plugin ships
        $migrationsDir = storage_path('app/public/plugins/' . $slug . '/backend/migrations');
        if (is_dir($migrationsDir)) {
            $this->runPluginMigrations($slug, $migrationsDir);
        }

        // Upsert plugin record — preserve is_enabled on updates; default false for new installs
        $existing = Plugin::find($slug);
        $record = [
            'name'       => $manifest['name'],
            'tier'       => $tier,
            'manifest'   => $manifest,
            'source_url' => $url,
        ];
        if ($existing) {
            $existing->update($record);
        } else {
            Plugin::create(array_merge($record, ['slug' => $slug, 'is_enabled' => false]));
        }

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

        // Run plugin teardown (drops its tables) before files are removed
        $teardownFile = storage_path('app/public/plugins/' . $slug . '/backend/teardown.php');
        if (file_exists($teardownFile)) {
            try {
                require $teardownFile;
            } catch (\Throwable $e) {
                \Log::warning("Plugin teardown failed for [{$slug}]: " . $e->getMessage());
            }
        }

        // Remove migration tracking records
        if (Schema::hasTable('plugin_migrations')) {
            \DB::table('plugin_migrations')->where('slug', $slug)->delete();
        }

        // Remove files
        \Storage::disk('public')->deleteDirectory('plugins/' . $slug);

        // Remove settings
        \DB::table('server_settings')->where('key', 'like', 'plugin_' . $slug . '_%')->delete();

        $plugin->delete();

        return response()->json(['ok' => true]);
    }

    // ── Plugin backend infrastructure ────────────────────────────────────────

    /**
     * Run any migration files from a plugin's backend/migrations/ directory
     * that have not already been applied. Tracks applied migrations in the
     * plugin_migrations table (created lazily on first use).
     */
    private function runPluginMigrations(string $slug, string $dir): void
    {
        // Create tracking table if it doesn't exist yet
        if (! Schema::hasTable('plugin_migrations')) {
            Schema::create('plugin_migrations', function ($table) {
                $table->id();
                $table->string('slug', 100);
                $table->string('filename', 255);
                $table->timestamp('ran_at')->useCurrent();
                $table->unique(['slug', 'filename']);
            });
        }

        $files = glob($dir . '/*.php');
        if (! $files) return;
        sort($files); // ensure numeric prefix ordering

        foreach ($files as $file) {
            $filename  = basename($file);
            $alreadyRan = \DB::table('plugin_migrations')
                ->where('slug', $slug)
                ->where('filename', $filename)
                ->exists();

            if (! $alreadyRan) {
                require $file;
                \DB::table('plugin_migrations')->insert([
                    'slug'     => $slug,
                    'filename' => $filename,
                    'ran_at'   => now(),
                ]);
            }
        }
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
            'model' => ['required', 'file', 'max:51200'],
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

    // ── File Manager ──────────────────────────────────────────────────────────

    /**
     * GET /api/plugins/file-manager/files?channel_id=&q=&page=
     * Scans messages for storage upload URLs and returns paginated file metadata.
     */
    public function fileManagerFiles(Request $request): JsonResponse
    {
        $channelId = $request->query('channel_id');
        $q         = trim((string) $request->query('q', ''));
        $page      = max(1, (int) $request->query('page', 1));
        $perPage   = 40;

        // Pattern matches any URL pointing into /storage/uploads/
        $uploadPattern = '%/storage/uploads/%';

        $query = \DB::table('messages')
            ->where('content', 'like', $uploadPattern);

        if ($channelId) {
            $query->where('channel_id', $channelId);
        }

        if ($q !== '') {
            $query->where('content', 'like', '%' . $q . '%');
        }

        $rows = $query
            ->orderByDesc('created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage + 1)
            ->get(['id', 'username', 'content', 'created_at']);

        $hasMore = $rows->count() > $perPage;
        $rows    = $rows->take($perPage);

        // Extract all upload URLs from each message content
        $files = [];
        $urlPattern = '/https?:\/\/\S+\/storage\/uploads\/[^\s"<]+/i';

        foreach ($rows as $row) {
            preg_match_all($urlPattern, $row->content, $matches);
            foreach ($matches[0] as $url) {
                $clean    = strtok($url, '?');
                $filename = basename($clean);
                $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                $type = match(true) {
                    in_array($ext, ['jpg','jpeg','png','gif','webp']) => 'image',
                    in_array($ext, ['obj','stl','glb','gltf'])        => 'model',
                    in_array($ext, ['mp4','webm','mov'])              => 'video',
                    default                                            => 'file',
                };

                // Only include files matching search query if one was given
                if ($q !== '' && stripos($filename, $q) === false) continue;

                $files[] = [
                    'url'        => $clean,
                    'filename'   => $filename,
                    'type'       => $type,
                    'ext'        => $ext,
                    'posted_by'  => $row->username,
                    'posted_at'  => $row->created_at,
                    'message_id' => $row->id,
                ];
            }
        }

        return response()->json([
            'files'    => $files,
            'page'     => $page,
            'has_more' => $hasMore,
        ]);
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
