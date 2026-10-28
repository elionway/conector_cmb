<?php

use Illuminate\Container\Container;
use Illuminate\Config\Repository as Config;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

/** Real framework dependencies; no bootstrap, .env, database or live HTTP. */
final class ConnectorValidationTest extends TestCase
{
    protected function setUp(): void
    {
        $app = new Container();
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app->instance('config', new Config());
        $app->instance('cache', new CacheRepository(new ArrayStore()));
        $app->instance(Factory::class, new Factory());
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        Mockery::close();
        Facade::clearResolvedInstances();
        Container::setInstance(null);
    }

    private function response(string $url, $body, int $status): void
    {
        Http::fake(function ($request) use ($url, $body, $status) {
            if ($request->url() !== $url) {
                throw new RuntimeException('Unexpected HTTP request blocked');
            }
            return Http::response($body, $status);
        });
    }

    private function token()
    {
        $class = new ReflectionClass(App\Services\Opencart::class);
        $service = $class->newInstanceWithoutConstructor();
        $class->getProperty('api_url')->setValue($service, 'https://connector.invalid/api');
        $class->getMethod('accessToken')->invoke($service);
        return $class->getProperty('access_token')->getValue($service);
    }

    private function cities($uf = 'SP')
    {
        return (new ReflectionMethod(App\Models\Valid::class, 'getCitiesFromBrasilApiByUF'))->invoke(null, $uf);
    }

    public function test403IsReportedBeforeReadingToken(): void
    {
        $this->response('https://connector.invalid/api/access_token', ['error' => ['warning' => 'Access denied.']], 403);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 403');
        $this->token();
    }

    public function testSyntheticTokenAccepted(): void
    {
        $this->response('https://connector.invalid/api/access_token', ['access_token' => 'synthetic'], 200);
        $this->assertSame('synthetic', $this->token());
        Http::assertSentCount(1);
    }

    public function testBadHttpStatusIsReportedBeforeDecoding(): void
    {
        $this->response('https://brasilapi.com.br/api/ibge/municipios/v1/SP?providers=dados-abertos-br,gov,wikipedia', '<html>unavailable</html>', 503);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 503');
        $this->cities();
    }

    public function testCitiesNormalizedAndCached(): void
    {
        $this->response('https://brasilapi.com.br/api/ibge/municipios/v1/SP?providers=dados-abertos-br,gov,wikipedia', [['nome' => 'Cidade Teste', 'codigo_ibge' => '3500000']], 200);
        $first = $this->cities();
        $this->assertSame('3500000', $first['cidade teste']['codigo_ibge']);
        $this->assertSame($first, $this->cities());
        Http::assertSentCount(1);
    }

    public function testLowercaseUfIsNormalized(): void
    {
        $this->response('https://brasilapi.com.br/api/ibge/municipios/v1/SP?providers=dados-abertos-br,gov,wikipedia', [['nome' => 'Cidade Teste', 'codigo_ibge' => '3500000']], 200);
        $this->assertSame('3500000', $this->cities(' sp ')['cidade teste']['codigo_ibge']);
        $this->cities('SP');
        Http::assertSentCount(1);
    }

    /** @dataProvider invalidTokens */
    public function testInvalidTokenBodyIsRejected($body): void
    {
        $this->response('https://connector.invalid/api/access_token', $body, 200);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('token ausente ou inválido');
        $this->token();
    }

    public function invalidTokens(): array
    {
        return [['<html>invalid</html>'], [[]], [['access_token' => null]], [['access_token' => ' ']], [['access_token' => ['invalid']]]];
    }

    /** @dataProvider invalidCities */
    public function testInvalidMunicipalityBodiesAreNotCached($body): void
    {
        $this->response('https://brasilapi.com.br/api/ibge/municipios/v1/SP?providers=dados-abertos-br,gov,wikipedia', $body, 200);
        try {
            $this->cities();
            $this->fail('Invalid response accepted');
        } catch (RuntimeException $e) {
            $this->assertSame('Resposta inválida na consulta de municípios.', $e->getMessage());
            $this->assertNull(Illuminate\Support\Facades\Cache::get('SP'));
        }
    }

