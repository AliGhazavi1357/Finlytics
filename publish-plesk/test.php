<?php
header('Content-Type: text/plain; charset=utf-8');
echo "PHP OK\n";
echo "version=" . PHP_VERSION . "\n";
echo "pdo_sqlite=" . (extension_loaded('pdo_sqlite') ? 'yes' : 'NO') . "\n";
echo "api=" . (is_file(__DIR__ . '/api/index.php') ? 'yes' : 'NO') . "\n";

$dirs = [
    __DIR__ . '/data',
    __DIR__ . '/api/data',
    __DIR__ . '/tmp_data',
];
echo "\n--- write test ---\n";
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $ok = false;
    if (is_dir($dir)) {
        $probe = $dir . '/.write_test';
        $ok = @file_put_contents($probe, 'ok') !== false;
        if ($ok) {
            @unlink($probe);
        }
    }
    echo $dir . ' => ' . ($ok ? 'WRITABLE' : 'NOT writable') . "\n";
}

echo "\nNext: /api/index.php?path=version\n";
echo "If all NOT writable: File Manager → data → Change Permissions → enable Write/Modify for web user.\n";
