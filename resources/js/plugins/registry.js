import GifPicker from './GifPicker.vue'

/**
 * Official plugin registry.
 *
 * Keyed by slug. Each entry declares which zones the plugin occupies
 * and which Vue component to mount. The DB controls whether it's enabled.
 */
export const OFFICIAL_PLUGINS = {
    'gif-picker': {
        component: GifPicker,
        zones: ['input'],
    },
}
