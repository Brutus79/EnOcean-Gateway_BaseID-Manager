<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Product;
require_once __DIR__.'/GatewayInventory.php';

/** Atomic, checksummed product registry; never reads/writes the transport WAL. */
final class InventoryStore
{
    public function __construct(private string $directory) {}
    public function read(): array
    {
        $path=$this->directory.'/inventory.json';
        if(is_link($this->directory)||is_link($path)){throw new \RuntimeException('Inventar-Symlink abgelehnt.');}
        if(!file_exists($path)){return GatewayInventory::empty();}
        $envelope=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($envelope['data']??null)||!hash_equals(hash('sha256',json_encode($envelope['data'],JSON_THROW_ON_ERROR)),$envelope['sha256']??'')){throw new \RuntimeException('Inventar-Prüfsumme ungültig. Keine automatische Reparatur.');}
        return GatewayInventory::validate($envelope['data']);
    }
    public function update(callable $operation): array
    {
        if(is_link($this->directory)||(!is_dir($this->directory)&&!mkdir($this->directory,0700,true))){throw new \RuntimeException('Inventar-Verzeichnis nicht sicher.');}
        $parent=fopen(dirname($this->directory),'r');if(!$parent)throw new \RuntimeException('Inventar-Elternverzeichnis nicht synchronisierbar.');try{if(!fsync($parent))throw new \RuntimeException('Inventar-Verzeichnis nicht dauerhaft bestätigt.');}finally{fclose($parent);}
        $lockPath=$this->directory.'/inventory.lock';if(is_link($lockPath)){throw new \RuntimeException('Unsichere Inventarsperre.');}
        $lock=fopen($lockPath,'c');if(!$lock||!flock($lock,LOCK_EX)){throw new \RuntimeException('Inventar gesperrt.');}chmod($lockPath,0600);
        $temporary=null;
        try{$db=$this->read();$operation($db);$db['revision']++;$db=GatewayInventory::validate($db);
            $json=json_encode(['data'=>$db,'sha256'=>hash('sha256',json_encode($db,JSON_THROW_ON_ERROR))],JSON_THROW_ON_ERROR);
            $temporary=$this->directory.'/pending-'.bin2hex(random_bytes(12));$f=fopen($temporary,'x+b');if(!$f)throw new \RuntimeException('Inventar kann nicht geschrieben werden.');chmod($temporary,0600);
            try{if(fwrite($f,$json)!==strlen($json)||!fflush($f)||!fsync($f)){throw new \RuntimeException('Inventar nicht dauerhaft geschrieben.');}}finally{fclose($f);}
            if(!rename($temporary,$this->directory.'/inventory.json'))throw new \RuntimeException('Atomare Inventarübernahme fehlgeschlagen.');$temporary=null;
            $dir=fopen($this->directory,'r');if(!$dir)throw new \RuntimeException('Inventarverzeichnis nicht synchronisierbar.');try{if(!fsync($dir))throw new \RuntimeException('Inventarverzeichnis nicht dauerhaft bestätigt.');}finally{fclose($dir);}
            return $db;
        }finally{if($temporary!==null&&is_file($temporary))unlink($temporary);flock($lock,LOCK_UN);fclose($lock);}
    }
    public function export(): string { return json_encode($this->read(),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT); }
}
