<template>
    <div class="gif-picker-wrap">
        <button
            class="gif-btn"
            :class="{ active: open }"
            title="Insert GIF"
            @click.stop="toggle"
        >GIF</button>

        <div v-if="open" class="gif-panel" @click.stop>
            <div class="gif-panel-header">
                <div class="gif-tabs">
                    <button
                        v-for="p in availableProviders"
                        :key="p.id"
                        class="gif-tab"
                        :class="{ active: provider === p.id }"
                        @click="provider = p.id; search()"
                    >{{ p.label }}</button>
                </div>
                <input
                    v-model="query"
                    class="gif-search"
                    placeholder="Search GIFs…"
                    @input="onSearchInput"
                    @keydown.enter.prevent="search"
                    autofocus
                />
            </div>

            <div v-if="!hasKey" class="gif-no-key">
                No API key configured. An admin must add a Tenor or Giphy key in Plugin Settings.
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
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['insert'])

const open     = ref(false)
const query    = ref('')
const results  = ref([])
const loading  = ref(false)
const provider = ref('')

let debounceTimer = null

const availableProviders = computed(() => {
    const list = []
    if (props.settings?.tenor_key) list.push({ id: 'tenor', label: 'Tenor' })
    if (props.settings?.giphy_key) list.push({ id: 'giphy', label: 'Giphy' })
    return list
})

const hasKey = computed(() => availableProviders.value.length > 0)

function toggle() {
    open.value = !open.value
    if (open.value && !provider.value && availableProviders.value.length) {
        provider.value = availableProviders.value[0].id
        search()
    }
}

function onSearchInput() {
    clearTimeout(debounceTimer)
    debounceTimer = setTimeout(search, 400)
}

async function search() {
    if (!hasKey.value) return
    if (!provider.value && availableProviders.value.length) {
        provider.value = availableProviders.value[0].id
    }
    loading.value = true
    results.value = []
    try {
        if (provider.value === 'tenor') {
            results.value = await searchTenor()
        } else {
            results.value = await searchGiphy()
        }
    } catch {
        results.value = []
    } finally {
        loading.value = false
    }
}

async function searchTenor() {
    const q   = query.value || 'trending'
    const key = props.settings.tenor_key
    const url = `https://tenor.googleapis.com/v2/search?q=${encodeURIComponent(q)}&key=${encodeURIComponent(key)}&limit=24&media_filter=gif`
    const res = await fetch(url)
    if (!res.ok) return []
    const data = await res.json()
    return (data.results ?? []).map(r => ({
        id:      r.id,
        title:   r.title || '',
        preview: r.media_formats?.tinygif?.url ?? r.media_formats?.gif?.url ?? '',
        url:     r.media_formats?.gif?.url ?? '',
    })).filter(g => g.url)
}

async function searchGiphy() {
    const q   = query.value || 'trending'
    const key = props.settings.giphy_key
    const endpoint = query.value
        ? `https://api.giphy.com/v1/gifs/search?q=${encodeURIComponent(q)}&api_key=${encodeURIComponent(key)}&limit=24&rating=g`
        : `https://api.giphy.com/v1/gifs/trending?api_key=${encodeURIComponent(key)}&limit=24&rating=g`
    const res = await fetch(endpoint)
    if (!res.ok) return []
    const data = await res.json()
    return (data.data ?? []).map(r => ({
        id:      r.id,
        title:   r.title || '',
        preview: r.images?.fixed_height_small?.url ?? r.images?.original?.url ?? '',
        url:     r.images?.original?.url ?? '',
    })).filter(g => g.url)
}

function pick(gif) {
    emit('insert', gif.url)
    open.value = false
    query.value = ''
    results.value = []
}

function onClickOutside(e) {
    if (open.value) open.value = false
}

onMounted(() => document.addEventListener('click', onClickOutside))
onUnmounted(() => document.removeEventListener('click', onClickOutside))
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
    position: absolute;
    bottom: calc(100% + 8px);
    left: 0;
    width: 360px;
    max-height: 420px;
    background: var(--bg-secondary, #2b2d31);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    z-index: 1000;
    box-shadow: 0 8px 32px rgba(0,0,0,0.4);
}

.gif-panel-header {
    padding: 8px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}

.gif-tabs {
    display: flex;
    gap: 4px;
}

.gif-tab {
    background: transparent;
    border: 1px solid rgba(255,255,255,0.15);
    color: rgba(255,255,255,0.6);
    border-radius: 4px;
    padding: 2px 10px;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.15s;
}
.gif-tab.active, .gif-tab:hover {
    border-color: var(--accent, #5865f2);
    color: var(--accent, #5865f2);
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
}
</style>
