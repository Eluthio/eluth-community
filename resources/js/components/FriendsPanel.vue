<template>
    <div class="side-panel" @click.stop>
        <div class="side-panel-header">
            <span>Friends</span>
            <button class="side-panel-close" @click="emit('close')">✕</button>
        </div>

        <!-- Pending requests -->
        <div v-if="requests.length > 0" class="side-panel-section">
            <div class="side-panel-label">Pending — {{ requests.length }}</div>
            <div v-for="r in requests" :key="r.id" class="friend-row">
                <div class="friend-avatar">
                    <img v-if="r.avatar_url" :src="r.avatar_url" :alt="r.username" @error="e => e.target.style.display='none'" />
                    <span v-else>{{ initials(r.username) }}</span>
                </div>
                <span class="friend-name">{{ r.username }}</span>
                <div class="friend-actions">
                    <button class="friend-btn friend-btn--accept" @click="accept(r.id)" title="Accept">✓</button>
                    <button class="friend-btn friend-btn--decline" @click="decline(r.id)" title="Decline">✕</button>
                </div>
            </div>
        </div>

        <!-- Friends list -->
        <div class="side-panel-section">
            <div class="side-panel-label">Friends — {{ friends.length }}</div>
            <div v-if="friends.length === 0" class="side-panel-empty">No friends yet.</div>
            <div v-for="f in friends" :key="f.id" class="friend-row">
                <div class="friend-avatar">
                    <img v-if="f.avatar_url" :src="f.avatar_url" :alt="f.username" @error="e => e.target.style.display='none'" />
                    <span v-else>{{ initials(f.username) }}</span>
                </div>
                <span class="friend-name">{{ f.username }}</span>
                <div class="friend-actions">
                    <button class="friend-btn" @click="emit('open-dm', f)" title="Message">✉</button>
                    <button class="friend-btn friend-btn--danger" @click="removeFriend(f.id)" title="Remove">✕</button>
                </div>
            </div>
        </div>

        <div v-if="error" class="side-panel-error">{{ error }}</div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'

const props = defineProps({
    centralUrl: { type: String, required: true },
    token:      { type: String, required: true },
})
const emit = defineEmits(['close', 'open-dm'])

const friends  = ref([])
const requests = ref([])
const error    = ref('')

async function api(method, path, body) {
    const res = await fetch(props.centralUrl + '/api' + path, {
        method,
        headers: {
            Authorization:  'Bearer ' + props.token,
            Accept:         'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    })
    if (!res.ok) throw new Error(await res.text())
    if (res.status === 204) return null
    return res.json()
}

async function load() {
    try {
        const [fl, rl] = await Promise.all([
            api('GET', '/friends'),
            api('GET', '/friends/requests'),
        ])
        friends.value  = fl.friends
        requests.value = rl.requests
    } catch {
        error.value = 'Failed to load friends.'
    }
}

async function accept(userId) {
    await api('POST', '/friends/accept/' + userId)
    await load()
}

async function decline(userId) {
    await api('POST', '/friends/decline/' + userId)
    requests.value = requests.value.filter(r => r.id !== userId)
}

async function removeFriend(userId) {
    await api('DELETE', '/friends/' + userId)
    friends.value = friends.value.filter(f => f.id !== userId)
}

function initials(name = '') { return name.slice(0, 2).toUpperCase() }

onMounted(load)
</script>
