/**
 * Runtime client config — fetched from /api/client-config on boot.
 * Replaces baked-in VITE_ env vars so the release zip works on any server.
 */

let _config = null

export async function loadClientConfig() {
    const res = await fetch('/api/client-config')
    _config = await res.json()
}

export function getConfig() {
    return _config ?? {}
}