    public function invalidCities(): array
    {
        return [['<html>invalid</html>'], [[]], [['error' => 'unavailable']], [[['nome' => 'Cidade Teste']]], [[['nome' => [], 'codigo_ibge' => '3500000']]], [[['nome' => 'Cidade Teste', 'codigo_ibge' => 'invalid']]]];
    }

    public function testInvalidUfMakesNoRequest(): void
    {
        Http::fake(function () { throw new LogicException('No request allowed'); });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UF inválida');
        $this->cities('../SP');
    }

    public function testMunicipalityConnectionFailureIsExplicit(): void
    {
        Http::fake(function () { throw new Illuminate\Http\Client\ConnectionException('synthetic'); });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Falha de conexão na consulta de municípios.');
        $this->cities();
    }

    public function testTokenConnectionFailureIsExplicit(): void
    {
        Http::fake(function () { throw new Illuminate\Http\Client\ConnectionException('synthetic'); });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Falha de conexão na autenticação da API CMB.');
        $this->token();
    }

    private function cepFixture($body, int $status = 200): void
    {
        Http::fake(function ($request) use ($body, $status) {
            $url = parse_url($request->url());
            parse_str($url['query'] ?? '', $query);
            $this->assertSame('clubedamedalha.com.br', $url['host']);
            $this->assertSame('/index.php', $url['path']);
            $this->assertSame(['route' => 'extension/module/ow_cep/getcep', 'cep' => '01001000'], $query);
            return Http::response($body, $status);
        });
    }

    private function consultCep($cep = '01001000')
    {
        return (new ReflectionMethod(App\Models\Valid::class, 'consultCep'))->invoke(null, $cep);
    }

    private function validCep(): array
    {
        return ['cep' => '01001-000', 'logradouro' => 'Rua Teste', 'bairro' => 'Bairro Teste', 'localidade' => 'Cidade Teste', 'uf' => 'SP', 'ibge' => '3550308'];
    }

    public function testCmbCepAndIbgeShareOneRequest(): void
    {
        $this->cepFixture($this->validCep());
        $data = $this->consultCep('01001-000');
        $this->assertSame('01001000', $data['cep']);
        $this->assertSame('Cidade Teste', $data['cidade']);
        $this->assertSame('3550308', $data['ibge']);
        $code = (new ReflectionMethod(App\Models\Valid::class, 'getIbgeCodigoMunicipioByOrderInfo'))->invoke(null, ['shipping' => ['postcode' => '01001000']]);
        $this->assertSame('50308', $code);
        Http::assertSentCount(1);
    }

    public function testLegacyCepCacheWithoutIbgeIsNotReused(): void
    {
        Illuminate\Support\Facades\Cache::put('01001000', ['cep' => '01001000', 'cidade' => 'Old city']);
        $this->cepFixture($this->validCep());
        $this->assertSame('3550308', $this->consultCep()['ibge']);
        Http::assertSentCount(1);
    }

    /** @dataProvider invalidCepBodies */
    public function testCmbCepRejectsInvalidBodyWithoutFallbackOrCache($body, string $message): void
    {
        $this->cepFixture($body);
        try {
            $this->consultCep();
            $this->fail('Invalid CEP response accepted');
        } catch (RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertNull(Illuminate\Support\Facades\Cache::get('cmb:cep:01001000'));
            Http::assertSentCount(1);
        }
    }

    public function invalidCepBodies(): array
    {
        $bad = 'Resposta inválida na consulta de CEP do CMB.';
        return [
            ['<html>unavailable</html>', $bad],
            [[], $bad],
            [['erro' => true], 'CEP não encontrado na consulta do CMB.'],
            [['error' => 'synthetic'], 'CEP não encontrado na consulta do CMB.'],
            [['cep' => '01001-000'], $bad],
            [['cep' => '01001-000', 'localidade' => 'Cidade Teste', 'uf' => 'SP', 'ibge' => 'bad'], $bad],
            [['cep' => '99999-999', 'localidade' => 'Cidade Teste', 'uf' => 'SP', 'ibge' => '3550308'], $bad],
        ];
    }

    public function testCmbCepHttpFailureHasNoFallback(): void
    {
        $this->cepFixture('<html>timeout</html>', 504);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('consulta de CEP do CMB: HTTP 504');
        $this->consultCep();
    }

    public function testCmbCepInvalidInputMakesNoRequest(): void
    {
        Http::fake(function () { throw new LogicException('Unexpected request'); });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CEP inválido');
        $this->consultCep('123');
    }

