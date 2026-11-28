<?php
/*
 * generator of the synthetic test data (no dependency)
 * usage: php tests/data/make.php
 *   writes the files next to this script (or into the directory given as the argument).
 *   The output is the same on every run.
 * ref) ISO/IEC 14496-12 (ISOBMFF), ISO/IEC 23008-12 (HEIF), ISO/IEC 14496-15 (hvcC),
 *      ITU-T H.265 (HEVC parameter sets, Annex B byte stream), ICC.1 (ICC profile)
 * The slice data are dummy bytes.
 */

$dir = isset($argv[1]) ? $argv[1] : dirname(__FILE__);

function box($type, $payload) {
    return pack('N', 8 + strlen($payload)).$type.$payload;
}

function fullbox($type, $version, $flags, $payload) {
    return box($type, chr($version).substr(pack('N', $flags), 1).$payload);
}

// box with the 64-bit largesize
function largebox($type, $payload) {
    return pack('N', 1).$type.pack('J', 16 + strlen($payload)).$payload;
}

function beN($value, $size) {
    $s = '';
    for ($i = $size - 1; $i >= 0; $i--) {
        $s .= chr(($value >> (8 * $i)) & 0xff);
    }
    return $s;
}

// $sizes: [offset_size, length_size, base_offset_size, index_size]
// $items: [[item_ID, construction_method, data_reference_index, base_offset,
//           [[extent_offset, extent_index, extent_length], ...]], ...]
function ilocBox($version, $sizes, $items) {
    list($offsetSize, $lengthSize, $baseOffsetSize, $indexSize) = $sizes;
    $idSize = ($version < 2) ? 2 : 4;
    $p = chr(($offsetSize << 4) | $lengthSize).
       chr(($baseOffsetSize << 4) | ($version ? $indexSize : 0)).beN(count($items), $idSize);
    foreach ($items as $item) {
        list($itemID, $method, $dataRef, $baseOffset, $extents) = $item;
        $p .= beN($itemID, $idSize);
        if ($version >= 1) {
            $p .= pack('n', $method);
        }
        $p .= pack('n', $dataRef).beN($baseOffset, $baseOffsetSize).pack('n', count($extents));
        foreach ($extents as $extent) {
            $p .= beN($extent[0], $offsetSize);
            if ($version >= 1) {
                $p .= beN($extent[1], $indexSize);
            }
            $p .= beN($extent[2], $lengthSize);
        }
    }
    return fullbox('iloc', $version, 0, $p);
}

// infe version 2 (16-bit item_ID) or 3 (32-bit item_ID)
function infeBox($version, $itemID, $itemType, $name, $contentType = null) {
    $p = beN($itemID, ($version < 3) ? 2 : 4).pack('n', 0).$itemType.$name."\0";
    if (! is_null($contentType)) {
        $p .= $contentType."\0";
    }
    return fullbox('infe', $version, 0, $p);
}

function iinfBox($version, $infes) {
    return fullbox('iinf', $version, 0, beN(count($infes), $version ? 4 : 2).implode('', $infes));
}

// reference box in iref: $idSize 2 (iref version 0) or 4 (version 1)
function refBox($type, $idSize, $from, $tos) {
    $p = beN($from, $idSize).pack('n', count($tos));
    foreach ($tos as $to) {
        $p .= beN($to, $idSize);
    }
    return box($type, $p);
}

// $entries: [item_ID => [[essential, property_index], ...]]
function ipmaBox($version, $flags, $entries) {
    $p = pack('N', count($entries));
    foreach ($entries as $itemID => $assocs) {
        $p .= beN($itemID, $version ? 4 : 2).chr(count($assocs));
        foreach ($assocs as $assoc) {
            if ($flags & 1) {
                $p .= pack('n', ($assoc[0] << 15) | $assoc[1]);
            } else {
                $p .= chr(($assoc[0] << 7) | $assoc[1]);
            }
        }
    }
    return fullbox('ipma', $version, $flags, $p);
}

