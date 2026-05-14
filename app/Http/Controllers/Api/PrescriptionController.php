<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PrescriptionResource;
use App\Models\Prescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PrescriptionController extends Controller
{
    /**
     * Get patient's prescriptions
     */
    public function myPrescriptions(Request $request): JsonResponse
    {
        try {
            $patient = $request->user()->patient;

            if (!$patient) {
                return response()->json([
                    'success' => false,
                    'message' => 'Patient profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $prescriptions = Prescription::with('doctor.user:id,name', 'doctor')
                ->where('patient_id', $patient->id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => PrescriptionResource::collection($prescriptions),
            ]);
        } catch (\Exception $e) {
            Log::error('Get prescriptions failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch prescriptions.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get single prescription details
     */
    public function show(int $id): JsonResponse
    {
        try {
            $prescription = Prescription::with('doctor.user', 'patient.user', 'appointment')
                ->where('id', $id)
                ->first();

            if (!$prescription) {
                return response()->json([
                    'success' => false,
                    'message' => 'Prescription not found',
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'data' => new PrescriptionResource($prescription),
            ]);
        } catch (\Exception $e) {
            Log::error('Get prescription failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch prescription details.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create prescription (Doctor only)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'appointment_id' => 'required|exists:appointments,id',
                'diagnosis' => 'nullable|string',
                'symptoms' => 'nullable|string',
                'medications' => 'required|array',
                'instructions' => 'nullable|string',
                'follow_up_date' => 'nullable|date',
            ]);

            $doctor = $request->user()->doctor;

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $appointment = \App\Models\Appointment::find($request->appointment_id);

            $prescription = Prescription::create([
                'appointment_id' => $request->appointment_id,
                'patient_id' => $appointment->patient_id,
                'doctor_id' => $doctor->id,
                'diagnosis' => $request->diagnosis,
                'symptoms' => $request->symptoms,
                'medications' => $request->medications,
                'instructions' => $request->instructions,
                'follow_up_date' => $request->follow_up_date,
            ]);

            // Update appointment status
            $appointment->update(['status' => 'completed']);

            Log::info('Prescription created', ['prescription_id' => $prescription->id]);

            return response()->json([
                'success' => true,
                'message' => 'Prescription created successfully',
                'data' => new PrescriptionResource($prescription->load('patient.user')),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            Log::error('Create prescription failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create prescription.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get doctor's prescriptions
     */
    public function doctorPrescriptions(Request $request): JsonResponse
    {
        try {
            $doctor = $request->user()->doctor;

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $prescriptions = Prescription::with('patient.user:id,name,phone', 'patient')
                ->where('doctor_id', $doctor->id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => PrescriptionResource::collection($prescriptions),
            ]);
        } catch (\Exception $e) {
            Log::error('Get doctor prescriptions failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch prescriptions.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}