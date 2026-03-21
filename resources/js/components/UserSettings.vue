<template>
    <div class="settings-overlay" @click.self="$emit('close')">
        <div class="settings-modal">

            <nav class="settings-nav">
                <div class="settings-nav-server">My Settings</div>
                <div class="settings-nav-group-label">Account</div>
                <button :class="navClass('profile')"  @click="panel = 'profile'">Profile</button>
                <button :class="navClass('security')" @click="panel = 'security'">Security</button>
                <div class="settings-nav-divider" />
                <div class="settings-nav-group-label">Communication</div>
                <button :class="navClass('audio')"   @click="panel = 'audio'">Audio</button>
                <button :class="navClass('video')"   @click="panel = 'video'">Video</button>
                <div class="settings-nav-divider" />
                <div class="settings-nav-group-label">Client</div>
                <button :class="navClass('appearance')" @click="panel = 'appearance'">Appearance</button>
                <div class="settings-nav-divider" />
                <button class="settings-nav-item settings-nav-item--danger" @click="$emit('close')">Close</button>
            </nav>

            <div class="settings-content">
                <div class="settings-panel-title">{{ panelTitle }}</div>

                <!-- Profile -->
                <div v-if="panel === 'profile'" class="settings-panel">

                    <!-- Banner -->
                    <div class="settings-section">
                        <label class="settings-label">Banner</label>
                        <div class="usettings-banner-preview" :style="bannerPreviewStyle">
                            <span v-if="!currentBannerUrl && !bannerPreview" class="usettings-banner-empty">No banner set</span>
                        </div>
                        <div class="usettings-banner-controls">
                            <button class="settings-btn-secondary" @click="bannerFileInput?.click()">
                                Upload file
                            </button>
                            <span class="usettings-banner-or">or</span>
                            <input
                                class="settings-input usettings-banner-url"
                                type="url"
                                placeholder="Paste a GIF URL (Giphy, Tenor…)"
                                v-model="bannerUrlInput"
                                @keydown.enter.prevent="onBannerUrlSubmit"
                                @blur="onBannerUrlSubmit"
                            />
                            <button v-if="currentBannerUrl || bannerPreview" class="settings-btn-ghost usettings-banner-remove" @click="removeBanner" title="Remove banner">✕</button>
                        </div>
                        <div class="settings-hint">Recommended 960×480. JPEG, PNG, WebP or GIF. Max 4 MB. Paste a Giphy/Tenor link to use an animated GIF.</div>
                        <div v-if="bannerError" class="settings-error">{{ bannerError }}</div>
                        <div v-if="bannerSaved" class="settings-saved">Banner updated.</div>
                        <input ref="bannerFileInput" type="file" accept="image/*" style="display:none" @change="onBannerSelected" />
                    </div>

                    <!-- Avatar -->
                    <div class="settings-section">
                        <label class="settings-label">Avatar</label>
                        <div class="usettings-avatar-row">
                            <div class="usettings-avatar-preview" :style="{ borderColor: form.profile_accent || 'var(--accent)' }">
                                <img v-if="avatarPreview || currentAvatarUrl" :src="avatarPreview || currentAvatarUrl" @error="onAvatarError" />
                                <span v-else>{{ initials(username) }}</span>
                            </div>
                            <div class="usettings-avatar-actions">
                                <button class="settings-btn-secondary" @click="avatarFileInput?.click()">Upload image</button>
                                <div class="settings-hint">Square images work best. GIFs are supported (animated).</div>
                                <div v-if="avatarError" class="settings-error">{{ avatarError }}</div>
                                <div v-if="avatarSaved" class="settings-saved">Avatar updated.</div>
                            </div>
                        </div>
                        <input ref="avatarFileInput" type="file" accept="image/*" style="display:none" @change="onAvatarSelected" />
                    </div>

                    <!-- Display name -->
                    <div class="settings-section">
                        <label class="settings-label">Display Name</label>
                        <input class="settings-input" type="text" v-model="form.display_name" maxlength="64" placeholder="Your display name" />
                        <div class="settings-hint">Shown instead of your username in chats. 64 characters max.</div>
                    </div>

                    <!-- Bio -->
                    <div class="settings-section">
                        <label class="settings-label">Bio</label>
                        <textarea class="settings-input settings-textarea" v-model="form.bio" maxlength="190" rows="3" placeholder="A short bio…" />
                        <div class="settings-hint">{{ form.bio?.length ?? 0 }}/190</div>
                    </div>

                    <!-- Status -->
                    <div class="settings-section">
                        <label class="settings-label">Status</label>
                        <input class="settings-input" type="text" v-model="form.status_text" maxlength="128" placeholder="What are you up to?" />
                    </div>

                    <!-- Accent colour -->
                    <div class="settings-section">
                        <label class="settings-label">Accent Colour</label>
                        <div class="usettings-accent-row">
                            <input type="color" class="usettings-color-picker" v-model="form.profile_accent" />
                            <input class="settings-input usettings-accent-hex" type="text" v-model="form.profile_accent" maxlength="7" placeholder="#5865F2" />
                        </div>
                        <div class="settings-hint">Used for your username colour and profile highlights.</div>
                    </div>

                    <!-- Privacy -->
                    <div class="usettings-section-label">Privacy</div>
                    <div class="usettings-privacy-row">
                        <div class="usettings-privacy-info">
                            <span class="usettings-privacy-title">Friends only DMs</span>
                            <span class="usettings-privacy-hint">Only people you are friends with can message you directly</span>
                        </div>
                        <button
                            class="usettings-toggle"
                            :class="{ on: form.dmFriendsOnly }"
                            type="button"
                            @click="form.dmFriendsOnly = !form.dmFriendsOnly"
                            :aria-checked="form.dmFriendsOnly"
                            role="switch"
                        >
                            <span class="usettings-toggle-knob" />
                        </button>
                    </div>

                    <!-- Save -->
                    <div class="settings-section">
                        <button class="settings-btn-primary" @click="saveProfile" :disabled="profileSaving">
                            {{ profileSaving ? 'Saving…' : 'Save Changes' }}
                        </button>
                        <div v-if="profileError" class="settings-error" style="margin-top:8px;">{{ profileError }}</div>
                        <div v-if="profileSaved" class="settings-saved" style="margin-top:8px;">Profile updated.</div>
                    </div>

                    <div class="settings-section">
                        <label class="settings-label">Username</label>
                        <div class="settings-input" style="opacity:0.5;cursor:not-allowed;">{{ username }}</div>
                        <div class="settings-hint">Username changes are managed on eluth.io.</div>
                    </div>
                </div>

                <!-- Security -->
                <div v-else-if="panel === 'security'" class="settings-panel">
                    <div class="usettings-section-label">Change Password</div>

                    <div class="settings-section">
                        <label class="settings-label">Current password</label>
                        <input class="settings-input" type="password" v-model="pw.current" placeholder="Your current password" />
                    </div>
                    <div class="settings-section">
                        <label class="settings-label">New password</label>
                        <input class="settings-input" type="password" v-model="pw.next" placeholder="At least 8 characters" />
                    </div>
                    <div class="settings-section">
                        <label class="settings-label">Confirm new password</label>
                        <input class="settings-input" type="password" v-model="pw.confirm" placeholder="Repeat new password" />
                    </div>

                    <div class="settings-section">
                        <button class="settings-btn-primary" @click="changePassword" :disabled="pw.saving">
                            {{ pw.saving ? 'Updating…' : 'Update Password' }}
                        </button>
                        <div v-if="pw.error" class="settings-error" style="margin-top:8px;">{{ pw.error }}</div>
                        <div v-if="pw.saved" class="settings-saved" style="margin-top:8px;">Password updated successfully.</div>
                    </div>

                    <div class="settings-section" style="margin-top:8px;">
                        <div class="settings-hint" style="line-height:1.6;">
                            <strong>⚠️ Encrypted messages and forgotten passwords:</strong>
                            End-to-end encrypted messages can only be read with your password.
                            If you forget your password, encrypted message history cannot be recovered —
                            this is the nature of E2EE. Keep your password safe.
                            Changing your password here automatically re-encrypts your message key.
                        </div>
                    </div>
                </div>

                <!-- Audio -->
                <div v-else-if="panel === 'audio'" class="settings-panel">
                    <div class="settings-section">
                        <label class="settings-label">Microphone</label>
                        <select class="settings-input settings-select" v-model="audio.micId" @change="saveAudioPrefs">
                            <option value="">Default microphone</option>
                            <option v-for="d in micDevices" :key="d.deviceId" :value="d.deviceId">{{ d.label || 'Microphone ' + d.deviceId.slice(0,6) }}</option>
                        </select>
                    </div>

                    <div class="settings-section">
                        <label class="settings-label">Speaker / Output</label>
                        <select class="settings-input settings-select" v-model="audio.speakerId" @change="saveAudioPrefs">
                            <option value="">Default speaker</option>
                            <option v-for="d in speakerDevices" :key="d.deviceId" :value="d.deviceId">{{ d.label || 'Speaker ' + d.deviceId.slice(0,6) }}</option>
                        </select>
                    </div>

                    <div class="settings-section">
                        <label class="settings-label">Input level</label>
                        <div class="usettings-level-bar">
                            <div class="usettings-level-fill" :style="{ width: micLevel + '%' }" />
                        </div>
                        <div class="settings-hint" style="margin-top:6px;">
                            <button class="settings-btn-ghost" @click="testMic" :disabled="testingMic">
                                {{ testingMic ? 'Testing… (click to stop)' : 'Test microphone' }}
                            </button>
                        </div>
                    </div>

                    <div class="settings-section">
                        <label class="settings-label">Voice activation</label>
                        <label class="settings-radio">
                            <input type="radio" v-model="audio.voiceMode" value="vad" @change="saveAudioPrefs" />
                            Voice activity detection — mic activates automatically when you speak
                        </label>
                        <label class="settings-radio" style="margin-top:8px;">
                            <input type="radio" v-model="audio.voiceMode" value="ptt" @change="saveAudioPrefs" />
                            Push to talk
                        </label>

                        <template v-if="audio.voiceMode === 'vad'">
                            <div style="margin-top:14px;">
                                <label class="settings-label">Detection sensitivity <span class="settings-hint">{{ audio.vadThreshold }}%</span></label>
                                <input type="range" min="1" max="100" v-model.number="audio.vadThreshold" @change="saveAudioPrefs" class="usettings-slider" />
                                <div class="settings-hint">Higher = less sensitive (only loud sounds activate the mic)</div>
                            </div>
                        </template>

                        <template v-if="audio.voiceMode === 'ptt'">
                            <div class="usettings-ptt-row" style="margin-top:14px;">
                                <label class="settings-label">Key bind</label>
                                <button class="settings-btn-secondary" @click="listenForPtt" :class="{ active: listeningForPtt }">
                                    {{ listeningForPtt ? 'Press any key…' : (audio.pttKey || 'Click to set') }}
                                </button>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Video -->
                <div v-else-if="panel === 'video'" class="settings-panel">
                    <div class="settings-section">
                        <label class="settings-label">Camera</label>
                        <select class="settings-input settings-select" v-model="video.cameraId" @change="onCameraChange">
                            <option value="">No camera / off by default</option>
                            <option v-for="d in cameraDevices" :key="d.deviceId" :value="d.deviceId">{{ d.label || 'Camera ' + d.deviceId.slice(0,6) }}</option>
                        </select>
                    </div>

                    <div v-if="video.cameraId" class="settings-section">
                        <label class="settings-label">Preview</label>
                        <video ref="cameraPreview" class="usettings-camera-preview" autoplay muted playsinline />
                    </div>

                    <div class="settings-section">
                        <label class="settings-radio">
                            <input type="checkbox" v-model="video.cameraOnByDefault" @change="saveVideoPrefs" />
                            Enable camera by default when joining a video channel
                        </label>
                    </div>
                </div>

                <!-- Appearance -->
                <div v-else-if="panel === 'appearance'" class="settings-panel">
                    <div class="settings-section">
                        <label class="settings-label">Message density</label>
                        <label class="settings-radio">
                            <input type="radio" v-model="appearance.density" value="comfortable" @change="saveAppearancePrefs" />
                            Comfortable — more spacing between messages
                        </label>
                        <label class="settings-radio" style="margin-top:8px;">
                            <input type="radio" v-model="appearance.density" value="compact" @change="saveAppearancePrefs" />
                            Compact — tighter layout, more messages visible
                        </label>
                    </div>

                    <div class="settings-section">
                        <label class="settings-label">Font size</label>
                        <select class="settings-input settings-select" v-model="appearance.fontSize" @change="saveAppearancePrefs">
                            <option value="13px">Small</option>
                            <option value="14px">Medium (default)</option>
                            <option value="16px">Large</option>
                            <option value="18px">Extra large</option>
                        </select>
                    </div>
                </div>
            </div>

            <button class="settings-close" @click="$emit('close')" title="Close (Esc)">✕</button>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useE2ee } from '../composables/useE2ee.js'

