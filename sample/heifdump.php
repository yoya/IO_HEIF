<?php

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/HEIF.php';
}

$options = getopt("f:hvtdR");

if ((isset($options['f']) === false) || (($options['f'] !== "-") && is_readable($options['f']) === false)) {
    fprintf(STDERR, "Usage: php heifdump.php -f <heif_file> [-htvd]\n");
    fprintf(STDERR, "ex) php heifdump.php -f test.heic -h \n");
    fprintf(STDERR, "ex) php heifdump.php -f test.heic -t \n");
    exit(1);
}

$filename = $options['f'];
if ($filename === "-") {
    $filename = "php://stdin";
}
$heifdata = file_get_contents($filename);

$opts = array();

if (isset($options['h'])) {
    $opts['hexdump'] = true;
}
if (isset($options['t'])) {
    $opts['typeonly'] = true;
}
if (isset($options['v'])) {
    $opts['verbose'] = true;
}
if (isset($options['d'])) {
    $opts['debug'] = true;
}
if (isset($options['r'])) {
    $opts['restrict'] = true;
}

$heif = new IO_HEIF();
try {
    $heif->parse($heifdata, $opts);
} catch (Exception $e) {
    echo "ERROR: heifdump: $filename:".PHP_EOL;
    echo $e->getMessage()." file:".$e->getFile()." line:".$e->getLine().PHP_EOL;
    echo $e->getTraceAsString().PHP_EOL;
    exit (1);
}

$heif->dump($opts);
