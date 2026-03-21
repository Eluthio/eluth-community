import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { getConfig } from './clientConfig.js'

// Two ws-bridge paths — proxy /app2/* to a second ws-bridge instance on port 3001.
// On 'unavailable' we recreate Echo on the alternate path so the client reconnects
// via the other bridge while the dead one is restarted by cron (within ~30s).
//
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
    // Pusher enters "unavailable" and stops retrying when the server is down.
    // If a caller provided onReplace, failover to the alternate ws-bridge path
    // so one instance dying doesn't require a page refresh.
    // Without onReplace (e.g. direct usage), just keep retrying the same path.
    const pusher = echo.connector.pusher
    pusher.connection.bind('unavailable', () => {
        setTimeout(() => {
            if (onReplace) {
                echo.disconnect()
                const nextIdx = (_pathIdx + 1) % WS_PATHS.length
                const newEcho = createCentralEcho(token, onReplace, nextIdx)
                onReplace(newEcho)
            } else {
                pusher.connect()
            }
        }, 5000)
    })

    return echo
}
