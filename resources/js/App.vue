<template>
    <div>
        <VideoBackground v-if="theme.backgroundType === 'video'" :src="theme.backgroundValue" />

        <!-- Auth gate: not logged in -->
        <div v-if="!authenticated" id="auth-gate">
            <div class="auth-card">
                <img :src="'/images/logo-full.svg'" alt="Eluth" class="auth-logo-img" />

                <template v-if="authState === 'checking'">
                    <div class="auth-subtitle" style="text-align:center;color:#64748b;">Connecting…</div>
                </template>

                <template v-else>
                    <div class="auth-subtitle">Sign in to join {{ serverName || 'this server' }}</div>
                    <button class="auth-submit auth-submit--eluth" @click="openLoginPopup">
                        Sign in with Eluth
                    </button>
                    <div v-if="authError" class="auth-error">{{ authError }}</div>
                    <div class="auth-register-link">
                        No account? <a :href="centralUrl + '/register'" target="_blank">Create one at eluth.io</a>
                    </div>
                </template>
            </div>
        </div>

        <!-- Not a member -->
        <div v-else-if="memberStatus === 'not_a_member'" id="auth-gate">
            <div class="auth-card">
                <img :src="'/images/logo-full.svg'" alt="Eluth" class="auth-logo-img" />
                <div class="server-gate-name">{{ serverName }}</div>
                <div class="server-gate-msg">You are not a member of this community server.</div>
                <div class="server-gate-actions">
                    <button class="auth-submit" @click="requestJoin" :disabled="joining">
                        {{ joining ? 'Sending request…' : (joinMode === 'request' ? 'Request to Join' : 'Join Server') }}
                    </button>
                    <a class="auth-submit auth-submit--outline" :href="centralUrl">Return to Eluth.io</a>
                </div>
                <div v-if="joinError" class="auth-error">{{ joinError }}</div>
                <div class="auth-totp-hint" style="margin-top:4px;">Signed in as <strong>{{ currentUser.username }}</strong> · <button class="auth-back" @click="signOut">Sign out</button></div>
            </div>
        </div>

        <!-- Membership pending -->
        <div v-else-if="memberStatus === 'pending'" id="auth-gate">
            <div class="auth-card">
                <img :src="'/images/logo-full.svg'" alt="Eluth" class="auth-logo-img" />
                <div class="server-gate-name">{{ serverName }}</div>
                <div class="pending-message">
                    <div class="pending-icon">⏳</div>
                    <p>Your request to join is waiting for approval from an administrator.</p>
                    <p>You'll be notified once it's approved.</p>
                </div>
                <a class="auth-submit auth-submit--outline" :href="centralUrl">Return to Eluth.io</a>
                <div class="auth-totp-hint" style="margin-top:4px;">Signed in as <strong>{{ currentUser.username }}</strong> · <button class="auth-back" @click="signOut">Sign out</button></div>
            </div>
        </div>

        <!-- Banned -->
        <div v-else-if="memberStatus === 'banned'" id="auth-gate">
            <div class="auth-card">
                <img :src="'/images/logo-full.svg'" alt="Eluth" class="auth-logo-img" />
                <div class="server-gate-name">{{ serverName }}</div>
                <div class="auth-error" style="text-align:center;margin-top:4px;">You have been banned from this server.</div>
                <a class="auth-submit auth-submit--outline" :href="centralUrl">Return to Eluth.io</a>
                <div class="auth-totp-hint" style="margin-top:4px;"><button class="auth-back" @click="signOut">Sign out</button></div>
            </div>
        </div>

        <!-- Main app -->
        <div v-else id="app-shell">
            <div id="topbar">
                <span id="topbar-name">{{ activeChannel?.name ?? '…' }}</span>
                <div id="topbar-divider" />
                <span id="topbar-topic">{{ activeChannel?.topic || 'No topic set' }}</span>
                <div style="display:flex;gap:4px;margin-left:auto;align-items:center;">
                    <!-- Admin: pending requests badge -->
                    <button
                        v-if="currentMember?.isAdmin && joinRequests.length > 0"
                        class="topbar-btn topbar-btn--alert"
                        title="Pending join requests"
                        @click="showJoinRequests = !showJoinRequests"
                    >{{ joinRequests.length }} pending</button>
                    <button class="topbar-btn" :class="{ active: showMembers }" title="Toggle members" @click="showMembers = !showMembers">👥</button>
                    <button class="topbar-btn" :class="{ active: showDMs }" title="Direct Messages" @click="toggleDMs">
                        ✉<span v-if="dmUnread > 0" class="topbar-badge">{{ dmUnread > 9 ? '9+' : dmUnread }}</span>
                    </button>
                    <button class="topbar-btn" :class="{ active: showFriends }" title="Friends" @click="showFriends = !showFriends; showDMs = false">♡</button>
                    <button class="topbar-btn" title="Sign out" @click="signOut">⏻</button>
                </div>
            </div>

            <!-- Normal server view -->
            <template v-if="!showDMs">
                <ChannelSidebar
                    :servers="servers"
                    :active-server-id="activeServerId"
                    :sections="sections"
                    :active-channel-id="activeChannelId"
                    :user="currentUser"
                    :unread-by-channel="unreadByChannel"
                    @select-server="selectServer"
                    @select-channel="selectChannel"
                    @open-settings="showSettings = true"
                    @open-user-settings="showUserSettings = true"
                    @view-profile="openProfilePopover"
                />

                <div id="content">
                    <!-- Admin join requests panel -->
                    <div v-if="showJoinRequests && currentMember?.isAdmin" class="join-requests-panel">
                        <div class="join-requests-header">
                            <span>Pending join requests</span>
                            <button class="join-close" @click="showJoinRequests = false">✕</button>
                        </div>
                        <div v-if="joinRequests.length === 0" class="join-empty">No pending requests.</div>
                        <div v-for="req in joinRequests" :key="req.central_user_id" class="join-request-row">
                            <span class="join-username">{{ req.username }}</span>
                            <div class="join-actions">
                                <button class="join-btn join-btn--approve" @click="approveRequest(req.central_user_id)">Approve</button>
                                <button class="join-btn join-btn--deny" @click="denyRequest(req.central_user_id)">Deny</button>
                            </div>
                        </div>
                    </div>

                    <MessagePane
                        :channel-name="activeChannel?.name ?? ''"
                        :channel-topic="activeChannel?.topic"
                        :messages="messages"
                        :members="members"
                        :current-member="currentMemberProxy"
                        :channel="activeChannel"
                        :can-stream="currentMemberProxy?.can('stream') ?? false"
                        api-base=""
                        :auth-token="authToken"
                        :enabled-plugins="enabledPlugins"
                        :plugin-settings="pluginSettings"
                        :custom-emotes="customEmotes"
                        @send="sendMessage"
                        @kick="kickMember"
                        @ban="banMember"
                        @open-dm="startDmWith"
                        @open-user-settings="showUserSettings = true"
                        @view-profile="openProfilePopover"
                    />
                    <MemberSidebar
                        v-if="showMembers"
                        :members="members"
                        :current-member="currentMemberProxy"
                        :channel-name="activeChannel?.name ?? null"
                        @kick="kickMember"
                        @ban="banMember"
                        @view-profile="openProfilePopover"
                    />
                    <!-- FriendsPanel is rendered via Teleport below -->
                </div>
            </template>

            <!-- Full-screen DM view — spans both grid columns -->
            <DirectMessagesView
                v-else
                :central-url="centralUrl"
                :token="authToken"
                :central-token="authToken"
                :current-username="currentUser.username"
                :current-user-id="currentUser.id"
                :open-with="dmOpenWith"
                :open-with-conv-id="dmOpenWithConvId"
                :central-echo="centralEcho"
                :active-call="activeCall"
                :local-name="currentUser.username"
                @close="showDMs = false; dmOpenWith = null; dmOpenWithConvId = null"
                @new-dm="onNewDm"
                @start-call="handleStartCall"
                @call-ended="onCallEnded"
            />
        </div>

        <!-- Welcome modal -->
        <Teleport to="body">
            <WelcomeModal
                v-if="showWelcomeModal"
                :server-name="serverName"
                :logo="theme.logo"
                :message="serverWelcome.message"
                :rules-enabled="serverRules.enabled"
                :require-rules-ack="serverWelcome.requireRulesAck"
                @dismiss="dismissWelcome"
                @view-rules="showRulesModal = true"
            />
        </Teleport>

        <!-- Rules modal -->
        <Teleport to="body">
            <RulesModal
                v-if="showRulesModal"
                :rules="serverRules.content"
                @close="showRulesModal = false"
            />
        </Teleport>

        <!-- User settings overlay -->
        <Teleport to="body">
            <UserSettings
                v-if="showUserSettings"
                :username="currentUser.username"
                :user-id="currentUser.id"
                :central-url="centralUrl"
                @close="showUserSettings = false"
                @avatar-updated="url => { currentUser.avatar_url = url }"
            />
        </Teleport>

        <!-- Friends panel slide-in -->
        <Teleport to="body">
            <div v-if="showFriends" class="side-panel-backdrop" @click="showFriends = false">
                <FriendsPanel
                    :central-url="centralUrl"
                    :token="authToken"
                    :current-username="currentUser?.username ?? ''"
                    @close="showFriends = false"
                    @open-dm="u => { startDmWith(u); showFriends = false }"
                />
            </div>
        </Teleport>

        <!-- Profile popover -->
        <ProfilePopover
            v-if="profilePopover.visible"
            :username="profilePopover.username"
            :self-id="currentUser.id"
            :central-url="centralUrl"
            :anchor-x="profilePopover.anchorX"
            :anchor-y="profilePopover.anchorY"
            @close="profilePopover.visible = false"
            @open-dm="u => { startDmWith(u); profilePopover.visible = false }"
            @open-settings="showUserSettings = true; profilePopover.visible = false"
            @friend-request="handleFriendRequest"
            @block="handleBlock"
            @report="handleReport"
        />

        <!-- Server settings overlay (Teleport to body so it sits above everything) -->
        <Teleport to="body">
            <ServerSettings
                v-if="showSettings && memberStatus === 'member'"
                :server-name="serverName"
                :current-member="currentMemberProxy"
                :members="members"
                :theme="theme"
                @close="showSettings = false"
                @server-updated="onServerUpdated"
                @appearance-updated="applyTheme"
                @members-updated="loadMembers"
                @channels-updated="loadChannels"
            />
        </Teleport>

        <Teleport to="body">
            <div v-if="incomingCall && !activeCall" class="incoming-call-toast" @click.stop>
                <div class="incoming-call-info">
                    <span class="incoming-call-icon">📞</span>
                    <div>
                        <div class="incoming-call-name">{{ incomingCall.callerName }}</div>
                        <div class="incoming-call-label">Incoming call</div>
                    </div>
                </div>
                <div class="incoming-call-actions">
                    <button class="call-answer-btn" @click="answerCall">Answer</button>
                    <button class="call-decline-btn" @click="declineCall">Decline</button>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { gsap } from 'gsap'
