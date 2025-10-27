<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\User;

class DiscountLevel extends Model
{
	protected $table = 'discount_levels';

  
    
	protected $fillable = [
		'name',
		'date',
		'days',
		'description',
		'value',
		'created_by',
	];

	protected $casts = [
		'date' => 'date',
		'value' => 'decimal:2',
	];

	public function creator()
	{
		return $this->belongsTo(User::class, 'created_by');
	}
}

