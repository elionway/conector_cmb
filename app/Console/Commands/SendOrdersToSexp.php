<?php

namespace App\Console\Commands;

use App\Support\Opencart;
use App\Support\Sexp;
use Illuminate\Console\Command;

class SendOrdersToSexp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:send:sexp';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command is responsible for get all orders paid in Opencart and send to SEXP.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $orders = Opencart::orders(['filter_status' => Opencart::searchStatuses('new_order')]);

        foreach ($orders as $order) {
            Sexp::createOrder($this->formatOrderDataToSexp($order));

            $this->updateOpencartOrderStatus($order['order_id']);
        }
    }

    private function updateOpencartOrderStatus(int $order_id)
    {
        Opencart::orderHistory($order_id,
         [
            'order_status_id' => Opencart::updateStatuses('integrated_sexp_order'),
            'comment' => 'Recebido na expedição',
            'notify' => false,
        ]);
    }

    private function address(): array
    {
        $address = Sexp::addressSettings();

        return [
            'bairro' => $address['neighborhood'],
            'cep' => $address['postcode'],
            'cidade' => $address['city'],
            'complemento' => '',
            'numero' => $address['number'],
            'rua' => $address['address'],
            'uf' => $address['state_code'],
        ];
    }

    private function package(array $order): array
    {
        $package['height'] = 0;

        $package['length'] = 0;

        $package['width'] = 0;

        $package['weight'] = 0;

        foreach ($order['products'] as $product) {
            $package['weight'] += $product['weight'] * $product['quantity'];

            $package['height'] += $product['height'] * $product['quantity'];

            $package['length'] += $product['length'] * $product['quantity'];

            $package['width'] += $product['width'] * $product['quantity'];
        }

        return $package;
    }

    private function formatOrderDataToSexp(array $order): array
    {
        $package = $this->package($order);

        return [
            'chaveUnica' => 'ECMB' . str_pad($order['order_id'], 9, '0', STR_PAD_LEFT),
            'codigoProduto' => '802402',
            'enderecoDevolucao' => $this->address(),
            'enderecoPrincipal' => [
                'bairro' => $order['shipping']['address_2'],
                'cep' => $order['shipping']['postcode'],
                'cidade' => $order['shipping']['city'],
                'complemento' => $order['shipping']['custom_field'][Opencart::addressComplement()] ?? '',
                'numero' => $order['shipping']['custom_field'][Opencart::addressNumber()] ?? '',
                'rua' => $order['shipping']['address_1'],
                'uf' => $order['shipping']['zone_code'],
            ],
            'idServico' => '8',
            'nomeCliente' => $order['firstname'] . ' ' . $order['lastname'],
            'quantidade' => '1',
            'tipoEmbalagem' => 'CAIXA/PACOTE',
            'valor' => $order['total'],
            'altura' => $package['height'],
            'comprimento' => $package['length'],
            'largura' => $package['width'],
            'peso' => $package['weight'],
        ];
    }
}
