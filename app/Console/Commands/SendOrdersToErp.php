<?php

namespace App\Console\Commands;

use App\Support\Erp;
use App\Support\Opencart;
use Illuminate\Console\Command;

class SendOrdersToErp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:send:erp';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command is responsible for get all orders dispatched in Opencart and send to ERP.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $orders = Opencart::orders(['filter_status' => Opencart::searchStatuses('after_dispatch_order')]);

        foreach ($orders as $order) {
            Erp::createOrder($this->formatOrderDataToErp($order));

            $this->updateOpencartOrderStatus($order['order_id']);
        }
    }

    private function updateOpencartOrderStatus(int $order_id)
    {
        Opencart::orderHistory($order_id, [
            'order_status_id' => Opencart::updateStatuses('in_invoicement_order'),
            'comment' => 'Em faturamento',
            'notify' => true,
        ]);
    }

    private function formatOrderDataToErp(array $order): array
    {
        dd($order);
        return [
            'PV_APLIC' => '', // required
            'PV_CPFCNPJ' => $this->document($order['custom_fields']), // required
            'PV_UF' => '', // required
            'PV_NUMPED' => '', // required
            'PV_CODPR' => '', // required
            'PV_DTHORA' => '', // required
            'PV_STATUS' => 'M', // required
            'PV_TES' => '', // required
            'PV_QTDPED' => '',
            'PV_PRUNIT' => '',
            'PV_FTCONV' => 0,
            'PV_TPCONV' => null,
            'PV_PBRUTO' => '',
            'PV_PESOL' => '',
            'PV_FRETE' => '',
            'PV_CODMSG' => '',
            'PV_CODCPG' => '',
            'PV_TRANSP' => '',
            'PV_QTDVOL' => '',
            'PV_ESPECV' => '',
            'PV_AR' => '',
            'PV_SEQUEN' => '',
            'PV_DELET' => null,
            'PV_TPINF' => 0,
            'PV_OBJETO' => '',
            'PV_MEIOPG' => '',
        ];
    }

    private function document(array $custom_fields): string | null
    {
        $document = isset($custom_fields[Opencart::cpf()]) 
            ? $custom_fields[Opencart::cpf()] 
            : (
                isset($custom_fields[Opencart::cnpj()])
                ? $custom_fields[Opencart::cnpj()]
                : null
            );

        return preg_replace( '/[^0-9]/', '', $document);
    }
}
