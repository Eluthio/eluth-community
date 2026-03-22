<template>
    <div class="wp-wrap" ref="wrapRef">
        <!-- Toggle button — shows badge if there are proposals -->
        <button
            class="wp-btn"
            :class="{ active: open }"
            @click="toggle"
            title="Watch Party"
        >
            🍿
            <span v-if="proposals.length > 0" class="wp-badge">{{ proposals.length }}</span>
        </button>

        <!-- Panel -->
        <Teleport to="body">
        <div v-if="open" class="wp-panel" :style="panelStyle" @click.stop>
            <div class="wp-panel-header">
                <span class="wp-title">🍿 Watch Party</span>
                <button class="wp-close" @click="open = false">✕</button>
            </div>

            <!-- Proposal list -->
            <div v-if="proposals.length === 0 && !loading" class="wp-empty">
                No proposals yet. Be the first!
            </div>
            <div v-if="loading && proposals.length === 0" class="wp-loading">Loading…</div>

            <div class="wp-proposals">
                <div
                    v-for="p in proposals"
                    :key="p.id"
                    class="wp-proposal"
                    :class="{ 'wp-proposal--top': proposals[0]?.id === p.id }"
                >
                    <div class="wp-proposal-meta">
                        <a :href="p.url" target="_blank" rel="noopener" class="wp-proposal-title">
                            {{ p.title || shortenUrl(p.url) }}
                        </a>
                        <span class="wp-proposal-by">by {{ p.proposed_by }}</span>
                    </div>
                    <div class="wp-proposal-actions">
                        <button
                            class="wp-vote-btn"
                            :class="{ voted: p.voted }"
                            @click="vote(p)"
                            :disabled="voting === p.id"
                        >
                            <span>👍</span>
                            <span class="wp-vote-count">{{ p.votes }}</span>
                        </button>
                        <a :href="p.url" target="_blank" rel="noopener" class="wp-open-btn" title="Open link">↗</a>
                        <button
                            v-if="p.is_mine || isAdmin"
                            class="wp-del-btn"
                            @click="remove(p)"
                            title="Remove"
                        >✕</button>
                    </div>
                </div>
            </div>

            <!-- Add proposal form -->
            <form class="wp-form" @submit.prevent="propose">
                <input
                    v-model="newUrl"
                    class="wp-input"
                    type="url"
                    placeholder="Paste a link to propose…"
                    required
                />
                <input
                    v-model="newTitle"
                    class="wp-input"
                    type="text"
                    placeholder="Title (optional)"
                    maxlength="128"
                />
                <div class="wp-form-footer">
                    <button class="wp-submit-btn" type="submit" :disabled="proposing">
                        {{ proposing ? 'Adding…' : 'Propose' }}
                    </button>
                    <button
                        v-if="isAdmin && proposals.length > 0"
                        type="button"
                        class="wp-clear-btn"
                        @click="clearAll"
                    >Clear all</button>
                </div>
                <div v-if="formError" class="wp-form-error">{{ formError }}</div>
            </form>
        </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'

const props = defineProps({
    settings:    { type: Object, default: () => ({}) },
    authToken:   { type: String, default: '' },
    apiBase:     { type: String, default: '' },
    channelId:   { type: String, default: '' },
    currentMember: { type: Object, default: null },
})

const open       = ref(false)
const proposals  = ref([])
const loading    = ref(false)
const voting     = ref(null)
const proposing  = ref(false)
const newUrl     = ref('')
const newTitle   = ref('')
const formError  = ref('')
const wrapRef    = ref(null)
const panelStyle = ref({})

const isAdmin = computed(() => props.currentMember?.role === 'admin' || props.currentMember?.role === 'owner')

let pollTimer = null

function base() { return props.apiBase.replace(/\/$/, '') }

function headers() {
    return { Authorization: 'Bearer ' + props.authToken, 'Content-Type': 'application/json' }
}

function shortenUrl(url) {
    try {
        const u = new URL(url)
        return u.hostname + (u.pathname !== '/' ? u.pathname.slice(0, 40) : '')
    } catch {
        return url.slice(0, 50)
    }
}

