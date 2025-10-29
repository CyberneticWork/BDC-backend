<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    // Table name matches existing migration
 
       use SoftDeletes;
    protected $fillable = [
        'supplier_name',
        'phone_number',
        'nic',
        'email',
        'address1',
        'address2',
        'credit_value',
        'credit_period',
    ];
}

