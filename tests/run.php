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

/*
 * toHEVC: item stored in several extents
 */
$data = makeHEIF([1], function($s) {
    return ilocBox([[1, [[$s, 5], [$s + 5, 5]]]]);
}, pack('N', 6)."\x26\x01IDR!"."TRAILING", hvcCBox($vpsSpsPps));
check('toHEVC several extents', $paramSets."\0\0\0\1\x26\x01IDR!", toHEVC($data, ['ItemID' => 1]));

/*
 * IO_HEIF_HEVC::input: no dynamic property deprecation (PHP 8.2+) from IO_HEIF itself
 */
$messages = [];
set_error_handler(function($errno, $errstr) use (&$messages) {
    if (strpos($errstr, 'IO_HEIF_HEVC') !== false) {
        $messages []= $errstr;
    }
    return true;
});
$hevc = new IO_HEIF_HEVC();
$hevc->input("\0\0\0\1\x4c\x01\xff\x80");  // filler data NAL unit
restore_error_handler();
check('IO_HEIF_HEVC no deprecation', [], $messages);
check('IO_HEIF_HEVC hevc', 'IO_HEVC', get_class($hevc->hevc));

/*
 * toHEVC: no item found (auxl with an unknown urn)
 */
$data = makeHEIF([1], function($s) {
    return ilocBox([[1, [[$s, 4]]]]);
}, "AAAA", hvcCBox($vpsSpsPps));
$messages = [];
set_error_handler(function($errno, $errstr) use (&$messages) {
    $messages []= $errstr;
    return true;
});
$result = toHEVC($data, ['RoleType' => 'auxl', 'urn' => 'urn:none']);
restore_error_handler();
check('toHEVC item not found', "Exception: toHEVC: can't found itemID", $result);
check('toHEVC item not found no warning', [], $messages);

/*
 * sample tools: -r (restrict) is accepted
 */
function runSample($script, $args, $data) {
    global $root;
    $cmd = escapeshellarg(PHP_BINARY).' -d include_path='.escapeshellarg(get_include_path()).
         ' '.escapeshellarg("$root/sample/$script").' -f - '.$args;
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    fwrite($pipes[0], $data);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($proc);
}
// hvcC whose first reserved nibble is 0 instead of 0xF
$hvcC = box('hvcC', "\x01\x01".pack('N', 0x60000000).str_repeat("\0", 6)."\x5a"."\x00\x00".
            "\xfc\xfd\xf8\xf8\x00\x00\x0f\x00");
$data = box('ftyp', 'heic'.pack('N', 0).'mif1heic').fullbox('meta', 0, 0, box('iprp', box('ipco', $hvcC)));
foreach (['heifdump.php', 'heifitems.php', 'heifprops.php'] as $script) {
    check("$script without -r", 0, runSample($script, '', $data));
    check("$script -r", 1, runSample($script, '-r', $data));
}

function parseHEIF($data, $opts = array()) {
    $heif = new IO_HEIF();
    $heif->parse($data, $opts);
    return $heif;
}

// build() result, or the exception class and message
function rebuild($data) {
    try {
        return parseHEIF($data)->build();
    } catch (Throwable $e) {
        return get_class($e).": ".$e->getMessage();
    }
}

// [return value of $func, messages of the warnings raised in it]
function withWarnings($func) {
    $warnings = [];
    set_error_handler(function($errno, $errstr) use (&$warnings) {
        $warnings []= $errstr;
        return true;
    });
    try {
        $ret = $func();
    } finally {
        restore_error_handler();
    }
    return [$ret, $warnings];
}

function firstBox($heif, $type) {
    $boxes = $heif->getBoxesByTypes([$type]);
    return count($boxes) ? $boxes[0] : null;
}

/*
 * test data in tests/data (made by tests/data/make.php)
 */
$dataDir = "$root/tests/data";

