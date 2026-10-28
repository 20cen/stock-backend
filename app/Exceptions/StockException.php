<?php

namespace App\Exceptions;

/**
 * Règle de stock non respectée (rendue en 422 comme toute BusinessException).
 */
class StockException extends BusinessException {}
