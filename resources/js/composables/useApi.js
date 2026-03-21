export class ApiError extends Error {
    constructor(message, status, code) {
        super(message)
        this.status = status
        this.code   = code  // e.g. 'membership_pending', 'membership_banned', 'token_expired'
    }
}

export function useApi() {
    function token() {
        return localStorage.getItem('eluth_token') ?? ''
    }

    async function request(method, path, body) {
        const opts = {
            method,
            headers: { Authorization: 'Bearer ' + token(), Accept: 'application/json' },
        }
        const socketId = window.Echo?.socketId?.()
        if (socketId) opts.headers['X-Socket-Id'] = socketId
        if (body !== undefined) {
            opts.headers['Content-Type'] = 'application/json'
            opts.body = JSON.stringify(body)
        }

        const res = await fetch('/api' + path, opts)

        if (!res.ok) {
            const data = await res.json().catch(() => ({}))
            throw new ApiError(data.message ?? `${method} ${path} failed`, res.status, data.error ?? null)
        }

        return res.json()
    }

    return {
        get:  (path)       => request('GET',  path),
        post: (path, body) => request('POST', path, body),
    }
}
