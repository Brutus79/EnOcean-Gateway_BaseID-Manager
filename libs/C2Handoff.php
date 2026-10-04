<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Maintenance;
require_once __DIR__.'/NativeGatewayResolver.php';
require_once __DIR__.'/WriteJournal.php';
use EnOceanGatewayManager\Safety\WriteJournal;
use RuntimeException;

interface C2Environment
{
    public function instance(int $id): array;
    public function configuration(int $id): array;
    public function instances(): array;
    public function create(string $module): int;
    public function configure(int $id,array $configuration): void;
    public function connect(int $child,int $parent): void;
    public function disconnect(int $child): void;
    public function delete(int $id): void;
    public function descriptors(string $path): ?array;
    public function selfPID(): int;
}

/** Durable handoff, no ESP3 sends. All foreign changes use exact CAS snapshots.
 * Steps are bounded and timer-driven, never wait/sleep for serial ownership.
 * Restart reconstructs from the journal, never continues synchronization/write.
 */
final class C2Handoff
{
    public const ARBITER='{C5D65AB1-045B-40ED-B854-3D74D41C81EC}';
    public const MANAGER='{ED8F6F0C-D57F-4E0F-B23B-63CF05AE9643}';
    private array $s=[];
    public function __construct(private readonly C2Environment $e,private readonly WriteJournal $journal)
    {
        $records=$journal->records();
        if($records!==[])$this->s=$records[array_key_last($records)]['handoff']??[];
    }
    public function state(): array { return $this->s; }
    /** Durable cancellation only. A removed manager never resumes a session. */
    public function retire(int $manager,float $now): string
    {
        if($this->s===[]||$this->s['phase']==='RESTORED')return'RESTORED';
        if($manager!==$this->s['manager'])throw new RuntimeException('Retirement owner mismatch.');
        $this->s['retiring']=true;$this->record('MANAGER_RETIRE_REQUESTED');
        return$this->restore($now);
    }
    private function recoverDeletion(): void
    {
        $all=$this->journal->records();$last=$all===[]?'':$all[array_key_last($all)]['event'];
        foreach(['OWN_ARBITER_DELETE_INTENT'=>'ownArbiter','OWN_IO_DELETE_INTENT'=>'ownIO']as$event=>$key){
            if($last===$event&&$this->s[$key]>0&&!in_array($this->s[$key],$this->e->instances(),true)){
                $this->s[$key]=0;$this->record('OWN_DELETE_COMPLETION_RECOVERED');
            }
        }
    }
    private function record(string $event): void { $this->journal->append(['event'=>$event,'at'=>microtime(true),'handoff'=>$this->s]); }
    public function begin(int $manager,int $reference,float $now): void
    {
        if($this->s!==[]&&$this->s['phase']!=='RESTORED')throw new RuntimeException('Previous handoff requires safe return.');
        $r=new NativeGatewayResolver($this->e->instance(...),$this->e->configuration(...),$this->e->instances(...));
        $snapshot=$r->resolve($reference);
        $m=$this->e->instance($manager);
        if(($m['ModuleInfo']['ModuleID']??'')!==self::MANAGER || ($m['ConnectionID']??-1)!==0)throw new RuntimeException('Manager must be disconnected before takeover.');
        $this->exclusive($snapshot['ioConfiguration']['Port'],1);
        $this->s=['phase'=>'CAPTURED','id'=>bin2hex(random_bytes(16)),'manager'=>$manager,
            'snapshot'=>$snapshot,'ownIO'=>0,'ownArbiter'=>0,'ownIOConfiguration'=>[],
            'ownArbiterConfiguration'=>[],'ownIOAllowed'=>[],'ownArbiterAllowed'=>[],
            'ownIOIdent'=>'','ownArbiterIdent'=>'','startedAt'=>$now,'closingAt'=>$now,'reason'=>''];
        $this->record('CAPTURED');
        if($r->resolve($reference)!==$snapshot)throw new RuntimeException('Gateway changed before close.');
        $closed=$snapshot['ioConfiguration'];$closed['Open']=false;
        $this->cas($snapshot['ioID'],$snapshot['ioConfiguration'],$closed);
        $this->s['phase']='CLOSING_NATIVE';$this->record('NATIVE_CLOSE_APPLIED');
    }
    private function cas(int $id,array $expected,array $desired): void
    {
        if($this->e->configuration($id)!==$expected)throw new RuntimeException('Configuration changed: no overwrite.');
        $this->record('CONFIGURATION_INTENT');
        $this->e->configure($id,$desired);
        if($this->e->configuration($id)!==$desired)throw new RuntimeException('Configuration application not verified.');
    }
    private function exclusive(string $path,int $count): void
    {
        $fds=$this->e->descriptors($path);
        if($fds===null||count($fds)!==$count)throw new RuntimeException('UART descriptor ownership not proven (expected '.$count.', observed '.($fds===null?'unknown':count($fds)).').');
        foreach($fds as$fd)if($fd['pid']!==$this->e->selfPID())throw new RuntimeException('UART owned by another process.');
        // Configuration users/aliases are checked in the runtime context as well.
    }
    private function nativeExpected(bool $detached): void
    {
        $n=$this->s['snapshot'];$i=$this->e->instance($n['nativeID']);$io=$this->e->instance($n['ioID']);
        $closed=$n['ioConfiguration'];$closed['Open']=false;
        if(($i['ModuleInfo']['ModuleID']??'')!==NativeGatewayResolver::NATIVE
            ||($i['ConnectionID']??-1)!==($detached?0:$n['ioID'])
            ||($io['ModuleInfo']['ModuleID']??'')!==NativeGatewayResolver::SERIAL
            ||($io['ConnectionID']??-1)!==0
            ||$this->e->configuration($n['nativeID'])!==$n['nativeConfiguration']
            ||$this->e->configuration($n['ioID'])!==$closed)throw new RuntimeException('Native handoff CAS/context changed.');
        $expectedUsers=$detached?[]:[$n['nativeID']];$users=[];
        foreach($this->e->instances()as$id)if(($this->e->instance($id)['ConnectionID']??0)===$n['ioID'])$users[]=$id;
        sort($users);if($users!==$expectedUsers)throw new RuntimeException('Native I/O acquired by another instance.');
    }
    public function advance(float $now): string
    {
        if(($this->s['phase']??'')!=='CLOSING_NATIVE')return $this->s['phase']??'IDLE';
        $this->nativeExpected(false);
        $fds=$this->e->descriptors($this->s['snapshot']['ioConfiguration']['Port']);
        if($fds!==[]){if($now-$this->s['closingAt']>3)throw new RuntimeException('Native I/O failed to release UART.');return'CLOSING_NATIVE';}
        $this->record('NATIVE_DETACH_INTENT');$this->e->disconnect($this->s['snapshot']['nativeID']);
        $this->s['phase']='DETACHED';$this->record('NATIVE_DETACHED');$this->nativeExpected(true);
        $io=$this->e->create(NativeGatewayResolver::SERIAL);$this->s['ownIO']=$io;
        $this->s['ownIOIdent']=$this->e->instance($io)['C2OwnershipIdent']??'';
        if($this->s['ownIOIdent']==='')throw new RuntimeException('Own object marker unavailable.');
        $this->s['ownIOAllowed'][]=$this->e->configuration($io);$this->record('OWN_IO_CREATED');
        $closed=$this->s['snapshot']['ioConfiguration'];$closed['Open']=false;
        $this->s['ownIOConfiguration']=$closed;$this->s['ownIOAllowed'][]=$closed;$this->record('OWN_IO_CONFIGURATION_INTENT');
        $this->e->configure($io,$closed);
        $a=$this->e->create(self::ARBITER);$this->s['ownArbiter']=$a;
        $this->s['ownArbiterIdent']=$this->e->instance($a)['C2OwnershipIdent']??'';
        if($this->s['ownArbiterIdent']==='')throw new RuntimeException('Own object marker unavailable.');
        $this->s['ownArbiterAllowed'][]=$this->e->configuration($a);$this->record('OWN_ARBITER_CREATED');
        $ac=$this->e->configuration($a);$ac['EnableMaintenance']=true;$ac['IsolatedReadOnly']=true;
        $this->s['ownArbiterConfiguration']=$ac;$this->s['ownArbiterAllowed'][]=$ac;$this->record('OWN_ARBITER_CONFIGURATION_INTENT');
        $this->e->configure($a,$ac);$this->e->connect($a,$io);$this->record('OWN_ARBITER_CONNECTED');
        $this->e->connect($this->s['manager'],$a);$this->record('MANAGER_CONNECTED');
        $this->nativeExpected(true);$this->exclusive($closed['Port'],0);
        $this->s['ownIOConfiguration']['Open']=true;$this->s['ownIOAllowed'][]=$this->s['ownIOConfiguration'];$this->record('OWN_OPEN_INTENT');
        $this->e->configure($io,$this->s['ownIOConfiguration']);
        $this->s['phase']='ACTIVE';$this->record('OWN_OPEN_APPLIED');
        return 'ACTIVE';
    }
    public function verifyActive(): string
    {
        if(($this->s['phase']??'')!=='ACTIVE')throw new RuntimeException('Handoff not active.');
        $this->nativeExpected(true);
        $this->verifyOwn(true);$this->exclusive($this->s['ownIOConfiguration']['Port'],1);
        return NativeGatewayResolver::fingerprint([$this->s['id'],$this->s['snapshot'],
            $this->s['ownIO'],$this->s['ownArbiter'],$this->s['ownIOConfiguration'],$this->s['ownArbiterConfiguration']]);
    }
    private function verifyOwn(bool $attached,bool $restoring=false): void
    {
        $io=$this->s['ownIO'];$a=$this->s['ownArbiter'];
        if($io>0){$n=$this->e->instance($io);if(($n['ModuleInfo']['ModuleID']??'')!==NativeGatewayResolver::SERIAL
            ||($n['C2OwnershipIdent']??'')!==$this->s['ownIOIdent'] ||($n['ConnectionID']??-1)!==0
            ||$this->e->configuration($io)!==$this->s['ownIOConfiguration'])throw new RuntimeException('Own I/O changed.');}
        if($a>0){$n=$this->e->instance($a);if(($n['ModuleInfo']['ModuleID']??'')!==self::ARBITER
            ||($n['C2OwnershipIdent']??'')!==$this->s['ownArbiterIdent']
            ||($restoring?!in_array($n['ConnectionID']??-1,[0,$io],true):($n['ConnectionID']??-1)!==($attached?$io:0))
            ||($restoring?!in_array($this->e->configuration($a),$this->s['ownArbiterAllowed'],true):$this->e->configuration($a)!==$this->s['ownArbiterConfiguration']))throw new RuntimeException('Own arbiter changed.');}
        foreach($this->e->instances()as$id){$p=$this->e->instance($id)['ConnectionID']??0;
            if(($io>0&&$p===$io&&$id!==$a)||($a>0&&$p===$a&&$id!==$this->s['manager']))throw new RuntimeException('Foreign child on temporary transport.');}
    }
    /** Start restoration, also valid after restart or an interrupted INTENT.
     * Only observed exact owned configurations can be closed/deleted. If another
     * actor changed anything, leave that configuration intact and require review.
     */
    public function restore(float $now): string
    {
        if($this->s===[]||$this->s['phase']==='RESTORED')return'RESTORED';
        $this->recoverDeletion();
        $n=$this->s['snapshot'];$io=$this->s['ownIO'];$a=$this->s['ownArbiter'];
        if($io===0&&$a===0&&($this->e->instance($n['nativeID'])['ConnectionID']??-1)===$n['ioID']
            &&$this->e->configuration($n['ioID'])===$n['ioConfiguration']
            &&$this->e->configuration($n['nativeID'])===$n['nativeConfiguration']){
            $this->s['phase']='RESTORED';$this->record('ORIGINAL_CONFIGURATION_UNCHANGED');return'RESTORED';
        }
        if($io>0){
            foreach($this->e->instances()as$id){$p=$this->e->instance($id)['ConnectionID']??0;
                if(($p===$io&&$id!==$a)||($a>0&&$p===$a&&$id!==$this->s['manager']))throw new RuntimeException('Foreign user: no blind transport close.');}
            $c=$this->e->configuration($io);$expected=$this->s['ownIOConfiguration'];
            $closed=$expected;$closed['Open']=false;
            if(!in_array($c,$this->s['ownIOAllowed'],true)&&$c!==$closed)throw new RuntimeException('Temporary I/O changed; no blind close.');
            if(($this->e->instance($io)['ModuleInfo']['ModuleID']??'')!==NativeGatewayResolver::SERIAL
                ||($this->e->instance($io)['C2OwnershipIdent']??'')!==$this->s['ownIOIdent'])throw new RuntimeException('Temporary module identity changed.');
            $closed=$c;$closed['Open']=false;
            $this->s['ownIOConfiguration']=$closed;$this->record('OWN_CLOSE_INTENT');
            $this->e->configure($io,$closed);
        }
        $this->s['phase']='RETURN_CLOSING';$this->s['closingAt']=$now;$this->record('RETURN_STARTED');
        return 'RETURN_CLOSING';
    }
    public function finishRestore(float $now): string
    {
        if(($this->s['phase']??'')!=='RETURN_CLOSING')return$this->s['phase']??'IDLE';
        $n=$this->s['snapshot'];
        // A crash can occur after deletion but before its completion record.
        $this->recoverDeletion();
        $fds=$this->e->descriptors($n['ioConfiguration']['Port']);
        if($fds!==[]){if($now-$this->s['closingAt']>3)throw new RuntimeException('Temporary UART close not proven.');return'RETURN_CLOSING';}
        // Never disconnect a manager from a foreign parent.
        $managerExists=in_array($this->s['manager'],$this->e->instances(),true);
        if(!$managerExists&&!($this->s['retiring']??false))throw new RuntimeException('Missing manager without durable retirement intent.');
        $mp=$managerExists?($this->e->instance($this->s['manager'])['ConnectionID']??-1):0;
        if($mp!==0&&$mp!==$this->s['ownArbiter'])throw new RuntimeException('Manager parent changed.');
        if($mp>0){$this->record('MANAGER_DETACH_INTENT');$this->e->disconnect($this->s['manager']);}
        $a=$this->s['ownArbiter'];$io=$this->s['ownIO'];
        if($a>0){$this->verifyOwn(true,true);$this->record('OWN_ARBITER_DETACH_INTENT');
            if(($this->e->instance($a)['ConnectionID']??0)>0)$this->e->disconnect($a);
            $this->verifyOwn(false,true);$this->record('OWN_ARBITER_DELETE_INTENT');$this->e->delete($a);$this->s['ownArbiter']=0;$this->record('OWN_ARBITER_DELETED');}
        if($io>0){$this->verifyOwn(false);$this->record('OWN_IO_DELETE_INTENT');$this->e->delete($io);$this->s['ownIO']=0;$this->record('OWN_IO_DELETED');}
        $parent=$this->e->instance($n['nativeID'])['ConnectionID']??-1;
        if($parent!==0&&$parent!==$n['ioID'])throw new RuntimeException('Native parent changed; do not overwrite.');
        $this->nativeExpected($parent===0);
        if($parent===0){$this->record('NATIVE_CONNECT_INTENT');$this->e->connect($n['nativeID'],$n['ioID']);}
        $closed=$n['ioConfiguration'];$closed['Open']=false;
        $this->cas($n['ioID'],$closed,$n['ioConfiguration']);
        if(($this->e->instance($n['nativeID'])['ConnectionID']??0)!==$n['ioID'])throw new RuntimeException('Native restore not verified.');
        $this->s['phase']='RESTORED';$this->record('CONFIGURATION_RESTORED_REFRESH_NOT_YET_PROVEN');
        return 'RESTORED';
    }
}