async function fetchProposals() {
    if (!props.channelId) return
    loading.value = proposals.value.length === 0
    try {
        const res = await fetch(`${base()}/api/plugins/watch-party/proposals?channel_id=${props.channelId}`, {
            headers: { Authorization: 'Bearer ' + props.authToken },
        })
        if (res.ok) {
            const data = await res.json()
            proposals.value = data.proposals ?? []
        }
    } finally {
        loading.value = false
    }
}

async function vote(p) {
    voting.value = p.id
    try {
        const res = await fetch(`${base()}/api/plugins/watch-party/proposals/${p.id}/vote`, {
            method: 'POST',
            headers: headers(),
        })
        if (res.ok) {
            const data = await res.json()
            p.voted = data.voted
            p.votes = data.votes
            // Re-sort by votes
            proposals.value = [...proposals.value].sort((a, b) => b.votes - a.votes)
        }
    } finally {
        voting.value = null
    }
}

async function propose() {
    if (!newUrl.value.trim()) return
    formError.value = ''
    proposing.value = true
    try {
        const res = await fetch(`${base()}/api/plugins/watch-party/proposals`, {
            method: 'POST',
            headers: headers(),
            body: JSON.stringify({
                channel_id: props.channelId,
                url: newUrl.value.trim(),
                title: newTitle.value.trim() || null,
            }),
        })
        const data = await res.json()
        if (!res.ok) {
            formError.value = data.message || 'Failed to add proposal.'
            return
        }
        newUrl.value   = ''
        newTitle.value = ''
        await fetchProposals()
    } catch {
        formError.value = 'Network error.'
    } finally {
        proposing.value = false
    }
}

async function remove(p) {
    await fetch(`${base()}/api/plugins/watch-party/proposals/${p.id}`, {
        method: 'DELETE',
        headers: headers(),
    })
    proposals.value = proposals.value.filter(x => x.id !== p.id)
}

async function clearAll() {
    if (!confirm('Clear all watch party proposals?')) return
    await fetch(`${base()}/api/plugins/watch-party/proposals?channel_id=${props.channelId}`, {
        method: 'DELETE',
        headers: headers(),
    })
    proposals.value = []
}

function positionPanel() {
    const rect = wrapRef.value?.getBoundingClientRect()
    if (!rect) return
    panelStyle.value = {
        left:   Math.max(8, Math.min(rect.left, window.innerWidth - 360)) + 'px',
        bottom: (window.innerHeight - rect.top + 8) + 'px',
    }
}

function toggle() {
    open.value = !open.value
    if (open.value) {
        positionPanel()
        fetchProposals()
        pollTimer = setInterval(fetchProposals, 5000)
    } else {
        clearInterval(pollTimer)
    }
}

function onClickOutside(e) {
    if (open.value && !wrapRef.value?.contains(e.target) && !document.querySelector('.wp-panel')?.contains(e.target)) {
        open.value = false
        clearInterval(pollTimer)
    }
}

onMounted(() => document.addEventListener('click', onClickOutside))
onUnmounted(() => {
    document.removeEventListener('click', onClickOutside)
    clearInterval(pollTimer)
})
</script>

<style scoped>
.wp-wrap { position: relative; display: inline-flex; align-items: center; }

.wp-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 4px 6px;
    border-radius: 6px;
    font-size: 17px;
    line-height: 1;
    color: rgba(255,255,255,.7);
    transition: background .15s;
    position: relative;
    display: flex;
    align-items: center;
    gap: 3px;
}
.wp-btn:hover, .wp-btn.active { background: rgba(251,191,36,.12); }

.wp-badge {
    font-size: 10px;
    font-weight: 700;
    background: #f59e0b;
    color: #000;
    border-radius: 8px;
    padding: 1px 5px;
    line-height: 1.4;
}

.wp-panel {
    position: fixed;
    width: 340px;
    max-height: 480px;
    background: #1a1d26;
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 12px;
    box-shadow: 0 16px 48px rgba(0,0,0,.6);
    display: flex;
    flex-direction: column;
    z-index: 9999;
    overflow: hidden;
}

