<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'business_name',
        'website_name',
        'business_type',
        'service_description',
        'service_category',
        'service_sub_category',
        'service_category_custom',
        'service_sub_category_custom',
        'availability_type',
        'availability_days',
        'office_start_time',
        'office_end_time',
        'full_name',
        'email',
        'mobile_number',
        'terms_accepted',
        'status',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'terms_accepted' => 'boolean',
        'availability_days' => 'array',
    ];

    // Relationships
    public function owner()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}