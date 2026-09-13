<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAclPermission extends Model
{
    protected $table = 'user_acl_permissions';

    protected $fillable = [
        'user_id',
        'module_key',
        'can_view',
        'can_add',
        'can_edit',
        'can_delete',
        'can_approve',
    ];

    protected $casts = [
        'can_view' => 'boolean',
        'can_add' => 'boolean',
        'can_edit' => 'boolean',
        'can_delete' => 'boolean',
        'can_approve' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function toActions(): array
    {
        return [
            'view' => (bool) $this->can_view,
            'add' => (bool) $this->can_add,
            'edit' => (bool) $this->can_edit,
            'delete' => (bool) $this->can_delete,
            'approve' => (bool) $this->can_approve,
        ];
    }
}
