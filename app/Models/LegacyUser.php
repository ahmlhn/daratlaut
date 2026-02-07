<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class LegacyUser extends Authenticatable
{
    use Notifiable;

    protected $table = 'noci_users';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'username',
        'password',
        'name',
        'fullname',
        'role',
        'status',
        'default_pop',
        'last_login',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'last_login' => 'datetime',
    ];
}
