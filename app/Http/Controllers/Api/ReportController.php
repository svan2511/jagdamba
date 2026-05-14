<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ReportResource;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    /**
     * Get patient's reports
     */
    public function myReports(Request $request): JsonResponse
    {
        try {
            $patient = $request->user()->patient;

            if (!$patient) {
                return response()->json([
                    'success' => false,
                    'message' => 'Patient profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $reports = Report::where('patient_id', $patient->id)
                ->orderBy('report_date', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => ReportResource::collection($reports),
            ]);
        } catch (\Exception $e) {
            Log::error('Get reports failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch reports.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get single report details
     */
    public function show(int $id): JsonResponse
    {
        try {
            $report = Report::with('patient.user', 'appointment')
                ->where('id', $id)
                ->first();

            if (!$report) {
                return response()->json([
                    'success' => false,
                    'message' => 'Report not found',
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'data' => new ReportResource($report),
            ]);
        } catch (\Exception $e) {
            Log::error('Get report failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch report details.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create report (Admin/Doctor)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'patient_id' => 'required|exists:patients,id',
                'appointment_id' => 'nullable|exists:appointments,id',
                'title' => 'required|string|max:255',
                'report_type' => 'required|string|max:255',
                'file_path' => 'nullable|string',
                'description' => 'nullable|string',
                'report_date' => 'required|date',
            ]);

            $report = Report::create($request->validated());

            Log::info('Report created', ['report_id' => $report->id]);

            return response()->json([
                'success' => true,
                'message' => 'Report created successfully',
                'data' => new ReportResource($report),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            Log::error('Create report failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create report.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get all reports for doctor's patients
     */
    public function doctorReports(Request $request): JsonResponse
    {
        try {
            $doctor = $request->user()->doctor;

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Get patients who have appointments with this doctor
            $patientIds = \App\Models\Appointment::where('doctor_id', $doctor->id)
                ->pluck('patient_id')
                ->unique();

            $reports = Report::with('patient.user:id,name,phone')
                ->whereIn('patient_id', $patientIds)
                ->orderBy('report_date', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => ReportResource::collection($reports),
            ]);
        } catch (\Exception $e) {
            Log::error('Get doctor reports failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch reports.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create report for a patient (Doctor)
     */
    public function doctorStoreReport(Request $request): JsonResponse
    {
        try {
            $doctor = $request->user()->doctor;

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Validate with file support
            $validationRules = [
                'patient_id' => 'required|exists:patients,id',
                'appointment_id' => 'nullable|exists:appointments,id',
                'title' => 'required|string|max:255',
                'report_type' => 'required|string|max:255',
                'description' => 'nullable|string',
                'report_date' => 'required|date',
                'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png,gif,doc,docx|max:10240', // 10MB max
            ];

            $request->validate($validationRules);

            // Verify this patient has an appointment with this doctor
            $hasAppointment = \App\Models\Appointment::where('doctor_id', $doctor->id)
                ->where('patient_id', $request->patient_id)
                ->exists();

            if (!$hasAppointment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Patient has no appointment with you',
                ], Response::HTTP_FORBIDDEN);
            }

            // Handle file upload
            $filePath = null;
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $fileName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                // Create directory if not exists
                $uploadPath = public_path('uploads/reports');
                if (!file_exists($uploadPath)) {
                    mkdir($uploadPath, 0755, true);
                }

                // Store file
                $file->move($uploadPath, $fileName);
                $filePath = '/uploads/reports/' . $fileName;
            }

            $report = Report::create([
                'patient_id' => $request->patient_id,
                'appointment_id' => $request->appointment_id,
                'title' => $request->title,
                'report_type' => $request->report_type,
                'file_path' => $filePath,
                'description' => $request->description,
                'report_date' => $request->report_date,
            ]);

            Log::info('Doctor created report', ['report_id' => $report->id, 'doctor_id' => $doctor->id, 'file_path' => $filePath]);

            return response()->json([
                'success' => true,
                'message' => 'Report created successfully',
                'data' => new ReportResource($report->load('patient.user')),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            Log::error('Doctor create report failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create report.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get single report details (Doctor)
     */
    public function doctorShowReport(Request $request, int $id): JsonResponse
    {
        try {
            $doctor = $request->user()->doctor;

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $report = Report::with('patient.user', 'appointment')
                ->where('id', $id)
                ->first();

            if (!$report) {
                return response()->json([
                    'success' => false,
                    'message' => 'Report not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Verify this report belongs to a patient of this doctor
            $patientIds = \App\Models\Appointment::where('doctor_id', $doctor->id)
                ->pluck('patient_id')
                ->unique()
                ->toArray();

            if (!in_array($report->patient_id, $patientIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], Response::HTTP_FORBIDDEN);
            }

            return response()->json([
                'success' => true,
                'data' => new ReportResource($report),
            ]);
        } catch (\Exception $e) {
            Log::error('Get doctor report failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch report details.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete report (Doctor)
     */
    public function doctorDeleteReport(Request $request, int $id): JsonResponse
    {
        try {
            $doctor = $request->user()->doctor;

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $report = Report::find($id);

            if (!$report) {
                return response()->json([
                    'success' => false,
                    'message' => 'Report not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Verify this report belongs to a patient of this doctor
            $patientIds = \App\Models\Appointment::where('doctor_id', $doctor->id)
                ->pluck('patient_id')
                ->unique()
                ->toArray();

            if (!in_array($report->patient_id, $patientIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], Response::HTTP_FORBIDDEN);
            }

            $report->delete();

            Log::info('Doctor deleted report', ['report_id' => $id, 'doctor_id' => $doctor->id]);

            return response()->json([
                'success' => true,
                'message' => 'Report deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Delete report failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete report.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}