import { createEcho } from './echo.js'
import { loadClientConfig, getConfig } from './clientConfig.js'
import { useApi, ApiError } from './composables/useApi.js'
import VideoBackground from './components/VideoBackground.vue'
import ChannelSidebar  from './components/ChannelSidebar.vue'
import MessagePane     from './components/MessagePane.vue'
import MemberSidebar   from './components/MemberSidebar.vue'
import ServerSettings      from './components/ServerSettings.vue'
import UserSettings        from './components/UserSettings.vue'
import WelcomeModal        from './components/WelcomeModal.vue'
import RulesModal          from './components/RulesModal.vue'
import FriendsPanel           from './components/FriendsPanel.vue'
import DirectMessagesView    from './components/DirectMessagesView.vue'
import ProfilePopover        from './components/ProfilePopover.vue'
import { createCentralEcho } from './centralEcho.js'

const { get, post } = useApi()

const centralUrl = ref('')

// ── Theme ─────────────────────────────────────────────────────────────────
const theme = ref({
    logo:            null,
    backgroundType:  'none',
    backgroundValue: null,
    primaryColor:    null,
    accentColor:     null,
})

function applyTheme(info) {
    theme.value.logo            = info.logo            ?? null
    theme.value.backgroundType  = info.background_type  ?? 'none'
    theme.value.backgroundValue = info.background_value ?? null
    theme.value.primaryColor    = info.primary_color    ?? null
    theme.value.accentColor     = info.accent_color     ?? null

    if (info.primary_color) document.documentElement.style.setProperty('--accent',       info.primary_color)
    if (info.accent_color)  document.documentElement.style.setProperty('--accent-hover',  info.accent_color)

    // Image/colour background on body
    if (info.background_type === 'color' && info.background_value) {
        document.body.style.background = info.background_value
    } else if (info.background_type === 'image' && info.background_value) {
        document.body.style.backgroundImage    = `url(${info.background_value})`
        document.body.style.backgroundSize     = 'cover'
        document.body.style.backgroundPosition = 'center'
    } else {
        document.body.style.background = ''
    }
}

