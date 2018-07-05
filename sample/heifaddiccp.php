<?php

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/HEIF.php';
}

$options = getopt("f:p:d");

if ((isset($options['f']) === false) || (is_readable($options['f']) === false) || (isset($options['p']) === false) || (is_readable($options['p']) === false) ) {
    fprintf(STDERR, "Usage: php heifaddicpp.php -f <heif_file> -p <iccp_profile> [-d]\n");
    fprintf(STDERR, "ex) php heifaddiccp.php -f test.heic -t sRGB.icc\n");
    exit(1);
}

$filename = $options['f'];
$heifdata = file_get_contents($filename);
$iccfilename = $options['p'];
$iccpdata = file_get_contents($iccfilename);

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

$heif->appendICCProfile($iccpdata);

echo $heif->build($opts);