const props = defineProps({
    username:   { type: String, default: '' },
    userId:     { type: String, default: '' },
    centralUrl: { type: String, default: '' },
})

const emit = defineEmits(['close', 'avatar-updated', 'profile-updated'])

// ── Nav ──────────────────────────────────────────────────────────────────────
const panel = ref('profile')
const panelTitle = computed(() => ({
    profile:    'Profile',
    security:   'Security',
    audio:      'Audio Settings',
    video:      'Video Settings',
    appearance: 'Appearance',
}[panel.value] ?? ''))

function navClass(p) {
    return ['settings-nav-item', panel.value === p ? 'active' : ''].filter(Boolean).join(' ')
}

function onEsc(e) { if (e.key === 'Escape') emit('close') }
onMounted(() => document.addEventListener('keydown', onEsc))
onUnmounted(() => {
    document.removeEventListener('keydown', onEsc)
    stopMicTest()
    stopCameraPreview()
})

function initials(name = '') {
    return name.slice(0, 2).toUpperCase()
}

// ── Profile / Avatar / Banner ─────────────────────────────────────────────────
const avatarFileInput  = ref(null)
const avatarPreview    = ref(null)
const avatarError      = ref('')
const avatarSaved      = ref(false)
const currentAvatarUrl = ref(null)

