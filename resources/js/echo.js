import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { getConfig } from './clientConfig.js'

window.Pusher = Pusher

export function createEcho() {
    const cfg = getConfig()
    const echo = window.Echo = new Echo({
        broadcaster: 'reverb',
        key:         cfg.reverbKey,
        wsHost:      cfg.reverbHost,
        wsPort:      cfg.reverbPort  ?? 8080,
        wssPort:     cfg.reverbPort  ?? 443,
        forceTLS:   (cfg.reverbScheme ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    })

    // ── Reconnection resilience ────────────────────────────────────────────────
    const pusher = echo.connector.pusher

    pusher.connection.bind('unavailable', () => {
        setTimeout(() => pusher.connect(), 5000)
    })

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && pusher.connection.state !== 'connected') pusher.connect()
    })

    window.addEventListener('online', () => {
        if (pusher.connection.state !== 'connected') pusher.connect()
    })

    return echo
}
