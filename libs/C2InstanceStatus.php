<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Product;

/** Instance health only. Never transport evidence or authorization. */
final class C2InstanceStatus
{
    public static function code(array $s,array $h,array $r,int $parent,bool $active,bool $nativeValid): int
    {
        $p=$s['phase']??'IDLE';$hp=$h['phase']??'IDLE';
        if(($s['faults']??[])!==[]||in_array($p,['FAULT_LATCHED','RETURN_WARNING'],true))return 201;
        if($parent===0&&$nativeValid){
            if($p==='IDLE'&&in_array($hp,['IDLE','RESTORED'],true))return 102;
            if($p==='RETURNED'&&$hp==='RESTORED')return 102;
        }
        if($p==='NATIVE_REFRESH_PENDING'&&in_array($hp,['RETURN_CLOSING','RESTORED'],true)){
            return $hp==='RESTORED'&&!$nativeValid?201:104;
        }
        if(in_array($hp,['CAPTURED','CLOSING_NATIVE','DETACHED'],true))return 104;
        if($hp==='ACTIVE'&&$active&&in_array($p,['IDLE','SYNCHRONIZING','MAINTENANCE_READY','REVIEW_A','REVIEW_B','PREWRITE_VERIFYING','WRITE_BLOCKED'],true))return 102;
        return 201;
    }
}
