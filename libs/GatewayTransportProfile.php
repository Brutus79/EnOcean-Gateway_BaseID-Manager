<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Product;

/** Pure endpoint/protocol description, never discovery or a transport bypass. */
final class GatewayTransportProfile
{
    public static function describe(string $endpointType,string $protocol='unknown'): array
    {
        $serial=in_array($endpointType,['serial','usb'],true);
        return [
            'endpointType'=>in_array($endpointType,['serial','usb','network'],true)?$endpointType:'unknown',
            'protocol'=>in_array($protocol,['ESP3','ESP2'],true)?$protocol:'unknown',
            'adapterAvailable'=>$serial&&$protocol==='ESP3',
            'discoveryInNormalWorkflow'=>false,
            'capabilitiesMustBeRead'=>true,
            'requirements'=>['exclusiveEndpoint','boundSession','correlatedResponses','observedDisconnectReconnect'],
        ];
    }
}
