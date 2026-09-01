<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Models\employee;

// Get employee with email perera@gmail.com
$employee = Employee::where('email', 'perera@gmail.com')->first();

if ($employee) {
    // Get user record
    $user = User::where('email', 'perera@gmail.com')->first();
    
    if ($user) {
        // Update user NIC from employee
        $user->nic = $employee->nic;
        $user->save();
        
        echo "User updated successfully!\n";
        echo "Email: {$user->email}\n";
        echo "NIC: {$user->nic}\n";
    } else {
        echo "User record not found\n";
    }
} else {
    echo "Employee not found\n";
}
