<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Titulo;
use App\Models\Faturado;
use Illuminate\Support\Facades\DB;

class Erp {
    private ?string $application_code = '';

    private ?string $ibge_country = '';

    public function __construct()
    {
        $this->application_code = config('connector.application_code');
        
        $this->ibge_country = config('connector.ibge_country');
    }

    public function createOrder(array $order)
    {
        DB::transaction(function () use ($order) {
            $this->storeCustomer($order['customer']);

            $this->storeOrderItems($order['orders']);

            $this->storeBills($order['billing']);
        });
    }

    public function orderHasInvoice(int $orderId): bool
    {
        return !!$this->find($orderId);
    }

    public function find(int $orderId): ?Faturado
    {
        return Faturado::where('PEDIDO', $orderId)->first();
    }

    public function applicationCode(): string
    {
        return $this->application_code;
    }

    public function ibgeCountry(): string
    {
        return $this->ibge_country;
    }

    private function storeCustomer(array $customer)
    {
        Cliente::create($customer);
    }

    private function storeOrderItems(array $items)
    {
        foreach ($items as $item) {
            Pedido::create($item);
        }
    }

    private function storeBills(array $bills)
    {
        foreach ($bills as $bill) {
            Titulo::create($bill);
        }
    }
}