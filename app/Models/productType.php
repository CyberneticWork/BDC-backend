<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;

class ProductType extends Model
{
    use SoftDeletes;

    protected $table = 'product_types';

    protected $fillable = [
        'type',
        'description',
        'created_by',
        'status',
    ];

    /**
     * The creator (user who created the product type)
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

