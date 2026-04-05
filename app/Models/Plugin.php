<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plugin extends Model
{
    protected $primaryKey = 'slug';
    public $incrementing  = false;
    protected $keyType    = 'string';

    protected $fillable = ['slug', 'name', 'tier', 'manifest', 'source_url', 'is_enabled'];

    protected $casts = [
        'manifest'   => 'array',
        'is_enabled' => 'boolean',
    ];

    /**
     * Build the popup registry from all enabled plugins' manifests.
     *
     * Each plugin can declare:
     *   "popups":   [{ "param", "value"?, "title", "accepts_controls"?, "component" }]
     *   "controls": [{ "popup", "component" }]   ← contributes a panel to another plugin's popup
     *
     * Returns an array of popup entries ready to be consumed by the frontend
     * (inlined as window.__EluthPopupRegistry on every page load).
     */
    public static function buildPopupRegistry(): array
    {
        $plugins = static::where('is_enabled', true)->get();

        // First pass: collect controls contributions keyed by target popup ID.
        $controlsMap = [];
        foreach ($plugins as $plugin) {
            foreach ($plugin->manifest['controls'] ?? [] as $control) {
                $popupId = $control['popup'] ?? '';
                if ($popupId) {
                    $controlsMap[$popupId][] = [
                        'slug'      => $plugin->slug,
                        'component' => $control['component'] ?? '',
                        'entry'     => $plugin->manifest['entry']   ?? 'index.js',
                        'version'   => $plugin->manifest['version'] ?? null,
                    ];
                }
            }
        }

        // Second pass: build popup entries.
        $popups = [];
        foreach ($plugins as $plugin) {
            foreach ($plugin->manifest['popups'] ?? [] as $popup) {
                $param = $popup['param'] ?? '';
                if (! $param) continue;

                // Popup ID used to match controls contributions.
                // Parameterised popups (param=value) use the value; presence-only use the param.
                $popupId = $popup['value'] ?? $param;

                $entry = [
                    'param'            => $param,
                    'value'            => $popup['value'] ?? null,
                    'title'            => $popup['title'] ?? '',
                    'accepts_controls' => (bool) ($popup['accepts_controls'] ?? false),
                    'slug'             => $plugin->slug,
                    'component'        => $popup['component'] ?? null,
                    'entry'            => $plugin->manifest['entry']   ?? 'index.js',
                    'version'          => $plugin->manifest['version'] ?? null,
                    'contributors'     => [],
                ];

                if ($entry['accepts_controls'] && isset($controlsMap[$popupId])) {
                    $entry['contributors'] = $controlsMap[$popupId];
                }

                $popups[] = $entry;
            }
        }

        return $popups;
    }

    /**
     * Ensure all official plugins have a DB row.
     * Called on boot and after plugin list changes.
     */
    public static function syncOfficial(array $definitions): void
    {
        foreach ($definitions as $slug => $def) {
            static::firstOrCreate(['slug' => $slug], [
                'name'       => $def['name'],
                'tier'       => 'official',
                'manifest'   => $def['manifest'],
                'is_enabled' => false,
            ]);
        }
    }
}
