<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BookAppointmentRequest;
use App\Http\Resources\Api\AppointmentResource;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Notification;
use App\Services\SlotGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AppointmentController extends Controller
{
    /**
     * Get patient's appointments
     */
    public function myAppointments(Request $request): JsonResponse
    {
        try {
            $patient = $request->user()->patient;

            if (!$patient) {
                return response()->json([
                    'success' => false,
                    'message' => 'Patient profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $query = Appointment::with('doctor.user:id,name', 'doctor')
                ->where('patient_id', $patient->id)
                ->orderBy('appointment_date', 'desc')
                ->orderBy('appointment_time', 'desc');

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter upcoming/past
            if ($request->has('type')) {
                if ($request->type === 'upcoming') {
                    $query->where('appointment_date', '>=', now()->toDateString())
                        ->whereIn('status', ['pending', 'confirmed']);
                } elseif ($request->type === 'past') {
                    $query->where('appointment_date', '<', now()->toDateString())
                        ->whereIn('status', ['completed', 'cancelled']);
                }
            }

            $appointments = $query->get();

            return response()->json([
                'success' => true,
                'data' => AppointmentResource::collection($appointments),
            ]);
        } catch (\Exception $e) {
            Log::error('Get appointments failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch appointments.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get single appointment details
     */
    public function show(int $id): JsonResponse
    {
        try {
            $appointment = Appointment::with('doctor.user', 'patient.user', 'prescriptions')
                ->where('id', $id)
                ->first();

            if (!$appointment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Appointment not found',
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'data' => new AppointmentResource($appointment),
            ]);
        } catch (\Exception $e) {
            Log::error('Get appointment failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch appointment details.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Book a new appointment
     * Uses database transaction and slot validation to prevent race conditions
     */
    public function store(BookAppointmentRequest $request): JsonResponse
    {
        try {
            $patient = $request->user()->patient;

            if (!$patient) {
                return response()->json([
                    'success' => false,
                    'message' => 'Patient profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $doctor = Doctor::find($request->doctor_id);

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Use database transaction to prevent race conditions
            return DB::transaction(function () use ($request, $patient, $doctor) {
                // Use SlotGenerationService to validate the slot comprehensively
                $slotService = new SlotGenerationService();
                $validation = $slotService->validateSlot(
                    $doctor,
                    $request->appointment_date,
                    $request->appointment_time
                );

                if (!$validation['valid']) {
                    return response()->json([
                        'success' => false,
                        'message' => $validation['message'],
                        'error_code' => $validation['error_code'] ?? 'VALIDATION_FAILED',
                    ], Response::HTTP_CONFLICT);
                }

                // Double-check slot availability within transaction ( pessimistic locking )
                $exists = Appointment::where('doctor_id', $request->doctor_id)
                    ->where('appointment_date', $request->appointment_date)
                    ->where('appointment_time', $request->appointment_time)
                    ->whereNotIn('status', ['cancelled', 'no-show'])
                    ->lockForUpdate()
                    ->exists();

                if ($exists) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This time slot was just booked by someone else. Please select another slot.',
                        'error_code' => 'SLOT_JUST_BOOKED',
                    ], Response::HTTP_CONFLICT);
                }

                $appointment = Appointment::create([
                    'patient_id' => $patient->id,
                    'doctor_id' => $request->doctor_id,
                    'appointment_date' => $request->appointment_date,
                    'appointment_time' => $request->appointment_time,
                    'type' => $request->type,
                    'status' => 'pending',
                    'reason' => $request->reason,
                ]);

                // Notify doctor
                Notification::create([
                    'user_id' => $doctor->user_id,
                    'title' => 'New Appointment Request',
                    'message' => "You have a new appointment request from {$request->user()->name} for {$request->appointment_date} at {$request->appointment_time}",
                    'type' => 'appointment',
                ]);

                Log::info('Appointment booked', [
                    'appointment_id' => $appointment->id,
                    'patient_id' => $patient->id,
                    'doctor_id' => $doctor->id,
                    'date' => $request->appointment_date,
                    'time' => $request->appointment_time,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Appointment booked successfully! Your request is pending doctor confirmation.',
                    'data' => new AppointmentResource($appointment->load('doctor.user')),
                ], Response::HTTP_CREATED);
            });
        } catch (\Exception $e) {
            Log::error('Book appointment failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to book appointment: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Cancel appointment
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        try {
            $appointment = Appointment::where('id', $id)
                ->whereHas('patient', function ($query) {
                    $query->where('user_id', auth()->id());
                })
                ->first();

            if (!$appointment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Appointment not found',
                ], Response::HTTP_NOT_FOUND);
            }

            if ($appointment->status === 'cancelled') {
                return response()->json([
                    'success' => false,
                    'message' => 'Appointment is already cancelled',
                ], Response::HTTP_CONFLICT);
            }

            if ($appointment->status === 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot cancel completed appointment',
                ], Response::HTTP_CONFLICT);
            }

            $appointment->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $request->reason ?? 'Cancelled by patient',
            ]);

            Log::info('Appointment cancelled', ['appointment_id' => $appointment->id]);

            return response()->json([
                'success' => true,
                'message' => 'Appointment cancelled successfully',
                'data' => new AppointmentResource($appointment),
            ]);
        } catch (\Exception $e) {
            Log::error('Cancel appointment failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel appointment.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get doctor's appointments (Doctor panel)
     */
    public function doctorAppointments(Request $request): JsonResponse
    {
        try {
            $doctor = $request->user()->doctor;

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $query = Appointment::with('patient.user:id,name,phone', 'patient')
                ->where('doctor_id', $doctor->id)
                ->orderBy('appointment_date', 'desc')
                ->orderBy('appointment_time', 'desc');

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter date
            if ($request->has('date')) {
                $query->where('appointment_date', $request->date);
            }

            $appointments = $query->get();

            return response()->json([
                'success' => true,
                'data' => AppointmentResource::collection($appointments),
            ]);
        } catch (\Exception $e) {
            Log::error('Get doctor appointments failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch appointments.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get doctor's patients (unique patients from appointments)
     */
    public function doctorPatients(Request $request): JsonResponse
    {
        try {
            $doctor = $request->user()->doctor;

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Get unique patients from appointments
            $appointments = Appointment::with('patient.user:id,name,email,phone', 'patient')
                ->where('doctor_id', $doctor->id)
                ->whereNotNull('patient_id')
                ->orderBy('appointment_date', 'desc')
                ->get();

            // Group by patient
            $patientsMap = [];
            foreach ($appointments as $apt) {
                if ($apt->patient && !isset($patientsMap[$apt->patient_id])) {
                    $patientsMap[$apt->patient_id] = [
                        'id' => $apt->patient->id,
                        'name' => $apt->patient->user->name ?? 'Unknown',
                        'email' => $apt->patient->user->email ?? '',
                        'phone' => $apt->patient->user->phone ?? '',
                        'gender' => $apt->patient->gender ?? '',
                        'date_of_birth' => $apt->patient->date_of_birth ?? '',
                        'blood_type' => $apt->patient->blood_type ?? '',
                        'address' => $apt->patient->address ?? '',
                        'total_visits' => 0,
                        'last_visit' => null,
                        'last_reason' => $apt->reason,
                    ];
                }
                if (isset($patientsMap[$apt->patient_id])) {
                    $patientsMap[$apt->patient_id]['total_visits']++;
                    if (!$patientsMap[$apt->patient_id]['last_visit'] || $apt->appointment_date > $patientsMap[$apt->patient_id]['last_visit']) {
                        $patientsMap[$apt->patient_id]['last_visit'] = $apt->appointment_date;
                        $patientsMap[$apt->patient_id]['last_reason'] = $apt->reason;
                    }
                }
            }

            $patients = array_values($patientsMap);

            return response()->json([
                'success' => true,
                'data' => $patients,
            ]);
        } catch (\Exception $e) {
            Log::error('Get doctor patients failed', [
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
     * Update appointment status (Doctor/Admin)
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'status' => 'required|in:confirmed,completed,cancelled,no-show',
            ]);

            $appointment = Appointment::find($id);

            if (!$appointment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Appointment not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $appointment->update(['status' => $request->status]);

            // Notify patient
            Notification::create([
                'user_id' => $appointment->patient->user_id,
                'title' => 'Appointment Updated',
                'message' => "Your appointment with Dr. {$appointment->doctor->user->name} has been {$request->status}",
                'type' => 'appointment',
            ]);

            Log::info('Appointment status updated', [
                'appointment_id' => $appointment->id,
                'status' => $request->status,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Appointment status updated',
                'data' => new AppointmentResource($appointment),
            ]);
        } catch (\Exception $e) {
            Log::error('Update appointment status failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update appointment status.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get all appointments (Admin)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Appointment::with('patient.user:id,name,email,phone', 'doctor.user:id,name', 'doctor');

            // Filter by status
            if ($request->has('status') && $request->status !== 'All Status') {
                $query->where('status', strtolower($request->status));
            }

            // Filter by date
            if ($request->has('appointment_date')) {
                $query->whereDate('appointment_date', $request->appointment_date);
            }

            // Filter by doctor
            if ($request->has('doctor_id')) {
                $query->where('doctor_id', $request->doctor_id);
            }

            // Filter by patient
            if ($request->has('patient_id')) {
                $query->where('patient_id', $request->patient_id);
            }

            // Filter by date range
            if ($request->has('from_date')) {
                $query->where('appointment_date', '>=', $request->from_date);
            }
            if ($request->has('to_date')) {
                $query->where('appointment_date', '<=', $request->to_date);
            }

            $appointments = $query->orderBy('appointment_date', 'desc')
                ->orderBy('appointment_time', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data' => AppointmentResource::collection($appointments),
                'pagination' => [
                    'current_page' => $appointments->currentPage(),
                    'last_page' => $appointments->lastPage(),
                    'per_page' => $appointments->perPage(),
                    'total' => $appointments->total(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get all appointments failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch appointments.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get specific patient details (Doctor)
     */
    public function doctorPatientDetails(Request $request, int $id): JsonResponse
    {
        try {
            $doctor = $request->user()->doctor;

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $patient = \App\Models\Patient::with('user:id,name,email,phone')
                ->where('id', $id)
                ->first();

            if (!$patient) {
                return response()->json([
                    'success' => false,
                    'message' => 'Patient not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Get total visits to this doctor
            $totalVisits = Appointment::where('doctor_id', $doctor->id)
                ->where('patient_id', $id)
                ->count();

            // Get last visit
            $lastAppointment = Appointment::where('doctor_id', $doctor->id)
                ->where('patient_id', $id)
                ->orderBy('appointment_date', 'desc')
                ->orderBy('appointment_time', 'desc')
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $patient->id,
                    'name' => $patient->user->name ?? '',
                    'email' => $patient->user->email ?? '',
                    'phone' => $patient->user->phone ?? '',
                    'gender' => $patient->gender ?? '',
                    'date_of_birth' => $patient->date_of_birth ?? '',
                    'blood_type' => $patient->blood_type ?? '',
                    'address' => $patient->address ?? '',
                    'total_visits' => $totalVisits,
                    'last_visit' => $lastAppointment ? $lastAppointment->appointment_date : null,
                    'last_reason' => $lastAppointment ? $lastAppointment->reason : null,
                    'emergency_contact_name' => $patient->emergency_contact_name,
                    'emergency_contact_phone' => $patient->emergency_contact_phone,
                    'emergency_contact_relation' => $patient->emergency_contact_relation,
                    'allergies' => $patient->allergies,
                    'medical_history' => $patient->medical_history,
                ],
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
     * Get patient medical history (Doctor)
     */
    public function patientMedicalHistory(Request $request, int $id): JsonResponse
    {
        try {
            $doctor = $request->user()->doctor;

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $patient = \App\Models\Patient::with('user:id,name,email,phone')
                ->where('id', $id)
                ->first();

            if (!$patient) {
                return response()->json([
                    'success' => false,
                    'message' => 'Patient not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Get patient's appointments with this doctor
            $appointments = Appointment::with('doctor.user:id,name')
                ->where('doctor_id', $doctor->id)
                ->where('patient_id', $id)
                ->orderBy('appointment_date', 'desc')
                ->get();

            // Get patient's prescriptions from this doctor
            $prescriptions = \App\Models\Prescription::where('doctor_id', $doctor->id)
                ->where('patient_id', $id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'patient' => [
                        'id' => $patient->id,
                        'name' => $patient->user->name ?? '',
                        'email' => $patient->user->email ?? '',
                        'phone' => $patient->user->phone ?? '',
                        'gender' => $patient->gender ?? '',
                        'date_of_birth' => $patient->date_of_birth ?? '',
                        'blood_type' => $patient->blood_type ?? '',
                        'address' => $patient->address ?? '',
                    ],
                    'medical_info' => [
                        'medical_history' => $patient->medical_history,
                        'allergies' => $patient->allergies,
                        'emergency_contact' => [
                            'name' => $patient->emergency_contact_name,
                            'phone' => $patient->emergency_contact_phone,
                            'relation' => $patient->emergency_contact_relation,
                        ],
                    ],
                    'appointments' => $appointments->map(function ($apt) {
                        return [
                            'id' => $apt->id,
                            'date' => $apt->appointment_date,
                            'time' => $apt->appointment_time,
                            'reason' => $apt->reason,
                            'status' => $apt->status,
                        ];
                    }),
                    'prescriptions' => $prescriptions->map(function ($rx) {
                        return [
                            'id' => $rx->id,
                            'date' => $rx->created_at,
                            'diagnosis' => $rx->diagnosis,
                            'symptoms' => $rx->symptoms,
                            'medications' => $rx->medications,
                            'instructions' => $rx->instructions,
                        ];
                    }),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get patient medical history failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch medical history.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}