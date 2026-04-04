<template>
    <div class="settings-overlay" @click.self="$emit('close')">
        <div class="settings-modal">

            <!-- Left nav -->
            <nav class="settings-nav">
                <div class="settings-nav-server">{{ serverName }}</div>

                <template v-if="hasAnyAdminPermission">
                    <div class="settings-nav-group-label">Administration</div>
                    <button v-if="can('manage_server')"   :class="navClass('overview')"      @click="panel = 'overview'">Overview</button>
                    <button v-if="can('manage_server')"   :class="navClass('appearance')"    @click="panel = 'appearance'">Appearance</button>
                    <button v-if="can('manage_server')"   :class="navClass('content')"       @click="panel = 'content'">Welcome &amp; Rules</button>
                    <button v-if="can('manage_server')"   :class="navClass('plugins')"       @click="panel = 'plugins'">Plugins</button>
                    <button v-if="can('manage_server')"   :class="navClass('emotes')"        @click="panel = 'emotes'">Emotes</button>
                    <button v-if="can('manage_channels')" :class="navClass('channels')"      @click="panel = 'channels'">Channels</button>
                    <button v-if="can('manage_roles')"    :class="navClass('roles')"         @click="panel = 'roles'">Roles</button>
                    <button v-if="hasAnyMemberPermission" :class="navClass('members')"       @click="panel = 'members'">Members</button>
                    <button v-if="can('approve_members')" :class="navClass('join-requests')" @click="panel = 'join-requests'">
                        Join Requests
                        <span v-if="joinRequestCount > 0" class="settings-nav-badge">{{ joinRequestCount }}</span>
                    </button>
                    <div class="settings-nav-divider" />
                </template>

                <div class="settings-nav-group-label">My Settings</div>
                <button :class="navClass('my-settings')" @click="panel = 'my-settings'">Preferences</button>

                <div class="settings-nav-divider" />
                <button class="settings-nav-item settings-nav-item--danger" @click="$emit('close')">Close</button>
            </nav>

            <!-- Content -->
            <div class="settings-content">
                <div class="settings-panel-title">{{ panelTitle }}</div>

                <!-- Overview -->
                <div v-if="panel === 'overview'" class="settings-panel">
                    <div class="settings-section">
                        <label class="settings-label">Server name</label>
                        <input class="settings-input" v-model="overviewForm.name" placeholder="Server name" />
                    </div>
                    <div class="settings-section">
                        <label class="settings-label">Join mode</label>
                        <div class="settings-radio-group">
                            <label class="settings-radio">
                                <input type="radio" v-model="overviewForm.joinMode" value="open" />
                                Open — anyone with an account can join
                            </label>
                            <label class="settings-radio">
                                <input type="radio" v-model="overviewForm.joinMode" value="request" />
                                Request — members must be approved by an admin
                            </label>
                        </div>
                    </div>
                    <button class="settings-btn-primary" @click="saveOverview" :disabled="savingOverview">
                        {{ savingOverview ? 'Saving…' : 'Save changes' }}
                    </button>
                    <div v-if="overviewSaved" class="settings-saved">Saved.</div>
                </div>

                <!-- Appearance -->
                <div v-else-if="panel === 'appearance'" class="settings-panel">
                    <div class="settings-section">
                        <label class="settings-label">Server logo</label>
                        <div class="settings-asset-row">
                            <input class="settings-input" v-model="appearanceForm.logo" placeholder="https://… or upload below" />
                            <button class="settings-btn-secondary" @click="triggerUpload('logo')">Upload</button>
                            <input ref="logoFileInput" type="file" accept="image/*" style="display:none" @change="onFileSelected('logo', $event)" />
                        </div>
                        <div v-if="appearanceForm.logo" style="margin-top:8px;">
                            <img :src="appearanceForm.logo" style="max-height:48px;max-width:120px;border-radius:6px;object-fit:contain;" />
                        </div>
                    </div>

                    <div class="settings-section">
                        <label class="settings-label">Background</label>
                        <select class="settings-input settings-select" v-model="appearanceForm.backgroundType" style="margin-bottom:10px;">
                            <option value="none">None</option>
                            <option value="color">Solid colour</option>
                            <option value="image">Image</option>
                            <option value="video">Video</option>
                        </select>

                        <div v-if="appearanceForm.backgroundType === 'color'">
                            <div style="display:flex;gap:8px;align-items:center;">
                                <input type="color" v-model="appearanceForm.backgroundValue" style="width:40px;height:32px;border:none;background:none;cursor:pointer;" />
                                <span class="settings-hint">{{ appearanceForm.backgroundValue || 'Pick a colour' }}</span>
                                <button class="settings-btn-ghost" @click="appearanceForm.backgroundValue = ''">Clear</button>
                            </div>
                        </div>

                        <div v-else-if="appearanceForm.backgroundType === 'image'">
                            <div class="settings-asset-row">
                                <input class="settings-input" v-model="appearanceForm.backgroundValue" placeholder="https://… or upload below" />
                                <button class="settings-btn-secondary" @click="triggerUpload('background-image')">Upload</button>
                                <input ref="bgImageFileInput" type="file" accept="image/*" style="display:none" @change="onFileSelected('background', $event)" />
                            </div>
                        </div>

                        <div v-else-if="appearanceForm.backgroundType === 'video'">
                            <div class="settings-warning" style="margin-bottom:10px;">
                                ⚠️ You should ensure that the video is as small as possible as server bandwidth will be adversely affected by video backgrounds. Use this feature at your own risk.
                            </div>
                            <div class="settings-asset-row">
                                <input class="settings-input" v-model="appearanceForm.backgroundValue" placeholder="https://… or upload below" />
                                <button class="settings-btn-secondary" @click="triggerUpload('background-video')">Upload</button>
                                <input ref="bgVideoFileInput" type="file" accept="video/*" style="display:none" @change="onFileSelected('background', $event)" />
                            </div>
                        </div>
                    </div>

                    <div class="settings-section">
                        <label class="settings-label">Colours</label>
                        <div style="display:flex;gap:24px;flex-wrap:wrap;">
                            <div>
                                <div class="settings-hint" style="margin-bottom:6px;">Primary (accent)</div>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <input type="color" v-model="appearanceForm.primaryColor" style="width:40px;height:32px;border:none;background:none;cursor:pointer;" />
                                    <span class="settings-hint">{{ appearanceForm.primaryColor || 'Default' }}</span>
                                    <button class="settings-btn-ghost" @click="appearanceForm.primaryColor = ''">Clear</button>
                                </div>
                            </div>
                            <div>
                                <div class="settings-hint" style="margin-bottom:6px;">Accent hover</div>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <input type="color" v-model="appearanceForm.accentColor" style="width:40px;height:32px;border:none;background:none;cursor:pointer;" />
                                    <span class="settings-hint">{{ appearanceForm.accentColor || 'Default' }}</span>
                                    <button class="settings-btn-ghost" @click="appearanceForm.accentColor = ''">Clear</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="uploadingAsset" class="settings-hint">Uploading…</div>
                    <div v-if="uploadError" class="settings-error">{{ uploadError }}</div>
                    <button class="settings-btn-primary" @click="saveAppearance" :disabled="savingAppearance || uploadingAsset">
                        {{ savingAppearance ? 'Saving…' : 'Save appearance' }}
                    </button>
                    <div v-if="appearanceSaved" class="settings-saved">Saved.</div>
                </div>

                <!-- Welcome & Rules -->
                <div v-else-if="panel === 'content'" class="settings-panel">
                    <div class="settings-section">
                        <label class="settings-label">Welcome message</label>
                        <label class="settings-radio" style="margin-bottom:10px;">
                            <input type="checkbox" v-model="contentForm.welcomeEnabled" />
                            Show a welcome message to new members
                        </label>
                        <textarea
                            v-if="contentForm.welcomeEnabled"
                            class="settings-input settings-textarea"
                            v-model="contentForm.welcomeMessage"
                            placeholder="Write a welcome message for new members…"
                            rows="5"
                        />
                    </div>

                    <div class="settings-section">
                        <label class="settings-label">Server rules</label>
                        <label class="settings-radio" style="margin-bottom:10px;">
                            <input type="checkbox" v-model="contentForm.rulesEnabled" />
                            Enable server rules
                        </label>
                        <template v-if="contentForm.rulesEnabled">
                            <textarea
                                class="settings-input settings-textarea"
                                v-model="contentForm.rules"
                                placeholder="1. Be respectful.&#10;2. No spam.&#10;…"
                                rows="10"
                            />
                            <label class="settings-radio" style="margin-top:10px;">
                                <input type="checkbox" v-model="contentForm.requireRulesAck" />
                                Require members to acknowledge the rules before entering
                            </label>
                        </template>
                    </div>

                    <button class="settings-btn-primary" @click="saveContent" :disabled="savingContent">
                        {{ savingContent ? 'Saving…' : 'Save' }}
                    </button>
                    <div v-if="contentSaved" class="settings-saved">Saved.</div>
                </div>

                <!-- Plugins -->
                <div v-else-if="panel === 'plugins'" class="settings-panel">
                    <!-- Install new plugin -->
                    <div class="settings-section">
                        <label class="settings-label">Install Plugin</label>
                        <div class="settings-inline-form">
                            <input
                                class="settings-input"
                                v-model="installUrl"
                                placeholder="GitHub release URL (.zip)"
                                :disabled="installing"
                            />
                            <button class="settings-btn-primary" @click="installPlugin" :disabled="installing || !installUrl.trim()">
                                {{ installing ? 'Installing…' : 'Install' }}
                            </button>
                        </div>
                        <div v-if="installError" class="settings-error" style="margin-top:6px;">{{ installError }}</div>
                        <div v-if="installSuccess" class="settings-saved" style="margin-top:6px;">{{ installSuccess }}</div>
                        <div class="settings-hint" style="margin-top:6px;">Paste a GitHub release .zip URL. Approved plugins are verified with Eluth. <a href="https://eluth.io/developers" target="_blank" style="color:var(--accent)">Plugin docs →</a></div>
                    </div>

                    <div v-if="pluginsLoading" class="settings-hint">Loading plugins…</div>
                    <div v-else-if="pluginsList.length === 0" class="settings-hint">No plugins installed.</div>
                    <div v-else class="settings-plugin-grid">
                        <div v-for="plugin in pluginsList" :key="plugin.slug" class="settings-plugin-card" :class="{ 'plugin-enabled': plugin.is_enabled }">
                            <div class="settings-plugin-card-top">
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <span class="settings-plugin-name">{{ plugin.name }}</span>
                                    <span class="settings-plugin-tier" :class="'tier-' + plugin.tier">{{ plugin.tier }}</span>
                                </div>
                                <p v-if="plugin.manifest?.description" class="settings-plugin-desc">{{ plugin.manifest.description }}</p>
                            </div>

                            <!-- Settings fields shown when plugin is enabled and has settings -->
                            <div v-if="plugin.is_enabled && plugin.manifest?.settings?.length" class="settings-plugin-settings">
                                <div v-for="setting in plugin.manifest.settings" :key="setting.key" style="margin-bottom:12px;">
                                    <label class="settings-label">{{ setting.label }}</label>
                                    <input
                                        class="settings-input"
                                        :type="setting.type ?? 'text'"
                                        :placeholder="setting.placeholder ?? ''"
                                        v-model="pluginSettingValues[plugin.slug + '.' + setting.key]"
                                    />
                                </div>
                                <button
                                    class="settings-btn-primary"
                                    @click="savePluginSettings(plugin)"
                                    :disabled="savingPluginSettings[plugin.slug]"
                                >{{ savingPluginSettings[plugin.slug] ? 'Saving…' : 'Save settings' }}</button>
                                <span v-if="savedPluginSettings[plugin.slug]" class="settings-saved" style="margin-left:8px;">Saved.</span>
                            </div>

                            <div class="settings-plugin-card-footer">
                                <button
                                    class="settings-btn-primary"
                                    v-if="!plugin.is_enabled"
                                    @click="enablePlugin(plugin)"
                                >Enable</button>
                                <button
                                    class="settings-btn-ghost"
                                    v-else
                                    @click="disablePlugin(plugin)"
                                >Disable</button>
                                <button
                                    class="settings-btn-ghost settings-btn-danger"
                                    @click="uninstallPlugin(plugin)"
                                >Uninstall</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Channels -->
                <div v-else-if="panel === 'channels'" class="settings-panel">
                    <div class="settings-section" style="display:flex;justify-content:space-between;align-items:center;">
                        <span class="settings-label" style="margin:0;">Sections &amp; channels</span>
                        <button class="settings-btn-secondary" @click="showAddSection = true">+ Add section</button>
                    </div>

                    <div v-if="showAddSection" class="settings-inline-form">
                        <input class="settings-input" v-model="newSectionName" placeholder="Section name" @keydown.enter="addSection" />
                        <button class="settings-btn-primary" @click="addSection">Add</button>
                        <button class="settings-btn-ghost" @click="showAddSection = false; newSectionName = ''">Cancel</button>
                    </div>

                    <div v-for="section in channelSections" :key="section.id" class="settings-section-block">
                        <div class="settings-section-header">
                            <span class="settings-section-name">{{ section.label }}</span>
                            <div style="display:flex;gap:6px;">
                                <button class="settings-btn-ghost" @click="addChannelToSection(section)">+ Channel</button>
                                <button class="settings-btn-ghost settings-btn-danger" @click="deleteSection(section.id)">Delete</button>
                            </div>
                        </div>

                        <!-- Add channel form for this section -->
                        <div v-if="addingChannelToSection === section.id" class="settings-inline-form" style="margin-left:16px;">
                            <input class="settings-input" v-model="newChannel.name" placeholder="channel-name" />
                            <select class="settings-input settings-select" v-model="newChannel.type">
                                <option value="text">Text</option>
                                <option value="announcement">Announcement</option>
                                <option value="voice">Voice</option>
                                <option value="stream">Stream</option>
                            </select>
                            <button class="settings-btn-primary" @click="saveNewChannel(section.id)">Add</button>
                            <button class="settings-btn-ghost" @click="addingChannelToSection = null">Cancel</button>
                        </div>

                        <template v-for="channel in section.channels" :key="channel.id">
                            <div class="settings-channel-row">
                                <span class="settings-channel-icon">
                                    <span v-if="channel.type === 'announcement'">📢</span>
                                    <span v-else-if="channel.type === 'voice'">🔊</span>
                                    <span v-else-if="channel.type === 'stream'">📺</span>
                                    <span v-else>#</span>
                                </span>
                                <span class="settings-channel-name">{{ channel.name }}</span>
                                <div style="display:flex;gap:6px;">
                                    <button class="settings-btn-ghost"
                                        :class="{ active: editingChannel?.id === channel.id }"
                                        @click="toggleChannelEdit(channel)">Edit</button>
                                    <button class="settings-btn-ghost settings-btn-danger" @click="deleteChannel(channel.id)">Delete</button>
                                </div>
                            </div>

                            <!-- Inline channel editor -->
                            <div v-if="editingChannel?.id === channel.id" class="settings-channel-editor">
                                <div class="settings-channel-editor-fields">
                                    <div style="flex:1;">
                                        <label class="settings-label">Name</label>
                                        <input class="settings-input" v-model="channelEditForm.name" />
                                    </div>
                                    <div>
                                        <label class="settings-label">Type</label>
                                        <select class="settings-input settings-select" v-model="channelEditForm.type">
                                            <option value="text">Text</option>
                                            <option value="announcement">Announcement</option>
                                            <option value="voice">Voice</option>
                                            <option value="stream">Stream</option>
                                        </select>
                                    </div>
                                    <div style="flex:1;">
                                        <label class="settings-label">Topic <span class="settings-hint">(optional)</span></label>
                                        <input class="settings-input" v-model="channelEditForm.topic" placeholder="Channel topic…" />
                                    </div>
                                </div>
                                <div style="margin-bottom:16px;">
                                    <label class="settings-radio">
                                        <input type="checkbox" v-model="channelEditForm.is_private" />
                                        Private — only roles with explicit view access can see this channel
                                    </label>
                                </div>

                                <!-- Permission overwrites -->
                                <div class="settings-label" style="margin-bottom:8px;">Role permissions</div>
                                <div v-if="channelPermissionsLoading" class="settings-hint">Loading…</div>
                                <table v-else class="settings-perms-table">
                                    <thead>
                                        <tr>
                                            <th class="settings-perms-role-col">Role</th>
                                            <th class="settings-perms-check-col">Can view</th>
                                            <th class="settings-perms-check-col">Can send</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="row in channelPermissions" :key="row.role_id">
                                            <td>
                                                <span class="settings-role-dot" :style="row.role_color ? { background: row.role_color } : {}" />
                                                {{ row.role_name }}
                                                <span v-if="row.is_system" class="settings-role-badge">System</span>
                                            </td>
                                            <td class="settings-perms-check-col">
                                                <input type="checkbox" v-model="row.can_view" :disabled="row.is_system" />
                                            </td>
                                            <td class="settings-perms-check-col">
                                                <input type="checkbox" v-model="row.can_send" :disabled="row.is_system || !row.can_view" />
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>

                                <div style="display:flex;gap:8px;margin-top:14px;">
                                    <button class="settings-btn-primary" @click="saveChannelEdit" :disabled="savingChannel">
                                        {{ savingChannel ? 'Saving…' : 'Save' }}
                                    </button>
                                    <button class="settings-btn-ghost" @click="editingChannel = null">Cancel</button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Roles -->
                <div v-else-if="panel === 'roles'" class="settings-panel">
                    <div style="display:flex;gap:16px;height:100%;">
                        <!-- Role list -->
                        <div class="settings-role-list">
                            <div v-for="role in roles" :key="role.id"
                                class="settings-role-row"
                                :class="{ active: selectedRole?.id === role.id }"
                                @click="selectRole(role)"
                            >
                                <span class="settings-role-dot" :style="role.color ? { background: role.color } : {}" />
                                <span class="settings-role-name">{{ role.name }}</span>
                                <span v-if="role.is_system" class="settings-role-badge">System</span>
                                <span v-if="role.is_default" class="settings-role-badge">Default</span>
                            </div>
                            <button class="settings-btn-secondary" style="margin-top:12px;width:100%;" @click="createNewRole">+ New role</button>
                        </div>

                        <!-- Role editor -->
                        <div v-if="selectedRole" class="settings-role-editor">
                            <div v-if="selectedRole.is_system" class="settings-hint" style="margin-bottom:16px;">
                                The Super Admin role cannot be modified. It always has all permissions.
                            </div>
                            <template v-else>
                                <div class="settings-section">
                                    <label class="settings-label">Role name</label>
                                    <input class="settings-input" v-model="roleForm.name" />
                                </div>
                                <div class="settings-section">
                                    <label class="settings-label">Colour</label>
                                    <div style="display:flex;gap:8px;align-items:center;">
                                        <input type="color" v-model="roleForm.color" style="width:40px;height:32px;border:none;background:none;cursor:pointer;" />
                                        <span class="settings-hint">{{ roleForm.color || 'None' }}</span>
                                        <button class="settings-btn-ghost" @click="roleForm.color = ''">Clear</button>
                                    </div>
                                </div>
                                <div class="settings-section">
                                    <label class="settings-label">Default role <span class="settings-hint">(auto-assigned to new members)</span></label>
                                    <label class="settings-radio">
                                        <input type="checkbox" :checked="roleForm.is_default" @change="onToggleDefault($event.target.checked)" />
                                        Make this the default role
                                    </label>
                                </div>
                                <div class="settings-section">
                                    <label class="settings-label">Permissions</label>
                                    <div v-for="(perms, category) in allPermissions" :key="category" class="settings-perm-group">
                                        <div class="settings-perm-category">{{ category }}</div>
                                        <label v-for="(desc, key) in perms" :key="key" class="settings-perm-row">
                                            <input type="checkbox" :value="key" :checked="roleForm.permissions.includes(key)" @change="onTogglePermission(key, $event.target.checked)" />
                                            <div>
                                                <div class="settings-perm-name">{{ desc }}</div>
                                                <div class="settings-perm-key">{{ key }}</div>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                                <div style="display:flex;gap:8px;">
                                    <button class="settings-btn-primary" @click="saveRole" :disabled="savingRole">
                                        {{ savingRole ? 'Saving…' : 'Save role' }}
                                    </button>
                                    <button class="settings-btn-ghost settings-btn-danger" @click="deleteRole" :disabled="selectedRole.is_system">
                                        Delete role
                                    </button>
                                </div>
                            </template>
                        </div>
                        <div v-else class="settings-role-editor settings-hint">Select a role to edit it.</div>
                    </div>
                </div>

                <!-- Members -->
                <div v-else-if="panel === 'members'" class="settings-panel">
                    <input class="settings-input" v-model="memberSearch" placeholder="Search members…" style="margin-bottom:16px;" />
                    <div class="settings-member-list">
                        <div v-for="member in filteredMembers" :key="member.id" class="settings-member-row">
                            <div class="settings-member-avatar">{{ member.username.slice(0,2).toUpperCase() }}</div>
                            <div style="flex:1;min-width:0;">
                                <div class="settings-member-name">{{ member.username }}</div>
                                <div class="settings-member-roles">
                                    <span v-for="role in member.roles" :key="role.id"
                                        class="settings-role-tag"
                                        :style="role.color ? { borderColor: role.color, color: role.color } : {}"
                                    >{{ role.name }}</span>
                                </div>
                            </div>
                            <div style="display:flex;gap:6px;" v-if="!member.is_super_admin">
                                <button class="settings-btn-ghost" @click="openRoleAssign(member)" title="Manage roles">Roles</button>
                                <button v-if="can('kick_members')" class="settings-btn-ghost" @click="kickMember(member.id)">Kick</button>
                                <button v-if="can('ban_members')"  class="settings-btn-ghost settings-btn-danger" @click="banMember(member.id)">Ban</button>
                            </div>
                        </div>
                    </div>

                    <!-- Role assignment sub-panel -->
                    <div v-if="roleAssignTarget" class="settings-role-assign-overlay" @click.self="roleAssignTarget = null">
                        <div class="settings-role-assign-panel">
                            <div class="settings-panel-title" style="margin-bottom:16px;">Roles — {{ roleAssignTarget.username }}</div>
                            <div v-for="role in nonSystemRoles" :key="role.id" class="settings-perm-row">
                                <input type="checkbox"
                                    :checked="memberHasRole(roleAssignTarget, role.id)"
                                    @change="toggleMemberRole(roleAssignTarget, role, $event.target.checked)"
                                />
                                <div>
                                    <div class="settings-perm-name">{{ role.name }}</div>
                                </div>
                            </div>
                            <button class="settings-btn-ghost" style="margin-top:16px;" @click="roleAssignTarget = null">Done</button>
                        </div>
                    </div>
                </div>

                <!-- Join requests -->
                <div v-else-if="panel === 'join-requests'" class="settings-panel">
                    <div v-if="joinRequests.length === 0" class="settings-hint">No pending join requests.</div>
                    <div v-for="req in joinRequests" :key="req.central_user_id" class="settings-member-row">
                        <div class="settings-member-avatar">{{ req.username.slice(0,2).toUpperCase() }}</div>
                        <div style="flex:1;min-width:0;">
                            <div class="settings-member-name">{{ req.username }}</div>
                            <div class="settings-hint">Requested {{ formatDate(req.joined_at) }}</div>
                        </div>
                        <div style="display:flex;gap:6px;">
                            <button class="settings-btn-primary" @click="approveRequest(req.central_user_id)">Approve</button>
                            <button class="settings-btn-ghost settings-btn-danger" @click="denyRequest(req.central_user_id)">Deny</button>
                        </div>
                    </div>
                </div>

                <!-- Emotes -->
                <div v-else-if="panel === 'emotes'" class="settings-panel">
                    <!-- Upload form -->
                    <div class="settings-section">
                        <label class="settings-label">Upload emote</label>
                        <div class="settings-hint" style="margin-bottom:8px;">Name: lowercase letters, numbers, underscores, hyphens (2–32 chars). File: GIF, PNG, or WebP — max 512 KB. Animated GIFs are supported.</div>
                        <div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;">
                            <input
                                class="settings-input"
                                v-model="emoteUploadName"
                                placeholder="emote_name"
                                style="width:150px;"
                                :disabled="emoteUploading"
                            />
                            <input
                                ref="emoteFileInput"
                                type="file"
                                accept="image/gif,image/png,image/webp"
                                style="display:none"
                                @change="onEmoteFileSelected"
                            />
                            <button class="settings-btn-secondary" @click="emoteFileInput?.click()" :disabled="emoteUploading">
                                {{ emoteSelectedFile ? emoteSelectedFile.name : 'Choose file…' }}
                            </button>
                            <button class="settings-btn-primary" @click="uploadEmote" :disabled="emoteUploading || !emoteUploadName.trim() || !emoteSelectedFile">
                                {{ emoteUploading ? 'Uploading…' : 'Upload' }}
                            </button>
                        </div>
                        <div v-if="emoteUploadError"   class="settings-error" style="margin-top:6px;">{{ emoteUploadError }}</div>
                        <div v-if="emoteUploadSuccess" class="settings-saved" style="margin-top:6px;">{{ emoteUploadSuccess }}</div>
                    </div>

                    <!-- Emote list -->
                    <div class="settings-section">
                        <label class="settings-label">Custom emotes</label>
                        <div v-if="emotesLoading" class="settings-hint">Loading…</div>
                        <div v-else-if="emotesList.length === 0" class="settings-hint">No custom emotes yet.</div>
                        <div v-else class="emotes-grid">
                            <div v-for="emote in emotesList" :key="emote.name" class="emote-row">
                                <img :src="emote.url" :alt="emote.name" class="emote-preview" />
                                <div class="emote-row-info">
                                    <span class="emote-row-name">:{{ emote.name }}:</span>
                                    <span v-if="emote.animated" class="emote-row-badge">Animated</span>
                                </div>
                                <button class="settings-btn-ghost settings-btn-danger" @click="deleteEmote(emote.name)" style="margin-left:auto;">Delete</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- My Settings -->
                <div v-else-if="panel === 'my-settings'" class="settings-panel">
                    <div class="settings-hint">User preferences for this server will appear here.</div>
                </div>
            </div>

            <!-- Close button -->
            <button class="settings-close" @click="$emit('close')" title="Close (Esc)">✕</button>
        </div>

        <!-- Uninstall confirmation modal -->
        <div v-if="uninstallTarget" class="settings-role-assign-overlay" @click.self="uninstallTarget = null">
            <div class="settings-role-assign-panel">
                <div class="settings-panel-title" style="margin-bottom:12px;">Uninstall "{{ uninstallTarget.name }}"?</div>
                <p class="settings-hint" style="margin-bottom:16px;">This will remove the plugin files and settings. This action cannot be undone.</p>
                <label style="display:flex;align-items:flex-start;gap:10px;margin-bottom:20px;cursor:pointer;">
                    <input type="checkbox" v-model="uninstallKeepData" style="margin-top:2px;flex-shrink:0;" />
                    <span class="settings-hint" style="margin:0;">Keep plugin data (database tables and records). Use this if you plan to reinstall the same plugin and want to preserve existing data.</span>
                </label>
                <div style="display:flex;gap:8px;">
                    <button class="settings-btn-ghost settings-btn-danger" @click="confirmUninstall" :disabled="uninstalling">
                        {{ uninstalling ? 'Uninstalling…' : 'Uninstall' }}
                    </button>
                    <button class="settings-btn-ghost" @click="uninstallTarget = null" :disabled="uninstalling">Cancel</button>
                </div>
                <div v-if="uninstallError" class="settings-error" style="margin-top:10px;">{{ uninstallError }}</div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useApi } from '../composables/useApi.js'

