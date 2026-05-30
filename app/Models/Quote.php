<?php

namespace App\Models;

use App\Services\Jobs\JobCreationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quote extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'quote_number',
        'title',
        'client_id',
        'customer_id',
        'client_name',
        'client_email',
        'equity_status',
        'quote_due_date',
        'currency',
        'subtotal',
        'discount',
        'is_tax_applicable',
        'tax_percentage',
        'total_amount',
        'customer_approved_price',
        'deposit_required',
        'deposit_type',
        'deposit_amount',
        'approval_status',
        'client_signature',
        'customer_signature',
        'approval_date',
        'approval_action_date',
        'status',
        'sent_at',
        'can_convert_to_job',
        'is_converted',
        'job_id',
        'converted_at',
        'follow_up_at',
        'reminder_type',
        'follow_up_status',
        'expires_at',
        'notes',
        'vendor_id',
        'created_by',
        'updated_by',
        'converted_by',
        'images',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'is_tax_applicable' => 'boolean',
        'tax_percentage' => 'integer',
        'total_amount' => 'decimal:2',
        'customer_approved_price' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'deposit_required' => 'boolean',
        'can_convert_to_job' => 'boolean',
        'is_converted' => 'boolean',
        'approved_at' => 'datetime',
        'approval_date' => 'datetime',
        'approval_action_date' => 'datetime',
        'sent_at' => 'datetime',
        'follow_up_at' => 'datetime',
        'expires_at' => 'datetime',
        'converted_at' => 'datetime',
        'quote_due_date' => 'date',
        'images' => 'array',
    ];


    /**
     * Relationship with vendor
     */
    public function vendor()
    {
        return $this->belongsTo(User::class, 'vendor_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function reminders()
    {
        return $this->hasMany(QuoteReminder::class);
    }


    /**
     * Relationship with quote items
     */
    public function items()
    {
        return $this->hasMany(QuoteItem::class);
    }

    /**
     * Relationship with creator
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Relationship with updater
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function converter()
    {
        return $this->belongsTo(User::class, 'converted_by');
    }

    public function canBeConverted(): bool
    {
        return $this->can_convert_to_job &&
            !$this->is_converted &&
            $this->approval_status === 'accepted';
    }

    /**
     * Scope for active quotes (draft or pending customer approval)
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['draft', 'pending']);
    }

    /**
     * Scope for pending approval
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for approved quotes
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Check if quote can be edited (only in draft, not after sent to customer)
     */
    public function canBeEdited(): bool
    {
        return in_array($this->status, ['draft', 'pending', 'rejected']);
    }

    /**
     * Check if quote can be sent
     */
    public function canBeSent(): bool
    {
        return $this->status === 'draft' && $this->items()->count() > 0;
    }

    /**
     * Calculate totals from items
     */
    /**
     * Calculate totals from items
     */
    public function calculateTotals(): self
    {
        $subtotal = $this->items->sum(function ($item) {
            return $item->quantity * $item->unit_price;
        });

        $total = $this->items->sum(function ($item) {
            $subtotal = $item->quantity * $item->unit_price;
            $tax = $subtotal * ($item->tax_rate / 100);
            return $subtotal + $tax;
        });

        $totalAmount = $total - ($this->discount ?? 0);

        $updateData = [
            'subtotal' => $subtotal,
            'total_amount' => $totalAmount,
        ];

        // Handle deposit amount based on type
        if ($this->deposit_required) {
            if ($this->deposit_type === 'percentage') {
                // If deposit_amount is stored as a percentage (e.g., 5 for 5%)
                // Keep it as is - don't recalculate
                // The frontend will calculate the actual amount for display
            } else {
                // For fixed amount, ensure it doesn't exceed total
                if ($this->deposit_amount > $totalAmount) {
                    $updateData['deposit_amount'] = $totalAmount;
                }
            }
        }

        $this->update($updateData);

        return $this;
    }

    /**
     * Generate quote number
     */
    public static function generateQuoteNumber(): string
    {
        $prefix = 'QT-';
        $lastQuote = self::withTrashed()
            ->where('quote_number', 'like', $prefix . '%')
            ->orderBy('id', 'desc')
            ->first();

        if (!$lastQuote) {
            return $prefix . str_pad('1', 5, '0', STR_PAD_LEFT);
        }

        $lastNumber = (int) str_replace($prefix, '', $lastQuote->quote_number);
        $nextNumber = $lastNumber + 1;

        return $prefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Check if quote can be converted to job
     */
    public function canBeConvertedToJob(): bool
    {
        return true;
    }

    /**
     * Convert quote to job
     */
    public function convertToJob(int $convertedBy): ?Job
    {
        if (!$this->canBeConvertedToJob()) {
            throw new \Exception('Quote cannot be converted to job at this stage.');
        }

        // Use the service to create the job
        return app(JobCreationService::class)->convertFromQuote($this->id, $convertedBy);
    }
}
