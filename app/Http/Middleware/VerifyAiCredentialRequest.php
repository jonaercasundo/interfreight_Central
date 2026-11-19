<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifyAiCredentialRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.ai.token');
        $timestamp = (string) $request->header('X-IFC-Timestamp', '');
        $nonce = (string) $request->header('X-IFC-Nonce', '');
        $signature = (string) $request->header('X-IFC-Signature', '');
        $body = $request->getContent();
        $path = '/api/internal/ai-credential';

        abort_if($request->method() !== 'GET' || $request->getPathInfo() !== $path || ! in_array(trim($body), ['', '[]'], true), 400, 'Invalid credential request.');
        abort_if($secret === '' || ! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > 300, 401, 'Invalid service signature.');
        abort_if(strlen($nonce) < 16 || strlen($nonce) > 100 || ! preg_match('/^[A-Za-z0-9_-]+$/', $nonce), 401, 'Invalid service signature.');

        $message = implode("\n", [$timestamp, $nonce, 'GET', $path, $body]);
        $expected = hash_hmac('sha256', $message, $secret);
        abort_unless(hash_equals($expected, $signature), 401, 'Invalid service signature.');

        if (app()->environment('production')) {
            abort_unless(parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https'
                && parse_url((string) config('services.ai.credential_url'), PHP_URL_SCHEME) === 'https', 403, 'Credential retrieval requires verified HTTPS.');
            try {
                $claimed = Cache::store('redis')->add('ifc:ai:credential-nonce:'.$nonce, true, now()->addMinutes(5));
            } catch (\Throwable) {
                abort(503, 'Credential replay protection is unavailable.');
            }
        } else {
            $claimed = Cache::add('ifc:ai:credential-nonce:'.$nonce, true, now()->addMinutes(5));
        }

        abort_unless($claimed, 401, 'Replayed service request.');

        return $next($request);
    }
}
