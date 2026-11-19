<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdministrator extends Command
{
    protected $signature = 'ifc:admin {email?}';

    protected $description = 'Create or promote an IFC Administrator using an interactively entered password';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('This command must run in an interactive console.');

            return self::FAILURE;
        }
        if (User::role('Administrator')->where('is_active', true)->exists()) {
            $this->error('An active Administrator already exists.');

            return self::FAILURE;
        }
        app(AccessControlSeeder::class)->run();
        $email = $this->argument('email') ?: $this->ask('Administrator email');
        $name = $this->ask('Administrator name');
        $password = $this->secret('Choose a password (minimum 12 characters)');
        $confirmation = $this->secret('Confirm password');
        $validator = Validator::make(['email' => $email, 'name' => $name, 'password' => $password], [
            'email' => ['required', 'email', 'max:254', 'unique:users,email'], 'name' => ['required', 'string', 'max:120'], 'password' => ['required', 'string', 'min:12'],
        ]);
        if ($validator->fails() || ! is_string($confirmation) || ! hash_equals((string) $password, $confirmation)) {
            $this->error($validator->errors()->first() ?: 'Password confirmation did not match.');

            return self::FAILURE;
        }
        $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'is_active' => true]);
        $user->assignRole('Administrator');
        AuditLog::create(['actor_id' => null, 'action' => 'users.bootstrap_administrator_created', 'target_type' => User::class, 'target_id' => $user->id, 'metadata' => [], 'created_at' => now()]);
        $this->info('Administrator account created. Sign in at /login.');

        return self::SUCCESS;
    }
}
