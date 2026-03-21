<template>
    <div class="dm-view">

        <!-- Left sidebar: conversation list -->
        <aside class="dm-view-sidebar">
            <div class="dm-view-sidebar-header">
                <span class="dm-view-sidebar-title">Direct Messages</span>
                <button class="dm-view-close" @click="emit('close')" title="Back to server">✕</button>
            </div>

            <div class="dm-view-search-wrap">
                <input
                    class="dm-view-search"
                    type="text"
                    placeholder="Find or start a conversation…"
                    v-model="search"
                />
            </div>

            <div class="dm-view-conv-list">
                <div
                    v-for="c in filteredConversations"
                    :key="c.id"
                    class="dm-view-conv-item"
                    :class="{ active: activeConv?.id === c.id }"
                    @click="openConversation(c)"
                >
                    <div class="dm-view-conv-avatar">
                        <img v-if="c.participant.avatar_url" :src="c.participant.avatar_url" :alt="c.participant.username" @error="e => e.target.style.display='none'" />
                        <span v-else>{{ initials(c.participant.username) }}</span>
                    </div>
                    <div class="dm-view-conv-info">
                        <span class="dm-view-conv-name">
                            {{ c.participant.username }}
                            <span v-if="c.encrypted" class="dm-conv-lock" title="End-to-end encrypted">🔒</span>
                        </span>
                        <span class="dm-view-conv-preview">{{ previewText(c) }}</span>
                    </div>
                </div>

                <div v-if="filteredConversations.length === 0" class="dm-view-empty">
                    {{ search ? 'No conversations match.' : 'No conversations yet.\nRight-click a user to start one.' }}
                </div>
            </div>
        </aside>

        <!-- Right area -->
        <div class="dm-view-thread">

            <!-- No conversation selected -->
            <div v-if="!activeConv" class="dm-view-placeholder">
                <div class="dm-view-placeholder-icon">✉</div>
                <div class="dm-view-placeholder-title">Your Direct Messages</div>
                <div class="dm-view-placeholder-hint">Select a conversation or right-click a user to start one.</div>
            </div>

            <!-- Active conversation -->
            <template v-else>
                <!-- Call panel -->
                <VoiceCall
                    v-if="activeCall && activeCall.convId === activeConv.id && centralToken && centralEcho"
                    :conv-id="activeCall.convId"
                    :central-url="centralUrl"
                    :central-token="centralToken"
                    :central-echo="centralEcho"
                    :local-name="localName"
                    :remote-name="activeCall.remoteName"
                    :video-call="activeCall.video"
                    :is-caller="activeCall.isCaller"
                    :remote-offer="activeCall.remoteOffer ?? null"
                    @ended="emit('call-ended')"
                />

                <div class="dm-view-thread-topbar">
                    <div class="dm-view-thread-avatar">
                        <img v-if="activeConv.participant.avatar_url" :src="activeConv.participant.avatar_url" @error="e => e.target.style.display='none'" />
                        <span v-else>{{ initials(activeConv.participant.username) }}</span>
                    </div>
                    <span class="dm-view-thread-name">{{ activeConv.participant.username }}</span>
                    <div class="dm-view-thread-actions">
                        <button class="dm-action-btn" title="Voice call" @click="emit('start-call', { convId: activeConv.id, remoteName: activeConv.participant.username, video: false })">📞</button>
                        <button class="dm-action-btn" title="Video call" @click="emit('start-call', { convId: activeConv.id, remoteName: activeConv.participant.username, video: true })">📹</button>
                    </div>
                </div>

                <!-- Unencrypted warning banner -->
                <div v-if="!activeConv.encrypted" class="dm-banner dm-banner--warn">
                    ⚠️ <strong>This conversation is not encrypted.</strong>
                    Messages are protected in transit but could be obtained by authorities under a court order or warrant.
                </div>

                <!-- Encrypted: unlock prompt (if not yet unlocked) -->
                <div v-else-if="activeConv.encrypted && !e2eeReady" class="dm-banner dm-banner--lock">
                    <div class="dm-unlock-row">
                        <span>🔒 <strong>End-to-end encrypted.</strong> Enter your Eluth password to read and send messages.</span>
                        <div class="dm-unlock-fields">
                            <input
                                class="dm-e2ee-passphrase"
                                type="password"
                                placeholder="Your Eluth password"
                                v-model="unlockPassphrase"
                                @keydown.enter.prevent="unlockE2ee"
                            />
                            <button class="dm-e2ee-unlock-btn" :disabled="unlocking" @click="unlockE2ee">
                                {{ unlocking ? '…' : 'Unlock' }}
                            </button>
                        </div>
                        <div v-if="unlockError" class="dm-e2ee-error">{{ unlockError }}</div>
                        <div class="dm-unlock-hint">
                            For a seamless experience, <a href="https://eluth.io/download" target="_blank" class="dm-unlock-link">download the Eluth app</a> — it unlocks automatically.
                        </div>
                    </div>
                </div>

                <!-- Encrypted: ready banner -->
                <div v-else-if="activeConv.encrypted && e2eeReady" class="dm-banner dm-banner--ok">
                    🔒 End-to-end encrypted — only you and {{ activeConv.participant.username }} can read these messages.
                </div>

                <!-- Messages (shown even if locked — messages show as ciphertext until unlocked) -->
                <div class="dm-view-messages" ref="messagesEl">
                    <div
                        v-for="msg in messages"
                        :key="msg.id"
                        class="dm-msg"
                        :class="{ 'dm-msg--mine': msg.author === currentUsername }"
                    >
                        <div v-if="msg.author !== currentUsername" class="dm-msg-author">{{ msg.author }}</div>
                        <div class="dm-msg-bubble" :class="{ 'dm-msg-bubble--locked': msg.encrypted && !msg._plaintext }">
                            {{ msg.encrypted ? (msg._plaintext ?? '🔒 Encrypted message — unlock to read') : msg.content }}
                        </div>
                        <div class="dm-msg-time">{{ formatTime(msg.at) }}</div>
                    </div>
                </div>

                <!-- Input (disabled if encrypted but not unlocked) -->
                <div class="dm-view-input-bar" :class="{ 'dm-view-input-bar--locked': activeConv.encrypted && !e2eeReady }">
                    <textarea
                        class="dm-view-input"
                        rows="1"
                        :placeholder="activeConv.encrypted && !e2eeReady ? 'Unlock to send messages…' : `Message ${activeConv.participant.username}`"
                        :disabled="activeConv.encrypted && !e2eeReady"
                        v-model="draft"
                        @keydown.enter.exact.prevent="sendMessage"
                        @input="autoResize"
                        ref="inputEl"
                    />
                </div>
            </template>
        </div>
    </div>

    <!-- New conversation: encryption choice modal -->
    <Teleport to="body">
        <div v-if="showEncryptionChoice" class="dm-choice-backdrop" @click.self="showEncryptionChoice = false">
            <div class="dm-choice-modal">
                <div class="dm-choice-title">Start a conversation with {{ pendingUser?.username }}</div>
                <div class="dm-choice-subtitle">Choose how this conversation will work:</div>

                <button class="dm-choice-btn dm-choice-btn--encrypted" @click="createConversation(true)">
                    <div class="dm-choice-btn-icon">🔒</div>
                    <div class="dm-choice-btn-body">
                        <div class="dm-choice-btn-label">Encrypted</div>
                        <div class="dm-choice-btn-desc">
                            End-to-end encrypted — only you and {{ pendingUser?.username }} can ever read these messages.
                            On the web, you'll need to enter your password each session.
                            <strong>For automatic unlock, <a href="https://eluth.io/download" target="_blank" class="dm-choice-link">download the Eluth app</a>.</strong>
                        </div>
                    </div>
                </button>

                <button class="dm-choice-btn dm-choice-btn--plain" @click="createConversation(false)">
                    <div class="dm-choice-btn-icon">💬</div>
                    <div class="dm-choice-btn-body">
                        <div class="dm-choice-btn-label">Standard</div>
                        <div class="dm-choice-btn-desc">
                            Not end-to-end encrypted. Messages are secured in transit, but could be obtained by
                            law enforcement under a court order or warrant.
                        </div>
                    </div>
                </button>

                <button class="dm-choice-cancel" @click="showEncryptionChoice = false">Cancel</button>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import VoiceCall from './VoiceCall.vue'
