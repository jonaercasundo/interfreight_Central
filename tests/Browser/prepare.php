<?php

use App\Models\Client;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = (string) env('DB_DATABASE');
if ($database === '' || ! str_starts_with($database, sys_get_temp_dir())) {
    throw new RuntimeException('Browser tests require the generated temporary SQLite database path.');
}
if (file_exists($database)) {
    throw new RuntimeException('Refusing to overwrite an existing browser-test database.');
}
touch($database);
Artisan::call('migrate', ['--force' => true]);
Artisan::call('db:seed', ['--class' => AccessControlSeeder::class, '--force' => true]);

$password = 'IFC-Browser-Test-Only-17!';
$admin = User::create(['name' => 'Phase 17 Test Administrator', 'email' => 'phase17-admin@example.test', 'password' => Hash::make($password), 'is_active' => true, 'must_change_password' => false]);
$admin->assignRole('Administrator');
$sales = User::create(['name' => 'Phase 17 Test Sales', 'email' => 'phase17-sales@example.test', 'password' => Hash::make($password), 'is_active' => true, 'must_change_password' => false]);
$sales->assignRole('Sales');
Client::create([
    'company_name' => 'Synthetic Location Test Client',
    'client_type' => 'FCL',
    'sales_owner_id' => $sales->id,
]);

for ($index = 1; $index <= 24; $index++) {
    $user = User::create([
        'name' => sprintf('Synthetic UAT User %02d', $index),
        'email' => sprintf('uat-user-%02d@example.test', $index),
        'password' => Hash::make($password),
        'is_active' => true,
        'must_change_password' => false,
    ]);
    $user->assignRole('Sales');
}
