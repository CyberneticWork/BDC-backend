<?php

namespace App\Services;

use App\Models\EmergencyContactRelationshipType;
use Illuminate\Support\Collection;

class EmergencyContactRelationshipTypesService
{
    /**
     * Get all relationship types.
     */
    public function getAllTypes(): Collection
    {
        return EmergencyContactRelationshipType::all();
    }

    /**
     * Create a new relationship type.
     */
    public function createType(array $data): EmergencyContactRelationshipType
    {
        return EmergencyContactRelationshipType::create([
            'description' => $data['description']
        ]);
    }

    /**
     * Find a specific relationship type by ID.
     */
    public function getTypeById(int $id): EmergencyContactRelationshipType
    {
        return EmergencyContactRelationshipType::findOrFaile($id);
    }

    /**
     * Update an existing relationship type.
     */
    public function updateType(int $id, array $data): EmergencyContactRelationshipType
    {
        $type = $this->getTypeById($id);

        $type->update([
            'description' => $data['description']
        ]);

        return $type;
    }

    /**
     * Delete an existing relationship type
     */
    public function deleteType(int $id): bool
    {
        $type = $this->getTypeById($id);
        return $type->delete();
    }
}
