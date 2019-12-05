<?php

/*
  IO_HEVC class
  (c) 2017/10/11 yoya@awm.jp
  ref) https://www.itu.int/rec/T-REC-H.265
 */

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/HEVC.php';
    require_once 'IO/HEVC/Bit.php';
}


class IO_HEIF_HEVC {
    var $naluList;
    function input($hevcdata) {
        $hevc = new IO_HEVC();
        $hevc->parse($hevcdata);
        $this->hevc = $hevc;
    }
    function getMDATdata() {
        $idrData = $this->hevc->getNALRawDataByType(19);      // IDR_W_RADL
        if (is_null($idrData)) {
            $idrData = $this->hevc->getNALRawDataByType(20);  // IDR_N_LP
        }
        if (is_null($idrData)) {
            throw new Exception("getMDATdata: IDR_W_RADL and IDR_N_LP are missing");
        }
        $idrDataLenData = pack("N", strlen($idrData));
        $mdatData = $idrDataLenData . $idrData;
        /*
        $freeData = $this->hevc->getNALRawDataByType(39); // PREFIX_SEI_NUT
        if ($freeData) {
            $freeDataLenData = pack("N", strlen($freeData));
            $mdatData = $freeDataLenData . $freeData . $mdatData;
        }
        */
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
        $bit_hevc = new IO_HEVC_Bit();
        $bit_hevc->input($spsData);
        /*
          -  SPS header
          f1 forbidden_zero_bit
          u6 nal_unit_type
          u6 nuh_layer_id
          u3 nuh_temporal_id_plus1
          -  SPS unit
          u4 sps_video_parameter_set_id
          u3 sps_max_sub_layers_minus1
          u1 sps_temporal_id_nesting_flag
          - ProfileTierLevel
          u2 general_profile_space
          u1 general_tier_flag
          u5 general_profile_idc
          u32 general_profile_compatibility_flag(s)
          u48 (constraintIndicatorFlags)
        */
        $bit_hevc->incrementOffset(4, 0);
        $profileCompatibilityFlags = $bit_hevc->getUIBits(32);
        $constraintIndicatorFlags = $bit_hevc->getUIBits(48);
        return ["type" => "hvcC",
                "version" => 1,
                "profileSpace" => $profile_tier_level->general_profile_space,
                "tierFlag" => $profile_tier_level->general_tier_flag,
                "profileIdc" => $profile_tier_level->general_profile_idc,
                "profileCompatibilityFlags" => $profileCompatibilityFlags,
                "constraintIndicatorFlags" => $constraintIndicatorFlags,
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
                    [ "array_completeness" => 1, "NALUnitType" => 32,
                      "nalus" => [["nalUnit" => $vpsData]] ],
                    [ "array_completeness" => 1, "NALUnitType" => 33,
                      "nalus" => [["nalUnit" => $spsData]] ],
                    [ "array_completeness" => 1, "NALUnitType" => 34,
                      "nalus" => [["nalUnit" => $ppsData]] ],
                ],
        ];
    }
}
