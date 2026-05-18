<?php

namespace App\Http\Controllers\Api\V1\Invoices;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\Api\V1\Invoice\InvoiceCollection;
use App\Http\Resources\Api\V1\Invoice\InvoiceResource;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoicePublicLink;
use App\Notifications\InvoicePublicLinkNotification;
use App\Helpers\NotificationHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InvoiceController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user?->vendor_id) {
            return $this->errorResponse('Authenticated user is not associated with a vendor.', 403);
        }

        if (!$this->hasInvoicePermission($user, ['view_invoices', 'view_invoice'])) {
            return $this->forbiddenResponse('You do not have permission to view invoices.');
        }

        $perPage = (int) $request->input('per_page', 15);

        $query = Invoice::with(['employee', 'client', 'items'])
            ->whereHas('employee', function ($employeeQuery) use ($user) {
                $employeeQuery->where('vendor_id', $user->vendor_id);
            })
            ->orderByDesc('id');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', (int) $request->input('employee_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $invoices = $query->paginate($perPage);

        return $this->successResponse(new InvoiceCollection($invoices), 'Invoices retrieved successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user?->vendor_id) {
            return $this->errorResponse('Authenticated user is not associated with a vendor.', 403);
        }

        if (!$this->hasInvoicePermission($user, ['create_invoices', 'create_invoice'])) {
            return $this->forbiddenResponse('You do not have permission to create invoices.');
        }

        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'client_id' => 'nullable|exists:clients,id',
            'bill_date' => 'required|date',
            'delivery_date' => 'nullable|date',
            'payment_deadline' => 'nullable|date',
            'mileage' => 'nullable|numeric|min:0',
            'other_expense' => 'nullable|numeric|min:0',
            'note' => 'nullable|string',
            'terms_conditions' => 'nullable|string',
            'billing_address' => 'nullable|array',
            'status' => 'nullable|in:draft,sent,paid,cancelled',
            'items' => 'required|array|min:1',
            'items.*.job_id' => 'nullable|exists:jobs,id',
            'items.*.job_name' => 'required|string|max:255',
            'items.*.mileage' => 'nullable|numeric|min:0',
            'items.*.other_expense' => 'nullable|numeric|min:0',
            'items.*.amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed.', 400, $validator->errors());
        }

        $validated = $validator->validated();

        $employee = Employee::where('id', $validated['employee_id'])
            ->where('vendor_id', $user->vendor_id)
            ->first();

        if (!$employee) {
            return $this->errorResponse('Selected employee does not belong to your vendor.', 422);
        }

        foreach ($validated['items'] as $item) {
            if (!empty($item['job_id'])) {
                // Check if job exists and belongs to vendor
                $job = DB::table('jobs')
                    ->where('id', $item['job_id'])
                    ->where('vendor_id', $user->vendor_id)
                    ->first();

                if (!$job) {
                    return $this->errorResponse('One or more jobs do not belong to your vendor.', 422);
                }

                // Check if an invoice already exists for this job (Direct DB check for production stability)
                $alreadyInvoiced = DB::table('invoice_items')
                    ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                    ->where('invoice_items.job_id', $item['job_id'])
                    ->exists();

                if ($alreadyInvoiced) {
                    return $this->errorResponse("Invoice already exists for Job: {$job->job_number}", 422);
                }
            }
        }

        $invoice = DB::transaction(function () use ($validated) {
            $invoice = Invoice::create([
                'employee_id' => $validated['employee_id'],
                'client_id' => $validated['client_id'] ?? null,
                'bill_date' => $validated['bill_date'],
                'delivery_date' => $validated['delivery_date'] ?? null,
                'payment_deadline' => $validated['payment_deadline'] ?? null,
                'mileage' => $validated['mileage'] ?? 0,
                'other_expense' => $validated['other_expense'] ?? 0,
                'note' => $validated['note'] ?? null,
                'terms_conditions' => $validated['terms_conditions'] ?? null,
                'billing_address' => $validated['billing_address'] ?? null,
                'status' => $validated['status'] ?? 'draft',
            ]);

            $items = array_map(function (array $item) {
                $amount = round((float) ($item['amount'] ?? 0), 2);
                $mileage = round((float) ($item['mileage'] ?? 0), 2);
                $otherExpense = round((float) ($item['other_expense'] ?? 0), 2);

                return [
                    'job_id' => $item['job_id'] ?? null,
                    'job_name' => $item['job_name'],
                    'mileage' => $mileage,
                    'other_expense' => $otherExpense,
                    'amount' => $amount,
                    'final_amount' => round($amount + $mileage + $otherExpense, 2),
                ];
            }, $validated['items']);

            $invoice->items()->createMany($items);

            return $invoice;
        });

        $invoice->load(['employee', 'client', 'items']);

        NotificationHelper::invoiceCreated($invoice, auth()->id());
        return $this->createdResponse(new InvoiceResource($invoice), 'Invoice created successfully.');
    }

    public function show(int $id): JsonResponse
    {
        $user = auth()->user();
        if (!$user?->vendor_id) {
            return $this->errorResponse('Authenticated user is not associated with a vendor.', 403);
        }

        if (!$this->hasInvoicePermission($user, ['view_invoices', 'view_invoice'])) {
            return $this->forbiddenResponse('You do not have permission to view invoices.');
        }

        $invoice = Invoice::with(['employee', 'client', 'items'])
            ->where('id', $id)
            ->whereHas('employee', function ($employeeQuery) use ($user) {
                $employeeQuery->where('vendor_id', $user->vendor_id);
            })
            ->first();

        if (!$invoice) {
            return $this->notFoundResponse('Invoice not found.');
        }

        return $this->successResponse(new InvoiceResource($invoice), 'Invoice retrieved successfully.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();
        if (!$user?->vendor_id) {
            return $this->errorResponse('Authenticated user is not associated with a vendor.', 403);
        }

        if (!$this->hasInvoicePermission($user, ['edit_invoices', 'edit_invoice'])) {
            return $this->forbiddenResponse('You do not have permission to edit invoices.');
        }

        $invoice = Invoice::with(['employee', 'client', 'items'])
            ->where('id', $id)
            ->whereHas('employee', function ($employeeQuery) use ($user) {
                $employeeQuery->where('vendor_id', $user->vendor_id);
            })
            ->first();

        if (!$invoice) {
            return $this->notFoundResponse('Invoice not found.');
        }

        $validator = Validator::make($request->all(), [
            'employee_id' => 'sometimes|exists:employees,id',
            'client_id' => 'nullable|exists:clients,id',
            'bill_date' => 'sometimes|date',
            'delivery_date' => 'nullable|date',
            'payment_deadline' => 'nullable|date',
            'mileage' => 'nullable|numeric|min:0',
            'other_expense' => 'nullable|numeric|min:0',
            'note' => 'nullable|string',
            'terms_conditions' => 'nullable|string',
            'billing_address' => 'nullable|array',
            'status' => 'nullable|in:draft,sent,paid,cancelled',
            'items' => 'sometimes|array|min:1',
            'items.*.job_id' => 'nullable|exists:jobs,id',
            'items.*.job_name' => 'required_with:items|string|max:255',
            'items.*.mileage' => 'nullable|numeric|min:0',
            'items.*.other_expense' => 'nullable|numeric|min:0',
            'items.*.amount' => 'required_with:items|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed.', 400, $validator->errors());
        }

        $validated = $validator->validated();

        if (isset($validated['employee_id'])) {
            $employee = Employee::where('id', $validated['employee_id'])
                ->where('vendor_id', $user->vendor_id)
                ->first();

            if (!$employee) {
                return $this->errorResponse('Selected employee does not belong to your vendor.', 422);
            }
        }

        if (isset($validated['items'])) {
            foreach ($validated['items'] as $item) {
                if (!empty($item['job_id'])) {
                    $jobBelongsToVendor = DB::table('jobs')
                        ->where('id', $item['job_id'])
                        ->where('vendor_id', $user->vendor_id)
                        ->exists();

                    if (!$jobBelongsToVendor) {
                        return $this->errorResponse('One or more jobs do not belong to your vendor.', 422);
                    }
                }
            }
        }

        $invoice = DB::transaction(function () use ($invoice, $validated) {
            $invoice->update([
                'employee_id' => $validated['employee_id'] ?? $invoice->employee_id,
                'client_id' => array_key_exists('client_id', $validated) ? $validated['client_id'] : $invoice->client_id,
                'bill_date' => $validated['bill_date'] ?? $invoice->bill_date,
                'delivery_date' => array_key_exists('delivery_date', $validated) ? $validated['delivery_date'] : $invoice->delivery_date,
                'payment_deadline' => array_key_exists('payment_deadline', $validated) ? $validated['payment_deadline'] : $invoice->payment_deadline,
                'mileage' => $validated['mileage'] ?? $invoice->mileage,
                'other_expense' => $validated['other_expense'] ?? $invoice->other_expense,
                'note' => array_key_exists('note', $validated) ? $validated['note'] : $invoice->note,
                'terms_conditions' => array_key_exists('terms_conditions', $validated) ? $validated['terms_conditions'] : $invoice->terms_conditions,
                'billing_address' => array_key_exists('billing_address', $validated) ? $validated['billing_address'] : $invoice->billing_address,
                'status' => $validated['status'] ?? $invoice->status,
            ]);

            if (isset($validated['items'])) {
                $items = array_map(function (array $item) {
                    $amount = round((float) ($item['amount'] ?? 0), 2);
                    $mileage = round((float) ($item['mileage'] ?? 0), 2);
                    $otherExpense = round((float) ($item['other_expense'] ?? 0), 2);

                    return [
                        'job_id' => $item['job_id'] ?? null,
                        'job_name' => $item['job_name'],
                        'mileage' => $mileage,
                        'other_expense' => $otherExpense,
                        'amount' => $amount,
                        'final_amount' => round($amount + $mileage + $otherExpense, 2),
                    ];
                }, $validated['items']);

                $invoice->items()->delete();
                $invoice->items()->createMany($items);
            }

            return $invoice;
        });

        $invoice->load(['employee', 'client', 'items']);

        return $this->successResponse(new InvoiceResource($invoice), 'Invoice updated successfully.');
    }

    public function destroy(int $id): JsonResponse
    {
        $user = auth()->user();
        if (!$user?->vendor_id) {
            return $this->errorResponse('Authenticated user is not associated with a vendor.', 403);
        }

        if (!$this->hasInvoicePermission($user, ['edit_invoices', 'edit_invoice'])) {
            return $this->forbiddenResponse('You do not have permission to delete invoices.');
        }

        $invoice = Invoice::where('id', $id)
            ->whereHas('employee', function ($employeeQuery) use ($user) {
                $employeeQuery->where('vendor_id', $user->vendor_id);
            })
            ->first();

        if (!$invoice) {
            return $this->notFoundResponse('Invoice not found.');
        }

        DB::transaction(function () use ($invoice) {
            $invoice->items()->delete();
            $invoice->publicLinks()->delete();
            $invoice->delete();
        });

        return $this->successResponse(null, 'Invoice deleted successfully.');
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();
        if (!$user?->vendor_id) {
            return $this->errorResponse('Authenticated user is not associated with a vendor.', 403);
        }

        if (!$this->hasInvoicePermission($user, ['send_invoices', 'send_invoice'])) {
            return $this->forbiddenResponse('You do not have permission to send invoices.');
        }

        $validator = Validator::make($request->all(), [
            'email' => 'required|email:rfc,dns|max:255',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed.', 400, $validator->errors());
        }

        $invoice = Invoice::with(['employee', 'items'])
            ->where('id', $id)
            ->whereHas('employee', function ($employeeQuery) use ($user) {
                $employeeQuery->where('vendor_id', $user->vendor_id);
            })
            ->first();

        if (!$invoice) {
            return $this->notFoundResponse('Invoice not found.');
        }

        $email = $validator->validated()['email'];
        $token = Str::random(64);
        $expiresAt = now()->addDays(7);

        InvoicePublicLink::where('invoice_id', $invoice->id)
            ->where('recipient_email', $email)
            ->delete();

        $publicLink = InvoicePublicLink::create([
            'invoice_id' => $invoice->id,
            'token' => $token,
            'recipient_email' => $email,
            'expires_at' => $expiresAt,
            'sent_at' => now(),
            'created_by' => $user->id,
        ]);

        $publicUrl = rtrim(config('app.url'), '/') . '/invoice/public/' . $publicLink->token;

        $customer = \App\Models\Customer::where('email', $email)->first();
        if ($customer) {
            $invoice->customer_id = $customer->id;
        }
        $invoice->status = 'sent';
        $invoice->customer_status = null;
        $invoice->save();

        Notification::route('mail', $email)->notify(
            new InvoicePublicLinkNotification(
                $invoice,
                $publicUrl,
                $expiresAt->toDateTimeString(),
            ),
        );

        NotificationHelper::invoiceSent($invoice, auth()->id());
        return $this->successResponse([
            'sent_to' => $email,
            'expires_at' => \$expiresAt->toIso8601String(),
        ], 'Invoice link sent successfully.');
    }

    public function publicView(string $token): View
    {
        $link = InvoicePublicLink::where('token', $token)->first();

        if (!$link || ($link->expires_at && $link->expires_at->isPast())) {
            abort(404);
        }

        $invoice = Invoice::with(['employee', 'items'])->find($link->invoice_id);

        if (!$invoice) {
            abort(404);
        }

        return view('invoices.public', [
            'invoice' => $invoice,
            'publicLink' => $link,
        ]);
    }

    private function hasInvoicePermission($user, array $actions): bool
    {
        foreach ($actions as $action) {
            if ($user->canDo($action)) {
                return true;
            }
        }

        return false;
    }
}
