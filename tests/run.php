<?php
/*
 * regression tests (no dependency)
 * usage: php tests/run.php
 *   IO_ISOBMFF, IO_HEVC, IO_Bit and IO_ICC are loaded from vendor/ (composer install) or include_path.
 */

$root = dirname(dirname(__FILE__));
if (is_readable($root.'/vendor/autoload.php')) {
    require_once $root.'/vendor/autoload.php';
}
set_include_path($root.PATH_SEPARATOR.get_include_path());
require_once 'IO/HEIF.php';

$failed = 0;
$passed = 0;

function check($name, $expect, $actual) {
    global $failed, $passed;
    if ($expect === $actual) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL: $name".PHP_EOL;
    echo "  expect: ".var_export($expect, true).PHP_EOL;
    echo "  actual: ".var_export($actual, true).PHP_EOL;
}

function box($type, $payload) {
    return pack('N', 8 + strlen($payload)).$type.$payload;
}

function fullbox($type, $version, $flags, $payload) {
    return box($type, chr($version).substr(pack('N', $flags), 1).$payload);
}

// iloc version 0, offset_size 4, length_size 4, base_offset_size 0
// items: [itemID, [[extentOffset, extentLength], ...]]
function ilocBox($items) {
    $p = "\x44\x00".pack('n', count($items));
    foreach ($items as $item) {
        $p .= pack('nnn', $item[0], 0, count($item[1]));
        foreach ($item[1] as $extent) {
            $p .= pack('NN', $extent[0], $extent[1]);
        }
    }
    return fullbox('iloc', 0, 0, $p);
}

// hvcC with NAL unit arrays: [[nalUnitType, [nalUnit, ...]], ...]
function hvcCBox($arrays) {
    $p = "\x01\x01".pack('N', 0x60000000).str_repeat("\0", 6)."\x5a"."\xf0\x00".
       "\xfc\xfd\xf8\xf8\x00\x00\x0f".chr(count($arrays));
    foreach ($arrays as $array) {
        $p .= chr(0x80 | $array[0]).pack('n', count($array[1]));
        foreach ($array[1] as $nalUnit) {
            $p .= pack('n', strlen($nalUnit)).$nalUnit;
        }
    }
    return box('hvcC', $p);
}

// ftyp, meta(pitm, iloc, iref, iprp), mdat. Every item has properties 1 (ispe) and 2 (hvcC).
// $ilocFunc(offset of the mdat payload) returns the iloc box.
function makeHEIF($itemIDs, $ilocFunc, $mdatPayload, $hvcC, $iref = '') {
    $ftyp = box('ftyp', 'heic'.pack('N', 0).'mif1heic');
    $ipco = box('ipco', fullbox('ispe', 0, 0, pack('NN', 1, 1)).$hvcC);
    $ipma = pack('N', count($itemIDs));
    foreach ($itemIDs as $itemID) {
        $ipma .= pack('n', $itemID)."\x02\x01\x82";
    }
    $iprp = box('iprp', $ipco.fullbox('ipma', 0, 0, $ipma));
    $meta = function($iloc) use ($itemIDs, $iref, $iprp) {
        return fullbox('meta', 0, 0, fullbox('pitm', 0, 0, pack('n', $itemIDs[0])).$iloc.$iref.$iprp);
    };
    $start = strlen($ftyp) + strlen($meta($ilocFunc(0))) + 8;
    return $ftyp.$meta($ilocFunc($start)).box('mdat', $mdatPayload);
}

// toHEVC() output, or the exception class and message
function toHEVC($data, $opts) {
    $heif = new IO_HEIF();
    $heif->parse($data);
    ob_start();
    try {
        $heif->toHEVC($opts);
    } catch (Throwable $e) {
        ob_end_clean();
        return get_class($e).": ".$e->getMessage();
    }
    return ob_get_clean();
}

$vpsSpsPps = [[32, ["\x40\x01VPS"]], [33, ["\x42\x01SPS"]], [34, ["\x44\x01PPS"]]];
$paramSets = "\0\0\0\1\x40\x01VPS"."\0\0\0\1\x42\x01SPS"."\0\0\0\1\x44\x01PPS";

/*
 * toHEVC RoleType thumbnail: the from_item_ID of thmb
 */
$iref = fullbox('iref', 0, 0, box('thmb', pack('nnn', 2, 1, 1)));
$data = makeHEIF([1, 2], function($s) {
    return ilocBox([[1, [[$s, 8]]], [2, [[$s + 8, 8]]]]);
}, pack('N', 4)."MAIN".pack('N', 4)."THMB", hvcCBox($vpsSpsPps), $iref);
check('toHEVC thumbnail', $paramSets."\0\0\0\1THMB", toHEVC($data, ['RoleType' => 'thumbnail']));

/*
 * toHEVC: each NAL unit of an hvcC array gets its own start code
 */
$arrays = array_merge($vpsSpsPps, [[39, ["\x4e\x01SEI-A", "\x4e\x01SEI-B"]]]);
$data = makeHEIF([1], function($s) {
    return ilocBox([[1, [[$s, 8]]]]);
}, pack('N', 4)."\x26\x01ID", hvcCBox($arrays));
check('toHEVC several NAL units in an array',
      $paramSets."\0\0\0\1\x4e\x01SEI-A"."\0\0\0\1\x4e\x01SEI-B"."\0\0\0\1\x26\x01ID",
      toHEVC($data, ['ItemID' => 1]));

echo "passed: $passed, failed: $failed".PHP_EOL;
exit($failed ? 1 : 0);
