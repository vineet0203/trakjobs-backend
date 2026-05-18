<?php
// app/Http/Controllers/Api/V1/Jobs/JobController.php

namespace App\Http\Controllers\Api\V1\Jobs;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Api\V1\Jobs\CreateJobRequest;
use App\Http\Requests\Api\V1\Jobs\UpdateJobRequest;
use App\Http\Requests\Api\V1\Jobs\AddTaskRequest;
use App\Http\Requests\Api\V1\Jobs\AddAttachmentRequest;
use App\Http\Requests\Api\V1\Jobs\GetJobRequest;
use App\Http\Resources\Api\V1\Job\JobCollection;
use App\Http\Resources\Api\V1\Job\JobResource;
use App\Http\Resources\Api\V1\Job\JobTaskResource;
use App\Http\Resources\Api\V1\Job\JobAttachmentResource;
use App\Models\JobAttachment;
// use App\Http\Resources\Api\V1\Jobs\JobActivityResource;
use App\Services\Jobs\JobCreationService;
use App\Services\Jobs\JobQueryService;
use App\Services\Jobs\UpdateService;
use App\Services\Jobs\JobDeletionService;
use App\Services\Jobs\JobTaskService;
use App\Services\Jobs\JobAttachmentService;
use App\Services\Jobs\JobUpdateService;
use App\Models\JobAssignment;
use App\Models\Employee;
use App\Models\JobActivity;
use App\Traits\ApiResponse;
use App\Helpers\NotificationHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class JobController extends BaseController
{
    use ApiResponse;

    private JobCreationService $JobCreationService;
    private JobQueryService $JobQueryService;
    private JobUpdateService $JobUpdateService;
    private JobDeletionService $JobDeletionService;
    private JobTaskService $JobTaskService;
    private JobAttachmentService $JobAttachmentService;

    public function __construct(
        JobCreationService $JobCreationService,
        JobQueryService $JobQueryService,
        JobUpdateService $JobUpdateService,
        JobDeletionService $JobDeletionService,
        JobTaskService $JobTaskService,
        JobAttachmentService $JobAttachmentService
    ) {
        $this->JobCreationService = $JobCreationService;
        $this->JobQueryService = $JobQueryService;
        $this->JobUpdateService = $JobUpdateService;
        $this->JobDeletionService = $JobDeletionService;
        $this->JobTaskService = $JobTaskService;
        $this->JobAttachmentService = $JobAttachmentService;
    }

    /**
     * Create a new job
     */
    public function store(CreateJobRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();

            Log::info('=== CREATE JOB START ===', [
                'title' => $validatedData['title'],
                'client_id' => $validatedData['client_id'],
                'work_type' => $validatedData['work_type'],
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            $Job = $this->JobCreationService->create($validatedData, auth()->id());

            Log::info('=== CREATE JOB END ===', [
                'job_id' => $Job->id,
                'job_number' => $Job->job_number,
                'status' => 'success'
            ]);

            // Load only the relationships you need for the response
            NotificationHelper::jobCreated($Job, auth()->id());
            return $this->createdResponse(
                new JobResource($Job->load(['client', 'tasks'])),
                'Job created successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== CREATE JOB END ===', [
                'status' => 'error',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(
                'Failed to create job. Please try again.',
                500
            );
        }
    }


    /**
     * Get all jobs with filtering and pagination - only for authenticated vendor
     */
    public function index(GetJobRequest $request): JsonResponse
    {
        try {
            Log::info('=== GET WORK ORDERS START ===', [
                'filters' => $request->all(),
                'ip' => $request->ip()
            ]);

            $validated = $request->validated();

            // Get the authenticated user's vendor_id
            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            // Add vendor_id to filters to restrict jobs
            $validated['vendor_id'] = $vendorId;

            $jobs = $this->JobQueryService->getJobs($validated, $validated['per_page'] ?? 15);

            $appliedFilters = $this->JobQueryService->getAppliedFilters($validated);

            Log::info('=== GET WORK ORDERS END ===', [
                'total_jobs' => $jobs->total(),
                'current_page' => $jobs->currentPage(),
                'vendor_id' => $vendorId,
                'status' => 'success'
            ]);

            return $this->successResponse(
                new JobCollection($jobs),
                'Jobs retrieved successfully.',
                200,
                [
                    'filters' => $appliedFilters,
                    'pagination' => [
                        'total' => $jobs->total(),
                        'per_page' => $jobs->perPage(),
                        'current_page' => $jobs->currentPage(),
                        'last_page' => $jobs->lastPage(),
                    ]
                ]
            );
        } catch (\Exception $e) {
            Log::error('=== GET WORK ORDERS END ===', [
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve job. Please try again.',
                500
            );
        }
    }

    /**
     * Get a single job by ID
     */
    public function show(int $id): JsonResponse
    {
        try {

            // Get vendor_id from authenticated user
            $user = auth()->user();
            $vendorId = $user->vendor_id;
            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            Log::info('=== GET JOB START ===', [
                'job_id' => $id,
            ]);

            $Job = $this->JobQueryService->getJob($id, [
                'client',
                'quote',
                'tasks',
                'attachments',
                'activities',
                'assignedTo',
                'createdBy',
                'updatedBy'
            ]);

            // Double-check that the job belongs to this vendor (additional safety)
            if ($Job && $Job->vendor_id !== $vendorId) {
                Log::warning('Vendor ID mismatch', [
                    'job_vendor_id' => $Job->vendor_id,
                    'user_vendor_id' => $vendorId
                ]);
                return $this->notFoundResponse('Job not found.');
            }

            Log::info('=== GET JOB END ===', [
                'job_id' => $Job->id,
                'job_number' => $Job->job_number,
                'status' => 'success'
            ]);

            return $this->successResponse(
                new JobResource($Job),
                'Job retrieved successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== GET JOB END ===', [
                'job_id' => $id,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve job. Please try again.',
                500
            );
        }
    }

    /**
     * Get a job by job number
     */
    public function showByNumber(string $jobNumber): JsonResponse
    {
        try {
            Log::info('=== GET JOB BY NUMBER START ===', [
                'job_number' => $jobNumber,
            ]);

            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            $Job = $this->JobQueryService->getJobByNumber($jobNumber, [
                'client',
                'tasks',
                'attachments',
                'activities',
                'assignedTo',
                'createdBy',
                'updatedBy'
            ]);

            // Double-check vendor_id
            if ($Job && $Job->vendor_id !== $vendorId) {
                return $this->notFoundResponse('Job not found.');
            }

            Log::info('=== GET JOB BY NUMBER END ===', [
                'job_id' => $Job->id,
                'job_number' => $Job->job_number,
                'status' => 'success'
            ]);

            return $this->successResponse(
                new JobResource($Job),
                'Job retrieved successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== GET JOB BY NUMBER END ===', [
                'job_number' => $jobNumber,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve job. Please try again.',
                500
            );
        }
    }

    /**
     * Update a job
     */
    public function update(UpdateJobRequest $request, int $id): JsonResponse
    {
        try {
            Log::info('=== UPDATE JOB START ===', [
                'job_id' => $id,
                'updates' => array_keys($request->all()),
                'ip' => $request->ip()
            ]);

            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            $Job = $this->JobQueryService->getJob($id);

            if (!$Job) {
                return $this->notFoundResponse('Job not found.');
            }

            // Verify job belongs to this vendor
            if ($Job->vendor_id !== $vendorId) {
                Log::warning('Unauthorized update attempt', [
                    'job_id' => $id,
                    'job_vendor_id' => $Job->vendor_id,
                    'user_vendor_id' => $vendorId
                ]);
                return $this->notFoundResponse('Job not found.');
            }

            $validatedData = $request->validated();
            $Job = $this->JobUpdateService->update($Job, $validatedData, auth()->id());

            Log::info('=== UPDATE JOB END ===', [
                'job_id' => $Job->id,
                'job_number' => $Job->job_number,
                'status' => 'success'
            ]);

            return $this->successResponse(
                new JobResource($Job->load([
                    'client',
                    'tasks',
                    'attachments',
                    'quote',
                    'assignedTo',
                    'createdBy',
                    'updatedBy'
                ])),
                'Job updated successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== UPDATE JOB END ===', [
                'job_id' => $id,
                'status' => 'error',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(
                $e->getMessage(),
                400
            );
        }
    }

    /**
     * Update job status
     */
    public function updateStatus(int $id): JsonResponse
    {
        try {
            Log::info('=== UPDATE JOB STATUS START ===', [
                'job_id' => $id,
                'new_status' => request('status'),
            ]);

            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            $Job = $this->JobQueryService->getJob($id);

            if (!$Job || $Job->vendor_id !== $vendorId) {
                return $this->notFoundResponse('Job not found.');
            }

            $status = request()->validate([
                'status' => 'required|in:pending,scheduled,in_progress,on_hold,completed,cancelled,archived',
            ])['status'];

            $Job = $this->JobUpdateService->updateStatus($Job, $status, auth()->id());

            Log::info('=== UPDATE JOB STATUS END ===', [
                'job_id' => $Job->id,
                'new_status' => $status,
                'status' => 'success'
            ]);

            NotificationHelper::jobStatusUpdated($Job, $status, auth()->id());
            return $this->successResponse(
                new JobResource($Job),
                'Job status updated successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== UPDATE JOB STATUS END ===', [
                'job_id' => $id,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to update job status.',
                500
            );
        }
    }

    /**
     * Delete/soft delete a job
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            Log::info('=== DELETE JOB START ===', [
                'job_id' => $id,
                'deleted_by' => auth()->id(),
            ]);

            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            $Job = $this->JobQueryService->getJob($id);

            if (!$Job) {
                return $this->notFoundResponse('Job not found.');
            }

            if ($Job->vendor_id !== $vendorId) {
                return $this->notFoundResponse('Job not found.');
            }

            // Check if job can be deleted
            $canDelete = $this->JobDeletionService->canDelete($Job);

            if (!$canDelete['can_delete']) {
                return $this->errorResponse(
                    $canDelete['message'],
                    409
                );
            }

            $this->JobDeletionService->forceDelete($Job, auth()->id());

            Log::info('=== DELETE JOB END ===', [
                'job_id' => $id,
                'status' => 'success'
            ]);

            return $this->successResponse(
                null,
                'Job deleted successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== DELETE JOB END ===', [
                'job_id' => $id,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to delete job. Please try again.',
                500
            );
        }
    }

    /**
     * Add task to job
     */
    public function addTask(AddTaskRequest $request, int $id): JsonResponse
    {
        try {
            Log::info('=== ADD TASK TO JOB START ===', [
                'job_id' => $id,
            ]);

            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            $Job = $this->JobQueryService->getJob($id);

            if (!$Job) {
                return $this->notFoundResponse('Job not found.');
            }

            if ($Job->vendor_id !== $vendorId) {
                return $this->notFoundResponse('Job not found.');
            }

            $task = $this->JobTaskService->addTask($Job, $request->validated(), auth()->id());

            Log::info('=== ADD TASK TO JOB END ===', [
                'job_id' => $Job->id,
                'task_id' => $task->id,
                'status' => 'success'
            ]);

            return $this->createdResponse(
                new JobTaskResource($task),
                'Task added successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== ADD TASK TO JOB END ===', [
                'job_id' => $id,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to add task.',
                500
            );
        }
    }

    /**
     * Toggle task completion
     */
    public function toggleTask(int $id, int $taskId): JsonResponse
    {
        try {
            Log::info('=== TOGGLE TASK START ===', [
                'job_id' => $id,
                'task_id' => $taskId,
            ]);

            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            $Job = $this->JobQueryService->getJob($id);

            if (!$Job) {
                return $this->notFoundResponse('Job not found.');
            }

            // Verify job belongs to this vendor
            if ($Job->vendor_id !== $vendorId) {
                return $this->notFoundResponse('Job not found.');
            }

            $task = $this->JobTaskService->toggleTask($Job, $taskId, auth()->id());

            Log::info('=== TOGGLE TASK END ===', [
                'job_id' => $Job->id,
                'task_id' => $task->id,
                'completed' => $task->completed,
                'status' => 'success'
            ]);

            return $this->successResponse(
                new JobTaskResource($task),
                'Task updated successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== TOGGLE TASK END ===', [
                'job_id' => $id,
                'task_id' => $taskId,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to update task.',
                500
            );
        }
    }

    /**
     * Delete task
     */
    public function deleteTask(int $id, int $taskId): JsonResponse
    {
        try {
            Log::info('=== DELETE TASK START ===', [
                'job_id' => $id,
                'task_id' => $taskId,
            ]);

            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            $Job = $this->JobQueryService->getJob($id);

            if (!$Job) {
                return $this->notFoundResponse('Job not found.');
            }

            // Verify job belongs to this vendor
            if ($Job->vendor_id !== $vendorId) {
                return $this->notFoundResponse('Job not found.');
            }

            $this->JobTaskService->deleteTask($Job, $taskId);

            Log::info('=== DELETE TASK END ===', [
                'job_id' => $Job->id,
                'task_id' => $taskId,
                'status' => 'success'
            ]);

            return $this->successResponse(
                null,
                'Task deleted successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== DELETE TASK END ===', [
                'job_id' => $id,
                'task_id' => $taskId,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to delete task.',
                500
            );
        }
    }

    /**
     * Add attachment to job
     */

    public function addAttachment(AddAttachmentRequest $request, int $id): JsonResponse
    {
        try {
            // Log ALL request data to see what's coming
            Log::info('=== ADD ATTACHMENT TO JOB START ===', [
                'job_id' => $id,
                'all_request_data' => $request->all(),
                'request_input' => $request->input(),
                'request_post' => $request->post(),
                'request_has_context' => $request->has('context'),
                'context_value_from_input' => $request->input('context'),
                'context_value_from_post' => $request->post('context'),
                'context_value_from_all' => $request->all()['context'] ?? null,
                'files' => $request->allFiles(),
                'headers' => $request->headers->all(),
            ]);

            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            $Job = $this->JobQueryService->getJob($id);

            if (!$Job || $Job->vendor_id !== $vendorId) {
                return $this->notFoundResponse('Job not found.');
            }

            // Try multiple ways to get context
            $context = $request->input('context') ??
                $request->post('context') ??
                ($request->all()['context'] ?? JobAttachment::CONTEXT_GENERAL);

            Log::info('Context after extraction', [
                'context' => $context,
                'type' => gettype($context),
                'method_used' => $context === $request->input('context') ? 'input' : ($context === $request->post('context') ? 'post' : 'all')
            ]);

            // Validate context is allowed
            $allowedContexts = [JobAttachment::CONTEXT_GENERAL, JobAttachment::CONTEXT_INSTRUCTIONS];
            if (!in_array($context, $allowedContexts)) {
                $context = JobAttachment::CONTEXT_GENERAL;
                Log::warning('Context not allowed, defaulting to general', [
                    'provided_context' => $context
                ]);
            }

            $attachment = $this->JobAttachmentService->addAttachment(
                $Job,
                $request->file('file'),
                auth()->id(),
                $request->input('file_name'),
                $context
            );

            Log::info('=== ADD ATTACHMENT TO JOB END ===', [
                'job_id' => $Job->id,
                'attachment_id' => $attachment->id,
                'context' => $context,
                'saved_context' => $attachment->context,
                'status' => 'success'
            ]);

            return $this->createdResponse(
                new JobAttachmentResource($attachment),
                'Attachment added successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== ADD ATTACHMENT TO JOB END ===', [
                'job_id' => $id,
                'status' => 'error',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse(
                'Failed to add attachment: ' . $e->getMessage(),
                500
            );
        }
    }
    /**
     * Delete attachment
     */
    public function deleteAttachment(int $id, int $attachmentId): JsonResponse
    {
        try {
            Log::info('=== DELETE ATTACHMENT START ===', [
                'job_id' => $id,
                'attachment_id' => $attachmentId,
            ]);

            // MISSING: Vendor validation
            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            $Job = $this->JobQueryService->getJob($id);

            if (!$Job) {
                return $this->notFoundResponse('Job not found.');
            }

            // Verify job belongs to this vendor
            if ($Job->vendor_id !== $vendorId) {
                return $this->notFoundResponse('Job not found.');
            }

            $this->JobAttachmentService->deleteAttachment($Job, $attachmentId);

            Log::info('=== DELETE ATTACHMENT END ===', [
                'job_id' => $Job->id,
                'attachment_id' => $attachmentId,
                'status' => 'success'
            ]);

            return $this->successResponse(
                null,
                'Attachment deleted successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== DELETE ATTACHMENT END ===', [
                'job_id' => $id,
                'attachment_id' => $attachmentId,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to delete attachment.',
                500
            );
        }
    }

    /**
     * Get job statistics
     */
    public function statistics(): JsonResponse
    {
        try {
            Log::info('=== GET JOB STATISTICS START ===');

            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                // Return empty statistics instead of error
                return $this->successResponse(
                    [
                        'total' => 0,
                        'by_status' => [],
                        'by_priority' => [],
                        'upcoming' => 0,
                        'overdue' => 0,
                        'total_revenue' => 0,
                        'pending_payment' => 0,
                    ],
                    'Job statistics retrieved successfully.'
                );
            }

            $statistics = $this->JobQueryService->getJobStatistics();

            Log::info('=== GET JOB STATISTICS END ===', [
                'status' => 'success'
            ]);

            return $this->successResponse(
                $statistics,
                'Job statistics retrieved successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== GET JOB STATISTICS END ===', [
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve job statistics.',
                500
            );
        }
    }

    /**
     * Assign a job to an employee
     */
    public function assignJob(Request $request, $id): JsonResponse
    {
        try {
            $request->validate([
                'employee_id' => 'required|exists:employees,id',
                'shift' => 'required|string|max:50',
            ]);

            $user = auth()->user();
            $vendorId = $user->vendor_id;

            $job = \App\Models\Job::where('id', $id)
                ->where('vendor_id', $vendorId)
                ->firstOrFail();

            $employee = Employee::where('id', $request->employee_id)
                ->where('vendor_id', $vendorId)
                ->firstOrFail();

            $assignment = JobAssignment::create([
                'job_id' => $job->id,
                'employee_id' => $employee->id,
                'shift' => $request->shift,
                'assigned_at' => now(),
            ]);

            // Log activity
            JobActivity::create([
                'job_id' => $job->id,
                'type' => 'assignment',
                'subject' => 'Job Assigned',
                'content' => "Job {$job->job_number} assigned to {$employee->first_name} {$employee->last_name} ({$request->shift} shift)",
                'metadata' => [
                    'employee_id' => $employee->id,
                    'employee_name' => "{$employee->first_name} {$employee->last_name}",
                    'shift' => $request->shift,
                ],
                'performed_by' => $user->id,
            ]);

            NotificationHelper::jobAssigned($job, $employee, auth()->id());
            return $this->successResponse(
                $assignment->load('employee'),
                'Job assigned successfully.'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Job or employee not found.', 404);
        } catch (\Exception $e) {
            Log::error('Failed to assign job', [
                'job_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to assign job. Please try again.',
                500
            );
        }
    }
}
