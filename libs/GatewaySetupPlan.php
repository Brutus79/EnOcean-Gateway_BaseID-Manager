<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Product;

/** Declarative console-created chain. No imperative foreign-instance configuration. */
final class GatewaySetupPlan
{
    public const MANAGER='{ED8F6F0C-D57F-4E0F-B23B-63CF05AE9643}';
    public const ARBITER='{C5D65AB1-045B-40ED-B854-3D74D41C81EC}';
    public const SERIAL='{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}';
    public static function chain(string $device,string $name): array
    {
        if(!str_starts_with($device,'/dev/')||str_contains($device,"\0")||$name===''||strlen($name)>120)throw new \InvalidArgumentException('Ungültige Schnittstelle oder Gatewayname.');
        return [
            ['moduleID'=>self::MANAGER,'name'=>$name,'configuration'=>['GatewayName'=>$name,'ConnectionDevice'=>$device,'ConnectionType'=>preg_match('~/(ttyUSB|ttyACM|serial/by-id/)~',$device)?'usb':'serial','EnableReadActions'=>true]],
            ['moduleID'=>self::ARBITER,'name'=>'Gateway-Transport ('.$name.')','configuration'=>['EnableMaintenance'=>true,'IsolatedReadOnly'=>true]],
            ['moduleID'=>self::SERIAL,'name'=>'Gateway-Schnittstelle ('.$name.')','configuration'=>['Port'=>$device,'BaudRate'=>'57600','DataBits'=>'8','StopBits'=>'1','Parity'=>'None','Open'=>true]],
        ];
    }
    public static function creatable(array $device,array $bindings): bool
    {
        if(!($device['ownershipKnown']??false)||($device['owners']??null)!==[])return false;
        foreach($bindings as$b){if(($b['realPath']??'')!==($device['realPath']??''))continue;if(($b['open']??true)||!($b['onlyGatewayManagers']??false))return false;}
        return true;
    }
}
