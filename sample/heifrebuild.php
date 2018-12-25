<?php

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/HEIF.php';
}

$options = getopt("f:");

if ((isset($options['f']) === false) || (is_readable($options['f']) === false)) {
    fprintf(STDERR, "Usage: php heifrebuild.php -f <heif_file> [-h]\n");
    fprintf(STDERR, "ex) php heifrebuild.php -f test.heic\n");
    exit(1);
}

$filename = $options['f'];
$heifdata = file_get_contents($filename);

$heif = new IO_HEIF();
try {
    $heif->parse($heifdata);
} catch (Exception $e) {
    echo "ERROR: heifrebuild: $filename:".PHP_EOL;
    echo $e->getMessage()." file:".$e->getFile()." line:".$e->getLine().PHP_EOL;
    echo $e->getTraceAsString().PHP_EOL;
    exit (1);
}


echo $heif->build();
