<?php

namespace App\Services\Clients;

use App\Models\Client;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class ClientQueryService
{
    /**
     * Get clients with filters and pagination
     */
    public function getClients(int $vendorId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Client::where('vendor_id', $vendorId)
            ->with('availabilitySchedules');

        // Apply filters
        $query = $this->applyFilters($query, $filters);

        // Apply search
        if (!empty($filters['search'])) {
            $query = $this->applySearch($query, $filters['search']);
        }

        // Apply sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query = $this->applySorting($query, $sortBy, $sortOrder);

        Log::debug('Executing client query', [
            'vendor_id' => $vendorId,
            'filters' => $filters,
            'per_page' => $perPage,
        ]);

        return $query->paginate($perPage);
    }

    /**
     * Get a specific client for a vendor
     */
    public function getClient(int $vendorId, int $clientId): ?Client
    {
        return Client::where('vendor_id', $vendorId)
            ->where('id', $clientId)
            ->with(['vendor', 'user', 'availabilitySchedules'])
            ->first();
    }

    /**
     * Get client by email for a vendor
     */
    public function getClientByEmail(int $vendorId, string $email): ?Client
    {
        return Client::where('vendor_id', $vendorId)
            ->where('email', $email)
            ->with('availabilitySchedules')
            ->first();
    }

    /**
     * Get clients by category
     */
    public function getClientsByCategory(int $vendorId, string $category): Collection
    {
        return Client::where('vendor_id', $vendorId)
            ->where('service_category', 'service_sub_category', $category)
            ->get();
    }

    /**
     * Get active clients
     */
    public function getActiveClients(int $vendorId): Collection
    {
        return Client::where('vendor_id', $vendorId)
            ->where('status', 'active')
            ->get();
    }

    /**
     * Get clients by business type
     */
    public function getClientsByBusinessType(int $vendorId, string $businessType): Collection
    {
        return Client::where('vendor_id', $vendorId)
            ->where('business_type', $businessType)
            ->get();
    }

    /**
     * Get client statistics for vendor
     */
    public function getClientStatistics(int $vendorId): array
    {
        return [
            'total' => Client::where('vendor_id', $vendorId)->count(),
            'active' => Client::where('vendor_id', $vendorId)->where('status', 'active')->count(),
            'inactive' => Client::where('vendor_id', $vendorId)->where('status', 'inactive')->count(),
            'premium' => Client::where('vendor_id', $vendorId)->where('service_category', 'service_sub_category', 'premium')->count(),
            'verified' => Client::where('vendor_id', $vendorId)->where('is_verified', true)->count(),
            'by_category' => Client::where('vendor_id', $vendorId)
                ->select('service_category', 'service_sub_category', DB::raw('count(*) as count'))
                ->groupBy('service_category')
                ->pluck('count', 'service_category')
                ->toArray(),
            'by_business_type' => Client::where('vendor_id', $vendorId)
                ->select('business_type', DB::raw('count(*) as count'))
                ->groupBy('business_type')
                ->pluck('count', 'business_type')
                ->toArray(),
        ];
    }

    /**
     * Check if client exists
     */
    public function clientExists(int $vendorId, int $clientId): bool
    {
        return Client::where('vendor_id', $vendorId)
            ->where('id', $clientId)
            ->exists();
    }

    /**
     * Apply filters to the query
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        // Filter by business_type
        if (!empty($filters['business_type'])) {
            $query->where('business_type', $filters['business_type']);
        }

        // Filter by industry
        if (!empty($filters['industry'])) {
            $query->where('industry', 'like', '%' . $filters['industry'] . '%');
        }

        // Filter by service_category
        if (!empty($filters['service_category'])) {
            $query->where('service_category', 'service_sub_category', $filters['service_category']);
        }

        // Filter by status
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filter by is_verified
        if (isset($filters['is_verified'])) {
            $query->where('is_verified', $filters['is_verified']);
        }

        // Filter by city
        if (!empty($filters['city'])) {
            $query->where('city', 'like', '%' . $filters['city'] . '%');
        }

        // Filter by state
        if (!empty($filters['state'])) {
            $query->where('state', 'like', '%' . $filters['state'] . '%');
        }

        // Filter by country
        if (!empty($filters['country'])) {
            $query->where('country', 'like', '%' . $filters['country'] . '%');
        }

        // Filter by date range
        if (!empty($filters['created_from'])) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }
        if (!empty($filters['created_to'])) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }

        return $query;
    }

    /**
     * Apply search to the query
     */
    private function applySearch(Builder $query, string $searchTerm): Builder
    {
        return $query->where(function ($q) use ($searchTerm) {
            $q->where('business_name', 'like', '%' . $searchTerm . '%')
                ->orWhere('contact_person_name', 'like', '%' . $searchTerm . '%')
                ->orWhere('email', 'like', '%' . $searchTerm . '%')
                ->orWhere('mobile_number', 'like', '%' . $searchTerm . '%')
                ->orWhere('alternate_mobile_number', 'like', '%' . $searchTerm . '%')
                ->orWhere('business_registration_number', 'like', '%' . $searchTerm . '%')
                ->orWhere('website_url', 'like', '%' . $searchTerm . '%');
        });
    }

    /**
     * Apply sorting to the query
     */
    private function applySorting(Builder $query, string $sortBy, string $sortOrder): Builder
    {
        $allowedSortFields = [
            'id',
            'business_name',
            'contact_person_name',
            'email',
            'created_at',
            'updated_at',
            'status',
            'service_category', 'service_sub_category',
            'business_type',
            'city',
            'country'
        ];

        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
        }

        return $query;
    }

    /**
     * Get applied filters for meta data
     */
    public function getAppliedFilters(array $filters): array
    {
        $appliedFilters = [];
        $filterableFields = [
            'business_type',
            'industry',
            'service_category', 'service_sub_category',
            'status',
            'is_verified',
            'city',
            'state',
            'country',
            'created_from',
            'created_to'
        ];

        foreach ($filterableFields as $field) {
            if (!empty($filters[$field])) {
                $appliedFilters[$field] = $filters[$field];
            }
        }

        if (!empty($filters['search'])) {
            $appliedFilters['search'] = $filters['search'];
        }

        return $appliedFilters;
    }
}
