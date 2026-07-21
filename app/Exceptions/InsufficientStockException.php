<?php

namespace App\Exceptions;

class InsufficientStockException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Insufficient stock for this product.');
    }
}
