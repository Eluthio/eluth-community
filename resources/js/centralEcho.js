import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { getConfig } from './clientConfig.js'

// Two ws-bridge paths — proxy /app2/* to a second ws-bridge instance on port 3001.
// Pusher constructs the full WS path as: {wsPath}/app/{key}
// So wsPath='' → /app/{key}  (primary, default)
//    wsPath='/app2' → /app2/app/{key} (fallback, routed by .htaccess to port 3001)
const WS_PATHS = ['', '/app2']

/**
 * Create an Echo instance connected to the central Reverb server.
 * Called once the user's JWT token is available so the auth header is set.
 *
 * @param {string}        token      JWT bearer token
 * @param {function|null} onReplace  Called with a new Echo instance when failover triggers.
 *                                   Caller should swap their ref to the new instance.
 * @param {number}        _pathIdx   Internal — which WS_PATHS entry to use (default 0)
 */
export function createCentralEcho(token, onReplace = null, _pathIdx = 0) {
    const wsPath = WS_PATHS[_pathIdx]

    const cfg = getConfig()
    const echo = new Echo({
        broadcaster:       'reverb',
        key:               cfg.centralReverbKey,
        wsHost:            cfg.centralReverbHost,
        wsPort:            cfg.centralReverbPort   ?? 8080,
        wssPort:           cfg.centralReverbPort   ?? 443,
        forceTLS:         (cfg.centralReverbScheme ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        wsPath,
        authEndpoint:      (cfg.centralUrl ?? '') + '/api/broadcasting/auth',
        auth: {
            headers: { Authorization: 'Bearer ' + token },
        },
    })

    // ── Reconnection resilience ──────────────────────────────────────────────
    // Don't wait for Pusher's "unavailable" (~30s of retries). Instead watch for
    // the first drop (connected → connecting) and failover after a 2s grace period
    // — long enough to ride out a brief network blip, short enough to feel instant.
    const pusher = echo.connector.pusher

    function doFailover() {
        echo.disconnect()
        const nextIdx = (_pathIdx + 1) % WS_PATHS.length
        const newEcho = createCentralEcho(token, onReplace, nextIdx)
        onReplace(newEcho)
    }

    if (onReplace) {
        let _failoverTimer = null

        pusher.connection.bind('state_change', ({ current, previous }) => {
            if (previous === 'connected' && current === 'connecting') {
                // Connection just dropped — give it 2s to self-recover before switching
                _failoverTimer = setTimeout(() => {
                    if (pusher.connection.state !== 'connected') doFailover()
                }, 2000)
            } else if (current === 'connected') {
                // Recovered on its own — cancel any pending failover
                clearTimeout(_failoverTimer)
                _failoverTimer = null
            }
        })

        // Pusher gave up entirely without ever connecting (e.g. initial path dead)
        pusher.connection.bind('unavailable', () => {
            clearTimeout(_failoverTimer)
            doFailover()
        })
    } else {
        pusher.connection.bind('unavailable', () => {
            setTimeout(() => pusher.connect(), 5000)
        })
    }

    return echo
}