function hdlrBox($handler, $name) {
    return fullbox('hdlr', 0, 0, pack('N', 0).$handler.str_repeat("\0", 12).$name."\0");
}

function ispeBox($width, $height) {
    return fullbox('ispe', 0, 0, pack('NN', $width, $height));
}

function nclxBox($primaries, $transfer, $matrix, $fullRange) {
    return box('colr', 'nclx'.pack('nnn', $primaries, $transfer, $matrix).chr($fullRange << 7));
}

// ICC v2.1 display profile with only a desc tag
function iccProfile($desc) {
    $descTag = 'desc'.pack('N', 0).pack('N', strlen($desc) + 1).$desc."\0".
             pack('NN', 0, 0).pack('n', 0).chr(0).str_repeat("\0", 67);
    $tagOffset = 128 + 4 + 12;
    $header = pack('N', $tagOffset + strlen($descTag))."\0\0\0\0".pack('N', 0x02100000).
            'mntr'.'RGB '.'XYZ '.str_repeat("\0", 12).'acsp'.str_repeat("\0", 4).
            pack('N', 0).pack('NN', 0, 0).pack('NN', 0, 0).pack('N', 0).
            pack('NNN', 0xf6d6, 0x10000, 0xd32d).pack('N', 0).str_repeat("\0", 16 + 28);
    return $header.pack('N', 1).'desc'.pack('NN', $tagOffset, strlen($descTag)).$descTag;
}


// $before . meta . $between . $mdat . $after, where $metaFunc(file offset of $payload in $mdat)
// returns the meta box (its size does not depend on the offset)
function layout($before, $metaFunc, $between, $mdat, $payload, $after = '') {
    $offset = strlen($before) + strlen($metaFunc(0)) + strlen($between) + strpos($mdat, $payload);
    return $before.$metaFunc($offset).$between.$mdat.$after;
}

// "0101 1..." bit string => bytes (zero padded)
function bits2bytes($s) {
    $s = str_replace(' ', '', $s);
    $s = str_pad($s, (int)ceil(strlen($s) / 8) * 8, '0');
    $o = '';
    foreach (str_split($s, 8) as $b) {
        $o .= chr(bindec($b));
    }
    return $o;
}

// insert emulation prevention bytes (H.265 7.4.2)
function epb($rbsp) {
    $o = '';
    $z = 0;
    foreach (str_split($rbsp) as $c) {
        if (($z >= 2) && (ord($c) <= 3)) {
            $o .= "\x03";
            $z = 0;
        }
        $o .= $c;
        $z = ($c === "\0") ? ($z + 1) : 0;
    }
    return $o;
}

// ue(v) Exp-Golomb code
function ue($value) {
    $b = decbin($value + 1);
    return str_repeat('0', strlen($b) - 1).$b;
}

// NAL unit (header + RBSP with emulation prevention) for the bit string $bits
function nalUnit($type, $bits) {
    return chr($type << 1)."\x01".epb(bits2bytes($bits.'1'));  // rbsp_stop_one_bit
}

// profile_tier_level(1, 0): Main profile (general_profile_compatibility_flag 1 and 2),
// progressive_source 1, frame_only_constraint 1, general_level_idc $level
function ptl($level) {
    return '00 0 00001'.sprintf('%032b', 0x60000000).'1 0 0 1'.str_repeat('0', 43).'0'.sprintf('%08b', $level);
}

// VPS, SPS (4:2:0, 8-bit, $width x $height) and PPS NAL units
function parameterSets($width, $height, $level) {
    $vps = nalUnit(32, '0000 1 1 000000 000 1'.str_repeat('1', 16).ptl($level).'0');
    $sps = nalUnit(33, '0000 000 1'.ptl($level).ue(0).ue(1).ue($width).ue($height).'0'.ue(0).ue(0).ue(4).'1');
    $pps = "\x44\x01"."PPS-DUMMY";
    return [$vps, $sps, $pps];
}

