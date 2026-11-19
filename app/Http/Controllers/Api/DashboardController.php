<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssistantMessageRequest;
use App\Models\AiUsageEvent;
use App\Models\AssistantConversation;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AiAssistantClient;
use App\Services\AiCredentialService;
use App\Services\AiServiceClient;
use App\Services\AssistantIntentResolver;
use App\Services\AssistantWorkflowRegistry;
use App\Services\SalesTutorialAssistant;
use App\Services\TutorialKnowledgeBase;
use App\Support\AuditEventCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Throwable;

class DashboardController extends Controller
{
    public function __invoke(AiServiceClient $service)
    {
        $user = request()->user();
        $administrator = $user->hasRole('Administrator');
        $metrics = [];
        $events = $this->recentEvents($user);
        $result = ['metrics' => $metrics, 'integration' => 'unavailable', 'recent_events' => $events];

        if ($administrator) {
            $result['metrics'] = ['users' => null, 'active_users' => null, 'inactive_users' => null, 'roles' => null, 'audit_events_today' => null];
            $result['checked_at'] = now()->toIso8601String();
            $result['system_health'] = $this->systemHealth($service, $user->can('integrations.status.view'));
            $result['integration'] = $result['system_health']['authentication']['status'] ?? 'unavailable';

            try {
                if ($user->can('users.view')) {
                    $totalUsers = User::count();
                    $activeUsers = User::where('is_active', true)->count();
                    $result['metrics']['users'] = $totalUsers;
                    $result['metrics']['active_users'] = $activeUsers;
                    $result['metrics']['inactive_users'] = $totalUsers - $activeUsers;
                    $result['recent_users'] = $this->recentUsers();
                } else {
                    $result['recent_users'] = [];
                }
                if ($user->can('roles.view')) {
                    $result['metrics']['roles'] = Role::count();
                }
                if ($user->can('audit.view')) {
                    $result['metrics']['audit_events_today'] = AuditLog::whereBetween('created_at', [now()->subDay(), now()])->count();
                }
                if ($user->can('admin.ai-usage.view') && Schema::hasTable('ai_usage_events') && Schema::hasColumn('ai_usage_events', 'estimated_cost_php')) {
                    $ai = AiUsageEvent::query()->whereBetween('created_at', [now()->startOfMonth(), now()])->selectRaw('COUNT(*) as requests, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(estimated_cost_php) as estimated_cost_php, SUM(CASE WHEN estimated_cost_php IS NOT NULL THEN 1 ELSE 0 END) as costed_requests')->first();
                    $result['ai_usage'] = ['requests' => (int) ($ai->requests ?? 0), 'input_tokens' => (int) ($ai->input_tokens ?? 0), 'output_tokens' => (int) ($ai->output_tokens ?? 0), 'estimated_cost_php' => $ai->estimated_cost_php, 'costed_requests' => (int) ($ai->costed_requests ?? 0)];
                }
            } catch (Throwable $e) {
                Log::notice('Administrator dashboard database data could not be loaded.', ['exception' => get_class($e)]);
                $result['recent_users'] = [];
            }
        } else {
            try {
                $result['integration'] = $service->diagnostic()['status'];
            } catch (Throwable $e) {
                Log::notice('Protected AI service diagnostic failed.', ['exception' => get_class($e)]);
            }
        }

        return response()->json($result);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function recentEvents(User $user): Collection
    {
        if (! $user->can('audit.view')) {
            return collect();
        }

        try {
            return AuditLog::with('actor:id,name')->latest('id')->limit(8)
                ->get(['id', 'actor_id', 'action', 'target_type', 'target_id', 'created_at'])
                ->map(fn (AuditLog $event): array => [
                    'id' => $event->id,
                    'action' => $event->action,
                    'event' => AuditEventCatalog::label($event->action),
                    'category' => AuditEventCatalog::category($event->action),
                    'category_label' => AuditEventCatalog::CATEGORIES[AuditEventCatalog::category($event->action)],
                    'target' => $event->target_type ? ['type' => class_basename($event->target_type), 'id' => $event->target_id] : null,
                    'created_at' => $event->created_at?->toIso8601String(),
                    'actor' => $event->actor ? ['name' => $event->actor->name] : null,
                ]);
        } catch (Throwable $e) {
            Log::notice('Dashboard audit activity could not be loaded.', ['exception' => get_class($e)]);

            return collect();
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function recentUsers(): array
    {
        $lastLogins = AuditLog::query()->select('actor_id')->selectRaw('MAX(created_at) as last_login_at')
            ->where('action', 'auth.login_succeeded')->groupBy('actor_id');

        return User::query()->leftJoinSub($lastLogins, 'last_logins', 'last_logins.actor_id', '=', 'users.id')
            ->with('roles:id,name')->select(['users.id', 'users.name', 'users.email', 'users.is_active', 'users.created_at', 'last_logins.last_login_at'])
            ->latest('users.created_at')->limit(8)->get()->map(fn (User $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'email' => $account->email,
                'roles' => $account->roles->pluck('name')->values(),
                'is_active' => $account->is_active,
                'created_at' => $account->created_at?->toIso8601String(),
                'last_login_at' => $account->getAttribute('last_login_at') ? CarbonImmutable::parse($account->getAttribute('last_login_at'))->toIso8601String() : null,
            ])->all();
    }

    /** @return array<string, mixed> */
    private function systemHealth(AiServiceClient $service, bool $canCheckIntegrations): array
    {
        $checkedAt = now()->toIso8601String();
        try {
            DB::connection()->select('select 1');
            $database = ['status' => 'connected', 'summary' => 'The application database responded successfully.'];
        } catch (Throwable $e) {
            Log::notice('Dashboard database check failed.', ['exception' => get_class($e)]);
            $database = ['status' => 'unavailable', 'summary' => 'The application database did not respond to a read-only check.'];
        }

        if (! $canCheckIntegrations) {
            return [
                'checked_at' => $checkedAt,
                'last_successful_check' => null,
                'application' => ['status' => 'connected', 'summary' => 'This dashboard is being served by the IFC application.'],
                'database' => $database,
                'service' => ['status' => 'not_authorized', 'summary' => 'Integration diagnostics are not available to this account.'],
                'authentication' => ['status' => 'not_authorized', 'summary' => 'Integration diagnostics are not available to this account.'],
            ];
        }

        try {
            $snapshot = $service->healthSnapshot();

            return [
                'checked_at' => $snapshot['checked_at'] ?? $checkedAt,
                'last_successful_check' => $snapshot['last_successful_check'] ?? null,
                'application' => ['status' => 'connected', 'summary' => 'This dashboard is being served by the IFC application.'],
                'database' => $database,
                'service' => collect($snapshot['service'] ?? [])->only(['status', 'summary', 'latency_ms'])->all(),
                'authentication' => collect($snapshot['authentication'] ?? [])->only(['status', 'summary', 'latency_ms'])->all(),
            ];
        } catch (Throwable $e) {
            Log::notice('Dashboard protected service health check failed.', ['exception' => get_class($e)]);

            return [
                'checked_at' => $checkedAt,
                'last_successful_check' => null,
                'application' => ['status' => 'connected', 'summary' => 'This dashboard is being served by the IFC application.'],
                'database' => $database,
                'service' => ['status' => 'unavailable', 'summary' => 'The protected service check could not be completed.'],
                'authentication' => ['status' => 'unavailable', 'summary' => 'The protected authentication check could not be completed.'],
            ];
        }
    }

    public function assistant(AssistantMessageRequest $request, AiAssistantClient $assistant, SalesTutorialAssistant $salesAssistant, AiCredentialService $aiConfiguration, AssistantIntentResolver $intentResolver, AssistantWorkflowRegistry $workflows)
    {
        $input = $request->validated();
        if (! is_string($input['message'] ?? null) || trim($input['message']) === '') {
            throw ValidationException::withMessages(['message' => 'The message field must not be blank.']);
        }

        $correlationId = (string) Str::uuid();
        $conversation = null;
        $conversationHistory = [];
        if (! empty($input['conversation_id'])) {
            $conversation = AssistantConversation::query()->where('user_id', $request->user()->id)
                ->where('conversation_uuid', $input['conversation_id'])->where('expires_at', '>', now())->firstOrFail();
            $conversationHistory = array_slice($conversation->messages ?? [], -10);
        }
        $result = null;
        $resolvedIntent = $intentResolver->resolve($input['message'], $input['context_page'] ?? null);
        if (($resolvedIntent['intent'] ?? null) === 'CREATE_SALES_INQUIRY') {
            $result = $workflows->startSalesInquiry($request->user(), $conversation, $resolvedIntent['confidence']);
        } elseif (($resolvedIntent['intent'] ?? null) === 'CREATE_CLIENT') {
            $language = preg_match('/\b(?:paano|gawa|tulong|ikaw|kliyente|ilagay|sige|natin|gusto|kita|salamat|ang|para|ako|po)\b/iu', $input['message']) ? 'taglish' : 'english';
            $result = $workflows->startClientForm($request->user(), $conversation, $language, $resolvedIntent['confidence']);
        }

        if ($result === null) {
            abort_unless($aiConfiguration->enabled(), 503, 'IFC AI Assistant is disabled or not configured.');
        }
        if ($result === null && (($input['context_module'] ?? null) === 'sales' || str_starts_with($input['context_page'] ?? '', '/sales') || isset($input['inquiry_id']))) {
            $result = $salesAssistant->answer(
                $request->user(), $input['message'], $input['context_page'] ?? null,
                isset($input['inquiry_id']) ? (int) $input['inquiry_id'] : null, $conversationHistory
            );
        } elseif ($result === null) {
            try {
                $context = ['module' => $input['context_module'] ?? 'dashboard', 'conversation_history' => $conversationHistory];
                $result = $assistant->ask($input['message'], $correlationId, $context, $request->user(), ['request_type' => 'assistant', 'module' => $input['context_module'] ?? 'dashboard']);
            } catch (ConnectionException $e) {
                Log::warning('AI service connection failed.', ['exception' => ConnectionException::class, 'correlation_id' => $correlationId]);

                return response()->json(['detail' => 'AI service is temporarily unavailable. Open Tutorials for verified static guidance.'], 503)->header('X-Correlation-ID', $correlationId);
            } catch (Throwable $e) {
                Log::warning('AI service request failed.', ['exception' => get_class($e), 'correlation_id' => $correlationId]);

                return response()->json(['detail' => 'AI service is temporarily unavailable. Open Tutorials for verified static guidance.'], 503)->header('X-Correlation-ID', $correlationId);
            }
        }

        if (! $intentResolver->isInformational($input['message']) && ($result['action_requested'] ?? false)
            && in_array($result['intent'] ?? null, ['create_inquiry', 'CREATE_SALES_INQUIRY'], true) && ! isset($result['workflow_session'])) {
            $result = $workflows->startSalesInquiry($request->user(), $conversation, (float) ($result['intent_confidence'] ?? $result['confidence'] ?? 0.8));
        }
        if (! $intentResolver->isInformational($input['message']) && ($result['action_requested'] ?? false)
            && in_array($result['intent'] ?? null, ['create_client', 'CREATE_CLIENT'], true) && ! isset($result['workflow_session'])) {
            $language = in_array($result['language'] ?? null, ['taglish', 'filipino'], true) ? $result['language'] : 'english';
            $result = $workflows->startClientForm($request->user(), $conversation, $language, (float) ($result['intent_confidence'] ?? $result['confidence'] ?? 0.8));
        }

        if ($conversation) {
            $messages = [...$conversationHistory,
                ['role' => 'user', 'content' => trim($input['message']), 'created_at' => now()->toIso8601String()],
                ['role' => 'assistant', 'content' => (string) ($result['answer'] ?? ''), 'created_at' => now()->toIso8601String()],
            ];
            $conversation->update(['messages' => array_slice($messages, -20), 'module' => $input['context_module'] ?? $conversation->module,
                'current_path' => $input['context_page'] ?? $conversation->current_path,
                'title' => $conversation->title ?: Str::limit(trim($input['message']), 70, ''),
                'last_activity_at' => now(), 'expires_at' => now()->addDays(30)]);
            $result['conversation_id'] = $conversation->conversation_uuid;
            $result['live_ai'] = ! (bool) ($result['degraded'] ?? false);
        }


        return response()->json($result)->header('X-Correlation-ID', $correlationId);
    }

    public function tutorials(Request $request, TutorialKnowledgeBase $knowledgeBase)
    {
        $request->validate(['path' => ['nullable', 'string', 'max:120']]);

        return response()->json($knowledgeBase->forUser($request->user(), $request->query('path')));
    }
}
