<template>
    <div id="main">

        <!-- Stream channel: player or offline placeholder -->
        <template v-if="props.channel?.type === 'stream'">
            <div class="stream-zone">
                <!-- Streamer: local preview with sync/live overlay -->
                <div v-if="isStreaming" class="stream-preview-wrap">
                    <video ref="previewEl" class="stream-preview-local" autoplay muted playsinline />
                    <div class="stream-sync-overlay" :class="{ 'stream-sync-overlay--live': streamSynced }">
                        <span v-if="streamSynced">🔴 Live</span>
                        <span v-else class="stream-syncing-text">
                            <span class="stream-syncing-dot" />Synchronising…
                        </span>
                    </div>
                </div>

                <!-- Viewer: someone else is live -->
                <StreamPlayer
                    v-else-if="props.channel.is_live"
                    :channel-id="props.channel.id"
                    :api-base="props.apiBase"
                    :streamer-username="props.channel.live_streamer_username ?? ''"
                    @error="onStreamError"
                />

                <!-- Not live: placeholder -->
                <div v-else class="stream-offline">
                    <div class="stream-offline-icon">📺</div>
                    <div class="stream-offline-title">Nobody is streaming yet</div>
                    <div v-if="props.canStream" class="stream-offline-hint">Click Go Live below to start streaming</div>
                </div>

                <!-- Source picker modal -->
                <Teleport to="body">
                    <div v-if="showSourcePicker" class="stream-source-overlay" @click.self="showSourcePicker = false">
                        <div class="stream-source-modal">
                            <div class="stream-source-title">Choose stream source</div>
                            <div class="stream-source-options">
                                <button class="stream-source-opt" @click="beginStream('display')">
                                    <span class="stream-source-icon">🖥️</span>
                                    <span class="stream-source-label">Desktop / Window</span>
                                    <span class="stream-source-desc">Share your screen or an app window</span>
                                </button>
                                <button class="stream-source-opt" @click="beginStream('camera')">
                                    <span class="stream-source-icon">📷</span>
                                    <span class="stream-source-label">Webcam / Virtual Camera</span>
                                    <span class="stream-source-desc">Use a webcam or OBS virtual camera</span>
                                </button>
                            </div>
                            <button class="stream-source-cancel" @click="showSourcePicker = false">Cancel</button>
                        </div>
                    </div>
                </Teleport>

                <!-- Streamer controls: shown to the user who has stream permission -->
                <div v-if="props.canStream" class="stream-controls">
                    <template v-if="!isStreaming">
                        <button class="stream-go-live-btn" @click="openSourcePicker" :disabled="streamStarting || props.channel.is_live">
                            {{ streamStarting ? 'Starting…' : props.channel.is_live ? 'Channel is live' : '🔴 Go Live' }}
                        </button>
                        <div v-if="streamError" class="stream-error">{{ streamError }}</div>
                    </template>
                    <template v-else>
                        <div class="stream-live-indicator">{{ streamDuration }}</div>
                        <button class="stream-stop-btn" @click="stopStream">⏹ Stop Stream</button>
                    </template>
                </div>
            </div>
        </template>

        <div id="messages" ref="messagesEl">
            <div v-if="visibleGroups.length === 0" class="empty-state">
                <div class="empty-state-icon">✦</div>
                <div class="empty-state-title">{{ channelName }}</div>
                <div style="font-size:13px;">Nothing here yet. Start the conversation.</div>
            </div>

            <div
                v-for="group in visibleGroups"
                :key="group.id"
                class="message-group"
                @contextmenu.prevent="openMenu($event, group)"
            >
                <div class="avatar">
                    <img v-if="group.avatarUrl" :src="group.avatarUrl" :alt="group.author" @error="e => e.target.style.display='none'" />
                    <span v-if="!group.avatarUrl">{{ initials(group.author) }}</span>
                </div>
                <div class="message-content">
                    <div class="message-meta">
                        <span class="message-author" :style="{ color: group.authorColor }">{{ group.author }}</span>
                        <span class="message-time">{{ group.time }}</span>
                    </div>
                    <div v-for="msg in group.messages" :key="msg.id" class="message-body">
                        <!-- Reply reference -->
                        <div v-if="msg.reply_to_author" class="reply-ref" @click="scrollToMessage(msg.reply_to_id)">
                            <span class="reply-ref-icon">↩</span>
                            <span class="reply-ref-author">{{ msg.reply_to_author }}</span>
                            <span class="reply-ref-preview">{{ msg.reply_to_preview }}</span>
                        </div>
                        <!-- Message content with @mention highlighting -->
                        <span v-html="renderContent(msg.content)" />
                    </div>
                </div>
            </div>
        </div>

        <div id="input-bar">
            <!-- Reply bar -->
            <div v-if="replyTo" class="reply-bar">
                <span class="reply-bar-label">Replying to <strong>{{ replyTo.author }}</strong></span>
                <span class="reply-bar-preview">{{ replyTo.preview }}</span>
                <button class="reply-bar-close" @click="replyTo = null" title="Cancel reply">✕</button>
            </div>

            <!-- GIF preview attachment -->
            <div v-if="pendingGif" class="gif-attachment">
                <img :src="pendingGif" class="gif-attachment-thumb" />
                <button class="gif-attachment-remove" @click="pendingGif = null" title="Remove GIF">✕</button>
            </div>

            <div class="message-input-wrap">
                <button class="input-action" title="Attach" style="font-size:19px;">⊕</button>
                <textarea
                    class="message-input"
                    rows="1"
                    :placeholder="`Message ${channelName}`"
                    v-model="draft"
                    @keydown="onKeydown"
                    @input="onInput"
                    @click="onCursorMove"
                    ref="inputEl"
                />
                <button class="input-action" title="Emoji" style="font-size:17px;">✦</button>
                <GifPicker
                    v-if="enabledPlugins.includes('gif-picker')"
                    :settings="pluginSettings['gif-picker'] ?? {}"
                    @insert="insertGif"
                />
                <EmoticonPicker
                    v-if="enabledPlugins.includes('emoticon-picker')"
                    :api-base="props.apiBase"
                    :auth-token="props.authToken"
                    @insert="insertEmote"
                />
            </div>

            <!-- @mention autocomplete -->
            <Teleport to="body">
                <div
                    v-if="mention.active && mention.results.length"
                    class="mention-dropdown"
                    :style="{ top: mentionTop + 'px', left: mentionLeft + 'px' }"
                    @mousedown.prevent
                >
                    <div
                        v-for="(m, i) in mention.results"
                        :key="m.id"
                        class="mention-item"
                        :class="{ active: i === mention.index }"
                        @click="selectMention(m)"
                    >
                        <div class="mention-avatar">
                            <img v-if="m.avatar_url" :src="m.avatar_url" :alt="m.username" @error="e => e.target.style.display='none'" />
                            <span v-else>{{ initials(m.username) }}</span>
                        </div>
                        <span class="mention-username">{{ m.username }}</span>
                        <span class="mention-presence" :class="m.presence">●</span>
                    </div>
                </div>
            </Teleport>
        </div>

        <!-- Context menu -->
        <Teleport to="body">
            <div
                v-if="menu.visible"
                class="ctx-menu"
                :style="{ top: menu.y + 'px', left: menu.x + 'px' }"
                @click.stop
            >
                <div class="ctx-header">{{ menu.author }}</div>

                <!-- Message actions (always visible) -->
                <button class="ctx-item" @click="doReply">Reply</button>
                <button class="ctx-item" @click="doMentionUser">Mention</button>

                <div class="ctx-separator" />

                <!-- Admin actions -->
                <template v-if="props.currentMember && !menu.isSelf">
                    <button
                        v-if="props.currentMember.can('kick_members')"
                        class="ctx-item"
                        @click="doKick"
                    >Kick</button>
                    <button
                        v-if="props.currentMember.can('ban_members')"
                        class="ctx-item ctx-item--danger"
                        @click="doBan"
                    >Ban</button>
                </template>

                <!-- User actions (not self) -->
                <template v-if="!menu.isSelf">
                    <button class="ctx-item" @click="toggleMute">
                        {{ mutedUsers.has(menu.author) ? 'Unmute' : 'Mute' }}
                    </button>
                    <button class="ctx-item" @click="doSendDm">Message</button>
                    <button class="ctx-item" @click="doViewProfile">View Profile</button>
                </template>

                <!-- Self actions -->
                <template v-if="menu.isSelf">
                    <button class="ctx-item" @click="emit('open-user-settings'); closeMenu()">Your Settings</button>
                </template>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, watch, reactive, onMounted, onUnmounted, nextTick } from 'vue'
