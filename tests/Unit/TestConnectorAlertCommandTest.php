<?php

use App\Console\Commands\TestConnectorAlert;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class TestConnectorAlertCommandTest extends TestCase
{
    /** @dataProvider outcomes */
    public function testSyntheticCommand(bool $enabled, bool $accepted, int $exit): void
    {
        $directory = sys_get_temp_dir() . '/cmb-command-' . bin2hex(random_bytes(8));
        $app = new Application(dirname(__DIR__, 2));
        $app->useStoragePath($directory);
        $app->instance('config', new Repository(['connector_alerts' => [
            'enabled' => $enabled, 'secret' => str_repeat('s', 40),
            'url' => 'https://cmb.example.test/notify', 'environment' => 'testing', 'connector' => 'real',
        ]]));
        $http = new Factory();
        $events = [];
        $http->fake(function ($request) use (&$events, $accepted) {
            $event = json_decode($request->body(), true);
            $events[] = $event;
            return Factory::response(['ok' => $accepted, 'id' => $event['id'], 'result' => 'sent'], $accepted ? 200 : 503);
        });
        $app->instance(Factory::class, $http);
        $command = new TestConnectorAlert();
        $command->setLaravel($app);
        try {
            $tester = new CommandTester($command);
            $this->assertSame($exit, $tester->execute([]));
            $this->assertCount($enabled ? 1 : 0, $events);
            $this->assertDirectoryDoesNotExist($directory . '/framework/connector-alerts');
            if ($enabled) {
                $this->assertStringStartsWith('cmb-test-', $events[0]['connector']);
                $this->assertArrayNotHasKey('message', $events[0]);
                $this->assertSame('runtime', $events[0]['category']);
            }
        } finally {
            if (is_dir($directory)) {
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
                rmdir($directory);
            }
            $app->flush();
            Illuminate\Container\Container::setInstance(null);
            Illuminate\Support\Facades\Facade::clearResolvedInstances();
        }
    }

    public static function outcomes(): array
    {
        return [[true, true, 0], [true, false, 1], [false, false, 1]];
    }
}
