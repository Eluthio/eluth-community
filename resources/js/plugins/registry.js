import GifPicker      from './GifPicker.vue'
import EmoticonPicker from './EmoticonPicker.vue'
import ImageUploader  from './ImageUploader.vue'
import ModelViewer    from './ModelViewer.vue'
import WatchParty     from './WatchParty.vue'

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
    'emoticon-picker': {
        component: EmoticonPicker,
        zones: ['input'],
    },
    'image-uploader': {
        component: ImageUploader,
        zones: ['input'],
    },
    'model-viewer': {
        component: ModelViewer,
        zones: ['input'],
    },
    'watch-party': {
        component: WatchParty,
        zones: ['input'],
    },
}
