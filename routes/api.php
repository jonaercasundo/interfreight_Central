<?php

use App\Http\Controllers\AdminAiUsageController;
use App\Http\Controllers\AiConfigurationController;
use App\Http\Controllers\Api\AssistantClientFormController;
use App\Http\Controllers\Api\AssistantConversationController;
use App\Http\Controllers\Api\AssistantDocumentController;
use App\Http\Controllers\Api\AssistantFormSessionController;
use App\Http\Controllers\Api\AssistantWorkflowController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ShipmentController;
use Illuminate\Support\Facades\Route;

Route::get('/internal/ai-credential', [AiConfigurationController::class, 'internalCredential'])
    ->middleware(['ai.credential.signature', 'throttle:120,1'])->name('api.internal.ai-credential');

// IFC's browser app authenticates with Laravel's same-origin web session.
// Keep these private API routes stateful and CSRF-protected instead of
// silently treating a logged-in browser as an anonymous API client.
Route::middleware(['web', 'auth', 'active', 'password.session', 'password.change'])->group(function (): void {
    Route::get('/me', fn () => response()->json(['user' => auth()->user()->only(['id', 'name', 'email', 'is_active']), 'roles' => auth()->user()->getRoleNames(), 'permissions' => auth()->user()->getAllPermissions()->pluck('name')]))->name('api.me');
    Route::get('/dashboard', DashboardController::class)->middleware('can:dashboard.view');
    Route::post('/assistant', [DashboardController::class, 'assistant'])->middleware(['can:assistant.use', 'throttle:20,1']);
    Route::get('/assistant/conversations', [AssistantConversationController::class, 'index'])->middleware(['can:assistant.use', 'throttle:30,1']);
    Route::post('/assistant/conversations', [AssistantConversationController::class, 'store'])->middleware(['can:assistant.use', 'throttle:10,1']);
    Route::get('/assistant/conversations/{conversation}', [AssistantConversationController::class, 'show'])->middleware(['can:assistant.use', 'throttle:30,1']);
    Route::patch('/assistant/conversations/{conversation}', [AssistantConversationController::class, 'rename'])->middleware(['can:assistant.use', 'throttle:10,1']);
    Route::delete('/assistant/conversations/{conversation}', [AssistantConversationController::class, 'destroy'])->middleware(['can:assistant.use', 'throttle:10,1']);
    Route::get('/assistant/documents', [AssistantDocumentController::class, 'index'])->middleware(['can:assistant.use', 'throttle:30,1']);
    Route::post('/assistant/documents', [AssistantDocumentController::class, 'store'])->middleware(['can:assistant.use', 'throttle:10,1']);
    Route::delete('/assistant/documents/{document}', [AssistantDocumentController::class, 'destroy'])->middleware(['can:assistant.use', 'throttle:30,1']);
    Route::get('/assistant/client-form-sessions/active', [AssistantClientFormController::class, 'active'])->middleware(['can:assistant.use', 'can:clients.create', 'throttle:30,1']);
    Route::post('/assistant/client-form-sessions/{session}/answer', [AssistantClientFormController::class, 'answer'])->middleware(['can:assistant.use', 'can:clients.create', 'throttle:30,1']);
    Route::put('/assistant/client-form-sessions/{session}/fields/{field}', [AssistantClientFormController::class, 'updateField'])->middleware(['can:assistant.use', 'can:clients.create', 'throttle:30,1']);
    Route::post('/assistant/client-form-sessions/{session}/save', [AssistantClientFormController::class, 'save'])->middleware(['can:assistant.use', 'can:clients.create', 'throttle:10,1']);
    Route::get('/assistant/form-sessions', [AssistantFormSessionController::class, 'index'])->middleware(['can:assistant.use', 'can:inquiries.create', 'throttle:30,1']);
    Route::post('/assistant/form-sessions', [AssistantFormSessionController::class, 'store'])->middleware(['can:assistant.use', 'can:inquiries.create', 'throttle:10,1']);
    Route::post('/assistant/form-sessions/{session}/answer', [AssistantFormSessionController::class, 'answer'])->middleware(['can:assistant.use', 'can:inquiries.create', 'throttle:30,1']);
    Route::post('/assistant/form-sessions/{session}/cancel', [AssistantFormSessionController::class, 'cancel'])->middleware(['can:assistant.use', 'can:inquiries.create', 'throttle:10,1']);
    Route::put('/assistant/form-sessions/{session}/fields/{field}', [AssistantFormSessionController::class, 'updateField'])->middleware(['can:assistant.use', 'can:inquiries.create', 'throttle:30,1']);
    Route::post('/assistant/form-sessions/{session}/draft', [AssistantFormSessionController::class, 'saveDraft'])->middleware(['can:assistant.use', 'can:inquiries.create', 'throttle:30,1']);
    Route::get('/assistant/workflows/inquiries/{inquiry}', [AssistantWorkflowController::class, 'inquiry'])->middleware(['can:assistant.use', 'can:inquiries.view', 'throttle:30,1']);
    Route::post('/assistant/workflows/inquiries/{inquiry}/submit', [AssistantWorkflowController::class, 'submitInquiry'])->middleware(['can:assistant.use', 'can:inquiries.submit', 'throttle:10,1']);
    Route::get('/assistant/tutorials', [DashboardController::class, 'tutorials'])->middleware('can:assistant.use');
    Route::get('/admin/ai-configuration', [AiConfigurationController::class, 'show'])->name('api.admin.ai-configuration');
    Route::put('/admin/ai-configuration', [AiConfigurationController::class, 'update'])->middleware('throttle:10,1')->name('api.admin.ai-configuration.update');
    Route::delete('/admin/ai-configuration/credential', [AiConfigurationController::class, 'removeCredential'])->middleware('throttle:5,1')->name('api.admin.ai-configuration.credential.remove');
    Route::post('/admin/ai-configuration/test', [AiConfigurationController::class, 'testConnection'])->middleware('throttle:5,1')->name('api.admin.ai-configuration.test');
    Route::get('/admin/ai-usage/summary', [AdminAiUsageController::class, 'summary'])->middleware('can:admin.ai-usage.view')->name('api.admin.ai-usage.summary');
    Route::get('/admin/ai-usage', [AdminAiUsageController::class, 'index'])->middleware('can:admin.ai-usage.view')->name('api.admin.ai-usage.index');
    Route::post('/admin/ai-usage/prices', [AdminAiUsageController::class, 'storePrice'])->middleware(['can:admin.ai-pricing.manage', 'throttle:10,1'])->name('api.admin.ai-usage.prices.store');
    Route::post('/admin/ai-usage/exchange-rates', [AdminAiUsageController::class, 'storeRate'])->middleware(['can:admin.ai-fx.manage', 'throttle:10,1'])->name('api.admin.ai-usage.rates.store');
    Route::get('/admin/ai-usage/exchange-rates/latest', [AdminAiUsageController::class, 'latestRate'])->middleware(['can:admin.ai-fx.manage', 'throttle:10,1'])->name('api.admin.ai-usage.rates.latest');
    Route::put('/admin/ai-usage/budget', [AdminAiUsageController::class, 'saveBudget'])->middleware(['can:admin.ai-budget.manage', 'throttle:10,1'])->name('api.admin.ai-usage.budget.update');
    Route::apiResource('shipments', ShipmentController::class)->middleware('can:shipments.view');
});
