<?php

/*
  IO_HEIF class
  (c) 2017/07/26 yoya@awm.jp
  ref) https://developer.apple.com/standards/qtff-2001.pdf
 */

require_once 'IO/Bit.php';
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
        "ipma" => "Item Properties Association",
    ];
    if (isset($getTypeDescriptionTable[$type])) {
        return $getTypeDescriptionTable[$type];
    }
    return null;
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
                    $offset = $item["baseOffset"];
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
        if ($boxLength < 8) {
            throw new Exception("parseBox: boxLength($boxLength) < 8");
        }
        $type = $bit->getData(4);
        $box = ["type" => $type, "_offset" => $boxOffset, "_length" => $boxLength];
        if (! empty($opts["debug"])) {
            fwrite(STDERR, "DEBUG: parseBox:$indentSpace type:$type offset:$boxOffset boxLength:$boxLength\n");
        }
        if ($bit->hasNextData($boxLength - 8) === false) {
            throw new Exception("parseBox: hasNext(boxLength:$boxLength - 8) === false (boxOffset:$boxOffset)");
        }
        $nextOffset = $boxOffset + $boxLength;
        $dataLen = $boxLength - 8; // 8 = len(4) + type(4)
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
            $box["conponentType"] = $bit->getData(4);
            $box["conponentSubType"] = $bit->getData(4);
            $box["conponentManufacturer"] = $bit->getData(4);
            $box["conponentFlags"] = $bit->getUI32BE();
            $box["conponentFlagsMask"] = $bit->getUI32BE();
            $box["conponentName"] = $bit->getData($dataLen - 24);
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
                var_dump($box);
                throw new Exception("reserved({$reserved}) !== 0xF");
            }
            $box["minSpatialSegmentationIdc"]  = $bit->getUIBits(12);
            $reserved = $bit->getUIBits(6);
            if ($reserved !== 0x3F) {
                var_dump($box);
                throw new Exception("reserved({$reserved}) !== 0x3F");
            }
            $box["parallelismType"]  = $bit->getUIBits(2);
            $reserved = $bit->getUIBits(6);
            if ($reserved !== 0x3F) {
                var_dump($box);
                throw new Exception("reserved({$reserved}) !== 0x3F");
            }
            $box["chromaFormat"]  = $bit->getUIBits(2);
            $reserved = $bit->getUIBits(5);
            if ($reserved !== 0x1F) {
                var_dump($box);
                throw new Exception("reserved({$reserved}) !== 0x1F");
            }
            $box["bitDepthLumaMinus8"]  = $bit->getUIBits(3);
            $reserved = $bit->getUIBits(5);
            if ($reserved !== 0x1F) {
                var_dump($box);
                throw new Exception("reserved({$reserved}) !== 0x1F");
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
                    throw new Exception("reserved({$reserved}) !== 0");
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
        $bit->setOffset($nextOffset, 0);
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
            $this->printfBox($box, $indentSpace."  itemName:%s contentType:%s contentEncoding:%s".PHP_EOL);
            break;
        case "pasp":
            echo $indentSpace."  hspace:".$box["hspace"]." vspace:".$box["vspace"].PHP_EOL;
            break;
        case "hvcC":
            $profileIdc = $box["profileIdc"];
            $profileIdcStr = ($profileIdc===1)?"Main profile":(($profileIdc===2)?"Main10 profile":"unknown profile");
            $this->printfBox($box, $indentSpace."  version:%d profileSpace:%d tierFlag:%x profileIdc:%d");
            echo "($profileIdcStr)".PHP_EOL;
            $this->printfBox($box, $indentSpace."  profileCompatibilityFlags:0x%x".PHP_EOL);
            $this->printfBox($box, $indentSpace."  constraintIndicatorFlags:0x%x levelIdc:%d".PHP_EOL);
            $this->printfBox($box, $indentSpace."  minSpatialSegmentationIdc:%d parallelismType:%d".PHP_EOL);
            $this->printfBox($box, $indentSpace."  chromaFormat:%d bitDepthLumaMinus8:%d bitDepthChromaMinus8:%d".PHP_EOL);
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
        $origOffset = $box["_offset"];
        $origLength = $box["_length"];
        $origDataOffset = $origOffset + 8;
        $origDataLength = $origLength - 8;
        if (! empty($opts["debug"])) {
            fwrite(STDERR, "DEBUG: buildBox: type:$type boxOffset:$boxOffset origOffset:$origOffset origLength:$origLength\n");
        }
        if (isset($box["boxList"])) {
            switch ($type) {
            case "iref":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["flags"] , 8 * 3);
                $dataLength = 4;
                break;
            case "iinf":
                $bit->putUI8($box["version"]);
                $bit->putUIBits($box["flags"] , 8 * 3);
                $count = $box["count"];
                if ($box["version"] <= 1) {  // XXX: 0 or 1 ???
                    $bit->putUI16BE($count);
                    $dataLength = 6;
                } else {
                    $bit->putUI32BE($count);
                    $dataLength = 8;
                }
                break;
            case "moov": // Movie Atoms
            case "trak":
            case "mdia":
            case "meta": // Metadata
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
            switch ($type) {
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
                        $bit->putUIBits($box["reserved"], 4);
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
            default:
                if ($type === "mdat") {
                    // _mdatId, _offset
                    $this->mdatOffsetList []= [
                        "_mdatId" => $box["_mdatId"],
                        "_offset" => $box["_offset"],
                    ];
                }
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
        $ftyp = ["type" => "ftyp",
                 "major" => "mif1", "alt" => ["mif1", "heic"] ];
        $mdat = ["type" => "mdat", "data" => $mdatData,
                 "_mdatId" => $itemID, "_offsetRelative" => 0 ];
        $hdlr = ["type" => "hdlr", "version" => 0, "flags" => 0,
                 "conponentType" => "\0\0\0\0",
                 "conponentSubType" => "pict",
                 "conponentManufacturer" => "\0\0\0\0",
                 "conponentFlags" => 0, "conponentFlagsMask" => 0,
                 "conponentName" => "IO_HEIF pict Handler\0" ];
        $iloc = ["type" => "iloc",  "version" => 0, "flags" => 0,
                 "offsetSize" => 0, "lengthSize" => 4, "baseOffsetSize" => 4,
                 "itemArray" => [
                     ["itemID" => 1, "dataReferenceIndex" => 0,
                      "baseOffset" => 0,
                      "extentArray" => [
                          [ "extentOffset" => 0,
                            "extentLength" => strlen($mdatData) ]
                      ],
                      "_mdatId" => $itemID, "_offsetRelative" => 0
                     ],
                 ]];
        $iinf = ["type" => "iinf", "version" => 0, "flags" => 0,
                 "boxList" => [
                     ["type" => "infe", "version" => 2, "flags" => 0,
                      "itemID" => $itemID,
                      "itemProtectionIndex" => 0,
                      "itemName" => "Image",
                      "contentType" => "contentEncoding"]
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
                      "boxList" => [$ispe, $pasp, $hvcC, $ipma] ]
                 ]];
        $meta = ["type" => "meta", "version" => 0, "flags" => 0,
                 "boxList" => [$hdlr, $iloc, $iinf, $iprp] ];
        $this->boxTree = [$ftyp, $mdat, $meta];
    }
}
