<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    protected $table = 'Users';

    protected $primaryKey = 'user_id';

    public $timestamps = false;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'fullname',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function getNameAttribute(): string
    {
        return $this->fullname;
    }

    public function setNameAttribute($name): void
    {
        $this->attributes['fullname'] = $name;
    }

    public function getIdAttribute(): int
    {
        return $this->user_id;
    }

    public function driver()
    {
        return $this->hasOne(Driver::class, 'user_id', 'user_id');
    }

    public function passenger()
    {
        return $this->hasOne(Passenger::class, 'user_id', 'user_id');
    }
}
