<?php

namespace App\Services;

use App\Models\UserPushToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmPushService
{
    public function sendToUsers(array $userIds, string $title, string $body, array $data = []): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) {
            return;
        }
        $tokens = UserPushToken::whereIn('user_id', $userIds)->pluck('token')->filter()->unique()->values();
        foreach ($tokens as $token) {
            $this->send($token, $title, $body, $data);
        }
    }

    public function send(string $token, string $title, string $body, array $data = []): void
    {
        $project = $this->projectId();
        if ($project === '' || $token === '') {
            return;
        }
        try {
            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'data' => array_map('strval', $data),
                    'webpush' => [
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                            'icon' => '/favicon.ico',
                        ],
                        'fcm_options' => [
                            'link' => '/employee-portal',
                        ],
                    ],
                ],
            ];
            $response = $this->http()
                ->withToken($this->accessToken())
                ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", $payload);
            if (!$response->successful()) {
                Log::warning('FCM send failed', ['body' => $response->body()]);
            }
        } catch (\Throwable $e) {
            Log::warning('FCM send skipped', ['error' => $e->getMessage()]);
        }
    }

    private function projectId(): string
    {
        $account = app(FirebaseStorageService::class)->serviceAccountPayload();

        return is_array($account) ? (string) ($account['project_id'] ?? '') : '';
    }

    private function accessToken(): string
    {
        return Cache::remember('firebase_fcm_access_token', 3000, function () {
            $account = app(FirebaseStorageService::class)->serviceAccountPayload();
            if (!is_array($account) || empty($account['client_email']) || empty($account['private_key'])) {
                throw new \RuntimeException('Firebase service account missing');
            }
            $now = time();
            $b64 = function (string $data) {
                return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
            };
            $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $b64(json_encode([
                'iss' => $account['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            $unsigned = $header . '.' . $claims;
            $ok = openssl_sign($unsigned, $signature, $account['private_key'], OPENSSL_ALGO_SHA256);
            if (!$ok) {
                throw new \RuntimeException('Could not sign FCM JWT');
            }
            $jwt = $unsigned . '.' . $b64($signature);
            $ca = storage_path('certs/cacert.pem');
            $http = Http::withOptions(is_readable($ca) ? ['verify' => $ca] : []);
            $response = $http->asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
            if (!$response->successful() || !$response->json('access_token')) {
                throw new \RuntimeException('FCM token failed');
            }

            return $response->json('access_token');
        });
    }

    private function http()
    {
        $ca = storage_path('certs/cacert.pem');

        return Http::withOptions(is_readable($ca) ? ['verify' => $ca] : []);
    }
}
