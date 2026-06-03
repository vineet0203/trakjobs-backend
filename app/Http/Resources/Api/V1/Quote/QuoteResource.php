<?php

namespace App\Http\Resources\Api\V1\Quote;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            
            // Section 1: Quote Details
            'quote_number' => $this->quote_number,
            'title' => $this->title,
            'client_id' => $this->client_id,
            'customer_id' => $this->customer_id,
            'client_name' => $this->client_name,
            'client_email' => $this->client_email,
            'equity_status' => $this->equity_status,
            'currency' => $this->currency,
            
            // Section 2: Line Items
            'items' => QuoteItemResource::collection($this->whenLoaded('items')),
            
            // Section 3: Pricing Summary
            'subtotal' => (float) $this->subtotal,
            'discount' => (float) $this->discount,
            'is_tax_applicable' => (bool) $this->is_tax_applicable,
            'tax_percentage' => (int) $this->tax_percentage,
            'total_amount' => (float) $this->total_amount,
            'customer_approved_price' => $this->customer_approved_price ? (float) $this->customer_approved_price : null,
            'deposit_required' => (bool) $this->deposit_required,
            'deposit_type' => $this->deposit_type,
            'deposit_amount' => $this->deposit_amount ? (float) $this->deposit_amount : null,
            
            // Section 4: Client Approval
            'approval_status' => $this->approval_status,
            'client_signature' => $this->client_signature,
            'customer_signature' => $this->customer_signature,
            'approval_date' => $this->approval_date?->format('Y-m-d H:i:s'),
            'approval_action_date' => $this->approval_action_date?->format('Y-m-d H:i:s'),
            
            // Section 5: Follow Ups & Reminders
            'reminders' => QuoteReminderResource::collection($this->whenLoaded('reminders')),
            
            // Section 6: Conversion to Job
            'can_convert_to_job' => (bool) $this->can_convert_to_job,
            'is_converted' => (bool) $this->is_converted,
            'job_id' => $this->job_id,
            'converted_at' => $this->converted_at?->format('Y-m-d H:i:s'),
            
            // Status
            'status' => $this->status,
            'sent_at' => $this->sent_at?->format('Y-m-d H:i:s'),
            
            // Dates
            'expires_at' => $this->expires_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            
            // Relationships
            'vendor' => $this->whenLoaded('vendor', fn() => [
                'id' => $this->vendor->id,
                'name' => $this->vendor->name,
                'email' => $this->vendor->email,
            ]),
            'client' => $this->whenLoaded('client', fn() => [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'email' => $this->client->email,
                'phone' => $this->client->phone,
            ]),
            'creator' => $this->whenLoaded('creator', fn() => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
                'email' => $this->creator->email,
            ]),
            'updater' => $this->whenLoaded('updater', fn() => [
                'id' => $this->updater->id,
                'name' => $this->updater->name,
                'email' => $this->updater->email,
            ]),
            
            // Meta
            'notes' => $this->notes,
            'quote_due_date' => $this->quote_due_date?->format('Y-m-d'),
            'can_edit' => $this->canBeEdited(),
            'can_send' => $this->canBeSent(),
            'can_convert' => $this->canBeConverted(),
            'images' => collect($this->images ?? [])->map(function ($path) {
                if (filter_var($path, FILTER_VALIDATE_URL)) {
                    return $path;
                }
                return url('storage/' . $path);
            })->filter()->values()->all(),
        ];
    }
}