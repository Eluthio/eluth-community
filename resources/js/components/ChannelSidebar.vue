<template>
    <aside id="nav">
        <!-- Server switcher -->
        <div class="server-switcher">
            <div
                v-for="server in servers"
                :key="server.id"
                class="server-item"
                :class="{ active: server.id === activeServerId }"
                :title="server.name"
                @click="$emit('select-server', server.id)"
                @contextmenu.prevent="openServerMenu($event, server)"
            >
                <div class="server-item-icon">
                    <img v-if="server.icon" :src="server.icon" :alt="server.name" />
                    <span v-else>{{ abbr(server.name) }}</span>
                </div>
                <span class="server-item-name truncate">{{ server.name }}</span>
            </div>
            <button class="server-add-btn" title="Join or create a server" @click="$emit('add-server')">
                <span class="server-add-icon">+</span>
                <span>Join or create a server</span>
            </button>
        </div>

        <!-- Channel tree -->
        <div id="nav-channels">
            <template v-for="section in sections" :key="section.label">
                <div class="nav-section-label">{{ section.label }}</div>
                <div
                    v-for="channel in section.channels"
                    :key="channel.id"
                    class="channel-item"
                    :class="{
                        active:   channel.id === activeChannelId,
                        unread:   unreadByChannel[channel.id]?.msgs > 0,
                        mention:  unreadByChannel[channel.id]?.mentions > 0,
                    }"
                    @click="$emit('select-channel', channel)"
                >
                    <svg v-if="channel.type === 'voice' || channel.type === 'video'" class="ch-wave" viewBox="0 0 14 10" fill="none">
                        <path d="M1 5 Q2.5 1 4 5 Q5.5 9 7 5 Q8.5 1 10 5 Q11.5 9 13 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" fill="none"/>
                    </svg>
                    <span v-else-if="channel.type === 'announcement'" class="ch-icon">📢</span>
                    <span v-else class="channel-type-dot" />
                    <span class="truncate">{{ channel.name }}</span>
                    <span
                        v-if="unreadByChannel[channel.id]?.mentions > 0"
                        class="ch-badge ch-badge--mention"
                    >{{ unreadByChannel[channel.id].mentions > 9 ? '9+' : unreadByChannel[channel.id].mentions }}</span>
                    <span
                        v-else-if="unreadByChannel[channel.id]?.msgs > 0"
                        class="ch-badge"
                    />
                </div>
            </template>
        </div>

        <!-- User panel -->
        <div id="user-panel">
            <div
                class="user-panel-avatar"
                style="cursor:pointer;"
                title="View your profile"
                @click="onAvatarClick"
            >
                <img v-if="user.avatar_url" :src="user.avatar_url" :alt="user.username" @error="e => e.target.style.display='none'" />
                <span v-else>{{ abbr(user.username) }}</span>
            </div>
            <div style="flex:1;min-width:0;">
                <div class="user-panel-name truncate">{{ user.username }}</div>
                <div class="user-panel-tag">Online</div>
            </div>
            <button title="Settings" class="input-action" style="font-size:15px;" @click="$emit('open-user-settings')">⚙</button>
        </div>

        <!-- Server context menu -->
        <Teleport to="body">
            <div
                v-if="serverMenu.visible"
                class="ctx-menu"
                :style="{ top: serverMenu.y + 'px', left: serverMenu.x + 'px' }"
                @click.stop
            >
                <div class="ctx-header">{{ serverMenu.server?.name }}</div>
                <button class="ctx-item" @click="$emit('open-settings'); closeServerMenu()">
                    Settings
                </button>
            </div>
        </Teleport>
    </aside>
</template>

<script setup>
import { reactive, onMounted, onUnmounted } from 'vue'

const emit = defineEmits(['select-server', 'add-server', 'select-channel', 'open-settings', 'open-user-settings', 'view-profile'])

const props = defineProps({
    servers:          { type: Array,  default: () => [] },
    activeServerId:   { type: String, default: null },
    sections:         { type: Array,  default: () => [] },
    activeChannelId:  { type: String, default: null },
    user:             { type: Object, default: () => ({ username: '?' }) },
    unreadByChannel:  { type: Object, default: () => ({}) },
})

function onAvatarClick(e) {
    emit('view-profile', {
        username: props.user.username,
        anchorX: e.clientX,
        anchorY: e.clientY,
    })
}

function abbr(name = '') {
    const words = name.trim().split(/\s+/)
    return words.length === 1
        ? name.slice(0, 2).toUpperCase()
        : words.map(w => w[0]).join('').slice(0, 2).toUpperCase()
}

const serverMenu = reactive({ visible: false, x: 0, y: 0, server: null })

function openServerMenu(event, server) {
    event.stopPropagation()
    const x = Math.min(event.clientX, window.innerWidth - 180)
    serverMenu.visible = true
    serverMenu.x       = x
    serverMenu.y       = event.clientY
    serverMenu.server  = server
}

function closeServerMenu() { serverMenu.visible = false }

onMounted(() => {
    document.addEventListener('click', closeServerMenu)
    document.addEventListener('contextmenu', closeServerMenu)
    document.addEventListener('keydown', onEsc)
})
onUnmounted(() => {
    document.removeEventListener('click', closeServerMenu)
    document.removeEventListener('contextmenu', closeServerMenu)
    document.removeEventListener('keydown', onEsc)
})
function onEsc(e) { if (e.key === 'Escape') closeServerMenu() }
</script>