.wp-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px 10px;
    border-bottom: 1px solid rgba(255,255,255,.06);
    flex-shrink: 0;
}
.wp-title { font-size: 14px; font-weight: 700; color: rgba(255,255,255,.9); }
.wp-close { background: none; border: none; cursor: pointer; color: rgba(255,255,255,.4); font-size: 14px; padding: 2px 6px; border-radius: 4px; }
.wp-close:hover { color: rgba(255,255,255,.8); background: rgba(255,255,255,.06); }

.wp-empty, .wp-loading {
    padding: 20px;
    text-align: center;
    font-size: 13px;
    color: rgba(255,255,255,.3);
}

.wp-proposals {
    flex: 1;
    overflow-y: auto;
    padding: 8px 0;
}

.wp-proposal {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    border-radius: 6px;
    transition: background .1s;
}
.wp-proposal:hover { background: rgba(255,255,255,.03); }
.wp-proposal--top { background: rgba(251,191,36,.05); }

.wp-proposal-meta { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.wp-proposal-title {
    font-size: 13px;
    font-weight: 500;
    color: #67e8f9;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    text-decoration: none;
}
.wp-proposal-title:hover { text-decoration: underline; }
.wp-proposal-by { font-size: 11px; color: rgba(255,255,255,.3); }

.wp-proposal-actions { display: flex; gap: 4px; align-items: center; flex-shrink: 0; }

.wp-vote-btn {
    display: flex;
    align-items: center;
    gap: 4px;
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 6px;
    padding: 3px 8px;
    font-size: 12px;
    cursor: pointer;
    color: rgba(255,255,255,.6);
    transition: all .15s;
}
.wp-vote-btn:hover { background: rgba(251,191,36,.15); border-color: rgba(251,191,36,.3); color: #fcd34d; }
.wp-vote-btn.voted { background: rgba(251,191,36,.2); border-color: #f59e0b; color: #fcd34d; }
.wp-vote-count { font-weight: 700; }

.wp-open-btn {
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 6px;
    padding: 3px 7px;
    font-size: 12px;
    color: rgba(255,255,255,.4);
    text-decoration: none;
    transition: all .15s;
}
.wp-open-btn:hover { color: rgba(255,255,255,.8); background: rgba(255,255,255,.1); }

.wp-del-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 3px 6px;
    border-radius: 4px;
    font-size: 11px;
    color: rgba(255,255,255,.25);
}
.wp-del-btn:hover { color: #f87171; background: rgba(248,113,113,.1); }

.wp-form {
    border-top: 1px solid rgba(255,255,255,.06);
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex-shrink: 0;
}
.wp-input {
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 7px;
    padding: 7px 10px;
    font-size: 13px;
    color: rgba(255,255,255,.85);
    width: 100%;
}
.wp-input:focus { outline: none; border-color: rgba(251,191,36,.4); }
.wp-input::placeholder { color: rgba(255,255,255,.3); }

.wp-form-footer { display: flex; gap: 6px; align-items: center; }

.wp-submit-btn {
    background: rgba(251,191,36,.15);
    border: 1px solid rgba(251,191,36,.3);
    border-radius: 7px;
    padding: 6px 14px;
    font-size: 13px;
    font-weight: 600;
    color: #fcd34d;
    cursor: pointer;
    transition: all .15s;
}
.wp-submit-btn:hover { background: rgba(251,191,36,.25); }
.wp-submit-btn:disabled { opacity: .5; cursor: default; }

.wp-clear-btn {
    background: none;
    border: 1px solid rgba(248,113,113,.3);
    border-radius: 7px;
    padding: 6px 10px;
    font-size: 12px;
    color: #f87171;
    cursor: pointer;
    transition: all .15s;
    margin-left: auto;
}
.wp-clear-btn:hover { background: rgba(248,113,113,.1); }

.wp-form-error { font-size: 12px; color: #f87171; }
</style>
