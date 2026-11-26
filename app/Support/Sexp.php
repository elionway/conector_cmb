<?php

namespace App\Support;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void processInvoicedOrder(array $order, \App\Models\Faturado $invoice)
 */
class Sexp extends Facade {
    protected static function getFacadeAccessor()
    {
        return 'sexp';
    }
}