// ── Welcome / Rules ────────────────────────────────────────────────────────
const serverWelcome     = ref({ enabled: false, message: '', requireRulesAck: false })
const serverRules       = ref({ enabled: false, content: '' })
const showWelcomeModal  = ref(false)
const showRulesModal    = ref(false)

function dismissWelcome() {
    showWelcomeModal.value = false
    post('/members/dismiss-welcome', {}).catch(() => {})
}

// ── Plugins ────────────────────────────────────────────────────────────────
const enabledPlugins    = ref([])   // array of slugs
const pluginSettings    = ref({})   // { slug: { key: value } }
const customEmotes      = ref([])

// ── Auth ───────────────────────────────────────────────────────────────────
const authenticated = ref(false)
const authToken     = ref('')
const memberStatus  = ref(null)   // null | 'pending' | 'banned' | 'member'
const currentUser   = ref({ id: '', username: '' })
const currentMember      = ref(null)
const currentMemberProxy = computed(() => currentMember.value ? {
    id:  currentUser.value.id,
    can: (perm) => currentMember.value.isSuperAdmin || currentMember.value.permissions?.includes(perm),
} : null)
const serverName    = ref('')
const joinMode      = ref('open')
const joining       = ref(false)
const joinError     = ref('')

// 'checking' | 'unauthenticated'  (authenticated is tracked separately)
const authState = ref('checking')
const authError = ref('')

const communityOrigin = window.location.origin

function buildAuthorizeUrl(state, prompt = '') {
    const params = new URLSearchParams({ redirect_uri: communityOrigin + '/auth/callback', state })
    if (prompt) params.set('prompt', prompt)
    return centralUrl.value + '/oauth/authorize?' + params.toString()
}

function randomState() {
    return Array.from(crypto.getRandomValues(new Uint8Array(16)))
        .map(b => b.toString(16).padStart(2, '0')).join('')
}


