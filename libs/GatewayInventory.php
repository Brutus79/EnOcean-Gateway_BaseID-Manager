<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Product;
require_once __DIR__.'/ESP3Codec.php';
use EnOceanGatewayManager\Protocol\ESP3Codec;

/** Product inventory, deliberately separate from the safety WAL. Events are never deleted. */
final class GatewayInventory
{
    public static function empty(): array { return ['schemaVersion'=>1,'revision'=>0,'gateways'=>[],'events'=>[]]; }
    public static function validate(array $db): array
    {
        if (($db['schemaVersion']??null)===0 && isset($db['gateways'],$db['events'],$db['revision'])) { $db['schemaVersion']=1; }
        if (($db['schemaVersion']??null)!==1 || !is_int($db['revision']??null) || $db['revision']<0 || !is_array($db['gateways']??null) || !is_array($db['events']??null)) { throw new \RuntimeException('Inventardaten beschädigt oder Schema nicht unterstützt. Keine Änderung möglich.'); }
        $owners=[];foreach($db['gateways'] as $id=>$g){
            if(!is_string($id)||!is_array($g)||($g['logicalID']??null)!==$id||!is_string($g['name']??null)){throw new \RuntimeException('Ungültige Gateway-Identität.');}
            if(isset($g['managerInstanceID'])){if(!is_int($g['managerInstanceID'])||$g['managerInstanceID']<=0||isset($owners[$g['managerInstanceID']]))throw new \RuntimeException('Gatewayzuordnung beschädigt oder mehrdeutig.');$owners[$g['managerInstanceID']]=true;}
            if(($g['master']??null)!==null){ESP3Codec::normalizeWritableBaseId($g['master']);}
        }
        $ids=[];
        foreach($db['events'] as $e){
            if(!is_array($e)||!isset($db['gateways'][$e['logicalID']??''])||!preg_match('/\A[0-9A-F]{8}\z/D',$e['baseID']??'')||!is_string($e['id']??null)||isset($ids[$e['id']])
                ||!is_bool($e['observed']??null)||!is_bool($e['written']??null)||!is_bool($e['requested']??null)||strtotime($e['at']??'')===false){throw new \RuntimeException('Ungültiger History-Eintrag.');}
            if($e['written']&&(!$e['observed']||$e['type']!=='WRITE_VERIFIED')){throw new \RuntimeException('Unbelegter Hardware-Erfolg.');}
            if(!in_array($e['type']??null,['HARDWARE_DETECTED','HARDWARE_OBSERVED','WRITE_VERIFIED','DIFFERENT_VALUE_APPLIED','MASTER_CREATED','MASTER_CHANGED','FORMER_MASTER','WRITE_REQUESTED','WRITE_FAILED','MANUAL_ENTRY','IMPORTED_EXISTING_CONFIGURATION'],true)
                ||$e['observed']!==in_array($e['type'],['HARDWARE_DETECTED','HARDWARE_OBSERVED','WRITE_VERIFIED','DIFFERENT_VALUE_APPLIED'],true)
                ||$e['written']!==($e['type']==='WRITE_VERIFIED')||$e['requested']!==($e['type']==='WRITE_REQUESTED')){throw new \RuntimeException('History-Ereignis und Belegrolle widersprüchlich.');}
            if($e['type']==='WRITE_REQUESTED'&&($e['observed']||$e['written']||!$e['requested'])){throw new \RuntimeException('Anforderung ist kein Hardwarebeweis.');}
            $ids[$e['id']]=true;
        }
        return $db;
    }
    public static function gateway(array &$db,string $id,string $name): void
    {
        if($id===''||strlen($name)>120){throw new \InvalidArgumentException('Ungültiger Gatewayname.');}
        $db['gateways'][$id]??=['logicalID'=>$id,'name'=>$name,'master'=>null,'acceptedEURID'=>null];
        $db['gateways'][$id]['name']=$name;
    }
    public static function event(array &$db,string $id,string $base,string $type,string $at,string $source,array $meta=[]): void
    {
        if(!isset($db['gateways'][$id])||!preg_match('/\A[0-9A-F]{8}\z/D',$base)||strtotime($at)===false){throw new \InvalidArgumentException('History ohne belastbare Adresse/Zeit.');}
        $key=hash('sha256',$id.'|'.$source.'|'.$base.'|'.$type);
        foreach($db['events']as$e){if($e['id']===$key)return;}
        $db['events'][]=['id'=>$key,'logicalID'=>$id,'baseID'=>$base,'type'=>$type,'at'=>$at,'source'=>$source,
            'observed'=>in_array($type,['HARDWARE_DETECTED','HARDWARE_OBSERVED','WRITE_VERIFIED','DIFFERENT_VALUE_APPLIED'],true),
            'written'=>$type==='WRITE_VERIFIED','requested'=>$type==='WRITE_REQUESTED','eurid'=>$meta['eurid']??null,
            'counterBefore'=>$meta['counterBefore']??null,'counterAfter'=>$meta['counterAfter']??null,
            'operation'=>$meta['operation']??null,'note'=>$meta['note']??'', 'evidenceType'=>$meta['evidenceType']??'LOCAL_ACTION'];
    }
    public static function master(array &$db,string $id,string $base,string $source,string $at): void
    {
        $base=ESP3Codec::normalizeWritableBaseId($base);$old=$db['gateways'][$id]['master'];
        if($source==='history'&&!isset(self::history($db,$id)[$base])){throw new \RuntimeException('Base-ID gehört nicht zur Historie dieses Gateways.');}
        if($old===$base)return;
        if($old!==null){self::event($db,$id,$old,'FORMER_MASTER',$at,'master-old:'.$db['revision'].':'.$at,['note'=>'Ehemalige Master Base-ID']);}
        self::event($db,$id,$base,$old===null?'MASTER_CREATED':'MASTER_CHANGED',$at,'master:'.$db['revision'].':'.$at,['note'=>$source]);
        $db['gateways'][$id]['master']=$base;
    }
    public static function history(array $db,string $id): array
    {
        $result=[];
        foreach($db['events']as$e){if($e['logicalID']!==$id)continue;$b=$e['baseID'];
            $result[$b]??=['baseID'=>$b,'logicalID'=>$id,'firstSeen'=>$e['at'],'lastSeen'=>$e['at'],'observed'=>false,'written'=>false,'requested'=>false,'formerMaster'=>false,'master'=>($db['gateways'][$id]['master']??null)===$b,'events'=>[]];
            $r=&$result[$b];if(strtotime($e['at'])<strtotime($r['firstSeen']))$r['firstSeen']=$e['at'];if(strtotime($e['at'])>strtotime($r['lastSeen']))$r['lastSeen']=$e['at'];
            foreach(['observed','written','requested']as$f){$r[$f]=$r[$f]||$e[$f];}$r['formerMaster']=$r['formerMaster']||$e['type']==='FORMER_MASTER';$r['events'][]=$e;unset($r);
        }return $result;
    }
    public static function importJournal(array &$db,string $id,array $rows): void
    {
        foreach($rows as$r){$tx=$r['transactionID']??'';$at=$r['timestamp']??null;if($tx===''||!is_string($at))continue;
            $ref='wal:'.$tx.':'.($r['sequence']??hash('sha256',json_encode($r)));
            if(!($r['readOnlyRecovery']??false)&&isset($r['target'],$r['preview'])&&preg_match('/\A[0-9A-F]{8}\z/D',$r['target'])){
                self::event($db,$id,$r['target'],'WRITE_REQUESTED',$at,'request:'.$tx,['operation'=>$tx,'counterBefore'=>$r['preview']['remaining']??null,'note'=>'Angefordert; kein Hardwarebeweis','evidenceType'=>'VALIDATED_WAL']);
            }
            $v=$r['reads']['CO_RD_VERSION']??[];$b=$r['reads']['CO_RD_IDBASE']??[];$value=$b['values']??[];
            if(($v['values']['returnName']??'')!=='RET_OK'||($value['returnName']??'')!=='RET_OK'||!isset($v['values']['eurid'],$value['baseIdRawHex'],$b['at'])
                ||($v['session']??null)!==($b['session']??'')||($v['binding']??null)!==($b['binding']??'')||$v['values']['eurid']!==($r['backupEURID']??''))continue;
            $base=$value['baseIdRawHex'];$meta=['eurid'=>$v['values']['eurid'],'operation'=>$tx,'counterBefore'=>$r['preview']['remaining']??$r['originalIntent']['preview']['remaining']??null,
                'counterAfter'=>$value['remainingWriteCycles']??null,'evidenceType'=>'VALIDATED_WAL_READ'];
            self::event($db,$id,$base,'HARDWARE_OBSERVED',gmdate('c',$b['at']),'read:'.$tx.':'.$b['at'].':'.($b['session']??''),$meta);
            if(($r['state']??'')==='VERIFIED'&&($r['sendAttempts']??0)===1&&$base===($r['target']??null)){
                $expected=$r['preview']['expectedRemaining']??null;$counter=$value['remainingWriteCyclesRawHex']??null;
                if($expected==='UNLIMITED'?$counter==='FF':(is_int($expected)&&$counter!==null&&hexdec($counter)===$expected)){self::event($db,$id,$base,'WRITE_VERIFIED',$at,$ref,$meta);}
            }
            $requested=$r['originalIntent']['target']??$r['target']??null;
            if(in_array($r['state']??'', ['UNKNOWN_OUTCOME','RECOVERED_WITH_DIFFERENT_APPLIED_VALUE'],true)&&$requested!==null&&$base!==$requested){self::event($db,$id,$base,'DIFFERENT_VALUE_APPLIED',$at,$ref,$meta+['note'=>'Angefordert '.$requested.'; tatsächlich beobachtet '.$base]);}
        }
    }
}
