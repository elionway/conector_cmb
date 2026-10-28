<?php
use PHPUnit\Framework\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory;
use App\Services\ConnectorAlertReporter;

class ConnectorAlertHooksTest extends TestCase {
    public function testProviderWiresCommandContextAndGlobalHandler(): void {
        $directory=sys_get_temp_dir().'/cmb-hooks-'.bin2hex(random_bytes(8));
        $app=new Application(dirname(__DIR__, 2));
        $app->useStoragePath($directory);
        $app->instance('config',new Repository(['connector_alerts'=>['enabled'=>false]]));
        $provider=new App\Providers\AppServiceProvider($app);$provider->register();$provider->boot();
        $http=new Factory();$messages=[];
        $http->fake(function($req)use(&$messages){$e=json_decode($req->body(),true);$messages[]=$e;return Factory::response(['ok'=>true,'id'=>$e['id'],'result'=>'sent']);});
        $reporter=new ConnectorAlertReporter(['enabled'=>true,'secret'=>str_repeat('s',40),'connector'=>'hooks','environment'=>'testing','url'=>'https://cmb.example.test/notify'],$directory,$http);
        $app->instance(ConnectorAlertReporter::class,$reporter);
        try {
            $app['events']->dispatch(new Illuminate\Console\Events\CommandStarting('orders:send:erp',new Symfony\Component\Console\Input\ArrayInput([]),new Symfony\Component\Console\Output\BufferedOutput()));
            $handler=new App\Exceptions\Handler($app);
            $callbacks=(new ReflectionProperty(Illuminate\Foundation\Exceptions\Handler::class,'reportCallbacks'))->getValue($handler);
            $this->assertCount(1,$callbacks);
            $callbacks[0](new TypeError('Synthetic private error'));
            $this->assertCount(1,$messages);$this->assertSame('orders:send:erp',$messages[0]['command']);
            $app['events']->dispatch(new Illuminate\Console\Events\CommandStarting('migrate',new Symfony\Component\Console\Input\ArrayInput([]),new Symfony\Component\Console\Output\BufferedOutput()));
            ConnectorAlertReporter::reportSafely(new TypeError('Not a connector job'));
            $this->assertCount(1,$messages);
        } finally {
            if(is_dir($directory)){foreach(glob($directory.'/*')as $p){unlink($p);}rmdir($directory);}
            $app->flush();Illuminate\Container\Container::setInstance(null);Illuminate\Support\Facades\Facade::clearResolvedInstances();
        }
    }
}
