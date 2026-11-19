<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SalesWorkspaceController;
use App\Models\AssistantConversation;
use App\Models\AssistantFormSession;
use App\Models\Client;
use App\Models\ClientInquiry;
use App\Services\AuditLogger;
use App\Services\FreightLocationCatalog;
use App\Services\InquiryNextStepRecommendationEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssistantFormSessionController extends Controller
{
    private const INQUIRY_STEPS = ['service_type', 'transport_mode', 'origin', 'origin_location_id', 'destination', 'destination_location_id', 'cargo_description'];

    private const SALES_FIELDS = [
        'client_id', 'service_type', 'transport_mode', 'origin', 'origin_country_code', 'origin_country_name', 'origin_location_id', 'origin_location_type', 'origin_location_code_system', 'origin_location_name', 'origin_custom_details',
        'destination', 'destination_country_code', 'destination_country_name', 'destination_location_id', 'destination_location_type', 'destination_location_code_system', 'destination_location_name', 'destination_custom_details', 'cargo_description',
    ];

    public function index(Request $request)
    {
        abort_unless($request->user()->can('inquiries.create'), 403);
        $sessions = AssistantFormSession::query()->where('user_id', $request->user()->id)->where('form_key', 'sales_inquiry')->whereIn('status', ['collecting', 'review', 'saved'])->latest('updated_at')->limit(20)->get()
            ->map(fn (AssistantFormSession $session): AssistantFormSession => $this->upgradeSession($session));

        return response()->json(['data' => $sessions->map(fn (AssistantFormSession $session) => $this->present($session, $request->user()->id))]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('inquiries.create'), 403);
        $data = $request->validate(['session_uuid' => ['nullable', 'uuid'], 'conversation_uuid' => ['nullable', 'uuid']]);
        abort_unless(isset($data['session_uuid']) || isset($data['conversation_uuid']), 422, 'A conversation or session identifier is required.');
        $existing = AssistantFormSession::query()->where('user_id', $request->user()->id)->where('form_key', 'sales_inquiry')->where(function ($query) use ($data): void {
            if (isset($data['conversation_uuid'])) {
                $query->where('conversation_uuid', $data['conversation_uuid']);
            }
            if (isset($data['session_uuid'])) {
                $query->orWhere('session_uuid', $data['session_uuid']);
            }
        })->first();
        if ($existing) {
            $existing = $this->upgradeSession($existing);

            return response()->json(['data' => $this->present($existing, $request->user()->id)]);
        }

        $session = AssistantFormSession::query()->create([
            'user_id' => $request->user()->id,
            'session_uuid' => $data['session_uuid'] ?? (string) Str::uuid(),
            'conversation_uuid' => $data['conversation_uuid'] ?? null,
            'form_key' => 'sales_inquiry',
            'field_values' => [],
            'field_order' => ['client_kind', 'client_id', ...self::INQUIRY_STEPS],
            'current_step' => 0,
            'status' => 'collecting',
        ]);

        return response()->json(['data' => $this->present($session, $request->user()->id)], 201);
    }

    public function answer(Request $request, AssistantFormSession $session)
    {
        $this->assertOwner($request, $session);
        $session = $this->upgradeSession($session);
        abort_unless($request->user()->can('inquiries.create'), 403);
        abort_unless($session->status === 'collecting', 409, 'This assistant session is not collecting answers.');
        $data = $request->validate(['answer' => ['required', 'string', 'max:4000']]);
        $field = ($session->field_order ?? [])[$session->current_step] ?? null;
        if (! $field) {
            abort(409, 'There is no unanswered required field.');
        }
        $value = trim($data['answer']);
        $values = $session->field_values ?? [];

        if (preg_match('/\b(origin|destination)\s+(?:should be|is|to)\s+([^,.!?]+)/iu', $value, $correction)) {
            $prefix = strtolower($correction[1]);
            $values = $this->assignStructuredLocation(trim($correction[2]), $prefix, $values, $session, $request->user()->id, app(FreightLocationCatalog::class));
            $session->field_values = $values;
            $session->save();
            $presented = $this->present($session, $request->user()->id);
            $this->appendConversationMessages($session, $request->user()->id, $value, 'Updated '.$correction[1].' to '.$values[$prefix].'.');

            return response()->json(['data' => $presented, 'accepted_field' => strtolower($correction[1]), 'corrected' => true]);
        }

        if ($field === 'client_kind') {
            $kind = preg_match('/\b(existing|current|old)\b/i', $value) ? 'existing' : (preg_match('/\b(new|bagong)\b/i', $value) ? 'new' : null);
            if (! $kind) {
                throw ValidationException::withMessages(['answer' => 'Please choose an existing client or a new client.']);
            }
            if ($kind === 'new') {
                abort_unless($request->user()->can('clients.create'), 403, 'Your account cannot create client records. Choose an existing client or ask an authorized Sales user to add the client.');
            }
            $values['client_kind'] = $kind;
            $session->field_order = $kind === 'new'
                ? ['client_kind', 'client_name', 'client_type', ...self::INQUIRY_STEPS]
                : ['client_kind', 'client_id', ...self::INQUIRY_STEPS];
        } elseif ($field === 'client_id') {
            $matches = $values['client_matches'] ?? [];
            if ($matches && preg_match('/^\s*(\d+)[.)]?\s*$/', $value, $choiceMatch)) {
                $choice = (int) $choiceMatch[1] - 1;
                $client = isset($matches[$choice]) ? Client::query()->where('sales_owner_id', $request->user()->id)->find($matches[$choice]['id']) : null;
                unset($values['client_matches']);
            } else {
                unset($values['client_matches']);
                $needle = mb_strtolower(trim(preg_replace('/^[\\s.,!?]+|[\\s.,!?]+$/u', '', $value) ?? $value));
                if (mb_strlen($needle) < 2) {
                    throw ValidationException::withMessages(['answer' => 'Please enter at least two characters from the client company name.']);
                }
                $found = Client::query()->where('sales_owner_id', $request->user()->id)->orderBy('company_name')->get(['id', 'company_name', 'reference'])
                    ->filter(fn (Client $item) => str_contains(mb_strtolower($item->company_name), $needle))->take(11)->values();
                $exact = $found->filter(fn (Client $item) => mb_strtolower(trim($item->company_name)) === $needle)->values();
                if ($exact->count() === 1) {
                    $found = $exact;
                }
                if ($found->count() > 1) {
                    $values['client_matches'] = $found->take(10)->map(fn (Client $item) => ['id' => $item->id, 'label' => $item->company_name.' ('.$item->reference.')'])->all();
                    $session->field_values = $values;
                    $session->save();
                    $choices = collect($values['client_matches'])->values()->map(fn ($item, $index) => ($index + 1).'. '.$item['label'])->implode(' ');
                    throw ValidationException::withMessages(['answer' => 'I found multiple client matches. Reply with the number for the correct client: '.$choices]);
                }
                $client = $found->first();
            }
            if (! $client) {
                throw ValidationException::withMessages(['answer' => 'I could not verify that exact client name in the records assigned to you. Please enter the full company name.']);
            }
            $values[$field] = (int) $client->id;
        } elseif ($field === 'client_name') {
            $values[$field] = $value;
        } elseif ($field === 'client_type') {
            $type = collect(['LCL', 'FCL', 'Mixed'])->first(fn ($type) => strcasecmp($type, $value) === 0);
            if (! $type) {
                throw ValidationException::withMessages(['answer' => 'Choose LCL, FCL, or Mixed.']);
            }
            $values[$field] = $type;
        } elseif ($field === 'origin' || $field === 'destination') {
            $prefix = $field;
            $countries = config('freight_locations.countries', []);
            $countryCode = collect($countries)->keys()->first(fn ($code) => strcasecmp($code, $value) === 0)
                ?? collect($countries)->first(fn ($name) => strcasecmp($name, $value) === 0);
            if (! $countryCode) {
                throw ValidationException::withMessages(['answer' => 'Choose a listed country: '.implode(', ', array_values($countries)).'.']);
            }
            if (! isset($countries[$countryCode])) {
                $countryCode = collect($countries)->search(fn ($name) => strcasecmp($name, $value) === 0);
            }
            $values[$prefix.'_country_code'] = $countryCode;
            $values[$prefix.'_country_name'] = $countries[$countryCode];
            $values[$prefix] = $countries[$countryCode];
        } elseif ($field === 'origin_location_id' || $field === 'destination_location_id') {
            $prefix = str_starts_with($field, 'origin_') ? 'origin' : 'destination';
            $values = $this->assignStructuredLocation($value, $prefix, $values, $session, $request->user()->id, app(FreightLocationCatalog::class));
        } elseif ($field === 'service_type') {
            $resolved = preg_match('/\bFCL\b|full container/i', $value) ? 'FCL' : (preg_match('/\bLCL\b|less than container/i', $value) ? 'LCL' : null);
            if (! $resolved) {
                throw ValidationException::withMessages(['answer' => 'Choose LCL or FCL.']);
            }
            $values[$field] = $resolved;
        } elseif ($field === 'transport_mode') {
            $resolved = null;
            foreach (['Sea', 'Air', 'Road', 'Rail'] as $mode) {
                if (preg_match('/\b'.preg_quote($mode, '/').'\b/i', $value)) {
                    $resolved = $mode;
                    break;
                }
            }
            if (! $resolved) {
                throw ValidationException::withMessages(['answer' => 'Choose Sea, Air, Road, or Rail.']);
            }
            $values[$field] = $resolved;
        } else {
            if ($value === '') {
                throw ValidationException::withMessages(['answer' => 'This field is required.']);
            }
            $values[$field] = $value;
        }

        $values = $this->captureExplicitDetails($value, $values);
        $session->field_values = $values;
        $session->current_step = $this->nextMissingStep($values, $session->field_order);
        if ($session->current_step >= count($session->field_order)) {
            $session->status = 'review';
        }
        $session->save();
        $presented = $this->present($session, $request->user()->id);
        $assistantText = $session->status === 'review'
            ? 'We have the required details. Please review them below; I will not save the draft until you confirm.'
            : (string) ($presented['question'] ?? '');
        $this->appendConversationMessages($session, $request->user()->id, $value, $assistantText);

        return response()->json(['data' => $presented, 'accepted_field' => $field]);
    }

    public function cancel(Request $request, AssistantFormSession $session)
    {
        $this->assertOwner($request, $session);
        abort_unless($request->user()->can('inquiries.create'), 403);
        abort_unless(in_array($session->status, ['collecting', 'review'], true), 409, 'This workflow can no longer be cancelled.');
        $session->update(['status' => 'cancelled']);
        $this->appendConversationMessages($session, $request->user()->id, 'Cancel this inquiry workflow.', 'I cancelled that Sales Inquiry workflow. The answers were not saved as a client or inquiry.');

        return response()->json(['data' => $this->present($session->fresh(), $request->user()->id)]);
    }

    public function saveDraft(Request $request, AssistantFormSession $session, SalesWorkspaceController $sales, AuditLogger $audit, InquiryNextStepRecommendationEngine $nextStepEngine)
    {
        $this->assertOwner($request, $session);
        $session = $this->upgradeSession($session);
        abort_unless($request->user()->can('inquiries.create'), 403);
        if ($session->status === 'collecting') {
            return response()->json(['message' => 'Choose the missing freight locations before reviewing the inquiry.', 'data' => $this->present($session, $request->user()->id)], 409);
        }
        abort_unless(in_array($session->status, ['review', 'saved'], true), 409, 'Complete the required answers before review.');
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $alreadySaved = $session->status === 'saved';
        $result = DB::transaction(function () use ($session, $request, $sales, $audit): array {
            $session = AssistantFormSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->status === 'saved') {
                $draft = ClientInquiry::query()->where('sales_owner_id', $request->user()->id)->findOrFail($session->draft_id);

                return ['draft' => $draft->load('client')->toArray(), 'session' => $this->present($session, $request->user()->id), 'created_client' => null];
            }
            abort_unless($session->status === 'review', 409, 'Complete the required answers before review.');
            $values = $session->field_values ?? [];
            $createdClient = null;
            if (($values['client_kind'] ?? null) === 'new') {
                abort_unless($request->user()->can('clients.create'), 403);
                $clientRequest = Request::create('/api/sales/clients', 'POST', ['company_name' => $values['client_name'], 'client_type' => $values['client_type']]);
                $clientRequest->setUserResolver(fn () => $request->user());
                $clientData = $sales->storeClient($clientRequest, $audit)->getData(true)['data'];
                $values['client_id'] = $clientData['id'];
                $createdClient = Client::query()->where('sales_owner_id', $request->user()->id)->findOrFail($clientData['id']);
            }
            $inquiryRequest = Request::create('/api/sales/inquiries', 'POST', collect($values)->only(self::SALES_FIELDS)->all());
            $inquiryRequest->setUserResolver(fn () => $request->user());
            $draft = $sales->storeInquiry($inquiryRequest, $audit)->getData(true)['data'] ?? null;
            abort_unless(is_array($draft) && isset($draft['id']), 500, 'The inquiry draft was not created.');
            $session->update(['status' => 'saved', 'draft_id' => (int) $draft['id'], 'field_values' => $values]);

            return ['draft' => $draft, 'session' => $this->present($session->fresh(), $request->user()->id), 'created_client' => $createdClient];
        });
        $draft = $result['draft'];
        $savedInquiry = ClientInquiry::query()->where('sales_owner_id', $request->user()->id)
            ->with('client:id,reference,company_name,client_type')->findOrFail($draft['id']);
        $recommendation = $nextStepEngine->recommend($request->user(), $savedInquiry, $this->conversationLanguage($session, $request->user()->id));
        $correlationId = (string) Str::uuid();
        $actionResults = [[
            'action' => 'sales_inquiry.created',
            'entity_type' => 'sales_inquiry',
            'entity_id' => (int) $draft['id'],
            'reference_number' => $draft['reference'],
            'status' => $draft['status'],
            'operation_status' => 'success',
            'correlation_id' => $correlationId,
            'next_steps' => $recommendation,
        ]];
        if ($result['created_client']) {
            $client = $result['created_client'];
            $actionResults[] = [
                'action' => 'client.created',
                'entity_type' => 'client',
                'entity_id' => (int) $client->id,
                'reference_number' => $client->reference,
                'status' => null,
                'operation_status' => 'success',
                'correlation_id' => $correlationId,
            ];
        }
        if (! $alreadySaved) {
            $this->appendConversationMessages($session, $request->user()->id, 'Confirmed: save this Sales Inquiry as a draft.', $recommendation['message']);
        }

        unset($result['created_client']);

        return response()->json(['data' => $result, 'action_results' => $actionResults, 'next_steps' => $recommendation], 201);
    }

    public function updateField(Request $request, AssistantFormSession $session, string $field)
    {
        $this->assertOwner($request, $session);
        $session = $this->upgradeSession($session);
        abort_unless($request->user()->can('inquiries.create'), 403);
        if ($session->status !== 'review') {
            return response()->json(['message' => 'This inquiry needs more information before it can be edited in review.', 'data' => $this->present($session, $request->user()->id)], 409);
        }
        abort_unless(in_array($field, ['client_id', 'client_name', 'client_type', 'service_type', 'transport_mode', 'origin', 'destination', 'cargo_description'], true), 404);
        $data = $request->validate(['value' => ['required', 'string', 'max:4000']]);
        $value = trim($data['value']);
        $values = $session->field_values ?? [];
        if ($field === 'client_id') {
            $client = Client::query()->where('sales_owner_id', $request->user()->id)->get(['id', 'company_name'])
                ->first(fn (Client $item) => mb_strtolower(trim($item->company_name)) === mb_strtolower($value));
            if (! $client) {
                throw ValidationException::withMessages(['value' => 'Enter the exact name of a client assigned to your account.']);
            }
            $values[$field] = (int) $client->id;
        } elseif ($field === 'client_name') {
            abort_unless($request->user()->can('clients.create'), 403);
            $values[$field] = $value;
        } elseif ($field === 'client_type') {
            $type = collect(['LCL', 'FCL', 'Mixed'])->first(fn ($type) => strcasecmp($type, $value) === 0);
            if (! $type) {
                throw ValidationException::withMessages(['value' => 'Choose LCL, FCL, or Mixed.']);
            }
            $values[$field] = $type;
        } elseif ($field === 'service_type') {
            $resolved = preg_match('/\bFCL\b|full container/i', $value) ? 'FCL' : (preg_match('/\bLCL\b|less than container/i', $value) ? 'LCL' : null);
            if (! $resolved) {
                throw ValidationException::withMessages(['value' => 'Choose LCL or FCL.']);
            }
            $values[$field] = $resolved;
        } elseif ($field === 'transport_mode') {
            $resolved = null;
            foreach (['Sea', 'Air', 'Road', 'Rail'] as $mode) {
                if (preg_match('/\b'.preg_quote($mode, '/').'\b/i', $value)) {
                    $resolved = $mode;
                    break;
                }
            }
            if (! $resolved) {
                throw ValidationException::withMessages(['value' => 'Choose Sea, Air, Road, or Rail.']);
            }
            $catalog = app(FreightLocationCatalog::class);
            foreach (['origin', 'destination'] as $prefix) {
                $locationId = $values[$prefix.'_location_id'] ?? null;
                $country = $values[$prefix.'_country_code'] ?? null;
                if ($locationId && $country && ! $catalog->resolve($locationId, $resolved, $country)) {
                    throw ValidationException::withMessages(['value' => 'This transport mode is incompatible with the selected '.$prefix.'. Edit the location first.']);
                }
            }
            $values[$field] = $resolved;
        } elseif ($field === 'origin' || $field === 'destination') {
            $prefix = $field;
            $countryName = $values[$prefix.'_country_name'] ?? '';
            $countries = config('freight_locations.countries', []);
            $parts = preg_split('/,\s*/u', $value, 2);
            if (count($parts) === 2) {
                $newCountry = collect($countries)->keys()->first(fn ($code) => strcasecmp($code, trim($parts[1])) === 0)
                    ?? collect($countries)->search(fn ($name) => strcasecmp($name, trim($parts[1])) === 0);
                if ($newCountry) {
                    $values[$prefix.'_country_code'] = $newCountry;
                    $values[$prefix.'_country_name'] = $countries[$newCountry];
                    $countryName = $countries[$newCountry];
                }
            }
            $answer = preg_replace('/,\s*'.preg_quote($countryName, '/').'$/iu', '', trim($parts[0])) ?? $parts[0];
            $values = $this->assignStructuredLocation($answer, $prefix, $values, $session, $request->user()->id, app(FreightLocationCatalog::class));
        } else {
            if ($value === '') {
                throw ValidationException::withMessages(['value' => 'This field is required.']);
            }
            $values[$field] = $value;
        }

        $session->update(['field_values' => $values]);

        return response()->json(['data' => $this->present($session->fresh(), $request->user()->id)]);
    }

    private function present(AssistantFormSession $session, int $userId): array
    {
        $values = $session->field_values ?? [];
        $publicValues = $values;
        unset($publicValues['client_matches'], $publicValues['origin_location_matches'], $publicValues['destination_location_matches']);
        $field = ($session->field_order ?? [])[$session->current_step] ?? null;
        $question = match ($field) {
            'client_kind' => 'Is this for an existing client or a new client?',
            'client_id' => 'Which client should I use? Enter the full company name.',
            'client_name' => 'What is the new client company name?',
            'client_type' => 'What service type does this client handle: LCL, FCL, or Mixed?',
            'service_type' => 'What service type does the client need: LCL or FCL?',
            'transport_mode' => 'Which transport mode: Sea, Air, Road, or Rail?',
            'origin' => 'Which country is the shipment origin in?',
            'origin_location_id' => 'Which listed origin location should I use?',
            'destination' => 'Which country is the shipment destination in?',
            'destination_location_id' => 'Which listed destination location should I use?',
            'cargo_description' => 'What goods or cargo should this inquiry describe?',
            default => null,
        };
        $clientName = isset($values['client_id']) ? Client::query()->where('sales_owner_id', $userId)->whereKey($values['client_id'])->value('company_name') : null;
        $suggestedChoices = [];
        if (in_array($field, ['origin', 'destination'], true)) {
            $suggestedChoices = array_values(config('freight_locations.countries', []));
        } elseif (in_array($field, ['origin_location_id', 'destination_location_id'], true)) {
            $prefix = str_starts_with($field, 'origin_') ? 'origin' : 'destination';
            $saved = $values[$prefix.'_location_matches'] ?? [];
            $suggestedChoices = $saved
                ? collect($saved)->values()->map(fn (array $item, int $index): string => ($index + 1).'. '.$item['label'])->all()
                : collect(app(FreightLocationCatalog::class)->search($values['transport_mode'] ?? 'Sea', $values[$prefix.'_country_code'] ?? null, null))
                    ->take(8)->map(fn (array $item): string => $item['name'])->unique()->values()->all();
        }

        return ['id' => $session->id, 'session_uuid' => $session->session_uuid, 'form_key' => $session->form_key,
            'conversation_uuid' => $session->conversation_uuid,
            'status' => $session->status, 'values' => [...$publicValues, ...(isset($values['client_id']) ? ['client_name' => $clientName] : [])], 'current_field' => $field,
            'question' => $question, 'suggested_choices' => $suggestedChoices, 'countries' => app(FreightLocationCatalog::class)->countries(), 'completed_fields' => min($session->current_step, count($session->field_order ?? [])), 'total_required_fields' => count($session->field_order ?? []),
            'progress' => (int) round(min($session->current_step, count($session->field_order ?? [])) / max(count($session->field_order ?? []), 1) * 100), 'draft_id' => $session->draft_id];
    }

    private function upgradeSession(AssistantFormSession $session): AssistantFormSession
    {
        if (! in_array($session->status, ['collecting', 'review'], true)) {
            return $session;
        }

        $values = $session->field_values ?? [];
        if (! isset($values['client_kind']) && $session->current_step === 0 && ($session->field_order[0] ?? null) === 'client_kind') {
            return $session;
        }
        $kind = $values['client_kind'] ?? (isset($values['client_name']) ? 'new' : 'existing');
        $values['client_kind'] = $kind;
        $steps = $kind === 'new'
            ? ['client_kind', 'client_name', 'client_type', ...self::INQUIRY_STEPS]
            : ['client_kind', 'client_id', ...self::INQUIRY_STEPS];
        $countries = config('freight_locations.countries', []);
        $catalog = app(FreightLocationCatalog::class);

        foreach (['origin', 'destination'] as $prefix) {
            if (isset($values[$prefix.'_location_id']) || ($values[$prefix.'_location_type'] ?? null) === 'custom') {
                continue;
            }

            $raw = trim((string) ($values[$prefix] ?? ''));
            if ($raw === '') {
                continue;
            }

            $countryCode = $values[$prefix.'_country_code'] ?? null;
            $locationName = $raw;
            if (preg_match('/^(.+?),\s*([^,]+)$/u', $raw, $parts)) {
                $locationName = trim($parts[1]);
                $suffix = trim($parts[2]);
                $countryCode = collect($countries)->keys()->first(fn (string $code): bool => strcasecmp($code, $suffix) === 0)
                    ?? collect($countries)->search(fn (string $name): bool => strcasecmp($name, $suffix) === 0)
                    ?? $countryCode;
            } else {
                $countryCode = collect($countries)->keys()->first(fn (string $code): bool => strcasecmp($code, $raw) === 0)
                    ?? collect($countries)->search(fn (string $name): bool => strcasecmp($name, $raw) === 0)
                    ?? $countryCode;
                if ($countryCode && strcasecmp($countries[$countryCode] ?? '', $raw) === 0) {
                    $values[$prefix.'_country_code'] = $countryCode;
                    $values[$prefix.'_country_name'] = $countries[$countryCode];

                    continue;
                }
            }

            if (! $countryCode || ! isset($countries[$countryCode])) {
                continue;
            }
            $values[$prefix.'_country_code'] = $countryCode;
            $values[$prefix.'_country_name'] = $countries[$countryCode];
            $matches = collect($catalog->search($values['transport_mode'] ?? 'Sea', $countryCode, $locationName));
            $exact = $matches->first(fn (array $item): bool => strcasecmp($item['name'], $locationName) === 0 || strcasecmp($item['code'], $locationName) === 0);
            if ($exact) {
                $values[$prefix.'_location_id'] = $exact['id'];
                $values[$prefix.'_location_type'] = $exact['type'];
                $values[$prefix.'_location_code_system'] = $exact['code_system'];
                $values[$prefix.'_location_name'] = $exact['name'];
                $values[$prefix.'_custom_details'] = null;
                $values[$prefix] = $exact['name'].', '.$countries[$countryCode];
            }
        }

        $currentStep = $this->nextMissingStep($values, $steps);
        $status = $session->status === 'review' && $currentStep < count($steps) ? 'collecting' : $session->status;
        if (($session->field_order ?? []) !== $steps || $session->current_step !== $currentStep || $session->status !== $status || $session->field_values !== $values) {
            $session->update(['field_order' => $steps, 'current_step' => $currentStep, 'status' => $status, 'field_values' => $values]);
        }

        return $session->fresh();
    }

    private function captureExplicitDetails(string $answer, array $values): array
    {
        if (! isset($values['service_type'])) {
            if (preg_match('/\bFCL\b|full container/i', $answer)) {
                $values['service_type'] = 'FCL';
            } elseif (preg_match('/\bLCL\b|less than container/i', $answer)) {
                $values['service_type'] = 'LCL';
            }
        }
        if (! isset($values['transport_mode'])) {
            foreach (['Sea', 'Air', 'Road', 'Rail'] as $mode) {
                if (preg_match('/\b'.preg_quote($mode, '/').'\b/i', $answer)) {
                    $values['transport_mode'] = $mode;
                    break;
                }
            }
        }

        return $values;
    }

    private function assignStructuredLocation(string $answer, string $prefix, array $values, AssistantFormSession $session, int $userId, FreightLocationCatalog $locations): array
    {
        $country = $values[$prefix.'_country_code'] ?? null;
        if (! $country) {
            throw ValidationException::withMessages(['answer' => 'Choose the country before selecting its location.']);
        }
        $mode = $values['transport_mode'] ?? 'Sea';
        $matchesKey = $prefix.'_location_matches';
        $savedMatches = $values[$matchesKey] ?? [];
        $custom = preg_match('/^custom:\s*(.+)$/iu', trim($answer), $customMatch) === 1 ? trim($customMatch[1]) : '';

        if ($custom !== '') {
            if (mb_strlen($custom) > 180) {
                throw ValidationException::withMessages(['answer' => 'A custom location must be 180 characters or fewer.']);
            }
            unset($values[$matchesKey]);
            $countryName = config('freight_locations.countries.'.$country, $values[$prefix.'_country_name'] ?? $country);
            $values[$prefix.'_location_id'] = null;
            $values[$prefix.'_location_type'] = 'custom';
            $values[$prefix.'_location_code_system'] = 'custom';
            $values[$prefix.'_location_name'] = $custom;
            $values[$prefix.'_custom_details'] = $custom;
            $values[$prefix] = $custom.', '.$countryName;

            return $values;
        }

        if ($savedMatches && preg_match('/^\s*(\d+)(?:[.)]\s*.*)?$/', $answer, $choiceMatch)) {
            $choice = (int) $choiceMatch[1] - 1;
            $selectedId = $savedMatches[$choice]['id'] ?? null;
            $location = is_string($selectedId) ? $locations->resolve($selectedId, $mode, $country) : null;
            unset($values[$matchesKey]);
        } else {
            unset($values[$matchesKey]);
            $search = $locations->search($mode, $country, $answer);
            $exact = collect($search)->filter(fn (array $item): bool => strcasecmp($item['name'], trim($answer)) === 0 || strcasecmp($item['code'], trim($answer)) === 0)->values();
            $found = $exact->isNotEmpty() ? $exact : collect($search)->filter(fn (array $item): bool => str_contains(mb_strtolower($item['name'].' '.$item['code']), mb_strtolower(trim($answer))))->values();
            if ($found->count() > 1) {
                $values[$matchesKey] = $found->take(10)->map(fn (array $item): array => ['id' => $item['id'], 'label' => $item['name'].' ('.$item['code'].')'])->all();
                $session->field_values = $values;
                $session->save();
                $choices = collect($values[$matchesKey])->values()->map(fn (array $item, int $index): string => ($index + 1).'. '.$item['label'])->implode(' ');
                throw ValidationException::withMessages(['answer' => 'I found multiple locations. Reply with the number for the correct one: '.$choices]);
            }
            $location = $found->first();
        }

        if (! $location) {
            throw ValidationException::withMessages(['answer' => 'That location is not in the authorized freight directory for this country and transport mode. Choose a listed location or reply “Custom: location name” if the place is not listed.']);
        }
        $values[$prefix.'_location_id'] = $location['id'];
        $values[$prefix.'_location_type'] = $location['type'];
        $values[$prefix.'_location_code_system'] = $location['code_system'];
        $values[$prefix.'_location_name'] = $location['name'];
        $values[$prefix.'_custom_details'] = null;
        $values[$prefix.'_country_name'] = $location['country_name'];
        $values[$prefix] = $location['name'].', '.$location['country_name'];

        return $values;
    }

    private function nextMissingStep(array $values, array $fields): int
    {
        foreach ($fields as $index => $field) {
            if (! isset($values[$field]) || $values[$field] === '') {
                return $index;
            }
        }

        return count($fields);
    }

    private function assertOwner(Request $request, AssistantFormSession $session): void
    {
        abort_unless((int) $session->user_id === (int) $request->user()->id, 404);
    }

    private function appendConversationMessages(AssistantFormSession $session, int $userId, string $userText, string $assistantText): void
    {
        if (! $session->conversation_uuid || trim($assistantText) === '') {
            return;
        }
        $conversation = AssistantConversation::query()->where('user_id', $userId)->where('conversation_uuid', $session->conversation_uuid)
            ->where('expires_at', '>', now())->first();
        if (! $conversation) {
            return;
        }
        $messages = $conversation->messages ?? [];
        $messages[] = ['role' => 'user', 'content' => $userText, 'created_at' => now()->toIso8601String()];
        $messages[] = ['role' => 'assistant', 'content' => $assistantText, 'created_at' => now()->toIso8601String()];
        $conversation->update(['messages' => array_slice($messages, -20), 'last_activity_at' => now(), 'expires_at' => now()->addDays(30)]);
    }

    private function conversationLanguage(AssistantFormSession $session, int $userId): string
    {
        if (! $session->conversation_uuid) {
            return 'english';
        }

        $messages = AssistantConversation::query()->where('user_id', $userId)
            ->where('conversation_uuid', $session->conversation_uuid)->value('messages') ?? [];
        $userText = collect($messages)->where('role', 'user')->pluck('content')->implode(' ');
        if (preg_match('/\b(?:filipino|tagalog)\b/iu', $userText)) {
            return 'filipino';
        }
        if (preg_match_all('/\b(?:paano|gawa|tulong|sige|opo|hindi|natin|gusto|kita|salamat|ba|ang|para|ako|po)\b/iu', $userText) >= 2) {
            return 'taglish';
        }

        return 'english';
    }
}
