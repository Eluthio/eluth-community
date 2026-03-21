<template>
    <div class="emote-picker-wrap" ref="wrapRef">
        <button class="emote-btn" :class="{ active: open }" title="Emoji & Emotes" @click.stop="toggle">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
                <circle cx="12" cy="12" r="10"/>
                <path d="M8 14s1.5 2 4 2 4-2 4-2"/>
                <line x1="9" y1="9" x2="9.01" y2="9"/>
                <line x1="15" y1="9" x2="15.01" y2="9"/>
            </svg>
        </button>

        <Teleport to="body">
            <div v-if="open" class="emote-panel" :style="panelStyle" @click.stop>

                <!-- Search -->
                <div class="emote-panel-header">
                    <input
                        v-model="search"
                        class="emote-search"
                        placeholder="Search emoji or emotes…"
                        ref="searchInput"
                        @input="onSearch"
                    />
                </div>

                <!-- Category tabs (hidden during search) -->
                <div v-if="!search" class="emote-cats">
                    <button
                        v-for="cat in allCategories"
                        :key="cat.id"
                        class="emote-cat-btn"
                        :class="{ active: activeCat === cat.id }"
                        :title="cat.title"
                        @click="activeCat = cat.id"
                    >{{ cat.label }}</button>
                </div>

                <!-- Content area -->
                <div class="emote-grid-wrap">
                    <!-- Search results -->
                    <template v-if="search">
                        <div class="emote-section-title">Results</div>
                        <div class="emote-grid" v-if="searchResults.length">
                            <template v-for="item in searchResults" :key="item.key">
                                <!-- Custom emote result -->
                                <button v-if="item.type === 'custom'" class="emote-item emote-item--custom" @click="pickCustom(item)" :title="':' + item.name + ':'">
                                    <img :src="item.url" :alt="item.name" loading="lazy" />
                                </button>
                                <!-- Standard emoji result -->
                                <button v-else class="emote-item" @click="pickEmoji(item.e)">{{ item.e }}</button>
                            </template>
                        </div>
                        <div v-else class="emote-empty">No results for "{{ search }}"</div>
                    </template>

                    <!-- Recent -->
                    <template v-else-if="activeCat === 'recent'">
                        <div class="emote-section-title">Recently Used</div>
                        <div class="emote-grid" v-if="recent.length">
                            <button v-for="e in recent" :key="e" class="emote-item" @click="pickEmoji(e)">{{ e }}</button>
                        </div>
                        <div v-else class="emote-empty">No recently used emoji yet.</div>
                    </template>

                    <!-- Server emotes -->
                    <template v-else-if="activeCat === 'server'">
                        <div class="emote-section-title">Server Emotes</div>
                        <div v-if="emotesLoading" class="emote-empty">Loading…</div>
                        <div v-else-if="serverEmotes.length" class="emote-grid emote-grid--custom">
                            <button
                                v-for="emote in serverEmotes"
                                :key="emote.name"
                                class="emote-item emote-item--custom"
                                @click="pickCustom(emote)"
                                :title="':' + emote.name + ':'"
                            >
                                <img :src="emote.url" :alt="emote.name" loading="lazy" />
                            </button>
                        </div>
                        <div v-else class="emote-empty">No server emotes yet.<br><small>An admin can add them in Settings → Emotes.</small></div>
                    </template>

                    <!-- Standard emoji category -->
                    <template v-else>
                        <div class="emote-section-title">{{ activeCategoryData?.title }}</div>
                        <div class="emote-grid">
                            <button
                                v-for="e in activeCategoryData?.emoji"
                                :key="e"
                                class="emote-item"
                                @click="pickEmoji(e)"
                            >{{ e }}</button>
                        </div>
                    </template>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue'
import { EMOJI_CATEGORIES } from './emojis.js'

const props = defineProps({
    settings:   { type: Object, default: () => ({}) },
    apiBase:    { type: String, default: '' },
    authToken:  { type: String, default: '' },
})
const emit = defineEmits(['insert'])

const wrapRef     = ref(null)
const searchInput = ref(null)
const open        = ref(false)
const search      = ref('')
const activeCat   = ref('smileys')
const panelStyle  = ref({})
const serverEmotes  = ref([])
const emotesLoading = ref(false)

// Recent emoji — stored in localStorage
const RECENT_KEY    = 'eluth_recent_emoji'
const MAX_RECENT    = 32
const recent        = ref(JSON.parse(localStorage.getItem(RECENT_KEY) ?? '[]'))

function saveRecent(emoji) {
    const list = [emoji, ...recent.value.filter(e => e !== emoji)].slice(0, MAX_RECENT)
    recent.value = list
    localStorage.setItem(RECENT_KEY, JSON.stringify(list))
}

const allCategories = computed(() => [
    { id: 'recent', label: '🕐', title: 'Recently Used' },
    ...EMOJI_CATEGORIES,
    { id: 'server', label: '⭐', title: 'Server Emotes' },
])

const activeCategoryData = computed(() =>
    EMOJI_CATEGORIES.find(c => c.id === activeCat.value) ?? null
)

