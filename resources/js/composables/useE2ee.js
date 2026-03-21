/**
 * E2EE composable — ECDH P-256 + AES-256-GCM
 *
 * Key model:
 *   - Each user has an ECDH P-256 key pair.
 *   - The public key is stored plaintext on central (anyone can encrypt to you).
 *   - The private key is encrypted client-side with a key derived from the user's
 *     password (PBKDF2 → AES-GCM) and stored as a ciphertext blob on central.
 *     Central never sees the plaintext private key.
 *
 * Encryption model:
 *   - To DM Bob: derive a shared AES key via ECDH(myPrivate, bobPublic).
 *   - Encrypt with AES-256-GCM. Store { iv, ct } as JSON in the message content.
 *   - Both Alice and Bob can decrypt using ECDH(theirPrivate, otherPublic)
 *     which produces the same shared secret.
 *
 * Usage:
 *   const e2ee = useE2ee()
 *   await e2ee.init(centralUrl, accessToken, password)   // call after login
 *   const ciphertext = await e2ee.encrypt(recipientId, centralUrl, accessToken, plaintext)
 *   const plaintext  = await e2ee.decrypt(senderId, centralUrl, accessToken, ciphertext)
 */

import { ref } from 'vue'

// In-memory key pair for this session
const _keyPair = ref(null)
// Cache of other users' public keys: userId → CryptoKey
const _pubKeyCache = {}

// ── Helpers ───────────────────────────────────────────────────────────────────

function b64ToBytes(b64) {
    return Uint8Array.from(atob(b64), c => c.charCodeAt(0))
}

function bytesToB64(buf) {
    return btoa(String.fromCharCode(...new Uint8Array(buf)))
}

async function deriveKeyFromPassword(password, salt) {
    const enc = new TextEncoder()
    const keyMaterial = await crypto.subtle.importKey(
        'raw',
        enc.encode(password),
        { name: 'PBKDF2' },
        false,
        ['deriveKey']
    )
    return crypto.subtle.deriveKey(
        { name: 'PBKDF2', salt, iterations: 200_000, hash: 'SHA-256' },
        keyMaterial,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt']
    )
}

async function encryptPrivateKey(privateKey, password) {
    const salt = crypto.getRandomValues(new Uint8Array(16))
    const iv   = crypto.getRandomValues(new Uint8Array(12))
    const aesKey = await deriveKeyFromPassword(password, salt)
    const exported = await crypto.subtle.exportKey('jwk', privateKey)
    const enc = new TextEncoder()
    const ct = await crypto.subtle.encrypt(
        { name: 'AES-GCM', iv },
        aesKey,
        enc.encode(JSON.stringify(exported))
    )
    return JSON.stringify({
        salt: bytesToB64(salt),
        iv:   bytesToB64(iv),
        ct:   bytesToB64(ct),
    })
}

