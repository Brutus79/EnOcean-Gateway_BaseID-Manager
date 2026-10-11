<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Product;
final class GatewayDiscovery
{
    /** No open(), serial reads, baud changes or handshake bypass. */
    public static function classify(array $devices,string $boundPath): array
    {
        $groups=[];
        foreach($devices as$d){if(!($d['exists']??false))continue;$real=$d['realPath']??$d['path'];$groups[$real][]=$d;}
        $out=[];
        foreach($groups as$real=>$aliases){usort($aliases,fn($a,$b)=>str_starts_with($b['path'],'/dev/serial/by-id/')<=>str_starts_with($a['path'],'/dev/serial/by-id/'));
            $d=$aliases[0];$paths=array_column($aliases,'path');$bound=in_array($boundPath,$paths,true)||$boundPath===$real;
            $out[]=['path'=>$d['path'],'realPath'=>$real,'aliases'=>$paths,'type'=>preg_match('/tty(USB|ACM)/',$real)?'usb':'serial',
                'bound'=>$bound,'probeAllowed'=>$bound,
                'status'=>$bound?'Konfigurierter Anschluss; Prüfung nur über kontrollierte Symcon-Verbindung':'Anschlusskandidat; keine automatische Prüfung'];
        }return $out;
    }
    public static function localDevices(string $boundPath): array
    {
        $paths=array_merge(glob('/dev/ttyAMA*')?:[],glob('/dev/ttyUSB*')?:[],glob('/dev/ttyACM*')?:[],glob('/dev/serial/by-id/*')?:[],['/dev/serial0'],$boundPath!==''?[$boundPath]:[]);
        $devices=[];foreach(array_unique($paths)as$path){$devices[]=['path'=>$path,'realPath'=>realpath($path)?:$path,'exists'=>file_exists($path)];}
        return self::classify($devices,$boundPath);
    }
}
