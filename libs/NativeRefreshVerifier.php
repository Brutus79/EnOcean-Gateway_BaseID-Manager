<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Maintenance;
require_once __DIR__.'/ESP3Codec.php';
require_once __DIR__.'/ESP3StreamParser.php';
use EnOceanGatewayManager\Protocol\ESP3Codec;
use EnOceanGatewayManager\Protocol\ESP3StreamParser;
use RuntimeException;
use Throwable;

/** Read-only interpretation of the native 9.0 debug contract observed in runtime.
 * No command injection, no sleeps, no proxy. Unsupported schemas fail closed.
 * Debug evidence is local telemetry, not cryptographically authenticated origin.
 */
final class NativeRefreshVerifier
{
    private array $s;
    private ESP3StreamParser $parser;
    public function __construct(int $native,int $io,int $cursor,string $base,?int $counter,float $now,array $state=[])
    {
        $this->s=$state?:['native'=>$native,'io'=>$io,'cursor'=>$cursor,'base'=>$base,'counter'=>$counter,
            'startedAt'=>$now,'status'=>'PENDING','pending'=>false,'candidate'=>null,'reason'=>'','history'=>[]];
        $this->parser=new ESP3StreamParser();
        if(($this->s['fragment']??'')!=='')$this->parser->feed(ESP3Codec::fromHex($this->s['fragment']));
    }
    public function state(): array { return $this->s; }
    private function fail(string $reason): void {$this->s['status']='WARNING';$this->s['reason']=$reason;}
    public function consume(array $messages,float $now): string
    {
        if($this->s['status']!=='PENDING')return$this->s['status'];
        if($now<$this->s['startedAt']||$now-$this->s['startedAt']>180){$this->fail('Native refresh not proven within observation window.');return'WARNING';}
        foreach($messages as$m){
            if(!isset($m['TimeStamp'],$m['SenderID'],$m['Message'],$m['Data'])||!is_int($m['TimeStamp'])||!is_array($m['Data'])){
                $this->fail('Unsupported snapshot schema.');break;
            }
            if($m['TimeStamp']<=$this->s['cursor'])continue;
            // Kernel ring buffer cannot be treated as complete after excessive lag.
            if($m['TimeStamp']-$this->s['cursor']>8192){$this->fail('Debug history gap.');break;}
            $this->s['cursor']=$m['TimeStamp'];
            if($m['SenderID']!==$this->s['native']||$m['Message']!==10206)continue;
            $d=$m['Data'];
            if(count($d)!==4||!is_string($d[0])||!is_string($d[1])||$d[2]!==1){continue;}
            $bytes=base64_decode($d[1],true);
            if($bytes===false){$this->fail('Invalid native debug payload.');break;}
            try {
                if($d[0]==='TRANSMIT'){
                    $f=ESP3Codec::parseFrame($bytes);
                    if($this->s['pending'])throw new RuntimeException('Native request overlap.');
                    if($bytes===ESP3Codec::buildReadRequest('CO_RD_IDBASE')){
                        $this->s['pending']=true;$this->s['candidate']=null;$this->parser->reset();
                        $this->s['history'][]=['event'=>'NATIVE_IDBASE_TX','timestamp'=>$m['TimeStamp']];
                    }
                }elseif($d[0]==='Parse Buffer'&&$this->s['pending']){
                    foreach($this->parser->feed($bytes)as$event){
                        if($event['type']==='error')throw new RuntimeException('Native receive CRC/parser warning.');
                        if($event['packetType']!==2)continue; // ordinary radio traffic is not a response
                        if($this->s['candidate']!==null)throw new RuntimeException('Duplicate response.');
                        $value=ESP3Codec::parseReadResponse('CO_RD_IDBASE',$event['frame']);
                        $counter=$value['remainingWriteCyclesMode']==='unlimited'?255:$value['remainingWriteCycles'];
                        if($value['returnCode']!==0||$value['baseIdRawHex']!==$this->s['base']||$counter!==$this->s['counter']){
                            throw new RuntimeException('Native refresh differs from verified maintenance state.');
                        }
                        $this->s['candidate']=$value;
                    }
                }elseif($d[0]==='RESULT'&&$this->s['pending']){
                    if($this->s['candidate']===null||$this->parser->bufferedBytes()!==0
                        ||$bytes!=="\0".ESP3Codec::fromHex($this->s['base']))throw new RuntimeException('Native processing result not correlated.');
                    $this->s['history'][]=['event'=>'NATIVE_IDBASE_RESULT','timestamp'=>$m['TimeStamp']];
                    $this->s['status']='OBSERVED_NATIVE_REFRESH';$this->s['pending']=false;
                }
            }catch(Throwable $e){$this->fail($e->getMessage());break;}
        }
        $this->s['fragment']=$this->parser->bufferedHex();
        return$this->s['status'];
    }
}
