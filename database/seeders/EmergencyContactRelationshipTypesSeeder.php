<?php

namespace Database\Seeders;

use App\Models\EmergencyContactRelationshipType;
use Illuminate\Database\Seeder;

class EmergencyContactRelationshipTypesSeeder extends Seeder
{
    protected $table = 'emergency_contact_relationship_types';

    public function run(): void
    {
        $defaultTypes = [
            ['description' => 'Spouse'],
            ['description' => 'Father'],
            ['description' => 'Mother'],
            ['description' => 'Brother'],
            ['description' => 'Sister'],
            ['description' => 'Friend'],
        ];

        foreach ($defaultTypes as $type) {
            EmergencyContactRelationshipType::updateOrCreate(
                ['description' => $type['description']]
            );
        }
    }
}