const bannerFileInput  = ref(null)
const bannerPreview    = ref(null)   // object URL or null
const currentBannerUrl = ref(null)
const bannerUrlInput   = ref('')
const bannerError      = ref('')
const bannerSaved      = ref(false)

const form = ref({
    display_name:   '',
    bio:            '',
    status_text:    '',
    profile_accent: '#5865F2',
    dmFriendsOnly:  false,
})

const profileSaving = ref(false)
const profileSaved  = ref(false)
const profileError  = ref('')

// ── Password change ───────────────────────────────────────────────────────
const e2ee = useE2ee()
const pw = ref({ current: '', next: '', confirm: '', saving: false, error: '', saved: false })

async function changePassword() {
    pw.value.error = ''
    pw.value.saved = false

    if (!pw.value.current || !pw.value.next || !pw.value.confirm) {
        pw.value.error = 'Please fill in all fields.'; return
    }
    if (pw.value.next !== pw.value.confirm) {
        pw.value.error = 'New passwords do not match.'; return
    }
    if (pw.value.next.length < 8) {
        pw.value.error = 'New password must be at least 8 characters.'; return
    }

    pw.value.saving = true
    try {
        const token = localStorage.getItem('eluth_token') ?? ''

        // Re-encrypt E2EE private key with new password before sending anything
        const newPrivateKeyEnc = await e2ee.reEncryptForPasswordChange(
            props.centralUrl, token, pw.value.current, pw.value.next
        )

        const body = {
            current_password: pw.value.current,
            new_password:     pw.value.next,
            ...(newPrivateKeyEnc ? { private_key_enc: newPrivateKeyEnc } : {}),
        }

        const res = await fetch(props.centralUrl + '/api/auth/change-password', {
            method:  'POST',
            headers: { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' },
            body:    JSON.stringify(body),
        })

        if (!res.ok) {
            const data = await res.json()
            pw.value.error = data.message ?? 'Failed to update password.'
            return
        }

        // Clear cached E2EE keys — they'll re-derive from the new password next unlock
        sessionStorage.removeItem('e2ee_private_key')
        window.electronAPI?.e2eeClearKey?.()

        pw.value.saved   = true
        pw.value.current = ''
        pw.value.next    = ''
        pw.value.confirm = ''
    } catch {
        pw.value.error = 'An error occurred. Please try again.'
    } finally {
        pw.value.saving = false
    }
}

const bannerPreviewStyle = computed(() => {
    const url = bannerPreview.value || currentBannerUrl.value
    if (!url) return {}
    return { backgroundImage: `url(${url})`, backgroundSize: 'cover', backgroundPosition: 'center' }
})

onMounted(async () => {
    try {
        const token = localStorage.getItem('eluth_token') ?? ''
        const res   = await fetch(props.centralUrl + '/api/profile', {
            headers: { Authorization: 'Bearer ' + token },
        })
        if (res.ok) {
            const data = await res.json()
            currentAvatarUrl.value      = data.avatar_url  ?? null
            currentBannerUrl.value      = data.banner_url  ?? null
            form.value.display_name     = data.display_name  ?? ''
            form.value.bio              = data.bio            ?? ''
            form.value.status_text      = data.status_text   ?? ''
            form.value.profile_accent   = data.profile_accent ?? '#5865F2'
            form.value.dmFriendsOnly    = data.dm_friends_only ?? false
        }
    } catch {}
})

function onAvatarError() { currentAvatarUrl.value = null }

async function onAvatarSelected(event) {
    const file = event.target.files?.[0]
    if (!file) return
    avatarError.value = ''
    avatarSaved.value = false

    try {
        const isGif = file.type === 'image/gif'
        let blob, filename

        if (isGif) {
            blob     = file
            filename = 'avatar.gif'
        } else {
            blob     = await resizeToSquare(file)
            filename = 'avatar.jpg'
        }

        avatarPreview.value = URL.createObjectURL(blob)

        const token   = localStorage.getItem('eluth_token') ?? ''
        const payload = new FormData()
        payload.append('avatar', blob, filename)

        const res  = await fetch(props.centralUrl + '/api/profile/avatar', {
            method: 'POST',
            headers: { Authorization: 'Bearer ' + token },
            body: payload,
        })
        const data = await res.json().catch(() => ({}))

        if (res.ok && data.avatar_url) {
            currentAvatarUrl.value = data.avatar_url
            avatarSaved.value = true
            emit('avatar-updated', data.avatar_url)
            setTimeout(() => { avatarSaved.value = false }, 3000)
        } else {
            avatarError.value   = data.error ?? `Upload failed (${res.status})`
            avatarPreview.value = null
        }
    } catch (err) {
        avatarError.value   = 'Upload failed: ' + (err?.message ?? 'unknown error')
        avatarPreview.value = null
    } finally {
        event.target.value = ''
    }
}

async function onBannerSelected(event) {
    const file = event.target.files?.[0]
    if (!file) return
    bannerError.value = ''
    bannerSaved.value = false
    bannerPreview.value = URL.createObjectURL(file)

    try {
        const token   = localStorage.getItem('eluth_token') ?? ''
        const payload = new FormData()
        payload.append('banner', file, file.name)

        const res  = await fetch(props.centralUrl + '/api/profile/banner', {
            method: 'POST',
            headers: { Authorization: 'Bearer ' + token },
            body: payload,
        })
        const data = await res.json().catch(() => ({}))

        if (res.ok && data.banner_url) {
            currentBannerUrl.value = data.banner_url
            bannerPreview.value    = null
            bannerSaved.value      = true
            setTimeout(() => { bannerSaved.value = false }, 3000)
        } else {
            bannerError.value   = data.error ?? `Upload failed (${res.status})`
            bannerPreview.value = null
        }
    } catch (err) {
        bannerError.value   = 'Upload failed: ' + (err?.message ?? 'unknown error')
        bannerPreview.value = null
    } finally {
        event.target.value = ''
    }
}

async function onBannerUrlSubmit() {
    const url = bannerUrlInput.value.trim()
    if (!url || url === currentBannerUrl.value) return
    bannerError.value = ''
    bannerSaved.value = false

    try {
        const token = localStorage.getItem('eluth_token') ?? ''
        const res   = await fetch(props.centralUrl + '/api/profile/banner-url', {
            method:  'POST',
            headers: { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' },
            body:    JSON.stringify({ url }),
        })
        const data = await res.json().catch(() => ({}))

        if (res.ok && data.banner_url) {
            currentBannerUrl.value = data.banner_url
            bannerPreview.value    = null
            bannerUrlInput.value   = ''
            bannerSaved.value      = true
            setTimeout(() => { bannerSaved.value = false }, 3000)
        } else {
            bannerError.value = data.error ?? `Failed (${res.status})`
        }
    } catch (err) {
        bannerError.value = 'Error: ' + (err?.message ?? 'unknown')
    }
}

function removeBanner() {
    bannerPreview.value    = null
    currentBannerUrl.value = null
    bannerUrlInput.value   = ''
    // TODO: call DELETE /api/profile/banner when endpoint exists
}

async function saveProfile() {
    profileSaving.value = true
    profileError.value  = ''
    profileSaved.value  = false

    try {
        const token = localStorage.getItem('eluth_token') ?? ''
        const res   = await fetch(props.centralUrl + '/api/profile/update', {
            method:  'POST',
            headers: { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                display_name:    form.value.display_name  || null,
                bio:             form.value.bio            || null,
                status_text:     form.value.status_text    || null,
                profile_accent:  form.value.profile_accent || null,
                dm_friends_only: form.value.dmFriendsOnly,
            }),
        })
        const data = await res.json().catch(() => ({}))

        if (res.ok) {
            profileSaved.value = true
            emit('profile-updated', data)
            setTimeout(() => { profileSaved.value = false }, 3000)
        } else {
            profileError.value = data.message ?? data.error ?? `Save failed (${res.status})`
        }
    } catch (err) {
        profileError.value = 'Error: ' + (err?.message ?? 'unknown')
    } finally {
        profileSaving.value = false
    }
}

function resizeToSquare(file) {
    return new Promise((resolve, reject) => {
        const img = new Image()
        const url = URL.createObjectURL(file)
        img.onload = () => {
            URL.revokeObjectURL(url)
            const size   = Math.min(img.width, img.height)
            const sx     = (img.width  - size) / 2
            const sy     = (img.height - size) / 2
            const canvas = document.createElement('canvas')
            canvas.width  = 256
            canvas.height = 256
            canvas.getContext('2d').drawImage(img, sx, sy, size, size, 0, 0, 256, 256)
            canvas.toBlob(blob => blob ? resolve(blob) : reject(new Error('Canvas export failed')), 'image/jpeg', 0.85)
        }
        img.onerror = reject
        img.src = url
    })
}

// ── Audio ────────────────────────────────────────────────────────────────────
const micDevices     = ref([])
const speakerDevices = ref([])
const micLevel       = ref(0)
const testingMic     = ref(false)

const audio = ref({
    micId:        localStorage.getItem('eluth_mic_id')        ?? '',
    speakerId:    localStorage.getItem('eluth_speaker_id')    ?? '',
    voiceMode:    localStorage.getItem('eluth_voice_mode')    ?? 'vad',
    vadThreshold: Number(localStorage.getItem('eluth_vad_threshold') ?? 20),
    pttKey:       localStorage.getItem('eluth_ptt_key')       ?? '',
})

const listeningForPtt = ref(false)

let micStream    = null
let micAnalyser  = null
let micRafHandle = null

async function loadDevices() {
    try {
        // Request permission first so labels are populated
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false }).catch(() => null)
        const devices = await navigator.mediaDevices.enumerateDevices()
        micDevices.value     = devices.filter(d => d.kind === 'audioinput')
        speakerDevices.value = devices.filter(d => d.kind === 'audiooutput')
        if (stream) stream.getTracks().forEach(t => t.stop())
    } catch {}
}

