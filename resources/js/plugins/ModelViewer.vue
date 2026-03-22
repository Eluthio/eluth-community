<template>
    <div class="model-wrap" ref="wrapRef">
        <button
            class="model-btn"
            :class="{ active: uploading }"
            @click="triggerPicker"
            :disabled="uploading"
            title="Upload 3D model (OBJ / STL / GLB)"
        >
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                <polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>
            </svg>
        </button>

        <input
            ref="fileInput"
            type="file"
            accept=".obj,.stl,.glb,.gltf"
            style="display:none"
            @change="onFileChosen"
        />

        <div v-if="uploading" class="model-uploading">
            <div class="model-progress-bar" :style="{ width: progress + '%' }"></div>
        </div>

        <div v-if="error" class="model-error">{{ error }}</div>
    </div>
</template>

<script setup>
import { ref, onUnmounted } from 'vue'

const props = defineProps({
    settings:  { type: Object, default: () => ({}) },
    authToken: { type: String, default: '' },
    apiBase:   { type: String, default: '' },
})
const emit = defineEmits(['insert'])

const wrapRef   = ref(null)
const fileInput = ref(null)
const uploading = ref(false)
const progress  = ref(0)
const error     = ref('')

let errorTimer = null

function showError(msg) {
    error.value = msg
    clearTimeout(errorTimer)
    errorTimer = setTimeout(() => { error.value = '' }, 4000)
}

function triggerPicker() {
    fileInput.value?.click()
}

async function upload(file) {
    if (!file) return

    const allowed = ['obj', 'stl', 'glb', 'gltf']
    const ext = file.name.split('.').pop()?.toLowerCase()
    if (!allowed.includes(ext)) {
        showError('Supported formats: OBJ, STL, GLB, GLTF')
        return
    }
    if (file.size > 50 * 1024 * 1024) {
        showError('File must be under 50 MB.')
        return
    }

    uploading.value = true
    progress.value  = 0
    error.value     = ''

    const formData = new FormData()
    formData.append('model', file)

    try {
        const xhr = new XMLHttpRequest()
        await new Promise((resolve, reject) => {
            xhr.upload.addEventListener('progress', e => {
                if (e.lengthComputable) progress.value = Math.round((e.loaded / e.total) * 90)
            })
            xhr.addEventListener('load', () => {
                progress.value = 100
                if (xhr.status >= 200 && xhr.status < 300) resolve(JSON.parse(xhr.responseText))
                else reject(new Error(JSON.parse(xhr.responseText)?.message || 'Upload failed.'))
            })
            xhr.addEventListener('error', () => reject(new Error('Network error.')))
            xhr.open('POST', props.apiBase.replace(/\/$/, '') + '/api/plugins/model-viewer/upload')
            xhr.setRequestHeader('Authorization', 'Bearer ' + props.authToken)
            xhr.send(formData)
        }).then(data => {
            // Emit the model URL — renderContent will show a View 3D Model button
            emit('insert', data.url)
        })
    } catch (err) {
        showError(err.message || 'Upload failed.')
    } finally {
        uploading.value = false
        progress.value  = 0
        if (fileInput.value) fileInput.value.value = ''
    }
}

function onFileChosen(e) {
    const file = e.target.files[0]
    if (file) upload(file)
}

onUnmounted(() => clearTimeout(errorTimer))
</script>

<style scoped>
.model-wrap { position: relative; display: inline-flex; align-items: center; }

.model-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 6px;
    border-radius: 6px;
    color: rgba(255,255,255,.45);
    transition: color .15s, background .15s;
    display: flex;
    align-items: center;
}
.model-btn:hover, .model-btn.active { color: #a78bfa; background: rgba(167,139,250,.1); }
.model-btn:disabled { opacity: .5; cursor: default; }

.model-uploading {
    position: absolute;
    bottom: -3px;
    left: 0;
    right: 0;
    height: 2px;
    background: rgba(255,255,255,.1);
    border-radius: 2px;
    overflow: hidden;
}
.model-progress-bar { height: 100%; background: #a78bfa; transition: width .2s; }

.model-error {
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    background: #7f1d1d;
    color: #fca5a5;
    font-size: 12px;
    padding: 5px 10px;
    border-radius: 6px;
    white-space: nowrap;
    pointer-events: none;
    z-index: 100;
}
</style>
