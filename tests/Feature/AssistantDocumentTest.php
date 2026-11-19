<?php

namespace Tests\Feature;

use App\Models\AssistantDocument;
use App\Models\User;
use App\Services\MalwareScanner;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssistantDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        Storage::fake('local');
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner
        {
            public function scan(string $filePath): array
            {
                return ['status' => 'clean', 'result' => 'clean'];
            }
        });
    }

    public function test_authorized_employee_can_upload_private_file_but_it_is_not_claimed_extracted(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Operations');
        $session = (string) Str::uuid();

        $response = $this->actingAs($user)->post('/api/assistant/documents', [
            'session_key' => $session,
            'documents' => [UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\nprivate cargo data")],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('data.0.scan_status', 'clean')->assertJsonPath('data.0.processing_status', 'blocked')->assertJsonPath('data.0.extraction_status', 'unavailable');
        $record = AssistantDocument::query()->firstOrFail();
        $this->assertSame($user->id, $record->user_id);
        $this->assertSame($session, $record->session_key);
        $this->assertStringStartsWith('assistant-documents/'.$user->id.'/', $record->path);
        Storage::disk('local')->assertExists($record->path);
    }

    public function test_invalid_signature_and_unauthorized_owner_access_are_rejected(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Operations');
        $session = (string) Str::uuid();
        $this->actingAs($user)->post('/api/assistant/documents', ['session_key' => $session, 'documents' => [UploadedFile::fake()->createWithContent('fake.pdf', 'not a pdf')]], ['Accept' => 'application/json'])->assertUnprocessable();

        $other = User::factory()->create();
        $other->assignRole('Operations');
        $document = AssistantDocument::query()->create(['user_id' => $other->id, 'session_key' => $session, 'original_name' => 'x.pdf', 'path' => 'quarantine/x', 'mime_type' => 'application/pdf', 'file_size' => 5, 'scan_status' => 'infected', 'processing_status' => 'quarantined']);
        $this->actingAs($user)->deleteJson('/api/assistant/documents/'.$document->id)->assertNotFound();
    }
}
