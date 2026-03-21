<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CentralJwtService
{
    /**
     * Decode and verify a JWT issued by the central server.
     * Returns the payload as a stdClass, or throws on failure.
     */
    public function decode(string $token): object
    {
        $publicKey = $this->getPublicKey();
        return JWT::decode($token, new Key($publicKey, 'RS256'));
    }

    /**
     * Fetch (and cache) the central server's RSA public key.
     */
    public function getPublicKey(): string
    {
        return Cache::remember('central_public_key', 3600, function () {
            $url = config('services.central.public_key_url');
            $response = Http::timeout(5)->get($url);

            if (! $response->successful()) {
                throw new \RuntimeException('Could not fetch central server public key.');
            }

            return $response->json('public_key')
                ?? throw new \RuntimeException('Public key missing from central server response.');
        });
    }
}
