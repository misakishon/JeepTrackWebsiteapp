<?php

namespace App\Models;

class GpsLocation extends TransportModel
{
    protected $table = 'GPS_Locations';

    protected $primaryKey = 'gps_id';

    protected $casts = ['latitude' => 'float', 'longitude' => 'float', 'speed' => 'float', 'timestamp' => 'datetime'];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'vehicle_id');
    }
}