// hvcC for parameterSets(): Main profile, compatibility 0x60000000, constraint 0x900000000000
// $arrays: [[NAL_unit_type, [nalUnit, ...]], ...] (array_completeness 1)
function hvcCBox($arrays, $level) {
    $p = "\x01"."\x01".pack('N', 0x60000000).pack('nN', 0x9000, 0).chr($level).
       pack('n', 0xf000).            // reserved 1111, min_spatial_segmentation_idc 0
       "\xfc".                       // reserved 111111, parallelismType 0
       "\xfd".                       // reserved 111111, chromaFormat 1 (4:2:0)
       "\xf8"."\xf8".                // reserved 11111, bitDepth{Luma,Chroma}Minus8 0
       pack('n', 0).                 // avgFrameRate
       "\x0f".                       // constantFrameRate 0, numTemporalLayers 1, temporalIdNested 1, lengthSizeMinusOne 3
       chr(count($arrays));
    foreach ($arrays as $array) {
        $p .= chr(0x80 | $array[0]).pack('n', count($array[1]));
        foreach ($array[1] as $nalUnit) {
            $p .= pack('n', strlen($nalUnit)).$nalUnit;
        }
    }
    return box('hvcC', $p);
}

// NAL units with 4-byte lengths (lengthSizeMinusOne 3), as in an item of mdat
function lengthPrefixed($nalUnits) {
    $data = '';
    foreach ($nalUnits as $nalUnit) {
        $data .= pack('N', strlen($nalUnit)).$nalUnit;
    }
    return $data;
}

$files = [];
$ftypHeic = box('ftyp', 'heic'.pack('N', 0).'mif1heic');
list($vps, $sps, $pps) = parameterSets(64, 48, 60);
$paramArrays = [[32, [$vps]], [33, [$sps]], [34, [$pps]]];
$idr = "\x26\x01"."IDR-SLICE-DATA";      // IDR_W_RADL (19)

/*
 * tiny.hevc: Annex B byte stream (VPS, SPS 64x48, PPS, IDR_W_RADL) with 4-byte start codes
 */
$files['tiny.hevc'] = "\0\0\0\1".implode("\0\0\0\1", [$vps, $sps, $pps, $idr]);

/*
 * idr_n_lp.hevc: 3-byte start codes, a prefix SEI before the picture, IDR_N_LP (20)
 */
$files['idr_n_lp.hevc'] = "\0\0\1".implode("\0\0\1", [$vps, $sps, $pps, "\x4e\x01"."SEI", "\x28\x01"."IDR-N-LP"]);

/*
 * single.heic: the picture of tiny.hevc as a HEIC with one hvc1 item
 */
$mdatData = lengthPrefixed([$idr]);
$files['single.heic'] = layout($ftypHeic, function($p) use ($paramArrays, $mdatData) {
    $ipco = box('ipco', hvcCBox($paramArrays, 60).ispeBox(64, 48));
    return fullbox('meta', 0, 0, hdlrBox('pict', '').fullbox('pitm', 0, 0, pack('n', 1)).
                   ilocBox(0, [4, 4, 0, 0], [[1, 0, 0, 0, [[$p, 0, strlen($mdatData)]]]]).
                   iinfBox(0, [infeBox(2, 1, 'hvc1', 'Image')]).
                   box('iprp', $ipco.ipmaBox(0, 0, [1 => [[1, 1], [0, 2]]])));
}, '', box('mdat', $mdatData), $mdatData);

/*
 * multi.heic: grid (in idat) of 4 tiles as the primary item, a thumbnail (own hvcC,
 * two NAL units in two extents), alpha and depth auxiliary items (auxC), Exif (cdsc)
 */
