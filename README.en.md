IO_HEIF
=======

This is a library for interpreting/editing HEIF (HEIC) binaries.
`IO_HEIF` extends `IO_ISOBMFF` from IO_ISOBMFF; IO_ISOBMFF does the box parsing, writing and dumping.
What `IO_HEIF` adds is conversion with HEVC (H.265): `fromHEVC()` makes a single-image HEIC from an HEVC byte stream, and `toHEVC()` extracts a HEIC item as an HEVC byte stream.
It requires IO_Bit, IO_ISOBMFF, IO_HEVC and IO_ICC.
Not limited in use, free to copy and modify by MIT license.
Please contact me if it doesn't work in your environment or you want new features.

# License

MIT License

# Install

```
% composer require yoya/io_heif
```

# Usage

- heiffromhevc.php (HEVC to HEIC)

Makes a single-image HEIC from the first IDR picture of an HEVC byte stream (Annex B format).
On PHP 8.2 or later, IO_HEVC prints Deprecated messages to standard output, which corrupts the output, so add `-d error_reporting='E_ALL & ~E_DEPRECATED'`.

```
% php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/yoya/io_heif/sample/heiffromhevc.php -f input.hevc > output.heic
```

- heiftree.php (list of properties and items)

```
% php vendor/yoya/io_heif/sample/heiftree.php -f output.heic
Props:
[1]: ispe width:64 height:64
[2]: pasp
[3]: hvcC profile:1(Main profile) level:30 chroma:1(YUV420)
    compatibility:0x60000000 constraint:0x900000000000 bitdepth,8:8
    naltype:32 (len:24) 40 01 0C 01 FF FF 01 60 00 00 03 00 90 00 00 03 ...
    naltype:33 (len:40) 42 01 01 01 60 00 00 03 00 90 00 00 03 00 00 03 ...
    naltype:34 (len:7) 44 01 C1 72 B4 22 40 
Items:
[1]: pitm type:hvc1 ref:0 offset:32 length:460
    (mdat:0x20,0x1cc) 00 00 01 C8 28 01 AF 1D 80 C2 4B B2 63 FC 54 92 ...
    [1?]ispe:64,64 [2?]pasp [3]hvcC:1,30,1 
Warning:
```

- heiftohevc.php (HEIC to HEVC)

Without an item, it lists the items. With an item ID (`-i <item_id>`) or a role (`-r`), it writes that item as an HEVC byte stream (the VPS/SPS/PPS of hvcC and the mdat data).
`-r` accepts `master` (`primary`, `pitm`), `thumbnail` (`thmb`) and `aux` (`auxl`, `auxiliary`; give the auxC type with `-u <urn>`).

```
% php vendor/yoya/io_heif/sample/heiftohevc.php -f output.heic
ItemID:1 infe:hvc1 pitm
% php vendor/yoya/io_heif/sample/heiftohevc.php -f output.heic -r master > output.hevc
```

- heifdump.php / heifitems.php / heifprops.php / heifrebuild.php / heifdelbox.php / heifaddiccp.php

These are the same as IO_ISOBMFF's isobmff*.php (only the class is `IO_HEIF`). See the IO_ISOBMFF README for their usage.

```
% php vendor/yoya/io_heif/sample/heifprops.php -f output.heic -t
ispe  index:1
pasp  index:2
hvcC  index:3
% php vendor/yoya/io_heif/sample/heifaddiccp.php -f output.heic -p sRGB.icc > output-icc.heic
```

- From PHP

```php
require_once 'IO/HEIF.php';

// make a single-image HEIC from the first IDR picture of an HEVC stream
$heif = new IO_HEIF();
$heif->fromHEVC(file_get_contents('input.hevc'));
file_put_contents('output.heic', $heif->build());

// extract the primary image of a HEIC as HEVC (toHEVC() writes to standard output instead of returning)
$heif = new IO_HEIF();
$heif->parse(file_get_contents('output.heic'));
ob_start();
$heif->toHEVC(["RoleType" => "primary"]);   // or ["ItemID" => 1]
file_put_contents('output.hevc', ob_get_clean());
```

# Design

| Class | Role |
|---|---|
| `IO_HEIF` | Subclass of `IO_ISOBMFF`. Adds `fromHEVC()` and `toHEVC()` |
| `IO_HEIF_HEVC` | Parses HEVC with IO_HEVC and makes what fromHEVC needs (mdat data, ispe, pasp, hvcC) |
| `IO_ISOBMFF` (separate package) | Box tree parse / build / dump / tree, rewriting of iloc offsets |
| `IO_HEVC`, `IO_Bit`, `IO_ICC` (separate packages) | HEVC NAL unit parsing / bit-level reading and writing / printing ICC profiles |

- `fromHEVC()`: puts the NAL unit of the first IDR picture (IDR_W_RADL, or IDR_N_LP if none) into mdat with a length prefix,
  and builds ftyp (heic) and meta (hdlr, pitm, iloc, iinf, iprp). The properties are ispe (picture size from the SPS), pasp (fixed 1:1) and hvcC (VPS/SPS/PPS).
- `toHEVC()`: writes the NAL units of the item's hvcC (VPS/SPS/PPS etc., in array order) and the NAL units of the data joined from all iloc extents,
  each with the start code `00 00 00 01`. The NAL unit lengths in the data are read as 4 bytes.

# Features

| Feature | Sample (`sample/`) |
|---|---|
| HEVC to HEIC | `heiffromhevc.php` |
| HEIC to HEVC (item list and extraction) | `heiftohevc.php` |
| Box tree dump | `heifdump.php` |
| Property and item list | `heiftree.php` |
| Per item / per property display | `heifitems.php`, `heifprops.php` |
| Rebuild / delete boxes / add an ICC profile | `heifrebuild.php`, `heifdelbox.php`, `heifaddiccp.php` |

# Known limitations

- The limitations of IO_ISOBMFF apply as they are (see "Known limitations" in the IO_ISOBMFF README).
- `fromHEVC()` uses only one NAL unit, the first IDR one. Pictures split into several slices, SEI and so on are not included.
- `toHEVC()` can extract only items that have an hvcC. Derived image items such as grid cannot be extracted (specify the original tile items).
- `toHEVC()` writes to standard output instead of returning the result.
- On PHP 8.2 or later, IO_HEVC prints Deprecated messages for dynamic properties. On the CLI they go to standard output, so add `-d error_reporting='E_ALL & ~E_DEPRECATED'` to heiffromhevc.php.

# Test

```
% php tests/run.php
```

# Reference

- ISO/IEC 23008-12 (HEIF)
- https://mpeg.chiariglione.org/standards/mpeg-h/image-file-format
- https://github.com/yoya/IO_ISOBMFF
- https://github.com/yoya/IO_HEVC
