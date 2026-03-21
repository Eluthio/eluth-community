<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $newPerms = [
            Permissions::MENTION_EVERYONE,
            Permissions::JOIN_VOICE,
            Permissions::SPEAK,
            Permissions::VIDEO,
            Permissions::MUTE_MEMBERS,
            Permissions::DEAFEN_MEMBERS,
            Permissions::MOVE_MEMBERS,
            Permissions::PRIORITY_SPEAKER,
        ];

        // Grant all new permissions to every system (Super Admin) role
        $superAdminIds = DB::table('roles')->where('is_system', true)->pluck('id');
        foreach ($superAdminIds as $roleId) {
            foreach ($newPerms as $perm) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id'    => $roleId,
                    'permission' => $perm,
                ]);
            }
        }

        // Grant voice join/speak/video to all default (Member) roles
        $memberDefaults = [Permissions::JOIN_VOICE, Permissions::SPEAK, Permissions::VIDEO];
        $defaultRoleIds = DB::table('roles')->where('is_default', true)->pluck('id');
        foreach ($defaultRoleIds as $roleId) {
            foreach ($memberDefaults as $perm) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id'    => $roleId,
                    'permission' => $perm,
                ]);
            }
        }
    }

    public function down(): void
    {
        $perms = [
            Permissions::MENTION_EVERYONE, Permissions::JOIN_VOICE, Permissions::SPEAK,
            Permissions::VIDEO, Permissions::MUTE_MEMBERS, Permissions::DEAFEN_MEMBERS,
            Permissions::MOVE_MEMBERS, Permissions::PRIORITY_SPEAKER,
        ];

        DB::table('role_permissions')->whereIn('permission', $perms)->delete();
    }
};
