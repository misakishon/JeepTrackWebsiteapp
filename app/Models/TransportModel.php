<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class TransportModel extends Model
{
    public $timestamps = false;

    // Writes must explicitly select fields; imported schema has no Laravel timestamps.
    protected $guarded = ['*'];
}
