import * as Vue from 'vue'
import { createApp } from 'vue'
import App from './App.vue'
import StreamControlPanel from './components/StreamControlPanel.vue'
import '../css/app.css'
import '../css/plugins.css'

// Expose Vue globally so plugin IIFE bundles can use it without bundling their own copy.
window.Vue = Vue

const params = new URLSearchParams(window.location.search)
if (params.get('popup') === 'stream-control') {
    createApp(StreamControlPanel, {
        channelId: params.get('channel'),
    }).mount('#app')
} else {
    createApp(App).mount('#app')
}