const props = defineProps({
    serverName:    { type: String, default: 'Server' },
    currentMember: { type: Object, default: null },
    members:       { type: Array,  default: () => [] },
    theme:         { type: Object, default: () => ({}) },
})

const emit = defineEmits(['close', 'server-updated', 'appearance-updated', 'members-updated', 'channels-updated'])

const { get, post } = useApi()

function can(perm) {
    return props.currentMember?.can(perm) ?? false
}

const hasAnyAdminPermission = computed(() =>
    ['manage_server','manage_channels','manage_roles','approve_members','kick_members','ban_members','manage_member_roles']
        .some(p => can(p))
)
const hasAnyMemberPermission = computed(() =>
    ['kick_members','ban_members','manage_member_roles'].some(p => can(p))
)

// ── Panel routing ───────────────────────────────────────────────────────────
const defaultPanel = computed(() => {
    if (can('manage_server'))   return 'overview'
    if (can('manage_channels')) return 'channels'
    if (can('manage_roles'))    return 'roles'
    if (hasAnyMemberPermission.value) return 'members'
    if (can('approve_members')) return 'join-requests'
    return 'my-settings'
})

const panel = ref(defaultPanel.value)

const panelTitle = computed(() => ({
    'overview':      'Server Overview',
    'appearance':    'Appearance',
    'content':       'Welcome & Rules',
    'plugins':       'Plugins',
    'channels':      'Channels',
    'roles':         'Roles',
    'members':       'Members',
    'join-requests': 'Join Requests',
    'emotes':        'Server Emotes',
    'my-settings':   'My Preferences',
}[panel.value] ?? ''))

