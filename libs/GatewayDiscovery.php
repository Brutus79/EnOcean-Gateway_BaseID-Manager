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
            $owners=$d['owners']??null;$out[]=['path'=>$d['path'],'realPath'=>$real,'aliases'=>$paths,'type'=>preg_match('/tty(USB|ACM)/',$real)?'usb':'serial',
                'bound'=>$bound,'ownershipKnown'=>is_array($owners),'owners'=>$owners,'probeAllowed'=>$bound&&is_array($owners)&&$owners===[$d['selfPID']??-1],
                'status'=>$bound?'Konfigurierter Anschluss; Identität nur nach ESP3-Prüfung':($owners===[]?'Freier Kandidat; noch kein Gateway-Nachweis':'Belegt oder Besitz unbekannt; keine automatische Prüfung')];
        }return $out;
    }
    public static function owners(string $path): ?array
    {
        $fds=self::descriptors($path);
        return $fds===null?null:array_values(array_unique(array_column($fds,'pid')));
    }
    /** Count descriptors, not just unique PIDs: two Symcon FDs are NOT exclusive. */
    public static function descriptors(string $path): ?array
    {
        // /proc/PID/fd/N is a dynamic symlink: the same number can now be a
        // socket or another device. Clearing stat alone leaves PHP realpath
        // entries alive and can count a formerly serial FD a second time.
        clearstatcache(true);
        $device=@stat($path);$status=@file_get_contents('/proc/self/status');
        if(!$device||($device['mode']&0170000)!==0020000||!is_string($status)||!preg_match('/^Uid:\s+0\s+0\s+0\s+0\s*$/m',$status)){return null;}
        $owners=[];
        foreach(glob('/proc/[0-9]*',GLOB_ONLYDIR)?:[]as$p){$fds=@scandir($p.'/fd');if($fds===false){clearstatcache(true);if(is_dir($p))return null;continue;}
            foreach($fds as$fd){if($fd==='.'||$fd==='..')continue;clearstatcache(true);$s=@stat($p.'/fd/'.$fd);if($s&&($s['mode']&0170000)===0020000&&$s['rdev']===$device['rdev'])$owners[]=['pid'=>(int)basename($p),'fd'=>(string)$fd];}}
        return $owners;
    }
    public static function localDevices(string $boundPath): array
    {
        $paths=array_merge(glob('/dev/ttyAMA*')?:[],glob('/dev/ttyUSB*')?:[],glob('/dev/ttyACM*')?:[],glob('/dev/serial/by-id/*')?:[],['/dev/serial0'],$boundPath!==''?[$boundPath]:[]);
        $devices=[];foreach(array_unique($paths)as$path){$devices[]=['path'=>$path,'realPath'=>realpath($path)?:$path,'exists'=>file_exists($path),'owners'=>self::owners($path),'selfPID'=>getmypid()];}
        return self::classify($devices,$boundPath);
    }
}
