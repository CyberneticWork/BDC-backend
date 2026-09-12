<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleDriveLogoService
{
    public function fileId(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('#/d/([a-zA-Z0-9_-]+)#', $value, $m)) {
            return $m[1];
        }
        if (preg_match('#[?&]id=([a-zA-Z0-9_-]+)#', $value, $m)) {
            return $m[1];
        }
        if (preg_match('#lh3\.googleusercontent\.com/d/([a-zA-Z0-9_-]+)#', $value, $m)) {
            return $m[1];
        }
        if (preg_match('#^[a-zA-Z0-9_-]{25,}$#', $value)) {
            return $value;
        }

        return null;
    }

    public function isDriveValue(?string $value): bool
    {
        return $this->fileId($value) !== null;
    }

    /** Direct image URL for a publicly shared Drive file. */
    public function displayUrl(?string $value): ?string
    {
        $id = $this->fileId($value);
        if (!$id) {
            $trimmed = trim((string) $value);

            return $trimmed !== '' ? $trimmed : null;
        }

        return 'https://lh3.googleusercontent.com/d/' . $id;
    }

    public function normalizeStoredUrl(?string $value): ?string
    {
        $display = $this->displayUrl($value);

        return $display ?: null;
    }

    public function isUploadConfigured(): bool
    {
        return filled(config('services.google_drive.client_id'))
            && filled(config('services.google_drive.client_secret'))
            && filled(config('services.google_drive.refresh_token'));
    }

    public function uploadPublicLogo(UploadedFile $file, string $companyCode): ?string
    {
        if (!$this->isUploadConfigured()) {
            return null;
        }

        $token = $this->accessToken();
        if (!$token) {
            return null;
        }

        $name = 'hr-logo-' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $companyCode) ?: 'company')
            . '-' . time() . '.' . $file->getClientOriginalExtension();
        $mime = $file->getMimeType() ?: 'image/png';
        $meta = ['name' => $name];
        $folder = config('services.google_drive.folder_id');
        if ($folder) {
            $meta['parents'] = [$folder];
        }

        $boundary = 'hrlogo' . bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . json_encode($meta) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: {$mime}\r\n\r\n"
            . file_get_contents($file->getRealPath()) . "\r\n"
            . "--{$boundary}--";

        $upload = Http::withToken($token)
            ->withBody($body, "multipart/related; boundary={$boundary}")
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id');

        if (!$upload->successful()) {
            Log::warning('Google Drive logo upload failed', ['body' => $upload->body()]);
            return null;
        }

        $id = $upload->json('id');
        if (!$id) {
            return null;
        }

        Http::withToken($token)->post(
            "https://www.googleapis.com/drive/v3/files/{$id}/permissions",
            ['type' => 'anyone', 'role' => 'reader']
        );

        return $this->displayUrl($id);
    }

    public function fetchImage(?string $storedUrl): ?array
    {
        $url = $this->displayUrl($storedUrl);
        if (!$url) {
            return null;
        }

        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 HR-Logo/1.0',
            'Accept' => 'image/*,*/*',
        ])->timeout(20)->get($url);

        if (!$response->successful() || !$response->body()) {
            return null;
        }

        $type = $response->header('Content-Type') ?: 'image/jpeg';
        if (!str_starts_with(strtolower($type), 'image/')) {
            $type = 'image/jpeg';
        }

        return ['body' => $response->body(), 'type' => $type];
    }

    private function accessToken(): ?string
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google_drive.client_id'),
            'client_secret' => config('services.google_drive.client_secret'),
            'refresh_token' => config('services.google_drive.refresh_token'),
            'grant_type' => 'refresh_token',
        ]);

        if (!$response->successful()) {
            Log::warning('Google Drive token refresh failed', ['body' => $response->body()]);
            return null;
        }

        return $response->json('access_token');
    }
}
