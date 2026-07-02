<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class VerificationProfile extends Model
{
    protected $table = 'verification_profiles';

    protected $fillable = [
        'authenticatable_type',
        'authenticatable_id',
        'status',
        'current_step',
        'verification_data',
        'document_type',
        'document_path',
        'submitted_at',
        'verified_at',
    ];

    protected $casts = [
        'verification_data' => 'array',
        'submitted_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    /**
     * Get the owning authenticatable model.
     */
    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }
}
