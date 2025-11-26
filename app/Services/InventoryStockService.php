<?php

namespace App\Services;

use App\Models\inventory_stock;

class InventoryStockService
{
    /**
     * Get paginated inventory stocks with related models.
     *
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getAll(int $perPage = 15)
    {
        return inventory_stock::with(['product', 'center', 'creator', 'updater'])->paginate($perPage);
    }

    /**
     * Get all inventory stocks (no pagination) with related models.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllDetails()
    {
        return inventory_stock::with(['product', 'center', 'creator', 'updater'])->get();
    }

    /**
     * Find a single inventory stock by id with relations.
     *
     * @param int $id
     * @return inventory_stock
     */
    public function find(int $id)
    {
        return inventory_stock::with(['product', 'center', 'creator', 'updater'])->findOrFail($id);
    }
}
