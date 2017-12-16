IO_HEIF
======

HEIF binary perser dumper, converter, powered by PHP.
bidirectional converter with H.265/HEVC

# install

```
% composer require yoya/io_heif
```

# require

- IO_Bit
 - https://github.com/yoya/IO_Bit
- IO_HEVC
 - https://github.com/yoya/IO_HEVC

## script (sample/*.php)

- heifdump.php

```
% php sample/heifdump.php -f test.heic
type:ftyp(offset:0 len:24):File Type and Compatibility
  major:mif1 minor:0  alt:mif1, heic
type:mdat(offset:24 len:139682):Media Data
  _mdatId:557184717
  _offsetRelative:8
  _itemID:1
type:meta(offset:139706 len:328):Information about items
  version:0 flags:0
    type:hdlr(offset:139718 len:53):Handler reference
      version:0 flags:0
      componentType:
      componentSubType:pict
(omit...)
```

- heiffromhevc.php

```
% php sample/heiffromhevc.php -f input.hevc > output.heic
```

- heiftohevc.php

```
% php sample/heiftohevc.php -f input.heic > output.hevc
```
