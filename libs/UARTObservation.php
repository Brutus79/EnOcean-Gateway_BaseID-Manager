<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Product;

/** Local /proc evidence only. Ancestry is NOT proof of a shared open file
 * description and never authorizes communication. No cmdline/environment reads.
 */
final class UARTObservation
{
    public static function inspect(string $path,?array $fds,int $self,?callable $process=null,?callable $flags=null): array
    {
        $process??=self::process(...);
        $flags??=static function(int $pid,string $fd): ?string {
            $raw=@file_get_contents('/proc/'.$pid.'/fdinfo/'.$fd);
            return is_string($raw)&&preg_match('/^flags:\s+([0-7]+)$/m',$raw,$m)?$m[1]:null;
        };
        $rows=[];$related=$fds!==null&&$fds!==[]&&count($fds)<=32;
        foreach(array_slice($fds??[],0,32)as$fd){
            $pid=(int)$fd['pid'];$number=(string)$fd['fd'];$chain=[];$seen=[];$p=$pid;
            for($depth=0;$depth<16&&$p>0&&!isset($seen[$p]);$depth++){
                $seen[$p]=true;$meta=$process($p);
                if(!is_array($meta)||($meta['pid']??0)!==$p||!isset($meta['ppid'],$meta['startTicks'])
                    ||!ctype_digit((string)$meta['startTicks']))break;
                $chain[]=$meta;
                if($p===$self)break;
                $p=(int)($meta['ppid']??0);
            }
            $descendant=$chain!==[]&&$pid!==$self&&($chain[array_key_last($chain)]['pid']??0)===$self;
            if($pid!==$self&&!$descendant)$related=false;
            // Re-read identity: vanished/reused PIDs are unknown, not inherited.
            foreach($chain as$meta){
                $again=$process($meta['pid']);
                if(!is_array($again)||($again['startTicks']??null)!==($meta['startTicks']??null)
                    ||($again['ppid']??null)!==($meta['ppid']??null))$related=false;
            }
            if($chain===[])$related=false;
            $rows[]=['pid'=>$pid,'fd'=>$number,'flags'=>$flags($pid,$number),
                'descendantCandidate'=>$descendant,'processChain'=>$chain];
        }
        $own=array_values(array_filter($fds??[],static fn(array $fd):bool=>$fd['pid']===$self));
        // Two descriptors in Symcon are not explained by fork inheritance.
        if(count($own)>1)$related=false;
        return ['path'=>$path,'selfPID'=>$self,'observedCount'=>$fds===null?null:count($fds),
            'classification'=>$related?'SELF_OR_DESCENDANTS_ONLY':'UNKNOWN_OR_INDEPENDENT',
            'descriptors'=>$rows];
    }
    private static function process(int $pid): ?array
    {
        $raw=@file_get_contents('/proc/'.$pid.'/stat');
        if(!is_string($raw)||!preg_match('/^([0-9]+) \((.*)\) (.*)$/s',trim($raw),$m))return null;
        $fields=preg_split('/\s+/',$m[3]);
        if(!isset($fields[19])||(int)$m[1]!==$pid)return null;
        return ['pid'=>$pid,'ppid'=>(int)$fields[1],'startTicks'=>$fields[19],
            'name'=>substr($m[2],0,64),'executable'=>@readlink('/proc/'.$pid.'/exe')?:null];
    }
}
