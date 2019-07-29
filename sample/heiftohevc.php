<?php

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/HEIF.php';
}

$options = getopt("f:i:r:u:");

if ((isset($options['f']) === false) || (is_readable($options['f']) === false)) {
    fprintf(STDERR, "Usage: php heiftohevc.php -f <heif_file> [-i <item_id]>\n");
    fprintf(STDERR, "ex) php heiftohevc.php -f test.heic\n");
    fprintf(STDERR, "ex) php heiftohevc.php -f test.heic -i 51\n");
    fprintf(STDERR, "ex) php heiftohevc.php -f test.heic -r master\n");
    exit(1);
}

$filename = $options['f'];
$opts = [];
if (isset($options['i'])) {
    $opts['ItemID'] = intval($options['i']);
}
if (isset($options['r'])) {
    $opts['RoleType'] = $options['r'];
}
if (isset($options['u'])) {
    $opts['urn'] = $options['u'];
}
$heifdata = file_get_contents($filename);

$heif = new IO_HEIF();

try {
    $heif->parse($heifdata);
    if (isset($opts['ItemID']) || isset($opts['RoleType'])) {
        echo $heif->toHEVC($opts);
    } else {
        $heif->analyze();
        foreach ($heif->itemTree as $itemID => $item) {
            echo "ItemID:$itemID";
            if (isset($item["infe"])) {
                echo " infe:".$item["infe"]["type"];
            }
            foreach (["pitm", "dimg", "thmb", "cdsc", "auxl"] as $type) {
                if (isset($item[$type])) {
                    echo " ".$type;
                    if (isset($item[$type]["from"])) {
                        echo ":".$item[$type]["from"];
                    }
                }
            }
            if (isset($item["auxl"])) {
                $propBoxes = $heif->getPropBoxesByItemID($itemID);
                foreach ($propBoxes as $propBox) {
                    if ($propBox["type"] === "auxC") {
                        echo "  ".$propBox["auxType"];
                    }
                }
            }
            //var_dump($item);
            echo PHP_EOL;
        }
    }
} catch (Exception $e) {
    echo "ERROR: heiftohevc: $filename:".PHP_EOL;
    echo $e->getMessage()." file:".$e->getFile()." line:".$e->getLine().PHP_EOL;
    echo $e->getTraceAsString().PHP_EOL;
    exit (1);
}

exit (0);


