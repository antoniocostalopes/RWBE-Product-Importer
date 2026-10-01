<?php
/**
 * Syntax-check every PHP file in the plugin.
 *
 * Kept as a file rather than an inline `php -r` in composer.json: the shell expands
 * $variables inside the script string before PHP ever sees it, which silently broke
 * the inline version.
 *
 * Usage: composer run lint   (or: php bin/lint.php)
 *
 * @package RWBE_Product_Importer
 */

// Command line only; this never needs to answer an HTTP request.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

$root = dirname(__DIR__);

$skip = array(
    $root . DIRECTORY_SEPARATOR . 'vendor',
    $root . DIRECTORY_SEPARATOR . 'node_modules',
);

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file) use ($skip) {
            foreach ($skip as $dir) {
                if (strpos($file->getPathname(), $dir) === 0) {
                    return false;
                }
            }
            return true;
        }
    )
);

$checked = 0;
$failed  = array();

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $output = array();
    $code   = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $code);

    ++$checked;
    if ($code !== 0) {
        $failed[] = implode(PHP_EOL, $output);
    }
}

if ($failed !== array()) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    fwrite(STDERR, sprintf('%d of %d files have syntax errors.%s', count($failed), $checked, PHP_EOL));
    exit(1);
}

printf('No syntax errors in %d files.%s', $checked, PHP_EOL);
exit(0);
