<?php

namespace App\Services;

class Sexp {
    public function createOrder(array $order)
    {
    }

    public function orderHasTrackingNumber(int $order_id): bool
    {
        return true;
    }
}