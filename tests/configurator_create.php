<?php
declare(strict_types=1);
// Only the console Create contract; synthetic metadata, no I/O or hardware.
class IPSModuleStrict
{
    public int $InstanceID=1234;
    protected function ReadPropertyString(string $name): string { return ''; }
    protected function ReadPropertyInteger(string $name): int { return $GLOBALS['nativeReference'] ?? 0; }
    public function GetCompatibleParents(): string
    {
        return '{"type":"connect","moduleIDs":["{C5D65AB1-045B-40ED-B854-3D74D41C81EC}"]}';
    }
}
function IPS_GetInstanceListByModuleID(string $module): array
{
    return $module === '{A52FEFE9-7858-4B8E-A96E-26E15CB944F7}' ? [4242] : [];
}
function IPS_GetInstanceList(): array { return [4242, 4243]; }
function IPS_GetInstance(int $id): array
{
    return ['ConnectionID'=>$id===1234?($GLOBALS['legacyParent']??0):($id === 4242 && !($GLOBALS['detached']??false) ? 4243 : 0), 'InstanceStatus'=>102,
        'ModuleInfo'=>['ModuleID'=>$id === 4242
            ? '{A52FEFE9-7858-4B8E-A96E-26E15CB944F7}'
            : '{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}']];
}
function IPS_GetConfiguration(int $id): string
{
    return json_encode($id === 4242 ? ['GatewayMode'=>2] :
        ['Port'=>'/dev/fixture', 'BaudRate'=>'57600', 'DataBits'=>'8',
            'Parity'=>'None', 'StopBits'=>'1', 'Open'=>true], JSON_THROW_ON_ERROR);
}
function IPS_GetName(int $id): string { return 'Synthetic native gateway'; }
require_once __DIR__.'/../EnOceanGatewayConfigurator/module.php';
$form=json_decode((new EnOceanGatewayConfigurator())->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
$rows=$form['actions'][1]['values'];
if (count($rows)!==1 || $rows[0]['instanceID']!==0) throw new RuntimeException('Expected one new native gateway row');
$create=$rows[0]['create'];
if ($create['moduleID']!=='{ED8F6F0C-D57F-4E0F-B23B-63CF05AE9643}') throw new RuntimeException('Must create exactly the manager, not an I/O or arbiter');
if ($create['configuration']!==['NativeGatewayInstanceID'=>4242,'EnableReadActions'=>false]) throw new RuntimeException('Selected native reference must be preserved, with reads disabled');
$notice=$form['actions'][2]['caption'];
if(str_contains($notice,'Keine realen Hardware-Writes')||!str_contains($notice,'ausdrückliche Bestätigungen'))throw new RuntimeException('Configurator must describe the current confirmed write workflow');
$GLOBALS['detached']=true;
$detached=json_decode((new EnOceanGatewayConfigurator())->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR)['actions'][1]['values'];
if(count($detached)!==1||isset($detached[0]['create'])||$detached[0]['address']!=='Native gateway has no current I/O.')throw new RuntimeException('Missing native I/O must explain unavailable creation without restoring or taking over anything');
$GLOBALS['detached']=false;
require_once __DIR__.'/../EnOceanGatewayManager/module.php';
$GLOBALS['nativeReference']=$create['configuration']['NativeGatewayInstanceID'];
$manager=new EnOceanGatewayManager();
if ($manager->GetCompatibleParents()!=='{}') throw new RuntimeException('Native-reference manager must not request a physical parent during console creation');
$GLOBALS['nativeReference']=0;
if ($manager->GetCompatibleParents()!=='{}') throw new RuntimeException('Disconnected manager must remain parent-free');
$GLOBALS['legacyParent']=4243;
if ($manager->GetCompatibleParents()!==(new IPSModuleStrict())->GetCompatibleParents()) throw new RuntimeException('Legacy parent contract must remain unchanged');
echo "PASS: native creation contract, missing-I/O reproduction and current configurator notice; no hardware access\n";