function navClass(p) {
    return ['settings-nav-item', panel.value === p ? 'active' : ''].filter(Boolean).join(' ')
}

// ── Escape to close ─────────────────────────────────────────────────────────
function onEsc(e) { if (e.key === 'Escape') emit('close') }
onMounted(() => document.addEventListener('keydown', onEsc))
onUnmounted(() => document.removeEventListener('keydown', onEsc))

// ── Overview ────────────────────────────────────────────────────────────────
const overviewForm  = ref({ name: props.serverName, joinMode: 'open' })

watch(() => panel.value, async (p) => {
    if (p === 'appearance') {
        appearanceForm.value = {
            logo:            props.theme?.logo            ?? '',
            backgroundType:  props.theme?.backgroundType  ?? 'none',
            backgroundValue: props.theme?.backgroundValue ?? '',
            primaryColor:    props.theme?.primaryColor    ?? '',
            accentColor:     props.theme?.accentColor     ?? '',
        }
    }
})
const savingOverview = ref(false)
const overviewSaved  = ref(false)

// ── Appearance ───────────────────────────────────────────────────────────────
const appearanceForm = ref({
    logo: '', backgroundType: 'none', backgroundValue: '', primaryColor: '', accentColor: '',
})
const savingAppearance = ref(false)
const appearanceSaved  = ref(false)
const uploadingAsset   = ref(false)
const uploadError      = ref('')

