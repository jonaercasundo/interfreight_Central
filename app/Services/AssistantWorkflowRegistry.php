<?php

namespace App\Services;

use App\Models\AssistantConversation;
use App\Models\AssistantFormSession;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssistantWorkflowRegistry
{
    public function startClientForm(User $user, ?AssistantConversation $conversation, string $language = 'english', float $confidence = 1.0): array
    {
        abort_unless($user->can('clients.create'), 403, 'Your account cannot create client records. Ask an IFC Administrator to review your permissions.');
        $fields = ['company_name', 'client_type', 'contact_name', 'email', 'phone', 'billing_address'];
        $create = fn () => AssistantFormSession::query()->create([
            'user_id' => $user->id,
            'session_uuid' => (string) Str::uuid(),
            'conversation_uuid' => $conversation?->conversation_uuid,
            'form_key' => 'client_profile',
            'field_values' => [],
            'field_order' => $fields,
            'current_step' => 0,
            'status' => 'collecting',
        ]);
        $session = DB::transaction(function () use ($conversation, $user, $create): AssistantFormSession {
            if ($conversation) {
                AssistantConversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                $active = AssistantFormSession::query()->where('user_id', $user->id)->where('conversation_uuid', $conversation->conversation_uuid)
                    ->where('form_key', 'client_profile')->whereIn('status', ['collecting', 'review'])->latest('id')->first();
                if ($active) {
                    return $active;
                }
            }

            return $create();
        });
        $field = ($session->field_order ?? [])[$session->current_step] ?? null;
        $question = $this->clientQuestion($field, $language);
        $answer = match ($session->status) {
            'review' => $language === 'filipino' ? 'Nakumpleto ko na ang mga detalye ng kliyente. Pakisuri muna bago ko ito i-save.' : ($language === 'taglish' ? 'Nakuha ko na ang client details. Paki-review muna bago ko ito i-save.' : "I've collected the client details. Please review them before I save anything."),
            'saved' => 'This conversation already has a saved client record. I did not create another one.',
            default => $session->current_step === 0 ? match ($language) {
                'taglish' => 'Sige! Tutulungan kitang punan ang Client Management form. Ano ang company o client name?',
                'filipino' => 'Sige! Tutulungan kitang punan ang Client Management form. Ano ang pangalan ng kumpanya o kliyente?',
                default => "Sure! I'll fill out the Client Management form with you. What company or client name should I enter?",
            } : $question,
        };

        return [
            'answer' => $answer,
            'topic' => 'sales_tutorial',
            'intent' => 'CREATE_CLIENT',
            'confidence' => $confidence,
            'workflow_session' => $this->presentClientForm($session, $language),
            'degraded' => false,
        ];
    }

    public function presentClientForm(AssistantFormSession $session, string $language = 'english'): array
    {
        $field = ($session->field_order ?? [])[$session->current_step] ?? null;
        $choices = $field === 'client_type' ? ['LCL', 'FCL', 'Mixed'] : [];
        $required = ['company_name', 'client_type'];
        $client = $session->status === 'saved' && $session->draft_id
            ? Client::query()->where('sales_owner_id', $session->user_id)->find($session->draft_id)
            : null;
        $nextSteps = ['message' => $client ? match ($language) {
            'taglish' => "Ayos! Na-save na si {$client->company_name} bilang client {$client->reference}.",
            'filipino' => "Tapos na! Nai-save na ang {$client->company_name} bilang kliyente {$client->reference}.",
            default => "Done! {$client->company_name} has been saved as client {$client->reference}.",
        } : null, 'actions' => []];
        $user = User::query()->find($session->user_id);
        if ($client && $user?->can('inquiries.create')) {
            $nextSteps['message'] .= match ($language) {
                'taglish' => ' Gusto mo bang gumawa tayo ng Sales Inquiry para sa client na ito?',
                'filipino' => ' Nais mo bang gumawa tayo ng Sales Inquiry para sa kliyenteng ito?',
                default => ' Would you like me to help you create a Sales Inquiry for this client?',
            };
            $nextSteps['actions'][] = ['key' => 'start_sales_inquiry', 'label' => $language === 'english' ? 'Create Sales Inquiry' : 'Gumawa ng Sales Inquiry'];
        }

        return [
            'id' => $session->id,
            'session_uuid' => $session->session_uuid,
            'conversation_uuid' => $session->conversation_uuid,
            'form_key' => 'client_profile',
            'status' => $session->status,
            'values' => $session->field_values ?? [],
            'source_fields' => array_keys(array_filter($session->field_values ?? [], fn ($value): bool => $value !== null && $value !== '')),
            'current_field' => $field,
            'question' => $this->clientQuestion($field, $language),
            'choices' => $choices,
            'required_fields' => $required,
            'completed_fields' => $session->current_step,
            'total_fields' => count($session->field_order ?? []),
            'progress' => (int) round($session->current_step / max(count($session->field_order ?? []), 1) * 100),
            'client' => $client?->only(['id', 'reference', 'company_name', 'client_type']),
            'next_steps' => $nextSteps,
        ];
    }

    private function clientQuestion(?string $field, string $language): ?string
    {
        $questions = [
            'english' => [
                'company_name' => 'What company or client name should I enter?',
                'client_type' => 'Which client type should I select: LCL, FCL, or Mixed?',
                'contact_name' => 'What is the contact person’s name? You can say “skip” if you do not have it.',
                'email' => 'What is the contact email? You can say “skip” if you do not have it.',
                'phone' => 'What is the contact phone number? You can say “skip” if you do not have it.',
                'billing_address' => 'What billing address should I enter? You can say “skip” if you do not have it.',
            ],
            'taglish' => [
                'company_name' => 'Ano ang company o client name na ilalagay ko?',
                'client_type' => 'Aling client type ang pipiliin: LCL, FCL, o Mixed?',
                'contact_name' => 'Sino ang contact person? Sabihin ang “skip” kung wala pa.',
                'email' => 'Ano ang contact email? Sabihin ang “skip” kung wala pa.',
                'phone' => 'Ano ang contact phone number? Sabihin ang “skip” kung wala pa.',
                'billing_address' => 'Ano ang billing address? Sabihin ang “skip” kung wala pa.',
            ],
            'filipino' => [
                'company_name' => 'Ano ang pangalan ng kumpanya o kliyente?',
                'client_type' => 'Aling uri ng kliyente ang pipiliin: LCL, FCL, o Mixed?',
                'contact_name' => 'Sino ang contact person? Sabihin ang “skip” kung wala pa.',
                'email' => 'Ano ang email ng contact? Sabihin ang “skip” kung wala pa.',
                'phone' => 'Ano ang numero ng telepono ng contact? Sabihin ang “skip” kung wala pa.',
                'billing_address' => 'Ano ang billing address? Sabihin ang “skip” kung wala pa.',
            ],
        ];

        return $questions[$language][$field] ?? null;
    }

    public function startSalesInquiry(User $user, ?AssistantConversation $conversation, float $confidence = 1.0): array
    {
        abort_unless($user->can('inquiries.create'), 403, 'Your account cannot create Sales Inquiries. Ask an IFC Administrator to review your permissions.');
        $create = fn () => AssistantFormSession::query()->create([
            'user_id' => $user->id,
            'session_uuid' => (string) Str::uuid(),
            'conversation_uuid' => $conversation?->conversation_uuid,
            'form_key' => 'sales_inquiry',
            'field_values' => [],
            'field_order' => ['client_kind', 'client_id', 'service_type', 'transport_mode', 'origin', 'origin_location_id', 'destination', 'destination_location_id', 'cargo_description'],
            'current_step' => 0,
            'status' => 'collecting',
        ]);
        $session = $conversation
            ? DB::transaction(function () use ($conversation, $user, $create) {
                AssistantConversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();

                return AssistantFormSession::query()->where('user_id', $user->id)->where('conversation_uuid', $conversation->conversation_uuid)->where('form_key', 'sales_inquiry')->first() ?? $create();
            })
            : $create();

        $field = ($session->field_order ?? [])[$session->current_step] ?? null;
        $question = match ($field) {
            'client_kind' => 'Is this for an existing client or a new client?',
            'client_id' => 'Which client should I use? Enter the full company name.',
            'client_name' => 'What is the new client company name?',
            'client_type' => 'What service type does this client handle: LCL, FCL, or Mixed?',
            'service_type' => 'What service type does the client need: LCL or FCL?',
            'transport_mode' => 'Which transport mode: Sea, Air, Road, or Rail?',
            'origin' => 'Which country is the shipment origin in?',
            'origin_location_id' => 'Which seaport or terminal is the origin?',
            'destination' => 'Which country is the shipment destination in?',
            'destination_location_id' => 'Which seaport or terminal is the destination?',
            'cargo_description' => 'What goods or cargo should this inquiry describe?',
            default => null,
        };
        $answer = match ($session->status) {
            'review' => 'Your Sales Inquiry is ready for review. Check the details and confirm before I save the draft.',
            'saved' => 'This conversation already has a saved Sales Inquiry draft. I did not create another one.',
            'cancelled' => "That inquiry workflow was cancelled. Say 'start a new inquiry' to begin again.",
            default => $session->current_step === 0
                ? "Sure! I'll help you create a new Sales Inquiry. Is this for an existing client or a new client?"
                : 'Your Sales Inquiry is already in progress. '.$question,
        };

        return [
            'answer' => $answer,
            'topic' => 'sales_tutorial',
            'intent' => 'CREATE_SALES_INQUIRY',
            'confidence' => $confidence,
            'workflow_session' => [
                'id' => $session->id,
                'session_uuid' => $session->session_uuid,
                'conversation_uuid' => $session->conversation_uuid,
                'status' => $session->status,
                'values' => $session->field_values ?? [],
                'current_field' => $field,
                'question' => $question,
                'completed_fields' => $session->current_step,
                'total_required_fields' => count($session->field_order ?? []),
                'progress' => (int) round($session->current_step / max(count($session->field_order ?? []), 1) * 100),
            ],
            'degraded' => false,
        ];
    }
}
