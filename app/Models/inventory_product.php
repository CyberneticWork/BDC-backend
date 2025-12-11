<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;
use App\Models\product;
use App\Models\Inventory;

class inventory_product extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'cost',
        'quantity',
        'min_price',
        'mrp',
        'amount',
        'discount',
        'product_id',
        'inventory_id',
        'batch_number',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'cost' => 'decimal:2',
        'discount' => 'decimal:2',
        'amount' => 'decimal:2',
        'min_price' => 'decimal:2',
        'mrp' => 'decimal:2',
        'quantity' => 'integer',
        'product_id' => 'integer',
        'inventory_id' => 'integer',
        'batch_number' => 'string',
        'created_by' => 'integer',
        'deleted_at' => 'datetime',
    ];

    /**
     * Relationships
     */
    public function product()
    {
        return $this->belongsTo(product::class, 'product_id');
    }

    public function inventory()
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