const logoFileInput    = ref(null)
const bgImageFileInput = ref(null)
const bgVideoFileInput = ref(null)

function triggerUpload(type) {
    if (type === 'logo')             logoFileInput.value?.click()
    if (type === 'background-image') bgImageFileInput.value?.click()
    if (type === 'background-video') bgVideoFileInput.value?.click()
}

async function onFileSelected(type, event) {
    const file = event.target.files?.[0]
    if (!file) return
    uploadingAsset.value = true
    uploadError.value    = ''
    try {
        const token = localStorage.getItem('eluth_token') ?? ''
        const form = new FormData()
        form.append('file', file)
        form.append('type', type)
        const res  = await fetch('/api/admin/upload', {
            method: 'POST',
            headers: { Authorization: 'Bearer ' + token },
            body: form,
        })
        const text = await res.text()
        let data
        try { data = JSON.parse(text) } catch { data = {} }

        if (res.ok && data.url) {
            if (type === 'logo') appearanceForm.value.logo = data.url
            else                 appearanceForm.value.backgroundValue = data.url
        } else {
            uploadError.value = data.error ?? `Server returned ${res.status}`
        }
    } catch (err) {
        uploadError.value = 'Upload failed: ' + (err?.message ?? 'unknown error')
    } finally {
        uploadingAsset.value = false
        event.target.value = ''
    }
}

