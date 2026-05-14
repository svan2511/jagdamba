<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdatePatientProfileRequest;
use App\Http\Resources\Api\PatientResource;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PatientController extends Controller
{
    /**
     * Get current patient's profile
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $patient = $request->user()->patient;

            if (!$patient) {
                return response()->json([
                    'success' => false,
                    'message' => 'Patient profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'data' => new PatientResource($patient->load('user')),
            ]);
        } catch (\Exception $e) {
            Log::error('Get patient profile failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch patient profile.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update patient profile
     */
    public function update(UpdatePatientProfileRequest $request): JsonResponse
    {
        try {
            $patient = $request->user()->patient;

            if (!$patient) {
                return response()->json([
                    'success' => false,
                    'message' => 'Patient profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Handle image upload
            $imageFile = $request->file('image');
            if ($imageFile) {
                $imageName = 'patient_' . $patient->id . '_' . time() . '.' . $imageFile->getClientOriginalExtension();
                $destinationPath = public_path('uploads/patients');

                // Ensure directory exists
                if (!file_exists($destinationPath)) {
                    mkdir($destinationPath, 0755, true);
                }

                // Delete old image if exists
                if ($patient->image && file_exists(public_path($patient->image))) {
                    unlink(public_path($patient->image));
                }

                $imageFile->move($destinationPath, $imageName);
                $patient->update(['image' => '/uploads/patients/' . $imageName]);

                Log::info('Patient image uploaded', ['path' => '/uploads/patients/' . $imageName]);
            }

            // Update user data
            $userData = [];
            if ($request->has('name')) {
                $userData['name'] = $request->name;
            }
            if ($request->has('phone')) {
                $userData['phone'] = $request->phone;
            }
            if (!empty($userData)) {
                $request->user()->update($userData);
            }

            // Update patient data
            $patientData = $request->validated();
            unset($patientData['name'], $patientData['phone'], $patientData['image']);

            if (!empty($patientData)) {
                $patient->update($patientData);
            }

            Log::info('Patient profile updated', ['patient_id' => $patient->id]);

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => new PatientResource($patient->load('user')),
            ]);
        } catch (\Exception $e) {
            Log::error('Update patient profile failed', [
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
     * Get patient's medical history
     */
    public function medicalHistory(Request $request): JsonResponse
    {
        try {
            $patient = $request->user()->patient;

            if (!$patient) {
                return response()->json([
                    'success' => false,
                    'message' => 'Patient profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'medical_history' => $patient->medical_history,
                    'allergies' => $patient->allergies,
                    'blood_type' => $patient->blood_type,
                    'emergency_contact' => [
                        'name' => $patient->emergency_contact_name,
                        'phone' => $patient->emergency_contact_phone,
                        'relation' => $patient->emergency_contact_relation,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get medical history failed', [
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