<template>
    <div class="img-upload-wrap" ref="wrapRef">
        <!-- Trigger button -->
        <button
            class="img-btn"
            :class="{ active: uploading }"
            @click="triggerPicker"
            :disabled="uploading"
            title="Upload image"
        >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/>
                <polyline points="21 15 16 10 5 21"/>
            </svg>
        </button>

        <!-- Hidden file input -->
        <input
            ref="fileInput"
            type="file"
            accept="image/jpeg,image/png,image/gif,image/webp"
            style="display:none"
            @change="onFileChosen"
        />

        <!-- Upload progress indicator -->
        <div v-if="uploading" class="img-uploading">
            <div class="img-progress-bar" :style="{ width: progress + '%' }"></div>
        </div>

        <!-- Error toast -->
        <div v-if="error" class="img-error">{{ error }}</div>

        <!-- Drag-drop overlay on parent (whole input area) — injected via JS -->
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'

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
    errorTimer = setTimeout(() => { error.value = '' }, 3500)
}

function triggerPicker() {
    fileInput.value?.click()
}

async function upload(file) {
    if (!file) return

    if (!file.type.startsWith('image/')) {
        showError('Only images are supported.')
        return
    }
    if (file.size > 8 * 1024 * 1024) {
        showError('Image must be under 8 MB.')
        return
    }

    uploading.value = true
    progress.value  = 0
    error.value     = ''

    const formData = new FormData()
    formData.append('image', file)

    try {
        const xhr = new XMLHttpRequest()
        await new Promise((resolve, reject) => {
            xhr.upload.addEventListener('progress', e => {
                if (e.lengthComputable) progress.value = Math.round((e.loaded / e.total) * 90)
            })
            xhr.addEventListener('load', () => {
                progress.value = 100
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(JSON.parse(xhr.responseText))
                } else {
                    reject(new Error(JSON.parse(xhr.responseText)?.message || 'Upload failed.'))
                }
            })
            xhr.addEventListener('error', () => reject(new Error('Network error.')))
            xhr.open('POST', props.apiBase.replace(/\/$/, '') + '/api/plugins/image-uploader/upload')
            xhr.setRequestHeader('Authorization', 'Bearer ' + props.authToken)
            xhr.send(formData)
        }).then(data => {
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

// Drag-drop: attach to the nearest .msg-input-wrap ancestor
let dropTarget = null

function onDragOver(e) {
    if ([...e.dataTransfer.types].includes('Files')) {
        e.preventDefault()
        e.dataTransfer.dropEffect = 'copy'
        dropTarget?.classList.add('drag-over')
    }
}
function onDragLeave(e) {
    if (!dropTarget?.contains(e.relatedTarget)) {
        dropTarget?.classList.remove('drag-over')
    }
}
function onDrop(e) {
    dropTarget?.classList.remove('drag-over')
    const file = e.dataTransfer.files[0]
    if (file?.type.startsWith('image/')) {
        e.preventDefault()
        upload(file)
    }
}

onMounted(() => {
    // Find input area ancestor to attach drag-drop to
    dropTarget = wrapRef.value?.closest('.msg-input-area') ?? document.body
    dropTarget.addEventListener('dragover', onDragOver)
    dropTarget.addEventListener('dragleave', onDragLeave)
    dropTarget.addEventListener('drop', onDrop)
})

onUnmounted(() => {
    if (dropTarget) {
        dropTarget.removeEventListener('dragover', onDragOver)
        dropTarget.removeEventListener('dragleave', onDragLeave)
        dropTarget.removeEventListener('drop', onDrop)
    }
    clearTimeout(errorTimer)
})
</script>

<style scoped>
.img-upload-wrap { position: relative; display: inline-flex; align-items: center; }

.img-btn {
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
.img-btn:hover, .img-btn.active { color: #22d3ee; background: rgba(34,211,238,.1); }
.img-btn:disabled { opacity: .5; cursor: default; }

.img-uploading {
    position: absolute;
    bottom: -3px;
    left: 0;
    right: 0;
    height: 2px;
    background: rgba(255,255,255,.1);
    border-radius: 2px;
    overflow: hidden;
}
.img-progress-bar {
    height: 100%;
    background: #22d3ee;
    transition: width .2s;
}

.img-error {
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