async function saveAppearance() {
    savingAppearance.value = true
    await post('/admin/server/appearance', {
        logo:             appearanceForm.value.logo             || null,
        background_type:  appearanceForm.value.backgroundType,
        background_value: appearanceForm.value.backgroundValue  || null,
        primary_color:    appearanceForm.value.primaryColor     || null,
        accent_color:     appearanceForm.value.accentColor      || null,
    }).catch(() => {})
    savingAppearance.value = false
    appearanceSaved.value  = true
    emit('appearance-updated', {
        logo:             appearanceForm.value.logo             || null,
        background_type:  appearanceForm.value.backgroundType,
        background_value: appearanceForm.value.backgroundValue  || null,
        primary_color:    appearanceForm.value.primaryColor     || null,
        accent_color:     appearanceForm.value.accentColor      || null,
    })
    setTimeout(() => appearanceSaved.value = false, 2000)
}

// ── Welcome & Rules ───────────────────────────────────────────────────────────
const contentForm   = ref({ welcomeEnabled: false, welcomeMessage: '', rulesEnabled: false, rules: '', requireRulesAck: false })
const savingContent = ref(false)
const contentSaved  = ref(false)

async function saveContent() {
    savingContent.value = true
    await post('/admin/server/content', {
        welcome_enabled:   contentForm.value.welcomeEnabled,
        welcome_message:   contentForm.value.welcomeMessage || null,
        rules_enabled:     contentForm.value.rulesEnabled,
        rules:             contentForm.value.rules           || null,
        require_rules_ack: contentForm.value.requireRulesAck,
    }).catch(() => {})
    savingContent.value = false
    contentSaved.value  = true
    setTimeout(() => contentSaved.value = false, 2000)
}

