<?php

require_once('IO/HEIF.php');

$options = getopt("f:");

if ((isset($options['f']) === false) || (is_readable($options['f']) === false)) {
    fprintf(STDERR, "Usage: php heiffromhevc.php -f <hevc_file> [-h]\n");
    fprintf(STDERR, "ex) php heiffromhevc.php -f test.hevc\n");
    exit(1);
}

$filename = $options['f'];
$hevcdata = file_get_contents($filename);

$heif = new IO_HEIF();
try {
    $heif->fromHEVC($hevcdata);
} catch (Exception $e) {
    echo "ERROR: heifdump: $filename:".PHP_EOL;
    echo $e->getMessage()." file:".$e->getFile()." line:".$e->getLine().PHP_EOL;
    echo $e->getTraceAsString().PHP_EOL;
    exit (1);
}

echo $heif->build();