import { gsap } from 'gsap'
import StreamPlayer from './StreamPlayer.vue'
import GifPicker from '../plugins/GifPicker.vue'
import EmoticonPicker from '../plugins/EmoticonPicker.vue'

const props = defineProps({
    channelName:   { type: String, default: 'general' },
    channelTopic:  { type: String, default: null },
    messages:      { type: Array,  default: () => [] },
    members:       { type: Array,  default: () => [] },
    currentMember: { type: Object, default: null },
    channel:       { type: Object, default: null },   // full channel object (type, is_live, etc.)
    canStream:     { type: Boolean, default: false },
    apiBase:       { type: String, default: '' },
    authToken:     { type: String, default: '' },
    enabledPlugins: { type: Array,  default: () => [] },
    pluginSettings: { type: Object, default: () => ({}) },
    customEmotes: { type: Array, default: () => [] },
})
const emit = defineEmits(['send', 'kick', 'ban', 'open-dm', 'open-user-settings', 'view-profile'])

const draft      = ref('')
const replyTo    = ref(null)   // { id, author, preview }
const messagesEl = ref(null)
const inputEl    = ref(null)

// ── Streaming ──────────────────────────────────────────────────────────────
const isStreaming     = ref(false)
const streamSynced    = ref(false)
const streamStarting  = ref(false)
const streamError     = ref('')
const streamDuration  = ref('0:00')
const showSourcePicker = ref(false)
const previewEl       = ref(null)

