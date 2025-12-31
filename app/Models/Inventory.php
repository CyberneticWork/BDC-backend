<?php

namespace App\Models;

use App\Models\User;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\centers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\inventory_product;

class Inventory extends Model
{
    use SoftDeletes;

    protected $table = 'inventory';

    protected $fillable = [
        'voucherNumber',
        'amount',
        'paid_value',
        'discountValue',
        'discountLevel_id',
        'referNumber',
        'refervoucherNumber',
        'is_ref',
        'is_confirmed',
        'status',
        'center_id',
        'supplier_id',
        'customer_id',
        'from_center',
        'to_center',
        'created_by',
        'approved_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(centers::class, 'center_id');
    }

    /**
     * Payments made against this inventory/GRN.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'inventory_id');
    }

    /**
     * Most recent payment record (shortcut).
     */
    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class, 'inventory_id')->latestOfMany();
    }

    /**
     * Line items associated with this inventory record (GRN lines).
     */
    public function items(): HasMany
    {
        return $this->hasMany(inventory_product::class, 'inventory_id');
    }
}
