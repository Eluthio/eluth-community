<template>
    <aside id="sidebar-members">
        <div v-if="channelName" class="member-channel-header">
            <span class="member-channel-hash">#</span>{{ channelName }}
        </div>
        <template v-for="group in grouped" :key="group.label">
            <div class="member-section-label">{{ group.label }} — {{ group.members.length }}</div>
            <div
                v-for="member in group.members"
                :key="member.id"
                class="member-item"
                @click="openProfileFor($event, member)"
                @contextmenu.prevent="openMenu($event, member)"
            >
                <div class="member-avatar">
                    <img v-if="member.avatar_url" :src="member.avatar_url" :alt="member.username" @error="e => e.target.style.display='none'" />
                    <span v-if="!member.avatar_url">{{ initials(member.username) }}</span>
                    <span class="presence-dot" :class="`presence-${member.presence}`" />
                </div>
                <span class="member-name truncate">{{ member.username }}</span>
            </div>
        </template>

        <!-- Context menu -->
        <Teleport to="body">
            <div
                v-if="menu.visible"
                class="ctx-menu"
                :style="{ top: menu.y + 'px', left: menu.x + 'px' }"
                @click.stop
            >
                <div class="ctx-header">{{ menu.member?.username }}</div>
                <button class="ctx-item" @click="doViewProfile">View Profile</button>
                <div class="ctx-separator" />
                <template v-if="currentMember">
                    <button
                        v-if="currentMember.can('kick_members') && menu.member?.id !== currentMember.id"
                        class="ctx-item"
                        @click="emit('kick', menu.member.id); closeMenu()"
                    >Kick</button>
                    <button
                        v-if="currentMember.can('ban_members') && menu.member?.id !== currentMember.id"
                        class="ctx-item ctx-item--danger"
                        @click="emit('ban', menu.member.id); closeMenu()"
                    >Ban</button>
                </template>
            </div>
        </Teleport>
    </aside>
</template>

<script setup>
import { computed, reactive, onMounted, onUnmounted } from 'vue'

const props = defineProps({
    members:       { type: Array,  default: () => [] },
    currentMember: { type: Object, default: null },
    channelName:   { type: String, default: null },
})

const emit = defineEmits(['kick', 'ban', 'view-profile'])

const canSeeOffline = computed(() =>
    props.currentMember?.can('kick_members') ||
    props.currentMember?.can('ban_members') ||
    props.currentMember?.can('manage_member_roles')
)

const grouped = computed(() => {
    const order  = canSeeOffline.value ? ['online', 'idle', 'dnd', 'offline'] : ['online', 'idle', 'dnd']
    const labels = { online: 'Online', idle: 'Idle', dnd: 'Do Not Disturb', offline: 'Offline' }
    return order
        .map(p => ({ label: labels[p], members: props.members.filter(m => m.presence === p) }))
        .filter(g => g.members.length > 0)
})

function initials(name = '') { return name.slice(0, 2).toUpperCase() }

const menu = reactive({ visible: false, x: 0, y: 0, member: null })

function openProfileFor(event, member) {
    emit('view-profile', { username: member.username, anchorX: event.clientX, anchorY: event.clientY })
}

function openMenu(event, member) {
    event.stopPropagation()
    const x      = Math.min(event.clientX, window.innerWidth - 160)
    menu.visible = true
    menu.x       = x
    menu.y       = event.clientY
    menu.member  = member
}

function doViewProfile() {
    if (menu.member) {
        emit('view-profile', { username: menu.member.username, anchorX: menu.x, anchorY: menu.y })
    }
    closeMenu()
}

function closeMenu() { menu.visible = false }

onMounted(() => {
    document.addEventListener('keydown', onEsc)
    document.addEventListener('click', closeMenu)
    document.addEventListener('contextmenu', closeMenu)
})
onUnmounted(() => {
    document.removeEventListener('keydown', onEsc)
    document.removeEventListener('click', closeMenu)
    document.removeEventListener('contextmenu', closeMenu)
})
function onEsc(e) { if (e.key === 'Escape') closeMenu() }
</script>
