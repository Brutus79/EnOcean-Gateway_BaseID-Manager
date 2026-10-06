<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Maintenance;

use RuntimeException;
use Throwable;

/** Resolve the reference anew. Neither inventory nor a saved port is consulted. */
final class NativeGatewayResolver
{
    public const NATIVE = '{A52FEFE9-7858-4B8E-A96E-26E15CB944F7}';
    public const SERIAL = '{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}';
    public const SOCKET = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    public function __construct(private readonly \Closure $instance,
        private readonly \Closure $configuration, private readonly \Closure $instances) {}
    public function resolve(int $reference): array
    {
        if ($reference <= 0) throw new RuntimeException('Select an existing native EnOcean gateway.');
        $native = ($this->instance)($reference);
        if (($native['ModuleInfo']['ModuleID'] ?? '') !== self::NATIVE) throw new RuntimeException('Native gateway missing or wrong module.');
        $nativeConfig = ($this->configuration)($reference);
        if (($nativeConfig['GatewayMode'] ?? null) !== 2) throw new RuntimeException('Only documented ESP3 binary mode is supported.');
        $parent = (int)($native['ConnectionID'] ?? 0);
        if ($parent <= 0) throw new RuntimeException('Native gateway has no current I/O.');
        $io = ($this->instance)($parent);
        $module = $io['ModuleInfo']['ModuleID'] ?? '';
        if ($module === self::SOCKET) throw new RuntimeException('LAN C2 contract not verified; maintenance unavailable.');
        if ($module !== self::SERIAL || (int)($io['ConnectionID'] ?? 0) !== 0) {
            throw new RuntimeException('Unknown transport chain; no automatic takeover.');
        }
        $config = ($this->configuration)($parent);
        foreach (['Port','BaudRate','DataBits','Parity','StopBits','Open'] as $key) {
            if (!array_key_exists($key,$config)) throw new RuntimeException('Transport configuration incomplete.');
        }
        if (!is_string($config['Port']) || $config['Port'] === '' || !ctype_digit((string)$config['BaudRate'])
            || (int)$config['BaudRate'] <= 0 || (string)$config['DataBits'] !== '8'
            || (string)$config['StopBits'] !== '1' || $config['Parity'] !== 'None'
            || $config['Open'] !== true || ($io['InstanceStatus'] ?? 0) !== 102
            || ($native['InstanceStatus'] ?? 0) !== 102) throw new RuntimeException('Transport is not an active supported 8N1 profile.');
        $users = [];
        foreach (($this->instances)() as $id) {
            if ((int)(($this->instance)($id)['ConnectionID'] ?? 0) === $parent) $users[]=$id;
        }
        sort($users);
        if ($users !== [$reference]) throw new RuntimeException('I/O is shared; exclusive takeover unavailable.');
        $snapshot = ['nativeID'=>$reference,'ioID'=>$parent,'profile'=>'serial-esp3',
            'nativeConfiguration'=>$nativeConfig,'ioConfiguration'=>$config,'nativeParent'=>$parent];
        $snapshot['binding'] = self::fingerprint($snapshot);
        return $snapshot;
    }
    /** Manual references, not takeover capability. No transport/configuration probe. */
    public function references(array $nativeIDs, \Closure $name): array
    {
        $out=[];
        foreach($nativeIDs as$id){
            try{
                if((($this->instance)($id)['ModuleInfo']['ModuleID']??'')!==self::NATIVE)continue;
                $out[]=['id'=>$id,'name'=>$name($id)];
            }catch(Throwable){} // Deleted between enumeration and metadata read.
        }
        return $out;
    }
    public function discover(array $nativeIDs, \Closure $name): array
    {
        $out=[];
        foreach($nativeIDs as$id){
            try { $s=$this->resolve($id); $reason='Seriell · exklusive Wartung verfügbar'; $supported=true; }
            catch(Throwable $e){$reason=$e->getMessage();$supported=false;}
            $out[]=['id'=>$id,'name'=>$name($id),'supported'=>$supported,'reason'=>$reason];
        }
        return $out;
    }
    public static function fingerprint(array $value): string
    {
        $sort = static function(array $a) use (&$sort): array {
            if (!array_is_list($a)) ksort($a);
            foreach($a as$k=>$v)if(is_array($v))$a[$k]=$sort($v);
            return $a;
        };
        return hash('sha256',json_encode($sort($value),JSON_THROW_ON_ERROR));
    }
}
