<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerType extends Model
{
   use SoftDeletes;

    protected $fillable = ['name', 'description'];

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }
}