    public function testCmbCepConnectionFailureIsExplicit(): void
    {
        Http::fake(function () { throw new Illuminate\Http\Client\ConnectionException('synthetic'); });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Falha de conexão na consulta de CEP do CMB.');
        $this->consultCep();
    }

    public function testOrder404ContractRemainsUnchanged(): void
    {
        $this->response('https://connector.invalid/api/order', [], 404);
        $class = new ReflectionClass(App\Services\Opencart::class);
        $service = $class->newInstanceWithoutConstructor();
        $class->getProperty('api_url')->setValue($service, 'https://connector.invalid/api');
        $this->assertSame([], $class->getMethod('execute')->invoke($service, 'order', 'get', null, []));
    }

    public function testBillingDateFormatWithPinnedCarbon(): void
    {
        $this->assertSame('20261028', Carbon\Carbon::createFromFormat('Y-m-d', '2026-10-28')->format('Ymd'));
    }

    private function output($command): void
    {
        $command->setOutput(new Illuminate\Console\OutputStyle(new Symfony\Component\Console\Input\ArrayInput([]), new Symfony\Component\Console\Output\BufferedOutput()));
    }

    public function testSexpExistingSyntheticOrderDoesNotWrite(): void
    {
        $oc = Mockery::mock();
        $oc->shouldReceive('searchStatuses')->with('reimport_new_order')->once()->andReturn(1);
        $oc->shouldReceive('searchStatuses')->with('in_dispatchment_order')->once()->andReturn(20);
        $oc->shouldReceive('orders')->with(['filter_status' => 1])->once()->andReturn([['order_id' => 900001, 'order_status_id' => 20]]);
        $oc->shouldNotReceive('orderHistory');
        $oc->shouldNotReceive('sendChangeOrdersNotValited');
        $sexp = Mockery::mock();
        $sexp->shouldReceive('getOrder')->with(900001)->once()->andReturn(['synthetic_existing' => true]);
        $sexp->shouldNotReceive('createOrder');
        App\Support\Opencart::swap($oc);
        App\Support\Sexp::swap($sexp);
        $command = new App\Console\Commands\SendOrdersToSexp();
        $this->output($command);
        ob_start();
        try { $command->handle(); } finally { ob_end_clean(); }
        $this->assertTrue(true);
    }

    public function testErpEmptySyntheticBatchDoesNotWrite(): void
    {
        $oc = Mockery::mock();
        $oc->shouldReceive('searchStatuses')->with('after_dispatch_order')->once()->andReturn(30);
        $oc->shouldReceive('orders')->with(['filter_status' => 30])->once()->andReturn([]);
        $oc->shouldNotReceive('orderHistory');
        $erp = Mockery::mock();
        $erp->shouldNotReceive('createOrder');
        App\Support\Opencart::swap($oc);
        App\Support\Erp::swap($erp);
        $command = new App\Console\Commands\SendOrdersToErp();
        $this->output($command);
        $command->handle();
        $this->assertTrue(true);
    }

    public function testSexpUnavailableStillAttemptsStoreErrorUpdate(): void
    {
        // Documents why an unreachable SEXP does not make the real command read-only.
        $oc = Mockery::mock();
        $oc->shouldReceive('searchStatuses')->with('reimport_new_order')->once()->andReturn(1);
        $oc->shouldReceive('orders')->with(['filter_status' => 1])->once()->andReturn([['order_id' => 900001, 'order_status_id' => 1]]);
        $oc->shouldReceive('sendChangeOrdersNotValited')->with(900001, Mockery::type('string'))->once();
        $oc->shouldNotReceive('orderHistory');
        $sexp = Mockery::mock();
        $sexp->shouldReceive('getOrder')->with(900001)->once()->andThrow(new RuntimeException('Synthetic SEXP unavailable'));
        $sexp->shouldReceive('createOrder')->once()->andThrow(new RuntimeException('Synthetic SEXP unavailable'));
        App\Support\Opencart::swap($oc);
        App\Support\Sexp::swap($sexp);
        $command = new App\Console\Commands\SendOrdersToSexp();
        $this->output($command);
        ob_start();
        try { $command->handle(); } finally { ob_end_clean(); }
        $this->assertTrue(true);
    }
}
