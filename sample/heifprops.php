<?php

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/HEIF.php';
}

$options = getopt("f:i:hvtdR");

if ((isset($options['f']) === false) || (($options['f'] !== "-") && is_readable($options['f']) === false)) {
    fprintf(STDERR, "Usage: php heifprops.php -f <heif_file> [-htvd]\n");
    fprintf(STDERR, "ex) php heifprops.php -f test.heic -i <propId> \n");
    fprintf(STDERR, "ex) php heifprops.php -f test.heic -h \n");
    fprintf(STDERR, "ex) php heifprops.php -f test.heic -t \n");
    exit(1);
}

$filename = $options['f'];
if ($filename === "-") {
    $filename = "php://stdin";
}
$heifdata = file_get_contents($filename);

$opts = array();

$propIndex = isset($options['i'])?intval($options['i']):null;
$opts['hexdump'] = isset($options['h']);
$opts['typeonly'] = isset($options['t']);
$opts['verbose'] = isset($options['v']);
$opts['debug'] = isset($options['d']);
$opts['restrict'] = isset($options['r']);

$heif = new IO_HEIF();
try {
    $heif->parse($heifdata, $opts);
} catch (Exception $e) {
    echo "ERROR: heifprops: $filename:".PHP_EOL;
    echo $e->getMessage()." file:".$e->getFile()." line:".$e->getLine().PHP_EOL;
    echo $e->getTraceAsString().PHP_EOL;
    exit (1);
}

$propBoxes = $heif->getPropBoxesByPropIndex($propIndex);

foreach ($propBoxes as $index => $box) {
    if ($index == 0) {
        continue;
    }
    if ($opts['typeonly']) {
        $type = $box["type"];
        echo $type;
        echo "  index:". $index;
        switch ($type) {
        case "colr":
            echo "  ".$box["subtype"];
            break;
        case "auxC":
            echo "  ".$box["auxType"];
            break;
        }
        echo PHP_EOL;
    } else {
        $opts['indent'] = 0;
        $heif->dumpBox($box, $opts);
    }
}
