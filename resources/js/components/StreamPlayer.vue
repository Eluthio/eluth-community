<template>
    <div class="stream-player">
        <!-- Unsupported browser -->
        <div v-if="!supported" class="stream-unsupported">
            <div class="stream-unsupported-icon">📺</div>
            <div>Live streaming is not supported in this browser.</div>
            <div class="stream-unsupported-hint">Try Chrome, Firefox, or Edge.</div>
        </div>

        <template v-else>
            <video
                ref="videoEl"
                class="stream-video"
                autoplay
                muted
                playsinline
                @click="toggleMute"
            />

            <!-- Stream overlay info -->
            <div class="stream-overlay">
                <div class="stream-live-badge">● LIVE</div>
                <div class="stream-streamer">{{ streamerUsername }}</div>
                <div class="stream-duration">{{ duration }}</div>
                <button class="stream-mute-btn" @click="toggleMute" :title="muted ? 'Unmute' : 'Mute'">
                    {{ muted ? '🔇' : '🔊' }}
                </button>
            </div>

            <!-- Buffering indicator -->
            <div v-if="buffering" class="stream-buffering">
                <div class="stream-spinner" />
                <span>Buffering…</span>
            </div>

            <!-- Catch-up indicator -->
            <div v-if="catchingUp && !buffering" class="stream-catching-up">
                Loading stream… {{ catchUpProgress }}%
            </div>
        </template>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue'

const props = defineProps({
    channelId:       { type: String, required: true },
    apiBase:         { type: String, default: '' },   // e.g. '' (relative) or 'https://community.eluth.io'
    streamerUsername: { type: String, default: '' },
    startedAt:       { type: String, default: null },
})

const emit = defineEmits(['error'])

const videoEl      = ref(null)
const supported    = ref(true)
const buffering    = ref(true)
const catchingUp   = ref(false)
const catchUpProgress = ref(0)
const muted        = ref(true)   // autoplay requires muted; user can unmute
const duration     = ref('')

let mediaSource    = null
let sourceBuffer   = null
let pollTimer      = null
let durationTimer  = null
let latestSeqKnown = -1
let nextSeqToFetch = 0
let fetchingChunk  = false
let destroyed      = false
let mimeType       = 'video/webm;codecs=vp8,opus'
let startTs        = props.startedAt ? new Date(props.startedAt).getTime() : Date.now()

// ── Compatibility check ─────────────────────────────────────────────────────
function checkSupport() {
    if (typeof MediaSource === 'undefined') { supported.value = false; return false }
    const types = ['video/webm;codecs=vp8,opus', 'video/webm;codecs=vp9,opus', 'video/webm']
    const ok = types.some(t => MediaSource.isTypeSupported(t))
    if (!ok) { supported.value = false; return false }
    return true
}

// ── Duration display ────────────────────────────────────────────────────────
function updateDuration() {
    const elapsed = Math.floor((Date.now() - startTs) / 1000)
    const h = Math.floor(elapsed / 3600)
    const m = Math.floor((elapsed % 3600) / 60)
    const s = elapsed % 60
    duration.value = h > 0
        ? `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`
        : `${m}:${String(s).padStart(2,'0')}`
}

// ── Fetch a single chunk ────────────────────────────────────────────────────
async function fetchChunk(seq) {
    const url = `${props.apiBase}/api/streams/${props.channelId}/chunks/${seq}`
    const res = await fetch(url)
    if (!res.ok) throw new Error(`chunk ${seq} returned ${res.status}`)
    return res.arrayBuffer()
}

// ── Append a buffer to MSE ──────────────────────────────────────────────────
function appendBuffer(data) {
    return new Promise((resolve, reject) => {
        if (!sourceBuffer || sourceBuffer.updating) {
            reject(new Error('SourceBuffer not ready'))
            return
        }
        const onEnd = () => { sourceBuffer.removeEventListener('updateend', onEnd); resolve() }
        const onErr = (e) => { sourceBuffer.removeEventListener('error', onErr); reject(e) }
        sourceBuffer.addEventListener('updateend', onEnd)
        sourceBuffer.addEventListener('error', onErr)
        try {
            sourceBuffer.appendBuffer(data)
        } catch (e) {
            sourceBuffer.removeEventListener('updateend', onEnd)
            sourceBuffer.removeEventListener('error', onErr)
            reject(e)
        }
    })
}

// ── Pump chunks ─────────────────────────────────────────────────────────────
async function pumpChunks() {
    if (destroyed || fetchingChunk || !sourceBuffer || sourceBuffer.updating) return
    if (nextSeqToFetch > latestSeqKnown) return   // caught up — wait for poll

    fetchingChunk = true
    try {
        const data = await fetchChunk(nextSeqToFetch)
        if (destroyed) return
        await appendBuffer(data)

        catchUpProgress.value = latestSeqKnown > 0
            ? Math.min(99, Math.round((nextSeqToFetch / latestSeqKnown) * 100))
            : 100

        nextSeqToFetch++
        buffering.value = false

        // Seek to live edge once we've buffered enough to start playing near-live
        if (catchingUp.value && nextSeqToFetch >= latestSeqKnown) {
            catchingUp.value = false
            seekToLive()
        }

        // Keep pumping
        if (nextSeqToFetch <= latestSeqKnown) {
            fetchingChunk = false
            pumpChunks()
        } else {
            fetchingChunk = false
        }
    } catch (e) {
        fetchingChunk = false
        // Chunk may not exist yet (cleaned up or not uploaded) — skip and move on
        if (e.message?.includes('404') || e.message?.includes('not_found')) {
            nextSeqToFetch++
        }
    }
}