// run php with $args in the repo root (include_path inherited, every error shown on stderr)
// [exit code (null on timeout), stdout, stderr]
function runPHP($args, $stdin = '', $timeout = 30) {
    global $root;
    $cmd = array_merge([PHP_BINARY, '-d', 'include_path='.get_include_path(),
                        '-d', 'error_reporting='.E_ALL, '-d', 'display_errors=stderr'], $args);
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = $err = '';
    $code = null;
    $deadline = microtime(true) + $timeout;
    while (true) {
        $read = [$pipes[1], $pipes[2]];
        $write = $except = null;
        stream_select($read, $write, $except, 0, 100000);
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (! $status['running']) {
            $code = $status['exitcode'];
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc, 9);
            break;
        }
    }
    $out .= stream_get_contents($pipes[1]);
    $err .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return [$code, $out, $err];
}

// PHP errors (warning, notice, deprecated, fatal) in the output of runPHP
function phpErrors($text) {
    preg_match_all('/(?:Warning|Notice|Deprecated|Fatal error|Parse error): .* on line \d+/', $text, $m);
    return $m[0];
}

// $func() output, and the messages of the PHP errors raised in it
function outputOf($func) {
    return withWarnings(function() use ($func) {
        ob_start();
        try {
            $func();
        } finally {
            $out = ob_get_clean();
        }
        return $out;
    });
}

// exception class and message of $func(), or 'no exception'
function exceptionOf($func) {
    ob_start(); // parse in restrict mode var_dumps the box
    try {
        $func();
        $result = 'no exception';
    } catch (Exception $e) {
        $result = get_class($e).': '.$e->getMessage();
    }
    ob_end_clean();
    return $result;
}

function boxTypes($boxList) {
    return array_column($boxList, 'type');
}

// item data of every item: [itemID => bytes] (base_offset + extents, construction_method 0 and 1)
function itemData($data) {
    $isobmff = parseHEIF($data);
    $idat = firstBox($isobmff, 'idat');
    $items = [];
    foreach (firstBox($isobmff, 'iloc')['itemArray'] as $item) {
        $method = isset($item['constructionMethod']) ? $item['constructionMethod'] : 0;
        $base = ($method === 1) ? $idat['_offset'] + 8 : 0;
        $bytes = '';
        foreach ($item['extentArray'] as $extent) {
            $bytes .= substr($data, $base + $item['baseOffset'] + $extent['extentOffset'], $extent['extentLength']);
        }
        $items[$item['itemID']] = $bytes;
    }
    return $items;
}

// [itemID => [baseOffset, [[extentOffset, extentLength], ...]]]
function ilocItems($iloc) {
    $items = [];
    foreach ($iloc['itemArray'] as $item) {
        $extents = [];
        foreach ($item['extentArray'] as $extent) {
            $extents []= [$extent['extentOffset'], $extent['extentLength']];
        }
        $items[$item['itemID']] = [$item['baseOffset'], $extents];
    }
    return $items;
}

// [itemID => [[essential, propertyIndex], ...]]
function ipmaEntries($ipma) {
    $entries = [];
    foreach ($ipma['entryArray'] as $entry) {
        $entries[$entry['itemID']] = [];
        foreach ($entry['associationArray'] as $assoc) {
            $entries[$entry['itemID']] []= [$assoc['essential'], $assoc['propertyIndex']];
        }
    }
    return $entries;
}

// [type, fromItemID, [itemID, ...]] of the iref children
function irefRefs($iref) {
    $refs = [];
    foreach ($iref['boxList'] as $box) {
        $refs []= [$box['type'], $box['fromItemID'], array_column($box['itemArray'], 'itemID')];
    }
    return $refs;
}

// build with $box inserted at $index of the top-level boxes
function insertAndBuild($data, $index, $box) {
    $isobmff = parseHEIF($data);
    array_splice($isobmff->boxTree, $index, 0, [$box]);
    return $isobmff->build();
}
$pasp = ["type" => "pasp", "hspace" => 1, "vspace" => 1]; // 16 bytes

// fromHEVC() and build()
function fromHEVC($hevcData) {
    $heif = new IO_HEIF();
    $heif->fromHEVC($hevcData);
    return $heif->build();
}

// [[NALUnitType, [nalUnit, ...]], ...] of an hvcC box
function nalArrays($hvcC) {
    $arrays = [];
    foreach ($hvcC['nalArrays'] as $nal) {
        $arrays []= [$nal['NALUnitType'], array_column($nal['nalus'], 'nalUnit')];
    }
    return $arrays;
}

