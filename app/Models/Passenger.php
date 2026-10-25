<?php

namespace App\Models;

class Passenger extends TransportModel
{
    protected $table = 'Passengers';

    protected $primaryKey = 'passenger_id';

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function feedback()
    {
        return $this->hasMany(Feedback::class, 'passenger_id', 'passenger_id');
    }
}
