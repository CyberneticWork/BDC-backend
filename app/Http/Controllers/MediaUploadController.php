<?php

namespace App\Http\Controllers;

use App\Services\CyberneticAdminAuth;
use App\Services\FirebaseStorageService;
use Illuminate\Http\Request;

class MediaUploadController extends Controller
{
    public function __construct(
        private FirebaseStorageService $firebase,
        private CyberneticAdminAuth $cyberneticAuth,
    ) {
    }

    public function status()
    {
        return response()->json(['configured' => $this->firebase->isConfigured()]);
    }

    public function store(Request $request)
    {
        $signedIn = (bool) $request->user('sanctum');
        if (!$this->cyberneticAuth->checkRequest($request) && !$signedIn) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (!$this->firebase->isConfigured()) {
            return response()->json(['message' => 'Firebase is not configured on the server.'], 503);
        }

        $request->validate([
            'file' => 'required|file|max:10240',
            'folder' => 'nullable|string|max:120',
        ]);

        $folder = $request->input('folder') ?: 'hr';
        $folder = preg_replace('/[^a-zA-Z0-9_\/\-]/', '', $folder) ?: 'hr';

        try {
            $url = $this->firebase->upload($request->file('file'), $folder);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['url' => $url]);
    }
}
