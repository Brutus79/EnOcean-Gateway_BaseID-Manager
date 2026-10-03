<?php

declare(strict_types=1);

$tests = [
    __DIR__ . '/esp3_codec_vectors.php',
    __DIR__ . '/esp3_stream_and_arbiter.php',
    __DIR__ . '/module_structure.php',
    __DIR__ . '/gateway_features.php',
    __DIR__ . '/baseid_write_workflow.php',
    __DIR__ . '/gateway_write_preparation.php',
    __DIR__ . '/transactional_write.php',
    __DIR__ . '/alignment_recovery.php',
    __DIR__ . '/transaction_state_model.php',
    __DIR__ . '/product_b8.php',
    __DIR__ . '/installation_b81.php',
    __DIR__ . '/master_target_binding_b82.php',
    __DIR__ . '/module_lifecycle.php',
    __DIR__ . '/master_target_module_b82.php',
    __DIR__ . '/uat_product.php',
];

foreach ($tests as $test) {
    (static function (string $path): void {
        require $path;
    })($test);
}

echo 'PASS: all offline test files completed' . PHP_EOL;