async function animateAppIn() {
    await nextTick()
    gsap.from('#topbar', { y: -10, opacity: 0, duration: 0.4, ease: 'power3.out', delay: 0.05 })
    gsap.from('#nav',    { x: -16, opacity: 0, duration: 0.45, delay: 0.10, ease: 'power3.out' })
    gsap.from('#main',   { opacity: 0,         duration: 0.45, delay: 0.18, ease: 'power3.out' })
}

let _refreshTimer = null

function scheduleRefresh(expUnix) {
    if (_refreshTimer) clearTimeout(_refreshTimer)
    const msUntilRefresh = Math.max(0, (expUnix - Math.floor(Date.now() / 1000) - 60) * 1000)
    _refreshTimer = setTimeout(tryRefresh, msUntilRefresh)
}

async function tryRefresh() {
    try {
        const res = await fetch(centralUrl.value + '/api/auth/refresh', {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
        })
        if (!res.ok) throw new Error('refresh failed')
        const data = await res.json()
        if (data.token) {
            const payload = JSON.parse(atob(data.token.split('.')[1]))
            localStorage.setItem('eluth_token', data.token)
            authToken.value = data.token
            scheduleRefresh(payload.exp)
        }
    } catch {
        // Fall back to silent OAuth check
        startSilentCheck()
    }
}

async function handleTokenReceived(token) {
    const payload = JSON.parse(atob(token.split('.')[1]))
    currentUser.value = { id: payload.sub, username: payload.username ?? 'User' }
    localStorage.setItem('eluth_token', token)
    authToken.value     = token
    authenticated.value = true
    centralEcho.value = createCentralEcho(token, (newEcho) => { centralEcho.value = newEcho })
    scheduleRefresh(payload.exp)
    requestNotificationPermission()
    await loadAll()
    animateAppIn()
}

function toggleDMs() {
    dmUnread.value = 0
    showDMs.value     = !showDMs.value
    showFriends.value = false
    if (showDMs.value) dmOpenWith.value = null
}

function startDmWith(user) {
    dmOpenWith.value  = user
    showDMs.value     = true
    showFriends.value = false
}

function openProfilePopover({ username, anchorX, anchorY }) {
    profilePopover.value = { visible: true, username, anchorX, anchorY }
}

function startSilentCheck() {
    const state   = randomState()
    const iframe  = document.createElement('iframe')
    iframe.src    = buildAuthorizeUrl(state, 'none')
    iframe.style.cssText = 'display:none;width:0;height:0;border:0;'
    document.body.appendChild(iframe)

    const cleanup = () => { iframe.parentNode?.removeChild(iframe) }

    const timer = setTimeout(() => {
        cleanup()
        authState.value = 'unauthenticated'
    }, 8000)

    const onMessage = async (event) => {
        if (event.origin !== centralUrl.value) return
        if (!event.data || event.data.state !== state) return

        clearTimeout(timer)
        cleanup()
        window.removeEventListener('message', onMessage)

        if (event.data.type === 'auth_token') {
            try {
                await handleTokenReceived(event.data.token)
            } catch {
                authState.value = 'unauthenticated'
            }
        } else {
            authState.value = 'unauthenticated'
        }
    }

    window.addEventListener('message', onMessage)
}

function openLoginPopup() {
    authError.value = ''
    const state = randomState()
    const url   = buildAuthorizeUrl(state)
    const w = 440, h = 640
    const left = Math.max(0, (screen.width  - w) / 2)
    const top  = Math.max(0, (screen.height - h) / 2)
    window.open(url, 'eluth-login', `width=${w},height=${h},left=${left},top=${top},resizable=no`)

    const onMessage = async (event) => {
        if (event.origin !== centralUrl.value) return
        if (!event.data || event.data.state !== state) return

        window.removeEventListener('message', onMessage)

        if (event.data.type === 'auth_token') {
            try {
                await handleTokenReceived(event.data.token)
            } catch {
                authError.value = 'Sign in failed. Please try again.'
            }
        }
    }

    window.addEventListener('message', onMessage)
}

async function signOut() {
    if (_refreshTimer) { clearTimeout(_refreshTimer); _refreshTimer = null }
    sessionStorage.removeItem('e2ee_private_key')
    stopHeartbeat()
    stopPolling()
    await post('/members/presence', { presence: 'offline' }).catch(() => {})
    localStorage.removeItem('eluth_token')
    authenticated.value = false
    memberStatus.value  = null
    authState.value     = 'unauthenticated'
    authError.value     = ''
    // Clear central SSO session so silent-check doesn't immediately re-log in
    fetch(centralUrl.value + '/oauth/logout', { credentials: 'include' }).catch(() => {})
}

async function requestJoin() {
    joining.value = true
    joinError.value = ''
    try {
        const result = await post('/join', {})
        memberStatus.value = result.status === 'member' ? 'member' : 'pending'
        if (result.status === 'member') await loadAll()
    } catch (e) {
        joinError.value = e.message ?? 'Failed to send request.'
    } finally {
        joining.value = false
    }
}

