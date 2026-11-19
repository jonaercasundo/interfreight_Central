<?php

namespace App\Services;

use Illuminate\Support\Str;

class AssistantIntentResolver
{
    /** @return array{intent: string, confidence: float}|null */
    public function resolve(string $message, ?string $contextPage = null): ?array
    {
        $text = Str::lower(trim($message));

        $onClientManagement = parse_url((string) $contextPage, PHP_URL_PATH) === '/sales/clients';
        $fillRequest = preg_match('/\b(fill(?:\s+up|\s+out)?|complete|populate|help\s+me\s+with)\b/u', $text)
            || preg_match('/\b(ikaw\s+na|tulungan\s+mo\s+akong|ikaw\s+mag)\b/u', $text)
            || str_contains($text, 'mag-fill');
        if ($onClientManagement && $fillRequest) {
            return ['intent' => 'CREATE_CLIENT', 'confidence' => 0.96];
        }
        if ($text === '' || $this->isInformational($text)) {
            return null;
        }

        $mentionsInquiry = preg_match('/\b(inquir(?:y|ies)|shipment request)\b/u', $text)
            || preg_match('/\b(gawa|gumawa|simulan)\b/u', $text) && str_contains($text, 'inquiry');
        $action = preg_match('/\b(create|start|begin|prepare|make|new|initiate|gawa|gumawa|simulan|tayo)\b/u', $text);
        if ($mentionsInquiry && $action) {
            return ['intent' => 'CREATE_SALES_INQUIRY', 'confidence' => 0.97];
        }

        if ((preg_match('/\b(create|add|register|new|make|gawa|gumawa|magdagdag)\b/u', $text) && preg_match('/\b(client|customer|kliyente)\b/u', $text))
            || (preg_match('/\b(create|add|register|new)\b/u', $text) && preg_match('/\b(client|customer)\b/u', $text))) {
            return ['intent' => 'CREATE_CLIENT', 'confidence' => 0.93];
        }

        return null;
    }

    public function isInformational(string $message): bool
    {
        return preg_match('/\b(how do i|how to|how does|paano (?:ako )?(?:gumawa|mag-create)|steps to|where do i|explain|tell me about|what is|what are)\b/u', Str::lower(trim($message))) === 1;
    }
}
