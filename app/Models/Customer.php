<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'city',
        'customer_type_id',
        'customer_category_id',

    ];

    public function customerType()
    {
        return $this->belongsTo(CustomerType::class);
    }

    public function customerCategory()
    {
        return $this->belongsTo(CustomerCategory::class);
    }
}
