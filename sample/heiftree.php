<?php

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/HEIF.php';
}

$options = getopt("f:");

if (! isset($options['f'])) {
    fprintf(STDERR, "Usage: php heiftree.php -f <heif_file>\n");
    fprintf(STDERR, "ex) php heiftree.php -f input.heic\n");
    exit(1);
}


$filename = $options['f'];
if ($filename === "-") {
    $filename = "php://stdin";
}
$heifdata = file_get_contents($filename);
$opts = array();

$heif = new IO_HEIF();

try {
    $heif->parse($heifdata, $opts);
} catch (Exception $e) {
    echo "ERROR: heiftree: $filename:".PHP_EOL;
    echo $e->getMessage()." file:".$e->getFile()." line:".$e->getLine().PHP_EOL;
    echo $e->getTraceAsString().PHP_EOL;
    exit (1);
}

$heif->tree();
