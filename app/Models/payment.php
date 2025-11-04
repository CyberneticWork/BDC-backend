<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $table = 'payments';

    protected $fillable = [
        'inventory_id',
        'amount',
        'mode',
        'note',
        'bank_name',
        'cheque_no',
        'cheque_date',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'cheque_date' => 'date',
    ];

    public function inventory()
    {
        return $this->belongsTo(Inventory::class);
    }
}
