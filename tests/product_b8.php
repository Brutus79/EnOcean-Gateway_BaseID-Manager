<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/InventoryStore.php';
require_once __DIR__.'/../libs/GatewayDiscovery.php';
require_once __DIR__.'/../libs/ProductPresentation.php';
require_once __DIR__.'/../libs/TransactionalWrite.php';
require_once __DIR__.'/../libs/BaseIDPreflight.php';
require_once __DIR__.'/../libs/WriteJournal.php';
use EnOceanGatewayManager\Product\GatewayInventory as I;
use EnOceanGatewayManager\Product\InventoryStore;
use EnOceanGatewayManager\Product\GatewayDiscovery as D;
use EnOceanGatewayManager\Product\ProductPresentation as P;
use EnOceanGatewayManager\Protocol\ESP3Codec as C;
use EnOceanGatewayManager\Safety\TransactionalWrite as T;
use EnOceanGatewayManager\Safety\WriteJournal;
$passed=0;$check=static function(bool $ok,string $name)use(&$passed):void{if(!$ok)throw new RuntimeException($name);$passed++;};
$throws=static function(callable $f,string $name)use($check):void{try{$f();}catch(Throwable){$check(true,$name);return;}$check(false,$name);};
$db=I::empty();I::gateway($db,'house','Haupthaus');I::gateway($db,'annex','Nebenhaus');
I::event($db,'house','FFC2F780','HARDWARE_DETECTED','2026-10-01T10:00:00Z','original',['eurid'=>'01020304']);
I::master($db,'house','FFC2F780','hardware','2026-10-01T11:00:00Z');
$check($db['gateways']['house']['master']==='FFC2F780','First master from hardware');
I::master($db,'house','FFC2F700','manual','2026-10-02T11:00:00Z');
$h=I::history($db,'house');$check($h['FFC2F780']['formerMaster']&&$h['FFC2F700']['master'],'Old master retained');
$check(!$h['FFC2F700']['observed']&&!$h['FFC2F700']['written'],'Manual master never hardware evidence');
I::master($db,'house','FFC2F780','history','2026-10-03T11:00:00Z');
$check($db['gateways']['house']['master']==='FFC2F780','Master from history');
$before=$db;$throws(function()use(&$db){I::master($db,'house','FFC2F740','manual','2026-10-03T11:01:00Z');},'Unaligned manual master rejects without rounding');$check($db===$before,'Invalid master no mutation');
$throws(function()use(&$db){I::master($db,'annex','FFC2F780','history','2026-10-03T11:01:00Z');},'Cross-gateway history not implicitly shared');
I::master($db,'annex','FFC2F880','manual','2026-10-03T11:02:00Z');$check($db['gateways']['house']['master']!==$db['gateways']['annex']['master'],'Multiple gateways independent');
$count=count($db['events']);I::event($db,'house','FFC2F780','HARDWARE_DETECTED','2026-10-01T10:00:00Z','original');$check(count($db['events'])===$count,'Stable event key deduplicates replay');
I::event($db,'house','FFC2F780','HARDWARE_OBSERVED','2026-10-04T10:00:00Z','new-observation',['eurid'=>'01020304']);$h=I::history($db,'house');$check($h['FFC2F780']['firstSeen']==='2026-10-01T10:00:00Z'&&$h['FFC2F780']['lastSeen']==='2026-10-04T10:00:00Z','First/last seen merge without losing events');
$version='55002100022600020B01000206030001020304454F0103474154455741594354524C00000000007F';
$values=C::parseReadResponse('CO_RD_VERSION',hex2bin($version));
$reads=static fn(string $base)=>['CO_RD_VERSION'=>['at'=>1791020000,'session'=>'post','binding'=>'binding','values'=>$values],
    'CO_RD_IDBASE'=>['at'=>1791020000,'session'=>'post','binding'=>'binding','values'=>C::parseReadResponse('CO_RD_IDBASE',hex2bin($base))]];