async function decryptPrivateKey(encBlob, password) {
    const { salt, iv, ct } = JSON.parse(encBlob)
    const aesKey = await deriveKeyFromPassword(password, b64ToBytes(salt))
    const dec = new TextDecoder()
    const plainBytes = await crypto.subtle.decrypt(
        { name: 'AES-GCM', iv: b64ToBytes(iv) },
        aesKey,
        b64ToBytes(ct)
    )
    const jwk = JSON.parse(dec.decode(plainBytes))
    return crypto.subtle.importKey('jwk', jwk, { name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveKey'])
}

async function deriveSharedKey(myPrivateKey, theirPublicKey) {
    return crypto.subtle.deriveKey(
        { name: 'ECDH', public: theirPublicKey },
        myPrivateKey,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt']
    )
}

async function importPublicKey(jwk) {
    return crypto.subtle.importKey('jwk', jwk, { name: 'ECDH', namedCurve: 'P-256' }, false, [])
}

async function fetchUserPublicKey(userId, centralUrl, accessToken) {
    if (_pubKeyCache[userId]) return _pubKeyCache[userId]

    const res = await fetch(`${centralUrl}/api/users/${userId}/public-key`, {
        headers: { Authorization: 'Bearer ' + accessToken },
    })
    if (!res.ok) return null

    const data = await res.json()
    if (!data.public_key) return null

    const key = await importPublicKey(JSON.parse(data.public_key))
    _pubKeyCache[userId] = key
    return key
}

// ── Public API ─────────────────────────────────────────────────────────────────

export function useE2ee() {

    /**
     * Initialise E2EE for the session.
     * - If the user already has keys on central: download and decrypt the private key.
     * - If not: generate a new key pair, encrypt the private key, and upload both.
     *
     * Must be called right after login, while the password is still available.
     */
    /**
     * Try to restore keys from the Electron OS keychain (safeStorage).
     * Returns true if successful — call this on app launch before showing any unlock prompt.
     */
    async function initFromKeychain(centralUrl, accessToken) {
        try {
            const api = window.electronAPI
            if (!api?.e2eeLoadKey) return false
            const privateJwkJson = await api.e2eeLoadKey()
            if (!privateJwkJson) return false
            return initFromCachedKey(privateJwkJson, centralUrl, accessToken)
        } catch {
            return false
        }
    }

    /**
     * Restore keys from a sessionStorage-cached private key JWK string.
     * Returns true if successful. Call this on page load before prompting for password.
     */
    async function initFromCachedKey(privateJwkJson, centralUrl, accessToken) {
        try {
            const jwk = JSON.parse(privateJwkJson)
            const privateKey = await crypto.subtle.importKey(
                'jwk', jwk, { name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveKey']
            )
            // Fetch public key from central to complete the pair
            const res  = await fetch(`${centralUrl}/api/auth/e2ee/keys`, {
                headers: { Authorization: 'Bearer ' + accessToken },
            })
            if (!res.ok) return false
            const data = await res.json()
            if (!data.public_key) return false
            const publicKey = await importPublicKey(JSON.parse(data.public_key))
            _keyPair.value = { publicKey, privateKey }
            return true
        } catch {
            return false
        }
    }

    /**
     * Initialise E2EE using the user's password.
     * Returns the private key as a JWK JSON string for sessionStorage caching,
     * or null if init failed.
     */
    async function init(centralUrl, accessToken, password) {
        try {
            // Check if keys already exist
            const res = await fetch(`${centralUrl}/api/auth/e2ee/keys`, {
                headers: { Authorization: 'Bearer ' + accessToken },
            })

            if (res.ok) {
                const data = await res.json()
                if (data.public_key && data.private_key_enc) {
                    const publicKey  = await importPublicKey(JSON.parse(data.public_key))
                    const privateKey = await decryptPrivateKey(data.private_key_enc, password)
                    _keyPair.value = { publicKey, privateKey }
                    const privateJwk = await crypto.subtle.exportKey('jwk', privateKey)
                    const jwkJson = JSON.stringify(privateJwk)
                    // Persist to OS keychain if running in Electron
                    window.electronAPI?.e2eeSaveKey?.(jwkJson).catch(() => {})
                    return jwkJson
                }
            }

            // Generate new key pair
            const keyPair = await crypto.subtle.generateKey(
                { name: 'ECDH', namedCurve: 'P-256' },
                true,
                ['deriveKey']
            )

            const publicJwk  = await crypto.subtle.exportKey('jwk', keyPair.publicKey)
            const privateJwk = await crypto.subtle.exportKey('jwk', keyPair.privateKey)
            const privateEnc = await encryptPrivateKey(keyPair.privateKey, password)

            await fetch(`${centralUrl}/api/auth/e2ee/keys`, {
                method: 'POST',
                headers: {
                    Authorization:  'Bearer ' + accessToken,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    public_key:      JSON.stringify(publicJwk),
                    private_key_enc: privateEnc,
                }),
            })

            _keyPair.value = { publicKey: keyPair.publicKey, privateKey: keyPair.privateKey }
            const jwkJson = JSON.stringify(privateJwk)
            // Persist to OS keychain if running in Electron
            window.electronAPI?.e2eeSaveKey?.(jwkJson).catch(() => {})
            return jwkJson
        } catch (err) {
            console.warn('[E2EE] init failed:', err)
            return null
        }
    }

    /**
     * Encrypt a plaintext message for a recipient.
     * Returns the ciphertext JSON string to store as message content,
     * or null if encryption is not available.
     */
    async function encrypt(recipientId, centralUrl, accessToken, plaintext) {
        if (!_keyPair.value) return null

        try {
            const recipientKey = await fetchUserPublicKey(recipientId, centralUrl, accessToken)
            if (!recipientKey) return null

            const sharedKey = await deriveSharedKey(_keyPair.value.privateKey, recipientKey)
            const iv  = crypto.getRandomValues(new Uint8Array(12))
            const enc = new TextEncoder()
            const ct  = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, sharedKey, enc.encode(plaintext))

            return JSON.stringify({ iv: bytesToB64(iv), ct: bytesToB64(ct) })
        } catch (err) {
            console.warn('[E2EE] encrypt failed:', err)
            return null
        }
    }

    /**
     * Decrypt a ciphertext message from a sender.
     * Returns the plaintext, or '[Encrypted message]' if decryption fails.
     */
    async function decrypt(senderId, centralUrl, accessToken, ciphertextJson) {
        if (!_keyPair.value) return '[Encrypted message — keys not loaded]'

        try {
            const { iv, ct } = JSON.parse(ciphertextJson)
            const senderKey  = await fetchUserPublicKey(senderId, centralUrl, accessToken)
            if (!senderKey) return '[Encrypted message — sender key unavailable]'

            const sharedKey = await deriveSharedKey(_keyPair.value.privateKey, senderKey)
            const plainBytes = await crypto.subtle.decrypt(
                { name: 'AES-GCM', iv: b64ToBytes(iv) },
                sharedKey,
                b64ToBytes(ct)
            )
            return new TextDecoder().decode(plainBytes)
        } catch {
            return '[Encrypted message — could not decrypt]'
        }
    }

    /** Whether E2EE keys are loaded and ready for this session */
    function isReady() {
        return _keyPair.value !== null
    }

    return { init, initFromCachedKey, initFromKeychain, encrypt, decrypt, isReady }
}
