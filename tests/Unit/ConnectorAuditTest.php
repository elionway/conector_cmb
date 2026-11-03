<?php

use App\Services\ConnectorAudit;
use PHPUnit\Framework\TestCase;

class ConnectorAuditTest extends TestCase
{
    private string $path;
    protected function setUp(): void { $this->path = sys_get_temp_dir() . '/cmb-audit-' . bin2hex(random_bytes(8)); }
    protected function tearDown(): void
    {
        if (!is_dir($this->path)) { return; }
        foreach (glob($this->path . '/*') as $p) { unlink($p); }
        rmdir($this->path);
    }
    private function events(): array
    {
        $events = [];
        foreach (glob($this->path . '/*.jsonl') as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) { $events[] = json_decode($line, true, 32, JSON_THROW_ON_ERROR); }
        }
        return $events;
    }
    public function testAutomaticBlockedAndManualOperationsTracedWithoutPayloads(): void
    {
        $audit = new ConnectorAudit($this->path);
        $audit->setEnabled(true);
        foreach (ConnectorAudit::COMMANDS as $command) { $this->assertFalse($audit->begin($command, false)); }
        $this->assertTrue($audit->begin('orders:update:tracking', true));
        $this->assertSame(42, $audit->operation('sexp.query', fn () => 42, ['order_id'=>123,'password'=>'SECRET','body'=>'CLIENT']));
        $audit->record('sexp.status_received', ['status_code'=>'3','tracking_present'=>false, 'message'=>'SECRET']);
        $audit->finish(0);
        $events = $this->events();
        $this->assertContains('sexp.query.requested', array_column($events, 'step'));
        $this->assertContains('sexp.query.returned', array_column($events, 'step'));
        $encoded = json_encode($events);
        $this->assertStringNotContainsString('SECRET', $encoded);
        $this->assertStringNotContainsString('CLIENT', $encoded);
        $last = end($events);
        $this->assertSame('execution.finished', $last['step']);
        $this->assertNotEmpty($last['run']);
        $this->assertSame(0600, fileperms(glob($this->path . '/*.jsonl')[0]) & 0777);
    }
    public function testEnablingDuringNormalExecutionBlocksNextBoundary(): void
    {
        $worker = new ConnectorAudit($this->path);
        $this->assertTrue($worker->begin('orders:send:erp', false));
        $worker->operation('erp.read', fn () => true);
        (new ConnectorAudit($this->path))->setEnabled(true);
        $called = false;
        try {
            $worker->operation('erp.create', function () use (&$called) { $called = true; });
            $this->fail('Automatic write was allowed');
        } catch (RuntimeException $e) { $this->assertFalse($called); }
        (new ConnectorAudit($this->path))->setEnabled(false);
        $this->assertSame('ok', $worker->operation('erp.read', fn () => 'ok'));
    }
    public function testFailuresAreLoggedWithoutExceptionMessageAndRethrown(): void
    {
        $audit = new ConnectorAudit($this->path);
        $audit->setEnabled(true);
        $audit->begin('orders:send:sexp', true);
        $error = new RuntimeException('PRIVATE CUSTOMER');
        try { $audit->operation('sexp.create', function () use ($error) { throw $error; }); }
        catch (RuntimeException $actual) { $this->assertSame($error, $actual); }
        $events = $this->events();
        $this->assertContains('sexp.create.failed', array_column($events, 'step'));
        $this->assertStringNotContainsString('PRIVATE', json_encode($events));
    }

    public function testEveryRealBusinessCommandStopsBeforeCallingServices(): void
    {
        $app = new Illuminate\Foundation\Application(dirname(__DIR__, 2));
        $audit = new ConnectorAudit($this->path);
        $audit->setEnabled(true);
        $app->instance(ConnectorAudit::class, $audit);
        try {
            foreach (['SendOrdersToSexp','SendOrdersToErp','UpdateOrderTracking','UpdateOrderInvoice','UpdateOrderShipment','CheckOrderErp'] as $name) {
                $class = 'App\\Console\\Commands\\' . $name;
                $command = new $class();
                $command->setLaravel($app);
                $tester = new Symfony\Component\Console\Tester\CommandTester($command);
                $this->assertSame(1, $tester->execute([]));
                $this->assertStringContainsString('--audit-manual', $tester->getDisplay());
            }
        } finally {
            $app->flush(); Illuminate\Container\Container::setInstance(null);
            Illuminate\Support\Facades\Facade::clearResolvedInstances();
        }
    }

    public function testRealSexpConsultationLogsOnlyStatusMetadata(): void
    {
        $app = new Illuminate\Foundation\Application(dirname(__DIR__, 2));
        $app->instance('config', new Illuminate\Config\Repository(['connector'=>['main_address'=>[]]]));
        $audit = new ConnectorAudit($this->path);
        $audit->setEnabled(true);
        $audit->begin('orders:update:tracking', true);
        $app->instance(ConnectorAudit::class, $audit);
        Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        $http = new Illuminate\Http\Client\Factory();
        $http->fake(fn () => Illuminate\Http\Client\Factory::response(
            '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns2:consultarRemessaResponse xmlns:ns2="http://ws.sexpwebservice.cmb.gov.br/"><informacoesRemessa><status><codigo>3</codigo></status><nomeCliente>PRIVATE CUSTOMER</nomeCliente></informacoesRemessa></ns2:consultarRemessaResponse></soap:Body></soap:Envelope>', 200));
        $app->instance(Illuminate\Http\Client\Factory::class, $http);
        try {
            $result = (new App\Services\Sexp())->getOrder(900001);
            $this->assertSame('3', $result['status']['codigo']);
            $events = $this->events();
            $this->assertContains('sexp.query.requested', array_column($events,'step'));
            $statuses = array_values(array_filter($events, fn ($e) => $e['step']==='sexp.status_received'));
            $this->assertSame('3', $statuses[0]['details']['status_code']);
            $this->assertFalse($statuses[0]['details']['tracking_present']);
            $this->assertStringNotContainsString('PRIVATE CUSTOMER', json_encode($events));
        } finally {
            $app->flush(); Illuminate\Container\Container::setInstance(null);
            Illuminate\Support\Facades\Facade::clearResolvedInstances();
        }
    }

    public function testRealScheduledEventsRecheckAuditFlagWithoutRestart(): void
    {
        $app = new Illuminate\Foundation\Application(dirname(__DIR__, 2));
        $audit = new ConnectorAudit($this->path);
        $app->instance(ConnectorAudit::class, $audit);
        $app->instance(Illuminate\Console\Scheduling\EventMutex::class, $this->createMock(Illuminate\Console\Scheduling\EventMutex::class));
        $app->instance(Illuminate\Console\Scheduling\SchedulingMutex::class, $this->createMock(Illuminate\Console\Scheduling\SchedulingMutex::class));
        try {
            $schedule = new Illuminate\Console\Scheduling\Schedule();
            $reflection = new ReflectionClass(App\Console\Kernel::class);
            $reflection->getMethod('schedule')->invoke($reflection->newInstanceWithoutConstructor(), $schedule);
            $this->assertCount(5, $schedule->events());
            foreach ($schedule->events() as $event) { $this->assertTrue($event->filtersPass($app)); }
            $audit->setEnabled(true);
            foreach ($schedule->events() as $event) { $this->assertFalse($event->filtersPass($app)); }
            $audit->setEnabled(false);
            foreach ($schedule->events() as $event) { $this->assertTrue($event->filtersPass($app)); }
        } finally {
            $app->flush(); Illuminate\Container\Container::setInstance(null);
            Illuminate\Support\Facades\Facade::clearResolvedInstances();
        }
    }

    public function testUnreadableAuditDirectoryDoesNotSilentlyEnableAutomation(): void
    {
        $audit = new ConnectorAudit($this->path);
        $audit->setEnabled(true);
        chmod($this->path, 0000);
        try {
            $audit->enabled();
            $this->fail('Unreadable audit state was treated as disabled');
        } catch (RuntimeException $e) {
            $this->assertSame('Audit storage is not accessible to this user', $e->getMessage());
        } finally { chmod($this->path, 0700); }
    }
}