import { useE2ee } from '../composables/useE2ee.js'

const props = defineProps({
    centralUrl:      { type: String, required: true },
    token:           { type: String, required: true },
    currentUsername: { type: String, default: '' },
    currentUserId:   { type: String, default: '' },
    openWith:        { type: Object, default: null },
    openWithConvId:  { type: String, default: null },
    centralEcho:     { type: Object, default: null },
    activeCall:      { type: Object, default: null },
    centralToken:    { type: String, default: null },
    localName:       { type: String, default: 'You' },
})
const emit = defineEmits(['close', 'new-dm', 'start-call', 'call-ended'])

const conversations       = ref([])
const activeConv          = ref(null)
const messages            = ref([])
const draft               = ref('')
const search              = ref('')
const messagesEl          = ref(null)
const inputEl             = ref(null)
const showEncryptionChoice = ref(false)
const pendingUser          = ref(null)

// ── E2EE ──────────────────────────────────────────────────────────────────
const e2ee             = useE2ee()
const e2eeReady        = ref(false)
const unlockPassphrase = ref('')
const unlockError      = ref('')
const unlocking        = ref(false)

// Try to restore key: keychain (Electron) → sessionStorage (web) → prompt
;(async function restoreKey() {
    // 1. Electron OS keychain — fully automatic, no prompt
    const keychainOk = await e2ee.initFromKeychain(props.centralUrl, props.token)
    if (keychainOk) { e2eeReady.value = true; return }

    // 2. sessionStorage — survives page refresh within the same tab
    const cached = sessionStorage.getItem('e2ee_private_key')
    if (cached) {
        const ok = await e2ee.initFromCachedKey(cached, props.centralUrl, props.token)
        if (ok) { e2eeReady.value = true }
    }
})()