// ── Data ───────────────────────────────────────────────────────────────────
const servers         = ref([])
const sections        = ref([])
const activeServerId  = ref('local')
const activeChannelId = ref(localStorage.getItem('ui_active_channel') ?? null)
const messages        = ref([])
const members         = ref([])
const showMembers     = ref(localStorage.getItem('ui_show_members') === 'true')
const showSettings     = ref(false)
const showUserSettings = ref(false)
const profilePopover   = ref({ visible: false, username: null, anchorX: 200, anchorY: 200 })
const showFriends      = ref(false)
const showDMs          = ref(localStorage.getItem('ui_show_dms') === '1')
const dmOpenWith       = ref(null)
const dmOpenWithConvId = ref(null)
const dmUnread         = ref(0)
const unreadByChannel  = ref({})   // { [channelId]: { msgs: 0, mentions: 0 } }
const centralEcho      = ref(null)
const incomingCall     = ref(null)
const activeCall       = ref(null)
let   centralUserChannel = null
const joinRequests     = ref([])
const showJoinRequests = ref(false)

watch(showMembers,     v => localStorage.setItem('ui_show_members', v))
watch(showDMs,         v => localStorage.setItem('ui_show_dms', v ? '1' : '0'))
watch(activeChannelId, v => v ? localStorage.setItem('ui_active_channel', v) : null)
watch(centralEcho, (echo) => {
    if (echo) subscribeToCentralUserChannel(echo)
    else centralUserChannel = null
})

const activeChannel = computed(() => {
    const all = sections.value.flatMap(s => s.channels)
    return all.find(c => c.id === activeChannelId.value) ?? all[0] ?? null
})

// ── Presence heartbeat ─────────────────────────────────────────────────────
let heartbeatTimer = null

function startHeartbeat() {
    stopHeartbeat()
    post('/members/heartbeat', {}).catch(() => {})
    heartbeatTimer = setInterval(() => post('/members/heartbeat', {}).catch(() => {}), 30_000)
    document.addEventListener('visibilitychange', onVisibilityChange)
}

function stopHeartbeat() {
    clearInterval(heartbeatTimer)
    heartbeatTimer = null
    document.removeEventListener('visibilitychange', onVisibilityChange)
}

function onVisibilityChange() {
    if (!document.hidden) post('/members/heartbeat', {}).catch(() => {})
}

// ── Notifications ─────────────────────────────────────────────────────────
function requestNotificationPermission() {
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission()
    }
}

// ── Audio — Web Audio API (unlocked permanently after first user gesture) ────

let _audioCtx    = null
let _ringBuffer  = null
let _ringSource  = null
let _audioReady  = false

function getAudioCtx() {
    if (!_audioCtx) _audioCtx = new (window.AudioContext || window.webkitAudioContext)()
    return _audioCtx
}

// Fetch + decode ring mp3 into an AudioBuffer
async function loadRingBuffer() {
    try {
        const res = await fetch('/sounds/ring.mp3')
        const arr = await res.arrayBuffer()
        _ringBuffer = await getAudioCtx().decodeAudioData(arr)
    } catch { /* non-fatal */ }
}
loadRingBuffer()

// Resume the AudioContext on first user gesture — stays unlocked for the page lifetime
function unlockAudio() {
    const ctx = getAudioCtx()
    if (ctx.state === 'suspended') ctx.resume()
    _audioReady = true
    document.removeEventListener('click',   unlockAudio)
    document.removeEventListener('keydown', unlockAudio)
}
document.addEventListener('click',   unlockAudio)
document.addEventListener('keydown', unlockAudio)

function startRing() {
    if (!_ringBuffer) return
    stopRing()
    const ctx = getAudioCtx()
    if (ctx.state === 'suspended') ctx.resume()
    _ringSource = ctx.createBufferSource()
    _ringSource.buffer = _ringBuffer
    _ringSource.loop   = true
    const gain = ctx.createGain()
    gain.gain.value = 0.8
    _ringSource.connect(gain)
    gain.connect(ctx.destination)
    _ringSource.start()
}

function stopRing() {
    if (_ringSource) {
        try { _ringSource.stop() } catch { /* already stopped */ }
        _ringSource.disconnect()
        _ringSource = null
    }
}

function playSound(src) {
    try {
        const ctx = getAudioCtx()
        if (ctx.state === 'suspended') ctx.resume()
        fetch(src)
            .then(r => r.arrayBuffer())
            .then(arr => ctx.decodeAudioData(arr))
            .then(buf => {
                const gain = ctx.createGain()
                gain.gain.value = 0.7
                const source = ctx.createBufferSource()
                source.buffer = buf
                source.connect(gain)
                gain.connect(ctx.destination)
                source.start()
            })
            .catch(() => {})
    } catch { /* ignore */ }
}
function playPing(mention = false) {
    playSound('/sounds/notification.mp3')
}
function playError() {
    playSound('/sounds/error.mp3')
}

function sendBrowserNotification(title, body) {
    if ('Notification' in window && Notification.permission === 'granted' && document.hidden) {
        new Notification(title, { body, icon: '/images/logo-full.svg' })
    }
}

