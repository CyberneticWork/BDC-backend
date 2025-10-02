<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class creator_roles extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $creators = [
           'Operations',
           'Digital marketing',
           'Human Resource',
           'Finance',
           'Marketing',
           'Management',
           'Senior Management'
        ];

        foreach ($creators as $creator) {
            DB::table('creator_roles')->insert([
                'role_name' => $creator,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
