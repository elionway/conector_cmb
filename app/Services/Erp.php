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

    public function createOrUpdateOrder(array $order)
    {
        DB::transaction(function () use ($order) {
            $this->createOrUpdateCustomer($order['customer']);

            $this->createOrUpdateItems($order['orders']);

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
        return str_pad($this->ibge_country, 5, "0", STR_PAD_LEFT);
    }

    private function createOrUpdateCustomer(array $data)
    {
        $customer = Cliente::where('CL_CPFCNPJ', $data['CL_CPFCNPJ'])->first();

        if ($customer) {
            $this->updateCustomer($customer, $data);

            return;
        }

        $this->storeCustomer($data);
    }

    private function updateCustomer(Cliente $customer, array $data)
    {
        Cliente::where('CL_CPFCNPJ', $data['CL_CPFCNPJ'])->update(['CL_END' => $data['CL_END']]);
    }

    private function storeCustomer(array $customer)
    {
        $client = new Cliente();

        foreach ($customer as $key => $value) {
            if ($key == 'CL_CEP') {
                $value = preg_replace("/[^0-9]/", "", $value);
            }

            $client->$key = $value;
        }

        $client->save();
    }

    private function createOrUpdateItems(array $items)
    {
        foreach ($items as $item) {
            $pedido = Pedido::where('PV_NUMPED', $item['PV_NUMPED'])->first();

            if ($pedido) {
                Pedido::where('PV_NUMPED', $item['PV_NUMPED'])->update($item);

                continue;
            }

            Pedido::create($item);
        }
    }

    private function storeBills(array $bills)
    {
        foreach ($bills as $bill) {
            $titulo = Titulo::where('TI_NUM', $bill['TI_NUM'])->where('TI_PREFIXO', $bill['TI_PREFIXO'])->first();

            if ($titulo) {
                Titulo::where('TI_NUM', $bill['TI_NUM'])->where('TI_PREFIXO', $bill['TI_PREFIXO'])->update($bill);

                continue;
            }

            Titulo::create($bill);
        }
    }
}