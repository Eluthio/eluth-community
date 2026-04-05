import * as Vue from 'vue'
import { createApp } from 'vue'
import App from './App.vue'
import PopupShell from './components/PopupShell.vue'
import '../css/app.css'
import '../css/plugins.css'

// Expose Vue globally so plugin IIFE bundles can use it without bundling their own copy.
window.Vue = Vue

const params = new URLSearchParams(window.location.search)

// Standard popup API — provided whenever we're in any popup context so plugin
// popup components have a consistent way to get auth/channel context and close.
function installPopupApi() {
    window.__eluthPopup = {
        getAuthToken: ()    => localStorage.getItem('eluth_token') ?? '',
        getChannelId: ()    => params.get('channel') ?? '',
        getParam:     (key) => params.get(key),
        close:        ()    => window.close(),
    }
}

// Check URL against the plugin-declared popup registry (inlined into the page
// by the server from all enabled plugin manifests).  If a match is found, mount
// a lightweight PopupShell instead of the full SPA — bootstrap() is never called.
const registry = window.__EluthPopupRegistry ?? []
const popupEntry = registry.find(entry => {
    if (! params.has(entry.param)) return false
    // Parameterised popup (e.g. ?popup=stream-control): value must also match.
    // Presence-only popup (e.g. ?discuss_participantsjoin=UUID): just having the param is enough.
    return entry.value === null || entry.value === undefined || params.get(entry.param) === String(entry.value)
})

if (popupEntry) {
    // Plugin-declared popup — lightweight shell, no SPA, no bootstrapping.
    installPopupApi()
    createApp(PopupShell, { entry: popupEntry }).mount('#app')
} else {
    createApp(App).mount('#app')
}
