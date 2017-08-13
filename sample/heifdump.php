<?php

require_once('IO/HEIF.php');

$options = getopt("f:hvt");

if ((isset($options['f']) === false) || (is_readable($options['f']) === false)) {
    fprintf(STDERR, "Usage: php heifdump.php -f <heif_file> [-h]\n");
    fprintf(STDERR, "ex) php heifdump.php -f test.heic -h \n");
    fprintf(STDERR, "ex) php heifdump.php -f test.heic -t \n");
    exit(1);
}

$filename = $options['f'];
$heifdata = file_get_contents($filename);

$heif = new IO_HEIF();
try {
    $heif->parse($heifdata);
} catch (Exception $e) {
    echo "ERROR: heifdump: $filename:".PHP_EOL;
    echo $e->getMessage()." file:".$e->getFile()." line:".$e->getLine().PHP_EOL;
    echo $e->getTraceAsString().PHP_EOL;
    exit (1);
}



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

$heif->dump($opts);
