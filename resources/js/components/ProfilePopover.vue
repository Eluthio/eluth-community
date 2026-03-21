<template>
    <Teleport to="body">
        <div
            class="profile-popover-backdrop"
            @click.self="$emit('close')"
            @keydown.esc="$emit('close')"
        >
            <div
                class="profile-popover"
                :style="positionStyle"
            >
                <!-- Loading -->
                <div v-if="loading" class="profile-popover-loading">
                    <span class="profile-popover-spinner" />
                </div>

                <!-- Error -->
                <div v-else-if="error" class="profile-popover-error">
                    {{ error }}
                </div>

                <!-- Profile -->
                <template v-else-if="profile">
                    <!-- Banner -->
                    <div
                        class="profile-popover-banner"
                        :style="bannerStyle"
                    />

                    <!-- Avatar -->
                    <div class="profile-popover-avatar-wrap">
                        <div
                            class="profile-popover-avatar"
                            :style="{ borderColor: profile.profile_accent || 'var(--accent)' }"
                        >
                            <img
                                v-if="profile.avatar_url"
                                :src="profile.avatar_url"
                                :alt="profile.username"
                                @error="e => e.target.style.display='none'"
                            />
                            <span v-else>{{ initials(profile.display_name || profile.username) }}</span>
                        </div>
                    </div>

                    <!-- Info -->
                    <div class="profile-popover-body">
                        <div class="profile-popover-names">
                            <span
                                class="profile-popover-display"
                                :style="{ color: profile.profile_accent || 'var(--accent)' }"
                            >{{ profile.display_name || profile.username }}</span>
                            <span v-if="profile.display_name" class="profile-popover-username">{{ profile.username }}</span>
                        </div>

                        <div v-if="profile.status_text" class="profile-popover-status">
                            {{ profile.status_text }}
                        </div>

                        <div v-if="profile.bio" class="profile-popover-bio">{{ profile.bio }}</div>

                        <div class="profile-popover-since">
                            Member since {{ profile.member_since }}
                        </div>

                        <!-- Actions -->
                        <div v-if="!isSelf" class="profile-popover-actions">
                            <button class="profile-action-btn" @click="emit('open-dm', { id: profile.id, username: profile.username })">
                                Message
                            </button>
                            <!-- Friend button — varies by friendship_status -->
                            <button
                                v-if="!profile.friendship_status"
                                class="profile-action-btn"
                                :disabled="friendBusy"
                                @click="sendFriendRequest"
                            >Send Friend Request</button>
                            <button
                                v-else-if="profile.friendship_status === 'pending_sent'"
                                class="profile-action-btn"
                                disabled
                            >Request Sent…</button>
                            <button
                                v-else-if="profile.friendship_status === 'pending_received'"
                                class="profile-action-btn"
                                :disabled="friendBusy"
                                @click="acceptFriendRequest"
                            >Accept Friend Request</button>
                            <button
                                v-else-if="profile.friendship_status === 'friends'"
                                class="profile-action-btn profile-action-btn--ghost"
                                :disabled="friendBusy"
                                @click="removeFriend"
                            >Friends ✓</button>
                            <button
                                v-else-if="profile.friendship_status === 'blocked'"
                                class="profile-action-btn profile-action-btn--ghost"
                                @click="unblockUser"
                            >Unblock</button>
                            <!-- Block / Report -->
                            <button
                                v-if="profile.friendship_status !== 'blocked'"
                                class="profile-action-btn profile-action-btn--ghost"
                                @click="blockUser"
                            >Block</button>
                            <button class="profile-action-btn profile-action-btn--ghost" @click="emit('report', { userId: profile.id })">
                                Report
                            </button>
                        </div>
                        <div v-else class="profile-popover-actions">
                            <button class="profile-action-btn" @click="emit('open-settings'); $emit('close')">
                                Edit Profile
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'

const props = defineProps({
    userId:      { type: String, default: null },   // member ID to look up
    username:    { type: String, default: null },   // username to look up (used if userId unavailable)
    selfId:      { type: String, default: null },   // current user's ID
    centralUrl:  { type: String, default: '' },
    anchorX:     { type: Number, default: 200 },
    anchorY:     { type: Number, default: 200 },
})

