<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssistantConversation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssistantConversationController extends Controller
{
    public function index(Request $request)
    {
        $data = AssistantConversation::query()->where('user_id', $request->user()->id)->where('expires_at', '>', now())
            ->latest('last_activity_at')->limit(20)->get(['id', 'conversation_uuid', 'title', 'module', 'current_path', 'last_activity_at']);

        return response()->json(['data' => $data]);
    }

    public function store(Request $request)
    {
        $now = now();
        $conversation = AssistantConversation::query()->create([
            'user_id' => $request->user()->id,
            'conversation_uuid' => (string) Str::uuid(),
            'messages' => [],
            'last_activity_at' => $now,
            'expires_at' => $now->copy()->addDays(30),
        ]);

        return response()->json(['data' => ['conversation_id' => $conversation->conversation_uuid]], 201);
    }

    public function show(Request $request, string $conversation)
    {
        $record = AssistantConversation::query()->where('user_id', $request->user()->id)
            ->where('conversation_uuid', $conversation)->where('expires_at', '>', now())->firstOrFail();

        return response()->json(['data' => ['conversation_id' => $record->conversation_uuid, 'messages' => $record->messages ?? [], 'module' => $record->module, 'current_path' => $record->current_path]]);
    }

    public function destroy(Request $request, string $conversation)
    {
        $record = AssistantConversation::query()->where('user_id', $request->user()->id)->where('conversation_uuid', $conversation)->firstOrFail();
        $record->delete();

        return response()->noContent();
    }

    public function rename(Request $request, string $conversation)
    {
        $record = AssistantConversation::query()->where('user_id', $request->user()->id)
            ->where('conversation_uuid', $conversation)->where('expires_at', '>', now())->firstOrFail();
        $validated = $request->validate(['title' => ['required', 'string', 'max:120']]);
        $title = trim($validated['title']);
        if ($title === '') {
            throw ValidationException::withMessages(['title' => 'Enter a name for this conversation.']);
        }
        $record->update(['title' => $title]);

        return response()->json(['data' => ['conversation_id' => $record->conversation_uuid, 'title' => $record->title]]);
    }
}
