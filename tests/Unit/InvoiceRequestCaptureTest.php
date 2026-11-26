<?php

use App\Services\InvoiceRequestCapture;
use PHPUnit\Framework\TestCase;

final class InvoiceRequestCaptureTest extends TestCase
{
    public function testRetriesKeepSeparatePrivateCopiesOfExactBody(): void
    {
        $root = sys_get_temp_dir() . '/cmb-capture-' . bin2hex(random_bytes(6));
        $xml = "<?xml version=\"1.0\"?>\n<nota>123</nota>\n";
        $paths = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $paths[] = $path = InvoiceRequestCapture::save($root, 123, $xml, 'http://test.invalid/', 'text/xml');
                $this->assertSame($xml, file_get_contents($path . '/envio.xml'));
                $data = json_decode(file_get_contents($path . '/dados.json'), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame(hash('sha256', $xml), $data['sha256']);
                $this->assertSame(0600, fileperms($path . '/envio.xml') & 0777);
                $this->assertSame(0700, fileperms($path) & 0777);
            }
            $this->assertNotSame($paths[0], $paths[1]);
        } finally {
            foreach ($paths as $path) {
                unlink($path . '/envio.xml');
                unlink($path . '/dados.json');
                rmdir($path);
            }
            if (is_dir($root)) rmdir($root);
        }
    }
}
