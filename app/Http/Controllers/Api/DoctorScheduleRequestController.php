<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\DoctorScheduleOverride;
use App\Models\DoctorScheduleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class DoctorScheduleRequestController extends Controller
{
    /**
     * Get all schedule requests for admin
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = DoctorScheduleRequest::with(['doctor.user', 'approver'])
                ->orderBy('created_at', 'desc');

            // Filter by status
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            // Filter by request type
            if ($request->has('request_type') && $request->request_type) {
                $query->where('request_type', $request->request_type);
            }

            // Filter by doctor
            if ($request->has('doctor_id') && $request->doctor_id) {
                $query->where('doctor_id', $request->doctor_id);
            }

            // Filter by date range
            if ($request->has('from_date') && $request->from_date) {
                $query->where('request_date', '>=', $request->from_date);
            }
            if ($request->has('to_date') && $request->to_date) {
                $query->where('request_date', '<=', $request->to_date);
            }

            // Search by doctor name
            if ($request->has('search') && $request->search) {
                $query->whereHas('doctor.user', function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->search . '%');
                });
            }

            // Get counts for dashboard
            $stats = [
                'pending' => DoctorScheduleRequest::where('status', 'pending')->count(),
                'approved' => DoctorScheduleRequest::where('status', 'approved')->count(),
                'rejected' => DoctorScheduleRequest::where('status', 'rejected')->count(),
                'today' => DoctorScheduleRequest::where('request_date', today())->count(),
            ];

            $perPage = $request->input('per_page', 15);
            $requests = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $requests->items(),
                'meta' => [
                    'current_page' => $requests->currentPage(),
                    'last_page' => $requests->lastPage(),
                    'per_page' => $requests->perPage(),
                    'total' => $requests->total(),
                ],
                'stats' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error('Get schedule requests failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch schedule requests',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get single schedule request details
     */
    public function show(int $id): JsonResponse
    {
        try {
            $request = DoctorScheduleRequest::with(['doctor.user', 'approver'])->find($id);

            if (!$request) {
                return response()->json([
                    'success' => false,
                    'message' => 'Schedule request not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Get doctor's current schedule for context
            $doctorSchedule = null;
            if ($request->request_type === 'temporary_timing' || $request->request_type === 'break_change') {
                $dayOfWeek = strtolower(date('l', strtotime($request->request_date)));
                $doctorSchedule = Doctor::find($request->doctor_id)
                    ->baseSchedules()
                    ->where('day_of_week', $dayOfWeek)
                    ->first();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'request' => $request,
                    'doctor_schedule' => $doctorSchedule,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get schedule request failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch schedule request',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Approve a schedule request
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        DB::beginTransaction();
        try {
            $scheduleRequest = DoctorScheduleRequest::with('doctor')->find($id);

            if (!$scheduleRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'Schedule request not found',
                ], Response::HTTP_NOT_FOUND);
            }

            if (!$scheduleRequest->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This request has already been processed',
                ], Response::HTTP_BAD_REQUEST);
            }

            $adminId = $request->user()->id;
            $doctorId = $scheduleRequest->doctor_id;
            $requestDate = $scheduleRequest->request_date;
            $overrideType = $this->mapRequestTypeToOverrideType($scheduleRequest->request_type);

            // Create or update the override based on request type
            if ($scheduleRequest->request_type === 'leave' || $scheduleRequest->request_type === 'unavailable') {
                DoctorScheduleOverride::updateOrCreate(
                    [
                        'doctor_id' => $doctorId,
                        'date' => $requestDate,
                        'override_type' => $overrideType,
                    ],
                    [
                        'reason' => $scheduleRequest->reason,
                        'is_active' => true,
                    ]
                );
            } elseif ($scheduleRequest->request_type === 'temporary_timing') {
                DoctorScheduleOverride::updateOrCreate(
                    [
                        'doctor_id' => $doctorId,
                        'date' => $requestDate,
                        'override_type' => 'custom_timing',
                    ],
                    [
                        'start_time' => $scheduleRequest->requested_start_time,
                        'end_time' => $scheduleRequest->requested_end_time,
                        'reason' => $scheduleRequest->reason,
                        'is_active' => true,
                    ]
                );
            } elseif ($scheduleRequest->request_type === 'break_change') {
                // Update the base schedule with new break times
                $dayOfWeek = strtolower(date('l', strtotime($requestDate)));
                $baseSchedule = Doctor::find($doctorId)
                    ->baseSchedules()
                    ->where('day_of_week', $dayOfWeek)
                    ->first();

                if ($baseSchedule) {
                    $baseSchedule->update([
                        'break_start' => $scheduleRequest->requested_start_time,
                        'break_end' => $scheduleRequest->requested_end_time,
                    ]);
                }
            }

            // Update the request status
            $scheduleRequest->update([
                'status' => 'approved',
                'admin_notes' => $validated['admin_notes'] ?? null,
                'approved_by' => $adminId,
                'approved_at' => now(),
            ]);

            DB::commit();

            Log::info('Schedule request approved', [
                'request_id' => $id,
                'doctor_id' => $doctorId,
                'request_type' => $scheduleRequest->request_type,
                'request_date' => $requestDate,
                'admin_id' => $adminId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Schedule request approved successfully',
                'data' => $scheduleRequest->fresh(['doctor.user', 'approver']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Approve schedule request failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve request',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Reject a schedule request
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        try {
            $scheduleRequest = DoctorScheduleRequest::find($id);

            if (!$scheduleRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'Schedule request not found',
                ], Response::HTTP_NOT_FOUND);
            }

            if (!$scheduleRequest->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This request has already been processed',
                ], Response::HTTP_BAD_REQUEST);
            }

            $scheduleRequest->update([
                'status' => 'rejected',
                'admin_notes' => $validated['admin_notes'] ?? null,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            Log::info('Schedule request rejected', [
                'request_id' => $id,
                'doctor_id' => $scheduleRequest->doctor_id,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Schedule request rejected',
                'data' => $scheduleRequest->fresh(['doctor.user', 'approver']),
            ]);
        } catch (\Exception $e) {
            Log::error('Reject schedule request failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject request',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update a schedule request (admin edit)
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'requested_start_time' => 'nullable|required_if:request_type,temporary_timing,break_change|date_format:H:i',
            'requested_end_time' => 'nullable|required_if:request_type,temporary_timing,break_change|date_format:H:i|after:requested_start_time',
            'reason' => 'nullable|string|max:500',
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        try {
            $scheduleRequest = DoctorScheduleRequest::find($id);

            if (!$scheduleRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'Schedule request not found',
                ], Response::HTTP_NOT_FOUND);
            }

            if (!$scheduleRequest->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot edit a processed request',
                ], Response::HTTP_BAD_REQUEST);
            }

            $scheduleRequest->update($validated);

            Log::info('Schedule request updated by admin', [
                'request_id' => $id,
                'updated_by' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Schedule request updated successfully',
                'data' => $scheduleRequest->fresh(['doctor.user', 'approver']),
            ]);
        } catch (\Exception $e) {
            Log::error('Update schedule request failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update request',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a schedule request (only pending requests)
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $scheduleRequest = DoctorScheduleRequest::find($id);

            if (!$scheduleRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'Schedule request not found',
                ], Response::HTTP_NOT_FOUND);
            }

            if (!$scheduleRequest->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete a processed request',
                ], Response::HTTP_BAD_REQUEST);
            }

            $scheduleRequest->delete();

            Log::info('Schedule request deleted', ['request_id' => $id]);

            return response()->json([
                'success' => true,
                'message' => 'Schedule request deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Delete schedule request failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete request',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get request statistics
     */
    public function stats(): JsonResponse
    {
        try {
            $stats = [
                'pending' => DoctorScheduleRequest::where('status', 'pending')->count(),
                'approved' => DoctorScheduleRequest::where('status', 'approved')->count(),
                'rejected' => DoctorScheduleRequest::where('status', 'rejected')->count(),
                'total' => DoctorScheduleRequest::count(),
                'today_pending' => DoctorScheduleRequest::where('status', 'pending')
                    ->where('request_date', today())
                    ->count(),
                'this_week' => DoctorScheduleRequest::whereBetween('request_date', [
                    now()->startOfWeek(),
                    now()->endOfWeek(),
                ])->count(),
                'this_month' => DoctorScheduleRequest::whereBetween('request_date', [
                    now()->startOfMonth(),
                    now()->endOfMonth(),
                ])->count(),
            ];

            // Breakdown by type
            $byType = DoctorScheduleRequest::select('request_type')
                ->selectRaw('COUNT(*) as count')
                ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending")
                ->selectRaw("SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved")
                ->selectRaw("SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected")
                ->groupBy('request_type')
                ->get()
                ->keyBy('request_type');

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => $stats,
                    'by_type' => $byType,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get schedule request stats failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get all doctors for dropdown (used in admin filtering)
     */
    public function doctors(): JsonResponse
    {
        try {
            $doctors = Doctor::with('user:id,name')
                ->orderBy('specialty')
                ->get(['id', 'user_id', 'specialty']);

            return response()->json([
                'success' => true,
                'data' => $doctors,
            ]);
        } catch (\Exception $e) {
            Log::error('Get doctors for filter failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch doctors',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Map request type to override type
     */
    private function mapRequestTypeToOverrideType(string $requestType): string
    {
        return match ($requestType) {
            'leave' => 'leave',
            'unavailable' => 'unavailable',
            'temporary_timing' => 'custom_timing',
            'break_change' => 'custom_timing',
            default => 'unavailable',
        };
    }

    // ========== DOCTOR API: Get my requests ==========

    public function myRequests(Request $request): JsonResponse
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

            $query = DoctorScheduleRequest::with(['approver'])
                ->where('doctor_id', $doctor->id)
                ->orderBy('created_at', 'desc');

            // Filter by status
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            // Filter by request type
            if ($request->has('request_type') && $request->request_type) {
                $query->where('request_type', $request->request_type);
            }

            // Get counts
            $stats = [
                'pending' => DoctorScheduleRequest::where('doctor_id', $doctor->id)->where('status', 'pending')->count(),
                'approved' => DoctorScheduleRequest::where('doctor_id', $doctor->id)->where('status', 'approved')->count(),
                'rejected' => DoctorScheduleRequest::where('doctor_id', $doctor->id)->where('status', 'rejected')->count(),
            ];

            $perPage = $request->input('per_page', 10);
            $requests = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $requests->items(),
                'meta' => [
                    'current_page' => $requests->currentPage(),
                    'last_page' => $requests->lastPage(),
                    'per_page' => $requests->perPage(),
                    'total' => $requests->total(),
                ],
                'stats' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error('Get my requests failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch requests',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ========== DOCTOR API: Create request ==========

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'request_type' => 'required|string|in:leave,temporary_timing,unavailable,break_change',
            'request_date' => 'required|date|after_or_equal:today',
            'old_start_time' => 'nullable|date_format:H:i',
            'old_end_time' => 'nullable|date_format:H:i',
            'requested_start_time' => 'nullable|required_if:request_type,temporary_timing,break_change|date_format:H:i',
            'requested_end_time' => 'nullable|required_if:request_type,temporary_timing,break_change|date_format:H:i|after:requested_start_time',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $user = auth()->user();
            $doctor = Doctor::where('user_id', $user->id)->first();

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Check for duplicate pending request
            $existing = DoctorScheduleRequest::where('doctor_id', $doctor->id)
                ->where('request_type', $validated['request_type'])
                ->where('request_date', $validated['request_date'])
                ->where('status', 'pending')
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have a pending request for this date and type',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate break ranges
            if (in_array($validated['request_type'], ['temporary_timing', 'break_change'])) {
                if (!empty($validated['requested_start_time']) && !empty($validated['requested_end_time'])) {
                    $reqStart = strtotime($validated['requested_start_time']);
                    $reqEnd = strtotime($validated['requested_end_time']);
                    if ($reqEnd - $reqStart < 1800) { // Less than 30 minutes
                        return response()->json([
                            'success' => false,
                            'message' => 'Requested time must be at least 30 minutes',
                        ], Response::HTTP_BAD_REQUEST);
                    }
                }
            }

            $scheduleRequest = DoctorScheduleRequest::create([
                'doctor_id' => $doctor->id,
                'request_type' => $validated['request_type'],
                'request_date' => $validated['request_date'],
                'old_start_time' => $validated['old_start_time'] ?? null,
                'old_end_time' => $validated['old_end_time'] ?? null,
                'requested_start_time' => $validated['requested_start_time'] ?? null,
                'requested_end_time' => $validated['requested_end_time'] ?? null,
                'reason' => $validated['reason'] ?? null,
                'status' => 'pending',
            ]);

            Log::info('Schedule request created by doctor', [
                'request_id' => $scheduleRequest->id,
                'doctor_id' => $doctor->id,
                'request_type' => $validated['request_type'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Request submitted successfully. Pending admin approval.',
                'data' => $scheduleRequest,
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            Log::error('Create request failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit request',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ========== DOCTOR API: Cancel request ==========

    public function cancel(int $id): JsonResponse
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

            $scheduleRequest = DoctorScheduleRequest::where('id', $id)
                ->where('doctor_id', $doctor->id)
                ->first();

            if (!$scheduleRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'Request not found',
                ], Response::HTTP_NOT_FOUND);
            }

            if (!$scheduleRequest->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending requests can be cancelled',
                ], Response::HTTP_BAD_REQUEST);
            }

            $scheduleRequest->delete();

            Log::info('Schedule request cancelled by doctor', [
                'request_id' => $id,
                'doctor_id' => $doctor->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Request cancelled successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Cancel request failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel request',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