function isMentioned(content) {
    if (!content) return false
    const me = currentUser.value?.username
    if (/@(everyone|here)\b/i.test(content)) return true
    if (me && new RegExp('@' + me + '\\b', 'i').test(content)) return true
    return false
}

function onIncomingChannelMessage(channelId, data) {
    const isActive = channelId === activeChannelId.value
    const mentioned = isMentioned(data.content)

    if (!isActive) {
        const entry = unreadByChannel.value[channelId] ?? { msgs: 0, mentions: 0 }
        entry.msgs++
        if (mentioned) entry.mentions++
        unreadByChannel.value = { ...unreadByChannel.value, [channelId]: entry }
    }

    if (mentioned && data.author !== currentUser.value?.username) {
        playPing(true)
        const ch = sections.value.flatMap(s => s.channels).find(c => c.id === channelId)
        sendBrowserNotification(
            `${data.author} mentioned you${ch ? ' in #' + ch.name : ''}`,
            data.content.slice(0, 100),
        )
    } else if (!isActive) {
        playPing(false)
    }
}

function onNewDm({ author, content }) {
    if (!showDMs.value) dmUnread.value++
    playPing(true)
    sendBrowserNotification('New message from ' + author, content.slice(0, 100))
}

// ── WebSocket ──────────────────────────────────────────────────────────────
let echoChannel      = null
let serverEchoChannel = null

function subscribeToServerChannel() {
    if (serverEchoChannel) return
    try {
        serverEchoChannel = window._echo.channel('server')
            .listen('.channel.live.started', ({ channel_id, streamer_username }) => {
                // Update the sections data so the LIVE badge appears immediately
                sections.value = sections.value.map(section => ({
                    ...section,
                    channels: section.channels.map(ch =>
                        ch.id === channel_id
                            ? { ...ch, is_live: true, live_streamer_username: streamer_username }
                            : ch
                    ),
                }))
            })
            .listen('.channel.live.ended', ({ channel_id }) => {
                sections.value = sections.value.map(section => ({
                    ...section,
                    channels: section.channels.map(ch =>
                        ch.id === channel_id
                            ? { ...ch, is_live: false, live_streamer_username: null }
                            : ch
                    ),
                }))
            })
    } catch { /* ignore */ }
}

function subscribeToChannel(channelId) {
    if (echoChannel) echoChannel.stopListening('.message.sent')
    echoChannel = window._echo.channel('channel.' + channelId)
    echoChannel.listen('.message.sent', (data) => {
        if (!messages.value.some(m => m.id === data.id)) {
            messages.value.push({ id: data.id, author: data.author, content: data.content, at: data.at })
            onIncomingChannelMessage(channelId, data)
        }
    })
}

function stopPolling() { /* no-op — polling removed */ }

// ── API ────────────────────────────────────────────────────────────────────
async function loadAll() {
    // Always fetch server info — it's public, gives us name, join mode, and theme
    const info = await get('/server').catch(() => null)
    if (info?.name)      serverName.value = info.name
    if (info?.join_mode) joinMode.value   = info.join_mode
    servers.value = [{ id: 'local', name: info?.name ?? 'Community Server', icon: null }]

    if (info) {
        applyTheme(info)
        serverWelcome.value = {
            enabled:        info.welcome_enabled,
            message:        info.welcome_message ?? '',
            requireRulesAck: info.require_rules_ack,
        }
        serverRules.value = {
            enabled: info.rules_enabled,
            content: info.rules ?? '',
        }
    }

    try {
        await loadChannels()
        await loadMembers()
        await loadPlugins()
        if (currentMember.value?.isAdmin) await loadJoinRequests()
        startHeartbeat()
    } catch (e) {
        if (e instanceof ApiError) {
            if (e.code === 'not_a_member')       { memberStatus.value = 'not_a_member'; return }
            if (e.code === 'membership_pending') { memberStatus.value = 'pending';      return }
            if (e.code === 'membership_banned')  { memberStatus.value = 'banned';       return }
            if (e.code === 'token_expired')      { signOut(); return }
        }
        console.error('Failed to load server data:', e)
    }
}

async function loadChannels() {
    const data = await get('/channels')
    sections.value = data.sections
    memberStatus.value = 'member'

    const all = sections.value.flatMap(s => s.channels)

    // If no stored channel (or stored channel no longer exists), pick the first text channel
    if (!activeChannelId.value || !all.find(c => c.id === activeChannelId.value)) {
        const first = all.find(c => c.type === 'text' || c.type === 'announcement')
        if (first) activeChannelId.value = first.id
    }

    // Always load messages for the active channel (covers both first load and page refresh)
    if (activeChannelId.value) {
        await loadMessages(activeChannelId.value)
        subscribeToChannel(activeChannelId.value)
    }

    subscribeToServerChannel()
}

