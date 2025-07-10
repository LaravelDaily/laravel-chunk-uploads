<?php

namespace App\Http\Controllers;

use App\Services\ChunkUploadService;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UploadController extends Controller
{
    public function __construct(
        private readonly ChunkUploadService $chunkUploadService,
        private readonly FileUploadService  $fileUploadService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Handle FilePond chunk uploads
        if ($request->isMethod('patch') ||
            $request->hasHeader('Upload-Length') ||
            $request->hasHeader('Upload-Name') ||
            $request->hasHeader('Upload-Offset')) {
            return $this->chunkUploadService->handleChunkUpload($request);
        }

        // Handle regular file uploads
        if ($request->hasFile('file')) {
            return $this->fileUploadService->handleRegularUpload($request);
        }

        return response()->json(['error' => 'No file uploaded'], 400);
    }
}
