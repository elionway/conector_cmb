<?php

namespace App\Support;

use Illuminate\Support\Facades\Facade;

class Opencart extends Facade {
    protected static function getFacadeAccessor()
    {
        return 'opencart';
    }
}