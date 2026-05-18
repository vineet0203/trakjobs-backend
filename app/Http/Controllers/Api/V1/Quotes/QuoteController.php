<?php

namespace App\Http\Controllers\Api\V1\Quotes;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Api\V1\Quotes\CreateQuoteRequest;
use App\Http\Requests\Api\V1\Quotes\UpdateQuoteRequest;
use App\Http\Requests\Api\V1\Quotes\GetQuotesRequest;
use App\Http\Resources\Api\V1\Job\JobResource;
use App\Http\Resources\Api\V1\Quote\QuoteCollection;
use App\Http\Resources\Api\V1\Quote\QuoteResource;
use App\Services\Jobs\JobCreationService;
use App\Services\Quotes\QuoteCreationService;
use App\Services\Quotes\QuoteQueryService;
use App\Services\Quotes\QuoteUpdateService;
use App\Services\Quotes\QuoteDeletionService;
use App\Traits\ApiResponse;
use App\Helpers\NotificationHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class QuoteController extends BaseController
{
    use ApiResponse;

    private QuoteCreationService $quoteCreationService;
    private QuoteQueryService $quoteQueryService;
    private QuoteUpdateService $quoteUpdateService;
    private QuoteDeletionService $quoteDeletionService;

    public function __construct(
        QuoteCreationService $quoteCreationService,
        QuoteQueryService $quoteQueryService,
        QuoteUpdateService $quoteUpdateService,
        QuoteDeletionService $quoteDeletionService
    ) {
        $this->quoteCreationService = $quoteCreationService;
        $this->quoteQueryService = $quoteQueryService;
        $this->quoteUpdateService = $quoteUpdateService;
        $this->quoteDeletionService = $quoteDeletionService;
    }

    /**
     * Create a new quote
     */
    public function store(CreateQuoteRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();

            // Fetch client email for duplicate check
            $client = \App\Models\Client::find($validatedData['client_id']);

            Log::info('=== CREATE QUOTE START ===', [
                'client_id' => $validatedData['client_id'],
                'client_email' => $client->email ?? 'not found',
                'title' => $validatedData['title'],
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            // Check for duplicate quote using client email from database
            $isDuplicate = $this->quoteCreationService->validateDuplicateQuote(
                $client->email,
                $validatedData['title']
            );

            if ($isDuplicate) {
                Log::warning('Duplicate quote detected', [
                    'client_id' => $validatedData['client_id'],
                    'client_email' => $client->email,
                    'title' => $validatedData['title'],
                ]);

                return $this->errorResponse(
                    'A similar quote already exists for this client.',
                    409
                );
            }

            $quote = $this->quoteCreationService->create($validatedData, auth()->id());

            Log::info('=== CREATE QUOTE END ===', [
                'quote_id' => $quote->id,
                'quote_number' => $quote->quote_number,
                'status' => 'success'
            ]);

            NotificationHelper::quoteCreated($quote, auth()->id());
            return $this->createdResponse(
                new QuoteResource($quote),
                'Quote created successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== CREATE QUOTE END ===', [
                'status' => 'error',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'client_id' => $request->client_id,
            ]);

            return $this->errorResponse(
                'Failed to create quote. Please try again.',
                500
            );
        }
    }

    /**
     * Get all quotes with filtering and pagination
     */
    /**
     * Get all quotes with filtering and pagination - only for authenticated vendor
     */
    public function index(GetQuotesRequest $request): JsonResponse
    {
        try {
            Log::info('=== GET QUOTES START ===', [
                'filters' => $request->all(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
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

            // Add vendor_id to filters to restrict quotes
            $validated['vendor_id'] = $vendorId;

            $quotes = $this->quoteQueryService->getQuotes($validated, $validated['per_page'] ?? 15);

            $appliedFilters = $this->quoteQueryService->getAppliedFilters($validated);

            Log::info('=== GET QUOTES END ===', [
                'total_quotes' => $quotes->total(),
                'current_page' => $quotes->currentPage(),
                'vendor_id' => $vendorId,
                'status' => 'success'
            ]);

            return $this->successResponse(
                new QuoteCollection($quotes),
                'Quotes retrieved successfully.',
                200,
                [
                    'filters' => $appliedFilters,
                    'pagination' => [
                        'total' => $quotes->total(),
                        'per_page' => $quotes->perPage(),
                        'current_page' => $quotes->currentPage(),
                        'last_page' => $quotes->lastPage(),
                    ]
                ]
            );
        } catch (\Exception $e) {
            Log::error('=== GET QUOTES END ===', [
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve quotes. Please try again.',
                500
            );
        }
    }

    /**
     * Get a single quote by ID
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

            Log::info('=== GET QUOTE START ===', [
                'quote_id' => $id,
                'vendor_id' => $vendorId
            ]);

            $quote = $this->quoteQueryService->getQuote($id);

            if (!$quote) {
                return $this->notFoundResponse('Quote not found.');
            }

            // Double-check that the quote belongs to this vendor
            if ($quote->vendor_id !== $vendorId) {
                Log::warning('Vendor ID mismatch', [
                    'quote_vendor_id' => $quote->vendor_id,
                    'user_vendor_id' => $vendorId
                ]);
                return $this->notFoundResponse('Quote not found.');
            }

            Log::info('=== GET QUOTE END ===', [
                'quote_id' => $quote->id,
                'quote_number' => $quote->quote_number,
                'status' => 'success'
            ]);

            return $this->successResponse(
                new QuoteResource($quote),
                'Quote retrieved successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== GET QUOTE END ===', [
                'quote_id' => $id,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve quote. Please try again.',
                500
            );
        }
    }

    /**
     * Get a quote by quote number
     */
    public function showByNumber(string $quoteNumber): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            Log::info('=== GET QUOTE BY NUMBER START ===', [
                'quote_number' => $quoteNumber,
                'vendor_id' => $vendorId
            ]);

            $quote = $this->quoteQueryService->getQuoteByNumber($quoteNumber);

            if (!$quote) {
                return $this->notFoundResponse('Quote not found.');
            }

            // Double-check vendor_id
            if ($quote->vendor_id !== $vendorId) {
                return $this->notFoundResponse('Quote not found.');
            }

            Log::info('=== GET QUOTE BY NUMBER END ===', [
                'quote_id' => $quote->id,
                'quote_number' => $quote->quote_number,
                'status' => 'success'
            ]);

            return $this->successResponse(
                new QuoteResource($quote),
                'Quote retrieved successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== GET QUOTE BY NUMBER END ===', [
                'quote_number' => $quoteNumber,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve quote. Please try again.',
                500
            );
        }
    }

    /**
     * Update a quote
     */
    public function update(UpdateQuoteRequest $request, int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                return $this->errorResponse(
                    'Authenticated user is not associated with a vendor.',
                    403
                );
            }

            Log::info('=== UPDATE QUOTE START ===', [
                'quote_id' => $id,
                'vendor_id' => $vendorId,
                'updates' => array_keys($request->all()),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            $quote = $this->quoteQueryService->getQuote($id);

            if (!$quote) {
                return $this->notFoundResponse('Quote not found.');
            }

            // Verify quote belongs to this vendor
            if ($quote->vendor_id !== $vendorId) {
                Log::warning('Unauthorized update attempt', [
                    'quote_id' => $id,
                    'quote_vendor_id' => $quote->vendor_id,
                    'user_vendor_id' => $vendorId
                ]);
                return $this->notFoundResponse('Quote not found.');
            }

            $validatedData = $request->validated();
            if ($request->has('items')) {
                $validatedData['items'] = $request->input('items');
            } elseif ($request->has('line_items')) {
                $validatedData['items'] = $request->input('line_items');
            }

            $quote = $this->quoteUpdateService->update($quote, $validatedData, auth()->id());

            Log::info('=== UPDATE QUOTE END ===', [
                'quote_id' => $quote->id,
                'quote_number' => $quote->quote_number,
                'status' => 'success'
            ]);

            return $this->successResponse(
                new QuoteResource($quote),
                'Quote updated successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== UPDATE QUOTE END ===', [
                'quote_id' => $id,
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
     * Send a quote to client
     */
    public function send(int $id): JsonResponse
    {
        try {
            Log::info('=== SEND QUOTE START ===', [
                'quote_id' => $id,
                'sent_by' => auth()->id(),
            ]);

            $quote = $this->quoteQueryService->getQuote($id);

            if (!$quote) {
                return $this->notFoundResponse('Quote not found.');
            }

            $quote = $this->quoteCreationService->sendQuote($quote, auth()->id());

            Log::info('=== SEND QUOTE END ===', [
                'quote_id' => $quote->id,
                'quote_number' => $quote->quote_number,
                'status' => 'success'
            ]);

            return $this->successResponse(
                new QuoteResource($quote),
                'Quote sent successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== SEND QUOTE END ===', [
                'quote_id' => $id,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                $e->getMessage(),
                400
            );
        }
    }

    /**
     * Delete/soft delete a quote
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            Log::info('=== DELETE QUOTE START ===', [
                'quote_id' => $id,
                'deleted_by' => auth()->id(),
            ]);

            $quote = $this->quoteQueryService->getQuote($id);

            if (!$quote) {
                return $this->notFoundResponse('Quote not found.');
            }

            // Check if quote can be deleted
            $canDelete = $this->quoteDeletionService->canDelete($quote);

            if (!$canDelete['can_delete']) {
                return $this->errorResponse(
                    $canDelete['message'],
                    409
                );
            }

            $this->quoteDeletionService->softDelete($quote, auth()->id());

            Log::info('=== DELETE QUOTE END ===', [
                'quote_id' => $id,
                'status' => 'success'
            ]);

            return $this->successResponse(
                null,
                'Quote deleted successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== DELETE QUOTE END ===', [
                'quote_id' => $id,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to delete quote. Please try again.',
                500
            );
        }
    }

    /**
     * Get quote statistics
     */
    public function statistics(): JsonResponse
    {
        try {
            Log::info('=== GET QUOTE STATISTICS START ===');

            $user = auth()->user();
            $vendorId = $user->vendor_id;

            if (!$vendorId) {
                // Return empty statistics instead of error
                return $this->successResponse(
                    [
                        'total' => 0,
                        'draft' => 0,
                        'sent' => 0,
                        'pending' => 0,
                        'approved' => 0,
                        'rejected' => 0,
                        'expired' => 0,
                        'total_amount' => 0,
                        'by_month' => [],
                    ],
                    'Quote statistics retrieved successfully.'
                );
            }

            $statistics = $this->quoteQueryService->getQuoteStatistics();

            Log::info('=== GET QUOTE STATISTICS END ===', [
                'status' => 'success',
                'vendor_id' => $vendorId
            ]);

            return $this->successResponse(
                $statistics,
                'Quote statistics retrieved successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== GET QUOTE STATISTICS END ===', [
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve quote statistics.',
                500
            );
        }
    }

    /**
     * Update follow-up status
     */
    public function updateFollowUpStatus(int $id): JsonResponse
    {
        try {
            Log::info('=== UPDATE FOLLOW-UP STATUS START ===', [
                'quote_id' => $id,
                'status' => request('status'),
            ]);

            $quote = $this->quoteQueryService->getQuote($id);

            if (!$quote) {
                return $this->notFoundResponse('Quote not found.');
            }

            $status = request()->validate([
                'status' => 'required|in:scheduled,completed,cancelled',
            ])['status'];

            $quote = $this->quoteUpdateService->updateFollowUpStatus($quote, $status, auth()->id());

            Log::info('=== UPDATE FOLLOW-UP STATUS END ===', [
                'quote_id' => $quote->id,
                'status' => $status,
                'success' => 'success'
            ]);

            return $this->successResponse(
                new QuoteResource($quote),
                'Follow-up status updated successfully.'
            );
        } catch (\Exception $e) {
            Log::error('=== UPDATE FOLLOW-UP STATUS END ===', [
                'quote_id' => $id,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to update follow-up status.',
                500
            );
        }
    }

    /**
     * Convert quote to job manually (For testing Purpose Only)
     */
    public function convertToJob(int $id): JsonResponse
    {
        try {
            Log::info('=== MANUAL QUOTE TO JOB CONVERSION START ===', [
                'quote_id' => $id,
                'converted_by' => auth()->id(),
            ]);

            $quote = $this->quoteQueryService->getQuote($id);

            if (!$quote) {
                return $this->notFoundResponse('Quote not found.');
            }

            if (!$quote->canBeConvertedToJob()) {
                return $this->errorResponse(
                    'Quote cannot be converted to job. Check if it is approved and not already converted.',
                    400
                );
            }

            $Job = app(JobCreationService::class)
                ->convertFromQuote($quote->id, auth()->id());

            Log::info('=== MANUAL QUOTE TO JOB CONVERSION END ===', [
                'quote_id' => $quote->id,
                'job_id' => $Job->id,
                'status' => 'success'
            ]);

            return $this->successResponse(
                [
                    'quote' => new QuoteResource($quote->fresh()),
                    'job' => new JobResource($Job),
                ],
                'Quote successfully converted to work order.'
            );
        } catch (\Exception $e) {
            Log::error('=== MANUAL QUOTE TO JOB CONVERSION END ===', [
                'quote_id' => $id,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to convert quote to job: ' . $e->getMessage(),
                500
            );
        }
    }
}
