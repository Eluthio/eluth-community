<template>
    <div class="sc-root">

        <!-- Header -->
        <div class="sc-header">
            <span class="sc-title">🎬 Stream Control</span>
            <span v-if="isStreaming" class="sc-live-badge">● LIVE {{ streamDuration }}</span>
            <div class="sc-header-actions">
                <button class="sc-icon-btn" :class="{ active: showSettings }" @click="showSettings = !showSettings" title="Settings">⚙</button>
            </div>
        </div>

        <!-- Settings overlay -->
        <div v-if="showSettings" class="sc-settings-overlay" @click.self="showSettings = false">
            <div class="sc-settings-panel">
                <div class="sc-panel-title">Settings</div>

                <div class="sc-field-group">
                    <label class="sc-label">Scene Transition</label>
                    <div class="sc-row">
                        <select class="sc-select" v-model="settingsForm.transitionType">
                            <option value="cut">Cut</option>
                            <option value="fade">Fade</option>
                        </select>
                        <template v-if="settingsForm.transitionType !== 'cut'">
                            <input type="number" class="sc-input-sm" v-model.number="settingsForm.transitionDuration"
                                min="100" max="2000" step="100" />
                            <span class="sc-unit">ms</span>
                        </template>
                    </div>
                </div>

                <div class="sc-field-group">
                    <label class="sc-label">Output Resolution</label>
                    <select class="sc-select" v-model.number="settingsForm.outputWidth"
                        @change="settingsForm.outputHeight = settingsForm.outputWidth === 1920 ? 1080 : settingsForm.outputWidth === 1280 ? 720 : 480">
                        <option :value="854">480p</option>
                        <option :value="1280">720p</option>
                        <option :value="1920">1080p</option>
                    </select>
                </div>

                <button class="sc-btn sc-btn--primary" @click="saveSettings">Save Settings</button>
            </div>
        </div>

        <!-- Main content -->
        <div class="sc-body">

            <!-- Scene list -->
            <div class="sc-scenes">
                <div class="sc-panel-title">Scenes</div>

                <div class="sc-scene-list">
                    <div
                        v-for="scene in scenes"
                        :key="scene.id"
                        class="sc-scene-item"
                        :class="{ active: scene.id === activeSceneId }"
                        @click="switchScene(scene.id)"
                    >
                        <div v-if="editingSceneId === scene.id" class="sc-scene-edit" @click.stop>
                            <input
                                ref="renameInput"
                                class="sc-scene-input"
                                v-model="editingSceneName"
                                @keydown.enter="commitRename"
                                @keydown.escape="editingSceneId = null"
                                @blur="commitRename"
                            />
                        </div>
                        <template v-else>
                            <span class="sc-scene-dot" :class="{ active: scene.id === activeSceneId }">●</span>
                            <span
                                class="sc-scene-name"
                                @dblclick.stop="startRename(scene)"
                                :title="'Double-click to rename'"
                            >{{ scene.name }}</span>
                            <button
                                v-if="scenes.length > 1"
                                class="sc-scene-delete"
                                @click.stop="deleteScene(scene.id)"
                                title="Delete scene"
                            >✕</button>
                        </template>
                    </div>

                    <div v-if="!scenes.length" class="sc-empty">No scenes.</div>
                </div>

                <button class="sc-btn sc-btn--ghost" @click="addScene">＋ Add Scene</button>
            </div>

            <!-- Divider -->
            <div class="sc-divider" />

            <!-- Audio mixer -->
            <div class="sc-audio">
                <div class="sc-panel-title" style="padding:10px 12px 6px">Audio Mix</div>
                <div v-if="Object.keys(audioChannels).length" class="sc-audio-channels">
                    <div v-for="(ch, key) in audioChannels" :key="key" class="sc-audio-strip">
                        <!-- VU meter -->
                        <div class="sc-vu-wrap">
                            <div class="sc-vu-track">
                                <div class="sc-vu-fill"
                                    :class="vuClass(audioLevels[key] ?? 0)"
                                    :style="{ height: (audioLevels[key] ?? 0) * 100 + '%' }" />
                            </div>
                        </div>
                        <!-- Rotary knob -->
                        <svg class="sc-knob" viewBox="0 0 40 40"
                            :class="{ muted: ch.muted }"
                            :title="`${ch.label}: ${Math.round(ch.gain * 100)}%`"
                            @mousedown.prevent="startKnobDrag(key, ch.gain, $event)"
                            @dblclick="sendCommand({ type: 'set-audio-gain', sourceKey: key, gain: 1 })">
                            <!-- Background track arc -->
                            <path class="sc-knob-track" d="M 8.7 31.3 A 16 16 0 1 1 31.3 31.3" />
                            <!-- Value arc -->
                            <path v-if="ch.gain > 0" class="sc-knob-value"
                                :class="{ muted: ch.muted }"
                                :d="knobArc(ch.gain)" />
                            <!-- Indicator line -->
                            <line x1="20" y1="20"
                                :x2="knobTip(ch.gain).x" :y2="knobTip(ch.gain).y"
                                class="sc-knob-line" :class="{ muted: ch.muted }" />
                        </svg>
                        <!-- Level % -->
                        <div class="sc-audio-pct" :class="{ muted: ch.muted }">
                            {{ ch.muted ? 'MUTE' : Math.round(ch.gain * 100) + '%' }}
                        </div>
                        <!-- Mute button -->
                        <button class="sc-audio-mute-btn" :class="{ muted: ch.muted }"
                            @click="sendCommand({ type: 'set-audio-mute', sourceKey: key, muted: !ch.muted })">
                            {{ ch.muted ? '🔇' : '🔊' }}
                        </button>
                        <!-- Channel label -->
                        <div class="sc-audio-lbl" :class="{ muted: ch.muted }">
                            {{ ch.icon }}&thinsp;{{ ch.label }}
                        </div>
                    </div>
                </div>
                <div v-else class="sc-empty" style="padding:20px 8px">
                    No audio.<br>Go live to see<br>channels.
                </div>
            </div>

            <!-- Divider -->
            <div class="sc-divider" />

            <!-- Layer editor -->
            <div class="sc-layers">
                <div class="sc-panel-header">
                    <div class="sc-panel-title">
                        Layers
                        <span v-if="activeScene" class="sc-panel-subtitle">— {{ activeScene.name }}</span>
                    </div>
                    <button class="sc-add-source-btn" @click="showSourcePicker = true">＋ Source</button>
                </div>

                <div v-if="!activeScene" class="sc-empty">No scene selected.</div>
                <div v-else class="sc-layer-list">
                    <div
                        v-for="layer in [...(activeScene.layers ?? [])].reverse()"
                        :key="layer.id"
                        class="sc-layer-row"
                        :class="{ selected: layer.id === selectedLayerId, hidden: !layer.visible }"
                        @click="selectedLayerId = layer.id"
                    >
                        <button class="sc-layer-vis" @click.stop="sendCommand({ type: 'toggle-visible', id: layer.id })">
                            {{ layer.visible ? '👁' : '🚫' }}
                        </button>
                        <span class="sc-layer-icon">{{ sourceIcon(layer.sourceKey) }}</span>
                        <span class="sc-layer-name">{{ sourceName(layer.sourceKey) }}</span>
                        <input
                            type="range" min="0" max="1" step="0.01"
                            class="sc-layer-opacity"
                            :value="layer.opacity"
                            @click.stop
                            @input="sendCommand({ type: 'set-opacity', id: layer.id, opacity: +$event.target.value })"
                            title="Opacity"
                        />
                        <button class="sc-layer-remove" @click.stop="sendCommand({ type: 'remove-layer', id: layer.id })">✕</button>
                    </div>
                    <div v-if="!activeScene.layers?.length" class="sc-empty">No layers. Add a source above.</div>
                </div>

                <!-- Transform panel for selected layer -->
                <div v-if="selectedLayer" class="sc-transform">
                    <div class="sc-transform-title">Transform — {{ sourceName(selectedLayer.sourceKey) }}</div>
                    <div class="sc-preset-row">
                        <button v-for="p in PRESETS" :key="p.label" class="sc-preset-btn"
                            :title="p.title" @click="applyPreset(p)">{{ p.label }}</button>
                    </div>
                    <div class="sc-slider-row">
                        <div class="sc-slider-field">
                            <span class="sc-slider-label">W</span>
                            <input type="range" class="sc-slider" min="0.05" max="1" step="0.01"
                                :value="selectedLayer.w"
                                @input="emitTransform({ w: +$event.target.value })" />
                            <span class="sc-slider-val">{{ pct(selectedLayer.w) }}</span>
                        </div>
                        <div class="sc-slider-field">
                            <span class="sc-slider-label">H</span>
                            <input type="range" class="sc-slider" min="0.05" max="1" step="0.01"
                                :value="selectedLayer.h"
                                @input="emitTransform({ h: +$event.target.value })" />
                            <span class="sc-slider-val">{{ pct(selectedLayer.h) }}</span>
                        </div>
                    </div>
                    <div class="sc-slider-row">
                        <div class="sc-slider-field">
                            <span class="sc-slider-label">X</span>
                            <input type="range" class="sc-slider" min="0" max="0.95" step="0.01"
                                :value="selectedLayer.x"
                                @input="emitTransform({ x: +$event.target.value })" />
                            <span class="sc-slider-val">{{ pct(selectedLayer.x) }}</span>
                        </div>
                        <div class="sc-slider-field">
                            <span class="sc-slider-label">Y</span>
                            <input type="range" class="sc-slider" min="0" max="0.95" step="0.01"
                                :value="selectedLayer.y"
                                @input="emitTransform({ y: +$event.target.value })" />
                            <span class="sc-slider-val">{{ pct(selectedLayer.y) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Source picker -->
        <div v-if="showSourcePicker" class="sc-modal-overlay" @click.self="showSourcePicker = false">
            <div class="sc-modal">
                <div class="sc-modal-title">Add Source</div>
                <div class="sc-source-grid">
                    <button
                        v-for="(src, key) in sourceRegistry"
                        :key="key"
                        class="sc-source-opt"
                        @click="pickSource(key)"
                    >
                        <span class="sc-source-icon">{{ src.icon }}</span>
                        <span class="sc-source-label">{{ src.label }}</span>
                    </button>
                    <div v-if="!Object.keys(sourceRegistry).length" class="sc-empty">
                        No sources available.
                    </div>
                </div>
                <button class="sc-btn sc-btn--ghost" @click="showSourcePicker = false">Cancel</button>
            </div>
        </div>

        <!-- Camera device picker -->
        <div v-if="showDevicePicker" class="sc-modal-overlay" @click.self="showDevicePicker = false">
            <div class="sc-modal">
                <div class="sc-modal-title">Choose Camera</div>
                <div class="sc-device-list">
                    <button v-for="dev in availableCameras" :key="dev.deviceId"
                        class="sc-device-btn" @click="pickCamera(dev.deviceId, dev.label)">
                        <span>📷</span>
                        <span>{{ dev.label }}</span>
                    </button>
                </div>
                <button class="sc-btn sc-btn--ghost" @click="showDevicePicker = false">Cancel</button>
            </div>
        </div>

        <!-- ── Plex panel ──────────────────────────────────────────────── -->
        <div v-if="plexAvailable" class="sc-plex">

            <div class="sc-plex-hdr" @click="plexExpanded = !plexExpanded">
                <span class="sc-plex-hdr-icon">🎬</span>
                <span class="sc-plex-hdr-label">Plex</span>
                <span v-if="plexState?.title && !plexBrowsing" class="sc-plex-hdr-title">{{ plexState.title }}</span>
                <span v-if="plexState?.isPlaying" class="sc-plex-hdr-live">▶</span>
                <span class="sc-plex-chevron">{{ plexExpanded ? '▲' : '▼' }}</span>
            </div>

            <div v-if="plexExpanded" class="sc-plex-body">

                <!-- Not authenticated -->
                <template v-if="!plexToken">
                    <div v-if="plexPinCode" class="sc-plex-pin-block">
                        <div class="sc-plex-pin-hint">Visit <strong>plex.tv/link</strong> and enter:</div>
                        <div class="sc-plex-pin-code">{{ plexPinCode }}</div>
                        <div class="sc-plex-pin-waiting">Waiting… <span class="sc-plex-spinner" /></div>
                    </div>
                    <button v-else class="sc-btn sc-btn--ghost" @click="plexStartAuth" :disabled="plexConnecting">
                        {{ plexConnecting ? 'Opening…' : 'Connect Plex Account' }}
                    </button>
                </template>

                <!-- Authenticated -->
                <template v-else>

                    <!-- Transport controls (not browsing) -->
                    <div v-if="!plexBrowsing" class="sc-plex-controls">
                        <template v-if="plexState?.isConfigured">
                            <div class="sc-plex-now-playing">{{ plexState.title || 'Ready' }}</div>
                            <div class="sc-plex-transport">
                                <button class="sc-plex-ctrl-btn"
                                    @click="sendCommand({ type: plexState.isPlaying && !plexState.isPaused ? 'plex-pause' : 'plex-resume' })">
                                    {{ plexState.isPlaying && !plexState.isPaused ? '⏸' : '▶' }}
                                </button>
                                <div class="sc-plex-seek-wrap">
                                    <input type="range" class="sc-plex-seek"
                                        min="0" :max="Math.round(plexState.duration) || 100" step="1"
                                        :value="Math.round(plexState.currentTime)"
                                        @change="sendCommand({ type: 'plex-seek', time: +$event.target.value })" />
                                    <span class="sc-plex-time">
                                        {{ formatPlexTime(plexState.currentTime) }} / {{ formatPlexTime(plexState.duration) }}
                                    </span>
                                </div>
                                <button class="sc-plex-ctrl-btn sc-plex-ctrl-btn--stop"
                                    @click="sendCommand({ type: 'plex-stop' })">⏹</button>
                            </div>
                        </template>
                        <div v-else class="sc-plex-empty-hint">No content selected.</div>
                        <div class="sc-plex-actions">
                            <button class="sc-btn sc-btn--ghost" @click="plexOpenBrowser">
                                {{ plexState?.isConfigured ? 'Change Content' : 'Browse Library' }}
                            </button>
                            <button class="sc-plex-disconnect" @click="plexDisconnect">Disconnect</button>
                        </div>
                    </div>

                    <!-- Library browser -->
                    <div v-else class="sc-plex-browser">
                        <button v-if="plexState?.isConfigured" class="sc-plex-back-btn" @click="plexBrowsing = false">← Back</button>

                        <!-- Server list -->
                        <div v-if="plexBrowseStep === 'servers'">
                            <div class="sc-plex-browse-title">Choose Server</div>
                            <div v-if="plexServersLoading" class="sc-plex-loading">Loading servers…</div>
                            <div v-else class="sc-plex-list">
                                <button v-for="s in plexServers" :key="s.clientIdentifier"
                                    class="sc-plex-list-item" @click="selectPlexServer(s)">
                                    <span>🖥</span><span>{{ s.name }}</span>
                                </button>
                                <div v-if="!plexServers.length" class="sc-plex-empty">No servers found.</div>
                            </div>
                        </div>

                        <!-- Library sections -->
                        <div v-else-if="plexBrowseStep === 'sections'">
                            <button class="sc-plex-back-btn" @click="plexBrowseStep = 'servers'">← {{ plexActiveServer?.name }}</button>
                            <div class="sc-plex-list" style="margin-top:6px">
                                <button v-for="sec in plexSections" :key="sec.key"
                                    class="sc-plex-list-item" @click="openPlexSection(sec)">
                                    <span>{{ plexSectionIcon(sec.type) }}</span><span>{{ sec.title }}</span>
                                </button>
                            </div>
                        </div>

                        <!-- Items grid -->
                        <div v-else-if="plexBrowseStep === 'items'">
                            <button class="sc-plex-back-btn" @click="plexGoBack">← Back</button>
                            <div v-if="plexBrowseTitle" class="sc-plex-browse-title">{{ plexBrowseTitle }}</div>
                            <div v-if="plexItemsLoading" class="sc-plex-loading">Loading…</div>
                            <div v-else class="sc-plex-grid">
                                <button v-for="item in plexItems" :key="item.ratingKey"
                                    class="sc-plex-grid-item" :title="item.title" @click="selectPlexItem(item)">
                                    <img v-if="item.thumb && plexActiveServer"
                                        :src="`${plexActiveServer.uri}${item.thumb}?X-Plex-Token=${plexActiveServer.accessToken}`"
                                        class="sc-plex-thumb" loading="lazy" />
                                    <span v-else class="sc-plex-thumb-ph">🎬</span>
                                    <span class="sc-plex-item-label">{{ item.title }}</span>
                                </button>
                                <div v-if="!plexItems.length" class="sc-plex-empty">No items.</div>
                            </div>
                        </div>
                    </div>

                </template>
            </div>
        </div>

        <!-- Footer -->
        <div class="sc-footer">
            <span v-if="!isStreaming" class="sc-status">Not streaming</span>
            <span v-else class="sc-status sc-status--live">● Streaming</span>
            <button v-if="isStreaming" class="sc-btn sc-btn--stop" @click="sendCommand({ type: 'stop-stream' })">
                ⏹ Stop Stream
            </button>
        </div>

    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'

const props = defineProps({
    channelId: { type: String, required: true },
})

// ── BroadcastChannel ──────────────────────────────────────────────────────────
let bc = null

function sendCommand(cmd) {
    bc?.postMessage(cmd)
}

// ── State (mirrored from main window) ─────────────────────────────────────────
const scenes         = ref([])
const activeSceneId  = ref(null)
const isStreaming     = ref(false)
const streamDuration  = ref('0:00')
const sourceRegistry  = ref({})   // { key: { label, icon } }

const activeScene   = computed(() => scenes.value.find(s => s.id === activeSceneId.value) ?? null)
const selectedLayerId = ref(null)
const selectedLayer   = computed(() =>
    activeScene.value?.layers?.find(l => l.id === selectedLayerId.value) ?? null
)

// ── Settings ─────────────────────────────────────────────────────────────────
const showSettings  = ref(false)
const settingsForm  = ref({ transitionType: 'fade', transitionDuration: 400, outputWidth: 1280, outputHeight: 720 })

function saveSettings() {
    sendCommand({
        type:     'update-settings',
        settings: {
            transition:  { type: settingsForm.value.transitionType, duration: settingsForm.value.transitionDuration },
            outputWidth:  settingsForm.value.outputWidth,
            outputHeight: settingsForm.value.outputHeight,
        },
    })
    showSettings.value = false
}

// ── Scenes ────────────────────────────────────────────────────────────────────
const editingSceneId   = ref(null)
const editingSceneName = ref('')
const renameInput      = ref(null)

function switchScene(id) {
    sendCommand({ type: 'switch-scene', id })
}

function addScene() {
    sendCommand({ type: 'add-scene' })
}

function deleteScene(id) {
    sendCommand({ type: 'delete-scene', id })
}

function startRename(scene) {
    editingSceneId.value   = scene.id
    editingSceneName.value = scene.name
    nextTick(() => renameInput.value?.[0]?.focus())
}

function commitRename() {
    if (editingSceneId.value && editingSceneName.value.trim()) {
        sendCommand({ type: 'rename-scene', id: editingSceneId.value, name: editingSceneName.value.trim() })
    }
    editingSceneId.value = null
}

// ── Source picker ─────────────────────────────────────────────────────────────
const showSourcePicker = ref(false)
const showDevicePicker = ref(false)
const availableCameras = ref([])
let   pendingCameraKey = null

async function pickSource(key) {
    showSourcePicker.value = false
    if (key === 'camera') {
        try {
            const all  = await navigator.mediaDevices.enumerateDevices()
            const cams = all.filter(d => d.kind === 'videoinput' && d.label)
            if (cams.length > 1) {
                availableCameras.value = cams
                showDevicePicker.value = true
                return
            }
        } catch { /* permission not yet granted — use default */ }
    }
    sendCommand({ type: 'add-layer', sourceKey: key })
}

function pickCamera(deviceId, label) {
    showDevicePicker.value = false
    sendCommand({ type: 'add-layer', sourceKey: 'camera', deviceId, deviceLabel: label })
}

// ── Layers ────────────────────────────────────────────────────────────────────
const PRESETS = [
    { label: '⬜', title: 'Full screen',    x: 0,    y: 0,    w: 1,    h: 1    },
    { label: '↖',  title: 'PiP top-left',  x: 0.02, y: 0.04, w: 0.28, h: 0.28 },
    { label: '↗',  title: 'PiP top-right', x: 0.70, y: 0.04, w: 0.28, h: 0.28 },
    { label: '↙',  title: 'PiP bot-left',  x: 0.02, y: 0.68, w: 0.28, h: 0.28 },
    { label: '↘',  title: 'PiP bot-right', x: 0.70, y: 0.68, w: 0.28, h: 0.28 },
    { label: '◧',  title: 'Left half',     x: 0,    y: 0,    w: 0.5,  h: 1    },
    { label: '▧',  title: 'Right half',    x: 0.5,  y: 0,    w: 0.5,  h: 1    },
]

function applyPreset(p) {
    if (!selectedLayer.value) return
    sendCommand({ type: 'set-transform', id: selectedLayer.value.id, x: p.x, y: p.y, w: p.w, h: p.h })
}

function emitTransform(partial) {
    const l = selectedLayer.value
    if (!l) return
    sendCommand({ type: 'set-transform', id: l.id,
        x: partial.x ?? l.x, y: partial.y ?? l.y,
        w: partial.w ?? l.w, h: partial.h ?? l.h,
    })
}

function pct(v) { return Math.round(v * 100) + '%' }

function sourceIcon(key) { return sourceRegistry.value[key]?.icon ?? '📹' }
function sourceName(key) { return sourceRegistry.value[key]?.label ?? key }

// ── Audio mixer ───────────────────────────────────────────────────────────────
const audioChannels = ref({})   // { sourceKey: { gain, muted, label, icon } }
const audioLevels   = ref({})   // { sourceKey: 0.0-1.0 } — from fast BC messages

// ── Knob helpers ──────────────────────────────────────────────────────────────
function knobAngle(gain) { return -135 + gain * 270 }

function knobTip(gain) {
    const rad = knobAngle(gain) * Math.PI / 180
    return { x: +(20 + 11 * Math.sin(rad)).toFixed(2), y: +(20 - 11 * Math.cos(rad)).toFixed(2) }
}

function knobArc(gain) {
    if (gain <= 0.001) return ''
    const r       = 16
    const startR  = -135 * Math.PI / 180
    const endR    = knobAngle(gain) * Math.PI / 180
    const sx      = +(20 + r * Math.sin(startR)).toFixed(2)
    const sy      = +(20 - r * Math.cos(startR)).toFixed(2)
    const ex      = +(20 + r * Math.sin(endR)).toFixed(2)
    const ey      = +(20 - r * Math.cos(endR)).toFixed(2)
    const large   = gain > 0.5 ? 1 : 0
    return `M ${sx} ${sy} A ${r} ${r} 0 ${large} 1 ${ex} ${ey}`
}

// ── Knob drag ─────────────────────────────────────────────────────────────────
let dragState = null

function startKnobDrag(key, startGain, e) {
    dragState = { key, startY: e.clientY, startGain }
    window.addEventListener('mousemove', onKnobMove)
    window.addEventListener('mouseup',   stopKnobDrag)
}

function onKnobMove(e) {
    if (!dragState) return
    const dy   = dragState.startY - e.clientY
    const gain = Math.max(0, Math.min(1, dragState.startGain + dy / 150))
    // Optimistic local update for smooth feel
    if (audioChannels.value[dragState.key]) audioChannels.value[dragState.key].gain = gain
    sendCommand({ type: 'set-audio-gain', sourceKey: dragState.key, gain: +gain.toFixed(3) })
}

function stopKnobDrag() {
    dragState = null
    window.removeEventListener('mousemove', onKnobMove)
    window.removeEventListener('mouseup',   stopKnobDrag)
}

function vuClass(level) {
    if (level > 0.85) return 'red'
    if (level > 0.6)  return 'yellow'
    return 'green'
}

// ── Plex ──────────────────────────────────────────────────────────────────────
const PLEX_TOKEN_KEY   = 'eluth_plex_token'
const PLEX_PRODUCT     = 'Eluth'
const PLEX_CLIENT_ID   = 'eluth-plugin-plex'

const plexExpanded      = ref(false)
const plexBrowsing      = ref(false)
const plexToken         = ref(localStorage.getItem(PLEX_TOKEN_KEY))
const plexState         = ref(null)   // mirrored from main window

const plexConnecting    = ref(false)
const plexPinCode       = ref(null)
const plexPinId         = ref(null)
let   plexPinPoller     = null

const plexBrowseStep    = ref('servers')
const plexServers       = ref([])
const plexServersLoading = ref(false)
const plexActiveServer  = ref(null)
const plexSections      = ref([])
const plexItems         = ref([])
const plexItemsLoading  = ref(false)
const plexBrowseStack   = ref([])
const plexBrowseTitle   = ref('')

const plexAvailable = computed(() => 'plex' in sourceRegistry.value)

function plexHeaders(token) {
    return {
        'Accept': 'application/json',
        'X-Plex-Product': PLEX_PRODUCT, 'X-Plex-Version': '1.0.0',
        'X-Plex-Client-Identifier': PLEX_CLIENT_ID, 'X-Plex-Platform': 'Web',
        ...(token ? { 'X-Plex-Token': token } : {}),
    }
}

async function plexStartAuth() {
    plexConnecting.value = true
    try {
        const res  = await fetch('https://plex.tv/api/v2/pins', {
            method: 'POST', headers: plexHeaders(),
            body: new URLSearchParams({ strong: 'false' }),
        })
        const data = await res.json()
        plexPinCode.value = data.code
        plexPinId.value   = data.id
        startPlexPinPoller()
    } catch { /* ignore */ }
    plexConnecting.value = false
}

function startPlexPinPoller() {
    clearInterval(plexPinPoller)
    plexPinPoller = setInterval(async () => {
        try {
            const res  = await fetch(`https://plex.tv/api/v2/pins/${plexPinId.value}`, { headers: plexHeaders() })
            const data = await res.json()
            if (data.authToken) {
                clearInterval(plexPinPoller)
                localStorage.setItem(PLEX_TOKEN_KEY, data.authToken)
                plexToken.value   = data.authToken
                plexPinCode.value = null
                await plexLoadServers()
                plexBrowsing.value = true
            }
        } catch { /* network hiccup */ }
    }, 2000)
}

async function plexLoadServers() {
    plexServersLoading.value = true
    plexBrowseStep.value = 'servers'
    try {
        const res       = await fetch('https://plex.tv/api/v2/resources?includeHttps=1&includeRelay=1', { headers: plexHeaders(plexToken.value) })
        const resources = await res.json()
        plexServers.value = resources.filter(r => r.provides?.includes('server'))
    } catch { plexServers.value = [] }
    plexServersLoading.value = false
}

async function plexOpenBrowser() {
    plexBrowsing.value = true
    if (!plexServers.value.length) await plexLoadServers()
}

async function selectPlexServer(server) {
    const conns = server.connections ?? []
    const uri   = (conns.find(c => c.protocol === 'https' && !c.relay)
               ?? conns.find(c => !c.relay)
               ?? conns[0])?.uri ?? ''
    plexActiveServer.value = { name: server.name, uri, accessToken: server.accessToken ?? plexToken.value }
    plexBrowseStep.value = 'sections'
    try {
        const text = await plexServerFetch('/library/sections')
        plexSections.value = parsePlexXml(text, 'Directory').map(el => ({
            key: el.getAttribute('key'), title: el.getAttribute('title'), type: el.getAttribute('type'),
        }))
    } catch { plexSections.value = [] }
}

async function openPlexSection(sec) {
    plexBrowseStack.value  = []
    plexBrowseTitle.value  = sec.title
    plexBrowseStep.value   = 'items'
    plexItemsLoading.value = true
    try {
        plexItems.value = await fetchPlexItems(`/library/sections/${sec.key}/all`)
    } catch { plexItems.value = [] }
    plexItemsLoading.value = false
}

async function plexDrillInto(item) {
    plexBrowseStack.value.push({ title: plexBrowseTitle.value, items: plexItems.value })
    plexBrowseTitle.value  = item.title
    plexItemsLoading.value = true
    try {
        plexItems.value = await fetchPlexItems(`/library/metadata/${item.ratingKey}/children`)
    } catch { plexItems.value = [] }
    plexItemsLoading.value = false
}

function plexGoBack() {
    if (plexBrowseStack.value.length) {
        const prev = plexBrowseStack.value.pop()
        plexItems.value = prev.items
        plexBrowseTitle.value = prev.title
    } else {
        plexBrowseStep.value = 'sections'
    }
}

async function selectPlexItem(item) {
    const drillTypes = ['show', 'season', 'artist', 'album']
    if (drillTypes.includes(item.type)) { await plexDrillInto(item); return }
    const srv = plexActiveServer.value
    sendCommand({
        type:   'plex-play',
        config: { serverUri: srv.uri, accessToken: srv.accessToken, serverName: srv.name, ratingKey: item.ratingKey, title: item.title },
    })
    plexBrowsing.value = false
}

async function plexServerFetch(path) {
    const srv = plexActiveServer.value
    const res = await fetch(`${srv.uri}${path}?X-Plex-Token=${srv.accessToken}`)
    if (!res.ok) throw new Error(`Server error ${res.status}`)
    return res.text()
}

function parsePlexXml(text, ...tags) {
    const doc = new DOMParser().parseFromString(text, 'text/xml')
    return tags.flatMap(t => [...doc.querySelectorAll(t)])
}

async function fetchPlexItems(path) {
    const text = await plexServerFetch(path)
    return parsePlexXml(text, 'Video', 'Directory')
        .filter(el => el.getAttribute('ratingKey'))
        .map(el => ({
            ratingKey: el.getAttribute('ratingKey'),
            title:     el.getAttribute('title'),
            thumb:     el.getAttribute('thumb') ?? el.getAttribute('art'),
            type:      el.getAttribute('type'),
        }))
        .sort((a, b) => (a.title ?? '').localeCompare(b.title ?? ''))
}

function plexSectionIcon(type) {
    return type === 'movie' ? '🎬' : type === 'show' ? '📺' : type === 'artist' ? '🎵' : '📁'
}

function formatPlexTime(secs) {
    if (!secs || !isFinite(secs)) return '0:00'
    const m = Math.floor(secs / 60), s = Math.floor(secs % 60)
    return `${m}:${String(s).padStart(2, '0')}`
}

function plexDisconnect() {
    localStorage.removeItem(PLEX_TOKEN_KEY)
    plexToken.value = null
    plexServers.value = []
    plexActiveServer.value = null
    plexBrowsing.value = false
    sendCommand({ type: 'plex-stop' })
}

// ── BroadcastChannel setup ────────────────────────────────────────────────────
function onMessage(e) {
    const msg = e.data
    if (!msg?.type) return

    if (msg.type === 'audio-levels') {
        audioLevels.value = msg.levels
        return
    }

    if (msg.type === 'state') {
        scenes.value        = msg.scenes         ?? []
        activeSceneId.value = msg.activeSceneId  ?? null
        isStreaming.value   = msg.isStreaming     ?? false
        streamDuration.value = msg.streamDuration ?? '0:00'
        sourceRegistry.value = msg.sourceRegistry ?? {}
        if (msg.audioChannels)              audioChannels.value = msg.audioChannels
        if (msg.pluginStates?.plex !== undefined) plexState.value = msg.pluginStates.plex
        // Sync settings form from main window state
        if (msg.settings) {
            settingsForm.value.transitionType     = msg.settings.transition?.type     ?? 'fade'
            settingsForm.value.transitionDuration = msg.settings.transition?.duration ?? 400
            settingsForm.value.outputWidth        = msg.settings.outputWidth          ?? 1280
            settingsForm.value.outputHeight       = msg.settings.outputHeight         ?? 720
        }
    }

    if (msg.type === 'plex-state') {
        plexState.value = msg.plex
    }
}

onMounted(() => {
    bc = new BroadcastChannel('eluth-stream-' + props.channelId)
    bc.addEventListener('message', onMessage)
    // Ask main window for current state
    sendCommand({ type: 'request-state' })
})

onUnmounted(() => {
    bc?.close()
    clearInterval(plexPinPoller)
    window.removeEventListener('mousemove', onKnobMove)
    window.removeEventListener('mouseup',   stopKnobDrag)
})
</script>

<style>
/* Reset for popup window */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { background: #0d0f18; font-family: 'Inter', system-ui, sans-serif; color: #e2e8f0; height: 100vh; overflow: hidden; }
#app { height: 100vh; }
</style>

<style scoped>
.sc-root {
    display: flex; flex-direction: column; height: 100vh;
    background: #0d0f18; color: #e2e8f0;
    font-family: 'Inter', system-ui, sans-serif;
    position: relative;
}

/* Header */
.sc-header {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 16px;
    background: #13151f; border-bottom: 1px solid rgba(255,255,255,0.08);
    flex-shrink: 0;
}
.sc-title { font-size: 14px; font-weight: 700; color: #e2e8f0; }
.sc-live-badge {
    font-size: 11px; font-weight: 700; color: #f87171;
    background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.3);
    padding: 2px 8px; border-radius: 20px;
}
.sc-header-actions { margin-left: auto; display: flex; gap: 6px; }
.sc-icon-btn {
    background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);
    color: #64748b; border-radius: 6px; padding: 4px 8px; font-size: 14px;
    cursor: pointer; transition: all 0.15s;
}
.sc-icon-btn:hover, .sc-icon-btn.active { color: #e2e8f0; background: rgba(255,255,255,0.12); }

/* Body — two-column layout */
.sc-body { display: flex; flex: 1; min-height: 0; }

/* Scenes panel */
.sc-scenes {
    width: 180px; flex-shrink: 0;
    display: flex; flex-direction: column; gap: 8px;
    padding: 14px 10px;
    border-right: 1px solid rgba(255,255,255,0.08);
    overflow-y: auto;
}
.sc-scene-list { display: flex; flex-direction: column; gap: 4px; flex: 1; }
.sc-scene-item {
    display: flex; align-items: center; gap: 6px;
    padding: 7px 8px; border-radius: 6px; cursor: pointer;
    border: 1px solid transparent; transition: all 0.15s;
    user-select: none;
}
.sc-scene-item:hover { background: rgba(255,255,255,0.06); }
.sc-scene-item.active { background: rgba(88,101,242,0.15); border-color: rgba(88,101,242,0.3); }
.sc-scene-dot { font-size: 8px; color: #475569; flex-shrink: 0; }
.sc-scene-dot.active { color: #5865f2; }
.sc-scene-name { font-size: 13px; color: #cbd5e1; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sc-scene-item.active .sc-scene-name { color: #e2e8f0; font-weight: 500; }
.sc-scene-delete { background: none; border: none; color: #374151; cursor: pointer; font-size: 10px; padding: 0 2px; opacity: 0; transition: all 0.15s; flex-shrink: 0; }
.sc-scene-item:hover .sc-scene-delete { opacity: 1; }
.sc-scene-delete:hover { color: #ef4444 !important; }
.sc-scene-edit { flex: 1; }
.sc-scene-input {
    width: 100%; background: rgba(255,255,255,0.1); border: 1px solid rgba(88,101,242,0.5);
    color: #e2e8f0; border-radius: 4px; padding: 3px 6px; font-size: 13px; outline: none;
}

/* Divider */
.sc-divider { width: 1px; background: rgba(255,255,255,0.08); flex-shrink: 0; }

/* Audio mixer panel */
.sc-audio {
    width: 200px; flex-shrink: 0;
    display: flex; flex-direction: column;
    border-right: 1px solid rgba(255,255,255,0.08);
    overflow-y: auto;
}
.sc-audio-channels {
    display: flex; flex-direction: row; gap: 6px;
    padding: 6px 10px 10px; flex-wrap: wrap; align-items: flex-end;
}
.sc-audio-strip {
    display: flex; flex-direction: column; align-items: center; gap: 4px;
    width: 52px;
}

/* VU meter */
.sc-vu-wrap { width: 10px; height: 80px; }
.sc-vu-track {
    width: 100%; height: 100%;
    background: rgba(255,255,255,0.06); border-radius: 3px;
    display: flex; align-items: flex-end; overflow: hidden;
}
.sc-vu-fill {
    width: 100%; border-radius: 3px;
    transition: height 0.08s linear;
}
.sc-vu-fill.green  { background: #22c55e; }
.sc-vu-fill.yellow { background: #eab308; }
.sc-vu-fill.red    { background: #ef4444; }

/* Knob */
.sc-knob {
    width: 44px; height: 44px; cursor: ns-resize;
    user-select: none; overflow: visible;
}
.sc-knob-track {
    fill: none; stroke: rgba(255,255,255,0.1); stroke-width: 3;
    stroke-linecap: round;
}
.sc-knob-value {
    fill: none; stroke: #5865f2; stroke-width: 3; stroke-linecap: round;
}
.sc-knob-value.muted { stroke: #374151; }
.sc-knob-line {
    stroke: #e2e8f0; stroke-width: 2; stroke-linecap: round;
}
.sc-knob-line.muted { stroke: #4b5563; }

.sc-audio-pct {
    font-size: 9px; font-weight: 700; color: #64748b;
    letter-spacing: 0.03em; text-align: center; line-height: 1;
}
.sc-audio-pct.muted { color: #374151; }

.sc-audio-mute-btn {
    background: none; border: none; cursor: pointer;
    font-size: 14px; padding: 0; line-height: 1; opacity: 0.7;
    transition: opacity 0.15s;
}
.sc-audio-mute-btn:hover { opacity: 1; }
.sc-audio-mute-btn.muted { opacity: 1; }

.sc-audio-lbl {
    font-size: 9px; color: #475569; text-align: center;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    width: 100%; line-height: 1.2;
}
.sc-audio-lbl.muted { color: #374151; }

/* Layers panel */
.sc-layers {
    flex: 1; min-width: 0;
    display: flex; flex-direction: column; gap: 0;
    overflow-y: auto;
}
.sc-panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 14px 6px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    flex-shrink: 0;
}
.sc-panel-title {
    font-size: 11px; font-weight: 700; color: #64748b;
    text-transform: uppercase; letter-spacing: 0.06em;
    padding: 10px 10px 6px;
}
.sc-panel-subtitle { font-size: 11px; color: #475569; text-transform: none; letter-spacing: 0; font-weight: 400; }
.sc-add-source-btn {
    background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);
    color: #94a3b8; border-radius: 4px; padding: 3px 8px;
    font-size: 11px; cursor: pointer; transition: all 0.15s;
}
.sc-add-source-btn:hover { background: rgba(255,255,255,0.12); color: #e2e8f0; }

.sc-layer-list { padding: 6px 10px; display: flex; flex-direction: column; gap: 4px; }
.sc-layer-row {
    display: flex; align-items: center; gap: 6px;
    padding: 6px 8px; border-radius: 6px; cursor: pointer;
    background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06);
    transition: all 0.15s;
}
.sc-layer-row:hover { background: rgba(255,255,255,0.07); }
.sc-layer-row.selected { border-color: rgba(88,101,242,0.5); background: rgba(88,101,242,0.08); }
.sc-layer-row.hidden { opacity: 0.4; }
.sc-layer-vis { background: none; border: none; cursor: pointer; font-size: 13px; padding: 0; flex-shrink: 0; }
.sc-layer-icon { font-size: 13px; flex-shrink: 0; }
.sc-layer-name { font-size: 12px; color: #cbd5e1; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sc-layer-opacity { width: 60px; flex-shrink: 0; accent-color: #5865f2; }
.sc-layer-remove { background: none; border: none; color: #374151; cursor: pointer; font-size: 11px; padding: 0; flex-shrink: 0; transition: color 0.15s; }
.sc-layer-remove:hover { color: #ef4444; }

/* Transform panel */
.sc-transform {
    border-top: 1px solid rgba(255,255,255,0.07);
    padding: 12px 14px;
    display: flex; flex-direction: column; gap: 8px;
    flex-shrink: 0;
}
.sc-transform-title { font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.06em; }
.sc-preset-row { display: flex; gap: 4px; }
.sc-preset-btn {
    flex: 1; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);
    color: #94a3b8; border-radius: 4px; padding: 4px 2px; font-size: 12px;
    cursor: pointer; transition: all 0.15s;
}
.sc-preset-btn:hover { background: rgba(255,255,255,0.12); color: #e2e8f0; border-color: #5865f2; }
.sc-slider-row { display: flex; flex-direction: column; gap: 4px; }
.sc-slider-field { display: flex; align-items: center; gap: 6px; }
.sc-slider-label { font-size: 10px; font-weight: 700; color: #475569; width: 10px; flex-shrink: 0; }
.sc-slider { flex: 1; accent-color: #5865f2; min-width: 0; }
.sc-slider-val { font-size: 10px; color: #64748b; width: 30px; text-align: right; flex-shrink: 0; }

/* Footer */
.sc-footer {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 14px;
    background: #13151f; border-top: 1px solid rgba(255,255,255,0.08);
    flex-shrink: 0;
}
.sc-status { font-size: 12px; color: #475569; }
.sc-status--live { color: #f87171; }

/* Shared buttons */
.sc-btn {
    padding: 7px 16px; border-radius: 6px; font-size: 13px; font-weight: 600;
    cursor: pointer; transition: all 0.15s; border: 1px solid rgba(255,255,255,0.1);
    background: rgba(255,255,255,0.06); color: #e2e8f0;
}
.sc-btn:hover { background: rgba(255,255,255,0.12); }
.sc-btn--primary { background: #5865f2; border-color: #5865f2; color: #fff; }
.sc-btn--primary:hover { background: #4752c4; }
.sc-btn--ghost { background: transparent; color: #64748b; border-color: transparent; width: 100%; }
.sc-btn--ghost:hover { color: #e2e8f0; background: rgba(255,255,255,0.06); }
.sc-btn--stop { background: rgba(239,68,68,0.15); border-color: rgba(239,68,68,0.4); color: #f87171; }
.sc-btn--stop:hover { background: rgba(239,68,68,0.3); }

/* Modals */
.sc-modal-overlay {
    position: fixed; inset: 0; z-index: 100;
    background: rgba(0,0,0,0.6);
    display: flex; align-items: center; justify-content: center;
}
.sc-modal {
    background: #1a1d27; border: 1px solid rgba(255,255,255,0.1);
    border-radius: 12px; padding: 20px; min-width: 280px; max-width: 440px;
    display: flex; flex-direction: column; gap: 12px;
}
.sc-modal-title { font-size: 14px; font-weight: 700; color: #e2e8f0; }
.sc-source-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 8px; }
.sc-source-opt {
    display: flex; flex-direction: column; align-items: center; gap: 6px;
    padding: 14px 8px; background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08); border-radius: 8px;
    cursor: pointer; color: #cbd5e1; transition: all 0.15s;
}
.sc-source-opt:hover { background: rgba(255,255,255,0.1); border-color: #5865f2; color: #fff; }
.sc-source-icon { font-size: 22px; }
.sc-source-label { font-size: 11px; font-weight: 600; text-align: center; }
.sc-device-list { display: flex; flex-direction: column; gap: 6px; }
.sc-device-btn {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 12px; background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08); border-radius: 8px;
    color: #e2e8f0; cursor: pointer; transition: all 0.15s; font-size: 13px;
}
.sc-device-btn:hover { background: rgba(255,255,255,0.1); border-color: #5865f2; }

/* Settings overlay */
.sc-settings-overlay {
    position: absolute; inset: 0; z-index: 50;
    background: rgba(0,0,0,0.5);
    display: flex; align-items: flex-start; justify-content: flex-end;
}
.sc-settings-panel {
    background: #1a1d27; border: 1px solid rgba(255,255,255,0.1);
    border-radius: 0 0 0 12px; padding: 20px; width: 280px;
    display: flex; flex-direction: column; gap: 16px; max-height: 100%;
}
.sc-field-group { display: flex; flex-direction: column; gap: 6px; }
.sc-label { font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
.sc-row { display: flex; align-items: center; gap: 8px; }
.sc-select {
    background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);
    color: #e2e8f0; border-radius: 6px; padding: 6px 10px; font-size: 13px; cursor: pointer;
}
.sc-input-sm {
    background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);
    color: #e2e8f0; border-radius: 6px; padding: 6px 10px; font-size: 13px; width: 72px;
}
.sc-unit { font-size: 12px; color: #64748b; }

/* Misc */
.sc-empty { font-size: 12px; color: #374151; text-align: center; padding: 16px 8px; }

/* ── Plex panel ── */
.sc-plex { border-top: 1px solid rgba(255,255,255,0.08); flex-shrink: 0; }
.sc-plex-hdr {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 14px; cursor: pointer; user-select: none;
    background: #13151f; transition: background 0.15s;
}
.sc-plex-hdr:hover { background: rgba(255,255,255,0.04); }
.sc-plex-hdr-icon  { font-size: 14px; }
.sc-plex-hdr-label { font-size: 12px; font-weight: 700; color: #94a3b8; flex-shrink: 0; }
.sc-plex-hdr-title { font-size: 11px; color: #64748b; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.sc-plex-hdr-live  { font-size: 10px; color: #4ade80; flex-shrink: 0; }
.sc-plex-chevron   { font-size: 9px; color: #475569; margin-left: auto; flex-shrink: 0; }

.sc-plex-body { padding: 10px 14px 14px; display: flex; flex-direction: column; gap: 8px; max-height: 260px; overflow-y: auto; }

.sc-plex-pin-block  { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 6px 0; }
.sc-plex-pin-hint   { font-size: 12px; color: #94a3b8; text-align: center; }
.sc-plex-pin-code   { font-size: 26px; font-weight: 800; color: #e2a31a; letter-spacing: 0.15em; font-family: monospace; }
.sc-plex-pin-waiting { font-size: 11px; color: #64748b; display: flex; align-items: center; gap: 6px; }
.sc-plex-spinner    { display: inline-block; width: 12px; height: 12px; border: 2px solid rgba(255,255,255,0.1); border-top-color: #e2a31a; border-radius: 50%; animation: plexSpin 0.8s linear infinite; }
@keyframes plexSpin { to { transform: rotate(360deg); } }

.sc-plex-controls   { display: flex; flex-direction: column; gap: 8px; }
.sc-plex-now-playing { font-size: 13px; font-weight: 600; color: #e2e8f0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sc-plex-transport  { display: flex; align-items: center; gap: 6px; background: rgba(0,0,0,0.3); border-radius: 6px; padding: 6px 8px; }
.sc-plex-ctrl-btn   { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); color: #e2e8f0; border-radius: 5px; width: 28px; height: 28px; cursor: pointer; font-size: 12px; transition: all 0.15s; flex-shrink: 0; }
.sc-plex-ctrl-btn:hover { background: rgba(255,255,255,0.16); }
.sc-plex-ctrl-btn--stop { color: #f87171; }
.sc-plex-seek-wrap  { flex: 1; display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.sc-plex-seek       { width: 100%; accent-color: #e2a31a; cursor: pointer; }
.sc-plex-time       { font-size: 10px; color: #64748b; text-align: right; }
.sc-plex-empty-hint { font-size: 12px; color: #475569; }
.sc-plex-actions    { display: flex; align-items: center; justify-content: space-between; gap: 6px; }
.sc-plex-disconnect { background: none; border: none; color: #374151; font-size: 11px; cursor: pointer; padding: 0; transition: color 0.15s; }
.sc-plex-disconnect:hover { color: #64748b; }

/* Browser */
.sc-plex-browser    { display: flex; flex-direction: column; gap: 6px; }
.sc-plex-back-btn   { background: none; border: none; color: #94a3b8; font-size: 12px; cursor: pointer; text-align: left; padding: 2px 0; }
.sc-plex-back-btn:hover { color: #e2e8f0; }
.sc-plex-browse-title { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.06em; padding: 4px 0 2px; }
.sc-plex-loading    { font-size: 12px; color: #475569; padding: 6px 0; }
.sc-plex-empty      { font-size: 12px; color: #374151; padding: 6px 0; }
.sc-plex-list       { display: flex; flex-direction: column; gap: 4px; }
.sc-plex-list-item  { display: flex; align-items: center; gap: 8px; padding: 7px 10px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.07); border-radius: 6px; color: #e2e8f0; cursor: pointer; font-size: 13px; transition: all 0.15s; text-align: left; }
.sc-plex-list-item:hover { background: rgba(255,255,255,0.08); border-color: #e2a31a; }
.sc-plex-grid       { display: grid; grid-template-columns: repeat(auto-fill, minmax(72px, 1fr)); gap: 6px; }
.sc-plex-grid-item  { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 6px 4px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.07); border-radius: 6px; cursor: pointer; color: #cbd5e1; transition: all 0.15s; }
.sc-plex-grid-item:hover { background: rgba(255,255,255,0.1); border-color: #e2a31a; }
.sc-plex-thumb      { width: 100%; aspect-ratio: 2/3; object-fit: cover; border-radius: 3px; }
.sc-plex-thumb-ph   { font-size: 22px; }
.sc-plex-item-label { font-size: 10px; text-align: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; width: 100%; }
</style>
