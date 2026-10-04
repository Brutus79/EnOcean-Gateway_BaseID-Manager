<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/ESP3TransportArbiterCore.php';
require_once __DIR__.'/../libs/ESP3StreamParser.php';
require_once __DIR__.'/../libs/C2Session.php';
use EnOceanGatewayManager\Maintenance\C2Session;
use EnOceanGatewayManager\Transport\ESP3TransportArbiterCore;
use EnOceanGatewayManager\Protocol\ESP3Codec;
$checks=0;$cases=0;
$check=static function(bool$b,string$l)use(&$checks):void{$checks++;if(!$b)throw new RuntimeException($l);};
$frame=static function(string$data,string$opt=''):string{$h=pack('nCC',strlen($data),strlen($opt),2);return"\x55".$h.chr(ESP3Codec::crc8($h)).$data.$opt.chr(ESP3Codec::crc8($data.$opt));};
$version=$frame(hex2bin('00010203000506070801020304454F0103').str_pad('SIMULATOR',16,"\0"));
$base=$frame(hex2bin('00FF900000'),"\x08");
foreach(['control','fragments','crc','duplicate','unexpectedType','lost','disconnect','reconnect','oldWrongShape','oldChangedIdentity','oldChangedBase','late']as$fault)
for($at=0;$at<20;$at++){
    $core=new ESP3TransportArbiterCore();$core->setMaintenanceEnabled(true);$core->setConnected(true,1000);
    $ctx=['session'=>'session','transportBinding'=>'binding','handoffBinding'=>'handoff','exclusive'=>true,'descriptorCount'=>1,'faultEpoch'=>0];
    $s=new C2Session();$s->start($ctx,1);$now=2.0;$tick=2000;
    $consume=static function(array$actions)use(&$ctx,$s,&$now):void{
        // Mirrors monotonic arbiter warning epoch, including errors before results.
        foreach($actions as$a)if(($a['type']??'')==='diagnostic'&&in_array($a['level']??'',['warning','error'],true))$ctx['faultEpoch']++;
        if(!$s->checkContext($ctx,$now))return;
        foreach($actions as$a)if(($a['type']??'')==='maintenance_result'){
            if($a['outcome']!=='RESPONSE'){$s->fault($a['outcome'],$now);continue;}
            $s->response($a['token'],$a['operation'],ESP3Codec::fromHex($a['frameHex']),$ctx,$now);
        }
    };
    for($i=0;$i<20;$i++){
        if($i===10){$r=$s->review('FF900080',$ctx,$now);$s->confirmA($r['token'],$now);$s->confirmB($r['token'],'FF900080',$ctx,$now);}
        $r=$s->request($ctx,$now);if($r===null)break;
        $queued=$core->enqueueMaintenance(['operation'=>$r['operation'],'ownerInstanceId'=>1,'token'=>$r['token'],
            'frameHex'=>ESP3Codec::toHex(ESP3Codec::buildReadRequest($r['operation'])),'timeoutMs'=>500],$tick);
        $check($queued['accepted'],'arbiter accepted');$consume($queued['actions']);
        $bytes=$i%2?$base:$version;
        if($i===$at){
            switch($fault){
                case'crc':$bytes[-1]=chr(ord($bytes[-1])^1);break;
                case'duplicate':$bytes.=$bytes;break;
                case'unexpectedType':$h=pack('nCC',1,0,5);$bytes="\x55".$h.chr(ESP3Codec::crc8($h))."\x03".chr(ESP3Codec::crc8("\x03")).$bytes;break;
                case'lost':case'late':$consume($core->tick($tick+600));if($fault==='late')$consume($core->receiveBytes($bytes,$tick+700));$bytes='';break;
                case'disconnect':case'reconnect':$consume($core->setConnected(false,$tick));if($fault==='reconnect'){$ctx['session']='new-session';$consume($core->setConnected(true,$tick+1));}$bytes='';break;
                case'oldWrongShape':$bytes=$i%2?$version:$base;break;
                case'oldChangedIdentity':if($i%2===0)$bytes=$frame(hex2bin('00010203000506070801020305454F0103').str_pad('SIMULATOR',16,"\0"));else$bytes=$frame(hex2bin('00FF910000'),"\x08");break;
                case'oldChangedBase':$bytes=$i%2?$frame(hex2bin('00FF910000'),"\x08"):$frame(hex2bin('00010203000506070801020305454F0103').str_pad('SIMULATOR',16,"\0"));break;
            }
        }
        if($bytes!==''){
            if($fault==='fragments'&&$i===$at){for($n=0;$n<strlen($bytes);$n++)$consume($core->receiveBytes($bytes[$n],$tick+$n));}
            else$consume($core->receiveBytes($bytes,$tick+10));
        }
        if($s->state()['phase']==='FAULT_LATCHED')break;
        $now+=.1;$tick+=100;
    }
    $expected=in_array($fault,['control','fragments'],true)?'WRITE_BLOCKED':'FAULT_LATCHED';
    $check($s->state()['phase']===$expected,'fault position '.$fault.':'.$at);$cases++;
}
echo "PASS: C2 arbiter {$checks} assertions, {$cases} stream fault cases\n";