async function unlockE2ee() {
    if (!unlockPassphrase.value) return
    unlocking.value = true
    unlockError.value = ''
    try {
        const cachedKey = await e2ee.init(props.centralUrl, props.token, unlockPassphrase.value)
        if (e2ee.isReady()) {
            if (cachedKey) sessionStorage.setItem('e2ee_private_key', cachedKey)
            e2eeReady.value = true
            unlockPassphrase.value = ''
            await decryptMessages(messages.value)
        } else {
            unlockError.value = 'Could not unlock. Check your password.'
        }
    } catch {
        unlockError.value = 'Could not unlock. Check your password.'
    } finally {
        unlocking.value = false
    }
}

async function decryptMessages(msgs) {
    if (!e2ee.isReady()) return
    for (const msg of msgs) {
        if (msg.encrypted && !msg._plaintext) {
            const plain = await e2ee.decrypt(msg.sender_id, props.centralUrl, props.token, msg.content)
            msg._plaintext = plain
        }
    }
}

// ── Filtering ──────────────────────────────────────────────────────────────

const filteredConversations = computed(() => {
    if (!search.value.trim()) return conversations.value
    const q = search.value.toLowerCase()
    return conversations.value.filter(c =>
        c.participant.username.toLowerCase().includes(q)
    )
})

function previewText(conv) {
    if (!conv.last_message) return 'No messages yet'
    if (conv.encrypted) return '🔒 Encrypted message'
    return conv.last_message.content
}

// ── API ────────────────────────────────────────────────────────────────────

