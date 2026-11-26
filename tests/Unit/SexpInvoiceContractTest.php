<?php

use App\Services\SexpInvoiceContract;
use PHPUnit\Framework\TestCase;

class SexpInvoiceContractTest extends TestCase
{
    public function testImportedInvoiceTypeWithoutSoapClient(): void
    {
        $docs = [
            'http://sexp.test/api?wsdl' => '<definitions xmlns="http://schemas.xmlsoap.org/wsdl/"><types><schema xmlns="http://www.w3.org/2001/XMLSchema"><import schemaLocation="?xsd=1"/></schema></types></definitions>',
            'http://sexp.test/api?xsd=1' => '<schema xmlns="http://www.w3.org/2001/XMLSchema"><include schemaLocation="?xsd=1"/><complexType name="notaFiscal"><sequence><element name="chaveNfe" type="string"/><element name="data" type="dateTime"/></sequence></complexType></schema>',
        ];
        $types = SexpInvoiceContract::types(array_key_first($docs), fn ($url) => $docs[$url]);
        $this->assertCount(1, $types);
        $this->assertStringContainsString('dateTime data;', $types[0]);
    }
}