$dataNames = [];
foreach (glob("$dataDir/*") as $path) {
    if (basename($path) !== 'make.php') {
        $dataNames []= basename($path);
    }
}
check('data files', ['idr_n_lp.hevc', 'multi.heic', 'single.heic', 'tiny.hevc', 'tiny.icc', 'truncated.heic'], $dataNames);

/*
 * make.php reproduces the test data
 */
$tmpDir = sys_get_temp_dir().'/io_heif_make_'.getmypid();
mkdir($tmpDir);
list($code, $out, $err) = runPHP(["$dataDir/make.php", $tmpDir]);
check('make.php exit', [0, []], [$code, phpErrors($err)]);
foreach ($dataNames as $name) {
    $made = is_file("$tmpDir/$name") ? file_get_contents("$tmpDir/$name") : null;
    check("make.php $name", file_get_contents("$dataDir/$name"), $made);
    if (is_file("$tmpDir/$name")) {
        unlink("$tmpDir/$name");
    }
}
rmdir($tmpDir);

// NAL units of tiny.hevc (4-byte start codes): VPS, SPS, PPS, IDR_W_RADL
$tiny = file_get_contents("$dataDir/tiny.hevc");
$tinyNALs = explode("\0\0\0\1", substr($tiny, 4));
list($vps, $sps, $pps, $idr) = $tinyNALs;
check('tiny.hevc NAL types', [32, 33, 34, 19], array_map(function($nal) {
    return ord($nal[0]) >> 1;
}, $tinyNALs));

/*
 * IO_HEIF_HEVC: values from the SPS of tiny.hevc
 */
list($hevc, $warnings) = withWarnings(function() use ($tiny) {
    $hevc = new IO_HEIF_HEVC();
    $hevc->input($tiny);
    return $hevc;
});
check('IO_HEIF_HEVC no warning', [], $warnings);
check('IO_HEIF_HEVC ispe', ['type' => 'ispe', 'version' => 0, 'flags' => 0, 'width' => 64, 'height' => 48], $hevc->getISPE());
check('IO_HEIF_HEVC mdat data', pack('N', strlen($idr)).$idr, $hevc->getMDATdata());
$config = $hevc->getHEVCConfig();
check('IO_HEIF_HEVC hvcC', [1, 0, 0, 1, 0x60000000, 0x900000000000, 60, 1, 0, 0, 3],
      [$config['version'], $config['profileSpace'], $config['tierFlag'], $config['profileIdc'],
       $config['profileCompatibilityFlags'], $config['constraintIndicatorFlags'], $config['levelIdc'],
       $config['chromaFormat'], $config['bitDepthLumaMinus8'], $config['bitDepthChromaMinus8'], $config['lengthSizeMinusOne']]);
check('IO_HEIF_HEVC hvcC NAL units', [[32, [$vps]], [33, [$sps]], [34, [$pps]]],
      array_map(function($nal) {
          return [$nal['NALUnitType'], array_column($nal['nalus'], 'nalUnit')];
      }, $config['nalArrays']));

/*
 * fromHEVC: tiny.hevc to HEIC
 */
list($out, $warnings) = withWarnings(function() use ($tiny) {
    return fromHEVC($tiny);
});
check('fromHEVC no warning', [], $warnings);
$heif = parseHEIF($out);
check('fromHEVC boxes', [['ftyp', 'mdat', 'meta'], ['hdlr', 'pitm', 'iloc', 'iinf', 'iprp'], ['ispe', 'pasp', 'hvcC']],
      [boxTypes($heif->boxTree), boxTypes(firstBox($heif, 'meta')['boxList']), boxTypes(firstBox($heif, 'ipco')['boxList'])]);