let mediaRecorder  = null
let mediaStream    = null
let streamSeq      = 0
let streamTimer    = null
let streamStartTs  = 0

function updateStreamDuration() {
    const elapsed = Math.floor((Date.now() - streamStartTs) / 1000)
    const m = Math.floor(elapsed / 60)
    const s = elapsed % 60
    streamDuration.value = `${m}:${String(s).padStart(2, '0')}`
}

function openSourcePicker() {
    streamError.value = ''
    showSourcePicker.value = true
}

async function beginStream(source) {
    showSourcePicker.value = false
    startStream(source)
}

async function startStream(source = 'display') {
    streamError.value = ''
    streamStarting.value = true

    try {
        if (source === 'camera') {
            mediaStream = await navigator.mediaDevices.getUserMedia({
                video: true,
                audio: true,
            })
        } else {
            // Capture display + system audio
            mediaStream = await navigator.mediaDevices.getDisplayMedia({
                video: { frameRate: { ideal: 30, max: 60 } },
                audio: true,
            })
        }

        // Pick best supported mimeType
        const candidates = [
            'video/webm;codecs=vp9,opus',
            'video/webm;codecs=vp8,opus',
            'video/webm',
        ]
        const mimeType = candidates.find(m => MediaRecorder.isTypeSupported(m)) ?? 'video/webm'

        // Notify server we're starting
        const res = await fetch(`${props.apiBase}/api/streams/${props.channel.id}/start`, {
            method: 'POST',
            headers: {
                Authorization: 'Bearer ' + props.authToken,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ mime_type: mimeType }),
        })
        if (!res.ok) {
            const err = await res.json().catch(() => ({}))
            throw new Error(err.message ?? 'Failed to start stream')
        }

        streamSeq = 0
        isStreaming.value = true
        streamStartTs = Date.now()
        streamTimer = setInterval(updateStreamDuration, 1000)

        // Show local preview (nextTick so the <video> ref is mounted)
        await nextTick()
        if (previewEl.value) previewEl.value.srcObject = mediaStream

        mediaRecorder = new MediaRecorder(mediaStream, { mimeType, timeslice: 4000 })

        mediaRecorder.ondataavailable = async (e) => {
            if (!e.data || e.data.size === 0 || !isStreaming.value) return
            const seq = streamSeq++
            const form = new FormData()
            form.append('seq', seq)
            form.append('chunk', e.data, 'chunk.webm')
            try {
                await fetch(`${props.apiBase}/api/streams/${props.channel.id}/chunk`, {
                    method: 'POST',
                    headers: { Authorization: 'Bearer ' + props.authToken },
                    body: form,
                })
                if (!streamSynced.value) streamSynced.value = true
            } catch { /* ignore individual chunk failures */ }
        }

        mediaRecorder.onstop = () => {
            mediaStream?.getTracks().forEach(t => t.stop())
            mediaStream = null
        }

        // If user stops sharing via browser UI, clean up
        mediaStream.getVideoTracks()[0]?.addEventListener('ended', () => stopStream())

        mediaRecorder.start(4000)
    } catch (e) {
        streamError.value = e.name === 'NotAllowedError'
            ? 'Permission denied. Allow camera/screen access and try again.'
            : (e.message ?? 'Could not start stream.')
        mediaStream?.getTracks().forEach(t => t.stop())
        mediaStream = null
    } finally {
        streamStarting.value = false
    }
}

