<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\BaseController;
use App\Models\ServiceSubCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ServiceSubCategoryController extends BaseController
{
    /**
     * Display a listing of service sub-categories.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ServiceSubCategory::query();

            // Filter by service_category_id if provided
            if ($request->has('service_category_id')) {
                $query->where('service_category_id', $request->service_category_id);
            }

            // Default to only active sub-categories
            if (!$request->boolean('all')) {
                $query->where('is_active', true);
            }

            $subCategories = $query->orderBy('sort_order', 'asc')
                ->orderBy('name', 'asc')
                ->with('serviceCategory')
                ->get();

            return $this->successResponse(
                $subCategories,
                'Service sub-categories retrieved successfully'
            );
        } catch (\Exception $e) {
            Log::error('Failed to retrieve service sub-categories', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to retrieve service sub-categories', 500);
        }
    }

    /**
     * Store a newly created service sub-category.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'service_category_id' => 'required|exists:service_categories,id',
                'name' => 'required|string|max:255',
                'slug' => 'required|string|max:255|unique:service_sub_categories,slug',
                'description' => 'nullable|string',
                'icon' => 'nullable|string|max:255',
                'is_active' => 'boolean',
                'sort_order' => 'integer',
            ]);

            $subCategory = ServiceSubCategory::create($validated);
            $subCategory->load('serviceCategory');

            return $this->createdResponse(
                $subCategory,
                'Service sub-category created successfully'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->errors());
        } catch (\Exception $e) {
            Log::error('Failed to create service sub-category', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to create service sub-category', 500);
        }
    }

    /**
     * Update the specified service sub-category.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $subCategory = ServiceSubCategory::find($id);

            if (!$subCategory) {
                return $this->notFoundResponse('Service sub-category not found');
            }

            $validated = $request->validate([
                'service_category_id' => 'required|exists:service_categories,id',
                'name' => 'required|string|max:255',
                'slug' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('service_sub_categories', 'slug')->ignore($id),
                ],
                'description' => 'nullable|string',
                'icon' => 'nullable|string|max:255',
                'is_active' => 'boolean',
                'sort_order' => 'integer',
            ]);

            $subCategory->update($validated);
            $subCategory->load('serviceCategory');

            return $this->successResponse(
                $subCategory,
                'Service sub-category updated successfully'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->errors());
        } catch (\Exception $e) {
            Log::error('Failed to update service sub-category', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to update service sub-category', 500);
        }
    }

    /**
     * Remove the specified service sub-category.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $subCategory = ServiceSubCategory::find($id);

            if (!$subCategory) {
                return $this->notFoundResponse('Service sub-category not found');
            }

            $subCategory->delete();

            return $this->successResponse(null, 'Service sub-category deleted successfully');
        } catch (\Exception $e) {
            Log::error('Failed to delete service sub-category', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to delete service sub-category', 500);
        }
    }

    /**
     * Toggle the status (is_active) of the service sub-category.
     */
    public function toggle(int $id): JsonResponse
    {
        try {
            $subCategory = ServiceSubCategory::find($id);

            if (!$subCategory) {
                return $this->notFoundResponse('Service sub-category not found');
            }

            $subCategory->is_active = !$subCategory->is_active;
            $subCategory->save();

            return $this->successResponse(
                $subCategory,
                'Service sub-category status toggled successfully'
            );
        } catch (\Exception $e) {
            Log::error('Failed to toggle service sub-category status', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to toggle service sub-category status', 500);
        }
    }
}
