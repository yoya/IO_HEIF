<?php

/*
  IO_HEIF class - v2.3
  (c) 2017/07/26 yoya@awm.jp
  ref) https://mpeg.chiariglione.org/standards/mpeg-h/image-file-format
 */

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/Bit.php';
    require_once 'IO/ISOBMFF.php';
}
require_once dirname(__FILE__).'/HEIF/HEVC.php';

class IO_HEIF extends IO_ISOBMFF {
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
        $buildInfo = $this->getHEVCBuildInfo($this->boxTree);
        if (isset($opts["ItemID"])) {
            $itemID = $opts["ItemID"];
        } else if (isset($opts["RoleType"])) {
            switch ($opts["RoleType"]) {
            case "master": // primary ???
            case "primary":
            case "pitm":
                $pitmArr = $this->getBoxesByTypes(["pitm"]);
                $itemID = $pitmArr[0]["itemID"];
                break;
            case "thumbnail":
            case "thmb":
                $thmbArr = $this->getBoxesByTypes(["thmb"]);
                if (count($thmbArr) !== 1) {
                    throw new Exception("toHEVC: count(thmbArr) must be 1");
                }
                $itemID = $thmbArr[0]["itemID"];
                break;
            case "auxiliary":
            case "auxl":
            case "aux":
                // urn:mpeg:hevc:2015:auxid:1 - transparent
                // urn:mpeg:hevc:2015:auxid:2 - depthmap
                // iphone depthmap
                // urn:com:apple:photo:2018:aux:portraiteffectsmatte"
                if (! isset($opts["urn"])) {
                    throw new Exception("toHEVC: auxiliary need to specify urn");
                }
                $auxCBoxes = $buildInfo["auxC"];
                foreach ($auxCBoxes as $i => $auxC) {
                    if ($auxC["auxType"] == $opts["urn"]) {
                        $itemID = $i;
                        break;
                    }
                }
                break;
            default:
                throw new Exception("toHEVC: unknown role type:".$opts["RoleType"]);
            } 
        }
        if (is_null($itemID)) {
            throw new Exception("toHEVC: can't found itemID");
        }
        $loc = $buildInfo["iloc"][$itemID];
        $hvcC = $buildInfo["hvcC"][$itemID];
        foreach ($hvcC["nals"] as $nal) {
            echo "\0\0\0\1".$nal;
        }
        $mdatBit = new IO_Bit();
        $mdatBit->input(substr($this->_isobmffData,
                               $loc["offset"], $loc["length"]));
        while ($mdatBit->hasNextData(4)) {
            $len = $mdatBit->getUI32BE();
            if ($mdatBit->hasNextData($len)) {
                echo "\0\0\0\1".$mdatBit->getData($len);
            } else {
                // throw new Exception("toHEVC: imcomplete media data");
            }
        }
    }
    function getHEVCBuildInfo($boxList) {
        $buildInfo = $this->getHEVCBuildInfoBoxList($boxList);
        $ipco = $buildInfo["ipco"];
        $hvcCBoxes = [];
        $auxCBoxes = [];
        foreach ($buildInfo["ipma"] as $itemID => $propIndices) {
            foreach ($propIndices as $index) {
                $box = $ipco[$index];
                switch ($box["type"]) {
                case "hvcC":
                    $hvcCBoxes[$itemID] = $box;
                    break;
                case "auxC":
                    $auxCBoxes[$itemID] = $box;
                    break;
                }
            }
        }
        $buildInfo["hvcC"] = $hvcCBoxes;
        $buildInfo["auxC"] = $auxCBoxes;
        return $buildInfo;
    }

    // bi-direct recursive call with getHEVCBuildInfoBox
    function getHEVCBuildInfoBoxList($boxList) {
        $buildInfo = array();
        foreach ($boxList as $box) {
            $buildInfo += $this->getHEVCBuildInfoBox($box);
        }
        return $buildInfo;

    }
    function getHEVCBuildInfoBox($box) {
        $buildInfo = array();
        switch ($box["type"]) {
        case "ipco":
            $propArray = [];
            foreach ($box["boxList"] as $index_minus1 => $propBox) {
                $prop = null;
                $type = $propBox["type"];
                switch ($type) {
                case "hvcC":
                    $nalArray = array();
                    foreach ($propBox["nalArrays"] as $nal) {
                        $nalUnit = "";
                        foreach ($nal["nalus"] as $nu) {
                            $nalUnit .= $nu["nalUnit"];
                        }
                        $nalArray[$nal["NALUnitType"]] = $nalUnit;
                    }
                    $prop = ["type" => $type, "nals" => $nalArray,
                             "profile" => $propBox["profileIdc"],
                             "chrome" => $propBox["chromaFormat"]];
                    break;
                case "ispe":
                    $prop = ["type" => $type,
                             "width"  => $propBox["width"],
                             "height" => $propBox["height"]];
                    break;
                case "auxC":
                    $prop = ["type" => $type,
                             "auxType" => $propBox["auxType"],
                             "auxSubType" => $propBox["auxSubType"]];
                    break;
                default:
                    //$prop = ["type" => $type, "box" => $propBox];
                    $prop = ["type" => $type];
                    break;
                }
                if (! is_null($prop)) {
                    $index = $index_minus1 + 1; // index is 1-origin
                    $propArray[$index] = $prop;
                }
            }
            $buildInfo += array("ipco" => $propArray);
            break;
        case "ipma":
            $entryArray = array();
            foreach ($box["entryArray"] as $entry) {
                $assocArray = array();
                foreach ($entry["associationArray"] as $assoc) {
                    $assocArray[] = $assoc["propertyIndex"];
                }
                $entryArray[$entry["itemID"]] = $assocArray;
            }
            $buildInfo += array("ipma" => $entryArray);
            break;
        case "iloc":
            $itemArray = array();
            foreach ($box["itemArray"] as $item) {
                $baseOffset = $item["baseOffset"];
                $extentLength = 0;
                foreach ($item["extentArray"] as $extent) {
                    $extentLength += $extent["extentLength"];
                }
                $itemArray[$item["itemID"]] = array(
                    "offset" => $baseOffset + $extent["extentOffset"],
                    "length" => $extentLength,
                );
            }
            $buildInfo += array("iloc" => $itemArray);
            break;
        }
        if (isset($box["boxList"])) {
            $buildInfo += $this->getHEVCBuildInfoBoxList($box["boxList"]);
        }
        return $buildInfo;
    }
}
