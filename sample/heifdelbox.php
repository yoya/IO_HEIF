<?php

require_once('IO/HEIF.php');

$options = getopt("f:t:d");

if ((isset($options['f']) === false) || (is_readable($options['f']) === false) || (isset($options['t']) === false)) {
    fprintf(STDERR, "Usage: php heifdelbox.php -f <heif_file> -t <typelist> [-d]\n");
    fprintf(STDERR, "ex) php heifdelbox.php -f test.heic -t iinf,iref\n");
    exit(1);
}

$filename = $options['f'];
$heifdata = file_get_contents($filename);
$removeTypeList = explode(",", $options['t']);

$opts = array();
if (isset($options['d'])) {
    $opts['debug'] = true;
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

$heif->removeBoxByType($removeTypeList, $opts);
echo $heif->build($opts);
