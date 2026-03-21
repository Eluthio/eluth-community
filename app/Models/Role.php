<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'color', 'position', 'is_system', 'is_default'];

    protected $casts = ['is_system' => 'boolean', 'is_default' => 'boolean'];

    public function permissions()
    {
        return $this->hasMany(RolePermission::class);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->permissions->contains('permission', $permission);
    }
}
