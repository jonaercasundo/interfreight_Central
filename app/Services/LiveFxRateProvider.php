<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LiveFxRateProvider
{
    /** Fetches the latest published daily reference rate, not a trading quote. */
    public function usdToPhp(): array
    {
        return Cache::remember('ifc.fx.reference.usd_php', now()->addMinutes(15), function (): array {
            $url = 'https://api.frankfurter.dev/v2/rate/USD/PHP';

            try {
                $response = Http::acceptJson()->connectTimeout(3)->timeout(8)->get($url);
            } catch (\Throwable $exception) {
                throw new RuntimeException('The exchange-rate provider could not be reached.', previous: $exception);
            }

            if (! $response->successful()) {
                throw new RuntimeException('The exchange-rate provider returned an unavailable response.');
            }

            $data = $response->json();
            $date = is_array($data) ? ($data['date'] ?? null) : null;
            $rate = is_array($data) ? ($data['rate'] ?? null) : null;

            if (strtoupper((string) ($data['base'] ?? '')) !== 'USD'
                || strtoupper((string) ($data['quote'] ?? '')) !== 'PHP'
                || ! is_numeric($rate) || (float) $rate <= 0 || ! is_string($date)) {
                throw new RuntimeException('The exchange-rate provider returned invalid USD/PHP data.');
            }

            try {
                $publishedAt = Carbon::createFromFormat('!Y-m-d', $date);
            } catch (\Throwable $exception) {
                throw new RuntimeException('The exchange-rate provider returned an invalid publication date.', previous: $exception);
            }

            if (! $publishedAt || $publishedAt->isFuture()) {
                throw new RuntimeException('The exchange-rate provider returned an invalid publication date.');
            }

            return [
                'base' => 'USD',
                'quote' => 'PHP',
                'rate' => number_format((float) $rate, 10, '.', ''),
                'date' => $publishedAt->toDateString(),
                'source' => 'Frankfurter daily reference rate',
                'source_url' => 'https://api.frankfurter.dev/v2/rate/USD/PHP',
                'fetched_at' => now()->toIso8601String(),
            ];
        });
    }
}
