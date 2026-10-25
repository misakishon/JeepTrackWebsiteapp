<?php

namespace App\Models;

class Driver extends TransportModel
{
    protected $table = 'Drivers';

    protected $primaryKey = 'driver_id';

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function trips()
    {
        return $this->hasMany(Trip::class, 'driver_id', 'driver_id');
    }
}
