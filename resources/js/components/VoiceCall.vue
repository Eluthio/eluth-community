<template>
    <div class="call-panel" @click.stop>
        <!-- Video area — always shown; displays remote video or avatar placeholder -->
        <div class="call-video-area">
            <video
                v-if="remoteVideoActive"
                ref="remoteVideoEl"
                class="call-remote-video"
                autoplay
                playsinline
            />
            <div v-else class="call-video-placeholder">
                <div class="call-avatar">{{ initials(remoteName) }}</div>
                <div class="call-pname">{{ remoteName }}</div>
                <span v-if="!connected" class="call-connecting">Connecting…</span>
            </div>

            <!-- Local video pip -->
            <video
                v-if="videoEnabled && localVideoActive"
                ref="localVideoEl"
                class="call-video-pip"
                autoplay
                playsinline
                muted
            />

            <!-- Hidden audio element — srcObject set in ontrack -->
            <audio ref="remoteAudioEl" autoplay playsinline style="display:none" />
        </div>

        <!-- Controls -->
        <div class="call-controls">
            <button
                class="call-btn"
                :class="{ 'call-btn--active': !micMuted }"
                @click="toggleMic"
                :title="micMuted ? 'Unmute' : 'Mute'"
            >{{ micMuted ? '🔇' : '🎤' }}</button>
            <button
                class="call-btn"
                :class="{ 'call-btn--active': videoEnabled }"
                @click="toggleVideo"
                :title="videoEnabled ? 'Stop video' : 'Start video'"
            >{{ videoEnabled ? '📹' : '📷' }}</button>
            <button class="call-btn call-btn--end" @click="endCall" title="End call">📵</button>
        </div>

        <div v-if="error" class="call-error">{{ error }}</div>
    </div>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted, nextTick } from 'vue'

const props = defineProps({
    convId:       { type: String,  required: true },
    centralUrl:   { type: String,  required: true },
    centralToken: { type: String,  required: true },
    centralEcho:  { type: Object,  required: true },
    localName:    { type: String,  default: 'You' },
    remoteName:   { type: String,  default: '' },
    videoCall:    { type: Boolean, default: false },
    isCaller:     { type: Boolean, required: true },
    remoteOffer:  { type: String,  default: null },   // SDP offer (callee only)
})

const emit = defineEmits(['ended'])

const connected       = ref(false)
const micMuted        = ref(false)
const videoEnabled    = ref(props.videoCall)
const localVideoActive  = ref(false)
const remoteVideoActive = ref(false)
const error           = ref('')
const remoteVideoEl   = ref(null)
const remoteAudioEl   = ref(null)
const localVideoEl    = ref(null)

const ICE_SERVERS = [
    { urls: 'stun:stun.l.google.com:19302' },
    { urls: 'stun:stun1.l.google.com:19302' },
]

let pc          = null
let localStream = null
let videoSender = null
const pendingCandidates = []

// ── API helper ─────────────────────────────────────────────────────────────