$ftyp = firstBox($heif, 'ftyp');
check('fromHEVC ftyp', ['heic', 0, ['mif1', 'heic']], [$ftyp['major'], $ftyp['minor'], $ftyp['alt']]);
check('fromHEVC hdlr pict', 'pict', firstBox($heif, 'hdlr')['componentSubType']);
check('fromHEVC pitm', 1, firstBox($heif, 'pitm')['itemID']);
check('fromHEVC infe', [1, 'hvc1'], [firstBox($heif, 'infe')['itemID'], firstBox($heif, 'infe')['itemType']]);
check('fromHEVC item data', [1 => pack('N', strlen($idr)).$idr], itemData($out));
check('fromHEVC ispe', [64, 48], [firstBox($heif, 'ispe')['width'], firstBox($heif, 'ispe')['height']]);
$hvcC = firstBox($heif, 'hvcC');
check('fromHEVC hvcC', [1, 0x60000000, 0x900000000000, 60, 1, 0, 0, 3],
      [$hvcC['profileIdc'], $hvcC['profileCompatibilityFlags'], $hvcC['constraintIndicatorFlags'], $hvcC['levelIdc'],
       $hvcC['chromaFormat'], $hvcC['bitDepthLumaMinus8'], $hvcC['bitDepthChromaMinus8'], $hvcC['lengthSizeMinusOne']]);
check('fromHEVC hvcC NAL units', [[32, [$vps]], [33, [$sps]], [34, [$pps]]], nalArrays($hvcC));
$props = [];
foreach ($heif->getPropBoxesByItemID(1) as $i => $box) {
    $props []= $box['type'];
}
check('fromHEVC item properties', ['ispe', 'pasp', 'hvcC'], $props);
check('fromHEVC hvcC essential', 1, ipmaEntries(firstBox($heif, 'ipma'))[1][2][0]);
check('fromHEVC rebuild', bin2hex($out), bin2hex(rebuild($out)));
check('fromHEVC toHEVC', bin2hex($tiny), bin2hex(toHEVC($out, ['RoleType' => 'primary'])));
// IDR_N_LP, 3-byte start codes, SEI (not taken)
$data = file_get_contents("$dataDir/idr_n_lp.hevc");
$out = fromHEVC($data);
check('fromHEVC IDR_N_LP item data', [1 => pack('N', 10)."\x28\x01IDR-N-LP"], itemData($out));
check('fromHEVC IDR_N_LP toHEVC', bin2hex("\0\0\0\1$vps\0\0\0\1$sps\0\0\0\1$pps\0\0\0\1\x28\x01IDR-N-LP"),
      bin2hex(toHEVC($out, ['ItemID' => 1])));
// no IDR picture
$data = "\0\0\0\1$vps\0\0\0\1$sps\0\0\0\1$pps";
check('fromHEVC no IDR', 'Exception: getMDATdata: IDR_W_RADL and IDR_N_LP are missing', exceptionOf(function() use ($data) {
    fromHEVC($data);
}));

/*
 * single.heic: one hvc1 item with the parameter sets of tiny.hevc
 */
$data = file_get_contents("$dataDir/single.heic");
$p = strpos($data, pack('N', strlen($idr)).$idr);
list($heif, $warnings) = withWarnings(function() use ($data) {
    return parseHEIF($data);
});
check('single parse no warning', [], $warnings);
check('single class', 'IO_HEIF', get_class($heif));
check('single boxes', [['ftyp', 'meta', 'mdat'], ['hdlr', 'pitm', 'iloc', 'iinf', 'iprp'], ['hvcC', 'ispe']],
      [boxTypes($heif->boxTree), boxTypes(firstBox($heif, 'meta')['boxList']), boxTypes(firstBox($heif, 'ipco')['boxList'])]);