async function api(method, path, body) {
    const res = await fetch(props.centralUrl + '/api' + path, {
        method,
        headers: {
            Authorization: 'Bearer ' + props.token,
            Accept:        'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    })
    if (!res.ok) throw new Error(await res.text())
    if (res.status === 204) return null
    return res.json()
}

// ── Conversations ──────────────────────────────────────────────────────────

async function loadConversations() {
    try {
        const data = await api('GET', '/dm/conversations')
        conversations.value = data.conversations
    } catch { /* silent */ }
}

async function openConversation(conv) {
    activeConv.value = conv
    localStorage.setItem('ui_active_dm_conv', conv.id)
    await loadMessages()
}

// Called from parent (right-click → message user)
async function startDmWith(user) {
    // Check if conversation already exists
    const existing = conversations.value.find(c => c.participant.id === user.id)
    if (existing) {
        await openConversation(existing)
        return
    }
    // Show the encryption choice modal
    pendingUser.value = user
    showEncryptionChoice.value = true
}

async function createConversation(encrypted) {
    showEncryptionChoice.value = false
    const user = pendingUser.value
    pendingUser.value = null
    if (!user) return

    try {
        const conv = await api('POST', '/dm/conversations', { user_id: user.id, encrypted })
        const idx = conversations.value.findIndex(c => c.id === conv.id)
        if (idx === -1) conversations.value.unshift(conv)
        await openConversation(conv)
    } catch { /* ignore */ }
}

async function loadMessages() {
    if (!activeConv.value) return
    try {
        const data = await api('GET', '/dm/conversations/' + activeConv.value.id + '/messages')
        messages.value = data.messages
        if (e2eeReady.value) await decryptMessages(messages.value)
        await nextTick()
        scrollToBottom()
    } catch { /* silent */ }
}

async function sendMessage() {
    const content = draft.value.trim()
    if (!content || !activeConv.value) return
    if (activeConv.value.encrypted && !e2eeReady.value) return
    draft.value = ''
    nextTick(() => { if (inputEl.value) inputEl.value.style.height = 'auto' })

    try {
        const recipientId = activeConv.value.participant.id
        let body = { content, encrypted: false }

        if (activeConv.value.encrypted && e2ee.isReady()) {
            const ciphertext = await e2ee.encrypt(recipientId, props.centralUrl, props.token, content)
            if (ciphertext) body = { content: ciphertext, encrypted: true }
        }

        const msg = await api('POST', '/dm/conversations/' + activeConv.value.id + '/messages', body)
        msg._plaintext = content
        messages.value.push(msg)
        await nextTick()
        scrollToBottom()

        const preview = body.encrypted ? '🔒 Encrypted message' : content
        const idx = conversations.value.findIndex(c => c.id === activeConv.value.id)
        if (idx !== -1) conversations.value[idx].last_message = { content: preview, sender: props.currentUsername }
    } catch { /* silent */ }
}

// ── Central Echo ───────────────────────────────────────────────────────────

let echoChannel = null

function subscribeToUserChannel() {
    const echo = props.centralEcho
    if (!echo) return
    try {
        const userId = JSON.parse(atob(props.token.split('.')[1])).sub
        echoChannel = echo.channel('user.' + userId)
            .listen('.dm.message.sent', async data => {
                const preview = data.encrypted ? '🔒 Encrypted message' : data.content

                const idx = conversations.value.findIndex(c => c.id === data.conversation_id)
                if (idx !== -1) {
                    conversations.value[idx].last_message = { content: preview, sender: data.author }
                    const [conv] = conversations.value.splice(idx, 1)
                    conversations.value.unshift(conv)
                } else {
                    await loadConversations()
                }

                if (activeConv.value?.id === data.conversation_id) {
                    if (!messages.value.some(m => m.id === data.id)) {
                        const msg = {
                            id: data.id, author: data.author, sender_id: data.sender_id,
                            content: data.content, encrypted: !!data.encrypted, at: data.at,
                        }
                        if (msg.encrypted && e2eeReady.value) await decryptMessages([msg])
                        messages.value.push(msg)
                        await nextTick()
                        scrollToBottom()
                    }
                } else {
                    emit('new-dm', { conversationId: data.conversation_id, author: data.author, content: preview })
                }
            })
    } catch { /* no Echo */ }
}

// ── Utils ──────────────────────────────────────────────────────────────────

function scrollToBottom() {
    if (messagesEl.value) messagesEl.value.scrollTop = messagesEl.value.scrollHeight
}

function autoResize(e) {
    e.target.style.height = 'auto'
    e.target.style.height = Math.min(e.target.scrollHeight, 160) + 'px'
}

function formatTime(iso) {
    if (!iso) return ''
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function initials(name = '') { return name.slice(0, 2).toUpperCase() }

// ── Lifecycle ──────────────────────────────────────────────────────────────

watch(() => props.centralEcho, (echo) => {
    if (echo && !echoChannel) subscribeToUserChannel()
})

// When E2EE becomes ready mid-session, decrypt currently visible messages
watch(e2eeReady, async (ready) => {
    if (ready && messages.value.length) await decryptMessages(messages.value)
})

watch(() => props.openWithConvId, async (id) => {
    if (!id) return
    const conv = conversations.value.find(c => c.id === id)
    if (conv) { await openConversation(conv); return }
    await loadConversations()
    const conv2 = conversations.value.find(c => c.id === id)
    if (conv2) await openConversation(conv2)
})

onMounted(async () => {
    subscribeToUserChannel()
    await loadConversations()
    if (props.openWith) {
        await startDmWith(props.openWith)
    } else if (props.openWithConvId) {
        const conv = conversations.value.find(c => c.id === props.openWithConvId)
        if (conv) await openConversation(conv)
    } else {
        const savedId = localStorage.getItem('ui_active_dm_conv')
        if (savedId) {
            const conv = conversations.value.find(c => c.id === savedId)
            if (conv) await openConversation(conv)
        }
    }
})

onUnmounted(() => {
    echoChannel?.stopListening('.dm.message.sent')
})

defineExpose({ startDmWith })
</script>

<style scoped>
.dm-action-btn {
    background: none;
    border: none;
    font-size: 16px;
    cursor: pointer;
    padding: 4px 6px;
    border-radius: 6px;
    opacity: 0.6;
    transition: opacity 0.15s, background 0.15s, transform 0.15s;
}
.dm-action-btn:hover { opacity: 1; background: rgba(255,255,255,0.08); transform: scale(1.2); }

.dm-conv-lock { font-size: 11px; margin-left: 4px; }

/* ── Banners ── */
.dm-banner {
    padding: 10px 16px;
    font-size: 13px;
    line-height: 1.5;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    flex-shrink: 0;
}
.dm-banner--warn {
    background: rgba(234, 179, 8, 0.1);
    color: #fbbf24;
    border-left: 3px solid #fbbf24;
}
.dm-banner--lock {
    background: rgba(88, 101, 242, 0.1);
    border-left: 3px solid #5865f2;
}
.dm-banner--ok {
    background: rgba(34, 197, 94, 0.08);
    color: #4ade80;
    border-left: 3px solid #4ade80;
    font-size: 12px;
}

.dm-unlock-row { display: flex; flex-direction: column; gap: 8px; }
.dm-unlock-fields { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.dm-e2ee-passphrase {
    flex: 1;
    min-width: 180px;
    padding: 7px 12px;
    border-radius: 7px;
    border: 1px solid rgba(255,255,255,0.15);
    background: rgba(255,255,255,0.06);
    color: inherit;
    font-size: 13px;
}
.dm-e2ee-error { font-size: 12px; color: #ff6b6b; }
.dm-e2ee-unlock-btn {
    padding: 7px 16px;
    border-radius: 7px;
    border: none;
    background: #5865f2;
    color: #fff;
    font-size: 13px;
    cursor: pointer;
    font-weight: 500;
    white-space: nowrap;
}
.dm-e2ee-unlock-btn:disabled { opacity: 0.5; cursor: default; }
.dm-unlock-hint { font-size: 12px; opacity: 0.55; }
.dm-unlock-link { color: #93c5fd; text-decoration: none; }
.dm-unlock-link:hover { text-decoration: underline; }

.dm-msg-bubble--locked { opacity: 0.5; font-style: italic; font-size: 13px; }

.dm-view-input-bar--locked { opacity: 0.4; pointer-events: none; }

/* ── Encryption choice modal ── */
.dm-choice-backdrop {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.6);
    display: flex; align-items: center; justify-content: center;
    z-index: 1000;
}
.dm-choice-modal {
    background: #1a1d2e;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 14px;
    padding: 28px 24px;
    width: 420px;
    max-width: 95vw;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.dm-choice-title { font-size: 17px; font-weight: 600; }
.dm-choice-subtitle { font-size: 13px; opacity: 0.5; }

.dm-choice-btn {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 16px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.1);
    background: rgba(255,255,255,0.04);
    color: inherit;
    cursor: pointer;
    text-align: left;
    transition: background 0.15s, border-color 0.15s;
}
.dm-choice-btn:hover { background: rgba(255,255,255,0.08); }
.dm-choice-btn--encrypted:hover { border-color: #5865f2; }
.dm-choice-btn--plain:hover { border-color: #fbbf24; }
.dm-choice-btn-icon { font-size: 26px; flex-shrink: 0; }
.dm-choice-btn-label { font-size: 15px; font-weight: 600; margin-bottom: 4px; }
.dm-choice-btn-desc { font-size: 12px; opacity: 0.6; line-height: 1.5; }
.dm-choice-link { color: #93c5fd; text-decoration: none; }
.dm-choice-link:hover { text-decoration: underline; }
.dm-choice-cancel {
    align-self: center;
    background: none;
    border: none;
    color: inherit;
    opacity: 0.4;
    cursor: pointer;
    font-size: 13px;
}
.dm-choice-cancel:hover { opacity: 0.8; }
</style>
