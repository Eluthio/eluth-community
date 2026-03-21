<template>
    <!-- Full-screen DM view — spans the entire below-topbar area -->
    <div class="dm-view">

        <!-- Left sidebar: conversation list -->
        <aside class="dm-view-sidebar">
            <div class="dm-view-sidebar-header">
                <span class="dm-view-sidebar-title">Direct Messages</span>
                <button class="dm-view-close" @click="emit('close')" title="Back to server">✕</button>
            </div>

            <!-- Search / new DM -->
            <div class="dm-view-search-wrap">
                <input
                    class="dm-view-search"
                    type="text"
                    placeholder="Find or start a conversation…"
                    v-model="search"
                />
            </div>

            <!-- Conversation list -->
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
                        <span class="dm-view-conv-name">{{ c.participant.username }}</span>
                        <span class="dm-view-conv-preview">{{ c.last_message?.content ?? 'No messages yet' }}</span>
                    </div>
                </div>

                <div v-if="filteredConversations.length === 0" class="dm-view-empty">
                    {{ search ? 'No conversations match.' : 'No conversations yet.\nRight-click a user to start one.' }}
                </div>
            </div>
        </aside>

        <!-- Right area: thread or placeholder -->
        <div class="dm-view-thread">

            <!-- E2EE unlock prompt -->
            <div v-if="!e2eeReady && !showUnlock" class="dm-e2ee-lock">
                <div class="dm-e2ee-lock-icon">🔒</div>
                <div class="dm-e2ee-lock-title">Messages are end-to-end encrypted</div>
                <div class="dm-e2ee-lock-hint">Enter your Eluth password to unlock your messages for this session.</div>
                <button class="dm-e2ee-unlock-btn" @click="showUnlock = true">Unlock</button>
            </div>

            <div v-else-if="showUnlock" class="dm-e2ee-lock">
                <div class="dm-e2ee-lock-icon">🔒</div>
                <div class="dm-e2ee-lock-title">Enter your password</div>
                <input
                    class="dm-e2ee-passphrase"
                    type="password"
                    placeholder="Your Eluth password"
                    v-model="unlockPassphrase"
                    @keydown.enter.prevent="unlockE2ee"
                    autofocus
                />
                <div v-if="unlockError" class="dm-e2ee-error">{{ unlockError }}</div>
                <div class="dm-e2ee-lock-btns">
                    <button class="dm-e2ee-unlock-btn" :disabled="unlocking" @click="unlockE2ee">
                        {{ unlocking ? 'Unlocking…' : 'Unlock' }}
                    </button>
                    <button class="dm-e2ee-skip-btn" @click="skipE2ee">Use without encryption</button>
                </div>
            </div>

            <!-- No conversation selected -->
            <div v-else-if="!activeConv" class="dm-view-placeholder">
                <div class="dm-view-placeholder-icon">✉</div>
                <div class="dm-view-placeholder-title">Your Direct Messages</div>
                <div class="dm-view-placeholder-hint">Select a conversation or right-click a user to start one.</div>
            </div>

            <!-- Active conversation (only shown once E2EE is resolved) -->
            <template v-else-if="e2eeReady || e2eeSkipped">
                <!-- Call panel — shown above chat when there's an active call for this conversation -->
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

                <div class="dm-view-messages" ref="messagesEl">
                    <div
                        v-for="msg in messages"
                        :key="msg.id"
                        class="dm-msg"
                        :class="{ 'dm-msg--mine': msg.author === currentUsername }"
                    >
                        <div v-if="msg.author !== currentUsername" class="dm-msg-author">{{ msg.author }}</div>
                        <div class="dm-msg-bubble" :class="{ 'dm-msg-bubble--encrypted': msg._decryptFailed }">
                            {{ msg._plaintext ?? msg.content }}
                        </div>
                        <div class="dm-msg-time">
                            {{ formatTime(msg.at) }}
                            <span v-if="msg.encrypted" class="dm-msg-e2ee" title="End-to-end encrypted">🔒</span>
                        </div>
                    </div>
                </div>

                <div class="dm-view-input-bar">
                    <textarea
                        class="dm-view-input"
                        rows="1"
                        :placeholder="`Message ${activeConv.participant.username}`"
                        v-model="draft"
                        @keydown.enter.exact.prevent="sendMessage"
                        @input="autoResize"
                        ref="inputEl"
                    />
                </div>
            </template>
        </div>
    </div>

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

const conversations = ref([])
const activeConv    = ref(null)
const messages      = ref([])
const draft         = ref('')
const search        = ref('')
const messagesEl    = ref(null)
const inputEl       = ref(null)

// ── E2EE ──────────────────────────────────────────────────────────────────
const e2ee            = useE2ee()
const e2eeReady       = ref(false)
const e2eeSkipped     = ref(false)
const showUnlock      = ref(false)
const unlockPassphrase = ref('')
const unlockError     = ref('')
const unlocking       = ref(false)