watch(() => panel.value, p => { if (p === 'audio') loadDevices() })

function saveAudioPrefs() {
    localStorage.setItem('eluth_mic_id',       audio.value.micId)
    localStorage.setItem('eluth_speaker_id',   audio.value.speakerId)
    localStorage.setItem('eluth_voice_mode',   audio.value.voiceMode)
    localStorage.setItem('eluth_vad_threshold',audio.value.vadThreshold)
    localStorage.setItem('eluth_ptt_key',      audio.value.pttKey)
}

async function testMic() {
    if (testingMic.value) { stopMicTest(); return }
    testingMic.value = true
    try {
        const constraints = { audio: audio.value.micId ? { deviceId: { exact: audio.value.micId } } : true }
        micStream = await navigator.mediaDevices.getUserMedia(constraints)
        const ctx      = new AudioContext()
        const source   = ctx.createMediaStreamSource(micStream)
        micAnalyser    = ctx.createAnalyser()
        micAnalyser.fftSize = 256
        source.connect(micAnalyser)
        const data = new Uint8Array(micAnalyser.frequencyBinCount)
        const tick = () => {
            if (!testingMic.value) return
            micAnalyser.getByteFrequencyData(data)
            const avg = data.reduce((a, b) => a + b, 0) / data.length
            micLevel.value = Math.min(100, Math.round((avg / 128) * 100))
            micRafHandle = requestAnimationFrame(tick)
        }
        tick()
    } catch {
        testingMic.value = false
    }
}

