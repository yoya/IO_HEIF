<?php

/*
  IO_HEIF class
  (c) 2017/07/26 yoya@awm.jp
  ref) https://developer.apple.com/standards/qtff-2001.pdf
 */

require_once 'IO/Bit.php';

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
    function parse($heifdata) {
        $bit = new IO_Bit();
        $bit->input($heifdata);
        $this->_heifdata = $heifdata;
        $this->boxTree = $this->parseBoxList($heifdata, 0);
    }
    function parseBoxList($data, $baseOffset) {
        // echo "parseBoxList(".strlen($data).")\n";
        $bit = new IO_Bit();
        $bit->input($data);
        $boxList = [];
        while ($bit->hasNextData(8)) {
            list($offset, $dummy) = $bit->getOffset();
            $len = $bit->getUI32BE();
            if ($len === 0) {
                echo "len == 0\n";
                continue;
            }
            if ($len < 8) {
                echo "len($len) < 8\n";
                break;
            }
            $type = $bit->getData(4);
            // echo "$type($len): " . substr($data, 0, 4) . "\n";
            $data = $bit->getData($len - 8);
            $box = $this->parseBox($type, $data, $baseOffset + $offset + 8);
            $box["_offset"] = $baseOffset + $offset;
            list($offsetNext, $dummy) = $bit->getOffset();
            $box["_length"] = $offsetNext - $offset;
            $boxList []= $box;
        }
        return $boxList;
    }
    
    function parseBox($type, $data, $baseOffset) {
        // echo "parseBox: $type(". strlen($data) . "):". substr($data, 0, 4) . "\n";
        $box = ["type" => $type, "(len)" => strlen($data)];
        switch($type) {
        case "ftyp":
            $types = str_split($data, 4);
            $box["major"] = $types[0];
            $box["minor"] = unpack("N", $types[1])[1];
            $box["alt"] = array_slice($types, 2);
            break;
        case "hdlr":
            $box["version"] = ord($data[0]);
            $box["flags"] = unpack("N", "\0".substr($data, 1, 3))[1];
            $box["conponentType"] = substr($data, 4, 4);
            $box["conponentSubType"] = substr($data, 8, 4);
            $box["conponentManufacturer"] = substr($data, 12, 4);
            $box["conponentFlags"] = unpack("N", substr($data, 16, 4))[1];
            $box["conponentFlagsMask"] = unpack("N", substr($data, 20, 4))[1];
            $box["conponentName"] = substr($data, 24);
            break;
        case "mvhd":
            $box["version"] = ord($data[0]);
            $box["flag"] = unpack("N", "\0".substr($data, 1, 3))[1];
            $box["creationTime"] = unpack("N", substr($data, 4, 4))[1];
            $box["modificationTime"] = unpack("N", substr($data, 8, 4))[1];
            $box["timeScale"] = unpack("N", substr($data, 12, 4))[1];
            $box["duration"] = unpack("N", substr($data, 16, 4))[1];
            $box["preferredRate"] = unpack("N", substr($data, 20, 4))[1];
            $box["preferredVolume"] = unpack("N", substr($data, 24, 2))[1];
            $box["reserved"] =substr($data, 26, 10);
            $box["MatrixStructure"] = unpack("N*", substr($data, 36, 36));
            $box["previewTime"] = unpack("N", substr($data, 72, 4))[1];
            $box["peviewDuration"] = unpack("N", substr($data, 76, 4))[1];
            $box["posterTime"] = unpack("N", substr($data, 80, 4))[1];
            $box["selectionTime"] = unpack("N", substr($data, 84, 4))[1];
            $box["selectionDuration"] = unpack("N", substr($data, 88, 4))[1];
            $box["currentTime"] = unpack("N", substr($data, 92, 4))[1];
            $box["nextTrackID"] = unpack("N", substr($data, 96, 4))[1];
            break;
        case "tkhd":
            $box["version"] = ord($data[0]);
            $box["flag"] = unpack("N", "\0".substr($data, 1, 3))[1];
            $box["creationTime"] = unpack("N", substr($data, 4, 4))[1];
            $box["modificationTime"] = unpack("N", substr($data, 8, 4))[1];
            $box["trackId"] = unpack("N", substr($data, 12, 4))[1];
            $box["reserved"] =substr($data, 16, 4);
            $box["duration"] = unpack("N", substr($data, 20, 4))[1];
            $box["reserved"] =substr($data, 24, 8);
            $box["layer"] = unpack("N", substr($data, 32, 2))[1];
            $box["alternat4eGroup"] = unpack("N", substr($data, 34, 2))[1];
            break;
        case "ispe":
            $box["version"] = ord($data[0]);
            $box["flags"] = unpack("N", "\0".substr($data, 1, 3))[1];
            $box["width"]  = unpack("N", substr($data, 4, 4))[1];
            $box["height"] = unpack("N", substr($data, 8, 4))[1];
            break;
        case "pasp":
            $box["hspace"]  = unpack("N", substr($data, 0, 4))[1];
            $box["vspace"] = unpack("N", substr($data, 4, 4))[1];
            break;
        case "hvcC":
            // https://gist.github.com/yohhoy/2abc28b611797e7b407ae98faa7430e7
            $hb = new IO_Bit();
            $hb->input($data);
            $box["version"]  = $hb->getUI8();
            $box["profileSpace"]  = $hb->getUIBits(2);
            $box["tierFlag"]  = $hb->getUIBit();
            $box["profileIdc"]  = $hb->getUIBits(5);
            $box["profileCompatibilityFlags"]  = $hb->getUI32BE();
            $box["constraintIndicatorFlags"]  = $hb->getUIBits(48);
            $box["levelIdc"]  = $hb->getUI8();
            $reserved = $hb->getUIBits(4);
            if ($reserved !== 0xF) {
                var_dump($box);
                throw new Exception("reserved({$reserved}) !== 0xF");
            }
            $box["minSpatialSegmentationIdc"]  = $hb->getUIBits(12);
            $reserved = $hb->getUIBits(6);
            if ($reserved !== 0x3F) {
                var_dump($box);
                throw new Exception("reserved({$reserved}) !== 0x3F");
            }
            $box["parallelismType"]  = $hb->getUIBits(2);
            $reserved = $hb->getUIBits(6);
            if ($reserved !== 0x3F) {
                var_dump($box);
                throw new Exception("reserved({$reserved}) !== 0x3F");
            }
            $box["chromaFormat"]  = $hb->getUIBits(2);
            $reserved = $hb->getUIBits(5);
            if ($reserved !== 0x1F) {
                var_dump($box);
                throw new Exception("reserved({$reserved}) !== 0x1F");
            }
            $box["bitDepthLumaMinus8"]  = $hb->getUIBits(3);
            $reserved = $hb->getUIBits(5);
            if ($reserved !== 0x1F) {
                var_dump($box);
                throw new Exception("reserved({$reserved}) !== 0x1F");
            }
            $box["bitDepthChromaMinus8"]  = $hb->getUIBits(3);
            $box["avgFrameRate"]  = $hb->getUIBits(16);
            $box["constantFrameRate"]  = $hb->getUIBits(2);
            $box["numTemporalLayers"]  = $hb->getUIBits(3);
            $box["temporalIdNested"]  = $hb->getUIBit();
            $box["lengthSizeMinusOne"]  = $hb->getUIBits(2);
            
            $box["numOfArrays"] = $numOfArrays = $hb->getUI8();
            $nalArrays = [];
            for ($i = 0 ; $i < $numOfArrays ; $i++) {
                $nal = [];
                $nal["array_completeness"] = $hb->getUIBit();
                $reserved = $hb->getUIBit();
                if ($reserved !== 0) {
                    var_dump($box);
                    var_dump($nalArrays);
                    throw new Exception("reserved({$reserved}) !== 0");
                }
                $nal["NALUnitType"] = $hb->getUIBits(6);
                $nal["numNalus"] = $numNalus = $hb->getUI16BE();
                $nalus = [];
                for ($j = 0 ; $j < $numNalus ; $j++) {
                    $nalu = [];
                    $nalu["nalUnitLength"] = $nalUnitLength = $hb->getUI16BE();
                    $nalu["nalUnit"] = $hb->getData($nalUnitLength);
                    $nalus []= $nalu;
                }
                $nal["nalus"] = $nalus;
                $nalArrays []= $nal;
            }
            $box["nalArrays"] = $nalArrays;
            // $box[""]  = $hb->getUIBits();
            // $box[""]  = $hb->getUI();
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
                $box["version"] = ord($data[0]);
                $box["flags"] = unpack("N", "\0".substr($data, 1, 3))[1];
                $containerData = substr($data, 4);
                $box["boxList"] = $this->parseBoxList($containerData, $baseOffset + 4);
            } else {
                $containerData = $data;
                $box["boxList"] = $this->parseBoxList($containerData, $baseOffset);
            }
            break;
        default:
        }
        return $box;
    }
    function dump($opts = Array()) {
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
        echo $indentSpace."type:".$type."(".$box["(len)"]."):";
        $desc = getTypeDescription($type);
        if ($desc) {
            echo $desc;
        }
        echo "\n";
        switch ($type) {
        case "meta":
            break;
        case "ftyp":
            echo $indentSpace."  major:".$box["major"]." minor:".$box["minor"];
            echo "  alt:".join(", ", $box["alt"]).PHP_EOL;
            break;
        case "ispe":
            echo $indentSpace."  version:".$box["version"]." flags:".$box["flags"];
            echo "  width:".$box["width"]." height:".$box["height"].PHP_EOL;
            break;
        case "pasp":
            echo $indentSpace."  hspace:".$box["hspace"]." vspace:".$box["vspace"].PHP_EOL;
            break;
        case "hvcC":
            $this->printfBox($box, $indentSpace."  version:%d profileSpace:%d tierFlag:%x profileIdc:%d".PHP_EOL);
            $this->printfBox($box, $indentSpace."  profileCompatibilityFlags:0x%x".PHP_EOL);
            $this->printfBox($box, $indentSpace."  constraintIndicatorFlags:0x%x levelIdc:%d".PHP_EOL);
            $this->printfBox($box, $indentSpace."  minSpatialSegmentationIdc:%d parallelismType:%d".PHP_EOL);
            $this->printfBox($box, $indentSpace."  chromaFormat:%d bitDepthLumaMinus8:%d bitDepthChromaMinus8:%d".PHP_EOL);
            $this->printfBox($box, $indentSpace."  avgFrameRate:%d constantFrameRate:%d".PHP_EOL);
            $this->printfBox($box, $indentSpace."  numTemporalLayers:%d temporalIdNested:%d lengthSizeMinusOne:%d".PHP_EOL);
            foreach ($box["nalArrays"] as $nal) {
                $this->printfBox($nal, $indentSpace."    array_completeness:%d NALUnitType:%d".PHP_EOL);
                foreach ($nal["nalus"] as $nalu) {
                    $this->printfBox($nalu, $indentSpace."      nalUnitLength:%d nalUnit:%h".PHP_EOL);
                }
            }
            break;
        default:
            $box2 = [];;
            foreach ($box as $key => $data) {
                if (in_array($key, ["type", "(len)", "boxList", "_offset", "_length"]) === false) {
                    $box2[$key] = $data;
                }
            }
            foreach ($box2 as $key => $data) {
                if (is_array($data)) {
                    echo $indentSpace."  $key:\n";
                    foreach ($data as $k => $v) {
                        echo $indentSpace."    $k:$v\n";
                    }
                } else {
                    echo $indentSpace."  $key:$data\n";
                }
            }
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
    function build($opts = array()) {
        ;
    }
}
