<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$passed = 0;

$assertTrue = static function (bool $condition, string $name) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException($name . ' failed.');
    }
    $passed++;
};

$readJson = static function (string $path): array {
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Expected JSON object in ' . $path);
    }
    return $decoded;
};

$library = $readJson($root . '/library.json');
$arbiterModule = $readJson($root . '/ESP3TransportArbiter/module.json');
$managerModule = $readJson($root . '/EnOceanGatewayManager/module.json');
$arbiterForm = $readJson($root . '/ESP3TransportArbiter/form.json');

$assertTrue($library['compatibility']['version'] === '9.0', 'Library requires IP-Symcon 9.0');
$assertTrue($arbiterModule['type'] === 2, 'Arbiter is a splitter');
$assertTrue($managerModule['type'] === 3, 'Manager is a device');
$assertTrue($arbiterModule['name'] === 'ESP3 Transport Arbiter', 'Arbiter class/module name');
$assertTrue($managerModule['name'] === 'EnOcean Gateway Manager', 'Manager class/module name');

$ids = [$library['id'], $arbiterModule['id'], $managerModule['id']];
$assertTrue(count($ids) === count(array_unique($ids)), 'Library and module IDs are unique');

$requestDataId = '{F5B497B9-0A7D-4F1A-A830-86B1B85A55D4}';
$resultDataId = '{4AE7CA23-1782-4C98-81E2-2BA7FC918C2C}';
$assertTrue(in_array($requestDataId, $arbiterModule['implemented'], true), 'Arbiter implements maintenance request interface');
$assertTrue(in_array($requestDataId, $managerModule['parentRequirements'], true), 'Manager requires maintenance request interface');
$assertTrue(in_array($resultDataId, $arbiterModule['childRequirements'], true), 'Arbiter declares maintenance result children');
$assertTrue(in_array($resultDataId, $managerModule['implemented'], true), 'Manager implements maintenance result interface');

$maintenanceDefault = null;
foreach ($arbiterForm['elements'] as $element) {
    if (($element['name'] ?? '') === 'EnableMaintenance') {
        $maintenanceDefault = $element;
    }
}
$assertTrue(is_array($maintenanceDefault), 'Arbiter form exposes maintenance gate');

$arbiterCode = (string) file_get_contents($root . '/ESP3TransportArbiter/module.php');
$managerCode = (string) file_get_contents($root . '/EnOceanGatewayManager/module.php');
$moduleCode = $arbiterCode . $managerCode;

$assertTrue(!str_contains($moduleCode, 'buildWriteIdBaseRequest'), 'Product modules do not build write frames');
$assertTrue(!str_contains($moduleCode, 'IPS_SetProperty'), 'Product modules never mutate foreign properties');
$assertTrue(!str_contains($moduleCode, 'IPS_ApplyChanges'), 'Product modules never apply foreign configuration');
$assertTrue(!str_contains($moduleCode, '/dev/'), 'Product modules contain no direct serial device path');
$assertTrue(str_contains($managerCode, 'READ_IDBASE_REQUEST_HEX'), 'Manager uses canonical read-only frame');
$assertTrue(str_contains($managerCode, 'ConfiguredBaseID'), 'Configured BaseID has independent storage');
$assertTrue(str_contains($managerCode, 'HardwareBaseID'), 'Hardware Base ID has independent storage');
$assertTrue(
    str_contains($arbiterCode, "RegisterPropertyBoolean('EnableMaintenance', false)"),
    'Arbiter maintenance defaults to disabled'
);
$assertTrue(
    str_contains($managerCode, "RegisterPropertyBoolean('EnableReadActions', false)"),
    'Manager read actions default to disabled'
);
$assertTrue(str_contains($arbiterCode, 'finally {'), 'Semaphore release is protected by finally');

$allowedTopLevelDirectories = ['docs', 'imgs', 'libs', 'modules', 'tests', 'ESP3TransportArbiter', 'EnOceanGatewayManager', 'EnOceanGatewayConfigurator'];
foreach (new DirectoryIterator($root) as $item) {
    if ($item->isDot() || !$item->isDir() || $item->getFilename() === '.git') {
        continue;
    }
    $assertTrue(
        in_array($item->getFilename(), $allowedTopLevelDirectories, true),
        'Top-level directory is supported by module structure: ' . $item->getFilename()
    );
}

echo 'PASS: ' . $passed . ' module-structure assertions' . PHP_EOL;
