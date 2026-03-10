<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

// Reset password for employee with email perera@gmail.com
$user = User::where('email', 'perera@gmail.com')->first();

if ($user) {
    $user->password = Hash::make('931245678V');
    $user->save();
    echo "Password updated successfully for {$user->email}\n";
    echo "Email: {$user->email}\n";
    echo "NIC: {$user->nic}\n";
    echo "New Password: 931245678V\n";
} else {
    echo "User not found with email: perera@gmail.com\n";
}
