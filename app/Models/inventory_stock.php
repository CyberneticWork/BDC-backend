<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class inventory_stock extends Model
{
    use SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'inventory_stocks';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'product_id',
        'center_id',
        'batch_number',
        'quantity',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'quantity' => 'integer',
    ];

    /**
     * Product relation
     *
     * @return BelongsTo
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo('App\\Models\\product', 'product_id');
    }

    /**
     * Center relation
     *
     * @return BelongsTo
     */
    public function center(): BelongsTo
    {
        return $this->belongsTo('App\\Models\\centers', 'center_id');
    }

    /**
     * Creator (user who created the record)
     *
     * @return BelongsTo
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo('App\\Models\\User', 'created_by');
    }

    /**
     * Updater (user who last updated the record)
     *
     * @return BelongsTo
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo('App\\Models\\User', 'updated_by');
    }
}