check('single iloc', [1 => [0, [[$p, 4 + strlen($idr)]]]], ilocItems(firstBox($heif, 'iloc')));
check('single hvcC NAL units', [[32, [$vps]], [33, [$sps]], [34, [$pps]]], nalArrays(firstBox($heif, 'hvcC')));
check('single ipma', [1 => [[1, 1], [0, 2]]], ipmaEntries(firstBox($heif, 'ipma')));
$info = $heif->getHEVCBuildInfo($heif->boxTree);
check('single build info', [
    'iloc' => [1 => ['extents' => [[$p, 4 + strlen($idr)]]]],
    'ipco' => [1 => ['type' => 'hvcC', 'nals' => [$vps, $sps, $pps], 'profile' => 1, 'chrome' => 1],
               2 => ['type' => 'ispe', 'width' => 64, 'height' => 48]],
    'ipma' => [1 => [1, 2]],
], array_intersect_key($info, array_flip(['ipco', 'iloc', 'ipma'])));
check('single build info hvcC auxC', [[1], []], [array_keys($info['hvcC']), $info['auxC']]);
foreach (['primary', 'master', 'pitm'] as $role) {
    check("single toHEVC $role", bin2hex($tiny), bin2hex(toHEVC($data, ['RoleType' => $role])));
}
check('single toHEVC ItemID', bin2hex($tiny), bin2hex(toHEVC($data, ['ItemID' => 1])));
check('single toHEVC no thumbnail', 'Exception: toHEVC: count(thmbArr) must be 1', toHEVC($data, ['RoleType' => 'thumbnail']));
check('single toHEVC no role', "Exception: toHEVC: can't found itemID", toHEVC($data, []));
list($out, $warnings) = outputOf(function() use ($data) {
    parseHEIF($data)->dump();
});
check('single dump', [[], true, true], [$warnings, strpos($out, "width:64 height:48\n") !== false,
                                       strpos($out, "constraintIndicatorFlags:0x900000000000 levelIdc:60\n") !== false]);
list($out, $warnings) = outputOf(function() use ($data) {
    parseHEIF($data)->tree();
});
check('single tree', [[], true, true], [$warnings, strpos($out, "[1]: pitm type:hvc1 ref:0 offset:$p length:".(4 + strlen($idr))."\n") !== false,
                                       strpos($out, "    [1]hvcC:1,60,1 [2?]ispe:64,48 \n") !== false]);
check('single rebuild', bin2hex($data), bin2hex(rebuild($data)));
check('single moved mdat toHEVC', bin2hex($tiny), bin2hex(toHEVC(insertAndBuild($data, 2, $pasp), ['ItemID' => 1])));
$heif = parseHEIF($data);
$heif->appendICCProfile(file_get_contents("$dataDir/tiny.icc"));
$out = $heif->build();
check('single add icc', [['hvcC', 'ispe', 'colr'], [1 => [[1, 1], [0, 2], [1, 3]]], bin2hex($tiny)],
      [boxTypes(firstBox(parseHEIF($out), 'ipco')['boxList']), ipmaEntries(firstBox(parseHEIF($out), 'ipma')),
       bin2hex(toHEVC($out, ['ItemID' => 1]))]);

/*
 * multi.heic: grid, tiles, thumbnail, alpha / depth auxiliary images, Exif
 */
$data = file_get_contents("$dataDir/multi.heic");
$paramSets = "\0\0\0\1$vps\0\0\0\1$sps\0\0\0\1$pps";
$thumbSets = "\0\0\0\1\x40\x01T-VPS\0\0\0\1\x42\x01T-SPS\0\0\0\1\x44\x01T-PPS";
list($heif, $warnings) = withWarnings(function() use ($data) {
    return parseHEIF($data);
});
check('multi parse no warning', [], $warnings);
check('multi meta boxes', ['hdlr', 'pitm', 'iloc', 'iinf', 'iref', 'iprp', 'idat'], boxTypes(firstBox($heif, 'meta')['boxList']));
check('multi infe', ['grid', 'hvc1', 'hvc1', 'hvc1', 'hvc1', 'hvc1', 'hvc1', 'hvc1', 'Exif'],
      array_column(firstBox($heif, 'iinf')['boxList'], 'itemType'));
check('multi iref', [['dimg', 1, [2, 3, 4, 5]], ['thmb', 6, [1]], ['auxl', 7, [1]], ['auxl', 8, [1]], ['cdsc', 9, [1]]],
      irefRefs(firstBox($heif, 'iref')));