$tiles = [];
for ($i = 1; $i <= 4; $i++) {
    $tiles []= lengthPrefixed(["\x26\x01"."TILE-$i"]);
}
$thumb = lengthPrefixed(["\x26\x01"."THUMB-A", "\x02\x01"."THUMB-B"]);   // IDR_W_RADL, TRAIL_R
$alpha = lengthPrefixed(["\x26\x01"."ALPHA"]);
$depth = lengthPrefixed(["\x26\x01"."DEPTH"]);
$payload = implode('', $tiles).$thumb.$alpha.$depth."EXIF";
$thumbArrays = [[32, ["\x40\x01"."T-VPS"]], [33, ["\x42\x01"."T-SPS"]], [34, ["\x44\x01"."T-PPS"]]];
$files['multi.heic'] = layout($ftypHeic, function($p) use ($paramArrays, $thumbArrays, $tiles, $thumb, $alpha, $depth) {
    $items = [[1, 1, 0, 0, [[0, 0, 8]]]];
    $offset = $p;
    foreach ($tiles as $i => $tile) {
        $items []= [2 + $i, 0, 0, 0, [[$offset, 0, strlen($tile)]]];
        $offset += strlen($tile);
    }
    $split = 15;  // in the middle of the second NAL unit
    $items []= [6, 0, 0, 0, [[$offset, 0, $split], [$offset + $split, 0, strlen($thumb) - $split]]];
    $offset += strlen($thumb);
    $items []= [7, 0, 0, 0, [[$offset, 0, strlen($alpha)]]];
    $offset += strlen($alpha);
    $items []= [8, 0, 0, 0, [[$offset, 0, strlen($depth)]]];
    $offset += strlen($depth);
    $items []= [9, 0, 0, 0, [[$offset, 0, 4]]];
    $iinf = [infeBox(2, 1, 'grid', '')];
    for ($i = 2; $i <= 8; $i++) {
        $iinf []= infeBox(2, $i, 'hvc1', '');
    }
    $iinf []= infeBox(2, 9, 'Exif', '');
    $iref = fullbox('iref', 0, 0, refBox('dimg', 2, 1, [2, 3, 4, 5]).refBox('thmb', 2, 6, [1]).
                    refBox('auxl', 2, 7, [1]).refBox('auxl', 2, 8, [1]).refBox('cdsc', 2, 9, [1]));
    $ipco = box('ipco', hvcCBox($paramArrays, 60).                                  // 1
                hvcCBox($thumbArrays, 30).                                          // 2
                ispeBox(64, 48).                                                    // 3
                ispeBox(32, 24).                                                    // 4
                ispeBox(16, 12).                                                    // 5
                fullbox('auxC', 0, 0, "urn:mpeg:hevc:2015:auxid:1\0").              // 6
                fullbox('auxC', 0, 0, "urn:mpeg:hevc:2015:auxid:2\0").              // 7
                nclxBox(1, 13, 6, 1));                                              // 8
    $ipma = ipmaBox(0, 0, [1 => [[0, 3], [0, 8]], 2 => [[1, 1], [0, 4]], 3 => [[1, 1], [0, 4]],
                           4 => [[1, 1], [0, 4]], 5 => [[1, 1], [0, 4]], 6 => [[1, 2], [0, 5]],
                           7 => [[1, 1], [0, 4], [1, 6]], 8 => [[1, 1], [0, 4], [1, 7]]]);
    // ImageGrid: version 0, flags 0, rows 2, columns 2, 64x48
    $idat = box('idat', "\x00\x00\x01\x01".pack('nn', 64, 48));
    return fullbox('meta', 0, 0, hdlrBox('pict', '').fullbox('pitm', 0, 0, pack('n', 1)).
                   ilocBox(1, [4, 4, 0, 0], $items).iinfBox(0, $iinf).$iref.box('iprp', $ipco.$ipma).$idat);
}, '', box('mdat', $payload), $payload);

/*
 * truncated.heic: single.heic cut in the middle of meta
 */
$files['truncated.heic'] = substr($files['single.heic'], 0, 100);

/*
 * tiny.icc: ICC profile (version 2.1) with the description "Tiny Test"
 */
$files['tiny.icc'] = iccProfile('Tiny Test');

foreach ($files as $name => $data) {
    file_put_contents("$dir/$name", $data);
    echo "$name: ".strlen($data).PHP_EOL;
}
