<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerMember extends Model
{
    public $incrementing = false;
    public $timestamps   = false;

    protected $primaryKey = 'central_user_id';
    protected $keyType    = 'string';

    protected $fillable = ['central_user_id', 'username', 'status', 'role', 'presence', 'joined_at', 'last_seen_at', 'welcomed_at'];

    protected $casts = [
        'joined_at'    => 'datetime',
        'last_seen_at' => 'datetime',
        'welcomed_at'  => 'datetime',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'member_roles', 'central_user_id', 'role_id')
                    ->with('permissions')
                    ->orderByDesc('position');
    }

    public function isSuperAdmin(): bool
    {
        return $this->relationLoaded('roles')
            ? $this->roles->some(fn ($r) => $r->is_system)
            : $this->roles()->where('is_system', true)->exists();
    }

    /** Legacy helper used by older code — checks roles if loaded, falls back to enum */
    public function isAdmin(): bool
    {
        return $this->isSuperAdmin()
            || ($this->relationLoaded('roles') && $this->roles->some(fn ($r) => $r->position >= 50));
    }

    public function can(string $permission): bool
    {
        if ($this->isSuperAdmin()) return true;

        if (! $this->relationLoaded('roles')) {
            $this->load('roles');
        }

        return $this->roles->some(fn ($r) => $r->hasPermission($permission));
    }
}
