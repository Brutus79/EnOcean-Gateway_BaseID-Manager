<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/GatewayDiscovery.php';
require_once __DIR__.'/../libs/GatewaySetupPlan.php';
require_once __DIR__.'/../libs/NativeGatewayResolver.php';
use EnOceanGatewayManager\Product\GatewayDiscovery;
use EnOceanGatewayManager\Product\GatewaySetupPlan;

final class EnOceanGatewayConfigurator extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();$this->RegisterPropertyString('GatewayName','EnOcean Gateway');$this->RegisterPropertyString('Device','');
    }
    public function GetConfigurationForm(): string
    {
        // Existing port-created managers remain usable. New normal installation
        // offers existing native gateways, never a raw endpoint chooser.
        if($this->ReadPropertyString('Device')==='')return$this->nativeGatewayForm();
        $devices=GatewayDiscovery::localDevices('');$bindings=[];$rows=[];
        foreach(IPS_GetInstanceListByModuleID(GatewaySetupPlan::SERIAL)as$id){$port=(string)IPS_GetProperty($id,'Port');$real=realpath($port);if($real===false)continue;
            $users=array_values(array_filter(IPS_GetInstanceList(),fn($other)=>(int)(IPS_GetInstance($other)['ConnectionID']??0)===$id));$ours=$users!==[];
            foreach($users as$other){
                if((IPS_GetInstance($other)['ModuleInfo']['ModuleID']??'')!==GatewaySetupPlan::ARBITER){$ours=false;continue;}
                $children=array_values(array_filter(IPS_GetInstanceList(),fn($child)=>(int)(IPS_GetInstance($child)['ConnectionID']??0)===$other));
                if($children===[])$ours=false;
                foreach($children as$child)if((IPS_GetInstance($child)['ModuleInfo']['ModuleID']??'')!==GatewaySetupPlan::MANAGER)$ours=false;
            }
            $bindings[]=['realPath'=>$real,'open'=>(bool)IPS_GetProperty($id,'Open'),'onlyGatewayManagers'=>$ours];
        }
        foreach(IPS_GetInstanceListByModuleID(GatewaySetupPlan::MANAGER)as$id){$parent=(int)IPS_GetInstance($id)['ConnectionID'];$serial=$parent>0?(int)IPS_GetInstance($parent)['ConnectionID']:0;
            $rows[]=['name'=>IPS_GetName($id),'address'=>$serial>0?(string)IPS_GetProperty($serial,'Port'):'Noch nicht verbunden','instanceID'=>$id,
                'create'=>['moduleID'=>GatewaySetupPlan::MANAGER,'configuration'=>json_decode(IPS_GetConfiguration($id),true)]];
        }
        $options=[['caption'=>'Schnittstelle wählen','value'=>'']];$selected=$this->ReadPropertyString('Device');$message='Schnittstelle auswählen und Übernehmen. Danach den neuen Gatewayeintrag markieren und Anlegen wählen. Baudrate und interne Verbindung werden vorgegeben.';
        foreach($devices as$d){$free=GatewaySetupPlan::creatable($d,$bindings);$options[]=['caption'=>$d['path'].($free?'':' · belegt / nicht frei verfügbar'),'value'=>$d['path']];
            if($d['path']===$selected){if($free)$rows[]=['name'=>$this->ReadPropertyString('GatewayName'),'address'=>$d['path'],'instanceID'=>0,'create'=>GatewaySetupPlan::chain($d['path'],$this->ReadPropertyString('GatewayName'))];
                else $message='Schnittstelle nicht frei verfügbar. Bestehendes Gateway unten öffnen; belegte oder fremde Anschlüsse werden nicht übernommen.';}}
        if($selected!==''&&!in_array($selected,array_column($devices,'path'),true))$message='Die gewählte Schnittstelle ist nicht mehr vorhanden. Bitte erneut auswählen.';
        return json_encode(['elements'=>[['type'=>'Label','caption'=>'EnOcean Gateway Manager'],['type'=>'ValidationTextBox','name'=>'GatewayName','caption'=>'Gatewayname'],['type'=>'Select','name'=>'Device','caption'=>'Serielle Schnittstelle','options'=>$options]],
            'actions'=>[['type'=>'Label','caption'=>$message],['type'=>'Label','caption'=>'57600 Baud · 8 Datenbits · keine Parität · 1 Stopbit. Ein Anschluss ist erst nach erfolgreicher Gatewayprüfung als EnOcean bestätigt.'],
                ['type'=>'Configurator','name'=>'Gateways','caption'=>'Gateway anlegen oder vorhandenes Gateway öffnen','delete'=>false,'rowCount'=>8,'values'=>$rows],
                ['type'=>'Label','caption'=>'Hardware-Schreiben ist in diesem Build gesperrt. Bestehende Master- und Historydaten werden nicht gelöscht.']],
            'status'=>[['code'=>102,'icon'=>'active','caption'=>'Bereit zur Gatewayeinrichtung']]],JSON_THROW_ON_ERROR);
    }
    private function nativeGatewayForm(): string
    {
        $r=new \EnOceanGatewayManager\Maintenance\NativeGatewayResolver(IPS_GetInstance(...),
            static fn(int$id):array=>json_decode(IPS_GetConfiguration($id),true,512,JSON_THROW_ON_ERROR),IPS_GetInstanceList(...));
        $rows=[];$existing=[];
        foreach(IPS_GetInstanceListByModuleID(GatewaySetupPlan::MANAGER)as$id){
            $reference=(int)IPS_GetProperty($id,'NativeGatewayInstanceID');if($reference>0)$existing[$reference]=$id;
            $rows[]=['name'=>IPS_GetName($id),'address'=>$reference>0?'Vorhandenes natives Gateway':'Bestehende isolierte Verbindung',
                'instanceID'=>$id,'create'=>['moduleID'=>GatewaySetupPlan::MANAGER,'configuration'=>json_decode(IPS_GetConfiguration($id),true)]];
        }
        $discovered=$r->discover(IPS_GetInstanceListByModuleID(\EnOceanGatewayManager\Maintenance\NativeGatewayResolver::NATIVE),IPS_GetName(...));
        foreach($discovered as$g){if(isset($existing[$g['id']]))continue;
            $row=['name'=>$g['name'],'address'=>$g['reason'],'instanceID'=>0];
            if($g['supported'])$row['create']=['moduleID'=>GatewaySetupPlan::MANAGER,'name'=>'Gateway Manager · '.$g['name'],
                'configuration'=>['NativeGatewayInstanceID'=>$g['id'],'EnableReadActions'=>false]];
            $rows[]=$row;
        }
        return json_encode(['elements'=>[['type'=>'Label','caption'=>'EnOcean Gateway Manager · vorhandenes Gateway auswählen']],
            'actions'=>[['type'=>'Label','caption'=>'Gateway markieren und Manager anlegen oder vorhandenen Manager öffnen. Anschlussparameter werden bei jeder Wartung aktuell aus IP-Symcon gelesen.'],
                ['type'=>'Configurator','name'=>'Gateways','caption'=>'Native EnOcean-Gateways / vorhandene Manager','delete'=>false,'rowCount'=>8,'values'=>$rows],
                ['type'=>'Label','caption'=>'Unbekannte Transports und LAN ohne verifizierten C2-Vertrag sind gesperrt. Keine realen Hardware-Writes in diesem Build.']],
            'status'=>[['code'=>102,'icon'=>'active','caption'=>'Gatewayauswahl']]],JSON_THROW_ON_ERROR);
    }
}
