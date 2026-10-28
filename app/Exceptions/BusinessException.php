<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Règle de gestion non respectée. Rendue en 422 avec un message lisible par l'utilisateur et,
 * si utile à l'interface, un code stable (ex. « cash_session_required »).
 */
class BusinessException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $errorCode = null)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ]), 422);
    }
}