async function saveOverview() {
    savingOverview.value = true
    await post('/admin/server', overviewForm.value).catch(() => {})
    savingOverview.value = false
    overviewSaved.value  = true
    emit('server-updated', overviewForm.value.name)
    setTimeout(() => overviewSaved.value = false, 2000)
}

// ── Channels ────────────────────────────────────────────────────────────────
const channelSections        = ref([])
const showAddSection         = ref(false)
const newSectionName         = ref('')
const addingChannelToSection = ref(null)
const newChannel             = ref({ name: '', type: 'text' })
const editingChannel         = ref(null)
const channelEditForm        = ref({ name: '', type: 'text', topic: '', is_private: false })
const channelPermissions     = ref([])
const channelPermissionsLoading = ref(false)
const savingChannel          = ref(false)

watch(() => panel.value, async (p) => {
    if (p === 'channels' && channelSections.value.length === 0) await loadChannels()
    if (p === 'roles'    && roles.value.length === 0)           await loadRoles()
    if (p === 'members'  && localMembers.value.length === 0)    loadLocalMembers()
    if (p === 'join-requests')                                  await loadJoinRequests()
    if (p === 'content')                                        await loadContentSettings()
    if (p === 'plugins')                                        await loadPlugins()
    if (p === 'emotes') await loadEmotes()
}, { immediate: true })

async function loadContentSettings() {
    const info = await fetch('/api/server').then(r => r.json()).catch(() => null)
    if (!info) return
    contentForm.value = {
        welcomeEnabled:  info.welcome_enabled  ?? false,
        welcomeMessage:  info.welcome_message  ?? '',
        rulesEnabled:    info.rules_enabled    ?? false,
        rules:           info.rules            ?? '',
        requireRulesAck: info.require_rules_ack ?? false,
    }
}

async function loadChannels() {
    const data = await get('/channels').catch(() => ({ sections: [] }))
    channelSections.value = data.sections
}

async function addSection() {
    if (!newSectionName.value.trim()) return
    const name = newSectionName.value.trim()
    const result = await post('/admin/sections', { name })
    channelSections.value.push(result.section)
    newSectionName.value = ''
    showAddSection.value = false
    emit('channels-updated')
}

async function deleteSection(sectionId) {
    if (!confirm('Delete this section and all its channels?')) return
    await post('/admin/sections/' + sectionId + '/delete', {})
    await loadChannels()
    emit('channels-updated')
}

function addChannelToSection(section) {
    addingChannelToSection.value = section.id
    newChannel.value = { name: '', type: 'text' }
}

async function saveNewChannel(sectionId) {
    if (!newChannel.value.name.trim()) return
    await post('/admin/channels', { section_id: sectionId, name: newChannel.value.name.trim(), type: newChannel.value.type })
    addingChannelToSection.value = null
    await loadChannels()
    emit('channels-updated')
}

async function deleteChannel(channelId) {
    if (!confirm('Delete this channel and all its messages?')) return
    await post('/admin/channels/' + channelId + '/delete', {})
    if (editingChannel.value?.id === channelId) editingChannel.value = null
    await loadChannels()
    emit('channels-updated')
}

async function toggleChannelEdit(channel) {
    if (editingChannel.value?.id === channel.id) {
        editingChannel.value = null
        return
    }
    editingChannel.value = channel
    channelEditForm.value = { name: channel.name, type: channel.type, topic: channel.topic ?? '', is_private: channel.is_private ?? false }
    channelPermissionsLoading.value = true
    channelPermissions.value = []
    const data = await get('/admin/channels/' + channel.id + '/permissions').catch(() => ({ permissions: [] }))
    channelPermissions.value = data.permissions
    channelPermissionsLoading.value = false
}

async function saveChannelEdit() {
    if (!editingChannel.value) return
    savingChannel.value = true
    await post('/admin/channels/' + editingChannel.value.id, channelEditForm.value).catch(() => {})
    await post('/admin/channels/' + editingChannel.value.id + '/permissions', {
        overwrites: channelPermissions.value.map(r => ({
            role_id:  r.role_id,
            can_view: r.can_view,
            can_send: r.can_send,
        })),
    }).catch(() => {})
    savingChannel.value = false
    await loadChannels()
    editingChannel.value = null
    emit('channels-updated')
}

// ── Roles ────────────────────────────────────────────────────────────────────
const roles        = ref([])
const selectedRole = ref(null)
const allPermissions = ref({})
const roleForm     = ref({ name: '', color: '', is_default: false, permissions: [] })
const savingRole   = ref(false)

async function loadRoles() {
    const data = await get('/admin/roles').catch(() => ({ roles: [], all_permissions: {} }))
    roles.value       = data.roles
    allPermissions.value = data.all_permissions
}

function selectRole(role) {
    selectedRole.value = role
    roleForm.value = {
        name:        role.name,
        color:       role.color ?? '',
        is_default:  role.is_default,
        permissions: [...(role.permissions ?? [])],
    }
}

