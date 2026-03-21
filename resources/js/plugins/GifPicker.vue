<template>
    <div class="gif-picker-wrap" ref="wrapRef">
        <button
            class="gif-btn"
            :class="{ active: open }"
            title="Insert GIF"
            @click.stop="toggle"
        >GIF</button>

        <Teleport to="body">
            <div v-if="open" class="gif-panel" :style="panelStyle" @click.stop>
                <div class="gif-panel-header">
                    <input
                        v-model="query"
                        class="gif-search"
                        placeholder="Search GIFs…"
                        @input="onSearchInput"
                        @keydown.enter.prevent="search"
                        ref="searchInput"
                    />
                </div>

                <div v-if="!hasKey" class="gif-no-key">
                    No Giphy API key configured.<br>
                    <small>An admin can add one in <strong>Settings → Plugins</strong>.</small>
                </div>

                <div v-else-if="loading" class="gif-loading">Searching…</div>

                <div v-else-if="results.length === 0 && query" class="gif-empty">No results.</div>

                <div v-else class="gif-grid">
                    <img
                        v-for="gif in results"
                        :key="gif.id"
                        :src="gif.preview"
                        :title="gif.title"
                        class="gif-thumb"
                        loading="lazy"
                        @click="pick(gif)"
                    />
                </div>

                <div class="gif-attribution">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/2/23/Giphy-logo.svg/320px-Giphy-logo.svg.png" alt="Powered by GIPHY" class="giphy-logo" />
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue'

const props = defineProps({
    settings:  { type: Object, default: () => ({}) },
    authToken: { type: String, default: '' },
    apiBase:   { type: String, default: '' },
})
const emit = defineEmits(['insert'])

const wrapRef    = ref(null)
const searchInput = ref(null)
const open       = ref(false)
const query      = ref('')
const results    = ref([])
const loading    = ref(false)
const panelStyle = ref({})

let debounceTimer = null

const hasKey = computed(() => !!props.settings?.giphy_key)

function toggle() {
    if (!open.value) {
        const rect = wrapRef.value.getBoundingClientRect()
        const left = Math.max(8, Math.min(rect.left, window.innerWidth - 376))
        panelStyle.value = {
            left:   left + 'px',
            bottom: (window.innerHeight - rect.top + 8) + 'px',
        }
        open.value = true
        if (hasKey.value) {
            search()
            nextTick(() => searchInput.value?.focus())
        }
    } else {
        close()
    }
}

function close() {
    open.value = false
    query.value = ''
    results.value = []
}

function onSearchInput() {
    clearTimeout(debounceTimer)
    debounceTimer = setTimeout(search, 400)
}

async function search() {
    if (!hasKey.value) return
    loading.value = true
    results.value = []
    try {
        const q        = query.value.trim()
        const base     = props.apiBase.replace(/\/$/, '')
        const endpoint = base + (q ? `/api/plugins/gif-picker/search?q=${encodeURIComponent(q)}` : '/api/plugins/gif-picker/trending')
        const token    = props.authToken ?? ''
        const res      = await fetch(endpoint, {
            headers: { Authorization: 'Bearer ' + token },
        })
        if (!res.ok) return
        const data = await res.json()
        results.value = data.gifs ?? []
    } catch {
        results.value = []
    } finally {
        loading.value = false
    }
}

function pick(gif) {
    emit('insert', gif.url)
    close()
}

function onClickOutside(e) {
    if (open.value && wrapRef.value && !wrapRef.value.contains(e.target)) {
        close()
    }
}

onMounted(() => document.addEventListener('click', onClickOutside))
onUnmounted(() => {
    document.removeEventListener('click', onClickOutside)
    clearTimeout(debounceTimer)
})
</script>

<style scoped>
.gif-picker-wrap {
    position: relative;
    display: inline-flex;
    align-items: center;
}

.gif-btn {
    background: transparent;
    border: 1px solid var(--accent, #5865f2);
    color: var(--accent, #5865f2);
    border-radius: 4px;
    padding: 2px 7px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    line-height: 20px;
    transition: background 0.15s, color 0.15s;
}
.gif-btn:hover, .gif-btn.active {
    background: var(--accent, #5865f2);
    color: #fff;
}

.gif-panel {
    position: fixed;
    width: 360px;
    max-height: 440px;
    background: var(--bg-secondary, #2b2d31);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    z-index: 9999;
    box-shadow: 0 8px 32px rgba(0,0,0,0.5);
}

.gif-panel-header {
    padding: 8px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}

.gif-search {
    width: 100%;
    background: rgba(0,0,0,0.2);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 4px;
    color: #fff;
    padding: 5px 8px;
    font-size: 13px;
    outline: none;
    box-sizing: border-box;
}
.gif-search:focus { border-color: var(--accent, #5865f2); }

.gif-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 3px;
    padding: 8px;
    overflow-y: auto;
    flex: 1;
}

.gif-thumb {
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
    border-radius: 4px;
    cursor: pointer;
    transition: opacity 0.15s, transform 0.15s;
}
.gif-thumb:hover { opacity: 0.85; transform: scale(1.03); }

.gif-loading, .gif-empty, .gif-no-key {
    padding: 24px;
    text-align: center;
    color: rgba(255,255,255,0.4);
    font-size: 13px;
    flex: 1;
}

.gif-attribution {
    padding: 6px 8px;
    border-top: 1px solid rgba(255,255,255,0.06);
    display: flex;
    justify-content: flex-end;
}
.giphy-logo {
    height: 14px;
    opacity: 0.5;
}
</style>
