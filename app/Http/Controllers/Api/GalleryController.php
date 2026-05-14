<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\GalleryResource;
use App\Models\Gallery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class GalleryController extends Controller
{
    /**
     * Get all active gallery items (public)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Gallery::where('is_active', true);

            if ($request->has('category')) {
                $query->where('category', $request->category);
            }

            $galleries = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => GalleryResource::collection($galleries),
            ]);
        } catch (\Exception $e) {
            Log::error('Get gallery failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch gallery.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get categories
     */
    public function categories(): JsonResponse
    {
        try {
            $categories = Gallery::distinct()->pluck('category')->filter()->values();

            return response()->json([
                'success' => true,
                'data' => $categories,
            ]);
        } catch (\Exception $e) {
            Log::error('Get gallery categories failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch categories.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create gallery item (Admin)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'title' => 'required|string|max:255',
                'image' => 'required|string',
                'category' => 'nullable|string|max:255',
                'is_active' => 'sometimes|boolean',
            ]);

            $imageData = $request->image;
            $imagePath = null;

            // Handle base64 image data
            if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $matches)) {
                $extension = $matches[1];
                $image = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $imageData));

                if ($image !== false) {
                    $fileName = 'gallery_' . time() . '.' . $extension;
                    $uploadPath = public_path('uploads/gallery');

                    if (!file_exists($uploadPath)) {
                        mkdir($uploadPath, 0755, true);
                    }

                    file_put_contents($uploadPath . '/' . $fileName, $image);
                    $imagePath = '/uploads/gallery/' . $fileName;
                }
            }

            $gallery = Gallery::create([
                'title' => $request->title,
                'image' => $imagePath,
                'category' => $request->category,
                'is_active' => $request->boolean('is_active', true),
            ]);

            Log::info('Gallery item created', ['gallery_id' => $gallery->id]);

            return response()->json([
                'success' => true,
                'message' => 'Gallery item created successfully',
                'data' => new GalleryResource($gallery),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            Log::error('Create gallery item failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create gallery item.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update gallery item (Admin)
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $gallery = Gallery::find($id);

            if (!$gallery) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gallery item not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $gallery->update($request->validated());

            Log::info('Gallery item updated', ['gallery_id' => $gallery->id]);

            return response()->json([
                'success' => true,
                'message' => 'Gallery item updated successfully',
                'data' => new GalleryResource($gallery),
            ]);
        } catch (\Exception $e) {
            Log::error('Update gallery item failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update gallery item.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete gallery item (Admin)
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $gallery = Gallery::find($id);

            if (!$gallery) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gallery item not found',
                ], Response::HTTP_NOT_FOUND);
            }

            $gallery->delete();

            Log::info('Gallery item deleted', ['gallery_id' => $id]);

            return response()->json([
                'success' => true,
                'message' => 'Gallery item deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Delete gallery item failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete gallery item.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get all gallery items (Admin)
     */
    public function adminIndex(Request $request): JsonResponse
    {
        try {
            $query = Gallery::orderBy('created_at', 'desc');

            if ($request->has('category')) {
                $query->where('category', $request->category);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->is_active === 'true');
            }

            $galleries = $query->paginate(20);

            return response()->json([
                'success' => true,
                'data' => GalleryResource::collection($galleries),
                'pagination' => [
                    'current_page' => $galleries->currentPage(),
                    'last_page' => $galleries->lastPage(),
                    'per_page' => $galleries->perPage(),
                    'total' => $galleries->total(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Admin get gallery failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch gallery.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}