function seekToLive() {
    const video = videoEl.value
    if (!video || !video.buffered?.length) return
    const end = video.buffered.end(video.buffered.length - 1)
    if (end > 2) video.currentTime = end - 1
}

// ── Poll state ──────────────────────────────────────────────────────────────
async function pollState() {
    if (destroyed) return
    try {
        const res = await fetch(`${props.apiBase}/api/streams/${props.channelId}/state`)
        if (!res.ok) return
        const state = await res.json()

        if (!state.is_live) {
            // Stream ended
            emit('error', 'ended')
            return
        }

        const newLatest = state.latest_seq ?? -1
        if (newLatest > latestSeqKnown) {
            latestSeqKnown = newLatest
            pumpChunks()
        }
    } catch { /* network hiccup — try again next poll */ }
}

// ── Init MSE ─────────────────────────────────────────────────────────────────
async function initMse(initialMimeType, initialLatestSeq) {
    mimeType       = initialMimeType
    latestSeqKnown = initialLatestSeq

    nextSeqToFetch = 0   // always start with chunk-0 (contains WebM init data)

    // If the stream just started and has no chunks yet, MSE is still set up —
    // pumpChunks will simply wait (nextSeqToFetch > latestSeqKnown) until
    // pollState finds a chunk and calls pumpChunks again with an updated seq.
    if (latestSeqKnown > 5) {
        catchingUp.value = true
    }

    mediaSource = new MediaSource()
    videoEl.value.src = URL.createObjectURL(mediaSource)

    mediaSource.addEventListener('sourceopen', async () => {
        try {
            // Pick the best supported mimeType
            const candidateMimes = [mimeType, 'video/webm;codecs=vp8,opus', 'video/webm;codecs=vp9,opus', 'video/webm']
            const useMime = candidateMimes.find(m => MediaSource.isTypeSupported(m)) ?? 'video/webm'

            sourceBuffer = mediaSource.addSourceBuffer(useMime)
            sourceBuffer.mode = 'sequence'   // handle timestamps automatically

            pumpChunks()
        } catch (e) {
            supported.value = false
        }
    })
}

// ── Mute toggle ─────────────────────────────────────────────────────────────
function toggleMute() {
    if (!videoEl.value) return
    muted.value = !muted.value
    videoEl.value.muted = muted.value
}

// ── Lifecycle ────────────────────────────────────────────────────────────────
onMounted(async () => {
    if (!checkSupport()) return

    startTs = props.startedAt ? new Date(props.startedAt).getTime() : Date.now()
    updateDuration()
    durationTimer = setInterval(updateDuration, 1000)

    // Fetch initial state
    try {
        const res = await fetch(`${props.apiBase}/api/streams/${props.channelId}/state`)
        if (res.ok) {
            const state = await res.json()
            if (state.is_live) {
                await initMse(state.mime_type ?? 'video/webm;codecs=vp8,opus', state.latest_seq ?? -1)
            }
        }
    } catch { /* ignore — poll will retry */ }

    // Poll for new chunks every 2 seconds
    pollTimer = setInterval(pollState, 2000)
})

onUnmounted(() => {
    destroyed = true
    clearInterval(pollTimer)
    clearInterval(durationTimer)
    if (mediaSource && mediaSource.readyState === 'open') {
        try { mediaSource.endOfStream() } catch { }
    }
    if (videoEl.value) {
        videoEl.value.src = ''
        videoEl.value.load()
    }
})
</script>

<style scoped>
.stream-player {
    position: relative;
    width: 100%;
    background: #000;
    border-radius: 8px;
    overflow: hidden;
    aspect-ratio: 16 / 9;
    max-height: 420px;
    flex-shrink: 0;
}

.stream-video {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
}

.stream-overlay {
    position: absolute;
    top: 0; left: 0; right: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: linear-gradient(to bottom, rgba(0,0,0,0.7), transparent);
    pointer-events: none;
}

.stream-live-badge {
    background: #ef4444;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.05em;
    padding: 2px 7px;
    border-radius: 4px;
    pointer-events: none;
}

.stream-streamer {
    font-size: 13px;
    font-weight: 600;
    color: #fff;
    text-shadow: 0 1px 3px rgba(0,0,0,0.6);
}

.stream-duration {
    font-size: 12px;
    color: rgba(255,255,255,0.7);
    margin-left: auto;
}

.stream-mute-btn {
    pointer-events: all;
    background: rgba(0,0,0,0.4);
    border: none;
    border-radius: 6px;
    padding: 4px 8px;
    font-size: 14px;
    cursor: pointer;
    color: #fff;
    transition: background 0.15s;
}
.stream-mute-btn:hover { background: rgba(0,0,0,0.65); }

.stream-buffering,
.stream-catching-up {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    background: rgba(0,0,0,0.7);
    color: #e2e8f0;
    font-size: 13px;
}

.stream-spinner {
    width: 32px; height: 32px;
    border: 3px solid rgba(255,255,255,0.2);
    border-top-color: #22d3ee;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

.stream-unsupported {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    height: 100%;
    color: #64748b;
    font-size: 13px;
    text-align: center;
    padding: 20px;
}
.stream-unsupported-icon { font-size: 36px; }
.stream-unsupported-hint { font-size: 12px; color: #475569; }
</style>
