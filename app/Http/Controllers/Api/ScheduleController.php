<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\AppointmentResource;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorBaseSchedule;
use App\Models\DoctorScheduleOverride;
use App\Services\SlotGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ScheduleController extends Controller
{
    // ========== PUBLIC API: Get available slots for a specific doctor and date ==========

    public function getAvailableSlots(Request $request, int $doctorId): JsonResponse
    {
        try {
            $request->validate([
                'date' => 'required|date|after_or_equal:today',
            ]);

            $doctor = Doctor::find($doctorId);

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $slotService = new SlotGenerationService();
            $result = $slotService->getAvailableSlots($doctor, $request->date);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Get available slots failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'doctor_id' => $doctorId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch available slots: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Validate a specific slot before booking (double-check)
     */
    public function validateSlot(Request $request, int $doctorId): JsonResponse
    {
        try {
            $request->validate([
                'date' => 'required|date',
                'time' => 'required|date_format:H:i',
            ]);

            $doctor = Doctor::find($doctorId);

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $slotService = new SlotGenerationService();
            $result = $slotService->validateSlot($doctor, $request->date, $request->time);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Validate slot failed', [
                'error' => $e->getMessage(),
                'doctor_id' => $doctorId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to validate slot',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getMySchedule(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $doctor = Doctor::where('user_id', $user->id)->first();

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Get base schedules - use CASE WHEN for SQLite compatibility
            $dayOrder = [
                'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4,
                'friday' => 5, 'saturday' => 6, 'sunday' => 7,
            ];
            $baseSchedules = DoctorBaseSchedule::where('doctor_id', $doctor->id)
                ->where('is_active', true)
                ->orderByRaw("CASE day_of_week " . collect($dayOrder)->map(fn($v, $k) => "WHEN '$k' THEN $v")->join(' ') . " END")
                ->get();

            // Get upcoming overrides (leaves, etc.)
            $overrides = DoctorScheduleOverride::where('doctor_id', $doctor->id)
                ->where('date', '>=', date('Y-m-d'))
                ->where('is_active', true)
                ->orderBy('date')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'base_schedules' => $baseSchedules,
                    'overrides' => $overrides,
                    'is_available' => $doctor->is_available,
                    'consultation_fee' => $doctor->consultation_fee,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get my schedule failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch schedule',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ========== DOCTOR API: Create leave ==========

    public function createLeave(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $doctor = Doctor::where('user_id', $user->id)->first();

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $validated = $request->validate([
                'start_date' => 'required|date|after_or_equal:today',
                'end_date' => 'required|date|after_or_equal:start_date',
                'reason' => 'nullable|string|max:500',
            ]);

            // Create override for each date in range
            $start = strtotime($validated['start_date']);
            $end = strtotime($validated['end_date']);
            $createdLeaves = [];

            while ($start <= $end) {
                $date = date('Y-m-d', $start);

                // Check if override already exists for this date
                $exists = DoctorScheduleOverride::where('doctor_id', $doctor->id)
                    ->where('date', $date)
                    ->where('override_type', 'leave')
                    ->exists();

                if (!$exists) {
                    $leave = DoctorScheduleOverride::create([
                        'doctor_id' => $doctor->id,
                        'date' => $date,
                        'override_type' => 'leave',
                        'reason' => $validated['reason'],
                        'is_active' => true,
                    ]);
                    $createdLeaves[] = $leave;
                }

                $start = strtotime('+1 day', $start);
            }

            Log::info('Doctor leave created', [
                'doctor_id' => $doctor->id,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Leave marked successfully',
                'data' => $createdLeaves,
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            Log::error('Create leave failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create leave',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ========== DOCTOR API: Create custom timing override ==========

    public function createCustomTiming(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $doctor = Doctor::where('user_id', $user->id)->first();

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $validated = $request->validate([
                'date' => 'required|date|after_or_equal:today',
                'start_time' => 'required|date_format:H:i',
                'end_time' => 'required|date_format:H:i|after:start_time',
                'reason' => 'nullable|string|max:500',
            ]);

            // Check if override already exists for this date
            $existing = DoctorScheduleOverride::where('doctor_id', $doctor->id)
                ->where('date', $validated['date'])
                ->where('override_type', 'custom_timing')
                ->first();

            if ($existing) {
                $existing->update([
                    'start_time' => $validated['start_time'],
                    'end_time' => $validated['end_time'],
                    'reason' => $validated['reason'],
                ]);
                $override = $existing;
            } else {
                $override = DoctorScheduleOverride::create([
                    'doctor_id' => $doctor->id,
                    'date' => $validated['date'],
                    'override_type' => 'custom_timing',
                    'start_time' => $validated['start_time'],
                    'end_time' => $validated['end_time'],
                    'reason' => $validated['reason'],
                    'is_active' => true,
                ]);
            }

            Log::info('Custom timing created', [
                'doctor_id' => $doctor->id,
                'date' => $validated['date'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Custom timing set successfully',
                'data' => $override,
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            Log::error('Create custom timing failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to set custom timing',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ========== DOCTOR API: Delete override ==========

    public function deleteOverride(Request $request, int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $doctor = Doctor::where('user_id', $user->id)->first();

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $override = DoctorScheduleOverride::where('id', $id)
                ->where('doctor_id', $doctor->id)
                ->first();

            if (!$override) {
                return response()->json([
                    'success' => false,
                    'message' => 'Override not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $override->delete();

            return response()->json([
                'success' => true,
                'message' => 'Override deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Delete override failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete override',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ========== DOCTOR API: Toggle availability ==========

    public function toggleAvailability(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $doctor = Doctor::where('user_id', $user->id)->first();

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $doctor->update([
                'is_available' => !$doctor->is_available,
            ]);

            return response()->json([
                'success' => true,
                'message' => $doctor->is_available ? 'You are now available' : 'You are now unavailable',
                'data' => [
                    'is_available' => $doctor->is_available,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Toggle availability failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle availability',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ========== ADMIN API: Get all schedules ==========

    public function adminIndex(Request $request): JsonResponse
    {
        try {
            $query = Doctor::with(['user:id,name,email', 'baseSchedules', 'scheduleOverrides' => function ($q) {
                $q->where('date', '>=', date('Y-m-d'))->where('is_active', true);
            }]);

            // Filter by specialty
            if ($request->has('specialty') && $request->specialty) {
                $query->where('specialty', $request->specialty);
            }

            // Search by name
            if ($request->has('search') && $request->search) {
                $query->whereHas('user', function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->search . '%');
                });
            }

            $doctors = $query->get();

            return response()->json([
                'success' => true,
                'data' => $doctors,
            ]);
        } catch (\Exception $e) {
            Log::error('Admin get schedules failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch schedules',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ========== ADMIN API: Create/Update doctor base schedule ==========

    public function adminSaveSchedule(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'doctor_id' => 'required|exists:doctors,id',
                'schedules' => 'required|array',
                'schedules.*.day_of_week' => 'required|string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
                'schedules.*.start_time' => 'required|date_format:H:i',
                'schedules.*.end_time' => 'required|date_format:H:i|after:schedules.*.start_time',
                'schedules.*.slot_duration' => 'required|integer|min:10|max:120',
                'schedules.*.break_start' => 'nullable|date_format:H:i',
                'schedules.*.break_end' => 'nullable|date_format:H:i|after:schedules.*.break_start',
                'schedules.*.is_active' => 'boolean',
            ]);

            foreach ($validated['schedules'] as $scheduleData) {
                DoctorBaseSchedule::updateOrCreate(
                    [
                        'doctor_id' => $validated['doctor_id'],
                        'day_of_week' => $scheduleData['day_of_week'],
                    ],
                    [
                        'start_time' => $scheduleData['start_time'],
                        'end_time' => $scheduleData['end_time'],
                        'slot_duration' => $scheduleData['slot_duration'],
                        'break_start' => $scheduleData['break_start'] ?? null,
                        'break_end' => $scheduleData['break_end'] ?? null,
                        'is_active' => $scheduleData['is_active'] ?? true,
                    ]
                );
            }

            Log::info('Doctor base schedule saved', ['doctor_id' => $validated['doctor_id']]);

            return response()->json([
                'success' => true,
                'message' => 'Schedule saved successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Admin save schedule failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save schedule',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ========== ADMIN API: Block doctor schedule ==========

    public function adminBlockDoctor(Request $request, int $doctorId): JsonResponse
    {
        try {
            $doctor = Doctor::find($doctorId);

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $validated = $request->validate([
                'start_date' => 'required|date|after_or_equal:today',
                'end_date' => 'required|date|after_or_equal:start_date',
                'reason' => 'nullable|string|max:500',
            ]);

            // Create holiday overrides
            $start = strtotime($validated['start_date']);
            $end = strtotime($validated['end_date']);

            while ($start <= $end) {
                $date = date('Y-m-d', $start);

                DoctorScheduleOverride::updateOrCreate(
                    [
                        'doctor_id' => $doctorId,
                        'date' => $date,
                        'override_type' => 'holiday',
                    ],
                    [
                        'reason' => $validated['reason'] ?? 'Blocked by admin',
                        'is_active' => true,
                    ]
                );

                $start = strtotime('+1 day', $start);
            }

            Log::info('Doctor schedule blocked by admin', [
                'doctor_id' => $doctorId,
                'period' => $validated['start_date'] . ' to ' . $validated['end_date'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Doctor schedule blocked successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Admin block doctor failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to block schedule',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ========== ADMIN API: Mark doctor unavailable ==========

    public function adminToggleDoctorAvailability(int $doctorId): JsonResponse
    {
        try {
            $doctor = Doctor::find($doctorId);

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $doctor->update([
                'is_available' => !$doctor->is_available,
            ]);

            return response()->json([
                'success' => true,
                'message' => $doctor->is_available ? 'Doctor is now available' : 'Doctor is now unavailable',
                'data' => [
                    'is_available' => $doctor->is_available,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Admin toggle doctor availability failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle availability',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ========== ADMIN API: Delete doctor schedule ==========

    public function adminDeleteSchedule(int $doctorId, string $dayOfWeek): JsonResponse
    {
        try {
            $schedule = DoctorBaseSchedule::where('doctor_id', $doctorId)
                ->where('day_of_week', $dayOfWeek)
                ->first();

            if (!$schedule) {
                return response()->json([
                    'success' => false,
                    'message' => 'Schedule not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $schedule->delete();

            return response()->json([
                'success' => true,
                'message' => 'Schedule deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Admin delete schedule failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete schedule',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}