$b71=['sequence'=>1,'timestamp'=>'2026-10-03T09:27:34Z','state'=>'UNKNOWN_OUTCOME','transactionID'=>'B71','owner'=>101,'target'=>'FFC2F740','backupEURID'=>'01020304','sendAttempts'=>1,
    'preview'=>['currentBaseID'=>'FFC2F780','remaining'=>10,'expectedRemaining'=>9],'reads'=>$reads('5500050102DB00FFC2F70009FA')];
$b72=$b71;$b72['sequence']=2;$b72['transactionID']='B72';$b72['readOnlyRecovery']=true;$b72['state']='RECOVERED_WITH_DIFFERENT_APPLIED_VALUE';$b72['originalIntent']=$b71;
$b73=$b71;$b73['sequence']=3;$b73['transactionID']='B73';$b73['target']='FFC2F780';$b73['state']='VERIFIED';$b73['preview']=['currentBaseID'=>'FFC2F700','remaining'=>9,'expectedRemaining'=>8];$b73['reads']=$reads('5500050102DB00FFC2F780084B');
I::importJournal($db,'house',[$b71,$b72,$b73]);$h=I::history($db,'house');
$check($h['FFC2F740']['requested']&&!$h['FFC2F740']['observed']&&!$h['FFC2F740']['written'],'B7.1 requested740 NEVER observed/applied');
$check($h['FFC2F700']['observed']&&in_array('DIFFERENT_VALUE_APPLIED',array_column($h['FFC2F700']['events'],'type'),true),'B7.1 observed700 and B7.2 recovery imported');
$check($h['FFC2F780']['written']&&in_array('WRITE_VERIFIED',array_column($h['FFC2F780']['events'],'type'),true),'B7.3 verified780 imported');
$count=count($db['events']);I::importJournal($db,'house',[$b71,$b72,$b73]);$check(count($db['events'])===$count,'WAL migration idempotent');
$bad=$b73;$bad['sequence']=4;$bad['transactionID']='bad-counter';$bad['preview']['expectedRemaining']=7;I::importJournal($db,'annex',[$bad]);
$check(!I::history($db,'annex')['FFC2F780']['written'],'Wrong counter never imported as verified write');
$oldMaster=$db['gateways']['house']['master'];I::event($db,'house','FFC2F800','HARDWARE_OBSERVED','2026-10-05T10:00:00Z','replacement',['eurid'=>'11223344']);
$check($db['gateways']['house']['master']===$oldMaster&&isset(I::history($db,'house')['FFC2F780']),'Replacement preserves Master and old history');
$db0=$db;$db0['schemaVersion']=0;$check(I::validate($db0)['events']===$db['events'],'Schema0→1 lossless migration');
$bad=$db;$bad['schemaVersion']=999;$throws(fn()=>I::validate($bad),'Unknown schema fail safe');
$bad=$db;$bad['events'][0]['written']=true;$throws(fn()=>I::validate($bad),'Fabricated success rejected');
$store=new InventoryStore(sys_get_temp_dir().'/egm-b8-inventory-'.bin2hex(random_bytes(8)));
$store->update(function(array &$d)use($db):void{$d=$db;});$export=json_decode($store->export(),true);
$check($export['schemaVersion']===1&&count($export['gateways'])===2&&count($export['events'])===count($db['events']),'Atomic store/export keeps complete multi-gateway registry');
$check($store->read()===$export,'New store reconstruction retains master/history');
$revision=$export['revision'];$throws(fn()=>$store->update(static function(array &$d):void{throw new RuntimeException('interrupted update');}),'Interrupted update fails');$check($store->read()['revision']===$revision,'Interrupted update preserves previous committed file');
$corruptDir=sys_get_temp_dir().'/egm-b8-corrupt-'.bin2hex(random_bytes(8));$corrupt=new InventoryStore($corruptDir);$corrupt->update(function(array &$d):void{I::gateway($d,'x','Gateway');});
// Test corruption in a disposable fixture only, not product data.
$f=fopen($corruptDir.'/inventory.json','r+b');fwrite($f,'broken');fclose($f);$hash=hash_file('sha256',$corruptDir.'/inventory.json');
$throws(fn()=>$corrupt->read(),'Corrupt registry detected');$throws(fn()=>$corrupt->update(function(array &$d):void{}),'Corrupt registry never silently overwritten');$check(hash_file('sha256',$corruptDir.'/inventory.json')===$hash,'Corrupt source preserved');
$devices=[['path'=>'/dev/ttyAMA0','realPath'=>'/dev/ttyAMA0','exists'=>true,'owners'=>[10],'selfPID'=>10],
    ['path'=>'/dev/ttyUSB0','realPath'=>'/dev/ttyUSB0','exists'=>true,'owners'=>[],'selfPID'=>10],
    ['path'=>'/dev/serial/by-id/enocean','realPath'=>'/dev/ttyUSB0','exists'=>true,'owners'=>[],'selfPID'=>10],
    ['path'=>'/dev/ttyACM0','realPath'=>'/dev/ttyACM0','exists'=>true,'owners'=>[20],'selfPID'=>10]];