async function unlockE2ee() {
    if (!unlockPassphrase.value) return
    unlocking.value = true
    unlockError.value = ''
    try {
        await e2ee.init(props.centralUrl, props.token, unlockPassphrase.value)
        if (e2ee.isReady()) {
            e2eeReady.value  = true
            showUnlock.value = false
            unlockPassphrase.value = ''
            // Re-decrypt already-loaded messages
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

function skipE2ee() {
    e2eeSkipped.value = true
    showUnlock.value  = false
}

async function decryptMessages(msgs) {
    if (!e2ee.isReady()) return
    for (const msg of msgs) {
        if (msg.encrypted && !msg._plaintext) {
            const plain = await e2ee.decrypt(msg.sender_id, props.centralUrl, props.token, msg.content)
            msg._plaintext    = plain
            msg._decryptFailed = plain.startsWith('[Encrypted message')
        }
    }
}

let echoChannel = null

// ── Filtering ──────────────────────────────────────────────────────────────

const filteredConversations = computed(() => {
    if (!search.value.trim()) return conversations.value
    const q = search.value.toLowerCase()
    return conversations.value.filter(c =>
        c.participant.username.toLowerCase().includes(q)
    )
})

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

async function startDmWith(user) {
    try {
        const conv = await api('POST', '/dm/conversations', { user_id: user.id })
        // Update or prepend in list
        const idx = conversations.value.findIndex(c => c.id === conv.id)
        if (idx === -1) conversations.value.unshift(conv)
        await openConversation(conv)
    } catch {
        /* ignore — conversation list will still show */
    }
}

async function loadMessages() {
    if (!activeConv.value) return
    try {
        const data = await api('GET', '/dm/conversations/' + activeConv.value.id + '/messages')
        messages.value = data.messages
        await decryptMessages(messages.value)
        await nextTick()
        scrollToBottom()
    } catch { /* silent */ }
}

async function sendMessage() {
    const content = draft.value.trim()
    if (!content || !activeConv.value) return
    draft.value = ''
    nextTick(() => { if (inputEl.value) inputEl.value.style.height = 'auto' })

    try {
        const recipientId = activeConv.value.participant.id
        let body = { content, encrypted: false }

        if (e2ee.isReady() && recipientId) {
            const ciphertext = await e2ee.encrypt(recipientId, props.centralUrl, props.token, content)
            if (ciphertext) {
                body = { content: ciphertext, encrypted: true }
            }
        }

        const msg = await api('POST', '/dm/conversations/' + activeConv.value.id + '/messages', body)
        // Store plaintext for immediate display — no need to decrypt our own sent message
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

function subscribeToUserChannel() {
    const echo = props.centralEcho
    if (!echo) return
    try {
        const userId = JSON.parse(atob(props.token.split('.')[1])).sub
        echoChannel = echo.channel('user.' + userId)
            .listen('.dm.message.sent', async data => {
                const preview = data.encrypted ? '🔒 Encrypted message' : data.content

                // Update conversation preview
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
                        const msg = { id: data.id, author: data.author, sender_id: data.sender_id, content: data.content, encrypted: !!data.encrypted, at: data.at }
                        if (msg.encrypted) await decryptMessages([msg])
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
    // Only stop listening for DM messages — do NOT leave the channel.
    // App.vue owns the user.{id} channel subscription for calls and keeps it alive.
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

.dm-e2ee-lock {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex: 1;
    gap: 12px;
    padding: 40px;
    text-align: center;
}
.dm-e2ee-lock-icon { font-size: 40px; }
.dm-e2ee-lock-title { font-size: 16px; font-weight: 600; opacity: 0.9; }
.dm-e2ee-lock-hint { font-size: 13px; opacity: 0.5; max-width: 320px; line-height: 1.5; }
.dm-e2ee-passphrase {
    width: 280px;
    padding: 10px 14px;
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.15);
    background: rgba(255,255,255,0.06);
    color: inherit;
    font-size: 14px;
}
.dm-e2ee-error { font-size: 12px; color: #ff6b6b; }
.dm-e2ee-lock-btns { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; }
.dm-e2ee-unlock-btn {
    padding: 8px 20px;
    border-radius: 8px;
    border: none;
    background: #5865f2;
    color: #fff;
    font-size: 14px;
    cursor: pointer;
    font-weight: 500;
}
.dm-e2ee-unlock-btn:disabled { opacity: 0.5; cursor: default; }
.dm-e2ee-skip-btn {
    padding: 8px 16px;
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.15);
    background: transparent;
    color: inherit;
    font-size: 13px;
    cursor: pointer;
    opacity: 0.6;
}
.dm-e2ee-skip-btn:hover { opacity: 1; }
.dm-msg-e2ee { font-size: 10px; opacity: 0.5; margin-left: 4px; }
.dm-msg-bubble--encrypted { opacity: 0.6; font-style: italic; }
</style>
