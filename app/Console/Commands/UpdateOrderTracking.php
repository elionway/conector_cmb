<?php

namespace App\Console\Commands;

use App\Support\Opencart;
use App\Support\Sexp;
use Illuminate\Console\Command;

class UpdateOrderTracking extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:update:tracking';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command is responsible for get all orders dispatched in Opencart and verify if has tracking number.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $orders = Opencart::orders(['filter_status' => Opencart::searchStatuses('in_dispatchment_order')]);

        foreach ($orders as $order) {
            if (Sexp::orderHasTrackingNumber($order['order_id'])) {
                $this->updateOpencartOrderStatus($order['order_id']);
            }
        }
    }

    private function updateOpencartOrderStatus(int $order_id)
    {
        Opencart::orderHistory($order_id, [
            'order_status_id' => Opencart::updateStatuses('dispatched_order'),
            'comment' => 'Enviado para separação',
            'notify' => true,
        ]);
    }
}
