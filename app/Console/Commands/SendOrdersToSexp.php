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
        Opencart::orderHistory($order_id, [
            'order_status_id' => Opencart::updateStatuses('integrated_sexp_order'),
            'comment' => 'Enviado para separação',
            'notify' => true,
        ]);
    }

    private function formatOrderDataToSexp(array $order): array
    {
        return [];
    }
}
