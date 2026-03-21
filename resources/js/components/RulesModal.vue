<template>
    <div class="rules-overlay" @click.self="$emit('close')">
        <div class="rules-modal">
            <div class="rules-header">
                <span class="rules-title">Server Rules</span>
                <button class="rules-close" @click="$emit('close')">✕</button>
            </div>
            <div class="rules-body">
                <pre class="rules-content">{{ rules }}</pre>
            </div>
            <div class="rules-footer">
                <button class="welcome-btn" @click="$emit('close')">Close</button>
            </div>
        </div>
    </div>
</template>

<script setup>
defineProps({
    rules: { type: String, default: '' },
})

defineEmits(['close'])
</script>
