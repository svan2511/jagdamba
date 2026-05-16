<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateDoctorRequest;
use App\Http\Requests\Api\UpdateDoctorRequest;
use App\Http\Resources\Api\AppointmentResource;
use App\Http\Resources\Api\DoctorResource;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Prescription;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DoctorController extends Controller
{
    /**
     * Get doctor dashboard data
     */
    public function dashboard(): JsonResponse
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

            // Stats
            $todayDate = now()->toDateString();
            $totalAppointments = Appointment::where('doctor_id', $doctor->id)->count();
            $todayAppointments = Appointment::where('doctor_id', $doctor->id)
                ->whereDate('appointment_date', $todayDate)
                ->count();
            $pendingAppointments = Appointment::where('doctor_id', $doctor->id)
                ->where('status', 'pending')
                ->count();
            $completedAppointments = Appointment::where('doctor_id', $doctor->id)
                ->where('status', 'completed')
                ->count();
            $thisMonthAppointments = Appointment::where('doctor_id', $doctor->id)
                ->whereMonth('appointment_date', now()->month)
                ->whereYear('appointment_date', now()->year)
                ->count();

            // Get unique patients
            $totalPatients = Appointment::where('doctor_id', $doctor->id)
                ->distinct('patient_id')
                ->count('patient_id');

            // Get prescriptions count
            $totalPrescriptions = Prescription::where('doctor_id', $doctor->id)->count();

            // Get average rating
            $averageRating = Review::where('doctor_id', $doctor->id)->avg('rating') ?: 0;

            // Today's appointments (for dashboard list)
            $todayAppointmentsList = AppointmentResource::collection(
                Appointment::with('patient.user:id,name,phone', 'patient')
                    ->where('doctor_id', $doctor->id)
                    ->whereDate('appointment_date', $todayDate)
                    ->orderBy('appointment_time', 'asc')
                    ->get()
            );

            // Recent appointments - get today's first, then others
            $recentAppointments = Appointment::with('patient.user:id,name', 'patient:id')
                ->where('doctor_id', $doctor->id)
                ->where('appointment_date', '>=', $todayDate)
                ->orderBy('appointment_date', 'asc')
                ->orderBy('appointment_time', 'asc')
                ->limit(20)
                ->get();

            // Recent prescriptions
            $recentPrescriptions = Prescription::with('patient.user:id,name', 'appointment')
                ->where('doctor_id', $doctor->id)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'doctor' => new DoctorResource($doctor->load('user')),
                    'stats' => [
                        'total_appointments' => $totalAppointments,
                        'today_appointments' => $todayAppointments,
                        'pending_appointments' => $pendingAppointments,
                        'completed_appointments' => $completedAppointments,
                        'total_patients' => $totalPatients,
                        'total_prescriptions' => $totalPrescriptions,
                        'average_rating' => round($averageRating, 1),
                        'this_month_appointments' => $thisMonthAppointments,
                    ],
                    'recent_appointments' => $recentAppointments,
                    'today_appointments' => $todayAppointmentsList,
                    'recent_prescriptions' => $recentPrescriptions,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Doctor dashboard failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard data.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get doctor profile for logged in doctor
     */
    public function doctorProfile(): JsonResponse
    {
        try {
            $user = auth()->user();
            $doctor = Doctor::with(['user:id,name,email,phone', 'baseSchedules' => function ($query) {
                $query->where('is_active', true)->orderBy('day_of_week', 'asc');
            }])->where('user_id', $user->id)->first();

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Define day order for sorting
            $dayOrder = ['monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7];

            // Extract available days with timing from base schedules
            $scheduleData = [];
            $schedules = $doctor->baseSchedules->toArray();

            // Sort by day order
            usort($schedules, function ($a, $b) use ($dayOrder) {
                return ($dayOrder[$a['day_of_week']] ?? 8) - ($dayOrder[$b['day_of_week']] ?? 8);
            });

            foreach ($schedules as $schedule) {
                $dayName = ucfirst($schedule['day_of_week']);
                $scheduleData[] = [
                    'day' => $dayName,
                    'day_key' => $schedule['day_of_week'],
                    'start_time' => $schedule['start_time'],
                    'end_time' => $schedule['end_time'],
                    'slot_duration' => $schedule['slot_duration'],
                    'break_start' => $schedule['break_start'],
                    'break_end' => $schedule['break_end'],
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $doctor->id,
                    'specialty' => $doctor->specialty,
                    'qualification' => $doctor->qualification,
                    'experience_years' => $doctor->experience_years,
                    'bio' => $doctor->bio,
                    'image' => $doctor->image ? url($doctor->image) : null,
                    'consultation_fee' => $doctor->consultation_fee,
                    'is_available' => $doctor->is_available,
                    'is_verified' => $doctor->is_verified,
                    'average_rating' => round($doctor->average_rating, 1),
                    'created_at' => $doctor->created_at->format('Y-m-d H:i:s'),
                    'user' => [
                        'id' => $doctor->user->id,
                        'name' => $doctor->user->name,
                        'email' => $doctor->user->email,
                        'phone' => $doctor->user->phone,
                        'is_active' => $doctor->user->is_active,
                    ],
                    'schedule' => $scheduleData,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get doctor profile failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch doctor profile.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update doctor profile
     */
    public function updateDoctorProfile(Request $request): JsonResponse
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

            Log::info('Update profile request', [
                'post_keys' => array_keys($request->post()),
                'files' => $_FILES ?? [],
            ]);

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|unique:users,email,' . $user->id,
                'phone' => 'sometimes|string|max:20',
                'specialty' => 'sometimes|string|max:255',
                'qualification' => 'sometimes|string|max:255',
                'experience_years' => 'sometimes|integer|min:0',
                'bio' => 'sometimes|string',
                'consultation_fee' => 'sometimes|numeric|min:0',
            ]);

            $doctorData = [];

            // Handle image upload - check $_FILES directly as fallback
            $imageFile = $request->file('image');
            if (!$imageFile && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $imageFile = $request->file('image');
            }

            if ($imageFile) {
                $imageName = 'doctor_' . $doctor->id . '_' . time() . '.' . $imageFile->getClientOriginalExtension();
                $destinationPath = public_path('uploads/doctors');

                // Ensure directory exists
                if (!file_exists($destinationPath)) {
                    mkdir($destinationPath, 0755, true);
                }

                $imageFile->move($destinationPath, $imageName);
                $doctorData['image'] = '/uploads/doctors/' . $imageName;

                Log::info('Image uploaded', ['path' => $doctorData['image']]);
            }

            // Update user data
            if (isset($validated['name']) || isset($validated['email']) || isset($validated['phone'])) {
                $userData = [];
                if (isset($validated['name'])) $userData['name'] = $validated['name'];
                if (isset($validated['email'])) $userData['email'] = $validated['email'];
                if (isset($validated['phone'])) $userData['phone'] = $validated['phone'];
                $doctor->user->update($userData);
            }

            // Update doctor data
            $doctorFields = ['specialty', 'qualification', 'experience_years', 'bio', 'consultation_fee'];
            foreach ($doctorFields as $field) {
                if (isset($validated[$field])) {
                    $doctorData[$field] = $validated[$field];
                }
            }
            if (!empty($doctorData)) {
                $doctor->update($doctorData);
            }

            // Refresh to get updated values
            $doctor->refresh();

            Log::info('Doctor profile updated', ['doctor_id' => $doctor->id, 'image' => $doctor->image, 'has_image' => !empty($doctorData['image'])]);

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'image' => $doctor->image ? url($doctor->image) : null,
                    'doctor' => new DoctorResource($doctor->load('user')),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Update doctor profile failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * List all doctors (public)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Doctor::with('user:id,name,email,phone')
                ->where('is_verified', true)
                ->where('is_available', true);

            // Filter by specialty
            if ($request->has('specialty')) {
                $query->where('specialty', 'like', '%' . $request->specialty . '%');
            }

            // Search by name
            if ($request->has('search')) {
                $query->whereHas('user', function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->search . '%');
                });
            }

            $doctors = $query->orderBy('experience_years', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => DoctorResource::collection($doctors),
            ]);
        } catch (\Exception $e) {
            Log::error('Get doctors failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch doctors.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get single doctor details
     */
    public function show(int $id): JsonResponse
    {
        try {
            $doctor = Doctor::with('user:id,name,email,phone', 'reviews.patient.user:id,name')
                ->where('id', $id)
                ->where('is_verified', true)
                ->first();

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor not found',
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'data' => new DoctorResource($doctor),
            ]);
        } catch (\Exception $e) {
            Log::error('Get doctor failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'doctor_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch doctor details.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create new doctor (Admin only)
     */
    public function adminIndex(Request $request): JsonResponse
    {
        try {
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);

            $query = Doctor::with('user:id,name,email,phone');

            // Search by name
            if ($request->has('search')) {
                $query->whereHas('user', function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->search . '%');
                });
            }

            // Filter by specialty
            if ($request->has('specialty')) {
                $query->where('specialty', 'like', '%' . $request->specialty . '%');
            }

            $doctors = $query->orderBy('id', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => DoctorResource::collection($doctors),
                'meta' => [
                    'current_page' => $doctors->currentPage(),
                    'last_page' => $doctors->lastPage(),
                    'total' => $doctors->total(),
                    'per_page' => $doctors->perPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Admin get doctors failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch doctors.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create new doctor (Admin only)
     */
    public function store(CreateDoctorRequest $request): JsonResponse
    {
        try {
            // Create user
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'role' => 'doctor',
            ]);

            Log::info('password for doctor' . $request->password );

            // Create doctor profile
            $doctor = Doctor::create([
                'user_id' => $user->id,
                'specialty' => $request->specialty,
                'qualification' => $request->qualification,
                'experience_years' => $request->experience_years,
                'bio' => $request->bio,
                'image' => $request->image,
                'consultation_fee' => $request->consultation_fee,
                'available_days' => $request->available_days,
                'start_time' => $request->start_time,
                'end_time' => $request->end_time,
                'is_verified' => true,
            ]);

            Log::info('Doctor created', ['doctor_id' => $doctor->id, 'user_id' => $user->id]);

            return response()->json([
                'success' => true,
                'message' => 'Doctor created successfully',
                'data' => new DoctorResource($doctor->load('user')),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            Log::error('Create doctor failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create doctor.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update doctor (Admin or Doctor themselves)
     */
    public function update(UpdateDoctorRequest $request, int $id): JsonResponse
    {
        try {
            $doctor = Doctor::find($id);

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Update doctor profile
            $doctor->update($request->validated());

            // Update user data
            if ($request->has('name') || $request->has('phone')) {
                $userData = [];
                if ($request->has('name')) {
                    $userData['name'] = $request->name;
                }
                if ($request->has('phone')) {
                    $userData['phone'] = $request->phone;
                }
                $doctor->user->update($userData);
            }

            Log::info('Doctor updated', ['doctor_id' => $doctor->id]);

            return response()->json([
                'success' => true,
                'message' => 'Doctor updated successfully',
                'data' => new DoctorResource($doctor->load('user')),
            ]);
        } catch (\Exception $e) {
            Log::error('Update doctor failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'doctor_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update doctor.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete doctor (Admin only)
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $doctor = Doctor::find($id);

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Soft delete - deactivate user
            $doctor->user->update(['is_active' => false]);
            $doctor->update(['is_available' => false]);

            Log::info('Doctor deactivated', ['doctor_id' => $doctor->id]);

            return response()->json([
                'success' => true,
                'message' => 'Doctor deactivated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Delete doctor failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'doctor_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate doctor.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get doctor availability/schedule
     */
    public function schedule(int $id): JsonResponse
    {
        try {
            $doctor = Doctor::find($id);

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor not found',
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'available_days' => $doctor->available_days,
                    'start_time' => $doctor->start_time,
                    'end_time' => $doctor->end_time,
                    'is_available' => $doctor->is_available,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get doctor schedule failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch doctor schedule.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get doctor's own schedule (authenticated)
     */
    public function mySchedule(Request $request): JsonResponse
    {
        try {
            $doctor = $request->user()->doctor;

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'available_days' => $doctor->available_days ?? [],
                    'start_time' => $doctor->start_time ?? '09:00',
                    'end_time' => $doctor->end_time ?? '17:00',
                    'is_available' => $doctor->is_available ?? true,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get my schedule failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch schedule.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update doctor's own schedule
     */
    public function updateSchedule(Request $request): JsonResponse
    {
        try {
            $doctor = $request->user()->doctor;

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $validated = $request->validate([
                'available_days' => 'sometimes|array',
                'available_days.*' => 'string',
                'start_time' => 'sometimes|date_format:H:i',
                'end_time' => 'sometimes|date_format:H:i|after:start_time',
                'is_available' => 'sometimes|boolean',
            ]);

            // Update fields individually to handle JSON encoding properly
            if (isset($validated['available_days'])) {
                $doctor->available_days = $validated['available_days'];
            }
            if (isset($validated['start_time'])) {
                $doctor->start_time = $validated['start_time'];
            }
            if (isset($validated['end_time'])) {
                $doctor->end_time = $validated['end_time'];
            }
            if (isset($validated['is_available'])) {
                $doctor->is_available = $validated['is_available'];
            }
            $doctor->save();

            Log::info('Doctor schedule updated', ['doctor_id' => $doctor->id]);

            return response()->json([
                'success' => true,
                'message' => 'Schedule updated successfully',
                'data' => [
                    'available_days' => $doctor->available_days,
                    'start_time' => $doctor->start_time,
                    'end_time' => $doctor->end_time,
                    'is_available' => $doctor->is_available,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Update schedule failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update schedule.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}