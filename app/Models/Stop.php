<?php

namespace App\Models;

class Stop extends TransportModel
{
    protected $table = 'Stops';

    protected $primaryKey = 'stop_id';

    protected $casts = ['latitude' => 'float', 'longitude' => 'float'];

    public function route()
    {
        return $this->belongsTo(TransportRoute::class, 'route_id', 'route_id');
    }
}