// Search across all standard emoji (by visual match query) + server emotes by name
const searchResults = computed(() => {
    if (!search.value) return []
    const q = search.value.toLowerCase()

    // Custom emotes by name
    const customs = serverEmotes.value
        .filter(e => e.name.toLowerCase().includes(q))
        .map(e => ({ type: 'custom', key: 'c:' + e.name, ...e }))

    // Standard emoji — search by category title and position heuristic
    // We'll search all emoji from all categories and return those where the
    // category name includes the query (simple approach)
    const standards = []
    for (const cat of EMOJI_CATEGORIES) {
        if (cat.title.toLowerCase().includes(q)) {
            cat.emoji.forEach(e => standards.push({ type: 'emoji', key: 'e:' + e, e }))
        }
    }

    return [...customs, ...standards].slice(0, 60)
})

function toggle() {
    if (!open.value) {
        const rect = wrapRef.value.getBoundingClientRect()
        const panelWidth = 360
        const left = Math.max(8, Math.min(rect.left, window.innerWidth - panelWidth - 8))
        panelStyle.value = {
            left:   left + 'px',
            bottom: (window.innerHeight - rect.top + 8) + 'px',
        }
        open.value = true
        if (activeCat.value === 'server' || serverEmotes.value.length === 0) {
            loadServerEmotes()
        }
        nextTick(() => searchInput.value?.focus())
    } else {
        close()
    }
}

function close() {
    open.value  = false
    search.value = ''
}

function onSearch() {
    // nothing extra needed; searchResults is computed
}

function pickEmoji(e) {
    saveRecent(e)
    emit('insert', e)
    close()
}

function pickCustom(emote) {
    emit('insert', ':' + emote.name + ':')
    close()
}

async function loadServerEmotes() {
    emotesLoading.value = true
    try {
        const base  = props.apiBase || (typeof window !== 'undefined' && window._eluthCommunityUrl) || ''
        const token = props.authToken ?? ''
        const res   = await fetch(base + '/api/plugins/emoticons/emotes', {
            headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
        })
        if (!res.ok) return
        const data = await res.json()
        serverEmotes.value = data.emotes ?? []
    } catch {
        serverEmotes.value = []
    } finally {
        emotesLoading.value = false
    }
}

// Load server emotes when switching to that tab
watch(activeCat, (cat) => {
    if (cat === 'server') loadServerEmotes()
})

function onClickOutside(e) {
    if (open.value && wrapRef.value && !wrapRef.value.contains(e.target)) {
        close()
    }
}

onMounted(() => document.addEventListener('click', onClickOutside))
onUnmounted(() => document.removeEventListener('click', onClickOutside))
</script>

<style scoped>
.emote-picker-wrap {
    position: relative;
    display: inline-flex;
    align-items: center;
}

.emote-btn {
    background: transparent;
    border: none;
    color: rgba(255,255,255,0.45);
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    transition: color 0.15s, background 0.15s;
}
.emote-btn:hover, .emote-btn.active {
    color: var(--accent, #5865f2);
    background: rgba(88,101,242,0.1);
}

.emote-panel {
    position: fixed;
    width: 360px;
    height: 440px;
    background: var(--bg-secondary, #2b2d31);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 10px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    z-index: 9999;
    box-shadow: 0 8px 32px rgba(0,0,0,0.5);
}

.emote-panel-header {
    padding: 8px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    flex-shrink: 0;
}

.emote-search {
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
.emote-search:focus { border-color: var(--accent, #5865f2); }

.emote-cats {
    display: flex;
    flex-wrap: nowrap;
    overflow-x: auto;
    gap: 2px;
    padding: 4px 8px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    flex-shrink: 0;
    scrollbar-width: none;
}
.emote-cats::-webkit-scrollbar { display: none; }

.emote-cat-btn {
    background: transparent;
    border: none;
    border-radius: 4px;
    padding: 5px 7px;
    font-size: 17px;
    cursor: pointer;
    opacity: 0.55;
    transition: opacity 0.1s, background 0.1s;
    flex-shrink: 0;
    line-height: 1;
}
.emote-cat-btn:hover { opacity: 0.85; background: rgba(255,255,255,0.06); }
.emote-cat-btn.active { opacity: 1; background: rgba(88,101,242,0.18); }

.emote-grid-wrap {
    flex: 1;
    overflow-y: auto;
    padding: 4px 6px 8px;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,0.1) transparent;
}

.emote-section-title {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: rgba(255,255,255,0.35);
    padding: 6px 2px 4px;
}

.emote-grid {
    display: grid;
    grid-template-columns: repeat(9, 1fr);
    gap: 2px;
}

.emote-grid--custom {
    grid-template-columns: repeat(7, 1fr);
    gap: 4px;
}

.emote-item {
    background: transparent;
    border: none;
    border-radius: 4px;
    padding: 3px;
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
    transition: background 0.1s, transform 0.1s;
    aspect-ratio: 1;
    display: flex;
    align-items: center;
    justify-content: center;
}
.emote-item:hover {
    background: rgba(255,255,255,0.09);
    transform: scale(1.15);
}

.emote-item--custom {
    padding: 4px;
    font-size: inherit;
}
.emote-item--custom img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    border-radius: 3px;
    display: block;
}

.emote-empty {
    padding: 24px 12px;
    text-align: center;
    color: rgba(255,255,255,0.35);
    font-size: 13px;
    line-height: 1.6;
}
.emote-empty small { font-size: 11px; }
</style>
