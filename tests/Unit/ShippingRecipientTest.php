<?php

use App\Services\ShippingRecipient;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class ShippingRecipientTest extends TestCase
{
    private $previous;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Container();
        Container::setInstance($app);
        $app->instance('config', new Repository());
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
    }

    public function testOnlyCpfAndShippingNameAreIncluded(): void
    {
        $shipping = ['firstname' => ' Ana ', 'lastname' => ' Silva ', 'custom_field' => ['CPF' => '012.345.678-90']];
        $this->assertSame(['cpf' => '01234567890', 'nome' => 'Ana Silva'], ShippingRecipient::fromShipping($shipping));
    }

    public function testNumericCustomFieldId(): void
    {
        config(['connector.shipping_recipient_cpf_field' => '17']);
        $result = ShippingRecipient::fromShipping(['firstname'=>'Ana', 'custom_field'=>[17=>'01234567890']]);
        $this->assertSame('01234567890', $result['cpf']);
    }

    public function testMissingCpfBlocksCreation(): void
    {
        $this->expectException(UnexpectedValueException::class);
        ShippingRecipient::fromShipping(['firstname'=>'Ana', 'custom_field'=>[7=>'123']]);
    }
}
