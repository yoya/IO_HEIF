<?php

/*
  IO_HEVC class
  (c) 2017/10/11 yoya@awm.jp
  ref) https://www.itu.int/rec/T-REC-H.265
 */

require_once 'IO/HEVC.php';

class IO_HEIF_HEVC {
    var $naluList;
    function input($hevcdata) {
        $hevc = new IO_HEVC();
        $hevc->parse($hevcdata);
        $this->hevc = $hevc;
    }
    function getMDATdata() {
        $idrData = $this->hevc->getNALRawDataByType(19); // IDR_W_RADL
        $idrDataLenData = pack("N", strlen($idrData));
        $mdatData = $idrDataLenData . $idrData;
        $freeData = $this->hevc->getNALRawDataByType(39); // PREFIX_SEI_NUT
        if ($freeData) {
            $freeDataLenData = pack("N", strlen($freeData));
            $mdatData = $freeDataLenData . $freeData . $mdatData;
        }
        return $mdatData;
    }
    function getISPE() {
        $sps = $this->hevc->getNALByType(33); // SPS_NUT
        return ["type" => "ispe", "version" => 0, "flags" => 0,
                "width" => $sps->unit->pic_width_in_luma_samples,
                "height" => $sps->unit->pic_height_in_luma_samples ];
    }
    function getPASP() {
        return ["type" => "pasp",
                "hspace" => 1, "vspace" => 1]; // XXX
    }
    function getHEVCConfig() {
        $sps = $this->hevc->getNALByType(33); // SPS_NUT
        $vpsData = $this->hevc->getNALRawDataByType(32); // VPS_NUT (RawData)
        $spsData = $this->hevc->getNALRawDataByType(33); // SPS_NUT (RawData)
        $ppsData = $this->hevc->getNALRawDataByType(34); // PPS_NUT (RawData)
        $sps_unit = $sps->unit;
        $profile_tier_level = $sps_unit->profile_tier_level;
        $profileCompatibilityFlagsBit = new IO_Bit();
        $profileCompatibilityFlags = 0;
        foreach ($profile_tier_level->general_profile_compatibility_flag as $flag) {
            $profileCompatibilityFlags <<= 1;
            $profileCompatibilityFlags |= $flag;
        }

        return ["type" => "hvcC",
                "version" => 1,
                "profileSpace" => $profile_tier_level->general_profile_space,
                "tierFlag" => $profile_tier_level->general_tier_flag,
                "profileIdc" => $profile_tier_level->general_profile_idc,
                "profileCompatibilityFlags" => $profileCompatibilityFlags,
                "constraintIndicatorFlags" => 0x900000000000,
                "levelIdc" => $profile_tier_level->general_level_idc,
                "minSpatialSegmentationIdc" => 0,
                "parallelismType" => 3,
                "chromaFormat" => $sps_unit->chroma_format_idc,
                "bitDepthLumaMinus8" => $sps_unit->bit_depth_luma_minus8,
                "bitDepthChromaMinus8" => $sps_unit->bit_depth_chroma_minus8,
                "avgFrameRate" => 0,
                "constantFrameRate" => 0,
                "numTemporalLayers" => 1,
                "temporalIdNested" => 1,
                "lengthSizeMinusOne" => 3,
                "nalArrays" => [
                    [ "array_completeness" => 1,
                      "NALUnitType" => 32,
                      "nalus" => [["nalUnit" => $vpsData]] ],
                    [ "array_completeness" => 1,
                      "NALUnitType" => 33,
                      "nalus" => [["nalUnit" => $spsData]] ],
                    [ "array_completeness" => 1,
                      "NALUnitType" => 34,
                      "nalus" => [["nalUnit" => $ppsData]] ],
                ],
        ];
    }
}