check('multi ipco', ['hvcC', 'hvcC', 'ispe', 'ispe', 'ispe', 'auxC', 'auxC', 'colr'], boxTypes(firstBox($heif, 'ipco')['boxList']));
check('multi auxC', ['urn:mpeg:hevc:2015:auxid:1', 'urn:mpeg:hevc:2015:auxid:2'], array_column($heif->getBoxesByTypes(['auxC']), 'auxType'));
check('multi thumbnail hvcC', [30, [[32, ["\x40\x01T-VPS"]], [33, ["\x42\x01T-SPS"]], [34, ["\x44\x01T-PPS"]]]],
      [$heif->getBoxesByTypes(['hvcC'])[1]['levelIdc'], nalArrays($heif->getBoxesByTypes(['hvcC'])[1])]);
$iloc = ilocItems(firstBox($heif, 'iloc'));
check('multi thumbnail extents', 2, count($iloc[6][1]));
$items = itemData($data);
check('multi item data', ["\x00\x00\x01\x01".pack('nn', 64, 48), pack('N', 8)."\x26\x01TILE-3", 'EXIF'], [$items[1], $items[4], $items[9]]);
$info = $heif->getHEVCBuildInfo($heif->boxTree);
check('multi build info', [[2, 3, 4, 5, 6, 7, 8], [7, 8], 'urn:mpeg:hevc:2015:auxid:2', [6 => [2, 5]]],
      [array_keys($info['hvcC']), array_keys($info['auxC']), $info['auxC'][8]['auxType'], array_intersect_key($info['ipma'], [6 => 1])]);
check('multi toHEVC thumbnail', bin2hex($thumbSets."\0\0\0\1\x26\x01THUMB-A\0\0\0\1\x02\x01THUMB-B"),
      bin2hex(toHEVC($data, ['RoleType' => 'thumbnail'])));
check('multi toHEVC thmb', bin2hex(toHEVC($data, ['RoleType' => 'thumbnail'])), bin2hex(toHEVC($data, ['RoleType' => 'thmb'])));
foreach ([2, 3, 4, 5] as $i => $itemID) {
    check("multi toHEVC tile $itemID", bin2hex($paramSets."\0\0\0\1\x26\x01TILE-".($i + 1)), bin2hex(toHEVC($data, ['ItemID' => $itemID])));
}
check('multi toHEVC alpha', bin2hex($paramSets."\0\0\0\1\x26\x01ALPHA"),
      bin2hex(toHEVC($data, ['RoleType' => 'auxl', 'urn' => 'urn:mpeg:hevc:2015:auxid:1'])));
check('multi toHEVC depth', bin2hex($paramSets."\0\0\0\1\x26\x01DEPTH"),
      bin2hex(toHEVC($data, ['RoleType' => 'auxiliary', 'urn' => 'urn:mpeg:hevc:2015:auxid:2'])));
check('multi toHEVC aux without urn', 'Exception: toHEVC: auxiliary need to specify urn', toHEVC($data, ['RoleType' => 'aux']));
check('multi toHEVC unknown role', 'Exception: toHEVC: unknown role type:other', toHEVC($data, ['RoleType' => 'other']));
$heif->analyze();
check('multi itemTree', [[], ['from' => 1], ['from' => 1], ['from' => 1], ['from' => 1]],
      [$heif->itemTree[1]['pitm'], $heif->itemTree[2]['dimg'], $heif->itemTree[6]['thmb'], $heif->itemTree[8]['auxl'], $heif->itemTree[9]['cdsc']]);
list($out, $warnings) = outputOf(function() use ($data) {
    parseHEIF($data)->dump();
});
check('multi dump', [[], true, true], [$warnings, strpos($out, "auxType:urn:mpeg:hevc:2015:auxid:2\n") !== false,
                                      strpos($out, "nalUnitLength:7 nalUnit: 42 01 54 2d 53 50 53\n") !== false]);
list($out, $warnings) = outputOf(function() use ($data) {
    parseHEIF($data)->tree();
});
check('multi tree no warning', [], $warnings);
foreach (["    (idat) version:0 flags:0 rows-1:1 cols-1:1 width:64 height:48", "[6]: thmb:1 type:hvc1 method:0",
          "    [2]hvcC:1,30,1 [5?]ispe:16,12 \n", "[8]: auxl:1 type:hvc1", "    [1]hvcC:1,60,1 [4?]ispe:32,24 [7]auxC \n",
          "[7]: auxC auxtype:urn:mpeg:hevc:2015:auxid:2\n"] as $line) {
    check("multi tree $line", true, strpos($out, $line) !== false);
}
check('multi rebuild', bin2hex($data), bin2hex(rebuild($data)));
$out = insertAndBuild($data, 2, $pasp);
check('multi moved mdat', [itemData($data), bin2hex(toHEVC($data, ['RoleType' => 'thumbnail']))],
      [itemData($out), bin2hex(toHEVC($out, ['RoleType' => 'thumbnail']))]);
