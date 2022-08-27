<?php

namespace App\Services;

class Erp {
    public function createOrder(array $order)
    {
        dd($order);
    }

    public function orderHasInvoice(int $order_id): bool
    {
        return true;
    }
}