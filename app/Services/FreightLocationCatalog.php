<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FreightLocationCatalog
{
    private const MODE_TYPES = [
        'Sea' => ['seaport', 'container_terminal'],
        'Air' => ['airport'],
        'Road' => ['city', 'logistics_hub', 'delivery_location'],
        'Rail' => ['city', 'logistics_hub', 'delivery_location'],
    ];

    public function countries(): array
    {
        return collect(config('freight_locations.countries', []))
            ->map(fn (string $name, string $code): array => ['code' => $code, 'name' => $name])
            ->values()->all();
    }

    public function search(string $mode, ?string $country, ?string $search): array
    {
        $types = self::MODE_TYPES[$mode] ?? [];
        $term = Str::lower(trim((string) $search));
        $countries = config('freight_locations.countries', []);

        return collect(config('freight_locations.locations', []))
            ->filter(fn (array $location): bool => in_array($location['type'], $types, true)
                && (! $country || $location['country'] === $country)
                && (! $term || str_contains(Str::lower($location['name'].' '.$location['code'].' '.$location['type']), $term)))
            ->unique(fn (array $location): string => $location['system'].':'.$location['code'].':'.$location['type'])
            ->take(50)
            ->map(fn (array $location): array => $this->present($location, $countries))
            ->values()->all();
    }

    public function resolve(string $id, string $mode, string $country): ?array
    {
        $types = self::MODE_TYPES[$mode] ?? [];
        foreach (config('freight_locations.locations', []) as $location) {
            if ($this->id($location) === $id && $location['country'] === $country && in_array($location['type'], $types, true)) {
                return $this->present($location, config('freight_locations.countries', []));
            }
        }

        return null;
    }

    public function normalizeSubmittedLocation(array $data, string $prefix, string $mode): array
    {
        $hasStructuredInput = collect([
            "{$prefix}_country_code", "{$prefix}_location_id", "{$prefix}_location_type",
            "{$prefix}_location_code_system", "{$prefix}_location_name", "{$prefix}_custom_details",
        ])->contains(fn (string $key): bool => array_key_exists($key, $data));

        if (! $hasStructuredInput) {
            return $data;
        }

        $country = strtoupper(trim((string) ($data["{$prefix}_country_code"] ?? '')));
        $countryName = trim((string) ($data["{$prefix}_country_name"] ?? ''));
        $locationId = trim((string) ($data["{$prefix}_location_id"] ?? ''));
        $custom = trim((string) ($data["{$prefix}_custom_details"] ?? ''));
        if (! preg_match('/^[A-Z]{2}$/', $country)) {
            throw ValidationException::withMessages(["{$prefix}_country_code" => 'Choose a country before choosing a location.']);
        }

        $knownCountry = config("freight_locations.countries.{$country}");
        if ($locationId !== '') {
            $location = $this->resolve($locationId, $mode, $country);
            if (! $location) {
                throw ValidationException::withMessages(["{$prefix}_location_id" => 'Choose a listed location that matches the country and transport mode.']);
            }
            foreach ([
                "{$prefix}_location_id" => $location['id'],
                "{$prefix}_location_type" => $location['type'],
                "{$prefix}_location_code_system" => $location['code_system'],
                "{$prefix}_location_name" => $location['name'],
                "{$prefix}_country_code" => $country,
                "{$prefix}_country_name" => $knownCountry ?: $countryName,
                "{$prefix}_custom_details" => null,
            ] as $key => $value) {
                $data[$key] = $value;
            }
            $data[$prefix] = $location['name'].', '.($knownCountry ?: $country);

            return $data;
        }

        if ($custom === '' || mb_strlen($custom) > 180 || ($knownCountry === null && $countryName === '')) {
            throw ValidationException::withMessages(["{$prefix}_custom_details" => 'Enter a location and country for a place not listed.']);
        }

        $data["{$prefix}_country_code"] = $country;
        $data["{$prefix}_country_name"] = $knownCountry ?: mb_substr($countryName, 0, 100);
        $data["{$prefix}_location_id"] = null;
        $data["{$prefix}_location_type"] = 'custom';
        $data["{$prefix}_location_code_system"] = 'custom';
        $data["{$prefix}_location_name"] = mb_substr($custom, 0, 180);
        $data["{$prefix}_custom_details"] = mb_substr($custom, 0, 180);
        $data[$prefix] = mb_substr($custom.', '.($knownCountry ?: $countryName ?: $country), 0, 180);

        return $data;
    }

    private function present(array $location, array $countries): array
    {
        $location['id'] = $this->id($location);
        $location['code_system'] = $location['system'];
        $location['country_code'] = $location['country'];
        $location['country_name'] = $countries[$location['country']] ?? $location['country'];
        unset($location['system'], $location['country']);

        return $location;
    }

    private function id(array $location): string
    {
        return $location['system'].':'.$location['code'];
    }
}
