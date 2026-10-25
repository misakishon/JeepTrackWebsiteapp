<?php

namespace App\Models;

class TransportRoute extends TransportModel
{
    protected $table = 'Routes';

    protected $primaryKey = 'route_id';

    public function stops()
    {
        return $this->hasMany(Stop::class, 'route_id', 'route_id');
    }

    public function trips()
    {
        return $this->hasMany(Trip::class, 'route_id', 'route_id');
    }
}
