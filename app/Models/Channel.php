<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Channel extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'type', 'section', 'position', 'topic', 'is_private'];

    protected $casts = [
        'is_private' => 'boolean',
        'position'   => 'integer',
    ];

    public function messages()
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }
}
