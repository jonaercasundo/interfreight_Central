<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\ShipmentForecast;
use App\Models\User;

/** Builds assistant guidance from persisted inquiry state and effective permissions. */
class InquiryNextStepRecommendationEngine
{
    /** @return array{status: string, message: string, actions: list<array{key: string, label: string}>, missing_fields: list<string>} */
    public function recommend(User $user, ClientInquiry $inquiry, string $language = 'english'): array
    {
        abort_unless((int) $inquiry->sales_owner_id === (int) $user->id, 404);

        $canView = $user->can('inquiries.view');
        $actions = [];
        $add = static function (string $key, string $label) use (&$actions): void {
            $actions[] = ['key' => $key, 'label' => $label];
        };

        $reference = $inquiry->reference ?: 'Sales Inquiry';
        $message = match ($inquiry->status) {
            'draft' => $this->draftMessage($reference, $language),
            'submitted' => "{$reference} was submitted successfully. No inquiry approval is required. The next step is Shipment Forecasting. Would you like me to prepare a forecast using this inquiry?",
            'under_review' => "{$reference} is a historical inquiry with the former under-review status. It can continue to Shipment Forecasting without inquiry approval.",
            'forecasted' => "{$reference} is forecasted. In IFC this is a planning status, not a quotation approval or an operational shipment.",
            'withdrawn' => "{$reference} is withdrawn. IFC does not provide a resume action for this state.",
            'cancelled' => "{$reference} is cancelled. IFC does not provide a reopen action for this state.",
            default => "{$reference} has the saved status “{$inquiry->status}”. I can show the record, but IFC does not define a verified next action for this status.",
        };

        $missingFields = $this->missingRequiredFields($user, $inquiry);
        if ($inquiry->status === 'draft') {
            if ($missingFields !== []) {
                $message = "{$reference} is a draft, but it needs these details before it can be submitted: ".implode(', ', $missingFields).'. Please edit and complete them first.';
            } elseif ($canView && $user->can('inquiries.submit')) {
                $add('review_submit', 'Review & Submit');
            }
            if ($canView && $user->can('inquiries.update')) {
                $add('edit_details', 'Edit Details');
            }
        }

        if ($inquiry->status === 'forecasted' && $user->can('inquiries.view') && $user->can('cost-estimates.view') && $user->can('cost-estimates.create')
            && ShipmentForecast::query()->where('sales_owner_id', $user->id)->where('inquiry_id', $inquiry->id)->where('status', 'active')->exists()) {
            $add('prepare_cost_estimate', 'Prepare Cost Estimate');
        }

        if (in_array($inquiry->status, ['submitted', 'under_review'], true) && $user->can('inquiries.view') && $user->can('forecasts.view') && $user->can('forecasts.create')) {
            $add('create_forecast', 'Create Shipment Forecast');
        }
        if ($user->can('assistant.use') && $user->can('inquiries.create')) {
            $add('create_another', 'Create Another Inquiry');
        }

        if ($canView) {
            $add('view_inquiry', 'View Inquiry');
            $add('check_status', 'Check Status');
        }

        if ($missingFields === [] && $language === 'taglish') {
            $message = match ($inquiry->status) {
                'draft' => "Ayos! Na-save na ang {$reference} bilang draft. Hindi pa ito submitted. Gusto mo bang i-review natin ang details at ihanda ito para ma-submit?",
                'submitted' => "Na-submit na ang {$reference}. Hindi kailangan ng approval sa inquiry. Gusto mo bang gumawa ng Shipment Forecast gamit ang mga detalye nito?",
                'under_review' => "Historical under-review ang {$reference}. Maaari na itong gamitin sa Shipment Forecasting nang walang inquiry approval.",
                default => $message,
            };
        } elseif ($missingFields === [] && $language === 'filipino') {
            $message = match ($inquiry->status) {
                'draft' => "Matagumpay na na-save ang {$reference} bilang draft. Hindi pa ito naipapasa para sa pagsusuri. Nais mo bang suriin natin ang mga detalye at ihanda ito para maipasa?",
                'submitted' => "Naipasa na ang {$reference}. Hindi kailangan ng pag-apruba sa inquiry. Nais mo bang gumawa ng Shipment Forecast mula sa mga detalye nito?",
                'under_review' => "Makasaysayang under-review ang {$reference}. Maaari itong magpatuloy sa Shipment Forecasting nang walang pag-apruba sa inquiry.",
                default => $message,
            };
        }

        return ['status' => $inquiry->status, 'message' => $message, 'actions' => $actions, 'missing_fields' => $missingFields];
    }

    /** @return list<string> */
    private function missingRequiredFields(User $user, ClientInquiry $inquiry): array
    {
        if ($inquiry->status !== 'draft') {
            return [];
        }

        $missing = [];
        if (! Client::query()->where('sales_owner_id', $user->id)->whereKey($inquiry->client_id)->exists()) {
            $missing[] = 'an active client';
        }
        if (! in_array($inquiry->service_type, ['LCL', 'FCL'], true)) {
            $missing[] = 'a valid service type';
        }
        if (! in_array($inquiry->transport_mode, ['Sea', 'Air', 'Road', 'Rail'], true)) {
            $missing[] = 'a valid transport mode';
        }
        foreach (['origin' => 'an origin', 'destination' => 'a destination'] as $field => $label) {
            if (blank($inquiry->{$field}) && blank($inquiry->{$field.'_location_id'}) && blank($inquiry->{$field.'_custom_details'})) {
                $missing[] = $label;
            }
        }
        if (blank($inquiry->cargo_description)) {
            $missing[] = 'a cargo description';
        }

        return $missing;
    }

    private function draftMessage(string $reference, string $language): string
    {
        return match ($language) {
            'taglish' => "Ayos! Na-save na ang {$reference} bilang draft. Hindi pa ito submitted. Gusto mo bang i-review natin ang details at ihanda ito para ma-submit?",
            'filipino' => "Matagumpay na na-save ang {$reference} bilang draft. Hindi pa ito naipapasa para sa pagsusuri. Nais mo bang suriin natin ang mga detalye at ihanda ito para maipasa?",
            default => "Done! {$reference} has been saved successfully as a draft. It has not been submitted yet. Would you like me to review the details with you and prepare it for submission?",
        };
    }
}