async function stopStream() {
    isStreaming.value = false
    streamSynced.value = false
    clearInterval(streamTimer)
    streamDuration.value = '0:00'

    if (previewEl.value) previewEl.value.srcObject = null
    mediaRecorder?.stop()
    mediaRecorder = null

    try {
        await fetch(`${props.apiBase}/api/streams/${props.channel.id}/stop`, {
            method: 'POST',
            headers: { Authorization: 'Bearer ' + props.authToken },
        })
    } catch { /* ignore */ }
}

function onStreamError(reason) {
    if (reason === 'ended') {
        // Remote stop — streamer ended from another device
        isStreaming.value = false
        clearInterval(streamTimer)
        mediaRecorder?.stop()
        mediaRecorder = null
    }
}

// ── Mute ──────────────────────────────────────────────────────────────────
const MUTE_KEY   = 'muted_users'
const mutedUsers = ref(new Set(JSON.parse(localStorage.getItem(MUTE_KEY) ?? '[]')))
function saveMuted() { localStorage.setItem(MUTE_KEY, JSON.stringify([...mutedUsers.value])) }

// ── Member lookup ─────────────────────────────────────────────────────────
const memberByUsername = computed(() => {
    const map = {}
    for (const m of props.members) map[m.username] = m
    return map
})
const memberAvatarMap = computed(() => {
    const map = {}
    for (const m of props.members) { if (m.avatar_url) map[m.username] = m.avatar_url }
    return map
})

// ── Message rendering ─────────────────────────────────────────────────────
const emoteMap = computed(() => {
    const map = {}
    for (const e of props.customEmotes) map[e.name] = e
    return map
})

function renderContent(content) {
    // Escape HTML first
    let safe = content
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')

    // Embed GIF/image URLs from known CDNs
    const gifPattern = /https?:\/\/(media\d*\.tenor\.com|c\.tenor\.com|media\d*\.giphy\.com|i\.giphy\.com)\/\S+\.(gif|webp|mp4)(\?\S*)?/gi
    safe = safe.replace(gifPattern, url => `<img src="${url}" class="msg-gif" alt="GIF" loading="lazy" />`)

    // Replace :emote_name: with custom emote images
    safe = safe.replace(/:([a-z0-9_-]{2,32}):/g, (match, name) => {
        const emote = emoteMap.value[name]
        if (!emote) return match
        return `<img src="${emote.url}" class="msg-emote" alt=":${name}:" title=":${name}:" loading="lazy" />`
    })

    // Highlight @mentions
    return safe.replace(/@(everyone|here|\w+)/g, (match, name) => {
        if (name === 'everyone' || name === 'here') {
            return `<span class="mention mention--everyone">${match}</span>`
        }
        const isSelf = props.currentMember?.username === name
        return `<span class="mention${isSelf ? ' mention--self' : ''}">${match}</span>`
    })
}

// ── Grouping ──────────────────────────────────────────────────────────────
const GROUP_GAP_MS = 60 * 1000

const groupedMessages = computed(() => {
    const groups = []
    for (const msg of props.messages) {
        const last    = groups[groups.length - 1]
        const lastMsg = last?.messages[last.messages.length - 1]
        // Break group if there's a reply (always starts a new group)
        const withinGap = lastMsg && (new Date(msg.at) - new Date(lastMsg.at)) < GROUP_GAP_MS
        const canGroup  = last && last.author === msg.author && withinGap && !msg.reply_to_id
        if (canGroup) {
            last.messages.push(msg)
        } else {
            groups.push({
                id:          msg.id,
                author:      msg.author,
                memberId:    memberByUsername.value[msg.author]?.id ?? null,
                authorColor: msg.authorColor ?? 'var(--accent)',
                avatarUrl:   memberAvatarMap.value[msg.author] ?? null,
                time:        formatTime(msg.at),
                messages:    [msg],
            })
        }
    }
    return groups
})

const visibleGroups = computed(() =>
    groupedMessages.value.filter(g => !mutedUsers.value.has(g.author))
)

