<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SalesWorkspaceController;
use App\Models\AssistantConversation;
use App\Models\ClientInquiry;
use App\Services\AuditLogger;
use App\Services\InquiryNextStepRecommendationEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AssistantWorkflowController extends Controller
{
    public function inquiry(Request $request, ClientInquiry $inquiry, InquiryNextStepRecommendationEngine $nextSteps)
    {
        abort_unless($request->user()->can('inquiries.view'), 403);
        abort_unless((int) $inquiry->sales_owner_id === (int) $request->user()->id, 404);
        $inquiry->load(['client:id,reference,company_name,client_type', 'owner:id,name']);

        return response()->json([
            'data' => $inquiry,
            'next_steps' => $nextSteps->recommend($request->user(), $inquiry),
        ]);
    }

    public function submitInquiry(Request $request, ClientInquiry $inquiry, SalesWorkspaceController $sales, AuditLogger $audit, InquiryNextStepRecommendationEngine $nextSteps)
    {
        $data = $request->validate([
            'confirmed' => ['required', 'accepted'],
            'conversation_uuid' => ['nullable', 'uuid'],
        ]);
        abort_unless((int) $inquiry->sales_owner_id === (int) $request->user()->id, 404);

        // Delegate to the established sales operation for its permission checks and audit entry.
        $operationRequest = Request::create('/api/sales/inquiries/'.$inquiry->id.'/submit', 'POST');
        $operationRequest->setUserResolver(fn () => $request->user());
        $sales->submitInquiry($operationRequest, $inquiry, $audit);

        $updated = ClientInquiry::query()->where('sales_owner_id', $request->user()->id)
            ->with(['client:id,reference,company_name,client_type', 'owner:id,name'])->findOrFail($inquiry->id);
        $conversationUuid = $data['conversation_uuid'] ?? null;
        $recommendation = $nextSteps->recommend($request->user(), $updated, $this->conversationLanguage($request->user()->id, $conversationUuid));
        $this->appendConversation($request->user()->id, $conversationUuid, $updated->reference ?? 'this inquiry', $recommendation['message']);
        $actionResults = [[
            'action' => 'sales_inquiry.updated',
            'entity_type' => 'sales_inquiry',
            'entity_id' => (int) $updated->id,
            'reference_number' => $updated->reference,
            'status' => $updated->status,
            'operation_status' => 'success',
            'correlation_id' => (string) Str::uuid(),
            'next_steps' => $recommendation,
        ]];

        return response()->json(['data' => $updated, 'next_steps' => $recommendation, 'action_results' => $actionResults]);
    }

    private function appendConversation(int $userId, ?string $conversationUuid, string $reference, string $assistantText): void
    {
        if (! $conversationUuid) {
            return;
        }

        $conversation = AssistantConversation::query()->where('user_id', $userId)
            ->where('conversation_uuid', $conversationUuid)->where('expires_at', '>', now())->first();
        if (! $conversation) {
            return;
        }

        $messages = $conversation->messages ?? [];
        $messages[] = ['role' => 'user', 'content' => "Confirmed: submit {$reference}.", 'created_at' => now()->toIso8601String()];
        $messages[] = ['role' => 'assistant', 'content' => $assistantText, 'created_at' => now()->toIso8601String()];
        $conversation->update(['messages' => array_slice($messages, -20), 'last_activity_at' => now(), 'expires_at' => now()->addDays(30)]);
    }

    private function conversationLanguage(int $userId, ?string $conversationUuid): string
    {
        if (! $conversationUuid) {
            return 'english';
        }
        $messages = AssistantConversation::query()->where('user_id', $userId)
            ->where('conversation_uuid', $conversationUuid)->value('messages') ?? [];
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