$heif = parseHEIF($data);
$heif->removeBoxByType(['thmb']);
$out = $heif->build();
check('multi remove thmb', [['dimg', 'auxl', 'auxl', 'cdsc'], 'Exception: toHEVC: count(thmbArr) must be 1'],
      [boxTypes(firstBox(parseHEIF($out), 'iref')['boxList']), toHEVC($out, ['RoleType' => 'thumbnail'])]);

/*
 * broken input
 */
$data = file_get_contents("$dataDir/truncated.heic");
check('truncated parse', 'Exception: parseBox: hasNext(', substr(exceptionOf(function() use ($data) {
    parseHEIF($data);
}), 0, 29));
// truncated at every length, in a child process with a timeout
$code = <<<'CODE'
require_once 'IO/HEIF.php';
$results = [];
foreach (array_slice($argv, 1) as $path) {
    $data = file_get_contents($path);
    for ($n = 0; $n < strlen($data); $n++) {
        $heif = new IO_HEIF();
        try {
            $heif->parse(substr($data, 0, $n));
            $results[basename($path)][$n] = count($heif->boxTree);
        } catch (Exception $e) {
            $results[basename($path)][$n] = 'exception';
        }
    }
}
echo json_encode($results);
CODE;
$names = ['single.heic', 'multi.heic'];
list($status, $out, $err) = runPHP(array_merge(['-r', $code], array_map(function($name) use ($dataDir) {
    return "$dataDir/$name";
}, $names)), '', 120);
check('truncated sweep exit', [0, []], [$status, phpErrors($err)]);
$results = json_decode($out, true);
foreach ($names as $name) {
    $data = file_get_contents("$dataDir/$name");
    $ends = [0];
    for ($offset = 0; $offset < strlen($data); $offset += unpack('N', substr($data, $offset, 4))[1]) {
        $ends []= $offset + unpack('N', substr($data, $offset, 4))[1];
    }
    $expect = [];
    $actual = [];
    for ($n = 0; $n < strlen($data); $n++) {
        $boxes = 0;
        while ($ends[$boxes + 1] <= $n) {
            $boxes++;
        }
        if ($n === $ends[$boxes]) {
            $expect[$n] = $boxes;          // cut at a box boundary: the boxes before it
        } else if ($n - $ends[$boxes] >= 8) {
            $expect[$n] = 'exception';     // cut inside a box
        } else {
            continue;                      // less than a box header left (not checked)
        }
        $actual[$n] = isset($results[$name][$n]) ? $results[$name][$n] : null;
    }
    check("truncated sweep $name", $expect, $actual);
}
// fromHEVC with every truncation of tiny.hevc: no PHP warning, no hang
$code = <<<'CODE'
require_once 'IO/HEIF.php';
$data = file_get_contents($argv[1]);
$results = [];
for ($n = 0; $n <= strlen($data); $n++) {
    $heif = new IO_HEIF();
    try {
        $heif->fromHEVC(substr($data, 0, $n));
        $results[$n] = strlen($heif->build());
    } catch (Exception $e) {
        $results[$n] = 'exception';
    }
}
echo json_encode($results);
CODE;
list($status, $out, $err) = runPHP(['-r', $code, "$dataDir/tiny.hevc"], '', 120);
$results = json_decode($out, true);
check('fromHEVC truncated sweep', [0, [], strlen(fromHEVC($tiny)), 'exception'],
      [$status, phpErrors($err), $results[strlen($tiny)], $results[strlen($tiny) - strlen($idr) - 4]]);

echo "passed: $passed, failed: $failed".PHP_EOL;
exit($failed ? 1 : 0);
