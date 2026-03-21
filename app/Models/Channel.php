<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Channel extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'type', 'section', 'position', 'topic', 'is_private',
                           'is_live', 'live_streamer_username', 'live_started_at', 'stream_seq'];

    protected $casts = [
        'is_private'      => 'boolean',
        'position'        => 'integer',
        'is_live'         => 'boolean',
        'stream_seq'      => 'integer',
        'live_started_at' => 'datetime',
    ];

    public function messages()
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }
}