const ELEVATED_PERMISSIONS = new Set([
    'manage_server', 'manage_roles', 'manage_channels', 'manage_member_roles',
    'kick_members', 'ban_members', 'approve_members', 'delete_any_message',
    'pin_messages', 'view_audit_log',
])

function onToggleDefault(checked) {
    if (!checked) {
        roleForm.value.is_default = false
        return
    }

    const messages = []

    // Check if another role is already the default
    const currentDefault = roles.value.find(r => r.is_default && r.id !== selectedRole.value?.id)
    if (currentDefault) {
        messages.push(`The current default role is "${currentDefault.name}". Are you sure you want to replace it?`)
    }

    // Check if this role has elevated permissions
    const hasElevated = roleForm.value.permissions.some(p => ELEVATED_PERMISSIONS.has(p))
    if (hasElevated) {
        messages.push(
            'This role has elevated permissions. Making it the default will give new — and potentially existing — members the ability to manage the server. Accept this at your own risk.'
        )
    }

    if (messages.length > 0 && !confirm(messages.join('\n\n'))) {
        return  // user cancelled — leave checkbox unchecked
    }

    roleForm.value.is_default = true
}

function onTogglePermission(key, checked) {
    if (!checked) {
        roleForm.value.permissions = roleForm.value.permissions.filter(p => p !== key)
        return
    }

    if (roleForm.value.is_default && ELEVATED_PERMISSIONS.has(key)) {
        if (!confirm(
            'This role is the default role. Adding elevated permissions will give new — and potentially existing — members the ability to manage the server. Accept this at your own risk.'
        )) {
            return  // leave unchecked
        }
    }

    roleForm.value.permissions = [...roleForm.value.permissions, key]
}

function createNewRole() {
    selectedRole.value = { id: null, name: 'New Role', is_system: false, is_default: false }
    roleForm.value = { name: 'New Role', color: '', is_default: false, permissions: [] }
}

async function saveRole() {
    savingRole.value = true
    if (selectedRole.value.id) {
        const updated = await post('/admin/roles/' + selectedRole.value.id + '/update', roleForm.value).catch(() => null)
        if (updated) {
            // If this role became the default, unset is_default on all others locally
            if (updated.role.is_default) {
                roles.value.forEach(r => { if (r.id !== updated.role.id) r.is_default = false })
            }
            const idx = roles.value.findIndex(r => r.id === selectedRole.value.id)
            if (idx !== -1) roles.value[idx] = updated.role
        }
    } else {
        const created = await post('/admin/roles', roleForm.value).catch(() => null)
        if (created) roles.value.push(created.role)
    }
    savingRole.value = false
}

async function deleteRole() {
    if (!selectedRole.value.id || !confirm('Delete this role?')) return
    await post('/admin/roles/' + selectedRole.value.id + '/delete', {})
    roles.value = roles.value.filter(r => r.id !== selectedRole.value.id)
    selectedRole.value = null
}

// ── Members ──────────────────────────────────────────────────────────────────
const localMembers    = ref([])
const memberSearch    = ref('')
const roleAssignTarget = ref(null)

const nonSystemRoles = computed(() => roles.value.filter(r => !r.is_system))

const filteredMembers = computed(() =>
    memberSearch.value
        ? localMembers.value.filter(m => m.username.toLowerCase().includes(memberSearch.value.toLowerCase()))
        : localMembers.value
)

function loadLocalMembers() { localMembers.value = [...props.members] }

function openRoleAssign(member) {
    if (roles.value.length === 0) loadRoles()
    roleAssignTarget.value = member
}

function memberHasRole(member, roleId) {
    return member.roles?.some(r => r.id === roleId)
}

async function toggleMemberRole(member, role, checked) {
    if (checked) {
        await post('/admin/members/' + member.id + '/roles/' + role.id, {})
        member.roles = [...(member.roles ?? []), { id: role.id, name: role.name, color: role.color }]
    } else {
        await deleteRequest('/admin/members/' + member.id + '/roles/' + role.id)
        member.roles = (member.roles ?? []).filter(r => r.id !== role.id)
    }
    emit('members-updated')
}

async function kickMember(userId) {
    if (!confirm('Kick this member?')) return
    await post('/admin/members/' + userId + '/kick', {})
    localMembers.value = localMembers.value.filter(m => m.id !== userId)
    emit('members-updated')
}

async function banMember(userId) {
    if (!confirm('Ban this member?')) return
    await post('/admin/members/' + userId + '/ban', {})
    localMembers.value = localMembers.value.filter(m => m.id !== userId)
    emit('members-updated')
}

// ── Join requests ─────────────────────────────────────────────────────────────
const joinRequests     = ref([])
const joinRequestCount = computed(() => joinRequests.value.length)

async function loadJoinRequests() {
    const data = await get('/admin/join-requests').catch(() => ({ requests: [] }))
    joinRequests.value = data.requests
}

async function approveRequest(userId) {
    await post('/admin/join-requests/' + userId + '/approve', {})
    joinRequests.value = joinRequests.value.filter(r => r.central_user_id !== userId)
    emit('members-updated')
}

async function denyRequest(userId) {
    await post('/admin/join-requests/' + userId + '/deny', {})
    joinRequests.value = joinRequests.value.filter(r => r.central_user_id !== userId)
}

// ── Plugins ──────────────────────────────────────────────────────────────────
const pluginsList           = ref([])
const pluginsLoading        = ref(false)
const pluginSettingValues   = ref({})   // keyed as 'slug.key'
const savingPluginSettings  = ref({})   // { slug: bool }
const savedPluginSettings   = ref({})   // { slug: bool }
const installUrl            = ref('')
const installing            = ref(false)
const installError          = ref('')
const installSuccess        = ref('')

async function loadPlugins() {
    pluginsLoading.value = true
    try {
        const data = await get('/plugins').catch(() => ({ plugins: [] }))
        pluginsList.value = data.plugins ?? []
        // Populate setting values
        const vals = {}
        for (const plugin of pluginsList.value) {
            for (const setting of (plugin.manifest?.settings ?? [])) {
                vals[plugin.slug + '.' + setting.key] = setting.value ?? ''
            }
        }
        pluginSettingValues.value = vals
    } finally {
        pluginsLoading.value = false
    }
}

async function enablePlugin(plugin) {
    await post('/admin/plugins/' + plugin.slug + '/enable', {}).catch(() => {})
    plugin.is_enabled = true
}