$ds=D::classify($devices,'/dev/ttyAMA0');$check(count($ds)===3,'Several candidates; alias dedup');
$check($ds[0]['probeAllowed']&&$ds[0]['type']==='serial','Bound ttyAMA0 exclusively owned may handshake via arbiter');
$usb=array_values(array_filter($ds,fn($d)=>$d['realPath']==='/dev/ttyUSB0'))[0];$check($usb['path']==='/dev/serial/by-id/enocean'&&!$usb['probeAllowed'],'Stable by-id preferred; free USB not opened automatically');
$acm=array_values(array_filter($ds,fn($d)=>$d['realPath']==='/dev/ttyACM0'))[0];$check(!$acm['probeAllowed']&&$acm['type']==='usb','Occupied ACM no competing probe');
$check(D::classify([],'')===[],'No device');$devices[0]['exists']=false;$check(count(D::classify($devices,'/dev/ttyAMA0'))===2,'Disappeared device excluded');$devices[0]['exists']=true;$check(count(D::classify($devices,'/dev/ttyAMA0'))===3,'Device returns');
$devices[0]['owners']=null;$check(!D::classify($devices,'/dev/ttyAMA0')[0]['probeAllowed'],'Unknown owner fails closed');
$check(!isset($usb['EURID']),'Device name cannot fabricate gateway identity');
$review=['EURID'=>'01020304','baseID'=>'FFC2F780','target'=>'FFC2F700','counter'=>8,'expectedCounter'=>7,'binding'=>'binding','session'=>'old'];
$new=$review;$new['session']='new';$check(P::sameSituation($review,$new),'Fresh session can rebind unchanged values, never reuse old confirmation');
foreach(['EURID'=>'11223344','baseID'=>'FFC2F800','counter'=>7,'binding'=>'other']as$key=>$value){$changed=$new;$changed[$key]=$value;$check(!P::sameSituation($review,$changed),'Revalidation changed '.$key.' requires new conscious confirmation');}
$check(!str_contains(P::outcome('UNKNOWN_OUTCOME'),'UNKNOWN_OUTCOME')&&!str_contains(P::outcome('UNKNOWN_OUTCOME'),'versuchen'),'Unknown user language no retry');
$check(!str_contains(P::outcome('FORCE_RECONNECT'),'erfolgreich'),'RET_OK before postverify not success');
$sample=['gateway'=>['master'=>null],'hardware'=>['baseID'=>null,'counter'=>null],'history'=>[],'discovery'=>[],'fresh'=>false,'leaseActive'=>false,'flow'=>[],'inventoryError'=>false,'replacement'=>false,'connectionText'=>'Kein Gateway verbunden','message'=>'Gateway prüfen'];
foreach(['noGateway','noMaster','matching','different','replacement','unlimited','unknownCounter','expired','prepared','blocked','unknown','recoveryRequired','recoveryComplete','registryError','lost']as$scenario){
    $s=$sample;if($scenario!=='noGateway')$s['hardware']['baseID']='FFC2F780';if(in_array($scenario,['matching','different'],true))$s['gateway']['master']=$scenario==='matching'?'FFC2F780':'FFC2F700';
    if($scenario==='unlimited')$s['hardware']['counter']='UNLIMITED';if($scenario==='replacement')$s['replacement']=true;if($scenario==='registryError')$s['inventoryError']=true;
    if($scenario==='prepared')$s['flow']=['phase'=>'REVIEW','review'=>$review];if($scenario==='blocked')$s['flow']=['phase'=>'BLOCKED'];if($scenario==='unknown'){$s['message']=P::outcome('UNKNOWN_OUTCOME');$s['transactionState']='UNKNOWN_OUTCOME';$s['leaseActive']=true;}
    if($scenario==='recoveryRequired')$s['message']=P::outcome('FORCE_RECONNECT');if($scenario==='recoveryComplete')$s['message']=P::outcome('READ_ONLY_RESOLVED');
    $form=P::form($s,['actions'=>[],'elements'=>[]]);$encoded=json_encode($form,JSON_UNESCAPED_UNICODE);
    $check(str_contains($encoded,'Erweiterte Diagnose')&&str_contains($encoded,'Master Base-ID'),'Native UI structure '.$scenario);
    $primary=array_filter($form['actions'],fn($a)=>($a['type']??'')!=='ExpansionPanel');$text=json_encode($primary);
    $check(!str_contains($text,'CO_WR_IDBASE')&&!str_contains($text,'PRE_WRITE_JOURNALED')&&!str_contains($text,'MAY_HAVE_SENT'),'No developer jargon in primary '.$scenario);
    $names=[];$visit=static function(array $items)use(&$visit,&$names):void{foreach($items as$item){if(!is_array($item))continue;if(isset($item['name']))$names[]=$item['name'];foreach($item as$value)if(is_array($value))$visit($value);}};$visit([$form]);
    $check(count($names)===count(array_unique($names)),'Native form unique names '.$scenario);
}
// Complete virtual future UI flow; no compiled product barrier is opened.
$journal=new class implements WriteJournal{public array $rows=[];public function append(array $r):void{$r['sequence']=count($this->rows)+1;$this->rows[]=$r;}public function records():array{return $this->rows;}};
$now=time();$c=['arbiterID'=>200,'binding'=>'binding','session'=>'before','ownerRevision'=>'revision','realConnectionActive'=>true,'correlationSafeAndIdle'=>true,'noUnknownOutcome'=>true,'exclusiveUARTOwner'=>true];
$backup=static fn(array $c,int $at)=>['baseID'=>'FFC2F780','observedEURID'=>'01020304','parentInstanceID'=>'200','binding'=>'binding','identityConfirmation'=>['confirmedAt'=>gmdate('c',$at),'session'=>$c['session'],'backupBaseID'=>'FFC2F780','eurid'=>'01020304']];
$feed=static function(T $t,array $c,int $at,string $base)use($version,$journal):void{foreach(['CO_RD_VERSION'=>$version,'CO_RD_IDBASE'=>$base]as$op=>$hex){$r=$t->nextRead($c,$at,$journal);if($r===null||$r['operation']!==$op)throw new RuntimeException('Expected fresh canonical read');$t->receive($r['token'],$op,'RESPONSE',$hex,$c,$at,$journal);}};
$t=new T();$t->begin(101,'FFC2F700',$c,$backup($c,$now),$now,$journal);$feed($t,$c,$now,'5500050102DB00FFC2F780084B');$display=P::review($t->view());$firstID=$t->snapshot()['transactionID'];$firstToken=$t->confirmationToken(101,$firstID);$t->authorize(101,$firstID,$journal);
$check($display['baseID']==='FFC2F780'&&$display['expectedCounter']===7,'UI step1 fresh preview8→7');
$t->observe($c,$now+61,$journal);$check($t->snapshot()['state']==='CANCELLED'&&$t->snapshot()['sendAttempts']===0,'User wait >60 never sends');
$c['session']='revalidated';$t->begin(101,'FFC2F700',$c,$backup($c,$now+62),$now+62,$journal);$feed($t,$c,$now+62,'5500050102DB00FFC2F780084B');
$check(P::sameSituation($display,P::review($t->view())),'Automatic read-only revalidation unchanged');$id=$t->snapshot()['transactionID'];$nonce=$t->confirmationToken(101,$id);
$check($id!==$firstID&&$nonce!==$firstToken,'New transaction/token, no old confirmation reuse');$t->authorize(101,$id,$journal);
$throws(fn()=>$t->confirm(101,$id,$firstToken,'FFC2F700',$c,$now+62,$journal),'Old token cannot authorize fresh transaction');
// The failed old-token attempt cancels; create another independent virtual operation.
$t->begin(101,'FFC2F700',$c,$backup($c,$now+63),$now+63,$journal);$feed($t,$c,$now+63,'5500050102DB00FFC2F780084B');$id=$t->snapshot()['transactionID'];$t->authorize(101,$id,$journal);$t->confirm(101,$id,$t->confirmationToken(101,$id),'FFC2F700',$c,$now+63,$journal);$feed($t,$c,$now+63,'5500050102DB00FFC2F780084B');
$check($t->snapshot()['state']==='PRE_WRITE_JOURNALED','Both stages and renewed pre-send reads');$check($t->prepareSend($c,$now+63,true,$journal)===null&&$t->snapshot()['sendAttempts']===0,'B8 true barrier stops real-equivalent workflow');
$frame=$t->prepareSend($c,$now+63,false,$journal);$check($frame!==null&&$t->snapshot()['sendAttempts']===1,'Pure virtual parent single send (no product constant change)');$throws(fn()=>$t->prepareSend($c,$now+63,false,$journal),'Virtual no retry');
$t->writeResponse('5500010002650000',$now+63,$journal);$check($t->snapshot()['state']==='FORCE_RECONNECT','RET_OK not VERIFIED');$off=$c;$off['realConnectionActive']=false;$t->observe($off,$now+64,$journal);$c['session']='post-reconnect';$t->observe($c,$now+65,$journal);
$reply=static function(string $data,string $opt):string{$d=hex2bin($data);$o=hex2bin($opt);$h=pack('nCC',strlen($d),strlen($o),2);return strtoupper(bin2hex(chr(85).$h.chr(C::crc8($h)).$d.$o.chr(C::crc8($d.$o))));};
$feed($t,$c,$now+65,$reply('00FFC2F700','07'));
$check($t->snapshot()['state']==='VERIFIED'&&$t->snapshot()['reads']['CO_RD_IDBASE']['values']['remainingWriteCycles']===7,'Virtual final700/7 VERIFIED only after new-session reads');
$virtual=I::empty();I::gateway($virtual,'virtual','Virtuelles Gateway');I::master($virtual,'virtual','FFC2F700','manual',gmdate('c'));I::importJournal($virtual,'virtual',$journal->records());
$check(I::history($virtual,'virtual')['FFC2F700']['written']&&$virtual['gateways']['virtual']['master']===$t->snapshot()['reads']['CO_RD_IDBASE']['values']['baseIdRawHex'],'Virtual WRITE_VERIFIED history and Master==hardware');
echo 'PASS: '.$passed.' B8 product/model/discovery/virtual assertions'.PHP_EOL;
