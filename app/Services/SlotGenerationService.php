<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorBaseSchedule;
use App\Models\DoctorScheduleOverride;
use App\Models\DoctorScheduleRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SlotGenerationService
{
    private ?Doctor $doctor = null;
    private ?Carbon $date = null;
    private string $dayOfWeek = '';
    private array $generatedSlots = [];
    private array $bookedSlots = [];

    /**
     * Generate available slots for a doctor on a specific date
     */
    public function getAvailableSlots(Doctor $doctor, string $date): array
    {
        $this->reset();
        $this->doctor = $doctor;
        $this->date = Carbon::parse($date);
        $this->dayOfWeek = strtolower($this->date->format('l'));

        // Check if doctor is available at all
        if (!$doctor->is_available) {
            return [
                'slots' => [],
                'message' => 'Doctor is currently not available for appointments',
                'date' => $date,
                'day' => $this->dayOfWeek,
                'has_schedule' => false,
            ];
        }

        // Check for approved leaves/unavailable/holidays
        $leaveCheck = $this->checkDoctorUnavailable();
        if ($leaveCheck !== null) {
            return $leaveCheck;
        }

        // Get the schedule for this day (custom timing or base schedule)
        $scheduleData = $this->getScheduleData();

        if (!$scheduleData) {
            return [
                'slots' => [],
                'message' => 'Doctor is not available on this day',
                'date' => $date,
                'day' => $this->dayOfWeek,
                'has_schedule' => false,
            ];
        }

        // Generate all possible slots
        $this->generateSlots($scheduleData);

        // Get booked appointments
        $this->loadBookedAppointments();

        // Filter out booked and past slots
        $availableSlots = $this->filterAvailableSlots();

        return [
            'slots' => $availableSlots,
            'message' => count($availableSlots) > 0 ? null : 'No available slots for this date',
            'date' => $date,
            'day' => $this->dayOfWeek,
            'has_schedule' => true,
            'schedule_info' => $scheduleData,
            'booked_count' => count($this->bookedSlots),
            'total_slots' => count($this->generatedSlots),
        ];
    }

    /**
     * Validate if a specific slot is available (for double-check before booking)
     */
    public function validateSlot(Doctor $doctor, string $date, string $time): array
    {
        $this->reset();
        $this->doctor = $doctor;
        $this->date = Carbon::parse($date);
        $this->dayOfWeek = strtolower($this->date->format('l'));

        // Check basic availability
        if (!$doctor->is_available) {
            return [
                'valid' => false,
                'message' => 'Doctor is currently not available',
                'error_code' => 'DOCTOR_UNAVAILABLE',
            ];
        }

        // Check for approved leaves/unavailable/holidays
        $override = DoctorScheduleOverride::where('doctor_id', $doctor->id)
            ->where('date', $date)
            ->where('is_active', true)
            ->whereIn('override_type', ['leave', 'unavailable', 'holiday'])
            ->first();

        if ($override) {
            return [
                'valid' => false,
                'message' => $override->reason ?? 'Doctor is not available on this date',
                'error_code' => 'DOCTOR_ON_LEAVE',
            ];
        }

        // Check if date is in the past
        $today = Carbon::today();
        if ($this->date->lt($today)) {
            return [
                'valid' => false,
                'message' => 'Cannot book appointments for past dates',
                'error_code' => 'PAST_DATE',
            ];
        }

        // For today, check if time is in the past
        if ($this->date->isToday() && $time <= date('H:i')) {
            return [
                'valid' => false,
                'message' => 'Cannot book appointments in the past',
                'error_code' => 'PAST_TIME',
            ];
        }

        // Get schedule data
        $scheduleData = $this->getScheduleData();
        if (!$scheduleData) {
            return [
                'valid' => false,
                'message' => 'Doctor has no schedule for this day',
                'error_code' => 'NO_SCHEDULE',
            ];
        }

        // Check if time falls within schedule hours
        $slotDuration = $scheduleData['slot_duration'] ?? 30;
        $normalizedTime = strlen($time) === 5 ? $time . ':00' : $time;
        $slotEndTime = Carbon::parse($normalizedTime)->addMinutes($slotDuration)->format('H:i:s');
        $scheduleStart = strlen($scheduleData['start_time']) === 5 ? $scheduleData['start_time'] . ':00' : $scheduleData['start_time'];
        $scheduleEnd = strlen($scheduleData['end_time']) === 5 ? $scheduleData['end_time'] . ':00' : $scheduleData['end_time'];

        if ($normalizedTime < $scheduleStart || $slotEndTime > $scheduleEnd) {
            return [
                'valid' => false,
                'message' => 'Selected time is outside doctor\'s working hours',
                'error_code' => 'OUTSIDE_HOURS',
            ];
        }

        // Check for break time conflicts
        if ($scheduleData['break_start'] && $scheduleData['break_end']) {
            $normalizedBreakStart = strlen($scheduleData['break_start']) === 5 ? $scheduleData['break_start'] . ':00' : $scheduleData['break_start'];
            $normalizedBreakEnd = strlen($scheduleData['break_end']) === 5 ? $scheduleData['break_end'] . ':00' : $scheduleData['break_end'];
            $slotStart = strtotime($normalizedTime);
            $slotEnd = strtotime($slotEndTime);
            $breakStart = strtotime($normalizedBreakStart);
            $breakEnd = strtotime($normalizedBreakEnd);

            // Check if slot overlaps with break
            if ($slotStart < $breakEnd && $slotEnd > $breakStart) {
                return [
                    'valid' => false,
                    'message' => 'Selected time conflicts with break period',
                    'error_code' => 'BREAK_TIME',
                ];
            }
        }

        // Check if slot is already booked
        $isBooked = Appointment::where('doctor_id', $doctor->id)
            ->where('appointment_date', $date)
            ->where('appointment_time', $time)
            ->whereNotIn('status', ['cancelled', 'no-show'])
            ->exists();

        if ($isBooked) {
            return [
                'valid' => false,
                'message' => 'This time slot is already booked',
                'error_code' => 'ALREADY_BOOKED',
            ];
        }

        return [
            'valid' => true,
            'message' => 'Slot is available',
            'slot' => [
                'start' => $normalizedTime,
                'end' => $slotEndTime,
            ],
        ];
    }

    /**
     * Reset internal state
     */
    private function reset(): void
    {
        $this->doctor = null;
        $this->date = null;
        $this->dayOfWeek = '';
        $this->generatedSlots = [];
        $this->bookedSlots = [];
    }

    /**
     * Check if doctor is unavailable on this date
     */
    private function checkDoctorUnavailable(): ?array
    {
        $override = DoctorScheduleOverride::where('doctor_id', $this->doctor->id)
            ->where('date', $this->date->format('Y-m-d'))
            ->where('is_active', true)
            ->whereIn('override_type', ['leave', 'unavailable', 'holiday'])
            ->first();

        if ($override) {
            return [
                'slots' => [],
                'message' => $override->reason ?? 'Doctor is not available on this date',
                'date' => $this->date->format('Y-m-d'),
                'day' => $this->dayOfWeek,
                'has_schedule' => false,
            ];
        }

        return null;
    }

    /**
     * Get schedule data for the specific date (override or base schedule)
     */
    private function getScheduleData(): ?array
    {
        // First check for custom timing override
        $override = DoctorScheduleOverride::where('doctor_id', $this->doctor->id)
            ->where('date', $this->date->format('Y-m-d'))
            ->where('override_type', 'custom_timing')
            ->where('is_active', true)
            ->first();

        if ($override) {
            // Get break times from base schedule
            $baseSchedule = $this->getBaseSchedule();

            return [
                'type' => 'custom_timing',
                'start_time' => $override->start_time,
                'end_time' => $override->end_time,
                'slot_duration' => $baseSchedule?->slot_duration ?? 30,
                'break_start' => $baseSchedule?->break_start,
                'break_end' => $baseSchedule?->break_end,
            ];
        }

        // Check if there's an approved temporary timing request
        $timingRequest = DoctorScheduleRequest::where('doctor_id', $this->doctor->id)
            ->where('request_date', $this->date->format('Y-m-d'))
            ->where('request_type', 'temporary_timing')
            ->where('status', 'approved')
            ->first();

        if ($timingRequest) {
            $baseSchedule = $this->getBaseSchedule();

            return [
                'type' => 'temporary_timing',
                'start_time' => $timingRequest->requested_start_time,
                'end_time' => $timingRequest->requested_end_time,
                'slot_duration' => $baseSchedule?->slot_duration ?? 30,
                'break_start' => $baseSchedule?->break_start,
                'break_end' => $baseSchedule?->break_end,
            ];
        }

        // Check for approved break change request
        $breakRequest = DoctorScheduleRequest::where('doctor_id', $this->doctor->id)
            ->where('request_date', $this->date->format('Y-m-d'))
            ->where('request_type', 'break_change')
            ->where('status', 'approved')
            ->first();

        // Get base schedule
        $baseSchedule = $this->getBaseSchedule();

        if (!$baseSchedule) {
            // Fallback to legacy doctor fields
            $availableDays = $this->doctor->available_days ?? [];
            $isAvailableDay = collect($availableDays)->contains(fn($day) => strtolower($day) === $this->dayOfWeek);

            if (!$isAvailableDay) {
                return null;
            }

            return [
                'type' => 'base_legacy',
                'start_time' => $this->doctor->start_time ?? '09:00',
                'end_time' => $this->doctor->end_time ?? '17:00',
                'slot_duration' => 30,
                'break_start' => null,
                'break_end' => null,
            ];
        }

        $breakStart = $breakRequest?->requested_start_time ?? $baseSchedule->break_start;
        $breakEnd = $breakRequest?->requested_end_time ?? $baseSchedule->break_end;

        return [
            'type' => 'base_schedule',
            'start_time' => $baseSchedule->start_time,
            'end_time' => $baseSchedule->end_time,
            'slot_duration' => $baseSchedule->slot_duration ?? 30,
            'break_start' => $breakStart,
            'break_end' => $breakEnd,
        ];
    }

    /**
     * Get base schedule for the day of week
     */
    private function getBaseSchedule(): ?DoctorBaseSchedule
    {
        return DoctorBaseSchedule::where('doctor_id', $this->doctor->id)
            ->where('day_of_week', $this->dayOfWeek)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Generate all possible slots based on schedule
     */
    private function generateSlots(array $scheduleData): void
    {
        $slots = [];
        $slotDuration = $scheduleData['slot_duration'] ?? 30;
        $start = strtotime($scheduleData['start_time']);
        $end = strtotime($scheduleData['end_time']);
        $breakStart = $scheduleData['break_start'] ? strtotime($scheduleData['break_start']) : null;
        $breakEnd = $scheduleData['break_end'] ? strtotime($scheduleData['break_end']) : null;

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
                'start_formatted' => $this->formatTime(date('H:i', $start)),
                'end_formatted' => $this->formatTime(date('H:i', $slotEnd)),
            ];

            $start = $slotEnd;
        }

        $this->generatedSlots = $slots;
    }

    /**
     * Load booked appointments for the date
     */
    private function loadBookedAppointments(): void
    {
        $this->bookedSlots = Appointment::where('doctor_id', $this->doctor->id)
            ->where('appointment_date', $this->date->format('Y-m-d'))
            ->whereNotIn('status', ['cancelled', 'no-show'])
            ->pluck('appointment_time')
            ->map(fn($t) => substr($t, 0, 5))
            ->toArray();
    }

    /**
     * Filter out booked and past slots
     */
    private function filterAvailableSlots(): array
    {
        return array_values(array_filter($this->generatedSlots, function ($slot) {
            // Skip if already booked
            if (in_array($slot['start'], $this->bookedSlots)) {
                return false;
            }

            // If today, skip past times
            if ($this->date->isToday()) {
                $currentTime = date('H:i');
                if ($slot['start'] <= $currentTime) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Format time from 24h to 12h format
     */
    private function formatTime(string $time24): string
    {
        $dateTime = Carbon::createFromTimeString($time24);
        return $dateTime->format('h:i A');
    }
}