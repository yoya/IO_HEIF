<?php

require_once('IO/HEIF.php');

$options = getopt("f:hv");

if ((isset($options['f']) === false) || (is_readable($options['f']) === false)) {
    fprintf(STDERR, "Usage: php heifdump.php -f <heif_file> [-h]\n");
    fprintf(STDERR, "ex) php heifdump.php -f test.heic -h \n");
    exit(1);
}

$heifdata = file_get_contents($options['f']);

$heif = new IO_HEIF();
$heif->parse($heifdata);

$opts = array();
if (isset($opts['h'])) {
    $opts['hexdump'] = true;
}
if (isset($opts['v'])) {
    $opts['verbose'] = true;
}
$heif->dump($opts);
