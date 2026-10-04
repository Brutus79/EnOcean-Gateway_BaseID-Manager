<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Maintenance;
require_once __DIR__.'/C2Handoff.php';
require_once __DIR__.'/GatewayDiscovery.php';
use EnOceanGatewayManager\Product\GatewayDiscovery;
use RuntimeException;

/** Only C2Handoff calls mutators after durable capture and CAS. No serial open(). */
final class C2SymconEnvironment implements C2Environment
{
    public function instance(int $id): array
    {
        if(!IPS_InstanceExists($id))throw new RuntimeException('Required instance missing.');
        return IPS_GetInstance($id)+['C2OwnershipIdent'=>IPS_GetObject($id)['ObjectIdent']??''];
    }
    public function configuration(int $id): array {return json_decode(IPS_GetConfiguration($id),true,512,JSON_THROW_ON_ERROR);}
    public function instances(): array {return IPS_GetInstanceList();}
    public function create(string $module): int
    {
        if(!in_array($module,[NativeGatewayResolver::SERIAL,C2Handoff::ARBITER],true))throw new RuntimeException('Unsupported temporary module.');
        $id=IPS_CreateInstance($module);
        IPS_SetIdent($id,'EGM_C2_'.bin2hex(random_bytes(16)));
        IPS_SetName($id,'EnOcean temporäre Wartungsverbindung');return$id;
    }
    public function configure(int $id,array $configuration): void
    {
        if(!in_array($this->instance($id)['ModuleInfo']['ModuleID'],[NativeGatewayResolver::SERIAL,C2Handoff::ARBITER],true))throw new RuntimeException('C2 cannot configure this module.');
        IPS_SetConfiguration($id,json_encode($configuration,JSON_THROW_ON_ERROR));IPS_ApplyChanges($id);
    }
    public function connect(int $child,int $parent): void {IPS_ConnectInstance($child,$parent);}
    public function disconnect(int $child): void {IPS_DisconnectInstance($child);}
    public function delete(int $id): void
    {
        $i=$this->instance($id);
        if(!str_starts_with($i['C2OwnershipIdent'],'EGM_C2_')||($i['ConnectionID']??-1)!==0)throw new RuntimeException('Cannot delete foreign/attached instance.');
        foreach(IPS_GetInstanceList()as$other)if((IPS_GetInstance($other)['ConnectionID']??0)===$id)throw new RuntimeException('Instance has foreign users.');
        // Only variables created by our own temporary arbiter; never other object types.
        foreach(IPS_GetChildrenIDs($id)as$child){$o=IPS_GetObject($child);
            if($o['ObjectType']!==2||!in_array($o['ObjectIdent'],['TransportStatus','QueueStatus','LastDiagnostic'],true))throw new RuntimeException('Unexpected temporary child; no deletion.');}
        foreach(IPS_GetChildrenIDs($id)as$child)IPS_DeleteVariable($child);
        IPS_DeleteInstance($id);
    }
    public function descriptors(string $path): ?array {return GatewayDiscovery::descriptors($path);}
    public function selfPID(): int {return getmypid();}
}
