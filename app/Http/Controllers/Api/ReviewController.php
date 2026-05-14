<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ReviewResource;
use App\Models\Doctor;
use App\Models\Notification;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ReviewController extends Controller
{
    /**
     * Get all approved reviews (public)
     */
    public function publicIndex(Request $request): JsonResponse
    {
        try {
            $limit = $request->input('limit', 10);

            $reviews = Review::with(['patient.user:id,name', 'doctor.user:id,name'])
                ->where('is_approved', true)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => ReviewResource::collection($reviews),
            ]);
        } catch (\Exception $e) {
            Log::error('Get public reviews failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch reviews.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a review
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'doctor_id' => 'required|exists:doctors,id',
                'appointment_id' => 'nullable|exists:appointments,id',
                'rating' => 'required|integer|min:1|max:5',
                'comment' => 'nullable|string|max:1000',
            ]);

            $patient = $request->user()->patient;

            if (!$patient) {
                return response()->json([
                    'success' => false,
                    'message' => 'Patient profile not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Check if already reviewed
            $exists = Review::where('patient_id', $patient->id)
                ->where('doctor_id', $request->doctor_id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have already reviewed this doctor',
                ], Response::HTTP_CONFLICT);
            }

            $review = Review::create([
                'patient_id' => $patient->id,
                'doctor_id' => $request->doctor_id,
                'appointment_id' => $request->appointment_id,
                'rating' => $request->rating,
                'comment' => $request->comment,
                'is_approved' => false,
            ]);

            // Notify doctor
            $doctor = Doctor::find($request->doctor_id);
            Notification::create([
                'user_id' => $doctor->user_id,
                'title' => 'New Review',
                'message' => "You received a {$request->rating}-star review from {$request->user()->name}",
                'type' => 'general',
            ]);

            Log::info('Review created', ['review_id' => $review->id]);

            return response()->json([
                'success' => true,
                'message' => 'Review submitted successfully. It will be visible after approval.',
                'data' => new ReviewResource($review),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            Log::error('Create review failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit review.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get approved reviews for a doctor (public)
     */
    public function doctorReviews(int $doctorId): JsonResponse
    {
        try {
            $doctor = Doctor::find($doctorId);

            if (!$doctor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Doctor not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $reviews = Review::with('patient.user:id,name')
                ->where('doctor_id', $doctorId)
                ->where('is_approved', true)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => ReviewResource::collection($reviews),
            ]);
        } catch (\Exception $e) {
            Log::error('Get doctor reviews failed', [
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
     * Approve/reject review (Admin only)
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'is_approved' => 'required|boolean',
            ]);

            $review = Review::find($id);

            if (!$review) {
                return response()->json([
                    'success' => false,
                    'message' => 'Review not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $review->update(['is_approved' => $request->is_approved]);

            Log::info('Review status updated', ['review_id' => $review->id]);

            return response()->json([
                'success' => true,
                'message' => 'Review status updated',
                'data' => new ReviewResource($review),
            ]);
        } catch (\Exception $e) {
            Log::error('Update review status failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update review status.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}