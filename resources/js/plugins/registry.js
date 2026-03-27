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
export async function loadPlugin(slug, baseUrl, entry = 'index.js', version = null) {
    if (window.__EluthPlugins?.[slug]) return window.__EluthPlugins[slug]
    if (_loading[slug]) return _loading[slug]

    _loading[slug] = new Promise((resolve, reject) => {
        const base = `${baseUrl.replace(/\/$/, '')}/plugins/${slug}/${entry}`
        const url  = version ? `${base}?v=${encodeURIComponent(version)}` : base
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

/**
 * Render a message URL using any loaded plugin's messageRenderer.
 * Each plugin may declare:
 *   messageRenderer: { pattern: RegExp, render: (url) => htmlString|null }
 * Returns an HTML string if a plugin handles it, or null if nothing matches.
 */
export function renderMessageUrl(url) {
    const plugins = window.__EluthPlugins ?? {}
    for (const slug of Object.keys(plugins)) {
        const mr = plugins[slug].messageRenderer
        if (mr && mr.pattern instanceof RegExp && mr.pattern.test(url)) {
            try {
                const html = mr.render(url)
                if (html != null) return html
            } catch (e) {
                console.warn(`[Plugin:${slug}] messageRenderer.render threw:`, e)
            }
        }
    }
    return null
}

/**
 * Apply all plugin content transformers to a rendered HTML string.
 * Each plugin may declare:
 *   transformContent: (html) => transformedHtml
 * Transformers are applied in load order.
 */
export function applyContentTransformers(html) {
    const plugins = window.__EluthPlugins ?? {}
    let result = html
    for (const slug of Object.keys(plugins)) {
        const transform = plugins[slug].transformContent
        if (typeof transform === 'function') {
            try {
                result = transform(result) ?? result
            } catch (e) {
                console.warn(`[Plugin:${slug}] transformContent threw:`, e)
            }
        }
    }
    return result
}

/**
 * Call bootstrap on every loaded plugin that declares one.
 * Plugins use this to perform async init (e.g. fetch emote lists) and
 * register their transformContent / messageRenderer on themselves.
 *
 * api: object provided by the host, e.g. { get, authToken, apiBase }
 */
export async function bootstrapPlugins(api) {
    const plugins = window.__EluthPlugins ?? {}
    for (const slug of Object.keys(plugins)) {
        const bootstrap = plugins[slug].bootstrap
        if (typeof bootstrap === 'function') {
            try {
                await bootstrap.call(plugins[slug], api)
            } catch (e) {
                console.warn(`[Plugin:${slug}] bootstrap threw:`, e)
            }
        }
    }
}
