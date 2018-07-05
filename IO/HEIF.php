<?php

/*
  IO_HEIF class
  (c) 2017/07/26 yoya@awm.jp
  ref) https://developer.apple.com/standards/qtff-2001.pdf
 */

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/Bit.php';
}
require_once dirname(__FILE__).'/HEIF/HEVC.php';

function getTypeDescription($type) {
    // http://mp4ra.org/atoms.html
    // https://developer.apple.com/videos/play/wwdc2017/513/
    static $getTypeDescriptionTable = [
        "ftyp" => "File Type and Compatibility",
        "meta" => "Information about items",
        "mdat" => "Media Data",
        "moov" => "MovieBox",
        //
        "hdlr" => "Handler reference",
        "pitm" => "Prinary item referene",
        "iloc" => "Item location",
        "iinf" => "Item information",
        "infe" => "Item information entry",
        //
        "dinf" => "Data information Box",
        "dref" => "Data Referenve Box",
        "url " => "Data Entry Url Box",
        "urn " => "Data Entry Urn Box",
        //
        "iref" => "Item Reference Box",
        "dimg" => "Derived Image",
        "thmb" => "Thumbnail",
        "auxl" => "Auxiliary Imagel",
        "cdsc" => "Content describe",
        //
        "iprp" => "Item Properties",
        "ipco" => "Item Property Container",
        "pasp" => "Pixel Aspect Ratio",
        "hvcC" => "HEVC Decoder Conf",
        "ispe" => "Image Spatial Extents", // width, height
        "colr" => "Colour Information", // ICC profile
        "pixi" => "Pixel Information",
        "clap" => "Clean Aperture",
        //
        "ipma" => "Item Properties Association",
    ];
    if (isset($getTypeDescriptionTable[$type])) {
        return $getTypeDescriptionTable[$type];
    }
    return null;
}

function getChromeFormatDescription($format) {
    static $chromeFormatDescription = [
        0 => "Grayscale",
        1 => "YUV420",
        2 => "YUV422",
        3 => "YUV444",
    ];
    if (isset($chromeFormatDescription[$format])) {
        return $chromeFormatDescription[$format];
    }
    return "Unknown Chroma Format";
}

class IO_HEIF {
    var $_chunkList = null;
    var $_heifdata = null;
    var $boxTree = [];
    function parse($heifdata, $opts = array()) {
        $opts["indent"] = 0;
        $bit = new IO_Bit();
        $bit->input($heifdata);
        $this->_heifdata = $heifdata;
        $this->boxTree = $this->parseBoxList($bit, strlen($heifdata), null, $opts);
        // offset linking iloc=baseOffset <=> mdat
        $this->applyFunctionToBoxTree2($this->boxTree, function(&$iloc, &$mdat) {
            if (($iloc["type"] !== "iloc") || ($mdat["type"] !== "mdat")) {
                return ;
            }
            foreach ($iloc["itemArray"] as &$item) {
                $itemID = $item["itemID"];
                if (isset($item["baseOffset"])) {
                    if ($item["baseOffset"] > 0) {
                        $offset = $item["baseOffset"];
                    } else {
                        $offset = $item["extentArray"][0]["extentOffset"];
                    }
                    $mdatStart = $mdat["_offset"];
                    $mdatNext = $mdatStart + $mdat["_length"];
                    if (($mdatStart <= $offset) && ($offset < $mdatNext)) {
                        $mdatId = mt_rand();
                        $item["_mdatId"] = $mdatId;
                        $mdat["_mdatId"] = $mdatId;
                        $offsetRelative = $offset - $mdatStart;
                        $item["_offsetRelative"] = $offsetRelative;
                        $mdat["_offsetRelative"] = $offsetRelative;
                        $mdat["_itemID"] = $itemID;
                    }
                }
            }
            unset($item);
        });
    }
    //
    function applyFunctionToBoxTree(&$boxTree, $callback, &$userdata) {
        foreach ($boxTree as &$box) {
            $callback($box, $userdata);
            if (isset($box["boxList"])) {
                $this->applyFunctionToBoxTree($box["boxList"], $callback, $userdata);
            }
        }
        unset($box);
    }
    // combination traversal
    function applyFunctionToBoxTree2(&$boxTree, $callback) {
        foreach ($boxTree as &$box) {
            $this->applyFunctionToBoxTree($boxTree, $callback, $box);
            if (isset($box["boxList"])) {
                $this->applyFunctionToBoxTree2($box["boxList"], $callback);
            }
        }
        unset($box);
    }
    function parseBoxList($bit, $length, $parentType, $opts) {
        // echo "parseBoxList(".strlen($data).")\n";
        $boxList = [];
        $opts["indent"] = $opts["indent"] + 1;
        list($boxOffset, $dummy) = $bit->getOffset();
        while ($bit->hasNextData(8) && ($bit->getOffset()[0] < ($boxOffset + $length))) {
            try {
                $type = str_split($bit->getData(8), 4)[1];
                $bit->incrementOffset(-8, 0);
                $box = $this->parseBox($bit, $parentType, $opts);
            } catch (Exception $e) {
                fwrite(STDERR, "ERROR type:$type".PHP_EOL);
                throw $e;
            }
            $boxList []= $box;
        }
        return $boxList;
    }
    
