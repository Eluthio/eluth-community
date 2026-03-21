<template>
    <div class="side-panel" @click.stop>
        <div class="side-panel-header">
            <button v-if="activeConv" class="side-panel-back" @click="activeConv = null">←</button>
            <span>{{ activeConv ? activeConv.participant.username : 'Direct Messages' }}</span>
            <button class="side-panel-close" @click="emit('close')">✕</button>
        </div>

        <!-- Conversation list -->
        <template v-if="!activeConv">
            <div class="side-panel-section">
                <div v-if="conversations.length === 0" class="side-panel-empty">No conversations yet.<br>Right-click a user to start one.</div>
                <div
                    v-for="c in conversations"
                    :key="c.id"
                    class="dm-conv-row"
                    @click="openConversation(c)"
                >
                    <div class="friend-avatar">
                        <img v-if="c.participant.avatar_url" :src="c.participant.avatar_url" :alt="c.participant.username" @error="e => e.target.style.display='none'" />
                        <span v-else>{{ initials(c.participant.username) }}</span>
                    </div>
                    <div class="dm-conv-info">
                        <span class="dm-conv-name">{{ c.participant.username }}</span>
                        <span class="dm-conv-preview">{{ c.last_message?.content ?? '' }}</span>
                    </div>
                </div>
            </div>
        </template>

        <!-- Message thread -->
        <template v-else>
            <!-- Thread topbar with call buttons -->
            <div class="dm-thread-topbar">
                <span class="dm-thread-name">{{ activeConv.participant.username }}</span>
                <div class="dm-thread-actions">
                    <button class="dm-action-btn" title="Voice call" @click="startCall(false)">📞</button>
                    <button class="dm-action-btn" title="Video call" @click="startCall(true)">📹</button>
                </div>
            </div>

            <div class="dm-messages" ref="messagesEl">
                <div v-for="msg in messages" :key="msg.id" class="dm-message" :class="{ 'dm-message--mine': msg.author === currentUsername }">
                    <div class="dm-message-author" v-if="msg.author !== currentUsername">{{ msg.author }}</div>
                    <div class="dm-message-bubble">{{ msg.content }}</div>
                    <div class="dm-message-time">{{ formatTime(msg.at) }}</div>
                </div>
            </div>
            <div class="dm-input-bar">
                <textarea
                    class="dm-input"
                    rows="1"
                    :placeholder="`Message ${activeConv.participant.username}`"
                    v-model="draft"
                    @keydown.enter.exact.prevent="sendMessage"
                    @input="autoResize"
                    ref="inputEl"
                />
            </div>
        </template>

        <div v-if="error" class="side-panel-error">{{ error }}</div>
    </div>

    <!-- Active call -->
    <Teleport to="body">
        <VoiceCall
            v-if="activeCall"
            :livekit-url="activeCall.livekitUrl"
            :token="activeCall.token"
            :local-name="currentUsername"
            :video-call="activeCall.video"
            @ended="onCallEnded"
        />

        <!-- Incoming call notification -->
        <div v-if="incomingCall" class="incoming-call-toast" @click.stop>
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
</template>

<script setup>
import { ref, watch, nextTick, onMounted, onUnmounted } from 'vue'
import VoiceCall from './VoiceCall.vue'

const props = defineProps({
    centralUrl:      { type: String, required: true },
    token:           { type: String, required: true },
    currentUsername: { type: String, default: '' },
    openWith:        { type: Object, default: null },
    centralEcho:     { type: Object, default: null },   // Echo connected to central Reverb
})
const emit = defineEmits(['close', 'new-dm'])

const conversations = ref([])
const activeConv    = ref(null)
const messages      = ref([])
const draft         = ref('')
const error         = ref('')
const messagesEl    = ref(null)
const inputEl       = ref(null)

// ── Call state ────────────────────────────────────────────────────────────────
const activeCall   = ref(null)   // { token, livekitUrl, video, convId }
const incomingCall = ref(null)   // { callerName, callerId, room, convId }

let echoChannel = null

// ── API helper ────────────────────────────────────────────────────────────────

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

// ── Conversations ─────────────────────────────────────────────────────────────

async function loadConversations() {
    try {
        const data      = await api('GET', '/dm/conversations')
        conversations.value = data.conversations
    } catch {
        error.value = 'Failed to load conversations.'
    }
}

async function openConversation(conv) {
    activeConv.value = conv
    await loadMessages()
}

async function openWith(user) {
    try {
        const conv = await api('POST', '/dm/conversations', { user_id: user.id })
        await openConversation(conv)
    } catch {
        error.value = 'Could not open conversation.'
    }
}

