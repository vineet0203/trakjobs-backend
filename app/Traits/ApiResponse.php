<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Http\Resources\Json\JsonResource;

trait ApiResponse
{
    /**
     * Success response method.
     * 
     * @param mixed $data Response data
     * @param string $message Success message
     * @param int $status HTTP status code
     * @param array $extra Additional data to include in response (will be merged with meta if paginated)
     * @return JsonResponse
     */
    protected function successResponse($data = null, string $message = '', int $status = 200, array $extra = []): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
        ];

        // If data is a ResourceCollection, handle it
        if ($data instanceof ResourceCollection) {
            $response = $this->formatResourceCollectionResponse($data, $response, $extra);
        }
        // If data is a paginator instance (not wrapped in ResourceCollection)
        elseif ($data instanceof AbstractPaginator) {
            $response = $this->formatPaginatedResponse($data, $response, $extra);
        }
        // If data is a JsonResource, convert it to array
        elseif ($data instanceof JsonResource) {
            $response['data'] = $data->toArray(request());

            // Add extra data if provided
            if (!empty($extra)) {
                $response = $this->addExtraData($response, $extra);
            }
        }
        // For regular data
        else {
            $response['data'] = $data;

            // Add extra data if provided
            if (!empty($extra)) {
                $response = $this->addExtraData($response, $extra);
            }
        }

        // Add timestamp and code
        $response['timestamp'] = now()->toIso8601String();
        $response['code'] = $status;

        return response()->json($response, $status);
    }

    /**
     * Format ResourceCollection response with pagination support
     */
    private function formatResourceCollectionResponse(ResourceCollection $collection, array $response, array $extra = []): array
    {
        // Get the collection's array representation
        $collectionArray = $collection->toArray(request());

        if (!isset($collectionArray['data'])) {
            $collectionArray = ['data' => $collectionArray];
        }

        // Merge the collection's structure directly into the response
        $response = array_merge($response, $collectionArray);

        // Add extra data if provided
        if (!empty($extra)) {
            // If the collection has a meta key, merge extra into it
            if (isset($response['meta']) && is_array($response['meta'])) {
                $response['meta'] = array_merge($response['meta'], $extra);
            } else {
                // Otherwise, add extra as a top-level key
                foreach ($extra as $key => $value) {
                    // Only add if key doesn't exist to prevent overwriting
                    if (!isset($response[$key])) {
                        $response[$key] = $value;
                    } elseif ($key === 'meta' && isset($response['meta'])) {
                        // If it's meta and exists, merge
                        $response['meta'] = array_merge($response['meta'], (array)$value);
                    }
                }
            }
        }

        return $response;
    }



    /**
     * Fallback method for non-paginated collections
     */
    private function fallbackFormatResourceCollection(ResourceCollection $collection, array $response, array $extra = []): array
    {
        // Get the resource response data
        $resourceData = $collection->response()->getData(true);

        // Extract data
        if (isset($resourceData['data'])) {
            $response['data'] = $resourceData['data'];
        }

        // Handle meta data
        $meta = [];

        // Add existing meta if exists
        if (isset($resourceData['meta'])) {
            $meta = $resourceData['meta'];
        }

        // Merge custom extra data
        if (!empty($extra)) {
            $meta = array_merge($meta, $extra);
        }

        // Add meta to response if not empty
        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        // Add links if exists
        if (isset($resourceData['links'])) {
            $response['links'] = $resourceData['links'];
        }

        return $response;
    }


    /**
     * Format paginated response for non-ResourceCollection paginators
     */
    private function formatPaginatedResponse(AbstractPaginator $paginator, array $response, array $extra = []): array
    {
        $response['data'] = $paginator->items();

        // Build meta with pagination info
        $meta = [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];

        // Merge custom extra data into meta
        if (!empty($extra)) {
            foreach ($extra as $key => $value) {
                // If the key already exists in meta, merge arrays
                if (isset($meta[$key]) && is_array($meta[$key]) && is_array($value)) {
                    $meta[$key] = array_merge($meta[$key], $value);
                } else {
                    $meta[$key] = $value;
                }
            }
        }

        $response['meta'] = $meta;

        $response['links'] = [
            'first' => $paginator->url(1),
            'last' => $paginator->url($paginator->lastPage()),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];

        return $response;
    }

    /**
     * Add extra data to response (for non-paginated responses)
     */
    private function addExtraData(array $response, array $extra): array
    {
        foreach ($extra as $key => $value) {
            // For root-level keys
            if (!in_array($key, ['success', 'message', 'data', 'meta', 'links', 'timestamp', 'code'])) {
                $response[$key] = $value;
            }
            // For meta data in non-paginated responses
            elseif ($key === 'meta') {
                if (!isset($response['meta'])) {
                    $response['meta'] = [];
                }
                if (is_array($value)) {
                    $response['meta'] = array_merge($response['meta'], $value);
                } else {
                    $response['meta'] = $value;
                }
            }
        }

        return $response;
    }

    /**
     * Success response with extra data (alias for backward compatibility)
     */
    protected function successResponseWithExtra($data = null, string $message = '', array $extra = [], int $status = 200): JsonResponse
    {
        return $this->successResponse($data, $message, $status, $extra);
    }

    /**
     * Error response method.
     */
    protected function errorResponse(string $message = '', int $status = 400, $errors = null, array $extra = []): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
            'timestamp' => now()->toIso8601String(),
            'code' => $status,
        ];

        if ($errors) {
            $response['errors'] = $errors;
        }

        foreach ($extra as $key => $value) {
            if (!array_key_exists($key, $response)) {
                $response[$key] = $value;
            }
        }

        return response()->json($response, $status);
    }

    /**
     * Validation error response method.
     */
    protected function validationErrorResponse(array $errors, string $message = 'Validation failed'): JsonResponse
    {
        return $this->errorResponse($message, 422, $errors);
    }

    /**
     * Not found response method.
     */
    protected function notFoundResponse(string $message = 'Resource not found'): JsonResponse
    {
        return $this->errorResponse($message, 404);
    }

    /**
     * Unauthorized response method.
     */
    protected function unauthorizedResponse(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->errorResponse($message, 401);
    }

    /**
     * Forbidden response method.
     */
    protected function forbiddenResponse(string $message = 'Forbidden'): JsonResponse
    {
        return $this->errorResponse($message, 403);
    }

    /**
     * Conflict error response (for duplicate data)
     */
    protected function conflictErrorResponse(string $message = 'Resource already exists'): JsonResponse
    {
        return $this->errorResponse($message, 409);
    }

    /**
     * Created response (201)
     */
    protected function createdResponse($data = null, string $message = 'Resource created successfully', array $extra = []): JsonResponse
    {
        return $this->successResponse($data, $message, 201, $extra);
    }

    /**
     * No content response (204)
     */
    protected function noContentResponse(string $message = 'No content'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'timestamp' => now()->toIso8601String(),
            'code' => 204,
        ], 204);
    }

    /**
     * Paginated response helper (for backward compatibility)
     */
    protected function paginatedResponse($paginator, string $message = '', array $extraMeta = []): JsonResponse
    {
        if ($paginator instanceof ResourceCollection) {
            return $this->successResponse($paginator, $message, 200, $extraMeta);
        }

        return $this->successResponse($paginator, $message, 200, $extraMeta);
    }
}