    function parseBox($bit, $parentType, $opts) {
        list($boxOffset, $dummy) = $bit->getOffset();
        $indentSpace = str_repeat(" ", ($opts["indent"]-1) * 4);
        $boxLength = $bit->getUI32BE();
        if ($boxLength <= 1) {
            $boxLength = null;
        } else if ($boxLength < 8) {
            list($offset, $dummy) = $bit->getOffset();
            throw new Exception("parseBox: boxLength($boxLength) < 8 (fileOffset:$offset)");
        }
        $type = $bit->getData(4);
        $box = ["type" => $type, "_offset" => $boxOffset, "_length" => $boxLength];
        if (! empty($opts["debug"])) {
            fwrite(STDERR, "DEBUG: parseBox:$indentSpace type:$type offset:$boxOffset boxLength:$boxLength\n");
        }
        if ($boxLength && ($bit->hasNextData($boxLength - 8) === false)) {
            list($offset, $dummy) = $bit->getOffset();
            throw new Exception("parseBox: hasNext(boxLength:$boxLength - 8) === false (boxOffset:$boxOffset) (fileOffset:$offset)");
        }
        if ($boxLength) {
            $nextOffset = $boxOffset + $boxLength;
            $dataLen = $boxLength - 8; // 8 = len(4) + type(4)
        } else {
            $nextOffset = null;
            $dataLen = null;
        }
        switch($type) {
        case "ftyp":
            $box["major"] = $bit->getData(4);
            $box["minor"] = $bit->getUI32BE();
            $altdata = $bit->getData($dataLen - 8);
            $box["alt"] = str_split($altdata, 4);
            break;
        case "hdlr":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $box["componentType"] = $bit->getData(4);
            $box["componentSubType"] = $bit->getData(4);
            $box["componentManufacturer"] = $bit->getData(4);
            $box["componentFlags"] = $bit->getUI32BE();
            $box["componentFlagsMask"] = $bit->getUI32BE();
            $box["componentName"] = $bit->getData($dataLen - 24);
            break;
        case "mvhd":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $box["creationTime"] = $bit->getUI32BE();
            $box["modificationTime"] = $bit->getUI32BE();
            $box["timeScale"] = $bit->getUI32BE();
            $box["duration"] = $bit->getUI32BE();
            $box["preferredRate"] = $bit->getUI32BE();
            $box["preferredVolume"] = $bit->getUI32BE();
            $box["reserved"] = $bit->getData(10);
            $matrixStructure = [];
            for ($i = 0 ; $i < 9 ; $i++) {
                $matrixStructure []= $bit->getSI32BE(); // XXX: SI ? UI ?
            }
            $box["MatrixStructure"] = $matrixStructure;
            $box["previewTime"] = $bit->getUI32BE();
            $box["peviewDuration"] = $bit->getUI32BE();
            $box["posterTime"] = $bit->getUI32BE();
            $box["selectionTime"] = $bit->getUI32BE();
            $box["selectionDuration"] = $bit->getUI32BE();
            $box["currentTime"] = $bit->getUI32BE();
            $box["nextTrackID"] = $bit->getUI32BE();
            break;
        case "tkhd":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $box["creationTime"] = $bit->getUI32BE();
            $box["modificationTime"] = $bit->getUI32BE();
            $box["trackId"] = $bit->getUI32BE();
            $box["reserved"] = $bit->getData(4);
            $box["duration"] = $bit->getUI32BE();
            $box["reserved"] = $bit->getData(4);
            $box["layer"] = $bit->getUI32BE();
            $box["alternat4eGroup"] = $bit->getUI32BE();
            break;
        case "ispe":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $box["width"]  = $bit->getUI32BE();
            $box["height"] = $bit->getUI32BE();
            break;
        case "pasp":
            $box["hspace"] = $bit->getUI32BE();
            $box["vspace"] = $bit->getUI32BE();
            break;
        case "pitm":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $box["itemID"] = $bit->getUI16BE();
            break;
        case "hvcC":
            // https://gist.github.com/yohhoy/2abc28b611797e7b407ae98faa7430e7
            $box["version"]  = $bit->getUI8();
            $box["profileSpace"] = $bit->getUIBits(2);
            $box["tierFlag"] = $bit->getUIBit();
            $box["profileIdc"] = $bit->getUIBits(5);
            $box["profileCompatibilityFlags"] = $bit->getUI32BE();
            $box["constraintIndicatorFlags"] = $bit->getUIBits(48);
            $box["levelIdc"] = $bit->getUI8();
            $reserved = $bit->getUIBits(4);
            if ($reserved !== 0xF) {
                $mesg = "reserved({$reserved}) !== 0xF at L%d";
                if (empty($opts['restrict'])) {
                    fprintf(STDERR, $mesg.PHP_EOL, __LINE__);
                } else {
                    var_dump($box);
                    throw new Exception($mesg);
                }
            }
            $box["minSpatialSegmentationIdc"]  = $bit->getUIBits(12);
            $reserved = $bit->getUIBits(6);
            if ($reserved !== 0x3F) {
                $mesg = "reserved({$reserved}) !== 0x3F at L%d";
                if (empty($opts['restrict'])) {
                    fprintf(STDERR, $mesg.PHP_EOL, __LINE__);
                } else {
                    var_dump($box);
                    throw new Exception($mesg);
                }
            }
            $box["parallelismType"]  = $bit->getUIBits(2);
            $reserved = $bit->getUIBits(6);
            if ($reserved !== 0x3F) {
                $mesg = "reserved({$reserved}) !== 0x3F at L%d";
                if (empty($opts['restrict'])) {
                    fprintf(STDERR, $mesg.PHP_EOL, __LINE__);
                } else {
                    var_dump($box);
                    throw new Exception($mesg);
                }
            }
            $box["chromaFormat"]  = $bit->getUIBits(2);
            $reserved = $bit->getUIBits(5);
            if ($reserved !== 0x1F) {
                $mesg = "reserved({$reserved}) !== 0x1F at L%d";
                if (empty($opts['restrict'])) {
                    fprintf(STDERR, $mesg.PHP_EOL, __LINE__);
                } else {
                    var_dump($box);
                    throw new Exception($mesg);
                }
            }
            $box["bitDepthLumaMinus8"]  = $bit->getUIBits(3);
            $reserved = $bit->getUIBits(5);
            if ($reserved !== 0x1F) {
                $mesg = "reserved({$reserved}) !== 0x1F at L%d";
                if (empty($opts['restrict'])) {
                    fprintf(STDERR, $mesg.PHP_EOL, __LINE__);
                } else {
                    var_dump($box);
                    throw new Exception($mesg);
                }
            }
            $box["bitDepthChromaMinus8"]  = $bit->getUIBits(3);
            $box["avgFrameRate"]  = $bit->getUIBits(16);
            $box["constantFrameRate"]  = $bit->getUIBits(2);
            $box["numTemporalLayers"]  = $bit->getUIBits(3);
            $box["temporalIdNested"]  = $bit->getUIBit();
            $box["lengthSizeMinusOne"]  = $bit->getUIBits(2);
            
            $box["numOfArrays"] = $numOfArrays = $bit->getUI8();
            $nalArrays = [];
            for ($i = 0 ; $i < $numOfArrays ; $i++) {
                $nal = [];
                $nal["array_completeness"] = $bit->getUIBit();
                $reserved = $bit->getUIBit();
                if ($reserved !== 0) {
                    var_dump($box);
                    var_dump($nalArrays);
                    throw new Exception("reserved({$reserved}) !== 0 at L%d");
                }
                $nal["NALUnitType"] = $bit->getUIBits(6);
                $nal["numNalus"] = $numNalus = $bit->getUI16BE();
                $nalus = [];
                for ($j = 0 ; $j < $numNalus ; $j++) {
                    $nalu = [];
                    $nalu["nalUnitLength"] = $nalUnitLength = $bit->getUI16BE();
                    $nalu["nalUnit"] = $bit->getData($nalUnitLength);
                    $nalus []= $nalu;
                }
                $nal["nalus"] = $nalus;
                $nalArrays []= $nal;
            }
            $box["nalArrays"] = $nalArrays;
           break;
        case "iloc":
            if ($parentType === "iref") {
                $box["itemID"] = $bit->getUI16BE();
                $box["itemCount"] = $bit->getUI16BE();
                $itemArray = [];
                for ($i = 0 ; $i < $box["itemCount"]; $i++) {
                    $item = [];
                    $item["itemID"] = $bit->getUI16BE();
                    $itemArray []= $item;
                }
                $box["itemArray"] = $itemArray;
            } else {
                $box["version"] = $bit->getUI8();
                $box["flags"] = $bit->getUIBits(8 * 3);
                $offsetSize = $bit->getUIBits(4);
                $lengthSize = $bit->getUIBits(4);
                $baseOffsetSize = $bit->getUIBits(4);
                $box["offsetSize"] = $offsetSize;
                $box["lengthSize"] = $lengthSize;
                $box["baseOffsetSize"] = $baseOffsetSize;
                if ($box["version"] === 0) {
                    $box["reserved"] = $bit->getUIBits(4);
                } else {
                    $indexSize = $bit->getUIBits(4);
                    $box["indexSize"] = $indexSize;
                }
                $itemCount = $bit->getUI16BE();
                $box["itemCount"] = $itemCount;
                $itemArray = [];
                for ($i = 0 ; $i < $itemCount; $i++) {
                    $item = [];
                    $item["itemID"] = $bit->getUI16BE();
                    if ($box["version"] >= 1) {
                        $item["constructionMethod"] = $bit->getUI16BE();
                    }
                    $item["dataReferenceIndex"] = $bit->getUI16BE();
                    $item["baseOffset"] = $bit->getUIBits(8 * $baseOffsetSize);
                    $extentCount = $bit->getUI16BE();
                    $item["extentCount"] = $extentCount;
                    $extentArray = [];
                    for ($j = 0 ; $j < $extentCount ; $j++) {
                        $extent = [];
                        $extent["extentOffset"] = $bit->getUIBits(8 * $offsetSize);
                        if ($box["version"] >= 1) {
                            $extent["extentIndex"] = $bit->getUIBits(8 * $indexSize);
                        }
                        $extent["extentLength"] = $bit->getUIBits(8 * $lengthSize);
                        $extentArray [] = $extent;
                    }
                    $item["extentArray"] = $extentArray;
                    $itemArray []= $item;
                }
                $box["itemArray"] = $itemArray;
            }
            break;
        case "iref":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $dataLen -= 4;
            $box["boxList"] = $this->parseBoxList($bit, $dataLen, $type, $opts);
            break;
        case "thmb":
            $box["fromItemID"] = $bit->getUI16BE();
            $box["itemCount"] = $bit->getUI16BE();
            $itemIDArray = [];
            for ($i = 0 ; $i < $box["itemCount"] ; $i++) {
                $item = [];
                $item["itemID"] = $bit->getUI16BE();
                $itemArray []= $item;
            }
            $box["itemArray"] = $itemArray;
            break;
        case "colr":
            $box["subtype"] = $bit->getData(4);
            $dataLen -= 4;
            $box["data"] = $bit->getData($dataLen);
            break;
        case "pixi":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $box["channelCount"] = $bit->getUI8();
            $channelArray = [];
            for ($i = 0 ; $i < $box["channelCount"] ; $i++) {
                $channelArray []= [ "bitsPerChannel" => $bit->getUI8() ];
            }
            $box["channelArray"] = $channelArray;
            break;
        case "clap":
            $box["width_N"] = $bit->getSI32BE();
            $box["width_D"] = $bit->getSI32BE();
            $box["height_N"] = $bit->getSI32BE();
            $box["height_D"] = $bit->getSI32BE();
            $box["horizOff_N"] = $bit->getSI32BE();
            $box["horizOff_D"] = $bit->getSI32BE();
            $box["vertOff_N"] = $bit->getSI32BE();
            $box["vertOff_D"] = $bit->getSI32BE();
            break;
        case "ipma":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $box["entryCount"] = $bit->getUI32BE();
            $entryArray = [];
            for ($i = 0 ; $i < $box["entryCount"] ; $i++) {
                $entry = [];
                $entry["itemID"] = $bit->getUI16BE();
                $entry["associationCount"] = $bit->getUI8();
                $associationArray = [];
                for ($j = 0 ; $j < $entry["associationCount"] ; $j++) {
                    $association = [];
                    $association["essential"] = $bit->getUIBit();
                    if ($box["flags"] & 1) {
                        $association["propertyIndex"] = $bit->getUIBits(15);
                    }  else {
                        $association["propertyIndex"] = $bit->getUIBits(7);
                    }
                    $associationArray [] = $association;
                }
                $entry["associationArray"] = $associationArray;
                $entryArray []= $entry;
            }
            $box["entryArray"] = $entryArray;
            break;
        case "iinf":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            if ($box["version"] <= 1) {  // XXX: 0 or 1 ???
                $box["count"] = $bit->getUI16BE();
                $dataLen -= 6;
            } else {
                $box["count"] = $bit->getUI32BE();
                $dataLen -= 8;
            }
            $box["boxList"] = $this->parseBoxList($bit, $dataLen, $type, $opts);
            break;
        case "infe":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $box["itemID"] = $bit->getUI16BE();
            $box["itemProtectionIndex"] = $bit->getUI16BE();
            if ($box["version"] <= 1) {  // XXX: 0 or 1 ???
                ;
            } else {
                $box["itemType"] = $bit->getData(4);
            }
            $box["itemName"] = $bit->getDataUntil("\0");
            $box["contentType"] = null;
            $box["contentEncoding"] = null;
            list($offset, $dummy) = $bit->getOffset();
            if (($offset - $boxOffset) < $dataLen) {
                $box["contentType"] = $bit->getDataUntil("\0");
                list($offset, $dummy) = $bit->getOffset();
                if (($offset - $boxOffset) < $dataLen) {
                    $box["contentEncoding"] = $bit->getDataUntil("\0");
                }
            }
            break;
        case "dimg":
            $box["fromItemID"] = $bit->getUI16BE();
            $box["itemCount"] = $bit->getUI16BE();
            $itemArray = [];
            for ($i = 0 ; $i < $box["itemCount"] ; $i++) {
                $item = [];
                $item["itemID"] = $bit->getUI16BE();
                $itemArray []= $item;
            }
            $box["itemArray"] = $itemArray;
            break;
        case "dref":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $entryCount = $bit->getUI32BE();
            $box["entryCount"] = $entryCount;
            $dataLen -= 8;
            $box["boxList"] = $this->parseBoxList($bit, $dataLen, $type, $opts);
            if (count($box["boxList"]) !== $entryCount) {
                throw new Exception("parseBox: box[boxList]:{$box['entryCount']} != entryCount:$entryCount");
            }
            break;
        case "url ":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $box["location"] = $bit->getData($dataLen - 4);
            break;
        case "auxC":
            $box["version"] = $bit->getUI8();
            $box["flags"] = $bit->getUIBits(8 * 3);
            $box["auxType"] = $bit->getDataUntil("\0");
            $currOffset = $bit->getOffset()[0];
            if ($currOffset < $nextOffset) {
                $box["auxSubType"] = $bit->getData($nextOffset - $currOffset);
            } else {
                $box["auxSubType"] = null;
            }
            break;
        case "auxl":
            $box["fromItemID"] = $bit->getUI16BE();
            $itemCount = $bit->getUI16BE();
            $itemArray = [];
            for ($i = 0 ; $i < $itemCount ; $i++) {
                $itemArray[] = ["itemID" => $bit->getUI16BE()];
            }
            $box["itemArray"] = $itemArray;
            break;
            /*
             * container type
             */
        case "moov": // Movie Atoms
        case "trak":
        case "mdia":
        case "meta": // Metadata
        case "dinf": // data infomation
        case "iprp": // item properties
        case "ipco": // item property container
            if ($type === "meta") {
                $box["version"] = $bit->getUI8();
                $box["flags"] = $bit->getUIBits(8 * 3);
                $dataLen -= 4;
            }
            $box["boxList"] = $this->parseBoxList($bit, $dataLen, $type, $opts);
            break;
        default:
            break;
        }
        if ($boxLength) {
            $bit->setOffset($nextOffset, 0);
        } else {
            $bit->getDataUntil(false); // skip to the end
            $currOffset = $bit->getOffset()[0];
            $boxLength = $currOffset - $box["_offset"];
            $box["_length"] = $boxLength;
        }
        return $box;
    }
    function dump($opts = array()) {
        $opts["indent"] = 0;
        $this->dumpBoxList($this->boxTree, $opts);
    }
    function dumpBoxList($boxList, $opts) {
        if (is_array($boxList) === false) {
            echo "dumpBoxList ERROR:";
            var_dump($boxList);
            return ;
        }
        foreach ($boxList as $box) {
            $this->dumpBox($box, $opts);
        }
    }
    function dumpBox($box, $opts) {
        $type = $box["type"];
        $indentSpace = str_repeat(" ", $opts["indent"] * 4);
        if (! empty($opts["typeonly"])) {
            $this->printfBox($box, $indentSpace."type:%s");
            if (isset($box["version"])) {
                $this->printfBox($box, " version:%d");
            }
            if (isset($box["flags"])) {
                $this->printfBox($box, " flags:%d");
            }
            echo PHP_EOL;
            if (isset($box["boxList"])) {
                $opts["indent"] += 1;
                $this->dumpBoxList($box["boxList"], $opts);
            }
            return ;
        }

        echo $indentSpace."type:".$type."(offset:".$box["_offset"]." len:".$box["_length"]."):";
        $desc = getTypeDescription($type);
        if ($desc) {
            echo $desc;
        }
        echo "\n";
        switch ($type) {
        case "ftyp":
            echo $indentSpace."  major:".$box["major"]." minor:".$box["minor"];
            echo "  alt:".join(", ", $box["alt"]).PHP_EOL;
            break;
        case "ispe":
            echo $indentSpace."  version:".$box["version"]." flags:".$box["flags"];
            echo "  width:".$box["width"]." height:".$box["height"].PHP_EOL;
            break;
        case "thmb":
            $this->printfBox($box, $indentSpace."  fromItemID:%d".PHP_EOL);
            $this->printfBox($box, $indentSpace."  itemCount:%d".PHP_EOL);
            foreach ($box["itemArray"] as $item) {
                $this->printfBox($item, $indentSpace."    itemID:%d".PHP_EOL);
            }
            break;
        case "colr":
            $this->printfBox($box, $indentSpace."  subtype:%s");
            echo "  data(len:".strlen($box["data"]).")".PHP_EOL;
            break;
        case "pixi":
            $this->printfBox($box, $indentSpace."  channelCount:%d".PHP_EOL);
            foreach ($box["channelArray"] as $item) {
                $this->printfBox($item, $indentSpace."    bitsPerChannel:%d".PHP_EOL);
            }
            break;
        case "clap":
            $this->printfBox($box, $indentSpace."  width_N:%d / width_D:%d  height_N:%d / height_D:%d".PHP_EOL);
            $this->printfBox($box, $indentSpace."  horizOff_N:%d / horizOff_D:%d  vertOff_N:%d / vertOff_D:%d".PHP_EOL);
            break;
        case "ipma":
            $this->printfBox($box, $indentSpace."  version:%d flags:%d".PHP_EOL);
            $box["entryCount"] = count($box["entryArray"]);
            $this->printfBox($box, $indentSpace."  entryCount:%d".PHP_EOL);
            foreach ($box["entryArray"] as $entry) {
                $this->printfBox($entry, $indentSpace."    itemID:%d".PHP_EOL);
                $entry["associationCount"]  = count($entry["associationArray"] );
                $this->printfBox($entry, $indentSpace."    associationCount:%d".PHP_EOL);
                foreach ($entry["associationArray"] as $assoc) {
                    $this->printfBox($assoc, $indentSpace."      essential:%d propertyIndex:%d".PHP_EOL);
                }
            }
            break;
        case "infe":
            $this->printfBox($box, $indentSpace."  version:%d flags:%d  itemID:%d itemProtectionIndex:%d".PHP_EOL);
            if ($box["version"] <= 1) {  // XXX: 0 or 1 ???
                ;
            } else {
                $this->printfBox($box, $indentSpace."  itemType:%s".PHP_EOL);
            }
            $this->printfBox($box, $indentSpace."  itemName:%s contentType:%s contentEncoding:%s".PHP_EOL);
            break;
            case "dimg":
                $this->printfBox($box, $indentSpace."  fromItemID:%d".PHP_EOL);
                foreach ($box["itemArray"] as $item) {
                    $this->printfBox($item, $indentSpace."    itemID:%d".PHP_EOL);
                }
            break;
        case "url ":
            $this->printfBox($box, $indentSpace."  version:%d flags:%d  location:%s".PHP_EOL);
            break;
        case "pasp":
            echo $indentSpace."  hspace:".$box["hspace"]." vspace:".$box["vspace"].PHP_EOL;
            break;
        case "pitm":
            $this->printfBox($box, $indentSpace."  version:%d flags:%d  itemID:%d".PHP_EOL);
            break;
        case "hvcC":
            $profileIdc = $box["profileIdc"];
            $profileIdcStr = ($profileIdc===1)?"Main profile":(($profileIdc===2)?"Main10 profile":"unknown profile");
            $this->printfBox($box, $indentSpace."  version:%d profileSpace:%d tierFlag:%x profileIdc:%d");
            echo "($profileIdcStr)".PHP_EOL;
            $this->printfBox($box, $indentSpace."  profileCompatibilityFlags:0x%x".PHP_EOL);
            $this->printfBox($box, $indentSpace."  constraintIndicatorFlags:0x%x levelIdc:%d".PHP_EOL);
            $this->printfBox($box, $indentSpace."  minSpatialSegmentationIdc:%d parallelismType:%d".PHP_EOL);
            $chromaFormatStr = getChromeFormatDescription($box["chromaFormat"]);
            $this->printfBox($box, $indentSpace."  chromaFormat:%d($chromaFormatStr) bitDepthLumaMinus8:%d bitDepthChromaMinus8:%d".PHP_EOL);
            $this->printfBox($box, $indentSpace."  avgFrameRate:%d constantFrameRate:%d".PHP_EOL);
            $this->printfBox($box, $indentSpace."  numTemporalLayers:%d temporalIdNested:%d lengthSizeMinusOne:%d".PHP_EOL);
            foreach ($box["nalArrays"] as $nal) {
                $this->printfBox($nal, $indentSpace."    array_completeness:%d NALUnitType:%d".PHP_EOL);
                foreach ($nal["nalus"] as $nalu) {
                    $nalu["nalUnitLength"] = strlen($nalu["nalUnit"]);
                    $this->printfBox($nalu, $indentSpace."      nalUnitLength:%d nalUnit:%h".PHP_EOL);
                }
            }
            break;
        case "iloc":
            if (isset($box["version"]) === false) {
                $this->printfBox($box, $indentSpace."  itemID:%d".PHP_EOL);
                $box["itemCount"] = count($box["itemArray"]);
                $this->printfBox($box, $indentSpace."  itemCount:%d".PHP_EOL);
                foreach ($box["itemArray"] as $item) {
                    $this->printfBox($item, $indentSpace."    itemID:%d".PHP_EOL);
                }
            } else {
                if  ($box["version"] === 0) {
                    $this->printfBox($box, $indentSpace."  version:%d flags:%d  offsetSize:%d lengthSize:%d baseOffsetSize:%d".PHP_EOL);
                } else {
                    $this->printfBox($box, $indentSpace."  version:%d flags:%d  offsetSize:%d lengthSize:%d baseOffsetSize:%d indexSize:%d".PHP_EOL);
                }
                $box["itemCount"] = count($box["itemArray"]);
                $this->printfBox($box, $indentSpace."  itemCount:%d".PHP_EOL);
                foreach ($box["itemArray"] as $item) {
                    if  ($box["version"] === 0) {
                        $this->printfBox($item, $indentSpace."    itemID:%d dataReferenceIndex:%d baseOffset:%d".PHP_EOL);
                    } else {
                        $this->printfBox($item, $indentSpace."    itemID:%d constructionMethod:%d dataReferenceIndex:%d baseOffset:%d".PHP_EOL);
                    }
                    $item["extentCount"]  = count($item["extentArray"]);
                    $this->printfBox($item, $indentSpace."    extentCount:%d".PHP_EOL);
                    foreach ($item["extentArray"] as $extent) {
                        if ($box["version"] === 0) {
                            $this->printfBox($extent, $indentSpace."      extentOffset:%d extentLength:%d".PHP_EOL);
                        } else {
                            $this->printfBox($extent, $indentSpace."      extentOffset:%d extentIndex:%d extentLength:%d".PHP_EOL);
                        }
                    }
                }
            }
            break;
        case "auxC":
            $this->printfBox($box, $indentSpace."  version:%d flags:%d".PHP_EOL);
            $this->printfBox($box, $indentSpace."  auxType:%s".PHP_EOL);
            $this->printfBox($box, $indentSpace."  auxSubType:%s".PHP_EOL);
            break;
        case "auxl":
            $this->printfBox($box, $indentSpace."  fromItemID:%d".PHP_EOL);
            foreach ($box["itemArray"] as $item) {
                    $this->printfBox($item, $indentSpace."    itemID:%d".PHP_EOL);
            }
            break;
        default:
            $box2 = [];
            foreach ($box as $key => $data) {
                if (in_array($key, ["type", "(len)", "boxList", "_offset", "_length", "version", "flags"]) === false) {
                    $box2[$key] = $data;
                }
            }
            if (isset($box["version"])) {
                $this->printfBox($box, $indentSpace."  version:%d flags:%d".PHP_EOL);
            }
            $this->printTableRecursive($indentSpace."  ", $box2);
            break;
        }
        if (isset($box["boxList"])) {
            if (! empty($opts['hexdump'])) {
                $bit = new IO_Bit();
                $bit->input($this->_heifdata);
                $offset = $box["_offset"];
                $length = $box["boxList"][0]["_offset"] - $offset;
                $bit->hexdump($offset, $length);
            }
            $opts["indent"] += 1;
            $this->dumpBoxList($box["boxList"], $opts);
        } else {
            if (! empty($opts['hexdump'])) {
                $bit = new IO_Bit();
                $bit->input($this->_heifdata);
                $bit->hexdump($box["_offset"], $box["_length"]);
            }
        }
    }
    function printTableRecursive($indentSpace, $table) {
        foreach ($table as $key => $value) {
            if (is_array($value)) {
                echo $indentSpace."$key:\n";
                $this->printTableRecursive($indentSpace."  ", $value);
            } else {
                echo $indentSpace."$key:$value\n";
            }
        }
    }

