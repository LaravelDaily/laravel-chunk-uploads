<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Filesystem\Filesystem;
use League\Flysystem\UnableToWriteFile;

class ChunkUploadService
{
    public function __construct(
        private Filesystem $disk
    ) {
        $this->disk = Storage::disk('local');
    }

    public function handleChunkUpload(Request $request): JsonResponse
    {
        $uploadOffset = (int) $request->header('Upload-Offset', 0);
        $uploadLength = (int) $request->header('Upload-Length');
        $uploadName = $request->header('Upload-Name');
        $uploadId = $request->header('Upload-Id', session()->getId());

        // Create temp path for chunks
        $tempPath = "chunks/{$uploadId}";

        // Ensure chunks directory exists
        $this->disk->makeDirectory($tempPath);

        // Get chunk content
        $chunkContent = $request->getContent();
        $chunkSize = strlen($chunkContent);

        // Skip empty chunks (FilePond sometimes sends empty first chunk)
        if ($chunkSize > 0) {
            // Save this chunk using Laravel Storage
            $chunkPath = "{$tempPath}/chunk_{$uploadOffset}";
            $this->disk->put($chunkPath, $chunkContent);
        }

        // Check if all chunks are received
        $currentSize = $this->getTotalChunksSize($tempPath);

        if ($currentSize >= $uploadLength) {
            // All chunks received, assemble the file
            return $this->assembleChunks($tempPath, $uploadName, $uploadLength);
        }

        // Clean up empty chunks
        $this->cleanupEmptyChunks($tempPath, $chunkSize);

        // Return progress
        return response()->json([
            'status' => 'chunk_received',
        ]);
    }

    private function getTotalChunksSize(string $tempPath): int
    {
        $totalSize = 0;

        if ($this->disk->exists($tempPath)) {
            $files = $this->disk->files($tempPath);
            foreach ($files as $file) {
                if (str_contains($file, 'chunk_')) {
                    $totalSize += $this->disk->size($file);
                }
            }
        }

        return $totalSize;
    }

    private function assembleChunks(string $tempPath, string $uploadName, int $expectedSize): JsonResponse
    {
        // Generate filename from original name
        $fileName = $this->generateSafeFilename($uploadName);
        $filePath = "uploads/{$fileName}";

        // Ensure uploads directory exists
        $this->disk->makeDirectory('uploads');

        // Get all chunk files and sort by offset
        $chunks = $this->disk->files($tempPath);
        $chunks = collect($chunks)
            ->filter(fn ($file) => str_contains($file, 'chunk_'))
            ->sort(function ($a, $b) use ($tempPath) {
                $offsetA = (int) str_replace($tempPath . '/chunk_', '', $a);
                $offsetB = (int) str_replace($tempPath . '/chunk_', '', $b);
                return $offsetA <=> $offsetB;
            })
            ->values()
            ->all();

        // Use streaming to combine chunks efficiently
        $assembledContent = '';
        foreach ($chunks as $chunkFile) {
            $assembledContent .= $this->disk->get($chunkFile);
        }

        // Store the assembled file
        $this->disk->put($filePath, $assembledContent);

        // Verify file size
        $actualSize = $this->disk->size($filePath);
        if ($actualSize !== $expectedSize) {
            // Clean up on error
            $this->disk->delete($filePath);
            $this->cleanupTempChunks($tempPath);
            throw new UnableToWriteFile("File size mismatch: expected {$expectedSize}, got {$actualSize}");
        }

        // Save file info to database
        $file = $this->createFileRecord($uploadName, $filePath, $actualSize);

        // Clean up temp chunks
        $this->cleanupTempChunks($tempPath);

        // Return success response
        return $this->createSuccessResponse($file);
    }

    private function cleanupEmptyChunks(string $tempPath, int $chunkSize): void
    {
        // If we have no chunks and this was an empty chunk, clean up immediately
        if ($this->getTotalChunksSize($tempPath) === 0 && $chunkSize === 0) {
            $this->cleanupTempChunks($tempPath);
            return;
        }

        // Also clean up if directory exists but has no valid chunks
        if ($this->disk->exists($tempPath)) {
            $files = $this->disk->files($tempPath);
            $hasValidChunks = collect($files)->some(function ($file) {
                return str_contains($file, 'chunk_') && $this->disk->size($file) > 0;
            });

            if (!$hasValidChunks) {
                $this->cleanupTempChunks($tempPath);
            }
        }
    }

    private function cleanupTempChunks(string $tempPath): void
    {
        if ($this->disk->exists($tempPath)) {
            $this->disk->deleteDirectory($tempPath);
        }
    }

    private function generateSafeFilename(string $originalName): string
    {
        // Sanitize the filename
        $name = pathinfo($originalName, PATHINFO_FILENAME);
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);

        // Remove unsafe characters and replace with underscores
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);

        // Ensure filename isn't too long (max 100 chars for the name part)
        if (strlen($safeName) > 100) {
            $safeName = substr($safeName, 0, 100);
        }

        // Combine name and extension
        $fileName = $safeName . ($extension ? '.' . $extension : '');

        // Check if file already exists and add counter if needed
        $counter = 1;

        while ($this->disk->exists("uploads/{$fileName}")) {
            $fileName = $safeName . '_' . $counter . ($extension ? '.' . $extension : '');
            $counter++;
        }

        return $fileName;
    }

    private function createFileRecord(string $fileName, string $filePath, int $fileSize): File
    {
        return Auth::user()->files()->create([
            'file_name' => $fileName,
            'file_path' => $filePath,
            'file_size' => $fileSize,
        ]);
    }

    private function createSuccessResponse(File $file): JsonResponse
    {
        return response()->json([
            'id'   => $file->id,
            'file' => $file->file_name,
            'path' => $file->file_path,
            'size' => $file->file_size,
        ]);
    }
}
