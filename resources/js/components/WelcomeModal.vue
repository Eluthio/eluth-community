<template>
    <div class="welcome-overlay">
        <div class="welcome-modal">
            <div class="welcome-header">
                <img v-if="logo" :src="logo" class="welcome-logo" :alt="serverName" />
                <h1 class="welcome-title">Welcome to {{ serverName }}</h1>
            </div>

            <div class="welcome-body">
                <p v-if="message" class="welcome-message">{{ message }}</p>
            </div>

            <div v-if="rulesEnabled" class="welcome-rules-hint">
                <span>This server has rules.</span>
                <button class="welcome-rules-link" @click="$emit('view-rules')">Read the rules →</button>
            </div>

            <div v-if="requireRulesAck && rulesEnabled" class="welcome-ack">
                <label class="welcome-ack-label">
                    <input type="checkbox" v-model="ackChecked" />
                    I have read and agree to the server rules
                </label>
            </div>

            <div class="welcome-actions">
                <button
                    class="welcome-btn"
                    :disabled="requireRulesAck && rulesEnabled && !ackChecked"
                    @click="$emit('dismiss')"
                >
                    {{ requireRulesAck && rulesEnabled ? 'Accept &amp; Continue' : 'Continue' }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue'

defineProps({
    serverName:     { type: String, default: 'this server' },
    logo:           { type: String, default: null },
    message:        { type: String, default: null },
    rulesEnabled:   { type: Boolean, default: false },
    requireRulesAck:{ type: Boolean, default: false },
})

defineEmits(['dismiss', 'view-rules'])

const ackChecked = ref(false)
</script>
