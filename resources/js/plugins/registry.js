/**
 * Runtime plugin loader.
 * Plugins are NOT bundled into the community server — they are downloaded
 * separately from their own GitHub repos and stored in /storage/app/public/plugins/{slug}/.
 * Each plugin's index.js is an IIFE that registers itself on window.__EluthPlugins.
 */

const _loading = {}

/**
 * Dynamically load a plugin's compiled IIFE from storage.
 * The IIFE must set: window.__EluthPlugins[slug] = { component, zones: [...] }
 */
export async function loadPlugin(slug, storageUrl) {
    if (window.__EluthPlugins?.[slug]) return window.__EluthPlugins[slug]
    if (_loading[slug]) return _loading[slug]

    _loading[slug] = new Promise((resolve, reject) => {
        const url = `${storageUrl.replace(/\/$/, '')}/plugins/${slug}/index.js`
        const script = document.createElement('script')
        script.src = url
        script.onload = () => {
            const plugin = window.__EluthPlugins?.[slug]
            if (plugin) {
                resolve(plugin)
            } else {
                reject(new Error(`Plugin "${slug}" loaded but did not register on window.__EluthPlugins`))
            }
        }
        script.onerror = () => reject(new Error(`Failed to load plugin: ${slug}`))
        document.head.appendChild(script)
    })

    return _loading[slug]
}

/**
 * Get a loaded plugin by slug. Returns null if not yet loaded.
 */
export function getPlugin(slug) {
    return window.__EluthPlugins?.[slug] ?? null
}
