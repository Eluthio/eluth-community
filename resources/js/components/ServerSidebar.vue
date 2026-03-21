<template>
    <nav id="sidebar-servers" class="glass">
        <div
            v-for="server in servers"
            :key="server.id"
            class="server-icon"
            :class="{ active: server.id === activeId }"
            :title="server.name"
            @click="$emit('select', server.id)"
        >
            <img v-if="server.icon" :src="server.icon" :alt="server.name" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;" />
            <span v-else>{{ initials(server.name) }}</span>
        </div>

        <div style="width:24px;height:1px;background:rgba(255,255,255,0.07);margin:4px 0;" />

        <button class="server-icon" title="Join or create a server" @click="$emit('add')" style="font-size:20px;font-weight:300;">
            +
        </button>
    </nav>
</template>

<script setup>
defineProps({
    servers:  { type: Array,  default: () => [] },
    activeId: { type: String, default: null },
})
defineEmits(['select', 'add'])

function initials(name) {
    return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
}
</script>
