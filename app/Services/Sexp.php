<?php

namespace App\Services;

class Sexp {
    public function createOrder(array $order)
    {
    }

    public function orderHasTrackingNumber(int $order_id): bool
    {
        return !!$this->getOrderTrackingNumber($order_id);
    }

    public function getOrderTrackingNumber(int $order_id): ?string
    {
        return 'BRHFDSIODSFHIU';
    }
}