async function loadMembers() {
    const channelId = activeChannelId.value
    const data = await get('/members' + (channelId ? '?channel_id=' + channelId : ''))
    members.value = data.members

    const me = data.members.find(m => m.id === currentUser.value.id)
    if (me) {
        const wasWelcomed = !!currentMember.value?.hasBeenWelcomed
        currentUser.value = { ...currentUser.value, avatar_url: me.avatar_url ?? null }
        currentMember.value = {
            isSuperAdmin:    me.is_super_admin ?? false,
            permissions:     me.permissions   ?? [],
            isAdmin:         me.is_super_admin || (me.permissions ?? []).length > 0,
            hasBeenWelcomed: !!me.welcomed_at,
        }
        // Show welcome modal on first load if not yet welcomed
        if (!wasWelcomed && !me.welcomed_at && serverWelcome.value.enabled) {
            showWelcomeModal.value = true
        }
    }
}

async function kickMember(userId) {
    await post('/admin/members/' + userId + '/kick', {})
    members.value = members.value.filter(m => m.id !== userId)
}

async function banMember(userId) {
    await post('/admin/members/' + userId + '/ban', {})
    members.value = members.value.filter(m => m.id !== userId)
}

async function loadJoinRequests() {
    const data = await get('/admin/join-requests').catch(() => ({ requests: [] }))
    joinRequests.value = data.requests
}

async function loadMessages(channelId) {
    const data = await get('/channels/' + channelId + '/messages')
    messages.value = data.messages
}

async function loadPlugins() {
    try {
        const data = await get('/plugins')
        enabledPlugins.value = (data.plugins ?? [])
            .filter(p => p.is_enabled)
            .map(p => p.slug)
        // Build settings map: { 'gif-picker': { tenor_key: '...', giphy_key: '...' } }
        const settings = {}
        for (const p of (data.plugins ?? [])) {
            if (p.manifest?.settings) {
                settings[p.slug] = {}
                for (const s of p.manifest.settings) {
                    settings[p.slug][s.key] = s.value ?? ''
                }
            }
        }
        pluginSettings.value = settings

        // Load custom emotes if emoticon plugin is enabled
        if (enabledPlugins.value.includes('emoticon-picker')) {
            try {
                const emoteData = await get('/plugins/emoticons/emotes')
                customEmotes.value = emoteData.emotes ?? []
            } catch { customEmotes.value = [] }
        } else {
            customEmotes.value = []
        }
    } catch { /* non-critical */ }
}

async function selectChannel(channel) {
    activeChannelId.value = channel.id
    messages.value = []
    // Clear unread for this channel
    if (unreadByChannel.value[channel.id]) {
        const { [channel.id]: _, ...rest } = unreadByChannel.value
        unreadByChannel.value = rest
    }
    stopPolling()
    await Promise.all([
        loadMessages(channel.id),
        loadMembers(),
    ])
    subscribeToChannel(channel.id)
}

function selectServer(id) { activeServerId.value = id }

async function sendMessage({ content, replyToId }) {
    if (!activeChannelId.value) return
    const tempId = 'temp-' + Date.now()
    messages.value.push({ id: tempId, author: currentUser.value.username, content, at: new Date().toISOString(), reply_to_id: replyToId ?? null })
    try {
        const body = { content }
        if (replyToId) body.reply_to_id = replyToId
        const saved = await post('/channels/' + activeChannelId.value + '/messages', body)
        const idx = messages.value.findIndex(m => m.id === tempId)
        if (idx !== -1) messages.value[idx] = {
            id:               saved.id,
            author:           saved.author,
            content:          saved.content,
            at:               saved.at,
            reply_to_id:      saved.reply_to_id,
            reply_to_author:  saved.reply_to_author,
            reply_to_preview: saved.reply_to_preview,
        }
    } catch {
        messages.value = messages.value.filter(m => m.id !== tempId)
    }
}

async function approveRequest(userId) {
    await post('/admin/join-requests/' + userId + '/approve', {})
    joinRequests.value = joinRequests.value.filter(r => r.central_user_id !== userId)
    await loadMembers()
}

async function denyRequest(userId) {
    await post('/admin/join-requests/' + userId + '/deny', {})
    joinRequests.value = joinRequests.value.filter(r => r.central_user_id !== userId)
}

function onServerUpdated(name) {
    serverName.value = name
    if (servers.value[0]) servers.value[0].name = name
}

