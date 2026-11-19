<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssistantDocument;
use App\Services\DocumentScanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssistantDocumentController extends Controller
{
    private const TYPES = ['pdf', 'docx', 'xlsx', 'csv', 'jpg', 'jpeg', 'png'];

    public function index(Request $request)
    {
        $data = $request->validate(['session_key' => ['required', 'uuid']]);

        return response()->json(['data' => AssistantDocument::query()->where('user_id', $request->user()->id)->where('session_key', $data['session_key'])->latest()->limit(10)->get()->map(fn (AssistantDocument $doc) => $this->present($doc))]);
    }

    public function store(Request $request, DocumentScanService $scanner)
    {
        $data = $request->validate(['session_key' => ['required', 'uuid'], 'documents' => ['required', 'array', 'min:1', 'max:5'], 'documents.*' => ['required', 'file', 'max:10240']]);
        abort_unless(AssistantDocument::query()->where('user_id', $request->user()->id)->where('session_key', $data['session_key'])->count() + count($data['documents']) <= 10, 422, 'A session can contain at most 10 documents.');
        $created = [];
        foreach ($data['documents'] as $file) {
            $extension = strtolower($file->getClientOriginalExtension());
            if (! in_array($extension, self::TYPES, true) || ! $this->signatureMatches($file->getRealPath(), $extension) || ! $this->mimeMatches($file->getRealPath(), $extension)) {
                throw ValidationException::withMessages(['documents' => 'Only genuine PDF, DOCX, XLSX, CSV, JPG, JPEG, and PNG documents are supported.']);
            }
            $stored = $scanner->store($file, 'assistant-documents/'.(string) $request->user()->id);
            $clean = $stored['scan_status'] === 'clean';
            $document = AssistantDocument::query()->create([
                'user_id' => $request->user()->id,
                'session_key' => $data['session_key'],
                'original_name' => Str::limit(basename($file->getClientOriginalName()), 255, ''),
                'path' => $stored['path'],
                'mime_type' => (string) $file->getMimeType(),
                'file_size' => (int) $file->getSize(),
                'scan_status' => $stored['scan_status'],
                'processing_status' => $clean ? 'blocked' : 'quarantined',
                'error_message' => $clean ? 'Document extraction is unavailable until the document processing service is configured.' : 'Document is quarantined until a clean malware scan is available.',
            ]);
            $created[] = $this->present($document);
        }

        return response()->json(['data' => $created], 201);
    }

    public function destroy(Request $request, AssistantDocument $document)
    {
        abort_unless((int) $document->user_id === (int) $request->user()->id, 404);
        Storage::disk('local')->delete($document->path);
        $document->delete();

        return response()->noContent();
    }

    private function present(AssistantDocument $document): array
    {
        return ['id' => $document->id, 'filename' => $document->original_name, 'size' => $document->file_size,
            'scan_status' => $document->scan_status, 'processing_status' => $document->processing_status,
            'extraction_status' => 'unavailable', 'error' => $document->error_message, 'created_at' => $document->created_at?->toIso8601String()];
    }

    private function signatureMatches(string $path, string $extension): bool
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            return false;
        }
        $head = fread($handle, 8);
        fclose($handle);

        return match ($extension) {
            'pdf' => str_starts_with($head, '%PDF-'),
            'png' => str_starts_with($head, "\x89PNG\r\n\x1a\n"),
            'jpg', 'jpeg' => str_starts_with($head, "\xff\xd8\xff"),
            'docx', 'xlsx' => str_starts_with($head, "PK\x03\x04"),
            'csv' => $this->looksLikeText($path),
            default => false,
        };
    }

    private function looksLikeText(string $path): bool
    {
        $sample = file_get_contents($path, false, null, 0, 4096);

        return is_string($sample) && ! str_contains($sample, "\0") && mb_check_encoding($sample, 'UTF-8');
    }

    private function mimeMatches(string $path, string $extension): bool
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $allowed = match ($extension) {
            'pdf' => ['application/pdf'],
            'png' => ['image/png'],
            'jpg', 'jpeg' => ['image/jpeg'],
            'docx', 'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
            default => [],
        };

        return is_string($mime) && in_array($mime, $allowed, true);
    }
}
