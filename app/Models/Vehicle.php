<?php

namespace App\Models;

class Vehicle extends TransportModel
{
    protected $table = 'Vehicles';

    protected $primaryKey = 'vehicle_id';

    protected $casts = ['capacity' => 'integer'];

    public function trips()
    {
        return $this->hasMany(Trip::class, 'vehicle_id', 'vehicle_id');
    }

    public function locations()
    {
        return $this->hasMany(GpsLocation::class, 'vehicle_id', 'vehicle_id');
    }
}