async function disablePlugin(plugin) {
    await post('/admin/plugins/' + plugin.slug + '/disable', {}).catch(() => {})
    plugin.is_enabled = false
}

async function savePluginSettings(plugin) {
    savingPluginSettings.value[plugin.slug] = true
    const settings = {}
    for (const setting of (plugin.manifest?.settings ?? [])) {
        settings[setting.key] = pluginSettingValues.value[plugin.slug + '.' + setting.key] ?? ''
    }
    await post('/admin/plugins/' + plugin.slug + '/settings', { settings }).catch(() => {})
    savingPluginSettings.value[plugin.slug] = false
    savedPluginSettings.value[plugin.slug] = true
    setTimeout(() => { savedPluginSettings.value[plugin.slug] = false }, 2000)
}

async function installPlugin() {
    installError.value   = ''
    installSuccess.value = ''
    installing.value     = true
    try {
        const data = await post('/admin/plugins/install', { url: installUrl.value.trim() })
        installSuccess.value = `"${data.name}" installed successfully. Enable it below.`
        installUrl.value = ''
        await loadPlugins()
    } catch (e) {
        installError.value = e.message ?? 'Installation failed.'
    } finally {
        installing.value = false
    }
}

const uninstallTarget   = ref(null)
const uninstallKeepData = ref(false)
const uninstalling      = ref(false)
const uninstallError    = ref('')

function uninstallPlugin(plugin) {
    uninstallTarget.value   = plugin
    uninstallKeepData.value = false
    uninstallError.value    = ''
}

async function confirmUninstall() {
    if (! uninstallTarget.value) return
    uninstalling.value   = true
    uninstallError.value = ''
    try {
        await post('/admin/plugins/' + uninstallTarget.value.slug + '/uninstall', {
            keep_data: uninstallKeepData.value,
        })
        uninstallTarget.value = null
        await loadPlugins()
    } catch (e) {
        uninstallError.value = e.message ?? 'Uninstall failed.'
    } finally {
        uninstalling.value = false
    }
}

// ── Emotes ──────────────────────────────────────────────────────────────────
const emoteFileInput    = ref(null)
const emotesList        = ref([])
const emotesLoading     = ref(false)
const emoteUploadName   = ref('')
const emoteSelectedFile = ref(null)
const emoteUploading    = ref(false)
const emoteUploadError  = ref('')
const emoteUploadSuccess = ref('')

async function loadEmotes() {
    emotesLoading.value = true
    try {
        const data = await get('/plugins/emoticons/emotes')
        emotesList.value = data.emotes ?? []
    } catch { emotesList.value = [] } finally {
        emotesLoading.value = false
    }
}

function onEmoteFileSelected(e) {
    emoteSelectedFile.value = e.target.files?.[0] ?? null
}

async function uploadEmote() {
    emoteUploadError.value   = ''
    emoteUploadSuccess.value = ''
    const name = emoteUploadName.value.trim().toLowerCase()
    if (!/^[a-z0-9_-]{2,32}$/.test(name)) {
        emoteUploadError.value = 'Name must be 2–32 chars: lowercase letters, numbers, _ or -'
        return
    }
    if (!emoteSelectedFile.value) return
    emoteUploading.value = true
    try {
        const token = localStorage.getItem('eluth_token') ?? ''
        const form  = new FormData()
        form.append('name', name)
        form.append('file', emoteSelectedFile.value)
        const res = await fetch('/api/admin/plugins/emoticons/emotes', {
            method: 'POST',
            headers: { Authorization: 'Bearer ' + token },
            body: form,
        })
        const data = await res.json()
        if (!res.ok) throw new Error(data.message ?? 'Upload failed')
        emoteUploadSuccess.value = ':' + name + ': uploaded!'
        emoteUploadName.value    = ''
        emoteSelectedFile.value  = null
        if (emoteFileInput.value) emoteFileInput.value.value = ''
        await loadEmotes()
    } catch (e) {
        emoteUploadError.value = e.message ?? 'Upload failed'
    } finally {
        emoteUploading.value = false
    }
}

async function deleteEmote(name) {
    if (!confirm(`Delete :${name}: permanently?`)) return
    try {
        const token = localStorage.getItem('eluth_token') ?? ''
        await fetch(`/api/admin/plugins/emoticons/emotes/${name}`, {
            method: 'DELETE',
            headers: { Authorization: 'Bearer ' + token },
        })
        await loadEmotes()
    } catch { /* ignore */ }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
const { del: deleteRequest } = (() => {
    const { get: _g, post: _p } = useApi()
    async function del(path) {
        const token = localStorage.getItem('eluth_token') ?? ''
        await fetch('/api' + path, { method: 'DELETE', headers: { Authorization: 'Bearer ' + token } })
    }
    return { del }
})()

function formatDate(iso) {
    return iso ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : ''
}
</script>

<style scoped>
.settings-plugin-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 12px;
}

.settings-plugin-card {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 10px;
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    transition: border-color 0.15s;
}
.settings-plugin-card.plugin-enabled {
    border-color: rgba(88,101,242,0.35);
}

.settings-plugin-card-top {
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex: 1;
}

.settings-plugin-card-footer {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: auto;
}

.settings-plugin-name {
    font-size: 14px;
    font-weight: 600;
    color: #e2e8f0;
}

.settings-plugin-tier {
    display: inline-block;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 1px 6px;
    border-radius: 3px;
    white-space: nowrap;
}
.tier-official   { background: rgba(88,101,242,0.2);  color: #818cf8; }
.tier-approved   { background: rgba(34,197,94,0.15);  color: #4ade80; }
.tier-unofficial { background: rgba(234,179,8,0.15);  color: #facc15; }

.settings-plugin-desc {
    font-size: 12px;
    color: rgba(255,255,255,0.45);
    margin: 0;
}

.settings-plugin-settings {
    padding-top: 10px;
    border-top: 1px solid rgba(255,255,255,0.06);
}

.emotes-grid {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 8px;
}

.emote-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 8px;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 6px;
}

.emote-preview {
    width: 32px;
    height: 32px;
    object-fit: contain;
    border-radius: 4px;
    flex-shrink: 0;
}

.emote-row-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.emote-row-name {
    font-size: 13px;
    font-weight: 600;
    color: #e2e8f0;
    font-family: monospace;
}

.emote-row-badge {
    font-size: 10px;
    background: rgba(88,101,242,0.2);
    color: var(--accent, #5865f2);
    border-radius: 3px;
    padding: 1px 5px;
    font-weight: 600;
    width: fit-content;
}
</style>
