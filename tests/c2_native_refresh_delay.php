<?php
declare(strict_types=1);
// Only the pending return delay: virtual time and synthetic native telemetry.
require_once __DIR__.'/../libs/NativeRefreshVerifier.php';
use EnOceanGatewayManager\Maintenance\NativeRefreshVerifier;
use EnOceanGatewayManager\Protocol\ESP3Codec;
$check=static function(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);};
$v=new NativeRefreshVerifier(10,20,100,'FF900000',8,100);
foreach([100.1,160.0,218.9]as$now)
    $check($v->consume([],$now)==='PENDING','No native evidence means pending, not returned');
$event=static fn(int $cursor,string $label,string $bytes):array=>[
    'TimeStamp'=>$cursor,'SenderID'=>10,'Message'=>10206,'Data'=>[$label,base64_encode($bytes),1,0]];
$data=hex2bin('00FF900000');$optional="\x08";$header=pack('nCC',5,1,2);
$response="\x55".$header.chr(ESP3Codec::crc8($header)).$data.$optional.chr(ESP3Codec::crc8($data.$optional));
$check($v->consume([$event(101,'TRANSMIT',ESP3Codec::buildReadRequest('CO_RD_IDBASE'))],219)==='PENDING','Delayed TX alone is not uptake');
$check($v->consume([$event(102,'Parse Buffer',$response)],219.01)==='PENDING','Delayed response alone is not uptake');
$check($v->consume([$event(103,'RESULT',hex2bin('00FF900000'))],219.02)==='OBSERVED_NATIVE_REFRESH','Complete delayed proof recognized immediately, no extra waiting period');
$v=new NativeRefreshVerifier(10,20,100,'FF900000',8,100);
$check($v->consume([],281)==='WARNING','Observation timeout must never imply success');
echo "PASS: delayed native refresh remains pending until complete native proof; immediate recognition; missing proof warns\n";
