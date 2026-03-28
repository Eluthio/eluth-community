<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PluginRoom extends Model
{
    protected $primaryKey = 'id';
    public $incrementing   = false;
    protected $keyType     = 'string';

    protected $fillable = [
        'id', 'plugin_slug', 'channel_id', 'max_players',
        'player_ids', 'player_names', 'status', 'data', 'data_updated_at',
    ];

    protected $casts = [
        'player_ids'   => 'array',
        'player_names' => 'array',
        'data'         => 'array',
        'data_updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $room) {
            $room->id = (string) Str::uuid();
        });
    }
}
