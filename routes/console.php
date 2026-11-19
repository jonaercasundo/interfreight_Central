<?php

use App\Models\AssistantConversation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('ifc:about', function () {
    $this->info('InterFreight Central operations platform');
});

Artisan::command('ifc:assistant-prune-conversations', function () {
    $deleted = AssistantConversation::query()->where('expires_at', '<=', now())->delete();
    $this->info("Deleted {$deleted} expired assistant conversations.");
})->purpose('Remove expired assistant conversation history');

Schedule::command('ifc:assistant-prune-conversations')->hourly();
