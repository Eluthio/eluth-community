<template>
    <div class="pw-overlay">
        <div class="pw-panel">
            <div class="pw-title">📹 Video Call</div>
            <div class="pw-preview-wrap">
                <video ref="previewEl" class="pw-preview" autoplay muted playsinline />
            </div>
            <div class="pw-status" :class="`pw-status--${statusClass}`">{{ statusText }}</div>
            <button class="pw-leave-btn" @click="leave">Leave</button>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'

const props = defineProps({
    roomId:        { type: [String, Number], required: true },
    authToken:     { type: String, required: true },
    apiBase:       { type: String, required: true },
    currentMember: { type: Object, default: null },
})

const emit = defineEmits(['close'])

const STUN = [{ urls: 'stun:stun.l.google.com:19302' }]

const previewEl = ref(null)
const status    = ref('requesting')

const statusText = computed(() => {
    switch (status.value) {
        case 'requesting':  return 'Requesting camera…'
        case 'connecting':  return 'Connecting…'
        case 'connected':   return 'Connected'
        case 'error':       return 'Error — could not connect'
        default:            return ''
    }
})

const statusClass = computed(() => status.value)

function getMemberId() {
    try { return String(JSON.parse(atob(props.authToken.split('.')[1])).sub) } catch { return 'unknown' }
}

let localStream   = null
let pc            = null
let pollTimer     = null
const memberId    = getMemberId()
const gatheredIce = []

async function apiPut(path, body) {
    const res = await fetch(`${props.apiBase}${path}`, {
        method: 'PUT',
        headers: { Authorization: `Bearer ${props.authToken}`, 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
    })
    if (!res.ok) throw new Error(`PUT ${path} → ${res.status}`)
    return res.json()
}

async function apiGet(path) {
    const res = await fetch(`${props.apiBase}${path}`, {
        headers: { Authorization: `Bearer ${props.authToken}`, Accept: 'application/json' },
    })
    if (!res.ok) throw new Error(`GET ${path} → ${res.status}`)
    return res.json()
}

async function start() {
    status.value = 'requesting'
    try {
        localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true })
    } catch (e) {
        status.value = 'error'
        console.warn('[ParticipantWindow] getUserMedia failed', e)
        return
    }

    if (previewEl.value) {
        previewEl.value.srcObject = localStream
    }

    pc = new RTCPeerConnection({ iceServers: STUN })

    for (const track of localStream.getTracks()) {
        pc.addTrack(track, localStream)
    }

    pc.onicecandidate = (e) => {
        if (e.candidate) gatheredIce.push(e.candidate.toJSON())
    }

    pc.onconnectionstatechange = () => {
        if (pc.connectionState === 'connected') {
            status.value = 'connected'
        } else if (pc.connectionState === 'failed' || pc.connectionState === 'disconnected') {
            status.value = 'error'
        }
    }

    const offer = await pc.createOffer()
    await pc.setLocalDescription(offer)

    // Wait for ICE gathering
    await new Promise(resolve => {
        if (pc.iceGatheringState === 'complete') { resolve(); return }
        const check = () => { if (pc.iceGatheringState === 'complete') { pc.removeEventListener('icegatheringstatechange', check); resolve() } }
        pc.addEventListener('icegatheringstatechange', check)
        setTimeout(resolve, 3000)
    })

    const username = props.currentMember?.username ?? getMemberId()

    const dataUpdate = {}
    dataUpdate[`${memberId}_offer`]    = pc.localDescription.sdp
    dataUpdate[`${memberId}_username`] = username
    dataUpdate[`${memberId}_ice_p`]    = gatheredIce.slice()

    try {
        await apiPut(`/plugin-rooms/${props.roomId}/data`, dataUpdate)
    } catch (e) {
        console.warn('[ParticipantWindow] Failed to PUT offer', e)
        status.value = 'error'
        return
    }

    status.value = 'connecting'
    pollTimer    = setInterval(pollForAnswer, 1500)
}

async function pollForAnswer() {
    let roomData
    try {
        const res = await apiGet(`/plugin-rooms/${props.roomId}`)
        roomData = res.data ?? {}
    } catch { return }

    // Upload any new ICE
    const update = {}
    update[`${memberId}_ice_p`] = gatheredIce.slice()
    apiPut(`/plugin-rooms/${props.roomId}/data`, update).catch(() => {})

    const answerSdp = roomData[`${memberId}_answer`]
    if (!answerSdp || pc.remoteDescription) return

    try {
        await pc.setRemoteDescription({ type: 'answer', sdp: answerSdp })
    } catch (e) {
        console.warn('[ParticipantWindow] setRemoteDescription failed', e)
        return
    }

    const hostIce = roomData[`${memberId}_ice_h`]
    if (Array.isArray(hostIce)) {
        for (const c of hostIce) {
            try { await pc.addIceCandidate(c) } catch { /* ignore */ }
        }
    }

    clearInterval(pollTimer)
    pollTimer = null
}

function leave() {
    clearInterval(pollTimer)
    localStream?.getTracks().forEach(t => t.stop())
    pc?.close()
    emit('close')
}

onMounted(() => { start() })

onUnmounted(() => {
    clearInterval(pollTimer)
    localStream?.getTracks().forEach(t => t.stop())
    pc?.close()
})
</script>

<style scoped>
.pw-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.85);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.pw-panel {
    background: #0f1117;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    width: 400px;
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    color: #e2e8f0;
}

.pw-title {
    font-size: 18px;
    font-weight: 700;
    text-align: center;
}

.pw-preview-wrap {
    background: #000;
    border-radius: 8px;
    overflow: hidden;
    aspect-ratio: 4/3;
}

.pw-preview {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transform: scaleX(-1);
}

.pw-status {
    font-size: 13px;
    text-align: center;
    padding: 6px 12px;
    border-radius: 6px;
    background: rgba(255, 255, 255, 0.05);
}

.pw-status--connected  { color: #4ade80; }
.pw-status--connecting { color: #facc15; }
.pw-status--requesting { color: #94a3b8; }
.pw-status--error      { color: #f87171; }

.pw-leave-btn {
    background: #7f1d1d;
    border: 1px solid #991b1b;
    color: #fca5a5;
    border-radius: 8px;
    padding: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
}

.pw-leave-btn:hover {
    background: #991b1b;
    color: #fff;
}
</style>
