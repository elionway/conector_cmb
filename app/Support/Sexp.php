<?php

namespace App\Support;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void createOrder(array $data)
 */
class Sexp extends Facade {
    protected static function getFacadeAccessor()
    {
        return 'sexp';
    }
}