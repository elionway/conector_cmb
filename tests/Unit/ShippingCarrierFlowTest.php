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

    public function testSexpServiceIdsAreSelectedByCarrierAndCorreiosService(): void
    {
        $this->assertSame(17, ShippingCarrier::sexpServiceId([
            'shipping' => ['code' => 'ow_rmcargas.ow_rmcargas', 'method' => 'Transportadora'],
        ]));
        $this->assertSame(18, ShippingCarrier::sexpServiceId([
            'shipping' => ['code' => 'ow_mscorreios.03220', 'method' => 'SEDEX - prazo de 1 dia útil'],
        ]));
        $this->assertSame(19, ShippingCarrier::sexpServiceId([
            'shipping' => ['code' => 'ow_mscorreios.03298', 'method' => 'PAC - prazo de 3 dias úteis'],
        ]));
    }

    public function testUnknownCorreiosServiceIsBlockedBeforeSexp(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Serviço dos Correios');

        ShippingCarrier::sexpServiceId([
            'shipping' => ['code' => 'ow_mscorreios.unknown', 'method' => 'Serviço desconhecido'],
        ]);
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
            'CHAVENFE' => str_repeat('1', 44),
            'PROTOCOLO' => '123456789012345',
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
        $this->assertSame(str_repeat('1', 44), $data['chaveNfe']);
        $this->assertSame('123456789012345', $data['protocoloNfe']);
        $this->assertSame(987.65, $data['valor']);
    }

    /** @dataProvider invalidInvoiceFields */
    public function testIncompleteInvoiceIsRejectedBeforeContactingSexp(string $field, string $value): void
    {
        $invoice = new Faturado();
        $fields = ['EMISSAO' => '20261115', 'NOTA' => '12345', 'SERIE' => '4',
            'CHAVENFE' => str_repeat('1', 44), 'PROTOCOLO' => '123456789012345'];
        $fields[$field] = $value;
        $invoice->setRawAttributes($fields);
        $this->expectException(\UnexpectedValueException::class);
        (new Sexp())->processInvoicedOrder(['total' => 10], $invoice);
    }

    public static function invalidInvoiceFields(): array
    {
        return [['NOTA', ''], ['NOTA', '0000'], ['SERIE', ''], ['EMISSAO', '20260230'],
            ['CHAVENFE', ''], ['CHAVENFE', '123'], ['PROTOCOLO', '']];
    }

    public function testMessageIdentifiesInvoiceAndEveryBlockingField(): void
    {
        $invoice = new Faturado();
        $invoice->setRawAttributes(['NOTA' => '000088138', 'EMISSAO' => '20261127', 'SERIE' => '4',
            'CHAVENFE' => '12345', 'PROTOCOLO' => '']);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("NF 000088138 encontrada. Envio ao SEXP pendente: chave da NF-e deve ter 44 dígitos; protocolo ausente.\n"
            . "  Número da NF: \"000088138\"\n  Série: \"4\"\n  Emissão (ERP): \"20261127\"\n"
            . "  Chave NF-e: \"12345\"\n  Protocolo: (ausente)\n  Total (OpenCart): 10");
        (new Sexp())->processInvoicedOrder(['total' => 10], $invoice);
    }
}
