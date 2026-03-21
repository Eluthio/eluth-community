<template>
    <div id="main">
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

const props = defineProps({
    channelName:   { type: String, default: 'general' },
    channelTopic:  { type: String, default: null },
    messages:      { type: Array,  default: () => [] },
    members:       { type: Array,  default: () => [] },
    currentMember: { type: Object, default: null },
})
const emit = defineEmits(['send', 'kick', 'ban', 'open-dm', 'open-user-settings', 'view-profile'])

const draft      = ref('')
const replyTo    = ref(null)   // { id, author, preview }
const messagesEl = ref(null)
const inputEl    = ref(null)

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
function renderContent(content) {
    // Escape HTML first
    const escaped = content
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
    // Highlight @mentions
    return escaped.replace(/@(everyone|here|\w+)/g, (match, name) => {
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
})
function onDocEsc(e) { if (e.key === 'Escape') { closeMenu(); closeMention() } }

// ── Send ──────────────────────────────────────────────────────────────────
function send() {
    const content = draft.value.trim()
    if (!content) return
    emit('send', { content, replyToId: replyTo.value?.id ?? null })
    draft.value = ''
    replyTo.value = null
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
