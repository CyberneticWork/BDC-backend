<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;
use App\Models\inventory_stock;

class product extends Model
{
    use SoftDeletes;

    protected $table = 'products';

    protected $fillable = [
        'barcode',
        'code',
        'cost',
        'description',
        'discount_level_id',
        'created_by',
        'is_active',
        'min_price',
        'mrp',
        'name',
        'oem_numbers',
        'product_type_id',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'min_price' => 'decimal:2',
        'mrp' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function discountLevel()
    {
        return $this->belongsTo(DiscountLevel::class, 'discount_level_id');
    }

    public function productType()
    {
        return $this->belongsTo(ProductType::class, 'product_type_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function inventoryStocks()
    {
        return $this->hasMany(inventory_stock::class, 'product_id');
    }
}