// ── Central API helper ─────────────────────────────────────────────────────
async function centralApi(method, path, body) {
    const res = await fetch(centralUrl.value + '/api' + path, {
        method,
        headers: {
            Authorization: 'Bearer ' + authToken.value,
            Accept: 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    })
    if (!res.ok) throw new Error(await res.text())
    if (res.status === 204) return null
    return res.json()
}

// ── Social event handlers ──────────────────────────────────────────────────
async function handleFriendRequest({ userId, action }) {
    const endpoints = {
        add:    ['POST',   '/friends/request/' + userId],
        accept: ['POST',   '/friends/accept/'  + userId],
        remove: ['DELETE', '/friends/'          + userId],
    }
    const [method, path] = endpoints[action] ?? []
    if (!method) return
    try {
        await centralApi(method, path)
    } catch { /* silent */ }
}

async function handleBlock({ userId, blocked }) {
    try {
        if (blocked) {
            await centralApi('POST', '/friends/block/' + userId)
        } else {
            await centralApi('DELETE', '/friends/block/' + userId)
        }
    } catch { /* silent */ }
}

async function handleReport({ userId }) {
    const reason = prompt('Reason for report (harassment, spam, other):')
    if (!reason) return
    try {
        await centralApi('POST', '/report', { reported_id: userId, reason })
    } catch { /* silent */ }
}

// ── Calls ──────────────────────────────────────────────────────────────────

function subscribeToCentralUserChannel(echo) {
    if (!echo || centralUserChannel) return
    try {
        const userId = currentUser.value.id
        if (!userId) return
        centralUserChannel = window._echo.channel('user.' + userId)
            .listen('.incoming-call', data => {
                incomingCall.value = { callerName: data.caller_name, callerId: data.caller_id, convId: data.conv_id, offer: data.offer, video: data.video ?? false }
                startRing()
                sendBrowserNotification('Incoming call', data.caller_name + ' is calling you')
            })
            .listen('.call-ended', () => {
                stopRing()
                activeCall.value = null
                incomingCall.value = null
            })
    } catch { /* ignore */ }
}

function handleStartCall({ convId, remoteName, video }) {
    activeCall.value = { convId, remoteName, video, isCaller: true, remoteOffer: null }
}

function answerCall() {
    if (!incomingCall.value) return
    stopRing()
    const { convId, callerName, offer, video } = incomingCall.value
    incomingCall.value = null
    activeCall.value = { convId, remoteName: callerName, video: video ?? false, isCaller: false, remoteOffer: offer }
    dmOpenWith.value = null
    dmOpenWithConvId.value = convId
    showDMs.value = true
}

async function declineCall() {
    stopRing()
    const convId = incomingCall.value?.convId
    incomingCall.value = null
    if (convId) {
        try { await centralApi('POST', `/dm/conversations/${convId}/call/signal`, { type: 'hangup', data: 'end' }) } catch { /* ignore */ }
    }
}

async function onCallEnded() {
    if (activeCall.value) {
        try { await centralApi('POST', '/dm/conversations/' + activeCall.value.convId + '/call/end', {}) } catch { /* ignore */ }
    }
    activeCall.value = null
}

// ── Init ───────────────────────────────────────────────────────────────────
onMounted(async () => {
    await loadClientConfig()
    centralUrl.value = getConfig().centralUrl ?? ''
    window._echo = createEcho()

    const stored = localStorage.getItem('eluth_token')
    if (stored) {
        try {
            const payload = JSON.parse(atob(stored.split('.')[1]))
            const now = Math.floor(Date.now() / 1000)
            if (payload.exp && payload.exp > now) {
                currentUser.value   = { id: payload.sub, username: payload.username ?? 'User' }
                jwtFeatures.value   = Array.isArray(payload.features) ? payload.features : []
                authToken.value     = stored
                authenticated.value = true
                centralEcho.value = createCentralEcho(stored, (newEcho) => { centralEcho.value = newEcho })
                scheduleRefresh(payload.exp)
                requestNotificationPermission()
                await loadAll()
                animateAppIn()
            } else {
                localStorage.removeItem('eluth_token')
                startSilentCheck()
            }
        } catch {
            localStorage.removeItem('eluth_token')
            startSilentCheck()
        }
    } else {
        startSilentCheck()
    }
})
</script>

<style>
/* Incoming call toast — global (not scoped) because it's Teleported to <body> from App.vue */
.incoming-call-toast {
    position: fixed;
    bottom: 24px; right: 24px;
    background: #0d1117;
    border: 1px solid rgba(34,211,238,0.3);
    border-radius: 12px;
    padding: 16px 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    z-index: 900;
    box-shadow: 0 8px 32px rgba(0,0,0,0.4);
    min-width: 240px;
    animation: toast-in 0.2s ease;
}

@keyframes toast-in {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}

.incoming-call-info  { display: flex; align-items: center; gap: 12px; }
.incoming-call-icon  { font-size: 24px; animation: ring 1s infinite; }

@keyframes ring {
    0%, 100% { transform: rotate(0deg); }
    20%       { transform: rotate(-15deg); }
    40%       { transform: rotate(15deg); }
    60%       { transform: rotate(-10deg); }
    80%       { transform: rotate(10deg); }
}

.incoming-call-name  { font-weight: 600; font-size: 14px; }
.incoming-call-label { font-size: 12px; color: rgba(255,255,255,0.45); }
.incoming-call-actions { display: flex; gap: 8px; }

.call-answer-btn, .call-decline-btn {
    flex: 1; padding: 8px 0;
    border: none; border-radius: 8px;
    font-size: 13px; font-weight: 600;
    cursor: pointer; transition: opacity 0.15s;
}
.call-answer-btn  { background: #22d3ee; color: #050810; }
.call-decline-btn { background: rgba(248,113,113,0.2); color: #f87171; }
.call-answer-btn:hover, .call-decline-btn:hover { opacity: 0.85; }
</style>
