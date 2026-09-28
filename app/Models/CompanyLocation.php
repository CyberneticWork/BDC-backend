<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyLocation extends Model
{
    protected $table = 'company_locations';

    protected $fillable = [
        'company_id',
        'name',
        'address',
    ];

    public function company()
    {
        return $this->belongsTo(company::class, 'company_id');
    }
}
