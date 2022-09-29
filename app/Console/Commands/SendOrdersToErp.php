<?php

namespace App\Console\Commands;

use App\Support\Erp;
use App\Support\Opencart;
use App\Support\Sexp;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

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
            try {
                $this->info($order['order_id']);
                
                Erp::createOrder($this->formatOrderDataToErp($order));

                $this->updateOpencartOrderStatus($order['order_id']);
            } catch (\Throwable $th) {
                $this->error("{$th->getMessage()} - {$th->getFile()}:{$th->getLine()}");
            }
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

    private function tesCode(array $order, array $product): string
    {   
        $productTypes = [
            '4' => '654',
            '5' => '890',
        ];

        if (isset($productTypes[$product['cest']])) {
            return $productTypes[$product['cest']];
        }

        if ($this->isOrderInRj($order) || $this->hasStateRegistration($order['custom_fields'])) {
            if ($this->isMedal($product['cest'])) {
                return '597';
            }

            if ($this->isCoin($product['cest'])) {
                return '504';
            }
        }

        if ($this->isMedal($product['cest'])) {
            return '598';
        }

        if ($this->isCoin($product['cest'])) {
            return '50A';
        }
    }

    private function isMedal(string $productType): bool 
    {   
        return $productType == '1';
    }

    private function isCoin(string $productType): bool
    {
        return in_array($productType, ['2', '3']);
    }

    private function hasStateRegistration(array $custom_fields): bool
    {
        return !empty($custom_fields[Opencart::stateRegistration()]);
    }

    private function isOrderInRj(array $order): bool
    {
        return $order['shipping']['zone_code'] == 'RJ';
    }

    private function messageAr(array $order, array $product): string
    {
        if ($this->hasBc($order['products'])) {
            return "PAM {$order['payment']['transaction']['payment_id']} PED {$this->orderId($order['order_id'])} Contrato Nº 50553/2016  entre Casa da Moeda do Brasil e Banco Central. Operação com Imunidade tributária estabelecida pelo art.150, VI, a, CRFB/88 e isenta de ICMS conforme Convênio ICMS 01/91.";
        }

        if ($this->tesCode($order, $product) === '504') {
            return "PAM {$order['payment']['transaction']['payment_id']} PED {$this->orderId($order['order_id'])} ICMS - ISENTO COM BASE NO CONVENIO 01/91 ";
        }

        return "PAM {$order['payment']['transaction']['payment_id']} PED {$this->orderId($order['order_id'])} ";
    }

    private function orderId($order_id): string
    {
        return str_pad($order_id, 9, '0', STR_PAD_LEFT);
    }

    private function hasBc(array $products): bool
    {
        foreach ($products as $product) {
            if ($this->isBc($product)) {
                return true;
            }
        }

        return false;
    }

    private function isBc(array $product): bool
    {
        return $product['cest'] == 3;
    }

    private function parsePaymentMethodData(array $order): array
    {
        $paymentMethods = [
            'cielo_api_boleto' => 1,
            'cielo_api_debito' => 2,
            'cielo_api_credito' => 3,
        ];

        $paymentCodes = [
            'cielo_api_boleto' => '010',
            'cielo_api_debito' => '010',
            'cielo_api_credito' => '30',
        ];

        return [
            'paymentMethod' => $paymentMethods[$order['payment']['method']] ?? 1,
            'paymentCode' => $paymentCodes[$order['payment']['method']] ?? '010',
        ];
    }

    private function ddd(string $telephone): string
    {
        $telephone = preg_replace('/[^0-9]/', '', $telephone);

        if (Str::startsWith($telephone, '55')) {
            $telephone = substr($telephone, 2, strlen($telephone));
        }

        return substr($telephone, 0, 2);
    }

    private function telephone(string $telephone): string
    {
        $telephone = preg_replace('/[^0-9]/', '', $telephone);

        if (Str::startsWith($telephone, '55')) {
            $telephone = substr($telephone, 2);
        }

        return substr($telephone, 2);
    }

    private function ibge($postcode)
    {
        $data = file_get_contents("https://viacep.com.br/ws/{$postcode}/json/");

        return json_decode($data, true);
    }

    private function getCustomerData(array $order): array
    {
        $address_number = $order['shipping']['custom_field'][Opencart::addressNumber()] ?? '';

        $address_complement = $order['shipping']['custom_field'][Opencart::addressComplement()] ?? '';

        $ibgeInfo = $this->ibge($order['shipping']['postcode']);

        $street = preg_replace('/[^a-zA-Z0-9_ \-\.\/]/s','', $order['shipping']['address_1']);

        $number = preg_replace('/[^a-zA-Z0-9_ \-\.\/]/s','', $address_number);

        $complement = preg_replace('/[^a-zA-Z0-9_ \-\.\/]/s','', $address_complement);

        $address = "{$street}, {$number} {$complement}";

        return [
            'CL_APLIC' => 'ECMB',
            'CL_STATUS' => 'M',
            'CL_CPFCNPJ' => $this->document($order['custom_fields']),
            'CL_END' => $address,
            'CL_BAIRRO' => $order['shipping']['address_2'],
            'CL_CEP' => $order['shipping']['postcode'],
            'CL_DDD' => $this->ddd($order['telephone']),
            'CL_TEL' => $this->telephone($order['telephone']),
            'CL_EMAIL' => $order['email'], // required
            'CL_DELET' => '',
            'CL_CODMUN' => substr($ibgeInfo['ibge'], -5), // required
            'CL_CODPAIS' => Erp::ibgeCountry(), // required
            'CL_INCCULT' => '2', // required
            'CL_NOME' => $order['firstname'] . ' ' . $order['lastname'],
            'CL_SIMPLES' => '2',
            'CL_PESSOA' => $order['custom_fields'][Opencart::cnpj()] ? 'J' : 'F',
            'CL_IEST' => $order['custom_fields'][Opencart::stateRegistration()] ?? '',
            'CL_MUNIC' => $order['shipping']['city'],
            'CL_UF' => $order['shipping']['zone_code'],
            'CL_PAIS' => $order['shipping']['country'],
            'CL_FAX' => '',
            'CL_TELEX' => '',
            'CL_CXPOST' => '',
            'CL_CODMSG' => '',
            'CL_COMLJ' => 'N',
        ];
    }

    private function shipping(array $totals): float
    {
        foreach ($totals as $total) {
            if ($total['code'] == 'shipping') {
                return $total['value'];
            }
        }

        return 0;
    }

    private function prepareSeparetedProducts(array $order): array
    {
        $data = [];

        $amountAll = 0;
        
        $weightAll = 0;

        // $amountBc = 0;

        // $weightBc = 0;

        // $amountNonBc = 0;

        // $weightNonBc = 0;

        $hasBc = false;

        foreach ($order['products'] as $product) {
            $amountAll += $product['total'];

            $weightAll += $product['weight'];

            if ($this->isBc($product)) {
                // $amountBc += $product['total'];

                // $weightBc += $product['weight'];

                $hasBc = true;

                continue;
            }

            // $amountNonBc += $product['total'];

            // $weightNonBc += $product['weight'];
        }

        $data = [
            'all' => [
                'amount' => $amountAll,
                'weight' => $weightAll ?? 1,
            ],
            'nonbc' => [
                'prefix' => '4',
                'amount' => $amountAll,
                'weight' => $weightAll ?? 1,
            ],
        ];

        $shipping_order = $this->shipping($order['totals']) / $data['all']['weight'];

        if ($hasBc) {
            $data['bc'] = [
                'prefix' => '4BC',
                'amount' => $amountAll,
                'weight' => $weightAll,
            ];

            $data['bc']['shipping_amount'] = $shipping_order * $data['bc']['weight']; 
        }

        $data['nonbc']['shipping_amount'] = $shipping_order * $data['nonbc']['weight']; 

        unset($data['all']);

        return $data;
    }

    private function getBillingData(array $order): array
    {
        $bills = $this->prepareSeparetedProducts($order);

        $data = [];

        foreach ($bills as $bill) {
            $data[] = [
                'TI_APLIC' => Erp::applicationCode(),
                'TI_PREFIXO' => $bill['prefix'],
                'TI_NUM' => $this->orderId($order['order_id']),
                'TI_TIPO' => 'RA',
                'TI_CPFCNPJ' => $this->document($order['custom_fields']),
                'TI_CC' => 'H2104',
                'TI_EMISSAO' => Carbon::parse($order['date_added'])->format('Ymd'),
                'TI_VENCTO' => Carbon::createFromFormat('Y-m-d', $order['payment']['transaction']['due_date'])->format('Ymd'),
                'TI_VALOR' => $bill['amount'],
                'TI_XOBS' => $order['order_id'],
                'TI_DTPAG' => $order['payment']['transaction']['paid_date'] ? Carbon::createFromFormat('Y-m-d', $order['payment']['transaction']['paid_date'])->format('Ymd') : '',
                'TI_STATUS' => 'M',
                'TI_FRETE' => $bill['shipping_amount'],
                'TI_CNS' => '',
                'TI_DESPBAN' => '',
            ];
        }

        return $data;
    }

    private function formatOrderDataToErp(array $order): array
    {
        $data = [
            'billing' => $this->getBillingData($order),
            'customer' => $this->getCustomerData($order),
            
        ];

        foreach ($order['products'] as $product) {
            $data['orders'][] = $this->getOrderData($order, $product);     
        }

        return $data;
    }

    private function getOrderData(array $order, array $product): array
    {
        $paymentData = $this->parsePaymentMethodData($order);

        $packageType = $this->packageType($order);

        return [
            'PV_APLIC' => Erp::applicationCode(),
            'PV_CPFCNPJ' => $this->document($order['custom_fields']),
            'PV_UF' => $order['shipping']['zone_code'],
            'PV_NUMPED' => $this->orderId($order['order_id']),
            'PV_CODPR' => $product['options'][0]['sku'] ?? $product['sku'],
            'PV_DTHORA' => Carbon::now()->format('Y-m-d H:i:s'),
            'PV_STATUS' => 'M',
            'PV_TES' => $this->tesCode($order, $product),
            'PV_QTDPED' => $product['quantity'],
            'PV_PRUNIT' => $product['total'],
            'PV_FTCONV' => 0,
            'PV_TPCONV' => '',
            'PV_CODMSG' => $product['cest'] == 3 ? '012' : '',
            'PV_QTDVOL' => 1,
            'PV_TRANSP' => '000062',
            'PV_AR' => $this->messageAr($order, $product),
            'PV_MEIOPG' => $paymentData['paymentMethod'],
            'PV_CODCPG' => $paymentData['paymentCode'],
            'PV_SEQUEN' => '',
            'PV_DELET' => '',
            'PV_TPINF' => 0,
            'PV_PBRUTO' => $this->totalWeight($order, 0),
            'PV_PESOL' => $this->totalWeight($order, 0),
            'PV_FRETE' => $this->shipping($order['totals']),
            'PV_ESPECV' => $packageType['type'],
            'PV_OBJETO' => $this->packageDescription($order, $packageType),
        ];
    }

    private function packageDescription(array $order, array $packageType): string
    {
        $sexpOrder = Sexp::getOrder($order['order_id']);

        $tracking_number = $sexpOrder['numeroPostagem'];

        return "{$tracking_number}(" . number_format($packageType["width"], 3, '.', '') . "x" . number_format($packageType["height"], 3, '.', '') . "x" . number_format($packageType["length"], 3, '.', '') . "x" . number_format($this->totalWeight($order, $packageType['weight']), 3, '.', '') . ")";
    }

    private function totalWeight(array $order, $packageWeight)
    {
        $weight = $packageWeight;

        foreach ($order['products'] as $product) {
            $weight += $product['quantity'] * $product['weight'];
        }

        return $weight;
    }

    private function packageType(array $order)
    {
        $count = 0;

        foreach ($order['products'] as $product) {
            $count += $product['quantity'];
        }
        
        if ($count <= 2) {
            return [
                'type' => 'ENVELOPE',
                'height' => 20,
                'width' => 1,
                'length' => 28,
                'weight' => 0.022,
            ];
        } elseif ($count <= 5) {
            return [
                'type' => 'CAIXA',
                'height' => 9,
                'width' => 14,
                'length' => 18,
                'weight' => 0.086,
            ];
        } else {
            return [
                'type' => 'CAIXA',
                'height' => 9,
                'width' => 18,
                'length' => 27,
                'weight' => 0.142,
            ];
        } 
        
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

        return preg_replace('/[^0-9]/', '', $document);
    }
}
