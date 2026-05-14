<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Doctor extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'specialty',
        'qualification',
        'experience_years',
        'bio',
        'image',
        'consultation_fee',
        'available_days',
        'start_time',
        'end_time',
        'is_available',
        'is_verified',
    ];

    protected function casts(): array
    {
        return [
            'available_days' => 'array',
            'is_available' => 'boolean',
            'is_verified' => 'boolean',
            'consultation_fee' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function baseSchedules(): HasMany
    {
        return $this->hasMany(DoctorBaseSchedule::class);
    }

    public function scheduleOverrides(): HasMany
    {
        return $this->hasMany(DoctorScheduleOverride::class);
    }

    public function getAverageRatingAttribute(): float
    {
        return $this->reviews()->avg('rating') ?? 0;
    }
}