async function api(path, body) {
    const res = await fetch(props.centralUrl + '/api' + path, {
        method: 'POST',
        headers: {
            Authorization:  'Bearer ' + props.centralToken,
            Accept:         'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(body),
    })
    if (!res.ok) throw new Error(await res.text())
    return res.json()
}

function sendSignal(type, data, extra = {}) {
    return api(`/dm/conversations/${props.convId}/call/signal`, { type, data, ...extra })
}

// ── Signal listener ────────────────────────────────────────────────────────

function listenForSignals() {
    const userId = JSON.parse(atob(props.centralToken.split('.')[1])).sub
    props.centralEcho.channel('user.' + userId).listen('.call.signal', async ({ conv_id, type, data }) => {
        if (conv_id !== props.convId || !pc) return

        if (type === 'answer' || type === 'reanswer') {
            await pc.setRemoteDescription({ type: 'answer', sdp: atob(data) })
            for (const c of pendingCandidates.splice(0)) {
                await pc.addIceCandidate(c).catch(() => {})
            }
        } else if (type === 'reoffer') {
            await pc.setRemoteDescription({ type: 'offer', sdp: atob(data) })
            for (const c of pendingCandidates.splice(0)) {
                await pc.addIceCandidate(c).catch(() => {})
            }
            const answer = await pc.createAnswer()
            await pc.setLocalDescription(answer)
            await sendSignal('reanswer', btoa(pc.localDescription.sdp))
        } else if (type === 'ice-candidate') {
            if (pc.remoteDescription) {
                await pc.addIceCandidate(data).catch(() => {})
            } else {
                pendingCandidates.push(data)
            }
        } else if (type === 'hangup') {
            await cleanup()
            emit('ended')
        }
    })
}

// ── Peer connection setup ──────────────────────────────────────────────────

async function setup() {
    localStream = await navigator.mediaDevices.getUserMedia({
        audio: true,
        video: props.videoCall,
    })

    if (props.videoCall) localVideoActive.value = true

    pc = new RTCPeerConnection({ iceServers: ICE_SERVERS })

    localStream.getTracks().forEach(t => {
        const sender = pc.addTrack(t, localStream)
        if (t.kind === 'video') videoSender = sender
    })

    pc.ontrack = async (e) => {
        const stream = e.streams[0] ?? new MediaStream([e.track])
        if (e.track.kind === 'audio' && remoteAudioEl.value) {
            remoteAudioEl.value.srcObject = stream
            remoteAudioEl.value.play().catch(() => {})
        }
        if (e.track.kind === 'video') {
            remoteVideoActive.value = true
            await nextTick()
            if (remoteVideoEl.value) {
                remoteVideoEl.value.srcObject = stream
                remoteVideoEl.value.play().catch(() => {})
            }
            e.track.onended = () => { remoteVideoActive.value = false }
        }
        connected.value = true
    }

    pc.oniceconnectionstatechange = () => {
        if (pc.iceConnectionState === 'failed') {
            error.value = 'Connection failed — peers could not reach each other'
        }
    }

    pc.onicecandidate = (e) => {
        if (e.candidate) sendSignal('ice-candidate', e.candidate.toJSON()).catch(() => {})
    }

    pc.onconnectionstatechange = () => {
        if (pc?.connectionState === 'connected' && remoteAudioEl.value?.srcObject) {
            remoteAudioEl.value.play().catch(() => {})
        }
        if (pc?.connectionState === 'disconnected' || pc?.connectionState === 'failed') {
            emit('ended')
        }
    }

    listenForSignals()

    if (props.isCaller) {
        const offer = await pc.createOffer()
        await pc.setLocalDescription(offer)
        await sendSignal('offer', btoa(pc.localDescription.sdp), { video: props.videoCall })
    } else {
        const sdp = atob(props.remoteOffer)
        await pc.setRemoteDescription({ type: 'offer', sdp })
        for (const c of pendingCandidates.splice(0)) {
            await pc.addIceCandidate(c).catch(() => {})
        }
        const answer = await pc.createAnswer()
        await pc.setLocalDescription(answer)
        await sendSignal('answer', btoa(pc.localDescription.sdp))
    }
}

// ── Controls ──────────────────────────────────────────────────────────────

function toggleMic() {
    micMuted.value = !micMuted.value
    localStream?.getAudioTracks().forEach(t => { t.enabled = !micMuted.value })
}

async function toggleVideo() {
    if (videoEnabled.value) {
        // Turn video off — remove track and renegotiate
        videoEnabled.value = false
        localVideoActive.value = false
        if (videoSender) { pc?.removeTrack(videoSender); videoSender = null }
        localStream?.getVideoTracks().forEach(t => { t.stop(); localStream.removeTrack(t) })
    } else {
        // Turn video on — acquire camera, add track, renegotiate
        try {
            const vs = await navigator.mediaDevices.getUserMedia({ video: true })
            const vt = vs.getVideoTracks()[0]
            localStream.addTrack(vt)
            videoSender = pc.addTrack(vt, localStream)
            videoEnabled.value = true
            localVideoActive.value = true
        } catch (e) {
            error.value = 'Could not access camera: ' + e.message
            return
        }
    }
    // Renegotiate so the remote side learns about the track change
    try {
        const offer = await pc.createOffer()
        await pc.setLocalDescription(offer)
        await sendSignal('reoffer', btoa(pc.localDescription.sdp))
    } catch (e) {
        console.error('[VoiceCall] renegotiation failed:', e)
    }
}

async function endCall() {
    await sendSignal('hangup', 'end').catch(() => {})
    await cleanup()
    emit('ended')
}

async function cleanup() {
    props.centralEcho?.channel('user.' + JSON.parse(atob(props.centralToken.split('.')[1])).sub)
        ?.stopListening('.call.signal')
    localStream?.getTracks().forEach(t => t.stop())
    pc?.close()
    pc = null
    videoSender = null
}

// ── Local video attachment ─────────────────────────────────────────────────

watch(localVideoActive, async (active) => {
    if (active) {
        await nextTick()
        if (localVideoEl.value) localVideoEl.value.srcObject = localStream
    }
})

function initials(name = '') { return name.slice(0, 2).toUpperCase() }

onMounted(() => setup().catch(e => { error.value = 'Could not start call: ' + e.message }))
onUnmounted(cleanup)
</script>

<style scoped>
.call-panel {
    background: #0d1117;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 10px 12px 8px;
    flex-shrink: 0;
}

.call-video-area {
    position: relative;
    width: 100%;
    background: #000;
    border-radius: 8px;
    min-height: 180px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.call-remote-video {
    width: 100%;
    display: block;
    border-radius: 8px;
}

.call-video-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 20px;
}

.call-avatar {
    width: 60px; height: 60px; border-radius: 50%;
    background: rgba(255,255,255,0.08);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; font-weight: 700; color: rgba(255,255,255,0.7);
}

.call-pname      { font-size: 13px; font-weight: 600; color: rgba(255,255,255,0.85); }
.call-connecting { font-size: 11px; color: #94a3b8; }

.call-video-pip {
    position: absolute;
    bottom: 8px; right: 8px;
    width: 80px; border-radius: 6px;
    border: 2px solid rgba(255,255,255,0.15);
    background: #000;
}

.call-controls { display: flex; justify-content: center; gap: 10px; }

.call-btn {
    width: 40px; height: 40px; border-radius: 50%; border: none;
    background: rgba(255,255,255,0.08); font-size: 18px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background 0.15s;
}
.call-btn:hover       { background: rgba(255,255,255,0.14); }
.call-btn--active     { background: rgba(34,211,238,0.15); }
.call-btn--end        { background: rgba(248,113,113,0.2); }
.call-btn--end:hover  { background: rgba(248,113,113,0.4); }

.call-error { font-size: 12px; color: #f87171; text-align: center; }
</style>
