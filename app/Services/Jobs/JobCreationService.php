<?php
// app/Services/Jobs/JobCreationService.php

namespace App\Services\Jobs;

use App\Models\Client;
use App\Models\Job;
use App\Models\JobTask;
use App\Models\JobActivity;
use App\Models\Quote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JobCreationService
{
    /**
     * Create a new job with tasks
     */
    public function create(array $data, int $createdBy): Job
    {
        DB::beginTransaction();

        try {
            // Fetch client details
            $client = Client::findOrFail($data['client_id']);

            // Generate job number
            $JobNumber = Job::generateJobNumber();

            // Calculate balance due
            $balanceDue = ($data['total_amount'] ?? 0) - ($data['paid_amount'] ?? 0);

            // Create job
            $Job = Job::create([
                'job_number' => $JobNumber,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'vendor_id' => $data['vendor_id'] ?? auth()->user()->vendor_id ?? null,
                'client_id' => $data['client_id'],
                'quote_id' => $data['quote_id'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? null,
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
                'work_type' => $data['work_type'],
                'priority' => $data['priority'],
                'status' => $data['status'] ?? 'pending',
                'issue_date' => $data['issue_date'],
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'estimated_completion_date' => $data['estimated_completion_date'] ?? null,
                'currency' => $data['currency'],
                'estimated_amount' => $data['estimated_amount'] ?? 0,
                'total_amount' => $data['total_amount'] ?? 0,
                'deposit_amount' => $data['deposit_amount'] ?? 0,
                'paid_amount' => $data['paid_amount'] ?? 0,
                'balance_due' => $balanceDue,
                'location_type' => $data['location_type'] ?? null,
                'address_line_1' => $data['address_line_1'] ?? null,
                'address_line_2' => $data['address_line_2'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'country' => $data['country'] ?? null,
                'zip_code' => $data['zip_code'] ?? null,
                'instructions' => $data['instructions'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_converted_from_quote' => $data['is_converted_from_quote'] ?? false,
                'converted_at' => isset($data['quote_id']) ? now() : null,
                'converted_by' => isset($data['quote_id']) ? $createdBy : null,
            ]);

            // Create tasks if provided
            if (!empty($data['tasks'])) {
                $this->createTasks($Job, $data['tasks'], $createdBy);
            }

            // Create attachments if provided
            if (!empty($data['attachments'])) {
                $this->createAttachments($Job, $data['attachments'], $createdBy);
            }

            // Log activity
            $this->logActivity($Job, 'created', 'Job created', $createdBy);

            DB::commit();

            Log::info('Job created successfully', [
                'job_id' => $Job->id,
                'job_number' => $Job->job_number,
                'client_id' => $Job->client_id,
                'created_by' => $createdBy,
            ]);

            return $Job;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create job', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Create tasks for job
     */
    private function createTasks(Job $Job, array $tasks, int $createdBy): void
    {
        $sortOrder = 0;

        foreach ($tasks as $taskData) {
            $Job->tasks()->create([
                'name' => $taskData['name'],
                'description' => $taskData['description'] ?? null,
                'due_date' => $taskData['due_date'] ?? null,
                'sort_order' => $sortOrder++,
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
            ]);
        }

        Log::info('Tasks created for job', [
            'job_id' => $Job->id,
            'tasks_count' => count($tasks),
        ]);
    }

    /**
     * Create attachments for job
     */
    private function createAttachments(Job $Job, array $attachments, int $uploadedBy): void
    {
        foreach ($attachments as $attachmentData) {
            $Job->attachments()->create([
                'file_name' => $attachmentData['file_name'],
                'file_path' => $attachmentData['file_path'],
                'file_type' => $attachmentData['file_type'],
                'mime_type' => $attachmentData['mime_type'] ?? null,
                'file_size' => $attachmentData['file_size'] ?? 0,
                'disk' => $attachmentData['disk'] ?? 'public',
                'metadata' => $attachmentData['metadata'] ?? null,
                'uploaded_by' => $uploadedBy,
            ]);
        }

        Log::info('Attachments created for job', [
            'job_id' => $Job->id,
            'attachments_count' => count($attachments),
        ]);
    }

    /**
     * Log activity
     */
    private function logActivity(Job $Job, string $type, string $description, int $performedBy): void
    {
        $Job->activities()->create([
            'type' => $type,
            'subject' => 'Job Created',
            'content' => $description,
            'performed_by' => $performedBy,
        ]);
    }

    /**
     * Convert quote to job automatically
     */
    public function convertFromQuote(int $quoteId, int $convertedBy): Job
    {
        $quote = Quote::with(['client', 'items', 'client.vendor'])->findOrFail($quoteId);

        $user = auth()->user();
        if ($user && isset($user->vendor_id) && $user->vendor_id) {
            if ($quote->client->vendor_id !== $user->vendor_id) {
                throw new \Exception('Quote does not belong to your vendor account.');
            }
        }

        if (!$quote->can_convert_to_job) {
            throw new \Exception('This quote cannot be converted to a job.');
        }

        if ($quote->is_converted) {
            throw new \Exception('This quote has already been converted to a job.');
        }

        DB::beginTransaction();

        try {
            // Prepare job data from quote
            $data = [
                'title' => $quote->title,
                'description' => "Auto-generated from approved quote #{$quote->quote_number}",
                'client_id' => $quote->client_id,
                'quote_id' => $quote->id,
                'work_type' => 'one_time',
                'priority' => 'medium',
                'issue_date' => now()->toDateString(),
                'start_date' => now()->addDay()->toDateString(), // Start tomorrow by default
                'currency' => $quote->currency,
                'estimated_amount' => $quote->total_amount,
                'total_amount' => $quote->total_amount,
                'deposit_amount' => $quote->deposit_amount ?? 0,
                'notes' => $quote->notes,
                'is_converted_from_quote' => true,
                'vendor_id' => $quote->vendor_id,
            ];

            // Create job (job)
            $Job = $this->create($data, $convertedBy);

            // Copy quote images to job attachments
            if (!empty($quote->images)) {
                foreach ($quote->images as $imagePath) {
                    $Job->attachments()->create([
                        'file_name' => basename($imagePath),
                        'file_path' => $imagePath,
                        'file_type' => 'image',
                        'mime_type' => 'image/' . pathinfo($imagePath, PATHINFO_EXTENSION),
                        'file_size' => \Illuminate\Support\Facades\Storage::disk('local')->exists($imagePath) ? \Illuminate\Support\Facades\Storage::disk('local')->size($imagePath) : 0,
                        'disk' => 'local',
                        'uploaded_by' => $convertedBy,
                    ]);
                }
            }

            // Mark quote as converted
            $quote->update([
                'is_converted' => true,
                'job_id' => $Job->id,
                'converted_at' => now(),
                'converted_by' => $convertedBy,
                'status' => 'accepted', // Ensure quote status is updated
            ]);

            // Log the automatic conversion
            Log::info('Quote automatically converted to job', [
                'quote_id' => $quote->id,
                'quote_number' => $quote->quote_number,
                'job_id' => $Job->id,
                'job_number' => $Job->job_number,
                'converted_by' => $convertedBy,
                'tasks_created' => 0,
            ]);

            DB::commit();

            return $Job->load(['client', 'tasks']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to auto-convert quote to job', [
                'quote_id' => $quoteId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
