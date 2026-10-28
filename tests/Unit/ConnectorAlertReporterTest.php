<?php
use PHPUnit\Framework\TestCase;
use Illuminate\Http\Client\Factory;
use App\Services\ConnectorAlertReporter;

class ConnectorAlertReporterTest extends TestCase {
    private string $directory;
    private array $options;
    private Factory $http;
    protected function setUp(): void {
        $this->directory=sys_get_temp_dir().'/cmb-alert-test-'.bin2hex(random_bytes(8));
        $this->options=['enabled'=>true,'secret'=>str_repeat('s',40),'connector'=>'test-connector','environment'=>'testing','url'=>'https://cmb.example.test/index.php?route=extension/module/ow_connector_notify'];
        $this->http=new Factory();
    }
    protected function tearDown(): void {
        if(is_dir($this->directory)) {foreach(glob($this->directory.'/*') as $p){unlink($p);}rmdir($this->directory);}
    }
    private function reporter(): ConnectorAlertReporter {
        $r=new ConnectorAlertReporter($this->options,$this->directory,$this->http);$r->setCommand('orders:send:sexp');return $r;
    }
    private function state(): array {return json_decode(file_get_contents($this->directory.'/state.json'),true);}
    private function pending(): array {return array_values($this->state()['pending']);}
    private function ack($request) {
        $event=json_decode($request->body(),true);
        return Factory::response(['ok'=>true,'id'=>$event['id'],'result'=>'sent'],200);
    }
    public function testDisabledDoesNotStoreOrSend(): void {
        $this->options['enabled']=false;$this->http->fake(function(){throw new LogicException('No HTTP allowed');});
        $this->reporter()->report(new RuntimeException('synthetic'));
        $this->assertDirectoryDoesNotExist($this->directory);
    }
    public function testMissingKeyPreservesQueueWithoutSending(): void {
        $this->options['secret']='';$this->http->fake(function(){throw new LogicException('No HTTP allowed');});
        $r=$this->reporter();$r->report(new RuntimeException('synthetic'));
        $this->assertCount(1,$this->pending());$this->assertSame(1,$r->flush()['failed']);
    }
    public function testUnrelatedCommandDoesNotReport(): void {
        $r=$this->reporter();$r->setCommand('migrate');$r->report(new RuntimeException('synthetic'));
        $this->assertDirectoryDoesNotExist($this->directory);
    }
    public function testExpectedInputValidationIsNotCritical(): void {
        $this->reporter()->report(new RuntimeException('CEP não encontrado na consulta do CMB.'));
        $this->assertDirectoryDoesNotExist($this->directory);
    }
    public function testBodyIsSanitizedAndSigned(): void {
        $captured=null;
        $this->http->fake(function($req)use(&$captured){$captured=$req;return $this->ack($req);});
        $this->reporter()->report(new PDOException('SQL with customer@example.test and password=synthetic-private-data'),'database');
        $this->assertNotNull($captured);
        $body=$captured->body();$event=json_decode($body,true);
        $this->assertStringNotContainsString('customer@example.test',$body);
        $this->assertStringNotContainsString('synthetic-private-data',$body);
        $this->assertStringNotContainsString('SQL',$body);
        $this->assertSame('database',$event['category']);
        $time=$captured->header('X-CMB-Timestamp')[0];
        $this->assertSame(hash_hmac('sha256',$time."\n".$body,$this->options['secret']),$captured->header('X-CMB-Signature')[0]);
        $this->assertSame([],$this->state()['pending']);
    }
    public function testDeliveryFailureQueuesWithBackoffAndSameIncidentId(): void {
        $calls=0;$this->http->fake(function()use(&$calls){++$calls;throw new RuntimeException('simulated network failure');});
        $r=$this->reporter();$r->report(new RuntimeException('synthetic'),'sexp');
        $first=$this->pending()[0];
        $r->report(new RuntimeException('different private message'),'sexp');
        $second=$this->pending()[0];
        $this->assertSame($first['event']['id'],$second['event']['id']);
        $this->assertSame(2,$second['event']['repeats']);$this->assertSame(1,$calls);
        $this->assertGreaterThan(time(),$second['next']);
    }
    public function testSameThrowableIsOnlyCapturedOnce(): void {
        $this->http->fake(fn()=>Factory::response([],503));$r=$this->reporter();$error=new RuntimeException('synthetic');
        $r->report($error);$r->report($error);
        $this->assertSame(1,$this->pending()[0]['event']['repeats']);
    }
    public function testFalseAckOrWrongIdNeverDiscardsIncident(): void {
        $this->http->fake(fn()=>Factory::response(['ok'=>true,'id'=>'wrong','result'=>'sent'],200));
        $this->reporter()->report(new RuntimeException('synthetic'));
        $this->assertCount(1,$this->pending());
    }
    public function testSuccessCooldownGroupsNewOccurrences(): void {
        $count=0;$this->http->fake(function($req)use(&$count){++$count;return $this->ack($req);});
        $r=$this->reporter();$r->report(new RuntimeException('first'));$r->report(new RuntimeException('second'));
        $this->assertSame(1,$count);$this->assertCount(1,$this->pending());
        $this->assertGreaterThanOrEqual(time()+1798,$this->pending()[0]['next']);
    }
    public function testRetryOnlySendsAlert(): void {
        $available=false;$this->http->fake(function($req)use(&$available){return $available?$this->ack($req):Factory::response([],503);});
        $r=$this->reporter();$r->report(new RuntimeException('synthetic'));
        $state=$this->state();foreach($state['pending']as &$item){$item['next']=0;}unset($item);
        file_put_contents($this->directory.'/state.json',json_encode($state));
        $available=true;
        $this->assertSame(['accepted'=>1,'pending'=>0,'failed'=>0],$r->flush());
    }
    public function testAuthFailureUsesKnownCodeWithoutRawMessage(): void {
        $body=null;$this->http->fake(function($req)use(&$body){$body=json_decode($req->body(),true);return $this->ack($req);});
        $this->reporter()->report(new RuntimeException('Falha na autenticação da API CMB: HTTP 403.'));
        $this->assertSame('auth',$body['category']);$this->assertSame(403,$body['http_status']);
    }
    public function testQueueIsPrivate(): void {
        $this->http->fake(fn()=>Factory::response([],503));$this->reporter()->report(new RuntimeException('synthetic'));
        $this->assertSame(0600,fileperms($this->directory.'/state.json')&0777);
        $this->assertStringNotContainsString($this->options['secret'],file_get_contents($this->directory.'/state.json'));
    }
    public function testPlainHttpAndUserInfoAreRejected(): void {
        $this->options['url']='https://user@cmb.example.test/path';$this->reporter()->report(new RuntimeException('synthetic'));
        $this->assertCount(1,$this->pending());
        $this->options['url']='http://cmb.example.test/path';$this->reporter()->report(new RuntimeException('synthetic'));
        $this->assertSame(2,$this->pending()[0]['event']['repeats']);
    }
    public function testNewErrorDuringDeliveryIsRetainedWithoutHoldingTheLock(): void {
        $reporter=$this->reporter();$calls=0;
        $this->http->fake(function($req)use($reporter,&$calls){
            ++$calls;
            $reporter->report(new RuntimeException('Concurrent synthetic occurrence'));
            return $this->ack($req);
        });
        $reporter->report(new RuntimeException('Initial synthetic occurrence'));
        $this->assertSame(1,$calls);
        $this->assertSame(1,$this->pending()[0]['event']['repeats']);
        $this->assertGreaterThan(time(),$this->pending()[0]['next']);
    }
}
