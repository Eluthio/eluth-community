import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

window.Pusher = Pusher

const echo = window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
})

// ── Reconnection resilience ────────────────────────────────────────────────
// Pusher enters "unavailable" after several failed reconnect attempts and
// stops trying. We keep nudging it back into a connect attempt so that when
// the server recovers (within ~60s via cron) the client reconnects on its own.

const pusher = echo.connector.pusher

pusher.connection.bind('unavailable', () => {
    setTimeout(() => pusher.connect(), 5000)
})

// Reconnect immediately when the tab regains focus — catches cases where the
// socket silently died while the tab was in the background.
document.addEventListener('visibilitychange', () => {
    if (!document.hidden && pusher.connection.state !== 'connected') {
        pusher.connect()
    }
})

// Reconnect when the browser reports the network is back online.
window.addEventListener('online', () => {
    if (pusher.connection.state !== 'connected') {
        pusher.connect()
    }
})

export default echo
