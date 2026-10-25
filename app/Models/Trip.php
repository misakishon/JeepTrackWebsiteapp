<?php

namespace App\Models;

class Trip extends TransportModel
{
    protected $table = 'Trips';

    protected $primaryKey = 'trip_id';

    protected $casts = ['start_time' => 'datetime', 'end_time' => 'datetime'];

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'driver_id', 'driver_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'vehicle_id');
    }

    public function route()
    {
        return $this->belongsTo(TransportRoute::class, 'route_id', 'route_id');
    }
}
