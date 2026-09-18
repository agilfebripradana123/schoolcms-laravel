<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Check all Guru users
$guruRole = \App\Models\System\Role::where('name','Guru')->first();
$users = \App\Models\System\User::where('role_id', $guruRole->id)->get();

foreach ($users as $u) {
    $directPerms = $u->permissions()->pluck('name')->toArray();
    $effective = $u->effectivePermissions();
    echo "User {$u->id} ({$u->name}, {$u->email}):" . PHP_EOL;
    echo "  Direct: " . implode(', ', $directPerms) . PHP_EOL;
    echo "  Has finalize-grades: " . (in_array('finalize-grades', $effective) ? 'YES' : 'NO') . PHP_EOL;
    echo "  Has view-scholarships: " . (in_array('view-scholarships', $effective) ? 'YES' : 'NO') . PHP_EOL;
    echo "  Has view-transactions: " . (in_array('view-transactions', $effective) ? 'YES' : 'NO') . PHP_EOL;
    echo PHP_EOL;
}
