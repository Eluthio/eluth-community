<template>
    <div class="bg-video-wrap">
        <video
            ref="video"
            autoplay
            muted
            loop
            playsinline
            :src="src"
            @canplaythrough="onLoaded"
        />
    </div>
</template>

<script setup>
import { ref } from 'vue'

defineProps({
    src: { type: String, default: null }
})

const video = ref(null)

function onLoaded() {
    video.value?.classList.add('loaded')
}
</script>