function stopMicTest() {
    testingMic.value = false
    micLevel.value   = 0
    cancelAnimationFrame(micRafHandle)
    micStream?.getTracks().forEach(t => t.stop())
    micStream = null
}

function listenForPtt() {
    listeningForPtt.value = true
    const handler = (e) => {
        e.preventDefault()
        audio.value.pttKey = e.code
        saveAudioPrefs()
        listeningForPtt.value = false
        window.removeEventListener('keydown', handler)
    }
    window.addEventListener('keydown', handler)
}

// ── Video ────────────────────────────────────────────────────────────────────
const cameraDevices   = ref([])
const cameraPreview   = ref(null)
let   cameraStream    = null

const video = ref({
    cameraId:         localStorage.getItem('eluth_camera_id')           ?? '',
    cameraOnByDefault:localStorage.getItem('eluth_camera_default') === 'true',
})

async function loadCameras() {
    try {
        const stream  = await navigator.mediaDevices.getUserMedia({ video: true }).catch(() => null)
        const devices = await navigator.mediaDevices.enumerateDevices()
        cameraDevices.value = devices.filter(d => d.kind === 'videoinput')
        if (stream) stream.getTracks().forEach(t => t.stop())
    } catch {}
}

watch(() => panel.value, async p => {
    if (p === 'video') {
        await loadCameras()
        if (video.value.cameraId) startCameraPreview(video.value.cameraId)
    } else {
        stopCameraPreview()
    }
})