// ── @mention autocomplete ─────────────────────────────────────────────────
const mention = reactive({
    active:  false,
    query:   '',
    atIndex: -1,
    index:   0,
    results: [],
})
const mentionTop  = ref(0)
const mentionLeft = ref(0)

const onlineFirst = computed(() => {
    const order = { online: 0, idle: 1, dnd: 2, offline: 3 }
    return [...props.members].sort((a, b) =>
        (order[a.presence] ?? 4) - (order[b.presence] ?? 4)
    )
})

function updateMentionDropdown() {
    const el  = inputEl.value
    if (!el) return

    // Read directly from the element so we always get the latest value
    const val    = el.value
    const cursor = el.selectionStart
    const before = val.substring(0, cursor)
    const atIdx  = before.lastIndexOf('@')

    if (atIdx === -1) { closeMention(); return }

    // Make sure there's no space between @ and cursor
    const partial = before.substring(atIdx + 1)
    if (/\s/.test(partial)) { closeMention(); return }

    mention.atIndex = atIdx
    mention.query   = partial.toLowerCase()

    if (mention.query.length < 2) { closeMention(); return }

    mention.results = onlineFirst.value
        .filter(m => m.username.toLowerCase().startsWith(mention.query))
        .slice(0, 8)

    if (!mention.results.length) { closeMention(); return }

    mention.active = true
    mention.index  = 0

    // Position above the textarea — transform handles the upward shift
    const rect = el.getBoundingClientRect()
    mentionTop.value  = rect.top - 4
    mentionLeft.value = rect.left
}

function closeMention() {
    mention.active  = false
    mention.results = []
    mention.atIndex = -1
}

function selectMention(member) {
    const before = draft.value.substring(0, mention.atIndex)
    const after  = draft.value.substring(inputEl.value.selectionStart)
    draft.value  = before + '@' + member.username + ' ' + after
    closeMention()
    nextTick(() => {
        const pos = mention.atIndex + member.username.length + 2
        inputEl.value.setSelectionRange(pos, pos)
        inputEl.value.focus()
    })
}

function onInput(e) {
    autoResize(e)
    updateMentionDropdown()
}

function onCursorMove() {
    updateMentionDropdown()
}

function onKeydown(e) {
    // Handle mention navigation first
    if (mention.active && mention.results.length) {
        if (e.key === 'ArrowDown') {
            e.preventDefault()
            mention.index = (mention.index + 1) % mention.results.length
            return
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault()
            mention.index = (mention.index - 1 + mention.results.length) % mention.results.length
            return
        }
        if (e.key === 'Tab' || (e.key === 'Enter' && mention.active)) {
            e.preventDefault()
            selectMention(mention.results[mention.index])
            return
        }
        if (e.key === 'Escape') {
            closeMention()
            return
        }
    }

    // Send on Enter (no shift)
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault()
        send()
    }
}

// ── Context menu ──────────────────────────────────────────────────────────
const menu = reactive({
    visible: false, x: 0, y: 0,
    author: '', memberId: null, isSelf: false,
    msgId: null, msgContent: '',
})

function openMenu(event, group) {
    event.stopPropagation()
    const isSelf = props.currentMember && group.memberId === props.currentMember.id
    // Get the specific message that was right-clicked (last in group as fallback)
    const msgs    = group.messages
    const lastMsg = msgs[msgs.length - 1]
    menu.visible    = true
    menu.x          = Math.min(event.clientX, window.innerWidth - 200)
    menu.y          = Math.min(event.clientY, window.innerHeight - 220)
    menu.author     = group.author
    menu.memberId   = group.memberId
    menu.isSelf     = isSelf
    menu.msgId      = lastMsg.id
    menu.msgContent = lastMsg.content
}

function closeMenu() { menu.visible = false }

function doReply() {
    replyTo.value = {
        id:      menu.msgId,
        author:  menu.author,
        preview: menu.msgContent.length > 80 ? menu.msgContent.slice(0, 80) + '…' : menu.msgContent,
    }
    closeMenu()
    nextTick(() => inputEl.value?.focus())
}

