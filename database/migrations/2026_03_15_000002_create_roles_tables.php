<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('color', 7)->nullable();   // hex colour e.g. #22d3ee
            $table->unsignedInteger('position')->default(0); // higher = more powerful
            $table->boolean('is_system')->default(false);    // Super Admin — cannot be deleted
            $table->boolean('is_default')->default(false);   // Auto-assigned to new approved members
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('role_id');
            $table->string('permission');
            $table->primary(['role_id', 'permission']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        Schema::create('member_roles', function (Blueprint $table) {
            $table->string('central_user_id');
            $table->uuid('role_id');
            $table->primary(['central_user_id', 'role_id']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        // Seed Super Admin role
        $superAdminId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id'         => $superAdminId,
            'name'       => 'Super Admin',
            'color'      => '#f59e0b',
            'position'   => 1000,
            'is_system'  => true,
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Super Admin gets all permissions
        foreach (Permissions::ALL as $perm) {
            DB::table('role_permissions')->insert(['role_id' => $superAdminId, 'permission' => $perm]);
        }

        // Seed default Member role
        $memberRoleId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id'         => $memberRoleId,
            'name'       => 'Member',
            'color'      => null,
            'position'   => 10,
            'is_system'  => false,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (Permissions::MEMBER_DEFAULTS as $perm) {
            DB::table('role_permissions')->insert(['role_id' => $memberRoleId, 'permission' => $perm]);
        }

        // Migrate existing server_members enum role → new roles
        // owners → Super Admin, everything else → Member role
        $owners = DB::table('server_members')->where('role', 'owner')->pluck('central_user_id');
        foreach ($owners as $userId) {
            DB::table('member_roles')->insertOrIgnore(['central_user_id' => $userId, 'role_id' => $superAdminId]);
        }

        $others = DB::table('server_members')->where('role', '!=', 'owner')->pluck('central_user_id');
        foreach ($others as $userId) {
            DB::table('member_roles')->insertOrIgnore(['central_user_id' => $userId, 'role_id' => $memberRoleId]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('member_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