async function onCameraChange() {
    saveVideoPrefs()
    stopCameraPreview()
    if (video.value.cameraId) startCameraPreview(video.value.cameraId)
}

async function startCameraPreview(deviceId) {
    try {
        cameraStream = await navigator.mediaDevices.getUserMedia({
            video: deviceId ? { deviceId: { exact: deviceId } } : true,
        })
        await new Promise(r => setTimeout(r, 50)) // let ref mount
        if (cameraPreview.value) cameraPreview.value.srcObject = cameraStream
    } catch {}
}

function stopCameraPreview() {
    cameraStream?.getTracks().forEach(t => t.stop())
    cameraStream = null
    if (cameraPreview.value) cameraPreview.value.srcObject = null
}

function saveVideoPrefs() {
    localStorage.setItem('eluth_camera_id',      video.value.cameraId)
    localStorage.setItem('eluth_camera_default', video.value.cameraOnByDefault)
}

// ── Appearance ────────────────────────────────────────────────────────────────
const appearance = ref({
    density:  localStorage.getItem('eluth_density')   ?? 'comfortable',
    fontSize: localStorage.getItem('eluth_font_size') ?? '14px',
})

function saveAppearancePrefs() {
    localStorage.setItem('eluth_density',   appearance.value.density)
    localStorage.setItem('eluth_font_size', appearance.value.fontSize)
    document.documentElement.style.setProperty('--font-size-base', appearance.value.fontSize)
    document.body.dataset.density = appearance.value.density
}

// Apply on mount
onMounted(() => {
    if (appearance.value.fontSize !== '14px') {
        document.documentElement.style.setProperty('--font-size-base', appearance.value.fontSize)
    }
    document.body.dataset.density = appearance.value.density
})
</script>