function doMentionUser() {
    const username = menu.author
    // Insert @username at cursor or append
    const el     = inputEl.value
    const cursor = el?.selectionStart ?? draft.value.length
    const before = draft.value.substring(0, cursor)
    const after  = draft.value.substring(cursor)
    const spacer = before.length && !before.endsWith(' ') ? ' ' : ''
    draft.value  = before + spacer + '@' + username + ' ' + after
    closeMenu()
    nextTick(() => {
        const pos = (before + spacer + '@' + username + ' ').length
        el?.setSelectionRange(pos, pos)
        el?.focus()
    })
}

function doViewProfile() {
    emit('view-profile', { username: menu.author, anchorX: menu.x, anchorY: menu.y })
    closeMenu()
}
function doKick()   { if (menu.memberId) emit('kick', menu.memberId); closeMenu() }
function doBan()    { if (menu.memberId) emit('ban',  menu.memberId); closeMenu() }
function doSendDm() { if (menu.memberId) emit('open-dm', { id: menu.memberId, username: menu.author }); closeMenu() }

function toggleMute() {
    if (mutedUsers.value.has(menu.author)) {
        mutedUsers.value.delete(menu.author)
    } else {
        mutedUsers.value.add(menu.author)
    }
    mutedUsers.value = new Set(mutedUsers.value)
    saveMuted()
    closeMenu()
}

onMounted(() => {
    document.addEventListener('click',       closeMenu)
    document.addEventListener('contextmenu', closeMenu)
    document.addEventListener('keydown',     onDocEsc)
})
onUnmounted(() => {
    document.removeEventListener('click',       closeMenu)
    document.removeEventListener('contextmenu', closeMenu)
    document.removeEventListener('keydown',     onDocEsc)
    if (isStreaming.value) stopStream()
    clearInterval(streamTimer)
})
function onDocEsc(e) { if (e.key === 'Escape') { closeMenu(); closeMention() } }

// ── GIF insert ────────────────────────────────────────────────────────────
const pendingGif = ref(null)

function insertGif(url) {
    pendingGif.value = url
    nextTick(() => inputEl.value?.focus())
}

function insertEmote(text) {
    // For standard emoji (single char or multi-char sequence), insert at cursor
    // For custom emotes (:name:), also insert at cursor
    const el     = inputEl.value
    const cursor = el?.selectionStart ?? draft.value.length
    const before = draft.value.substring(0, cursor)
    const after  = draft.value.substring(cursor)
    const spacer = before.length && !before.endsWith(' ') ? ' ' : ''
    draft.value  = before + spacer + text + ' ' + after
    nextTick(() => {
        const pos = (before + spacer + text + ' ').length
        el?.setSelectionRange(pos, pos)
        el?.focus()
    })
}

// ── Send ──────────────────────────────────────────────────────────────────
function send() {
    const text = draft.value.trim()
    const gif  = pendingGif.value

    if (!text && !gif) return

    // Combine text and GIF URL — GIF goes on its own line after any text
    const content = [text, gif].filter(Boolean).join('\n')

    emit('send', { content, replyToId: replyTo.value?.id ?? null })
    draft.value    = ''
    pendingGif.value = null
    replyTo.value  = null
    closeMention()
    nextTick(() => { if (inputEl.value) inputEl.value.style.height = 'auto' })
}

