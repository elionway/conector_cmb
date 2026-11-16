<?php

use App\Models\Faturado;
use App\Services\Sexp;
use App\Services\ShippingCarrier;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class ShippingCarrierFlowTest extends TestCase
{
    protected function setUp(): void
    {
        $app = new Container();
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app->instance('config', new Config(['connector' => require dirname(__DIR__, 2) . '/config/connector.php']));
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Container::setInstance(null);
    }

    public function testCarrierIsResolvedFromShippingCode(): void
    {
        $this->assertSame(ShippingCarrier::RM_CARGAS, ShippingCarrier::fromOrder([
            'shipping' => ['code' => 'ow_rmcargas.ow_rmcargas', 'method' => 'Transportadora'],
        ]));
        $this->assertSame(ShippingCarrier::RM_CARGAS, ShippingCarrier::fromOrder([
            'shipping' => ['code' => 'ow_rmcargas_local.ow_rmcargas_local'],
        ]));
        $this->assertSame(ShippingCarrier::CORREIOS, ShippingCarrier::fromOrder([
            'shipping' => ['code' => 'ow_mscorreios.sedex'],
        ]));
    }

    public function testLegacyTransportadoraWithoutCodeRemainsRmCargas(): void
    {
        $this->assertTrue(ShippingCarrier::isRmCargas([
            'shipping' => ['method' => 'Transportadora'],
        ]));
    }

    public function testUnknownCarrierIsBlocked(): void
    {
        $this->expectException(DomainException::class);
        ShippingCarrier::fromOrder(['shipping' => ['code' => 'unknown.service']]);
    }

    public function testErpCodesAreSelectedByCarrier(): void
    {
        $this->assertSame('000258', ShippingCarrier::erpTransportCode([
            'shipping' => ['code' => 'ow_rmcargas.ow_rmcargas'],
        ]));
        $this->assertSame('000007', ShippingCarrier::erpTransportCode([
            'shipping' => ['code' => 'ow_mscorreios.sedex'],
        ]));
    }

    public function testSexpReadyStatusIsCarrierSpecific(): void
    {
        $rmCargas = ['shipping' => ['code' => 'ow_rmcargas.ow_rmcargas']];
        $correios = ['shipping' => ['code' => 'ow_mscorreios.sedex']];

        $this->assertTrue(ShippingCarrier::isSexpShipmentReady($rmCargas, '5'));
        $this->assertFalse(ShippingCarrier::isSexpShipmentReady($rmCargas, '4'));
        $this->assertTrue(ShippingCarrier::isSexpShipmentReady($correios, '4'));
        $this->assertFalse(ShippingCarrier::isSexpShipmentReady($correios, '5'));
    }

    public function testCorreiosInvoiceContainsNewNfeFieldsInSchemaOrder(): void
    {
        $invoice = new Faturado();
        $invoice->setRawAttributes([
            'EMISSAO' => '20261115',
            'NOTA' => '12345',
            'SERIE' => '4',
            'CHAVE_NFE' => 'NFE-SYNTHETIC-KEY',
            'PROTOCOLO_NFE' => 'SYNTHETIC-PROTOCOL',
        ]);
        $order = [
            'total' => 987.65,
            'shipping' => ['code' => 'ow_mscorreios.sedex'],
        ];

        $method = new ReflectionMethod(Sexp::class, 'invoiceData');
        $method->setAccessible(true);
        $data = $method->invoke(new Sexp(), $order, $invoice);

        $this->assertSame(
            ['chaveNfe', 'data', 'numero', 'protocoloNfe', 'serie', 'valor'],
            array_keys($data)
        );
        $this->assertSame('2026-11-15T00:00:00', $data['data']);
        $this->assertSame(12345, $data['numero']);
        $this->assertSame(4, $data['serie']);
        $this->assertSame(987.65, $data['valor']);
    }

    public function testCorreiosInvoiceWithoutKeyOrProtocolIsBlockedBeforeSexp(): void
    {
        $invoice = new Faturado();
        $invoice->setRawAttributes(['EMISSAO' => '20261115', 'NOTA' => '12345', 'SERIE' => '4']);
        $order = ['total' => 10.0, 'shipping' => ['code' => 'ow_mscorreios.sedex']];

        $method = new ReflectionMethod(Sexp::class, 'invoiceData');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sem chave NFe ou protocolo NFe');
        $method->invoke(new Sexp(), $order, $invoice);
    }
}
