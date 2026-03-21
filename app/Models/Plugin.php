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
