<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/GatewayDiscovery.php';
use EnOceanGatewayManager\Product\GatewayDiscovery;
if(PHP_OS_FAMILY!=='Linux'||!preg_match('/^Uid:\s+0\s+0\s+0\s+0\s*$/m',file_get_contents('/proc/self/status'))){
    echo "NOT TESTED: C2 dynamic FD metadata needs root Linux /proc\n";return;
}
$count=0;$stale=0;$check=static function(bool$b,string$l)use(&$count):void{$count++;if(!$b)throw new RuntimeException($l);};
for($i=0;$i<25;$i++){
    $null=fopen('/dev/null','r');$path=null;
    foreach(glob('/proc/'.getmypid().'/fd/*')as$p)if(@readlink($p)==='/dev/null'&&(int)basename($p)>2)$path=$p;
    $check($path!==null,'own harmless null descriptor found');
    clearstatcache(true);$original=stat($path);fclose($null);$zero=fopen('/dev/zero','r');
    $check(@readlink($path)==='/dev/zero','descriptor reused with different target');
    clearstatcache();$cached=stat($path);clearstatcache(true);$fresh=stat($path);
    if($cached['rdev']===$original['rdev']&&$fresh['rdev']!==$cached['rdev'])$stale++;
    // Different PHP builds can already resolve this correctly. Assert current
    // truth, not that every runtime must reproduce the historical cache bug.
    $check($fresh['rdev']===stat('/dev/zero')['rdev'],'full cache clearing resolves current device');
    $rows=GatewayDiscovery::descriptors('/dev/zero');
    $check(is_array($rows)&&in_array(['pid'=>getmypid(),'fd'=>basename($path)],$rows,true),'production metadata inspector resolves current descriptor');
    fclose($zero);
}
echo "PASS: C2 dynamic FD metadata {$count} assertions, {$stale} stale stat-only observations (no serial opens)\n";