    function printfBox($box, $format) {
        preg_match_all('/(\S+:[^%]*%\S+|\s+)/', $format, $matches);
        foreach ($matches[1] as $match) {
            if (preg_match('/(\S+):([^%]*)(%\S+)/', $match , $m)) {
                $f = $m[3];
                if ($f === "%h") {
                    printf($m[1].":".$m[2]);
                    foreach (str_split($box[$m[1]]) as $c) {
                        printf(" %02x", ord($c));
                    }
                } else {
                    printf($m[1].":".$m[2].$f, $box[$m[1]]);
                }
            } else {
                echo $match;
            }
        }
    }
    function removeBoxByType($removeTypeList) {
        $this->boxTree = $this->removeBoxByType_r($this->boxTree, $removeTypeList);
        // update baseOffset in iloc Box
    }
    function removeBoxByType_r($boxList, $removeTypeList) {
        foreach ($boxList as $idx => $box) {
            if (in_array($box["type"], $removeTypeList)) {
                unset($boxList[$idx]);
            } else if (isset($box["boxList"])) {
                $boxList[$idx]["boxList"] = $this->removeBoxByType_r($box["boxList"], $removeTypeList);
            }
        }
        return array_values($boxList);
    }
    function build($opts = array()) {
        // for iloc => mdat linkage
        $this->ilocBaseOffsetFieldList = []; // _mdatId, _offsetRelative, fieldOffset, fieldSize
        $this->mdatOffsetList = []; // _mdatId, _offset
        //

        $bit = new IO_Bit();
        $this->buildBoxList($bit, $this->boxTree, null, $opts);
        //
        foreach ($this->ilocBaseOffsetFieldList as $ilocBOField) {
            $_mdatId = $ilocBOField["_mdatId"];
            foreach ($this->mdatOffsetList as $mdatOffset) {
                if ($_mdatId === $mdatOffset["_mdatId"]) {
                    $_offsetRelative = $ilocBOField["_offsetRelative"];
                    $fieldOffset = $ilocBOField["fieldOffset"];
                    $baseOffsetSize = $ilocBOField["baseOffsetSize"];
                    $newOffset = $mdatOffset["_offset"] + $_offsetRelative;
                    // XXXn
                    switch ($baseOffsetSize) {
                    case 1:
                        $bit->setUI8($newOffset, $fieldOffset);
                        break;
                    case 2:
                        $bit->setUI16BE($newOffset, $fieldOffset);
                        break;
                    case 4:
                        $bit->setUI32BE($newOffset, $fieldOffset);
                        break;
                    default:
                        new Exception("baseOffsetSize:$baseOffsetSize not implement yet.");
                    }
                    break;
                }
            }
        }
        return $bit->output();
    }
    function buildBoxList($bit, $boxList, $parentType, $opts) {
        list($boxOffset, $dummy) = $bit->getOffset();
        foreach ($boxList as $box) {
            $this->buildBox($bit, $box, $parentType, $opts);
        }
        list($nextOffset, $dummy) = $bit->getOffset();
        return $nextOffset - $boxOffset;
    }
    function buildBox($bit, $box, $parentType, $opts) {
        list($boxOffset, $dummy) = $bit->getOffset();
        $bit->putUI32BE(0); // length field.
        $type = $box["type"];
        $bit->putData($type);
        //
        $origOffset = isset($box["_offset"])?$box["_offset"]:null;
        $origLength = isset($box["_length"])?$box["_length"]:null;
        $origDataOffset = $origOffset + 8;
        $origDataLength = $origLength - 8;
        if (! empty($opts["debug"])) {
            fwrite(STDERR, "DEBUG: buildBox: type:$type boxOffset:$boxOffset origOffset:$origOffset origLength:$origLength\n");
        }
        if (isset($box["boxList"])) {
            /*
             * container box
             */
            switch ($type) {
            case "iref":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["flags"] , 8 * 3);
                $dataLength = 4;
                break;
            case "iinf":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["flags"] , 8 * 3);
                // $count = $box["count"];
                $count = count($box["boxList"]);
                if ($box["version"] <= 1) {  // XXX: 0 or 1 ???
                    $bit->putUI16BE($count);
                    $dataLength = 6;
                } else {
                    $bit->putUI32BE($count);
                    $dataLength = 8;
                }
                break;
            case "dref":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["flags"] , 8 * 3);
                $bit->putUI32BE(count($box["boxList"]));
                $dataLength = 8;
                break;
            case "moov": // Movie Atoms
            case "trak":
            case "mdia":
            case "meta": // Metadata
            case "dinf": // data infomation
            case "iprp": // item properties
            case "ipco": // item property container
                if ($type === "meta") {
                    $bit->putUI8($box["version"]);
                    $bit->putUIBits($box["flags"] , 8 * 3);
                    $dataLength = 4;
                } else {
                    $dataLength = 0;
                }
                break;
            default:
                throw new Exception("buildBox: with BoxList type:$type not implemented yet. (boxOffset:$boxOffset)");
                break;
            }
            $dataLength += $this->buildBoxList($bit, $box["boxList"], $type, $opts);
        } else {
            /*
             * no container box (leaf node)
             */
            switch ($type) {
            case "ftyp":
                $bit->putData($box["major"], 4);
                if (! isset($box["minor"])) {
                    $box["minor"] = 0;
                }
                $bit->putUI32BE($box["minor"]);
                foreach ($box["alt"]  as $altData) {
                    $bit->putData($altData, 4);
                }
                break;
            case "hdlr":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["flags"], 8 * 3);
                $bit->putData($box["componentType"] , 4);
                $bit->putData($box["componentSubType"], 4);
                $bit->putData($box["componentManufacturer"], 4);
                $bit->putUI32BE($box["componentFlags"]);
                $bit->putUI32BE($box["componentFlagsMask"]);
                $bit->putData($box["componentName"]);
                break;
            case "iloc":
                if ($parentType === "iref") {
                    $bit->putUI16BE($box["itemID"]);
                    $itemCount = count($box["itemArray"]);
                    $bit->putUI16BE($itemCount);
                    foreach ($box["itemArray"] as $item) {
                        $bit->putUI16BE(item["itemID"]);
                    }
                } else {
                    $bit->putUI8($box["version"]);
                    $bit->putUIBits($box["flags"], 8 * 3);
                    $offsetSize = $box["offsetSize"];
                    $lengthSize = $box["lengthSize"];
                    $bit->putUIBits($offsetSize, 4);
                    $bit->putUIBits($lengthSize, 4);
                    $baseOffsetSize = $box["baseOffsetSize"]; // XXX
                    $bit->putUIBits($baseOffsetSize, 4);
                    if ($box["version"] === 0) {
                        if (isset($box["reserved"])) {
                            $bit->putUIBits($box["reserved"], 4);
                        } else {
                            $bit->putUIBits(0, 4);
                        }
                    } else {
                        $indexSize = $box["indexSize"];
                        $bit->putUIBits($indexSize, 4);
                    }
                    $itemCount = count($box["itemArray"]);
                    $bit->putUI16BE($itemCount);
                    foreach ($box["itemArray"] as $item) {
                        $bit->putUI16BE($item["itemID"]);
                        if ($box["version"] >= 1) {
                            $bit->putUI16BE($item["constructionMethod"]);
                        }
                        $bit->putUI16BE($item["dataReferenceIndex"]);
                        list($fieldOffset, $dummy) = $bit->getOffset();
                        $bit->putUIBits($item["baseOffset"], 8 * $baseOffsetSize);
                        $extentCount = count($item["extentArray"]);
                        $bit->putUI16BE($extentCount);
                        foreach ($item["extentArray"] as $extent) {
                            $bit->putUIBits($extent["extentOffset"], 8 * $offsetSize);
                            if ($box["version"] >= 1) {
                                $bit->putUIBits($extent["extentIndex"], 8 * $indexSize);
                            }
                            $bit->putUIBits($extent["extentLength"] , 8 * $lengthSize);
                        }
                        $this->ilocBaseOffsetFieldList []= [
                            "_mdatId" => $item["_mdatId"],
                            "_offsetRelative" => $item["_offsetRelative"],
                            "fieldOffset" => $fieldOffset,
                            "baseOffsetSize" => $baseOffsetSize,
                        ];
                    }
                }
                break;
            case "infe":
                 $bit->putUI8($box["version"]);
                 $bit->putUIBits($box["flags"], 8 * 3);
                 $bit->putUI16BE($box["itemID"]);
                 $bit->putUI16BE($box["itemProtectionIndex"]);
                 if ($box["version"] <= 1) {  // XXX: 0 or 1 ???
                     ;
                 } else {
                     $bit->putData($box["itemType"], 4);
                 }
                 $itemName = explode("\0", $box["itemName"])[0];
                 $bit->putData($itemName."\0");
                 if (isset($box["contentType"])) {
                     $contentType = explode("\0", $box["contentType"] )[0];
                     $bit->putData($contentType."\0");
                     if (isset($box["contentEncoding"])) {
                         $contentEncoding = explode("\0", $box["contentEncoding"] )[0];
                         $bit->getData($contentEncoding."\0");
                     }
                 }
                break;
            case "dimg":
                $bit->putUI16BE($box["fromItemID"]);
                $itemCount = count($box["itemArray"]);
                if ($box["itemCount"] !== $itemCount) {
                    throw new Exception("buildBox: box[itemCount]:{$box['itemCount']} != itemCount:$itemCount");
                }
                $bit->putUI16BE($itemCount);
                foreach ($box["itemArray"] as $item) {
                    $bit->putUI16BE($item["itemID"]);
                }
            break;
            case "url ":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["flags"], 8 * 3);
                $bit->putData($box["location"]);
                break;
            case "ispe":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["flags"], 8 * 3);
                $bit->putUI32BE($box["width"]);
                $bit->putUI32BE($box["height"]);
                break;
            case "pasp":
                $bit->putUI32BE($box["hspace"]);
                $bit->putUI32BE($box["vspace"]);
                break;
            case "pitm":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["flags"], 8 * 3);
                $bit->putUI16BE($box["itemID"]);
            break;
            case "hvcC":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["profileSpace"], 2);
                $bit->putUIBit($box["tierFlag"]);
                $bit->putUIBits($box["profileIdc"], 5);
                //
                $bit->putUI32BE($box["profileCompatibilityFlags"]);
                $bit->putUIBits($box["constraintIndicatorFlags"], 48);
                //
                $bit->putUI8($box["levelIdc"]);
                //
                $bit->putUIBits(0xF, 4); // reserved
                $bit->putUIBits($box["minSpatialSegmentationIdc"], 12);
                //
                $bit->putUIBits(0x3F, 6); // reserved
                $bit->putUIBits($box["parallelismType"], 2);
                //
                $bit->putUIBits(0x3F, 6); // reserved
                $bit->putUIBits($box["chromaFormat"], 2);
                //
                $bit->putUIBits(0x1F, 5); // reserved
                $bit->putUIBits($box["bitDepthLumaMinus8"], 3);
                //
                $bit->putUIBits(0x1F, 5); // reserved
                $bit->putUIBits($box["bitDepthChromaMinus8"], 3);
                //
                $bit->putUIBits($box["avgFrameRate"], 16);
                //
                $bit->putUIBits($box["constantFrameRate"], 2);
                $bit->putUIBits($box["numTemporalLayers"], 3);
                $bit->putUIBit($box["temporalIdNested"]);
                $bit->putUIBits($box["lengthSizeMinusOne"], 2);
                //
                $bit->putUI8(count($box["nalArrays"]));
                foreach ($box["nalArrays"] as $nal) {
                    $bit->putUIBit($nal["array_completeness"]);
                    $bit->putUIBit(0); // reserved
                    $bit->putUIBits($nal["NALUnitType"], 6);

                    $bit->putUI16BE(count($nal["nalus"]));
                    foreach ($nal["nalus"] as $nalu) {
                        $nalUnitLength = strlen($nalu["nalUnit"]);
                        $bit->putUI16BE($nalUnitLength);
                        $bit->putData($nalu["nalUnit"], $nalUnitLength);
                    }
                }
                break;
            case "clap":
                $bit->putSI32BE($box["width_N"]);
                $bit->putSI32BE($box["width_D"]);
                $bit->putSI32BE($box["height_N"]);
                $bit->putSI32BE($box["height_D"]);
                $bit->putSI32BE($box["horizOff_N"]);
                $bit->putSI32BE($box["horizOff_D"]);
                $bit->putSI32BE($box["vertOff_N"]);
                $bit->putSI32BE($box["vertOff_D"]);
                break;
            case "ipma":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["flags"], 8 * 3);
                $bit->putUI32BE(count($box["entryArray"]));
                foreach ($box["entryArray"] as $entry) {
                    $bit->putUI16BE($entry["itemID"]);
                    $bit->putUI8(count($entry["associationArray"]));
                    foreach ($entry["associationArray"] as $association) {
                        $bit->putUIBit($association["essential"]);
                        if ($box["flags"] & 1) {
                            $bit->putUIBits($association["propertyIndex"], 15);
                        }  else {
                            $bit->putUIBits($association["propertyIndex"], 7);
                        }
                    }
                }
                break;
            case "mdat":
                if (isset($box["_mdatId"])) {
                    $this->mdatOffsetList []= [
                        "_mdatId" => $box["_mdatId"],
                        "_offset" => $boxOffset,
                    ];
                } else {
                    fwrite(STDERR, "ERROR mdat no _mdatId".PHP_EOL);
                }
                if (isset($box["data"])) {
                    $data = $box["data"];
                } else {
                    $data = substr($this->_heifdata,
                                   $origDataOffset, $origDataLength);
                }
                $bit->putData($data);
                break;
            default:
                $data = substr($this->_heifdata, $origDataOffset, $origDataLength);
                $bit->putData($data);
                break;
            }
            list($currentOffset, $dummy) = $bit->getOffset();
            $dataLength = $currentOffset - ($boxOffset + 8);
        }
        $boxLength = 8 + $dataLength;
        $bit->setUI32BE($boxLength, $boxOffset);
    }
    function fromHEVC($hevcdata, $opts = array()) {
        $itemID = 1;
        //
        $hevc = new IO_HEIF_HEVC();
        $hevc->input($hevcdata);
        $mdatData = $hevc->getMDATdata();
        $offsetRelative = 8;
        $ftyp = ["type" => "ftyp",
                 "major" => "mif1", "alt" => ["mif1", "heic"] ];
        $mdat = ["type" => "mdat", "data" => $mdatData,
                 "_mdatId" => $itemID, "_offsetRelative" => $offsetRelative ];
        $hdlr = ["type" => "hdlr", "version" => 0, "flags" => 0,
                 "componentType" => "\0\0\0\0",
                 "componentSubType" => "pict",
                 "componentManufacturer" => "\0\0\0\0",
                 "componentFlags" => 0, "componentFlagsMask" => 0,
                 "componentName" => "IO_HEIF pict Handler\0" ];
        $pitm = ["type" => "pitm",  "version" => 0, "flags" => 0,
                 "itemID" => $itemID];
        $iloc = ["type" => "iloc",  "version" => 0, "flags" => 0,
                 "offsetSize" => 0, "lengthSize" => 4, "baseOffsetSize" => 4,
                 "itemArray" => [
                     ["itemID" => 1, "dataReferenceIndex" => 0,
                      "baseOffset" => 0,
                      "extentArray" => [
                          [ "extentOffset" => 0,
                            "extentLength" => strlen($mdatData) ]
                      ],
                      "_mdatId" => $itemID, "_offsetRelative" => $offsetRelative,
                     ],
                 ]];
        $iinf = ["type" => "iinf", "version" => 0, "flags" => 0,
                 "boxList" => [
                     ["type" => "infe", "version" => 2, "flags" => 0,
                      "itemID" => $itemID,
                      "itemProtectionIndex" => 0,
                      "itemType" => "hvc1",
                      "itemName" => "Image"]
                 ]];
        $ispe = $hevc->getISPE();
        $pasp = $hevc->getPASP();
        $hvcC = $hevc->getHEVCConfig();
        $ipma = ["type" => "ipma",
                 "version" => 0, "flags" => 0,
                 "entryArray" => [
                     ["itemID" => $itemID,
                      "associationArray" => [
                          ["essential" => 0, "propertyIndex" => 1],
                          ["essential" => 0, "propertyIndex" => 2],
                          ["essential" => 1, "propertyIndex" => 3]
                      ]]
                 ]];
        $iprp = ["type" => "iprp",
                 "boxList" => [
                     ["type" => "ipco",
                      "boxList" => [$ispe, $pasp, $hvcC] ],
                     $ipma
                 ]];
        $meta = ["type" => "meta", "version" => 0, "flags" => 0,
                 "boxList" => [$hdlr, $pitm, $iloc, $iinf, $iprp] ];
        $this->boxTree = [$ftyp, $mdat, $meta];
    }
    function toHEVC($opts = array()) {
        $buildInfo = $this->getHEIFBuildInfo($this->boxTree);
        $itemID = array_keys($buildInfo["ipma"])[0];
        $loc = $buildInfo["iloc"][$itemID];
        foreach ($buildInfo["hvcC"]["nals"] as $nal) {
            echo "\0\0\0\1".$nal;
        }
        $mdatBit = new IO_Bit();
        $mdatBit->input(substr($this->_heifdata,
                               $loc["baseOffset"], $loc["extentLength"]));

        while ($mdatBit->hasNextData(4)) {
            $len = $mdatBit->getUI32BE();
            if ($mdatBit->hasNextData($len)) {
                echo "\0\0\0\1".$mdatBit->getData($len);
            } else {
                break;
            }
        }
    }
    function getHEIFBuildInfo($boxList) {
        $buildInfo = array();
        foreach ($boxList as $box) {
            $buildInfo += $this->getHEIFBuildInfoBox($box);
        }
        return $buildInfo;
    }
    function getHEIFBuildInfoBox($box) {
        $buildInfo = array();
        switch ($box["type"]) {
        case "ipma":
            $entryArray = array();
            foreach ($box["entryArray"] as $entry) {
                $entryArray[$entry["itemID"]] = true;
            }
            $buildInfo += array("ipma" => $entryArray);
            break;
        case "iloc":
            $itemArray = array();
            foreach ($box["itemArray"] as $item) {
                $extentLength = 0;
                foreach ($item["extentArray"] as $extent) {
                    $extentLength += $extent["extentLength"];
                }
                $itemArray[$item["itemID"]] = array(
                    "baseOffset" => $item["baseOffset"],
                    "extentLength" => $extentLength,
                );
            }
            $buildInfo += array("iloc" => $itemArray);
            break;
        case "hvcC":
            $nalArray = array();
            foreach ($box["nalArrays"] as $nal) {
                $nalUnit = "";
                foreach ($nal["nalus"] as $nu) {
                    $nalUnit .= $nu["nalUnit"];
                }
                $nalArray[$nal["NALUnitType"]] = $nalUnit;
            }
            $buildInfo += array("hvcC" => array(
                "nals" => $nalArray,
            ));
            break;
        }
        if (isset($box["boxList"])) {
            $buildInfo += $this->getHEIFBuildInfo($box["boxList"]);
        }
        return $buildInfo;
    }
}
