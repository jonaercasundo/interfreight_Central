<?php

namespace App\Services;

use App\Models\ClientInquiry;
use App\Models\SalesCapacityAvailability;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class SalesTutorialAssistant
{
    public function __construct(private readonly TutorialKnowledgeBase $knowledgeBase, private readonly AiAssistantClient $ai) {}

    /** @return array<string, mixed> */
    public function answer(User $user, string $message, ?string $currentPath, ?int $inquiryId, array $conversationHistory = []): array
    {
        $page = $currentPath ? $this->knowledgeBase->authorizedPage($user, $currentPath) : null;
        if ($currentPath && str_starts_with($currentPath, '/sales') && ! $page) {
            throw new HttpException(403, 'The current Sales page is not authorized for this account.');
        }

        $record = null;
        if ($inquiryId !== null) {
            abort_unless($user->can('inquiries.view'), 403);
            $record = ClientInquiry::query()->where('sales_owner_id', $user->id)->findOrFail($inquiryId);
        }

        $catalog = $this->knowledgeBase->forUser($user, $currentPath);
        $verifiedTutorials = collect($catalog['tutorials'])->map(fn (array $item): array => [
            'key' => $item['key'], 'title' => $item['module_name'], 'module_name' => $item['module_name'],
            'steps' => $item['steps'], 'next_workflow_step' => $item['next_workflow_step'],
            'navigation_page' => $this->knowledgeBase->authorizedPage($user, $item['navigation_path'])['label'] ?? null,
        ])->values()->all();
        $context = [
            'module' => 'sales', 'page_key' => $this->pageKey($currentPath),
            'authorized_page' => $page['label'] ?? null,
            'authorized_actions' => array_values(array_filter([
                $user->can('clients.create') ? 'Add a client in Client Management' : null,
                $user->can('inquiries.create') ? 'Prepare a Sales Inquiry draft' : null,
                $user->can('inquiries.submit') ? 'Submit an owned draft inquiry' : null,
                $user->can('forecasts.create') ? 'Create a shipment forecast' : null,
                $user->can('quotations.create') ? 'Prepare a quotation' : null,
            ])),
            'verified_tutorials' => $verifiedTutorials,
            'conversation_history' => array_slice($conversationHistory, -10),
        ];
        if ($record) {
            $context['record_status'] = $record->status;
        }

        $classification = null;
        $degraded = false;
        try {
            $classification = $this->ai->ask($message, (string) Str::uuid(), $context, $user);
        } catch (Throwable) {
            // Workflow facts and tutorials remain deterministic if FastAPI is down.
            $degraded = true;
        }

        $intent = $classification['intent'] ?? $this->fallbackIntent($message);
        if ($record && in_array($intent, ['inquiry_status', 'inquiry_approval', 'next_step'], true)) {
            return [
                'answer' => $this->explainInquiry($user, $record),
                'topic' => 'sales_tutorial', 'intent' => $intent, 'language' => $classification['language'] ?? 'english',
                'source' => 'client_inquiries.status', 'record_reference' => $record->reference, 'degraded' => $degraded,
            ];
        }

        if ($intent === 'availability') {
            return [
                'answer' => $this->availabilityAnswer($user), 'topic' => 'sales_tutorial', 'intent' => $intent,
                'language' => $classification['language'] ?? 'english', 'source' => 'verified_sales_capacity', 'degraded' => $degraded,
            ];
        }

        if (in_array($intent, ['general'], true) && ! $page) {
            if ($classification) {
                return [...$classification, 'degraded' => false];
            }

            throw new HttpException(503, 'AI service is temporarily unavailable.');
        }

        $tutorialKey = $this->tutorialForIntent($intent, $page['path'] ?? null);
        $tutorial = collect($catalog['tutorials'])->firstWhere('key', $tutorialKey);
        if (! $tutorial) {
            return [
                'answer' => 'I can only guide you through a Sales page that your account is allowed to use. Ask an IFC Administrator to review your permissions.',
                'topic' => 'sales_tutorial', 'intent' => $intent, 'source' => 'permission_filtered_sales_tutorials', 'degraded' => $degraded,
            ];
        }

        return [
            'answer' => $classification['answer'] ?? $this->tutorialAnswer($user, $tutorial, $intent, $classification['language'] ?? 'english'),
            'topic' => 'sales_tutorial', 'intent' => $intent, 'language' => $classification['language'] ?? 'english',
            'action_requested' => (bool) ($classification['action_requested'] ?? false),
            'intent_confidence' => (float) ($classification['intent_confidence'] ?? 0),
            'tutorial_key' => $tutorial['key'], 'tutorial_version' => $catalog['version'], 'source' => 'verified_ifc_sales_tutorial', 'degraded' => $degraded,
        ];
    }

    private function explainInquiry(User $user, ClientInquiry $inquiry): string
    {
        $reference = $inquiry->reference ?: 'This inquiry';

        return match ($inquiry->status) {
            'draft' => $user->can('inquiries.submit') && $user->can('inquiries.view')
                ? "{$reference} is a draft. It has not been submitted. Review its details, then use Client Inquiries to submit it if ready."
                : "{$reference} is a draft. Your account cannot submit inquiries; ask an authorized Sales user for help.",
            'submitted' => $this->submittedExplanation($user, $reference),
            'under_review' => $user->can('forecasts.create')
                ? "{$reference} has a historical under_review status. Inquiry approval is no longer required; you can create a shipment forecast now."
                : "{$reference} has a historical under_review status. Inquiry approval is no longer required; an authorized Sales user can create a shipment forecast.",
            'forecasted' => $user->can('cost-estimates.create') && $user->can('cost-estimates.view')
                ? "{$reference} is forecasted. The forecast is planning demand, not a shipment. You can prepare an internal cost estimate if the forecast is active."
                : "{$reference} is forecasted. This is a planning record, not an operational shipment. Cost estimate access is not available to your account.",
            'withdrawn' => "{$reference} is withdrawn. The current inquiry workflow does not provide a resume action; contact an authorized Sales Manager if it should be reopened.",
            'cancelled' => "{$reference} is cancelled. The inquiry workflow does not provide a reopen action.",
            default => "{$reference} currently has status ‘{$inquiry->status}’. I cannot infer an approval or next action that is not represented in this record’s workflow.",
        };
    }

    private function submittedExplanation(User $user, string $reference): string
    {
        return $user->can('forecasts.create')
            ? "{$reference} is submitted. No inquiry approval is required. You can create a shipment forecast from this inquiry now."
            : "{$reference} is submitted. No inquiry approval is required. An authorized Sales user can create a shipment forecast from it.";
    }

    private function availabilityAnswer(User $user): string
    {
        if (! $user->can('sales.availability.view')) {
            return 'Your account cannot view Shipment Availability. Ask an IFC Administrator to review your permissions.';
        }

        $records = SalesCapacityAvailability::query()->whereNull('deactivated_at')->where('verified_at', '<=', now())
            ->where('valid_from', '<=', today())->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
            ->whereBetween('departure_date', [today(), today()->addDays(7)])
            ->whereColumn('confirmed_capacity', '>', 'reserved_capacity')->orderBy('departure_date')->limit(5)->get();

        if ($records->isEmpty()) {
            return 'I found no verified, currently valid capacity records in the register for the next 7 days. This does not prove that carriers have no space; check Shipment Availability or verify directly with the carrier/forwarder. Forecasts are not capacity evidence.';
        }

        $lines = $records->map(fn (SalesCapacityAvailability $record): string => sprintf(
            '%s → %s · %s · %s · %s %s available (verified %s)',
            $record->origin, $record->destination, $record->transport_mode, $record->departure_date->toDateString(),
            $record->available_capacity, $record->capacity_unit, $record->verified_at?->toDateString() ?? 'date unavailable'
        ));

        return "Verified register entries for the next 7 days:\n• ".implode("\n• ", $lines->all())."\nConfirm current terms and booking cutoff with the recorded source before booking.";
    }

    /** @param array<string, mixed> $tutorial */
    private function tutorialAnswer(User $user, array $tutorial, string $intent, string $language): string
    {
        if ($intent === 'inquiry_status') {
            return 'Select an owned inquiry from the context selector so I can read its current saved status. IFC inquiries do not currently have returned or declined states; I will not infer them.';
        }
        if ($intent === 'inquiry_approval') {
            return 'Client Inquiries do not require approval. Submit a completed inquiry, then create a Shipment Forecast. Quotation approval remains a separate later step.';
        }

        $steps = array_slice($tutorial['steps'], 0, 3);
        $prefix = $language === 'taglish'
            ? "Verified na Sales guide (v{$tutorial['key']}): {$tutorial['module_name']}.\n"
            : "{$tutorial['module_name']} — verified IFC guide.\n";

        return $prefix.implode("\n", array_map(fn (string $step, int $index): string => ($index + 1).'. '.$step, $steps, array_keys($steps)))."\nNext: {$tutorial['next_workflow_step']}\nOpen: {$tutorial['navigation_path']}";
    }

    private function tutorialForIntent(string $intent, ?string $path): string
    {
        return match ($intent) {
            'create_client' => 'client-management',
            'create_inquiry' => 'client-inquiries',
            'inquiry_status', 'inquiry_approval' => 'sales-status-monitoring',
            'forecasting' => 'shipment-forecasting',
            'quotation' => 'quotation-preparation',
            'calendar' => 'shipment-calendar',
            'availability' => 'shipment-availability',
            'page_help' => match ($path) {
                '/sales/clients' => 'client-management', '/sales/inquiries' => 'client-inquiries',
                '/sales/calendar' => 'shipment-calendar',
                '/sales/availability' => 'shipment-availability', '/sales/forecasts/calendar', '/sales/forecasts/monthly' => 'shipment-forecasting',
                '/sales/cost-estimates' => 'internal-cost-estimates', '/sales/quotations', '/sales/quotation-history' => 'quotation-preparation',
                '/admin/roles' => 'administrator-role-access',
                default => 'sales-dashboard',
            },
            default => $path === '/admin/roles' ? 'administrator-role-access' : 'sales-dashboard',
        };
    }

    private function pageKey(?string $path): string
    {
        return match ($path) {
            '/sales/clients' => 'client-management', '/sales/inquiries' => 'client-inquiries',
            '/sales/calendar' => 'shipment-calendar',
            '/sales/availability' => 'shipment-availability', '/sales/forecasts/calendar', '/sales/forecasts/monthly' => 'shipment-forecasting',
            '/sales/cost-estimates' => 'internal-cost-estimates', '/sales/quotations', '/sales/quotation-history' => 'quotation-preparation',
            default => 'sales-dashboard',
        };
    }

    private function fallbackIntent(string $message): string
    {
        $text = Str::lower($message);

        return match (true) {
            Str::contains($text, ['approve', 'approval', 'mag-aapprove', 'sino']) => 'inquiry_approval',
            Str::contains($text, ['pending', 'submitted', 'status', 'next', 'ano next']) => 'inquiry_status',
            Str::contains($text, ['available', 'availability', 'capacity']) => 'availability',
            Str::contains($text, ['calendar', 'schedule']) => 'calendar',
            Str::contains($text, ['quotation', 'quote']) => 'quotation',
            Str::contains($text, ['forecast']) => 'forecasting',
            Str::contains($text, ['client']) => 'create_client',
            Str::contains($text, ['inquiry', 'inquiries', 'inquiry']) => 'create_inquiry',
            default => 'page_help',
        };
    }
}
