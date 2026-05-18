<?php

namespace App\Http\Controllers\Api\V1\Schedule;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Api\V1\Schedule\CreateScheduleRequest;
use App\Http\Requests\Api\V1\Schedule\UpdateScheduleRequest;
use App\Http\Resources\Api\V1\Schedule\ScheduleCollection;
use App\Http\Resources\Api\V1\Schedule\ScheduleResource;
use App\Models\Schedule;
use App\Models\Job;
use App\Traits\ApiResponse;
use App\Helpers\NotificationHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScheduleController extends BaseController
{
    use ApiResponse;

    /**
     * List all schedules for the authenticated vendor
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse('Authenticated user is not associated with a vendor.', 403);
            }

            Log::info('=== GET SCHEDULES START ===', [
                'filters' => $request->all(),
                'ip' => $request->ip(),
            ]);

            $query = Schedule::byVendor($vendorId)
                ->with(['job.client', 'crew']);

            // Filter by status
            if ($request->filled('status')) {
                $query->byStatus($request->input('status'));
            }

            // Filter by priority
            if ($request->filled('priority')) {
                $query->where('priority', $request->input('priority'));
            }

            // Filter by date range
            if ($request->filled('start_date') && $request->filled('end_date')) {
                $query->byDateRange($request->input('start_date'), $request->input('end_date'));
            }

            // Filter by job_id
            if ($request->filled('job_id')) {
                $query->where('job_id', $request->input('job_id'));
            }

            // Search
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->whereHas('job', function ($jq) use ($search) {
                        $jq->where('title', 'like', "%{$search}%")
                            ->orWhere('job_number', 'like', "%{$search}%");
                    })
                    ->orWhereHas('crew', function ($cq) use ($search) {
                        $cq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
                });
            }

            // Sorting
            $sortBy = $request->input('sort_by', 'start_datetime');
            $sortDirection = $request->input('sort_direction', 'desc');
            $allowedSorts = ['start_datetime', 'end_datetime', 'priority', 'status', 'created_at'];
            if (in_array($sortBy, $allowedSorts)) {
                $query->orderBy($sortBy, $sortDirection);
            }

            $perPage = $request->input('per_page', 15);
            $schedules = $query->paginate($perPage);

            Log::info('=== GET SCHEDULES END ===', [
                'total' => $schedules->total(),
                'status' => 'success',
            ]);

            return $this->successResponse(
                new ScheduleCollection($schedules),
                'Schedules retrieved successfully.',
                200
            );
        } catch (\Exception $e) {
            Log::error('=== GET SCHEDULES END ===', [
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to retrieve schedules. Please try again.', 500);
        }
    }

    /**
     * Create a new schedule
     */
    public function store(CreateScheduleRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse('Authenticated user is not associated with a vendor.', 403);
            }

            $validated = $request->validated();

            Log::info('=== CREATE SCHEDULE START ===', [
                'job_id' => $validated['job_id'],
                'ip' => $request->ip(),
            ]);

            DB::beginTransaction();

            $schedule = Schedule::create([
                'vendor_id' => $vendorId,
                'job_id' => $validated['job_id'],
                'crew_id' => $validated['crew_id'] ?? null,
                'start_datetime' => $validated['start_datetime'],
                'end_datetime' => $validated['end_datetime'],
                'priority' => $validated['priority'],
                'status' => $validated['status'],
                'notes' => $validated['notes'] ?? null,
                'is_multi_day' => $validated['is_multi_day'],
                'is_recurring' => $validated['is_recurring'],
                'notify_client' => $validated['notify_client'],
                'notify_crew' => $validated['notify_crew'],
            ]);

            // Update job status to 'scheduled' if schedule is not a draft
            if ($validated['status'] === 'scheduled') {
                $job = Job::find($validated['job_id']);
                if ($job && in_array($job->status, ['pending', 'draft'])) {
                    $job->update(['status' => 'scheduled']);
                }
            }

            DB::commit();

            $schedule->load(['job.client', 'crew']);

            Log::info('=== CREATE SCHEDULE END ===', [
                'schedule_id' => $schedule->id,
                'status' => 'success',
            ]);

            NotificationHelper::scheduleCreated($schedule, null, auth()->id());
            return $this->createdResponse(
                new ScheduleResource($schedule),
                'Schedule created successfully.'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $this->validationErrorResponse($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('=== CREATE SCHEDULE END ===', [
                'status' => 'error',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Failed to create schedule. Please try again.', 500);
        }
    }

    /**
     * Get a single schedule
     */
    public function show(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse('Authenticated user is not associated with a vendor.', 403);
            }

            $schedule = Schedule::byVendor($vendorId)
                ->with(['job.client', 'crew'])
                ->find($id);

            if (!$schedule) {
                return $this->notFoundResponse('Schedule not found.');
            }

            return $this->successResponse(
                new ScheduleResource($schedule),
                'Schedule retrieved successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== GET SCHEDULE ERROR ===', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to retrieve schedule. Please try again.', 500);
        }
    }

    /**
     * Update a schedule
     */
    public function update(UpdateScheduleRequest $request, int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse('Authenticated user is not associated with a vendor.', 403);
            }

            $schedule = Schedule::byVendor($vendorId)->find($id);

            if (!$schedule) {
                return $this->notFoundResponse('Schedule not found.');
            }

            $validated = $request->validated();

            Log::info('=== UPDATE SCHEDULE START ===', [
                'schedule_id' => $id,
                'updates' => array_keys($validated),
            ]);

            DB::beginTransaction();

            $schedule->update($validated);

            // Update job status if schedule status changed to 'scheduled'
            if (isset($validated['status']) && $validated['status'] === 'scheduled') {
                $job = $schedule->job;
                if ($job && in_array($job->status, ['pending', 'draft'])) {
                    $job->update(['status' => 'scheduled']);
                }
            }

            DB::commit();

            $schedule->load(['job.client', 'crew']);

            Log::info('=== UPDATE SCHEDULE END ===', [
                'schedule_id' => $id,
                'status' => 'success',
            ]);

            return $this->successResponse(
                new ScheduleResource($schedule),
                'Schedule updated successfully.'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $this->validationErrorResponse($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('=== UPDATE SCHEDULE END ===', [
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to update schedule. Please try again.', 500);
        }
    }

    /**
     * Delete a schedule
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse('Authenticated user is not associated with a vendor.', 403);
            }

            $schedule = Schedule::byVendor($vendorId)->find($id);

            if (!$schedule) {
                return $this->notFoundResponse('Schedule not found.');
            }

            Log::info('=== DELETE SCHEDULE START ===', [
                'schedule_id' => $id,
            ]);

            $schedule->delete();

            Log::info('=== DELETE SCHEDULE END ===', [
                'schedule_id' => $id,
                'status' => 'success',
            ]);

            return $this->successResponse(null, 'Schedule deleted successfully.');
        } catch (\Exception $e) {
            Log::error('=== DELETE SCHEDULE END ===', [
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to delete schedule. Please try again.', 500);
        }
    }
}
