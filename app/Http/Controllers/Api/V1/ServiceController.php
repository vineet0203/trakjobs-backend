<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ServiceController extends BaseController
{
    /**
     * Display a public listing of Published services.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Service::where('status', 'Published');

            if ($request->filled('category') && $request->category !== 'all') {
                $query->where('category', $request->category);
            }

            $services = $query->orderBy('featured', 'desc')
                ->orderBy('sort_order', 'asc')
                ->orderBy('title', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Services retrieved successfully',
                'data' => $services,
                'total' => $services->count(),
                'timestamp' => now()->toIso8601String(),
                'code' => 200
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve public services', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to retrieve services', 500);
        }
    }

    /**
     * Display a protected listing of all services (for admin).
     */
    public function adminIndex(Request $request): JsonResponse
    {
        try {
            $query = Service::query();

            if ($request->filled('search')) {
                $query->where(function ($q) use ($request) {
                    $q->where('title', 'like', '%' . $request->search . '%')
                      ->orWhere('subtitle', 'like', '%' . $request->search . '%');
                });
            }

            if ($request->filled('category') && $request->category !== 'all') {
                $query->where('category', $request->category);
            }

            if ($request->filled('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            if ($request->filled('featured') && $request->featured !== 'all') {
                $query->where('featured', $request->boolean('featured'));
            }

            $paginator = $query->orderBy('sort_order', 'asc')
                ->orderBy('title', 'asc')
                ->paginate(15);

            $stats = [
                'total' => Service::count(),
                'published' => Service::where('status', 'Published')->count(),
                'pending' => Service::where('status', 'Pending')->count(),
                'draft' => Service::where('status', 'Draft')->count(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Services retrieved successfully',
                'data' => $paginator->items(),
                'total' => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'stats' => $stats,
                'timestamp' => now()->toIso8601String(),
                'code' => 200
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve admin services', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to retrieve services', 500);
        }
    }

    /**
     * Store a newly created service.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'subtitle' => 'nullable|string|max:255',
                'image' => 'nullable|string|max:2048',
                'category' => 'required|string|max:255',
                'sub_category_id' => 'nullable|integer|exists:service_sub_categories,id',
                'sub_category' => 'nullable|string|max:255',
                'price' => 'nullable|string|max:255',
                'location' => 'nullable|string|max:255',
                'detailed_address' => 'nullable|string|max:1000',
                'latitude' => 'nullable|numeric|between:-90,90',
                'longitude' => 'nullable|numeric|between:-180,180',
                'status' => 'in:Published,Pending,Draft',
                'featured' => 'boolean',
                'sort_order' => 'integer',
            ]);

            $service = Service::create($validated);

            return $this->createdResponse(
                $service,
                'Service created successfully'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->errors());
        } catch (\Exception $e) {
            Log::error('Failed to create service', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to create service', 500);
        }
    }

    /**
     * Update the specified service.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $service = Service::find($id);

            if (!$service) {
                return $this->notFoundResponse('Service not found');
            }

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'subtitle' => 'nullable|string|max:255',
                'image' => 'nullable|string|max:2048',
                'category' => 'required|string|max:255',
                'sub_category_id' => 'nullable|integer|exists:service_sub_categories,id',
                'sub_category' => 'nullable|string|max:255',
                'price' => 'nullable|string|max:255',
                'location' => 'nullable|string|max:255',
                'detailed_address' => 'nullable|string|max:1000',
                'latitude' => 'nullable|numeric|between:-90,90',
                'longitude' => 'nullable|numeric|between:-180,180',
                'status' => 'in:Published,Pending,Draft',
                'featured' => 'boolean',
                'sort_order' => 'integer',
            ]);

            $service->update($validated);

            return $this->successResponse(
                $service,
                'Service updated successfully'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->errors());
        } catch (\Exception $e) {
            Log::error('Failed to update service', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to update service', 500);
        }
    }

    /**
     * Remove the specified service.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $service = Service::find($id);

            if (!$service) {
                return $this->notFoundResponse('Service not found');
            }

            $service->delete();

            return $this->successResponse(null, 'Service deleted successfully');
        } catch (\Exception $e) {
            Log::error('Failed to delete service', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to delete service', 500);
        }
    }

    /**
     * Toggle featured property of a service.
     */
    public function toggleFeatured(int $id): JsonResponse
    {
        try {
            $service = Service::find($id);

            if (!$service) {
                return $this->notFoundResponse('Service not found');
            }

            $service->featured = !$service->featured;
            $service->save();

            return $this->successResponse($service, 'Service featured status toggled successfully');
        } catch (\Exception $e) {
            Log::error('Failed to toggle service featured status', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to toggle service featured status', 500);
        }
    }

    /**
     * Toggle status property of a service.
     */
    public function toggleStatus(int $id): JsonResponse
    {
        try {
            $service = Service::find($id);

            if (!$service) {
                return $this->notFoundResponse('Service not found');
            }

            $service->status = $service->status === 'Published' ? 'Draft' : 'Published';
            $service->save();

            return $this->successResponse($service, 'Service status toggled successfully');
        } catch (\Exception $e) {
            Log::error('Failed to toggle service status', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to toggle service status', 500);
        }
    }
}
