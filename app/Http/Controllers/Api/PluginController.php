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

        \Log::info("[Plugin:{$slug}] E00 Enable started");

        $plugin = Plugin::findOrFail($slug);
        $plugin->update(['is_enabled' => true]);

        // Clear tracking records before re-running migrations.
        // Migration files must be idempotent (Schema::hasTable/hasColumn guards),
        // so re-running is safe and cheap. Clearing first means that if tables were
        // manually dropped or tracking records are stale, a disable → re-enable always
        // fixes the installation without needing SSH access.
        if (Schema::hasTable('plugin_migrations')) {
            \DB::table('plugin_migrations')->where('slug', $slug)->delete();
            \Log::info("[Plugin:{$slug}] Cleared migration tracking records — will re-run all migrations");
        }

        $migrationsDir = storage_path('app/public/plugins/' . $slug . '/backend/migrations');
        if (is_dir($migrationsDir)) {
            try {
                $this->runPluginMigrations($slug, $migrationsDir);
            } catch (\Throwable $e) {
                \Log::error("[Plugin:{$slug}] E01-DBE Migration failed: " . $e->getMessage(), [
                    'file' => $e->getFile(), 'line' => $e->getLine(),
                ]);
                return response()->json([
                    'message' => "[E01-DBE] Enable failed — migration error: " . $e->getMessage(),
                ], 500);
            }
        }

        \Log::info("[Plugin:{$slug}] E02 Running post-enable verification");
        $manifest = $plugin->fresh()->manifest ?? [];
        $issues   = $this->verifyPlugin($slug, $manifest);
        if ($issues) {
            $detail = implode('; ', $issues);
            \Log::error("[Plugin:{$slug}] E02-VRF Post-enable verification failed: {$detail}");
            return response()->json([
                'message' => "[E02-VRF] Enable failed — {$detail}",
            ], 500);
        }

        \Log::info("[Plugin:{$slug}] E00 Enabled successfully");
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

        \Log::info("[Plugin:{$slug}] Disabled");
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

        \Log::info("[Plugin:?] I00 Install started", ['url' => $url]);

        // Download the zip
        $tmpZip = tempnam(sys_get_temp_dir(), 'eluth_plugin_') . '.zip';
        try {
            $response = \Http::timeout(30)->get($url);
            if (! $response->ok()) {
                \Log::error("[Plugin:?] I01-HTTP Download failed — HTTP {$response->status()}", ['url' => $url]);
                return response()->json(['message' => '[I01-HTTP] Could not download plugin zip.'], 422);
            }
            file_put_contents($tmpZip, $response->body());
            \Log::info("[Plugin:?] I01 Downloaded zip (" . strlen($response->body()) . " bytes)");
        } catch (\Throwable $e) {
            \Log::error("[Plugin:?] I01-NET Download exception: " . $e->getMessage());
            return response()->json(['message' => '[I01-NET] Download failed: ' . $e->getMessage()], 422);
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

        \Log::info("[Plugin:{$slug}] I02 zip validated — installing v{$manifest['version']} (tier: {$tier})");

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
        \Log::info("[Plugin:{$slug}] I03 Files extracted to storage/app/public/plugins/{$slug}");

        // Run any backend migrations the plugin ships
        $migrationsDir = storage_path('app/public/plugins/' . $slug . '/backend/migrations');
        if (is_dir($migrationsDir)) {
            \Log::info("[Plugin:{$slug}] I04 Running migrations");
            try {
                $this->runPluginMigrations($slug, $migrationsDir);
                \Log::info("[Plugin:{$slug}] I04 Migrations complete");
            } catch (\Throwable $e) {
                \Log::error("[Plugin:{$slug}] I04-DBE Migration failed: " . $e->getMessage(), [
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                ]);
                // Clean up extracted files so a retry starts fresh
                \Storage::disk('public')->deleteDirectory('plugins/' . $slug);
                return response()->json([
                    'message' => "[I04-DBE] Install failed — migration error: " . $e->getMessage() . " — files removed, please try again.",
                ], 500);
            }
        }

        // Upsert plugin record — preserve is_enabled on updates; default disabled for new installs.
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

        // Post-install verification — checks entry file, backend files, and any
        // tables/columns declared in plugin.json under "requires".
        \Log::info("[Plugin:{$slug}] I05 Running post-install verification");
        $issues = $this->verifyPlugin($slug, $manifest);
        if ($issues) {
            $detail = implode('; ', $issues);
            \Log::error("[Plugin:{$slug}] I05-VRF Post-install verification failed: {$detail}");
            // Files and DB record stay — admin can see what's missing and retry enable
            return response()->json([
                'message' => "[I05-VRF] Plugin installed but verification failed — {$detail}",
                'slug'    => $slug,
                'name'    => $manifest['name'],
            ], 500);
        }

        \Log::info("[Plugin:{$slug}] I00 Install complete (v{$manifest['version']})");
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

        // keep_data=true → preserve tables and migration tracking so a reinstall
        // picks up exactly where it left off (only runs migrations added since).
        // keep_data=false (default) → full teardown: drop tables, clear tracking.
        $keepData = filter_var($request->input('keep_data', false), FILTER_VALIDATE_BOOLEAN);
        \Log::info("[Plugin:{$slug}] Uninstall started", ['keep_data' => $keepData]);

        if (! $keepData) {
            // Run plugin teardown (drops its tables) before files are removed
            $teardownFile = storage_path('app/public/plugins/' . $slug . '/backend/teardown.php');
            if (file_exists($teardownFile)) {
                try {
                    require $teardownFile;
                    \Log::info("[Plugin:{$slug}] U01 Teardown complete");
                } catch (\Throwable $e) {
                    // teardown failure is non-fatal — files are removed regardless
                    \Log::error("[Plugin:{$slug}] U01-DBE Teardown failed (non-fatal): " . $e->getMessage());
                }
            }

            // Remove migration tracking records
            if (Schema::hasTable('plugin_migrations')) {
                \DB::table('plugin_migrations')->where('slug', $slug)->delete();
                \Log::info("[Plugin:{$slug}] U02 Migration tracking records removed");
            }
        }

        // Remove files
        \Storage::disk('public')->deleteDirectory('plugins/' . $slug);
        \Log::info("[Plugin:{$slug}] U03 Plugin files removed");

        // Remove settings
        \DB::table('server_settings')->where('key', 'like', 'plugin_' . $slug . '_%')->delete();

        $plugin->delete();

        \Log::info("[Plugin:{$slug}] Uninstall complete");
        return response()->json(['ok' => true]);
    }

    // ── Plugin backend infrastructure ────────────────────────────────────────

    /**
     * Run any migration files from a plugin's backend/migrations/ directory
     * that have not already been applied. Tracks applied migrations in the
     * plugin_migrations table (created lazily on first use).
     */
    /**
     * Run migration files from a plugin's backend/migrations/ directory that have
     * not already been applied. Files are sorted alphabetically (use a numeric prefix
     * like 001_, 002_ to guarantee order).
     *
     * Each migration file receives the full Laravel DB/Schema API. Use guards so
     * every operation is idempotent:
     *
     *   CREATE TABLE  → if (! Schema::hasTable('x'))   { Schema::create(...) }
     *   ADD COLUMN    → if (! Schema::hasColumn('t','c')) { Schema::table(...add...) }
     *   DROP COLUMN   → if (Schema::hasColumn('t','c'))  { Schema::table(...drop...) }
     *   DROP TABLE    → if (Schema::hasTable('x'))       { Schema::dropIfExists('x') }
     *   INSERT/UPDATE → wrap in try/catch or check before inserting
     *
     * A file is only marked as run after it completes without exception. A failed
     * migration has no tracking record and will be retried on the next install/update.
     * A user on v1 who skips v2 and installs v3 will have v2's migrations run first,
     * then v3's — all untracked files execute in filename order.
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
        sort($files); // numeric prefix ordering: 001_, 002_, …

        foreach ($files as $file) {
            $filename   = basename($file);
            $alreadyRan = \DB::table('plugin_migrations')
                ->where('slug', $slug)
                ->where('filename', $filename)
                ->exists();

            if (! $alreadyRan) {
                \Log::info("[Plugin:{$slug}] Running migration: {$filename}");
                require $file; // throws on failure — no tracking record written, retried next time
                \DB::table('plugin_migrations')->insert([
                    'slug'     => $slug,
                    'filename' => $filename,
                    'ran_at'   => now(),
                ]);
                \Log::info("[Plugin:{$slug}] Migration complete: {$filename}");
            } else {
                \Log::info("[Plugin:{$slug}] Migration already ran (skipped): {$filename}");
            }
        }
    }

    /**
     * Verify a plugin's installation is complete.
     *
     * Checks:
     *  1. Entry JS file exists
     *  2. backend/routes.php and backend/controller.php exist (if backend dir present)
     *  3. Any tables listed in manifest["requires"]["tables"] exist in the DB
     *  4. Any columns listed in manifest["requires"]["columns"][table] exist
     *
     * Returns an array of problem strings. Empty = all checks passed.
     */
    private function verifyPlugin(string $slug, array $manifest): array
    {
        $issues    = [];
        $pluginDir = storage_path('app/public/plugins/' . $slug);

        // 1. Entry file
        $entry = $manifest['entry'] ?? '';
        if ($entry && ! file_exists($pluginDir . '/' . $entry)) {
            $issues[] = "entry file missing: {$entry}";
        }

        // 2. Backend files
        if (is_dir($pluginDir . '/backend')) {
            foreach (['routes.php', 'controller.php'] as $file) {
                if (! file_exists($pluginDir . '/backend/' . $file)) {
                    $issues[] = "backend file missing: backend/{$file}";
                }
            }
        }

        // 3. Required tables
        foreach ($manifest['requires']['tables'] ?? [] as $table) {
            if (! Schema::hasTable($table)) {
                $issues[] = "required table missing: {$table}";
            }
        }

        // 4. Required columns
        foreach ($manifest['requires']['columns'] ?? [] as $table => $columns) {
            foreach ((array) $columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $issues[] = "required column missing: {$table}.{$column}";
                }
            }
        }

        return $issues;
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
