<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/MasterTargetBinding.php';
require_once __DIR__.'/../libs/ProductPresentation.php';
use EnOceanGatewayManager\Product\MasterTargetBinding as B;
use EnOceanGatewayManager\Product\ProductPresentation as P;
$passed=0;$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;};
$reject=static function(callable $f,string $label)use($check):void{try{$f();}catch(Throwable){$check(true,$label);return;}$check(false,$label);};
$s=['gateway'=>['master'=>'FFC2F700','acceptedEURID'=>'01020304'],'hardware'=>['baseID'=>'FFC2F780','EURID'=>'01020304','counter'=>8],
    'inventoryError'=>false,'replacement'=>false,'leaseActive'=>false,'fresh'=>true,'pending'=>false];
$c=['realConnectionActive'=>true,'correlationSafeAndIdle'=>true,'noUnknownOutcome'=>true,'exclusiveUARTOwner'=>true,'exclusiveChain'=>true,'arbiterID'=>200,'binding'=>'binding'];
$backup=['baseID'=>'FFC2F780','observedEURID'=>'01020304','parentInstanceID'=>'200','binding'=>'binding'];
$old=['BaseIDSource'=>'saved','ManualBaseID'=>''];$before=$old;
$choice=B::selection($s,$c,$backup);
$check($choice===[['name'=>'BaseIDSource','value'=>'manual'],['name'=>'ManualBaseID','value'=>'FFC2F700']],'Conscious native option uses ONLY existing applied properties');
$check($old===$before,'Calculating/displaying option cannot apply target');
$reject(fn()=>B::applied($s,$old,$c,$backup),'Master existence alone cannot replace backup target');
$native=$old;foreach($choice as$p)$native[$p['name']]=$p['value'];
$view=B::applied($s,$native,$c,$backup);
$check($view['currentBaseID']==='FFC2F780'&&$view['master']==='FFC2F700'&&$view['target']==='FFC2F700','Hardware/Master/applied target stay separate');
$check($view['remaining']===8&&$view['hardwareWriteBarrier']&&!$view['hardwareWriteEnabled']&&!$view['transactionStarted'],'Target validation neither writes nor starts a transaction');
$same=$s;$same['hardware']['baseID']='FFC2F700';$check(B::applied($same,$native,$c,$backup)['currentBaseID']==='FFC2F700','Same current/Master supported');
foreach([null,'','NOTHEX!!','FFC2F740','FE000000','FFFFFF81']as$master){$bad=$s;$bad['gateway']['master']=$master;$reject(fn()=>B::selection($bad,$c,$backup),'Missing/invalid/out-of-range/unaligned Master rejected '.json_encode($master));}
$bad=$native;$bad['ManualBaseID']='FFC2F800';$reject(fn()=>B::applied($s,$bad,$c,$backup),'Tampered applied target differs from Master');
$bad=$native;$bad['BaseIDSource']='other';$reject(fn()=>B::applied($s,$bad,$c,$backup),'Unknown source rejected');
$changed=$s;$changed['hardware']['baseID']='FFC2F800';B::applied($changed,$native,$c,$backup);$check($changed['gateway']['master']==='FFC2F700','Changed hardware never overwrites Master');
foreach(['inventoryError'=>true,'replacement'=>true,'leaseActive'=>true,'fresh'=>false,'pending'=>true]as$key=>$value){$bad=$s;$bad[$key]=$value;$reject(fn()=>B::selection($bad,$c,$backup),'Selection gate '.$key);}
foreach(['realConnectionActive','correlationSafeAndIdle','noUnknownOutcome','exclusiveUARTOwner','exclusiveChain']as$key){$bad=$c;$bad[$key]=false;$reject(fn()=>B::selection($s,$bad,$backup),'Transport gate '.$key);}
foreach(['observedEURID'=>'11223344','parentInstanceID'=>'201','binding'=>'other','baseID'=>'FFC2F740']as$key=>$value){$bad=$backup;$bad[$key]=$value;$reject(fn()=>B::selection($s,$c,$bad),'Conflicting backup '.$key);}
$bad=$s;$bad['hardware']['EURID']=null;$reject(fn()=>B::selection($bad,$c,$backup),'No guessed gateway identity');
$check(B::selection($s,$c,[])===$choice,'New gateway without a backup may configure a target, not write');
$sample=$s+['history'=>[],'discovery'=>[],'flow'=>[],'connectionText'=>'Connected','message'=>'Read gateway','targetConfiguration'=>$old,'masterTargetSelection'=>$choice,'targetValidated'=>false,'targetOnlyBuild'=>true];
$form=P::form($sample,['actions'=>[],'elements'=>[['name'=>'BaseIDSource','type'=>'Select'],['name'=>'ManualBaseID','type'=>'ValidationTextBox']]]);
$select=$form['elements'][2]['items'][1];
$check($select['name']==='MasterTargetChoice'&&$select['options'][1]['value']===$choice,'Native MultiSelect in elements submits both properties');
$check($select['options'][0]['value']===[['name'=>'BaseIDSource','value'=>'saved'],['name'=>'ManualBaseID','value'=>'']],'Default remains old configuration, not automatic Master selection');
$encoded=json_encode($form,JSON_UNESCAPED_UNICODE);$check(!str_contains($encoded,'"name":"BaseIDSource"')||substr_count($encoded,'"name":"BaseIDSource"')===2,'No duplicate legacy editor fields, only native option payloads');
$helper=file_get_contents(__DIR__.'/../libs/MasterTargetBinding.php');
foreach(['IPS_SetProperty','IPS_ApplyChanges','SendDataToParent','CO_WR_IDBASE','WriteJournal']as$forbidden)$check(!str_contains($helper,$forbidden),'Pure target binding has no '.$forbidden);
$product=file_get_contents(__DIR__.'/../libs/ProductModule.php');$check(str_contains($product,'private const PRODUCT_TARGET_ONLY = true'),'B8.2 product transaction start is immutable blocked');
$arbiter=file_get_contents(__DIR__.'/../ESP3TransportArbiter/module.php');
$check(str_contains($arbiter,'private const B6_HARDWARE_WRITE_BARRIER = true')&&str_contains($arbiter,'private const BLOCK_NATIVE_TX = true'),'Actual compiled chip/native barriers remain true');
$check(str_contains($arbiter,'Target does not match current owner configuration.'),'Independent frozen arbiter target check remains');
// Feed the SAME native-applied target into the unchanged engine, offline only.
require_once __DIR__.'/../libs/TransactionalWrite.php';
$journal=new class implements \EnOceanGatewayManager\Safety\WriteJournal{public array $rows=[];public function append(array $r):void{$this->rows[]=$r;}public function records():array{return $this->rows;}};
$now=time();$engineContext=$c+['session'=>'offline-b82','ownerRevision'=>hash('sha256',json_encode($native))];
$engineBackup=$backup+['identityConfirmation'=>['confirmedAt'=>gmdate('c',$now),'session'=>'offline-b82','backupBaseID'=>'FFC2F780','eurid'=>'01020304']];
$engine=new \EnOceanGatewayManager\Safety\TransactionalWrite();$engine->begin(101,$view['target'],$engineContext,$engineBackup,$now,$journal);
$feed=static function()use($engine,$engineContext,$now,$journal):void{foreach(['55002100022600020B01000206030001020304454F0103474154455741594354524C00000000007F','5500050102DB00FFC2F780084B']as$reply){$r=$engine->nextRead($engineContext,$now,$journal);$engine->receive($r['token'],$r['operation'],'RESPONSE',$reply,$engineContext,$now,$journal);}};
$feed();$v=$engine->view();$check($v['state']==='READY_FOR_CONFIRMATION'&&$v['target']==='FFC2F700','Existing engine accepts exact native-applied Master target offline');
$check($v['preview']['remaining']===8&&$v['preview']['expectedRemaining']===7,'Existing engine uses actual mocked hardware counter and reserve');
$engine->authorize(101,$v['transactionID'],$journal);$token=$engine->confirmationToken(101,$v['transactionID']);$engine->confirm(101,$v['transactionID'],$token,$view['target'],$engineContext,$now,$journal);$feed();
$check($engine->view()['state']==='PRE_WRITE_JOURNALED','Full mocked path passes mandatory re-reads and durable intent gate');
$check($engine->prepareSend($engineContext,$now,true,$journal)===null,'Closed barrier yields no ESP3 write effect even after confirmations');
$check($engine->view()['sendAttempts']===0,'No virtual parent write attempt under actual barrier policy');
echo 'PASS: '.$passed.' B8.2 master-target assertions'.PHP_EOL;
