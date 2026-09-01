<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ResetUserPassword extends Command
{
    protected $signature = 'user:reset-password
                            {identifier : User email or NIC}
                            {password : New plain-text password}';

    protected $description = 'Reset a user password on the server (email or NIC lookup)';

    public function handle(): int
    {
        $identifier = trim((string) $this->argument('identifier'));
        $password = (string) $this->argument('password');
        $lower = strtolower($identifier);

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$lower])
            ->orWhereRaw('LOWER(nic) = ?', [$lower])
            ->first();

        if (!$user) {
            $this->error("No user found for: {$identifier}");
            return self::FAILURE;
        }

        $user->password = $password;
        $user->save();

        $this->info("Password updated for {$user->email} (id: {$user->id}, role: {$user->role})");

        return self::SUCCESS;
    }
}
