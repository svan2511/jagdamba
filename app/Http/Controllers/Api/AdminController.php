<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Review;
use App\Models\User;
use App\Models\Gallery;
use App\Models\Setting;
use App\Models\DoctorScheduleOverride;
use App\Models\DoctorScheduleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\Response;

class AdminController extends Controller
{
    /**
     * Get analytics data
     */
    public function analytics(Request $request): JsonResponse
    {
        try {
            $range = $request->get('range', '6months');

            // Calculate date range
            $startDate = match($range) {
                '7days' => Carbon::now()->subDays(7),
                '30days' => Carbon::now()->subDays(30),
                '6months' => Carbon::now()->subMonths(6),
                '1year' => Carbon::now()->subYear(),
                default => Carbon::now()->subMonths(6),
            };

            // Appointments in date range
            $appointmentsInRange = Appointment::where('appointment_date', '>=', $startDate)
                ->where('appointment_date', '<=', Carbon::now())
                ->get();

            // Monthly data
            $monthlyData = [];
            $currentDate = $startDate->copy();
            while ($currentDate->lte(Carbon::now())) {
                $monthAppointments = Appointment::whereMonth('appointment_date', $currentDate->month)
                    ->whereYear('appointment_date', $currentDate->year)
                    ->count();
                $monthPatients = User::where('role', 'patient')
                    ->whereMonth('created_at', $currentDate->month)
                    ->whereYear('created_at', $currentDate->year)
                    ->count();

                $monthlyData[] = [
                    'month' => $currentDate->format('M'),
                    'patients' => $monthPatients,
                    'appointments' => $monthAppointments,
                    'revenue' => $monthAppointments * 5000, // Estimated revenue
                ];

                $currentDate->addMonth();
            }

            // Weekly data (last 7 days)
            $weeklyData = [];
            for ($i = 6; $i >= 0; $i--) {
                $day = Carbon::now()->subDays($i);
                $visits = Appointment::whereDate('appointment_date', $day)->count();
                $weeklyData[] = [
                    'day' => $day->format('Mon'),
                    'visits' => $visits,
                ];
            }

            // Department stats from doctors
            $doctors = Doctor::where('is_verified', true)->with('user:id,name')->get();
            $departmentStats = [];

            // Get date range for comparison
            $previousStartDate = match($range) {
                '7days' => Carbon::now()->subDays(14),
                '30days' => Carbon::now()->subDays(60),
                '6months' => Carbon::now()->subMonths(12),
                '1year' => Carbon::now()->subYears(2),
                default => Carbon::now()->subMonths(12),
            };

            foreach ($doctors as $doctor) {
                $deptName = $doctor->specialty ?: 'General';
                $currentPatients = Appointment::where('doctor_id', $doctor->id)
                    ->where('appointment_date', '>=', $startDate)
                    ->count();
                $previousPatients = Appointment::where('doctor_id', $doctor->id)
                    ->where('appointment_date', '>=', $previousStartDate)
                    ->where('appointment_date', '<', $startDate)
                    ->count();

                if (isset($departmentStats[$deptName])) {
                    $departmentStats[$deptName]['patients'] += $currentPatients;
                    $departmentStats[$deptName]['previous_patients'] += $previousPatients;
                } else {
                    $departmentStats[$deptName] = [
                        'name' => $deptName,
                        'patients' => $currentPatients,
                        'previous_patients' => $previousPatients,
                        'percentage' => 0,
                        'growth' => 0,
                    ];
                }
            }

            // Calculate percentages and growth
            $totalDeptPatients = array_sum(array_column($departmentStats, 'patients'));
            $totalPreviousPatients = array_sum(array_column($departmentStats, 'previous_patients'));
            foreach ($departmentStats as &$dept) {
                $dept['percentage'] = $totalDeptPatients > 0 ? round(($dept['patients'] / $totalDeptPatients) * 100) : 0;
                // Calculate real growth
                if ($dept['previous_patients'] > 0) {
                    $dept['growth'] = round((($dept['patients'] - $dept['previous_patients']) / $dept['previous_patients']) * 100);
                } elseif ($dept['patients'] > 0) {
                    $dept['growth'] = 100;
                } else {
                    $dept['growth'] = 0;
                }
            }

            // Top doctors
            $topDoctorsData = [];
            foreach ($doctors as $doctor) {
                $patientCount = Appointment::where('doctor_id', $doctor->id)->count();
                $rating = Review::where('doctor_id', $doctor->id)->avg('rating') ?: 0;

                $topDoctorsData[] = [
                    'name' => $doctor->user->name ?? 'Unknown',
                    'patients' => $patientCount,
                    'rating' => round($rating, 1),
                    'department' => $doctor->specialty ?: 'General',
                ];
            }

            usort($topDoctorsData, function($a, $b) {
                return $b['patients'] - $a['patients'];
            });

            $topDoctorsData = array_slice($topDoctorsData, 0, 5);

            // KPI calculations
            $totalPatients = User::where('role', 'patient')->where('is_active', true)->count();
            $totalAppointments = Appointment::count();
            $totalAppointmentsInRange = $appointmentsInRange->count();
            $previousRangeAppointments = Appointment::where('appointment_date', '>=', $startDate->subMonths(6))
                ->where('appointment_date', '<', $startDate)
                ->count();
            $appointmentChange = $previousRangeAppointments > 0
                ? round((($totalAppointmentsInRange - $previousRangeAppointments) / $previousRangeAppointments) * 100)
                : 100;

            $previousPatientsCount = User::where('role', 'patient')
                ->where('created_at', '<', $startDate)
                ->count();
            $patientChange = $previousPatientsCount > 0
                ? round((($totalPatients - $previousPatientsCount) / $previousPatientsCount) * 100)
                : 100;

            // Calculate avg daily visits
            $avgDailyVisits = $totalAppointmentsInRange > 0
                ? round($totalAppointmentsInRange / max($range === '7days' ? 7 : ($range === '30days' ? 30 : ($range === '1year' ? 365 : 180)), 1))
                : 0;

            // Total revenue (from completed appointments)
            $totalRevenue = Appointment::where('status', 'completed')->sum('consultation_fee') ?: ($totalAppointments * 5000);
            $revenueInRange = $appointmentsInRange->where('status', 'completed')->sum('consultation_fee') ?: ($totalAppointmentsInRange * 5000);
            $previousRevenue = Appointment::where('appointment_date', '>=', $startDate->subMonths(6))
                ->where('appointment_date', '<', $startDate)
                ->where('status', 'completed')
                ->sum('consultation_fee') ?: ($previousRangeAppointments * 5000);
            $revenueChange = $previousRevenue > 0
                ? round(((($revenueInRange - $previousRevenue) / $previousRevenue) * 100))
                : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'kpi' => [
                        'total_patients' => $totalPatients,
                        'patient_change' => $patientChange,
                        'total_appointments' => $totalAppointmentsInRange,
                        'appointment_change' => $appointmentChange,
                        'total_revenue' => $revenueInRange,
                        'revenue_change' => $revenueChange,
                        'avg_daily_visits' => $avgDailyVisits,
                        'visit_change' => $appointmentChange,
                    ],
                    'monthly_data' => $monthlyData,
                    'weekly_data' => $weeklyData,
                    'department_stats' => array_values($departmentStats),
                    'top_doctors' => $topDoctorsData,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get analytics failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch analytics data.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get dashboard statistics
     */
    public function dashboard(): JsonResponse
    {
        try {
            // Total counts
            $totalPatients = User::where('role', 'patient')->where('is_active', true)->count();
            $totalDoctors = Doctor::where('is_verified', true)->count();
            $totalAppointments = Appointment::count();
            $totalReviews = Review::count();

            // Today's appointments
            $todayAppointments = Appointment::where('appointment_date', today())
                ->count();

            // Pending appointments
            $pendingAppointments = Appointment::where('status', 'pending')->count();

            // This month stats
            $thisMonthAppointments = Appointment::whereMonth('appointment_date', now()->month)
                ->whereYear('appointment_date', now()->year)
                ->count();

            // Recent appointments
            $recentAppointments = Appointment::with('patient.user:id,name', 'doctor.user:id,name', 'doctor')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            // Recent reviews
            $recentReviews = Review::with('doctor.user:id,name', 'patient.user:id,name')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();

            // Appointment status breakdown
            $appointmentStats = [
                'pending' => Appointment::where('status', 'pending')->count(),
                'confirmed' => Appointment::where('status', 'confirmed')->count(),
                'completed' => Appointment::where('status', 'completed')->count(),
                'cancelled' => Appointment::where('status', 'cancelled')->count(),
            ];

            // ===== DOCTOR AVAILABILITY STATS =====
            $today = Carbon::now()->toDateString();

            // Fetch all overrides for today in one query (fixes N+1)
            $allOverrides = DoctorScheduleOverride::whereDate('date', $today)
                ->where('is_active', true)
                ->with('doctor.user:id,name')
                ->get();

            // Separate by type
            $doctorsOnLeave = [];
            $doctorsTimingChange = [];

            foreach ($allOverrides as $override) {
                if (in_array($override->override_type, ['leave', 'holiday', 'unavailable'])) {
                    $doctorsOnLeave[] = [
                        'id' => $override->doctor->id,
                        'name' => $override->doctor->user->name ?? 'Unknown',
                        'reason' => $override->reason,
                        'override_type' => $override->override_type,
                    ];
                } elseif ($override->override_type === 'custom_timing') {
                    $doctorsTimingChange[] = [
                        'id' => $override->doctor->id,
                        'name' => $override->doctor->user->name ?? 'Unknown',
                        'original_timing' => null,
                        'new_timing' => $override->start_time . ' - ' . $override->end_time,
                        'reason' => $override->reason,
                    ];
                }
            }

            // Pending schedule requests
            $pendingRequests = DoctorScheduleRequest::where('status', 'pending')
                ->whereDate('request_date', '>=', $today)
                ->with('doctor.user:id,name')
                ->get()
                ->map(function ($request) {
                    return [
                        'id' => $request->id,
                        'doctor_id' => $request->doctor->id,
                        'doctor_name' => $request->doctor->user->name ?? 'Unknown',
                        'type' => $request->request_type,
                        'type_label' => $request->request_type_label,
                        'date' => $request->request_date,
                        'requested_time' => $request->requested_start_time && $request->requested_end_time
                            ? $request->requested_start_time . ' - ' . $request->requested_end_time : null,
                        'reason' => $request->reason,
                    ];
                });

            $doctorsNotAvailableToday = count($doctorsOnLeave);
            $doctorsWithTimingChanges = count($doctorsTimingChange);
            $pendingRequestCount = $pendingRequests->count();

            // All doctors with availability status - using pre-fetched overrides
            $overrideByDoctorId = $allOverrides->keyBy('doctor_id');

            $allDoctorsWithStatus = Doctor::where('is_verified', true)
                ->with('user:id,name')
                ->get()
                ->map(function ($doctor) use ($overrideByDoctorId) {
                    $override = $overrideByDoctorId->get($doctor->id);

                    if ($override) {
                        if (in_array($override->override_type, ['leave', 'holiday', 'unavailable'])) {
                            return [
                                'id' => $doctor->id,
                                'name' => $doctor->user->name ?? 'Unknown',
                                'specialty' => $doctor->specialty,
                                'status' => 'unavailable',
                                'status_label' => 'On ' . ucfirst($override->override_type),
                                'details' => $override->reason,
                            ];
                        } elseif ($override->override_type === 'custom_timing') {
                            return [
                                'id' => $doctor->id,
                                'name' => $doctor->user->name ?? 'Unknown',
                                'specialty' => $doctor->specialty,
                                'status' => 'timing_changed',
                                'status_label' => 'Modified Hours',
                                'details' => $override->start_time . ' - ' . $override->end_time,
                            ];
                        }
                    }

                    return [
                        'id' => $doctor->id,
                        'name' => $doctor->user->name ?? 'Unknown',
                        'specialty' => $doctor->specialty,
                        'status' => 'available',
                        'status_label' => 'Available',
                        'details' => null,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => [
                        'total_patients' => $totalPatients,
                        'total_doctors' => $totalDoctors,
                        'total_appointments' => $totalAppointments,
                        'total_reviews' => $totalReviews,
                        'today_appointments' => $todayAppointments,
                        'pending_appointments' => $pendingAppointments,
                        'this_month_appointments' => $thisMonthAppointments,
                    ],
                    'appointment_stats' => $appointmentStats,
                    'recent_appointments' => $recentAppointments,
                    'recent_reviews' => $recentReviews,
                    'doctor_availability' => [
                        'today' => [
                            'doctors_on_leave' => $doctorsNotAvailableToday,
                            'doctors_with_timing_changes' => $doctorsWithTimingChanges,
                            'available_doctors' => $totalDoctors - $doctorsNotAvailableToday - $doctorsWithTimingChanges,
                        ],
                        'doctors_on_leave_today' => $doctorsOnLeave,
                        'doctors_timing_changed_today' => $doctorsTimingChange,
                        'pending_schedule_requests' => $pendingRequests,
                        'pending_request_count' => $pendingRequestCount,
                        'all_doctors_status' => $allDoctorsWithStatus,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get dashboard failed', [
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
     * Get all patients (Admin)
     */
    public function patients(Request $request): JsonResponse
    {
        try {
            $query = User::where('role', 'patient')
                ->with('patient')
                ->orderBy('created_at', 'desc');

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%')
                        ->orWhere('phone', 'like', '%' . $search . '%');
                });
            }

            // Filter by status (is_active)
            if ($request->has('status')) {
                if ($request->status === 'Active') {
                    $query->where('is_active', true);
                } elseif ($request->status === 'Inactive') {
                    $query->where('is_active', false);
                }
            }

            // Filter by blood group (needs join with patient table)
            if ($request->has('blood_group') && $request->blood_group !== 'All Blood Groups') {
                $query->whereHas('patient', function ($q) use ($request) {
                    $q->where('blood_type', $request->blood_group);
                });
            }

            // Filter by gender (needs join with patient table)
            if ($request->has('gender') && $request->gender !== 'All Genders') {
                $query->whereHas('patient', function ($q) use ($request) {
                    $q->where('gender', strtolower($request->gender));
                });
            }

            $patients = $query->paginate(20);

            return response()->json([
                'success' => true,
                'data' => $patients,
                'pagination' => [
                    'current_page' => $patients->currentPage(),
                    'last_page' => $patients->lastPage(),
                    'per_page' => $patients->perPage(),
                    'total' => $patients->total(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get patients failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch patients.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get patient details
     */
    public function viewPatient(int $id): JsonResponse
    {
        try {
            $user = User::where('role', 'patient')
                ->with('patient', 'patient.appointments', 'patient.prescriptions', 'patient.reports')
                ->find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Patient not found',
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'data' => $user,
            ]);
        } catch (\Exception $e) {
            Log::error('Get patient details failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch patient details.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get all doctors (Admin)
     */
    public function doctors(Request $request): JsonResponse
    {
        try {
            $query = Doctor::with('user:id,name,email,phone,is_active')
                ->orderBy('created_at', 'desc');

            if ($request->has('search')) {
                $query->whereHas('user', function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->search . '%')
                        ->orWhere('email', 'like', '%' . $request->search . '%');
                });
            }

            if ($request->has('specialty')) {
                $query->where('specialty', $request->specialty);
            }

            if ($request->has('is_available')) {
                $query->where('is_available', $request->is_available);
            }

            $doctors = $query->paginate(20);

            return response()->json([
                'success' => true,
                'data' => $doctors,
                'pagination' => [
                    'current_page' => $doctors->currentPage(),
                    'last_page' => $doctors->lastPage(),
                    'per_page' => $doctors->perPage(),
                    'total' => $doctors->total(),
                ],
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
     * Get doctor details
     */
    public function viewDoctor(int $id): JsonResponse
    {
        try {
            $doctor = Doctor::with('user', 'reviews', 'appointments')
                ->find($id);

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor not found',
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'data' => $doctor,
            ]);
        } catch (\Exception $e) {
            Log::error('Get doctor details failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch doctor details.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get all reviews (Admin)
     */
    public function reviews(Request $request): JsonResponse
    {
        try {
            $query = Review::with('doctor.user:id,name', 'patient.user:id,name')
                ->orderBy('created_at', 'desc');

            // Filter by status (is_approved: 0=Pending, 1=Published)
            if ($request->has('status')) {
                $status = $request->status;
                if ($status === 'Pending') {
                    $query->where('is_approved', false);
                } elseif ($status === 'Published') {
                    $query->where('is_approved', true);
                }
            }

            // Filter by doctor
            if ($request->has('doctor_id')) {
                $query->where('doctor_id', $request->doctor_id);
            }

            // Filter by rating
            if ($request->has('rating')) {
                $query->where('rating', $request->rating);
            }

            $reviews = $query->paginate(20);

            return response()->json([
                'success' => true,
                'data' => $reviews,
                'pagination' => [
                    'current_page' => $reviews->currentPage(),
                    'last_page' => $reviews->lastPage(),
                    'per_page' => $reviews->perPage(),
                    'total' => $reviews->total(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get reviews failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch reviews.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get specialties list
     */
    public function specialties(): JsonResponse
    {
        try {
            $specialties = Doctor::distinct()->pluck('specialty')->filter()->values();

            return response()->json([
                'success' => true,
                'data' => $specialties,
            ]);
        } catch (\Exception $e) {
            Log::error('Get specialties failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch specialties.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Toggle user status (Admin)
     */
    public function toggleUserStatus(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'is_active' => 'required|boolean',
            ]);

            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $user->update(['is_active' => $request->is_active]);

            Log::info('User status toggled', ['user_id' => $user->id, 'is_active' => $request->is_active]);

            return response()->json([
                'success' => true,
                'message' => 'User status updated',
            ]);
        } catch (\Exception $e) {
            Log::error('Toggle user status failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update user status.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get settings
     */
    public function getSettings(): JsonResponse
    {
        try {
            $settings = Setting::all()->pluck('value', 'key')->toArray();

            // Default settings if not exist
            $defaults = [
                'hospital_name' => 'MAA JAGDAMBA SUPER SPECIALITY HOSPITAL',
                'hospital_address' => '',
                'hospital_phone' => '',
                'hospital_email' => '',
                'hospital_emergency' => '',
                'timezone' => 'Asia/Kolkata',
                'date_format' => 'DD/MM/YYYY',
                'appointment_duration' => '30',
                'currency' => 'INR',
            ];

            $merged = array_merge($defaults, $settings);

            return response()->json([
                'success' => true,
                'data' => $merged,
            ]);
        } catch (\Exception $e) {
            Log::error('Get settings failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch settings.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        try {
            $settingsData = $request->all();

            foreach ($settingsData as $key => $value) {
                Setting::set($key, $value);
            }

            Log::info('Settings updated', array_keys($settingsData));

            return response()->json([
                'success' => true,
                'message' => 'Settings updated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Update settings failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update settings.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}