<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class FirebaseStorageService
{
    public function isConfigured(): bool
    {
        return $this->bucket() !== '' && $this->serviceAccount() !== null;
    }

    public function upload(UploadedFile $file, string $folder = 'hr'): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Firebase Storage is not configured.');
        }

        $safe = preg_replace('/[^\w.\-]+/', '_', $file->getClientOriginalName() ?: 'file') ?: 'file';
        $destination = trim($folder, '/') . '/' . time() . '-' . Str::uuid() . '-' . $safe;
        $token = (string) Str::uuid();
        $access = $this->accessToken();
        $bucket = $this->bucket();
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $boundary = 'hrfb' . bin2hex(random_bytes(6));
        $meta = json_encode([
            'name' => $destination,
            'contentType' => $mime,
            'metadata' => [
                'firebaseStorageDownloadTokens' => $token,
            ],
        ]);
        $body = "--{$boundary}\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . $meta . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: {$mime}\r\n\r\n"
            . file_get_contents($file->getRealPath()) . "\r\n"
            . "--{$boundary}--";

        $response = $this->http()
            ->withToken($access)
            ->withBody($body, "multipart/related; boundary={$boundary}")
            ->post("https://storage.googleapis.com/upload/storage/v1/b/{$bucket}/o?uploadType=multipart");

        if (!$response->successful()) {
            throw new \RuntimeException('Firebase upload failed: ' . $response->body());
        }

        $encoded = rawurlencode($destination);

        return "https://firebasestorage.googleapis.com/v0/b/{$bucket}/o/{$encoded}?alt=media&token={$token}";
    }

    public function storeFile(UploadedFile $file, string $folder = 'hr'): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Firebase Storage is not configured. All images and documents must be uploaded to Firebase.');
        }

        return $this->upload($file, $folder);
    }

    private function bucket(): string
    {
        return trim((string) config('services.firebase.storage_bucket'), " \t\n\r\0\x0B\"'");
    }

    private function serviceAccount(): ?array
    {
        $path = trim((string) config('services.firebase.service_account_path'));
        if ($path !== '') {
            $full = $this->resolveAccountPath($path);
            if ($full && is_readable($full)) {
                $data = json_decode((string) file_get_contents($full), true);
                if (is_array($data) && !empty($data['client_email']) && !empty($data['private_key'])) {
                    return $data;
                }
            }
        }

        $raw = trim((string) config('services.firebase.service_account_json'));
        if ($raw === '') {
            return null;
        }
        $raw = trim($raw, "\"'");
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
            return null;
        }

        return $data;
    }

    private function resolveAccountPath(string $path): ?string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path) || str_starts_with($path, '/')) {
            return $path;
        }

        $fromBase = base_path($path);
        if (is_readable($fromBase)) {
            return $fromBase;
        }

        $fromStorage = storage_path($path);
        return is_readable($fromStorage) ? $fromStorage : $fromBase;
    }

    private function accessToken(): string
    {
        return Cache::remember('firebase_storage_access_token', 3000, function () {
            $sa = $this->serviceAccount();
            if (!$sa) {
                throw new \RuntimeException('Firebase service account is missing.');
            }
            $now = time();
            $header = $this->b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $this->b64url(json_encode([
                'iss' => $sa['client_email'],
                'scope' => 'https://www.googleapis.com/auth/devstorage.full_control',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            $unsigned = $header . '.' . $claims;
            $ok = openssl_sign($unsigned, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256);
            if (!$ok) {
                throw new \RuntimeException('Could not sign Firebase JWT.');
            }
            $jwt = $unsigned . '.' . $this->b64url($signature);
            $response = $this->http()->asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
            if (!$response->successful() || !$response->json('access_token')) {
                throw new \RuntimeException('Firebase token failed: ' . $response->body());
            }

            return $response->json('access_token');
        });
    }

    private function http()
    {
        $ca = storage_path('certs/cacert.pem');
        $options = is_readable($ca) ? ['verify' => $ca] : [];

        return Http::withOptions($options);
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
