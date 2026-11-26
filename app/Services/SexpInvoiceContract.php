<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use RuntimeException;

final class SexpInvoiceContract
{
    /** Reads inline schemas and imports served by the same SEXP endpoint. */
    public static function types(string $wsdl, callable $read): array
    {
        $origin = new Uri($wsdl);
        $pending = [$wsdl];
        $seen = [];
        $types = [];
        while ($pending) {
            $url = array_shift($pending);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            if (count($seen) > 32) {
                throw new RuntimeException('Contrato excedeu o limite de documentos.');
            }
            $doc = new DOMDocument();
            if (!$doc->loadXML($read($url), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new RuntimeException('XML do contrato inválido.');
            }
            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
            $xpath->registerNamespace('wsdl', 'http://schemas.xmlsoap.org/wsdl/');
            foreach ($xpath->query('//xs:complexType') as $type) {
                $name = $type->getAttribute('name') ?: ($type->parentNode instanceof DOMElement ? $type->parentNode->getAttribute('name') : '');
                $lines = [];
                foreach ($xpath->query('./xs:sequence/xs:element | ./xs:all/xs:element | ./xs:complexContent/xs:extension/xs:sequence/xs:element', $type) as $field) {
                    $lines[] = trim($field->getAttribute('type') . ' ' . ($field->getAttribute('name') ?: $field->getAttribute('ref')));
                }
                if ($lines) {
                    $types[] = "struct $name {\n  " . implode(";\n  ", $lines) . ";\n}";
                }
            }
            foreach ($xpath->query('//wsdl:import/@location | //xs:import/@schemaLocation | //xs:include/@schemaLocation') as $link) {
                $next = UriResolver::resolve(new Uri($url), new Uri($link->nodeValue))->withFragment('');
                if ($next->getScheme() === $origin->getScheme() && $next->getAuthority() === $origin->getAuthority()) {
                    $pending[] = (string)$next;
                }
            }
        }
        return $types;
    }
}
