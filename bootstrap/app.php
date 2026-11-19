<?php

use App\Console\Commands\CreateAdministrator;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\RequireSecureRequest;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ValidatePasswordSession;
use App\Http\Middleware\VerifyAiCredentialRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['active' => EnsureActiveAccount::class, 'password.change' => RequirePasswordChange::class, 'secure.password' => RequireSecureRequest::class, 'password.session' => ValidatePasswordSession::class, 'ai.credential.signature' => VerifyAiCredentialRequest::class]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/dashboard');
        $middleware->append(SecurityHeaders::class);
    })
    ->withCommands([CreateAdministrator::class])
    ->withExceptions(function (Exceptions $exceptions): void {})
    ->create();
