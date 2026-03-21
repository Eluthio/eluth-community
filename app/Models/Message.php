<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['channel_id', 'central_user_id', 'username', 'content', 'created_at', 'reply_to_id', 'reply_to_author', 'reply_to_preview'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }
}
