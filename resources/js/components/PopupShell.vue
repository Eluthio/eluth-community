<template>
    <div class="popup-shell">
        <component :is="popupComponent" v-if="popupComponent" v-bind="componentProps" />
        <div v-else-if="loadError" class="popup-shell-error">
            <p>{{ loadError }}</p>
        </div>
        <div v-else class="popup-shell-loading">Loading…</div>
    </div>
</template>

<script setup>
/**
 * PopupShell — lightweight popup host.
 *
 * Mounts plugin-declared popup components without loading the full SPA.
 * bootstrap() is NEVER called here — plugins must not run leader election
 * or heavy init in popup contexts.
 *
 * window.__eluthPopup is already installed by app.js before this mounts.
 *
 * Plugins expose popup components via:
 *   window.__EluthPlugins[slug].popupComponents = { ComponentName: VueComponent }
 */
import { ref, markRaw, onMounted } from 'vue'
import { loadPlugin } from '../plugins/registry.js'

const props = defineProps({
    entry: { type: Object, required: true },
})

const popupComponent = ref(null)
const componentProps  = ref({})
const loadError       = ref(null)

onMounted(async () => {
    try {
        const config = await fetch('/api/client-config').then(r => r.json()).catch(() => ({}))
        const storageUrl = config.storageUrl ?? (window.location.origin + '/storage')

        // Load the owning plugin's entry script — registers window.__EluthPlugins[slug].
        // bootstrap() is intentionally NOT called.
        await loadPlugin(props.entry.slug, storageUrl, props.entry.entry, props.entry.version)

        // Load any contributing plugins (e.g. Discussion contributing to stream-control).
        for (const c of props.entry.contributors ?? []) {
            await loadPlugin(c.slug, storageUrl, c.entry, c.version)
        }

        const plugin = window.__EluthPlugins?.[props.entry.slug]
        if (! plugin) throw new Error(`Plugin "${props.entry.slug}" did not register on window.__EluthPlugins`)

        const comp = plugin.popupComponents?.[props.entry.component]
        if (! comp) throw new Error(`Plugin "${props.entry.slug}" has no popup component "${props.entry.component}"`)

        popupComponent.value = markRaw(comp)
    } catch (e) {
        console.error('[PopupShell]', e)
        loadError.value = e.message
    }
})
</script>

<style scoped>
.popup-shell {
    width: 100%;
    height: 100%;
}

.popup-shell-loading,
.popup-shell-error {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100vh;
    font-size: 14px;
    color: #94a3b8;
}

.popup-shell-error p {
    color: #f87171;
}
</style>
