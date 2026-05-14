<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorScheduleRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'doctor_id',
        'request_type',
        'request_date',
        'old_start_time',
        'old_end_time',
        'requested_start_time',
        'requested_end_time',
        'reason',
        'status',
        'admin_notes',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('request_date', $date);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function getRequestTypeLabelAttribute(): string
    {
        return match ($this->request_type) {
            'leave' => 'Leave',
            'temporary_timing' => 'Timing Change',
            'unavailable' => 'Unavailable',
            'break_change' => 'Break Change',
            default => $this->request_type,
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return ucfirst($this->status);
    }
}
