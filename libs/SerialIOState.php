<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Maintenance;
require_once __DIR__.'/NativeGatewayResolver.php';

/** Symcon I/O acknowledgement and topology only, not OS process ownership. */
final class SerialIOState
{
    public static function closed(array $instance,array $configuration): bool
    {
        return ($instance['ModuleInfo']['ModuleID']??'')===NativeGatewayResolver::SERIAL
            &&($instance['ConnectionID']??-1)===0
            &&($configuration['Open']??null)===false&&($instance['InstanceStatus']??0)===104;
    }
    public static function controlled(int $io,int $consumer,callable $instance,callable $configuration,array $ids): bool
    {
        $i=$instance($io);$c=$configuration($io);$port=$c['Port']??'';
        if(($i['ModuleInfo']['ModuleID']??'')!==NativeGatewayResolver::SERIAL
            ||($i['ConnectionID']??-1)!==0||($i['InstanceStatus']??0)!==102
            ||($c['Open']??null)!==true||!is_string($port)||$port==='')return false;
        $endpoint=realpath($port)?:$port;$users=[];
        foreach($ids as$id){
            $node=$instance($id);
            if(($node['ConnectionID']??0)===$io)$users[]=$id;
            if($id!==$io&&($node['ModuleInfo']['ModuleID']??'')===NativeGatewayResolver::SERIAL){
                $other=$configuration($id);$otherPort=$other['Port']??'';
                if(($other['Open']??false)===true&&is_string($otherPort)&&$otherPort!==''
                    &&(realpath($otherPort)?:$otherPort)===$endpoint)return false;
            }
        }
        return $users===[$consumer];
    }
}
