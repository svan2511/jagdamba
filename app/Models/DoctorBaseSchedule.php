<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorBaseSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'doctor_id',
        'day_of_week',
        'start_time',
        'end_time',
        'slot_duration',
        'break_start',
        'break_end',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'slot_duration' => 'integer',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function generateTimeSlots(): array
    {
        $slots = [];
        $slotDuration = $this->slot_duration ?? 30;

        $start = strtotime($this->start_time);
        $end = strtotime($this->end_time);
        $breakStart = $this->break_start ? strtotime($this->break_start) : null;
        $breakEnd = $this->break_end ? strtotime($this->break_end) : null;

        while ($start + ($slotDuration * 60) <= $end) {
            $slotEnd = $start + ($slotDuration * 60);

            // Skip if slot overlaps with break
            if ($breakStart && $breakEnd) {
                if ($start < $breakEnd && $slotEnd > $breakStart) {
                    $start = $breakEnd;
                    continue;
                }
            }

            $slots[] = [
                'start' => date('H:i', $start),
                'end' => date('H:i', $slotEnd),
            ];

            $start = $slotEnd;
        }

        return $slots;
    }
}