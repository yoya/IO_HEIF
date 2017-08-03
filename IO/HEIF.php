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
        $this->boxTree = $this->parseBoxList($heifdata);
    }
    function parseBoxList($data) {
        // echo "parseBoxList(".strlen($data).")\n";
        $bit = new IO_Bit();
        $bit->input($data);
        $boxList = [];
        while ($bit->hasNextData(8)) {
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
            $boxList []= $this->parseBox($type, $data);
        }
        return $boxList;
    }
    
    function parseBox($type, $data) {
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
        case "mvhd__":
            $box["version"] = ord($data[0]);
            $box["flag"] = unpack("N", "\0".substr($data, 1, 3))[1];
            $box["creationTime"] = unpack("N", substr($data, 4, 4))[1];
            // ...
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
            } else {
                $containerData = $data;
            }
            $box["boxList"] = $this->parseBoxList($containerData);
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
        default:
            $box2 = [];;
            foreach ($box as $key => $data) {
                if (in_array($key, ["type", "(len)", "boxList"]) === false) {
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
            $opts["indent"] += 1;
            $this->dumpBoxList($box["boxList"], $opts);
        }
    }
    function build($opts = array()) {
        ;
    }
}
