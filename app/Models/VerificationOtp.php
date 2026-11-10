<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificationOtp extends Model
{
    protected $table = 'verification_otps';

    protected $fillable = [
        'contact_type',
        'contact_destination',
        'authenticatable_type',
        'authenticatable_id',
        'otp_hash',
        'attempts',
        'expires_at',
        'verified_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
    ];
}
