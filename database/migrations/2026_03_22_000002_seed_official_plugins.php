<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Plugin;

return new class extends Migration {
    public function up(): void
    {
        $plugins = [
            'gif-picker' => [
                'name'     => 'GIF Picker',
                'manifest' => [
                    'version'     => '1.0.0',
                    'description' => 'Search and send GIFs from Tenor or Giphy.',
                    'zones'       => ['input'],
                    'entry'       => 'index.js',
                    'settings'    => [
                        ['key' => 'tenor_key',  'label' => 'Tenor API Key',  'type' => 'text'],
                        ['key' => 'giphy_key',  'label' => 'Giphy API Key',  'type' => 'text'],
                        ['key' => 'provider',   'label' => 'Provider (tenor/giphy)', 'type' => 'text'],
                    ],
                ],
            ],
            'watch-party' => [
                'name'     => 'Watch Party',
                'manifest' => [
                    'version'     => '1.0.0',
                    'description' => 'Propose and vote on videos to watch together.',
                    'zones'       => ['input'],
                    'entry'       => 'index.js',
                    'settings'    => [],
                ],
            ],
            'emoticon-picker' => [
                'name'     => 'Emoticon Picker',
                'manifest' => [
                    'version'     => '1.0.0',
                    'description' => 'Upload custom emotes and use them in chat.',
                    'zones'       => ['input'],
                    'entry'       => 'index.js',
                    'settings'    => [],
                ],
            ],
            'image-uploader' => [
                'name'     => 'Image Uploader',
                'manifest' => [
                    'version'     => '1.0.0',
                    'description' => 'Upload images directly to your server.',
                    'zones'       => ['input'],
                    'entry'       => 'index.js',
                    'settings'    => [
                        ['key' => 'max_size_mb', 'label' => 'Max file size (MB)', 'type' => 'text'],
                    ],
                ],
            ],
            'model-viewer' => [
                'name'     => '3D Model Viewer',
                'manifest' => [
                    'version'     => '1.0.0',
                    'description' => 'Upload and view 3D models (OBJ, STL, GLB, GLTF) in chat.',
                    'zones'       => ['input'],
                    'entry'       => 'index.js',
                    'settings'    => [],
                ],
            ],
            'polls' => [
                'name'     => 'Polls',
                'manifest' => [
                    'version'     => '1.0.0',
                    'description' => 'Create polls and let your community vote.',
                    'zones'       => ['input'],
                    'entry'       => 'index.js',
                    'settings'    => [],
                ],
            ],
        ];

        foreach ($plugins as $slug => $data) {
            Plugin::firstOrCreate(['slug' => $slug], [
                'name'       => $data['name'],
                'tier'       => 'official',
                'manifest'   => $data['manifest'],
                'is_enabled' => false,
            ]);
        }
    }

    public function down(): void
    {
        \DB::table('plugins')->whereIn('slug', [
            'gif-picker', 'watch-party', 'emoticon-picker',
            'image-uploader', 'model-viewer', 'polls',
        ])->delete();
    }
};
