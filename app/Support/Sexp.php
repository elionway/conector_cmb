<?php

namespace App\Support;

use Illuminate\Support\Facades\Facade;

class Sexp extends Facade {
    protected static function getFacadeAccessor()
    {
        return 'sexp';
    }
}