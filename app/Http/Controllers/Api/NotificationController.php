<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class NotificationController extends Controller
{
    /**
     * Get user's notifications
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $notifications = $request->user()->notifications()
                ->when($request->has('unread'), function ($query) {
                    $query->where('is_read', false);
                })
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data' => NotificationResource::collection($notifications),
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                ],
                'unread_count' => $request->user()->unreadNotifications()->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Get notifications failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notifications.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(int $id): JsonResponse
    {
        try {
            $notification = Notification::where('id', $id)
                ->where('user_id', auth()->id())
                ->first();

            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $notification->markAsRead();

            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read',
            ]);
        } catch (\Exception $e) {
            Log::error('Mark notification as read failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to mark notification as read.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            $request->user()->unreadNotifications()->update(['is_read' => true]);

            return response()->json([
                'success' => true,
                'message' => 'All notifications marked as read',
            ]);
        } catch (\Exception $e) {
            Log::error('Mark all notifications as read failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to mark all notifications as read.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete notification
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $notification = Notification::where('id', $id)
                ->where('user_id', auth()->id())
                ->first();

            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $notification->delete();

            return response()->json([
                'success' => true,
                'message' => 'Notification deleted',
            ]);
        } catch (\Exception $e) {
            Log::error('Delete notification failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete notification.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create notification (Admin)
     */
    public function createNotification(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'title' => 'required|string|max:255',
                'message' => 'required|string',
                'type' => 'nullable|in:info,warning,success,error',
                'user_id' => 'nullable|exists:users,id',
            ]);

            $notification = Notification::create([
                'user_id' => $request->user_id ?? auth()->id(),
                'title' => $request->title,
                'message' => $request->message,
                'type' => $request->type ?? 'info',
                'is_read' => false,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Notification sent successfully',
                'data' => new NotificationResource($notification),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            Log::error('Create notification failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send notification.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get all notifications (Admin)
     */
    public function adminIndex(Request $request): JsonResponse
    {
        try {
            $query = Notification::with('user:id,name,email')
                ->orderBy('created_at', 'desc');

            // Filter by type
            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            // Filter by read status
            if ($request->has('is_read')) {
                $query->where('is_read', $request->is_read === 'true');
            }

            $notifications = $query->paginate(20);

            return response()->json([
                'success' => true,
                'data' => NotificationResource::collection($notifications),
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Admin get notifications failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notifications.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete notification (Admin)
     */
    public function deleteNotification(int $id): JsonResponse
    {
        try {
            $notification = Notification::find($id);

            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $notification->delete();

            return response()->json([
                'success' => true,
                'message' => 'Notification deleted',
            ]);
        } catch (\Exception $e) {
            Log::error('Delete notification failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete notification.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}