async function loadMessages() {
    if (!activeConv.value) return
    try {
        const data = await api('GET', '/dm/conversations/' + activeConv.value.id + '/messages')
        messages.value = data.messages
        await nextTick()
        scrollToBottom()
    } catch {
        error.value = 'Failed to load messages.'
    }
}

watch(() => activeConv.value, (val) => {
    if (!val) messages.value = []
})

async function sendMessage() {
    const content = draft.value.trim()
    if (!content || !activeConv.value) return
    draft.value = ''
    try {
        const msg = await api('POST', '/dm/conversations/' + activeConv.value.id + '/messages', { content })
        messages.value.push(msg)
        await nextTick()
        scrollToBottom()
    } catch {
        error.value = 'Failed to send message.'
    }
}

// ── Calls ─────────────────────────────────────────────────────────────────────

async function startCall(video = false) {
    if (!activeConv.value) return
    try {
        const data = await api('POST', '/dm/conversations/' + activeConv.value.id + '/call/token', { caller: true })
        activeCall.value = { token: data.token, livekitUrl: data.livekit_url, video, convId: activeConv.value.id }
    } catch {
        error.value = 'Could not start call.'
    }
}

async function answerCall() {
    if (!incomingCall.value) return
    const { convId, callerName } = incomingCall.value
    incomingCall.value = null
    try {
        const data = await api('POST', '/dm/conversations/' + convId + '/call/token', {})
        activeCall.value = { token: data.token, livekitUrl: data.livekit_url, video: false, convId }
    } catch {
        error.value = 'Could not join call.'
    }
}

function declineCall() {
    incomingCall.value = null
}

async function onCallEnded() {
    if (activeCall.value) {
        try {
            await api('POST', '/dm/conversations/' + activeCall.value.convId + '/call/end', {})
        } catch { /* ignore */ }
    }
    activeCall.value = null
}

// ── Subscribe to central private channel (calls + DMs) ────────────────────────

function subscribeToUserChannel() {
    const echo = props.centralEcho
    if (!echo) return
    try {
        const payload = JSON.parse(atob(props.token.split('.')[1]))
        const userId  = payload.sub
        echoChannel = echo.channel('user.' + userId)
            .listen('.incoming-call', (data) => {
                incomingCall.value = {
                    callerName: data.caller_name,
                    callerId:   data.caller_id,
                    room:       data.room,
                    convId:     data.conv_id,
                }
            })
            .listen('.call-ended', () => {
                activeCall.value   = null
                incomingCall.value = null
            })
            .listen('.dm.message.sent', async (data) => {
                // Update conversation list preview
                const convIdx = conversations.value.findIndex(c => c.id === data.conversation_id)
                if (convIdx !== -1) {
                    conversations.value[convIdx].last_message = { content: data.content, sender: data.author, at: data.at }
                } else {
                    // New conversation — reload list
                    await loadConversations()
                }

                // If the conversation is open, append the message
                if (activeConv.value?.id === data.conversation_id) {
                    if (!messages.value.some(m => m.id === data.id)) {
                        messages.value.push({ id: data.id, author: data.author, content: data.content, at: data.at })
                        await nextTick()
                        scrollToBottom()
                    }
                } else {
                    // Notify parent so it can show a badge
                    emit('new-dm', { conversationId: data.conversation_id, author: data.author, content: data.content })
                }
            })
    } catch { /* no Echo available */ }
}

// ── Utils ─────────────────────────────────────────────────────────────────────

function scrollToBottom() {
    if (messagesEl.value) messagesEl.value.scrollTop = messagesEl.value.scrollHeight
}

function autoResize(e) {
    e.target.style.height = 'auto'
    e.target.style.height = Math.min(e.target.scrollHeight, 120) + 'px'
}

function formatTime(iso) {
    if (!iso) return ''
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function initials(name = '') { return name.slice(0, 2).toUpperCase() }

// ── Lifecycle ─────────────────────────────────────────────────────────────────

if (props.openWith) {
    openWith(props.openWith)
} else {
    loadConversations()
}

defineExpose({ openWith })

onMounted(subscribeToUserChannel)
onUnmounted(() => {
    if (echoChannel) props.centralEcho?.leave('user.' + JSON.parse(atob(props.token.split('.')[1])).sub)
})
</script>

<style scoped>
.dm-thread-topbar {
    display: flex;
    align-items: center;
    padding: 8px 14px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    flex-shrink: 0;
    gap: 8px;
}

.dm-thread-name {
    font-weight: 600;
    font-size: 14px;
    flex: 1;
}

.dm-thread-actions { display: flex; gap: 4px; }

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

/* Incoming call toast */
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

.incoming-call-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.incoming-call-icon { font-size: 24px; animation: ring 1s infinite; }

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
