<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/NativeRefreshVerifier.php';
use EnOceanGatewayManager\Maintenance\NativeRefreshVerifier;
use EnOceanGatewayManager\Protocol\ESP3Codec;
$count=0;$check=static function(bool$b,string$l)use(&$count):void{$count++;if(!$b)throw new RuntimeException($l);};
$f=static function(string$b,int$c=8):string{$d="\0".hex2bin($b);$h=pack('nCC',5,1,2);return"\x55".$h.chr(ESP3Codec::crc8($h)).$d.chr($c).chr(ESP3Codec::crc8($d.chr($c)));};
$event=static fn(int$t,string$name,string$bytes,int$sender=10):array=>['TimeStamp'=>$t,'SenderID'=>$sender,'Message'=>10206,'Data'=>[$name,base64_encode($bytes),1,0]];
$tx=$event(101,'TRANSMIT',ESP3Codec::buildReadRequest('CO_RD_IDBASE'));
$rx=$event(102,'Parse Buffer',$f('FF900000'));
$result=$event(103,'RESULT',hex2bin('00FF900000'));
$v=new NativeRefreshVerifier(10,20,100,'FF900000',8,100);
$check($v->consume([$tx],101)==='PENDING','TX alone not success');
$check($v->consume([$rx],102)==='PENDING','response alone not uptake');
$check($v->consume([$result],103)==='OBSERVED_NATIVE_REFRESH','native processed RESULT');
foreach(['wrongBase','wrongCounter','wrongCRC','overlap','duplicate','unmatchedResult','oldTrace','foreignSender','timeout','historyGap']as$fault){
    $v=new NativeRefreshVerifier(10,20,100,'FF900000',8,100);$rows=[$tx,$rx,$result];
    switch($fault){
        case'wrongBase':$rows[1]=$event(102,'Parse Buffer',$f('FF910000'));break;
        case'wrongCounter':$rows[1]=$event(102,'Parse Buffer',$f('FF900000',7));break;
        case'wrongCRC':$bad=$f('FF900000');$bad[-1]=chr(ord($bad[-1])^1);$rows[1]=$event(102,'Parse Buffer',$bad);break;
        case'overlap':$rows[1]=$event(102,'TRANSMIT',ESP3Codec::buildReadRequest('CO_RD_VERSION'));break;
        case'duplicate':$rows[1]=$event(102,'Parse Buffer',$f('FF900000').$f('FF900000'));break;
        case'unmatchedResult':$rows=[$result];break;
        case'oldTrace':foreach($rows as&$r)$r['TimeStamp']-=100;unset($r);break;
        case'foreignSender':foreach($rows as&$r)$r['SenderID']=30;unset($r);break;
        case'timeout':$rows=[];break;
        case'historyGap':$rows[0]['TimeStamp']=9000;break;
    }
    $status=$v->consume($rows,$fault==='timeout'?281:103);
    $check($status!=='OBSERVED_NATIVE_REFRESH','no false return '.$fault);
}
// Fragments are persisted across fresh PHP callback objects, not discarded.
$raw=$f('FF900000');$v=new NativeRefreshVerifier(10,20,100,'FF900000',8,100);
$check($v->consume([$tx,$event(102,'Parse Buffer',substr($raw,0,4))],101)==='PENDING','fragment pending');
$s=$v->state();$v=new NativeRefreshVerifier(10,20,100,'FF900000',8,100,$s);
$check($v->consume([$event(103,'Parse Buffer',substr($raw,4)),$event(104,'RESULT',hex2bin('00FF900000'))],102)==='OBSERVED_NATIVE_REFRESH','fragment resumed');
// Native cache retains A, replacement initially B, simulated restoration B->A.
// This unit test verifies return proof; real native ERP1 bytes are a separate runtime test.
$v=new NativeRefreshVerifier(10,20,100,'FF900000',7,100);
$check($v->consume([$tx,$event(102,'Parse Buffer',$f('FF900000',7)),$result],103)==='OBSERVED_NATIVE_REFRESH','replacement restored target');
echo "PASS: native refresh {$count} assertions\n";