const emit = defineEmits(['close', 'open-dm', 'friend-request', 'block', 'report', 'open-settings'])

const loading = ref(true)
const error   = ref(null)
const profile = ref(null)

const isSelf = computed(() => profile.value && props.selfId && profile.value.id === props.selfId)

const bannerStyle = computed(() => {
    if (profile.value?.banner_url) {
        return { backgroundImage: `url(${profile.value.banner_url})`, backgroundSize: 'cover', backgroundPosition: 'center' }
    }
    const accent = profile.value?.profile_accent || '#22d3ee'
    return { background: `linear-gradient(135deg, ${accent}33 0%, ${accent}11 100%)` }
})

const positionStyle = computed(() => {
    // Position near anchor, but keep within viewport
    const popW = 300
    const popH = 380
    let x = props.anchorX + 12
    let y = props.anchorY

    if (x + popW > window.innerWidth  - 12) x = props.anchorX - popW - 12
    if (y + popH > window.innerHeight - 12) y = window.innerHeight - popH - 12
    if (y < 12) y = 12

    return { left: x + 'px', top: y + 'px' }
})

onMounted(async () => {
    await fetchProfile()
})

async function fetchProfile() {
    loading.value = true
    error.value   = null
    profile.value = null

    const lookupKey = props.username
    if (!lookupKey) {
        error.value = 'No user specified.'
        loading.value = false
        return
    }

    try {
        const token = localStorage.getItem('eluth_token') ?? ''
        const res   = await fetch(`${props.centralUrl}/api/profile/${encodeURIComponent(lookupKey)}`, {
            headers: { Authorization: 'Bearer ' + token },
        })
        if (!res.ok) {
            error.value = res.status === 404 ? 'User not found.' : `Error ${res.status}`
            return
        }
        profile.value = await res.json()
    } catch (e) {
        error.value = 'Could not load profile.'
    } finally {
        loading.value = false
    }
}

function initials(name = '') {
    return name.slice(0, 2).toUpperCase()
}

// ── Social actions ──────────────────────────────────────────────────────────

const friendBusy = ref(false)

async function friendApi(method, path) {
    const token = localStorage.getItem('eluth_token') ?? ''
    const res = await fetch(props.centralUrl + '/api' + path, {
        method,
        headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
    })
    if (!res.ok) throw new Error(await res.text())
    return res.status === 204 ? null : res.json()
}

async function sendFriendRequest() {
    if (friendBusy.value) return
    friendBusy.value = true
    try {
        await friendApi('POST', '/friends/request/' + profile.value.id)
        profile.value = { ...profile.value, friendship_status: 'pending_sent' }
        emit('friend-request', { userId: profile.value.id, action: 'add' })
    } catch { /* silent */ } finally {
        friendBusy.value = false
    }
}

async function acceptFriendRequest() {
    if (friendBusy.value) return
    friendBusy.value = true
    try {
        await friendApi('POST', '/friends/accept/' + profile.value.id)
        profile.value = { ...profile.value, friendship_status: 'friends' }
        emit('friend-request', { userId: profile.value.id, action: 'accept' })
    } catch { /* silent */ } finally {
        friendBusy.value = false
    }
}

async function removeFriend() {
    if (friendBusy.value) return
    friendBusy.value = true
    try {
        await friendApi('DELETE', '/friends/' + profile.value.id)
        profile.value = { ...profile.value, friendship_status: null }
        emit('friend-request', { userId: profile.value.id, action: 'remove' })
    } catch { /* silent */ } finally {
        friendBusy.value = false
    }
}

async function blockUser() {
    try {
        await friendApi('POST', '/friends/block/' + profile.value.id)
        profile.value = { ...profile.value, friendship_status: 'blocked' }
        emit('block', { userId: profile.value.id, blocked: true })
    } catch { /* silent */ }
}

async function unblockUser() {
    try {
        await friendApi('DELETE', '/friends/block/' + profile.value.id)
        profile.value = { ...profile.value, friendship_status: null }
        emit('block', { userId: profile.value.id, blocked: false })
    } catch { /* silent */ }
}
</script>
