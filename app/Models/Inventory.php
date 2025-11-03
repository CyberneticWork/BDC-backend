<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    use SoftDeletes;

    protected $table = 'inventory';

    protected $fillable = [
        'voucherNumber',
        'unitPrice',
        'quantity',
        'amount',
        'paid_value',
        'discountValue',
        'referNumber',
        'status',
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
}
