<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/UARTObservation.php';
use EnOceanGatewayManager\Product\UARTObservation;
$n=0;$check=static function(bool $ok,string $label)use(&$n):void {if(!$ok)throw new RuntimeException($label);$n++;};
$processes=[1=>['pid'=>1,'ppid'=>0,'startTicks'=>'10','name'=>'symcon','executable'=>'SIMULATOR'],
    2=>['pid'=>2,'ppid'=>1,'startTicks'=>'20','name'=>'sh','executable'=>'SIMULATOR'],
    3=>['pid'=>3,'ppid'=>2,'startTicks'=>'30','name'=>'arp','executable'=>'SIMULATOR'],
    4=>['pid'=>4,'ppid'=>0,'startTicks'=>'40','name'=>'other','executable'=>'SIMULATOR']];
$read=static fn(int $pid):?array=>$processes[$pid]??null;
$fd=[['pid'=>1,'fd'=>'88'],['pid'=>2,'fd'=>'88'],['pid'=>3,'fd'=>'88']];
$ob=UARTObservation::inspect('SIMULATOR',$fd,1,$read,static fn()=> '0404002');
$check($ob['classification']==='SELF_OR_DESCENDANTS_ONLY','nested children identified as candidates only');
$check($ob['observedCount']===3&&count($ob['descriptors'])===3,'all descriptors retained, not collapsed');
$check($ob['descriptors'][2]['processChain'][1]['pid']===2&&$ob['descriptors'][2]['flags']==='0404002','ancestry and flags retained');
$check(!isset($ob['exclusive'],$ob['authorized']),'diagnostic is never ownership/write authorization');
foreach([null,[['pid'=>4,'fd'=>'88']],[['pid'=>99,'fd'=>'88']],[['pid'=>1,'fd'=>'88'],['pid'=>1,'fd'=>'89']]]as$bad)
    $check(UARTObservation::inspect('SIMULATOR',$bad,1,$read,static fn()=>null)['classification']==='UNKNOWN_OR_INDEPENDENT','unexplained ownership never classified as descendants');
$calls=0;$unstable=static function(int $pid)use($processes,&$calls):?array {
    $calls++;$p=$processes[$pid]??null;if($calls>2&&$p!==null)$p['startTicks']='999';return$p;
};
$check(UARTObservation::inspect('SIMULATOR',[['pid'=>2,'fd'=>'88']],1,$unstable,static fn()=>null)['classification']==='UNKNOWN_OR_INDEPENDENT','PID reuse/unstable sample not classified as related');
// Linux metadata smoke check with /dev/zero, NOT a UART, and no device read/write.
// Explicitly pass a harmless FD to a waiting child; inspect /proc, then close pipes.
if(PHP_OS_FAMILY==='Linux'&&function_exists('proc_open')){
    $file=fopen('/dev/zero','r');$selfFD=null;
    foreach(scandir('/proc/self/fd')as$fd)if(@readlink('/proc/self/fd/'.$fd)==='/dev/zero')$selfFD=$fd;
    $child=proc_open(['/bin/cat'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w'],3=>$file],$pipes);
    try{
        $check(is_resource($child)&&$selfFD!==null,'metadata fixture starts without shell or UART');
        $pid=proc_get_status($child)['pid'];
        $actual=UARTObservation::inspect('/dev/zero',[['pid'=>getmypid(),'fd'=>$selfFD],['pid'=>$pid,'fd'=>'3']],getmypid());
        $check($actual['classification']==='SELF_OR_DESCENDANTS_ONLY','real Linux PID/PPID/start-time parser');
        $check($actual['descriptors'][1]['flags']!==null&&$actual['descriptors'][1]['processChain'][0]['ppid']===getmypid(),'real Linux fdinfo and ancestry retained');
    }finally{
        foreach($pipes as$pipe)fclose($pipe);proc_close($child);fclose($file);
    }
}
echo "PASS: {$n} UART metadata checks; no gateway/device commands\n";
