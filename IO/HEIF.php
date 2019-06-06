<?php

/*
  IO_HEIF class - v2.0
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
        $buildInfo = $this->getHEIFBuildInfo($this->boxTree);
        $itemID = array_keys($buildInfo["ipma"])[0];
        $loc = $buildInfo["iloc"][$itemID];
        foreach ($buildInfo["hvcC"]["nals"] as $nal) {
            echo "\0\0\0\1".$nal;
        }
        $mdatBit = new IO_Bit();
        $mdatBit->input(substr($this->_isobmffData,
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
