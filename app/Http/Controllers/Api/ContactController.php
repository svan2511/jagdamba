<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ContactController extends Controller
{
    /**
     * Submit contact form (Public)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'nullable|string|max:20',
                'department' => 'nullable|string|max:255',
                'message' => 'required|string',
            ]);

            $contact = Contact::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'department' => $request->department,
                'message' => $request->message,
                'status' => 'new',
            ]);

            Log::info('Contact form submitted', ['contact_id' => $contact->id]);

            return response()->json([
                'success' => true,
                'message' => 'Thank you for contacting us. We will get back to you soon!',
                'data' => $contact,
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            Log::error('Contact form submission failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit contact form. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get all contacts (Admin only)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Contact::orderBy('created_at', 'desc');

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('message', 'like', "%{$search}%");
                });
            }

            $contacts = $query->paginate(20);

            return response()->json([
                'success' => true,
                'data' => $contacts->items(),
                'pagination' => [
                    'current_page' => $contacts->currentPage(),
                    'last_page' => $contacts->lastPage(),
                    'per_page' => $contacts->perPage(),
                    'total' => $contacts->total(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get contacts failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch contacts.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get single contact (Admin only)
     */
    public function show(int $id): JsonResponse
    {
        try {
            $contact = Contact::find($id);

            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contact not found.',
                ], Response::HTTP_NOT_FOUND);
            }

            // Mark as read if new
            if ($contact->status === 'new') {
                $contact->update(['status' => 'read']);
            }

            return response()->json([
                'success' => true,
                'data' => $contact,
            ]);
        } catch (\Exception $e) {
            Log::error('Get contact failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch contact.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update contact status/notes (Admin only)
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $contact = Contact::find($id);

            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contact not found.',
                ], Response::HTTP_NOT_FOUND);
            }

            $request->validate([
                'status' => 'sometimes|in:new,read,replied,closed',
                'admin_notes' => 'sometimes|string',
            ]);

            $contact->update($request->only(['status', 'admin_notes']));

            Log::info('Contact updated', ['contact_id' => $contact->id]);

            return response()->json([
                'success' => true,
                'message' => 'Contact updated successfully.',
                'data' => $contact,
            ]);
        } catch (\Exception $e) {
            Log::error('Update contact failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update contact.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete contact (Admin only)
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $contact = Contact::find($id);

            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contact not found.',
                ], Response::HTTP_NOT_FOUND);
            }

            $contact->delete();

            Log::info('Contact deleted', ['contact_id' => $id]);

            return response()->json([
                'success' => true,
                'message' => 'Contact deleted successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Delete contact failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete contact.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get contact stats (Admin only)
     */
    public function stats(): JsonResponse
    {
        try {
            $stats = [
                'total' => Contact::count(),
                'new' => Contact::where('status', 'new')->count(),
                'read' => Contact::where('status', 'read')->count(),
                'replied' => Contact::where('status', 'replied')->count(),
                'closed' => Contact::where('status', 'closed')->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error('Get contact stats failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch contact stats.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}