<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class JournalEntry extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'entry_number',
        'entry_date',
        'memo',
        'account_type',
        'account_name',
        'debit',
        'credit',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];
}
