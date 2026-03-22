<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\ServerMember;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    /** Maps plugin slug → Permissions::CATEGORIES key that belongs to it */
    private const PLUGIN_PERMISSION_CATEGORIES = [
        'polls'       => 'Polls',
        'watch-party' => 'Watch Party',
    ];

    private function requirePermission(Request $request, string $permission): void
    {
        $member = $request->attributes->get('member');
        if (! $member?->can($permission)) {
            abort(response()->json(['error' => 'forbidden'], 403));
        }
    }

    // ── Server overview ────────────────────────────────────────────────────

    public function updateServer(Request $request)
    {
        $this->requirePermission($request, Permissions::MANAGE_SERVER);

        $validated = $request->validate([
            'name'           => 'required|string|max:100',
            'joinMode'       => 'required|in:open,request',
            'welcomeMessage' => 'nullable|string|max:2000',
        ]);

        \DB::table('server_settings')->upsert([
            ['key' => 'server_name',     'value' => $validated['name'],                           'updated_at' => now(), 'created_at' => now()],
            ['key' => 'join_mode',       'value' => $validated['joinMode'],                        'updated_at' => now(), 'created_at' => now()],
            ['key' => 'welcome_message', 'value' => $validated['welcomeMessage'] ?? null,          'updated_at' => now(), 'created_at' => now()],
        ], ['key'], ['value', 'updated_at']);

        return response()->json(['message' => 'Server settings saved.']);
    }

    public function updateAppearance(Request $request): JsonResponse
    {
        $this->requirePermission($request, Permissions::MANAGE_SERVER);

        $validated = $request->validate([
            'logo'             => 'nullable|string|max:500',
            'background_type'  => 'nullable|in:none,color,image,video',
            'background_value' => 'nullable|string|max:500',
            'primary_color'    => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent_color'     => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $rows = [];
        foreach (['logo', 'background_type', 'background_value', 'primary_color', 'accent_color'] as $key) {
            $rows[] = ['key' => $key, 'value' => $validated[$key] ?? null, 'created_at' => now(), 'updated_at' => now()];
        }

        \DB::table('server_settings')->upsert($rows, ['key'], ['value', 'updated_at']);

        return response()->json(['message' => 'Appearance saved.']);
    }

    public function updateContent(Request $request): JsonResponse
    {
        $this->requirePermission($request, Permissions::MANAGE_SERVER);

        $validated = $request->validate([
            'welcome_enabled'   => 'boolean',
            'welcome_message'   => 'nullable|string|max:5000',
            'rules_enabled'     => 'boolean',
            'rules'             => 'nullable|string|max:20000',
            'require_rules_ack' => 'boolean',
        ]);

        $rows = [];
        foreach (['welcome_enabled', 'welcome_message', 'rules_enabled', 'rules', 'require_rules_ack'] as $key) {
            $rows[] = ['key' => $key, 'value' => isset($validated[$key]) ? (string) $validated[$key] : null, 'created_at' => now(), 'updated_at' => now()];
        }

        \DB::table('server_settings')->upsert($rows, ['key'], ['value', 'updated_at']);

        return response()->json(['message' => 'Content settings saved.']);
    }

    public function uploadAsset(Request $request): JsonResponse
    {
        $this->requirePermission($request, Permissions::MANAGE_SERVER);

        $request->validate([
            'file' => 'required|file|max:51200', // 50 MB max
            'type' => 'required|in:logo,background',
        ]);

        $file = $request->file('file');
        $type = $request->input('type');
        $mime = $file->getMimeType();

        if ($type === 'logo' && ! str_starts_with($mime, 'image/')) {
            return response()->json(['error' => 'Logo must be an image file.'], 422);
        }

        if ($type === 'background' && ! str_starts_with($mime, 'image/') && ! str_starts_with($mime, 'video/')) {
            return response()->json(['error' => 'Background must be an image or video file.'], 422);
        }

        $dir = public_path('uploads/server');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = $type . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $file->move($dir, $filename);

        return response()->json(['url' => '/uploads/server/' . $filename]);
    }

    // ── Join requests ──────────────────────────────────────────────────────

    public function joinRequests(Request $request)
    {
        $this->requirePermission($request, Permissions::APPROVE_MEMBERS);

        return response()->json([
            'requests' => ServerMember::where('status', 'pending')
                ->orderBy('joined_at')
                ->get(['central_user_id', 'username', 'joined_at']),
        ]);
    }

    public function approve(Request $request, string $userId)
    {
        $this->requirePermission($request, Permissions::APPROVE_MEMBERS);

        $member = ServerMember::where('central_user_id', $userId)->where('status', 'pending')->firstOrFail();

        $defaultRole = Role::where('is_default', true)->first();
        if ($defaultRole) {
            \DB::table('member_roles')->insertOrIgnore([
                'central_user_id' => $userId,
                'role_id'         => $defaultRole->id,
            ]);
        }

        $member->update(['status' => 'member']);

        return response()->json(['message' => 'Member approved.']);
    }

    public function deny(Request $request, string $userId)
    {
        $this->requirePermission($request, Permissions::APPROVE_MEMBERS);

        ServerMember::where('central_user_id', $userId)->where('status', 'pending')->firstOrFail()->delete();

        return response()->json(['message' => 'Request denied.']);
    }

    // ── Member management ──────────────────────────────────────────────────

    public function kickMember(Request $request, string $userId)
    {
        $this->requirePermission($request, Permissions::KICK_MEMBERS);

        $target = ServerMember::where('central_user_id', $userId)->firstOrFail();
        if ($target->isSuperAdmin()) {
            return response()->json(['error' => 'Cannot kick the Super Admin.'], 422);
        }

        $target->delete();

        return response()->json(['message' => 'Member kicked.']);
    }

    public function banMember(Request $request, string $userId)
    {
        $this->requirePermission($request, Permissions::BAN_MEMBERS);

        $target = ServerMember::where('central_user_id', $userId)->firstOrFail();
        if ($target->isSuperAdmin()) {
            return response()->json(['error' => 'Cannot ban the Super Admin.'], 422);
        }

        $target->update(['status' => 'banned']);

        return response()->json(['message' => 'Member banned.']);
    }

    public function assignRole(Request $request, string $userId, string $roleId)
    {
        $this->requirePermission($request, Permissions::MANAGE_MEMBER_ROLES);

        $target = ServerMember::where('central_user_id', $userId)->where('status', 'member')->firstOrFail();
        $role   = Role::findOrFail($roleId);

        // Only Super Admins can assign the Super Admin role
        if ($role->is_system && ! $request->attributes->get('member')->isSuperAdmin()) {
            return response()->json(['error' => 'Only a Super Admin can assign the Super Admin role.'], 403);
        }

        \DB::table('member_roles')->insertOrIgnore(['central_user_id' => $userId, 'role_id' => $roleId]);

        return response()->json(['message' => 'Role assigned.']);
    }

    public function removeRole(Request $request, string $userId, string $roleId)
    {
        $this->requirePermission($request, Permissions::MANAGE_MEMBER_ROLES);

        $role = Role::findOrFail($roleId);
        if ($role->is_system) {
            return response()->json(['error' => 'Cannot remove the Super Admin role this way.'], 422);
        }

        \DB::table('member_roles')->where('central_user_id', $userId)->where('role_id', $roleId)->delete();

        return response()->json(['message' => 'Role removed.']);
    }

    // ── Roles ──────────────────────────────────────────────────────────────

    public function listRoles(Request $request)
    {
        $this->requirePermission($request, Permissions::MANAGE_ROLES);

        $roles = Role::with('permissions')->orderByDesc('position')->get()->map(fn ($r) => [
            'id'          => $r->id,
            'name'        => $r->name,
            'color'       => $r->color,
            'position'    => $r->position,
            'is_system'   => $r->is_system,
            'is_default'  => $r->is_default,
            'permissions' => $r->permissions->pluck('permission'),
        ]);

        // Only expose plugin-specific permission categories when that plugin is enabled
        $categories = Permissions::CATEGORIES;
        $pluginPermsEnabled = Plugin::whereIn('slug', array_keys(self::PLUGIN_PERMISSION_CATEGORIES))
            ->where('is_enabled', true)
            ->pluck('slug')
            ->all();
        foreach (array_keys(self::PLUGIN_PERMISSION_CATEGORIES) as $slug) {
            if (! in_array($slug, $pluginPermsEnabled)) {
                $category = self::PLUGIN_PERMISSION_CATEGORIES[$slug];
                unset($categories[$category]);
            }
        }

        return response()->json([
            'roles'            => $roles,
            'all_permissions'  => $categories,
        ]);
    }

    public function createRole(Request $request)
    {
        $this->requirePermission($request, Permissions::MANAGE_ROLES);

        $validated = $request->validate([
            'name'        => 'required|string|max:32',
            'color'       => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'position'    => 'integer|min:1|max:999',
            'is_default'  => 'boolean',
            'permissions' => 'array',
            'permissions.*' => 'string|in:' . implode(',', Permissions::ALL),
        ]);

        $role = Role::create([
            'name'       => $validated['name'],
            'color'      => $validated['color'] ?? null,
            'position'   => $validated['position'] ?? 10,
            'is_default' => $validated['is_default'] ?? false,
        ]);

        if (! empty($validated['permissions'])) {
            foreach ($validated['permissions'] as $perm) {
                $role->permissions()->create(['permission' => $perm]);
            }
        }

        return response()->json(['role' => $role->load('permissions')], 201);
    }

    public function updateRole(Request $request, string $roleId)
    {
        $this->requirePermission($request, Permissions::MANAGE_ROLES);

        $role = Role::findOrFail($roleId);

        if ($role->is_system) {
            return response()->json(['error' => 'System roles cannot be modified.'], 422);
        }

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:32',
            'color'       => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'position'    => 'sometimes|integer|min:1|max:999',
            'is_default'  => 'sometimes|boolean',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string|in:' . implode(',', Permissions::ALL),
        ]);

        $fields = array_filter($validated, fn ($v, $k) => $k !== 'permissions', ARRAY_FILTER_USE_BOTH);

        // If making this the default, clear the old default first
        if (! empty($fields['is_default'])) {
            Role::where('is_default', true)->where('id', '!=', $role->id)->update(['is_default' => false]);
        }

        $role->update($fields);

        if (isset($validated['permissions'])) {
            $role->permissions()->delete();
            foreach ($validated['permissions'] as $perm) {
                $role->permissions()->create(['permission' => $perm]);
            }
        }

        return response()->json(['role' => $role->load('permissions')]);
    }

    public function deleteRole(Request $request, string $roleId)
    {
        $this->requirePermission($request, Permissions::MANAGE_ROLES);

        $role = Role::findOrFail($roleId);

        if ($role->is_system) {
            return response()->json(['error' => 'The Super Admin role cannot be deleted.'], 422);
        }

        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }
}
