<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class AiServiceClient
{
    public function testOpenAi(string $model): array
    {
        $base = rtrim((string) config('services.ai.url'), '/');
        $secret = (string) config('services.ai.token');
        if ($base === '' || $secret === '') {
            throw new AiConnectionFailure('configuration_missing', 'Configure the Laravel-to-FastAPI service URL and shared signing token.');
        }
        if (! filter_var($base, FILTER_VALIDATE_URL) || ! in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new AiConnectionFailure('configuration_missing', 'The Laravel-to-FastAPI service URL is invalid.');
        }
        if (app()->environment('production') && (parse_url($base, PHP_URL_SCHEME) !== 'https' || ! config('services.ai.verify_tls', true))) {
            throw new AiConnectionFailure('configuration_missing', 'The production AI service must use verified HTTPS.');
        }

        $path = '/internal/openai-test';
        $body = json_encode(['model' => $model], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $nonce = Str::random(40);
        $correlation = (string) Str::uuid();
        $signature = hash_hmac('sha256', implode("\n", [$timestamp, $nonce, 'POST', $path, $body]), $secret);
        try {
            $response = Http::acceptJson()->withOptions(['verify' => $this->tlsVerification()])
                ->connectTimeout((int) config('services.ai.connect_timeout', 3))->timeout((int) config('services.ai.timeout', 10))
                ->withHeaders(['X-IFC-Timestamp' => $timestamp, 'X-IFC-Nonce' => $nonce, 'X-IFC-Signature' => $signature, 'X-Correlation-ID' => $correlation])
                ->withBody($body, 'application/json')->post($base.$path);
        } catch (ConnectionException $exception) {
            throw new AiConnectionFailure($this->transportCategory($exception), 'The Laravel-to-FastAPI connection failed.', 503, $exception);
        }
        $result = $response->json();
        if (! $response->successful() || ! is_array($result) || ($result['status'] ?? null) !== 'connected') {
            $category = is_array($result) ? ($result['category'] ?? null) : null;
            $category = $this->safeCategory($category, $response->status());
            throw new AiConnectionFailure($category, 'The OpenAI connection test was not successful.', $response->status());
        }

        return ['status' => 'connected', 'provider' => 'openai', 'model' => $model, 'correlation_id' => $correlation,
            'input_tokens' => isset($result['input_tokens']) ? (int) $result['input_tokens'] : null,
            'cached_input_tokens' => isset($result['cached_input_tokens']) ? (int) $result['cached_input_tokens'] : null,
            'output_tokens' => isset($result['output_tokens']) ? (int) $result['output_tokens'] : null];
    }

    public function forecastShipments(array $payload): array
    {
        $base = rtrim((string) config('services.ai.url'), '/');
        $secret = (string) config('services.ai.token');
        if ($base === '' || $secret === '' || ! filter_var($base, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('AI service is not configured.');
        }
        if (app()->environment('production') && (parse_url($base, PHP_URL_SCHEME) !== 'https' || ! config('services.ai.verify_tls', true))) {
            throw new RuntimeException('AI service must use verified HTTPS in production.');
        }

        $path = '/api/v1/ai/forecast/shipments';
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $nonce = Str::random(40);
        $correlation = (string) Str::uuid();
        $signature = hash_hmac('sha256', implode("\n", [$timestamp, $nonce, 'POST', $path, $body]), $secret);
        $response = Http::acceptJson()->withOptions(['verify' => $this->tlsVerification()])
            ->connectTimeout((int) config('services.ai.connect_timeout', 3))->timeout((int) config('services.ai.timeout', 10))
            ->withHeaders(['X-IFC-Timestamp' => $timestamp, 'X-IFC-Nonce' => $nonce, 'X-IFC-Signature' => $signature, 'X-Correlation-ID' => $correlation])
            ->withBody($body, 'application/json')->post($base.$path);
        if (! $response->successful()) {
            Log::warning('Shipment forecast request failed safely.', ['status' => $response->status(), 'correlation_id' => $correlation]);
            throw new RuntimeException('The shipment forecast service did not return a valid result.', $response->status());
        }
        $result = $response->json();
        if (! is_array($result) || ! isset($result['status'], $result['model_version'], $result['forecasts']) || ! in_array($result['status'], ['ready', 'insufficient_data'], true) || ! is_array($result['forecasts'])) {
            throw new RuntimeException('The shipment forecast service returned an invalid response.');
        }
        $result['correlation_id'] = $correlation;

        return $result;
    }

    public function diagnostic(): array
    {
        $base = rtrim((string) config('services.ai.url'), '/');
        $secret = (string) config('services.ai.token');
        if ($base === '' || $secret === '') {
            throw new RuntimeException('AI service is not configured.');
        }
        if (! filter_var($base, FILTER_VALIDATE_URL) || ! in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException('AI service URL is invalid.');
        }
        if (app()->environment('production') && (parse_url($base, PHP_URL_SCHEME) !== 'https' || ! config('services.ai.verify_tls', true))) {
            throw new RuntimeException('AI service must use verified HTTPS in production.');
        }
        $body = '{}';
        $timestamp = (string) now()->timestamp;
        $nonce = Str::random(40);
        $path = '/internal/diagnostic';
        $signature = hash_hmac('sha256', implode("\n", [$timestamp, $nonce, 'POST', $path, $body]), $secret);
        $correlation = (string) Str::uuid();
        $response = Http::acceptJson()->asJson()->withOptions(['verify' => $this->tlsVerification()])->connectTimeout((int) config('services.ai.connect_timeout', 3))
            ->timeout((int) config('services.ai.timeout', 10))->withHeaders([
                'X-IFC-Timestamp' => $timestamp, 'X-IFC-Nonce' => $nonce,
                'X-IFC-Signature' => $signature, 'X-Correlation-ID' => $correlation,
            ])->withBody($body, 'application/json')->post($base.$path);
        if (! $response->successful() || $response->json('status') !== 'authenticated') {
            $shouldReport = Cache::add('ifc-ai-diagnostic-failure-'.($response->status() === 401 ? 'auth' : 'service'), true, now()->addMinute());
            if ($shouldReport) {
                Log::warning('Protected FastAPI diagnostic rejected or failed.', ['status' => $response->status(), 'correlation_id' => $correlation]);
            }
            if ($response->status() === 401 && $shouldReport) {
                app(AuditLogger::class)->record('integrations.authentication_failed', null, ['status' => 401, 'correlation_id' => $correlation]);
            }
            throw new RuntimeException('AI service diagnostic failed.', $response->status());
        }

        return ['status' => 'authenticated', 'correlation_id' => $correlation];
    }

    public function healthSnapshot(): array
    {
        $checkedAt = now()->toIso8601String();
        $base = rtrim((string) config('services.ai.url'), '/');
        $serviceStatus = 'unknown';
        $serviceSummary = 'Service address is not configured.';
        $serviceLatency = null;

        if ($base !== '' && filter_var($base, FILTER_VALIDATE_URL) && in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $started = microtime(true);
            try {
                $response = Http::acceptJson()->withOptions(['verify' => $this->tlsVerification()])
                    ->connectTimeout((int) config('services.ai.connect_timeout', 3))->timeout((int) config('services.ai.timeout', 10))
                    ->get($base.'/health');
                $serviceLatency = (int) round((microtime(true) - $started) * 1000);
                if ($response->successful() && $response->json('status') === 'ok') {
                    $serviceStatus = 'connected';
                    $serviceSummary = 'The service health endpoint responded successfully.';
                } else {
                    $serviceStatus = 'unavailable';
                    $serviceSummary = 'The service health endpoint did not report healthy.';
                }
            } catch (ConnectionException) {
                $serviceLatency = (int) round((microtime(true) - $started) * 1000);
                $serviceStatus = 'unavailable';
                $serviceSummary = 'The service did not respond. Check that its process is running.';
            } catch (RuntimeException) {
                $serviceStatus = 'unavailable';
                $serviceSummary = 'The service health check could not be completed.';
            }
        }

        $authStatus = 'unknown';
        $authSummary = 'Protected diagnostic has not been checked.';
        $authLatency = null;
        if ($base === '' || trim((string) config('services.ai.token')) === '') {
            $authStatus = 'not_configured';
            $authSummary = 'Add the service credential to Laravel configuration to enable authenticated checks.';
        } else {
            $started = microtime(true);
            try {
                $this->diagnostic();
                $authLatency = (int) round((microtime(true) - $started) * 1000);
                $authStatus = 'authenticated';
                $authSummary = 'Laravel authenticated successfully with the protected service endpoint.';
                Cache::put('ifc-ai-last-successful-diagnostic', now()->toIso8601String(), now()->addDays(30));
            } catch (ConnectionException) {
                $authLatency = (int) round((microtime(true) - $started) * 1000);
                $authStatus = 'unavailable';
                $authSummary = 'The protected service endpoint could not be reached.';
            } catch (RuntimeException $exception) {
                $authLatency = (int) round((microtime(true) - $started) * 1000);
                if ($exception->getCode() === 401) {
                    $authStatus = 'rejected';
                    $authSummary = 'The service rejected Laravel authentication. Check that both services use the same credential.';
                } else {
                    $authStatus = 'degraded';
                    $authSummary = 'The protected diagnostic returned an unsuccessful response.';
                }
            }
        }

        return [
            'checked_at' => $checkedAt,
            'last_successful_check' => Cache::get('ifc-ai-last-successful-diagnostic'),
            'application' => ['name' => 'Laravel Application', 'category' => 'Application', 'status' => 'connected', 'summary' => 'This page is being served by the IFC application.'],
            'service' => ['name' => 'Python FastAPI Service', 'category' => 'Internal service', 'status' => $serviceStatus, 'summary' => $serviceSummary, 'latency_ms' => $serviceLatency],
            'authentication' => ['name' => 'Protected API Authentication', 'category' => 'Machine-to-machine access', 'status' => $authStatus, 'summary' => $authSummary, 'latency_ms' => $authLatency],
        ];
    }

    private function tlsVerification(): bool|string
    {
        return config('services.ai.ca_bundle') ?: (bool) config('services.ai.verify_tls', true);
    }

    private function safeCategory(mixed $category, int $status): string
    {
        if (is_string($category) && in_array($category, [
            'invalid_api_key', 'model_not_found', 'model_access_denied', 'insufficient_quota',
            'rate_limited', 'ssl_certificate_error', 'connection_timeout', 'dns_error',
            'provider_unavailable', 'invalid_provider_response', 'configuration_missing',
        ], true)) {
            return $category;
        }

        return match ($status) {
            // A 401 here is Laravel-to-FastAPI authentication, not an OpenAI auth response.
            // OpenAI failures arrive as a sanitized category in the signed service response.
            401 => 'configuration_missing',
            403 => 'model_access_denied',
            404 => 'model_not_found',
            402 => 'insufficient_quota',
            408, 504 => 'connection_timeout',
            429 => 'rate_limited',
            502 => 'invalid_provider_response',
            503, 500 => 'provider_unavailable',
            default => 'invalid_provider_response',
        };
    }

    private function transportCategory(ConnectionException $exception): string
    {
        $cause = strtolower($exception->getMessage());

        return match (true) {
            str_contains($cause, 'ssl certificate'), str_contains($cause, 'certificate verify'), str_contains($cause, 'curl error 60') => 'ssl_certificate_error',
            str_contains($cause, 'curl error 28'), str_contains($cause, 'timed out'), str_contains($cause, 'timeout') => 'connection_timeout',
            str_contains($cause, 'curl error 6'), str_contains($cause, 'could not resolve'), str_contains($cause, 'getaddrinfo') => 'dns_error',
            default => 'provider_unavailable',
        };
    }
}
