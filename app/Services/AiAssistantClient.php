<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class AiAssistantClient
{
    /** @param array<string, mixed>|null $context
     * @return array{answer: string, topic: string, intent?: string, language?: string}
     */
    public function ask(string $message, ?string $correlationId = null, ?array $context = null, ?User $user = null, array $usageMetadata = []): array
    {
        $started = microtime(true);
        $usageMetadata += ['correlation_id' => $correlationId, 'request_type' => 'assistant', 'module' => $context['module'] ?? 'assistant'];
        $baseUrl = rtrim((string) config('services.ai.url'), '/');
        $token = (string) config('services.ai.token');

        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('AI service is not configured.');
        }
        if (! filter_var($baseUrl, FILTER_VALIDATE_URL) || ! in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException('AI service URL is invalid.');
        }
        if (app()->environment('production') && parse_url($baseUrl, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('AI service must use HTTPS in production.');
        }
        if (app()->environment('production') && ! config('services.ai.verify_tls', true)) {
            throw new RuntimeException('TLS verification cannot be disabled in production.');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($token)
                ->withHeader('X-Correlation-ID', $correlationId ?? (string) Str::uuid())
                ->withOptions(['verify' => config('services.ai.ca_bundle') ?: (bool) config('services.ai.verify_tls', true)])
                ->connectTimeout((int) config('services.ai.connect_timeout', 3))
                ->timeout((int) config('services.ai.timeout', 10))
                ->post($baseUrl.'/assistant', array_filter(['message' => $message, 'context' => $context], fn ($value) => $value !== null));
        } catch (ConnectionException $exception) {
            app(AiCredentialService::class)->recordUsage($user, (string) config('ifc_ai.model'), 'error', 'service_unavailable', null, null, (int) round((microtime(true) - $started) * 1000), $usageMetadata);
            throw $exception;
        }

        if (! $response->successful()) {
            $category = $this->safeCategory($response->status(), $response->json('detail'));
            app(AiCredentialService::class)->recordUsage($user, (string) config('ifc_ai.model'), 'error', $category, null, null, (int) round((microtime(true) - $started) * 1000), $usageMetadata);
            throw new RuntimeException('AI service returned an unsuccessful response.', $response->status());
        }

        $payload = $response->json();
        $validator = Validator::make(is_array($payload) ? $payload : [], [
            'answer' => ['required', 'string', 'max:5000'],
            'topic' => ['required', 'string', 'in:delays,mode_selection,costs,customs,general,sales_tutorial'],
            'intent' => ['sometimes', 'nullable', 'string', 'in:page_help,next_step,create_client,create_inquiry,edit_inquiry,fill_form,upload_extract_document,view_record_status,explain_workflow,inquiry_status,inquiry_approval,forecasting,quotation,calendar,availability,general'],
            'action_requested' => ['sometimes', 'boolean'],
            'intent_confidence' => ['sometimes', 'numeric', 'between:0,1'],
            'language' => ['sometimes', 'nullable', 'string', 'in:english,taglish'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'input_tokens' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'output_tokens' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'cached_input_tokens' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);
        $validator->after(function ($validator) use ($payload): void {
            if (is_array($payload) && is_string($payload['answer'] ?? null) && trim($payload['answer']) === '') {
                $validator->errors()->add('answer', 'The answer must not be blank.');
            }
        });

        if ($validator->fails()) {
            throw new RuntimeException('AI service returned an invalid response.');
        }

        $validated = $validator->validated();
        $usageMetadata['cached_input_tokens'] = $validated['cached_input_tokens'] ?? null;
        app(AiCredentialService::class)->recordUsage($user, $validated['model'] ?? (string) config('ifc_ai.model'), 'success', null, $validated['input_tokens'] ?? null, $validated['output_tokens'] ?? null, (int) round((microtime(true) - $started) * 1000), $usageMetadata);

        return $validated;
    }

    private function safeCategory(int $status, mixed $detail = null): string
    {
        if (is_string($detail) && in_array($detail, ['invalid_api_key', 'model_unavailable', 'billing_or_quota', 'rate_limited', 'service_unavailable'], true)) {
            return $detail;
        }

        return match ($status) {
            401 => 'invalid_api_key', 402 => 'billing_or_quota', 404 => 'model_unavailable', 429 => 'rate_limited', 503 => 'service_unavailable', default => 'request_failed'
        };
    }
}