function scrollToMessage(id) {
    const el = messagesEl.value?.querySelector(`[data-msg-id="${id}"]`)
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

function autoResize(e) {
    e.target.style.height = 'auto'
    e.target.style.height = Math.min(e.target.scrollHeight, 200) + 'px'
}
function formatTime(iso) {
    if (!iso) return ''
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}
function initials(name = '') { return name.slice(0, 2).toUpperCase() }

watch(() => props.messages.length, async () => {
    await nextTick()
    if (!messagesEl.value) return
    const newMsg = messagesEl.value.querySelector('.message-group:last-child')
    if (newMsg) gsap.from(newMsg, { opacity: 0, y: 6, duration: 0.18, ease: 'power2.out' })
    messagesEl.value.scrollTo({ top: messagesEl.value.scrollHeight, behavior: 'smooth' })
})
</script>

<style scoped>
.msg-gif {
    display: block;
    max-width: 300px;
    max-height: 200px;
    border-radius: 6px;
    margin-top: 4px;
}

.msg-emote {
    display: inline-block;
    width: 22px;
    height: 22px;
    vertical-align: middle;
    object-fit: contain;
    margin: 0 1px;
}

.gif-attachment {
    position: relative;
    display: inline-block;
    margin: 4px 12px 0;
}
.gif-attachment-thumb {
    display: block;
    max-height: 120px;
    max-width: 200px;
    border-radius: 6px;
    border: 1px solid rgba(255,255,255,0.1);
}
.gif-attachment-remove {
    position: absolute;
    top: -6px;
    right: -6px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: rgba(0,0,0,0.7);
    border: none;
    color: #fff;
    font-size: 10px;
    line-height: 18px;
    text-align: center;
    cursor: pointer;
    padding: 0;
}

.stream-zone {
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}

.stream-offline {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 32px 16px;
    color: #64748b;
    text-align: center;
}
.stream-offline-icon  { font-size: 40px; }
.stream-offline-title { font-size: 15px; font-weight: 600; color: #94a3b8; }
.stream-offline-hint  { font-size: 12px; }

.stream-controls {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    background: rgba(0,0,0,0.15);
    border-top: 1px solid rgba(255,255,255,0.05);
}

.stream-go-live-btn {
    background: rgba(239,68,68,0.15);
    border: 1px solid rgba(239,68,68,0.3);
    color: #f87171;
    font-size: 13px;
    font-weight: 600;
    padding: 6px 14px;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.15s;
}
.stream-go-live-btn:hover:not(:disabled) { background: rgba(239,68,68,0.3); }
.stream-go-live-btn:disabled { opacity: 0.45; cursor: not-allowed; }

.stream-live-indicator {
    font-size: 13px;
    font-weight: 600;
    color: #f87171;
}

.stream-stop-btn {
    background: rgba(239,68,68,0.2);
    border: 1px solid rgba(239,68,68,0.35);
    color: #f87171;
    font-size: 13px;
    font-weight: 600;
    padding: 6px 14px;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.15s;
}
.stream-stop-btn:hover { background: rgba(239,68,68,0.35); }

.stream-error {
    font-size: 12px;
    color: #f87171;
}

/* Source picker modal */
.stream-source-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
}

.stream-source-modal {
    background: var(--bg-secondary, #2b2d31);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 12px;
    padding: 24px;
    width: 340px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.stream-source-title {
    font-size: 15px;
    font-weight: 600;
    color: #e2e8f0;
    text-align: center;
}

.stream-source-options {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.stream-source-opt {
    display: grid;
    grid-template-columns: 36px 1fr;
    grid-template-rows: auto auto;
    column-gap: 10px;
    align-items: start;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 8px;
    padding: 12px 14px;
    cursor: pointer;
    text-align: left;
    transition: background 0.15s, border-color 0.15s;
}
.stream-source-opt:hover {
    background: rgba(255,255,255,0.09);
    border-color: var(--accent, #5865f2);
}

.stream-source-icon {
    grid-row: 1 / 3;
    font-size: 22px;
    line-height: 1;
    display: flex;
    align-items: center;
    padding-top: 2px;
}

.stream-source-label {
    font-size: 13px;
    font-weight: 600;
    color: #e2e8f0;
}

.stream-source-desc {
    font-size: 11px;
    color: rgba(255,255,255,0.45);
    margin-top: 2px;
}

.stream-source-cancel {
    background: transparent;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 6px;
    color: rgba(255,255,255,0.5);
    padding: 7px;
    font-size: 13px;
    cursor: pointer;
    transition: background 0.15s;
}
.stream-source-cancel:hover { background: rgba(255,255,255,0.06); }

.stream-preview-wrap {
    position: relative;
    width: 100%;
    background: #000;
    line-height: 0;
}

.stream-preview-local {
    width: 100%;
    max-height: 360px;
    background: #000;
    object-fit: contain;
    display: block;
}

.stream-sync-overlay {
    position: absolute;
    top: 10px;
    left: 10px;
    background: rgba(0,0,0,0.6);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 20px;
    padding: 4px 12px;
    font-size: 12px;
    font-weight: 600;
    color: rgba(255,255,255,0.7);
    display: flex;
    align-items: center;
    gap: 6px;
    transition: color 0.3s, border-color 0.3s;
    pointer-events: none;
}

.stream-sync-overlay--live {
    color: #f87171;
    border-color: rgba(239,68,68,0.4);
}

.stream-syncing-text {
    display: flex;
    align-items: center;
    gap: 6px;
}

.stream-syncing-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: rgba(255,255,255,0.6);
    animation: pulse-dot 1.2s ease-in-out infinite;
    flex-shrink: 0;
}

@keyframes pulse-dot {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: 0.35; transform: scale(0.75); }
}
</style>
