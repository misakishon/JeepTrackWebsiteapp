<?php

namespace App\Models;

class Feedback extends TransportModel
{
    protected $table = 'Feedback';

    protected $primaryKey = 'feedback_id';

    protected $casts = ['rating' => 'integer'];

    public function passenger()
    {
        return $this->belongsTo(Passenger::class, 'passenger_id', 'passenger_id');
    }
}
