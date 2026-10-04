<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Maintenance;
require_once __DIR__.'/C2SymconEnvironment.php';

/** Recovery only for durable Destroy cancellation; NEVER adopt orphan sessions. */
final class C2RetirementRecovery
{
    public static function process(string $kernel): array
    {
        $result=[];
        if(!IPS_SemaphoreEnter('EGM_C2_COORDINATOR',1000))return['Coordinator busy'];
        try{
            foreach(glob(rtrim($kernel,'/').'/egm-c2-handoff-*',GLOB_ONLYDIR)?:[]as$dir){
                if(!preg_match('/egm-c2-handoff-([0-9]+)$/D',$dir,$match))continue;
                try{
                    $h=new C2Handoff(new C2SymconEnvironment(),new \EnOceanGatewayManager\Safety\DurableWriteJournal($dir));
                    $s=$h->state();
                    if(!($s['retiring']??false)||($s['phase']??'')==='RESTORED')continue;
                    if(($s['manager']??0)!==(int)$match[1])throw new \RuntimeException('Journal owner mismatch.');
                    if($s['phase']!=='RETURN_CLOSING')$h->restore(microtime(true));
                    $result[$match[1]]=$h->finishRestore(microtime(true));
                }catch(\Throwable$e){$result[$match[1]]='REVIEW REQUIRED: '.$e->getMessage();}
            }
        }finally{IPS_SemaphoreLeave('EGM_C2_COORDINATOR');}
        return$result;
    }
}
