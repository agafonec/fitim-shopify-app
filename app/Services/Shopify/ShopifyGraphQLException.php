<?php

namespace App\Services\Shopify;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class ShopifyGraphQLException extends RuntimeException
{
    public function __construct(string $message, public readonly mixed $errors = null)
    {
        parent::__construct($message);
    }

    public function context(): array
    {
        return ['shopify_errors' => $this->errors];
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ], 502);
    }
}
