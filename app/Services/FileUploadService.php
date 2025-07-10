<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Filesystem\Filesystem;

class FileUploadService
{
    public function __construct(
        private Filesystem $disk
    ) {
        $this->disk = Storage::disk('local');
    }

    public function handleRegularUpload(Request $request): JsonResponse
    {
        $uploadedFile = $request->file('file');

        if (!$uploadedFile instanceof UploadedFile) {
            throw new \InvalidArgumentException('Invalid file upload');
        }

        // Get original filename
        $originalName = $uploadedFile->getClientOriginalName();

        // Generate safe filename from original name
        $fileName = $this->generateSafeFilename($originalName);

        // Use Laravel's putFileAs for better file handling
        $filePath = $this->disk->putFileAs('uploads', $uploadedFile, $fileName);

        // Save file info to database
        $file = $this->createFileRecord($originalName, $filePath, $uploadedFile->getSize());

        // Return success response
        return $this->createSuccessResponse($file);
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
