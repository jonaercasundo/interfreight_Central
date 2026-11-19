<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SalesWorkspaceController;
use App\Models\AssistantConversation;
use App\Models\AssistantFormSession;
use App\Models\Client;
use App\Services\AssistantWorkflowRegistry;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AssistantClientFormController extends Controller
{
    private const FIELDS = ['company_name', 'client_type', 'contact_name', 'email', 'phone', 'billing_address'];

    public function active(Request $request, AssistantWorkflowRegistry $workflows)
    {
        abort_unless($request->user()->can('clients.create'), 403);
        $filters = $request->validate(['conversation_uuid' => ['nullable', 'uuid']]);
        $session = AssistantFormSession::query()->where('user_id', $request->user()->id)
            ->where('form_key', 'client_profile')
            ->when($filters['conversation_uuid'] ?? null, fn ($query, $uuid) => $query->where('conversation_uuid', $uuid)->whereIn('status', ['collecting', 'review', 'saved']))
            ->when(! ($filters['conversation_uuid'] ?? null), fn ($query) => $query->whereIn('status', ['collecting', 'review']))
            ->latest('updated_at')->first();

        return response()->json(['data' => $session ? $workflows->presentClientForm($session, $this->language($request)) : null]);
    }

    public function answer(Request $request, AssistantFormSession $session, AssistantWorkflowRegistry $workflows)
    {
        $this->assertSession($request, $session);
        abort_unless($session->status === 'collecting', 409, 'This client form is ready for review or has already been saved.');
        $data = $request->validate(['answer' => ['required', 'string', 'max:4000'], 'conversation_uuid' => ['nullable', 'uuid']]);
        $field = ($session->field_order ?? [])[$session->current_step] ?? null;
        abort_unless(in_array($field, self::FIELDS, true), 409, 'There is no unanswered client form field.');
        $value = trim($data['answer']);
        $values = $session->field_values ?? [];
        if (in_array($field, ['contact_name', 'email', 'phone', 'billing_address'], true) && preg_match('/^(?:skip|none|not available|n\/a|wala(?: pa)?|laktaw)$/iu', $value)) {
            $value = '';
        }
        if ($field === 'client_type') {
            $value = collect(['LCL', 'FCL', 'Mixed'])->first(fn (string $choice): bool => strcasecmp($choice, $value) === 0) ?? $value;
        }
        $this->validateField($field, $value);
        $values[$field] = $value;
        $session->field_values = $values;
        $session->current_step++;
        if ($session->current_step >= count($session->field_order ?? [])) {
            $session->status = 'review';
        }
        $session->save();

        $language = $this->language($request);
        $presented = $workflows->presentClientForm($session, $language);
        $reply = $session->status === 'review'
            ? match ($language) {
                'taglish' => 'Kumpleto na ang client form. Paki-review ang details sa ibaba. Hindi ko ito ise-save hangga’t hindi mo kinukumpirma.',
                'filipino' => 'Nakumpleto na ang form ng kliyente. Pakisuri ang mga detalye sa ibaba. Hindi ko ito ise-save hangga’t hindi mo kinukumpirma.',
                default => "I've completed the client form. Please review the details below. I won't save it until you confirm.",
            }
        : $presented['question'];
        $this->appendConversation($request->user()->id, $data['conversation_uuid'] ?? null, trim($data['answer']), $reply);

        return response()->json(['data' => $presented, 'accepted_field' => $field]);
    }

    public function updateField(Request $request, AssistantFormSession $session, string $field, AssistantWorkflowRegistry $workflows)
    {
        $this->assertSession($request, $session);
        abort_unless($session->status === 'review', 409, 'Complete the client form before editing its review.');
        abort_unless(in_array($field, self::FIELDS, true), 404);
        $data = $request->validate(['value' => ['present', 'nullable', 'string', 'max:2000']]);
        $value = trim((string) ($data['value'] ?? ''));
        if ($field === 'client_type') {
            $value = collect(['LCL', 'FCL', 'Mixed'])->first(fn (string $choice): bool => strcasecmp($choice, $value) === 0) ?? $value;
        }
        $this->validateField($field, $value);
        $values = $session->field_values ?? [];
        $values[$field] = $value;
        $session->update(['field_values' => $values]);

        return response()->json(['data' => $workflows->presentClientForm($session->fresh(), $this->language($request))]);
    }

    public function save(Request $request, AssistantFormSession $session, SalesWorkspaceController $sales, AuditLogger $audit, AssistantWorkflowRegistry $workflows)
    {
        $this->assertSession($request, $session);
        $data = $request->validate([
            'confirmed' => ['required', 'accepted'],
            'conversation_uuid' => ['nullable', 'uuid'],
            'values' => ['sometimes', 'array'],
            'values.company_name' => ['sometimes', 'required', 'string', 'max:180'],
            'values.client_type' => ['sometimes', 'required', 'string', 'in:LCL,FCL,Mixed'],
            'values.contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'values.email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'values.phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'values.billing_address' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        if (isset($data['values'])) {
            $values = array_intersect_key($data['values'], array_flip(self::FIELDS));
        } else {
            $values = $session->field_values ?? [];
        }
        $this->validateField('company_name', (string) ($values['company_name'] ?? ''));
        $this->validateField('client_type', (string) ($values['client_type'] ?? ''));

        $outcome = DB::transaction(function () use ($session, $request, $values, $sales, $audit): array {
            $locked = AssistantFormSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->user_id === $request->user()->id && $locked->form_key === 'client_profile', 404);
            if ($locked->status === 'saved' && $locked->draft_id) {
                $client = Client::query()->where('sales_owner_id', $request->user()->id)->findOrFail($locked->draft_id);

                return ['client' => $client, 'created' => false];
            }
            abort_unless($locked->status === 'review', 409, 'Review the completed client form before saving.');
            $locked->update(['field_values' => $values]);
            $clientRequest = Request::create('/api/sales/clients', 'POST', $values);
            $clientRequest->setUserResolver(fn () => $request->user());
            $clientData = $sales->storeClient($clientRequest, $audit)->getData(true)['data'];
            $client = Client::query()->where('sales_owner_id', $request->user()->id)->findOrFail($clientData['id']);
            $locked->update(['status' => 'saved', 'draft_id' => $client->id]);

            return ['client' => $client, 'created' => true];
        });

        $client = $outcome['client'];
        $language = $this->language($request);
        $presented = $workflows->presentClientForm($session->fresh(), $language);
        $presented['client'] = $client->only(['id', 'reference', 'company_name', 'client_type']);
        $nextSteps = $presented['next_steps'];
        $actionResults = $outcome['created'] ? [[
            'action' => 'client.created', 'entity_type' => 'client', 'entity_id' => (int) $client->id,
            'reference_number' => $client->reference, 'status' => 'active', 'operation_status' => 'success',
            'correlation_id' => (string) Str::uuid(), 'next_steps' => $nextSteps,
        ]] : [];
        if ($outcome['created']) {
            $this->appendConversation($request->user()->id, $data['conversation_uuid'] ?? null, 'Confirm and save client '.$client->company_name, $nextSteps['message']);
        }

        return response()->json(['data' => $presented, 'client' => $client, 'next_steps' => $nextSteps, 'action_results' => $actionResults]);
    }

    private function assertSession(Request $request, AssistantFormSession $session): void
    {
        abort_unless($session->user_id === $request->user()->id && $session->form_key === 'client_profile', 404);
        abort_unless($request->user()->can('clients.create'), 403);
    }

    private function validateField(string $field, string $value): void
    {
        $rules = match ($field) {
            'company_name' => ['required', 'string', 'max:180'],
            'client_type' => ['required', 'in:LCL,FCL,Mixed'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:50'],
            'billing_address' => ['nullable', 'string', 'max:2000'],
        };
        Validator::make([$field => $value], [$field => $rules])->validate();
    }

    private function language(Request $request): string
    {
        $conversationUuid = $request->input('conversation_uuid');
        if (! is_string($conversationUuid)) {
            return 'english';
        }
        $messages = AssistantConversation::query()->where('user_id', $request->user()->id)->where('conversation_uuid', $conversationUuid)->value('messages') ?? [];
        $text = collect($messages)->where('role', 'user')->pluck('content')->implode(' ');
        if (preg_match('/\b(?:filipino|tagalog)\b/iu', $text)) {
            return 'filipino';
        }

        return preg_match_all('/\b(?:paano|gawa|tulong|ikaw|kliyente|ilagay|sige|natin|gusto|kita|salamat|ang|para|ako|po)\b/iu', $text) >= 2 ? 'taglish' : 'english';
    }

    private function appendConversation(int $userId, ?string $conversationUuid, string $userText, string $assistantText): void
    {
        if (! $conversationUuid) {
            return;
        }
        $conversation = AssistantConversation::query()->where('user_id', $userId)->where('conversation_uuid', $conversationUuid)->where('expires_at', '>', now())->first();
        if (! $conversation) {
            return;
        }
        $messages = $conversation->messages ?? [];
        $messages[] = ['role' => 'user', 'content' => $userText, 'created_at' => now()->toIso8601String()];
        $messages[] = ['role' => 'assistant', 'content' => $assistantText, 'created_at' => now()->toIso8601String()];
        $conversation->update(['messages' => array_slice($messages, -20), 'last_activity_at' => now(), 'expires_at' => now()->addDays(30)]);
    }
}
