IO_HEIF
=======

English version: [README.en.md](README.en.md)

HEIF (HEIC) のバイナリを解釈/編集する為のライブラリです。
`IO_HEIF` は IO_ISOBMFF の `IO_ISOBMFF` を継承していて、box の解釈・書き出し・ダンプは IO_ISOBMFF が行います。
`IO_HEIF` が足すのは HEVC (H.265) との変換で、HEVC のバイトストリームから 1 枚の HEIC を作る `fromHEVC()` と、HEIC の item を HEVC のバイトストリームで取り出す `toHEVC()` があります。
IO_Bit、IO_ISOBMFF、IO_HEVC、IO_ICC が必要です。
利用に制限はかけません。コピーも改変もご自由にどうぞ。MIT ライセンスにしました。
自分の環境だと動かないとか、機能が欲しいとかいった要望があれば連絡下さい。

# License

MIT License

# Install

```
% composer require yoya/io_heif
```

# Usage

- heiffromhevc.php (HEVC → HEIC)

HEVC のバイトストリーム (Annex B 形式) の最初の IDR ピクチャから、1 枚の画像の HEIC を作ります。

```
% php vendor/yoya/io_heif/sample/heiffromhevc.php -f input.hevc > output.heic
```

- heiftree.php (property と item の一覧)

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

- heiftohevc.php (HEIC → HEVC)

item を指定しないと item の一覧を出します。`-i <item_id>` で item ID を、`-r` で役割を指定すると、その item を HEVC のバイトストリーム (hvcC の VPS/SPS/PPS と、mdat のデータ) で出します。
`-r` には `master` (`primary`, `pitm`)、`thumbnail` (`thmb`)、`aux` (`auxl`, `auxiliary`。`-u <urn>` で auxC の種類を指定) が使えます。

```
% php vendor/yoya/io_heif/sample/heiftohevc.php -f output.heic
ItemID:1 infe:hvc1 pitm
% php vendor/yoya/io_heif/sample/heiftohevc.php -f output.heic -r master > output.hevc
```

- heifdump.php / heifitems.php / heifprops.php / heifrebuild.php / heifdelbox.php / heifaddiccp.php

IO_ISOBMFF の isobmff*.php と同じものです (クラスが `IO_HEIF` なだけ)。使い方は IO_ISOBMFF の README を見て下さい。

```
% php vendor/yoya/io_heif/sample/heifprops.php -f output.heic -t
ispe  index:1
pasp  index:2
hvcC  index:3
% php vendor/yoya/io_heif/sample/heifaddiccp.php -f output.heic -p sRGB.icc > output-icc.heic
```

- PHP から使う

```php
require_once 'IO/HEIF.php';

// HEVC の最初の IDR ピクチャから 1 枚の HEIC を作る
$heif = new IO_HEIF();
$heif->fromHEVC(file_get_contents('input.hevc'));
file_put_contents('output.heic', $heif->build());

// HEIC の主画像を HEVC で取り出す (toHEVC() は返さずに標準出力に書く)
$heif = new IO_HEIF();
$heif->parse(file_get_contents('output.heic'));
ob_start();
$heif->toHEVC(["RoleType" => "primary"]);   // または ["ItemID" => 1]
file_put_contents('output.hevc', ob_get_clean());
```

# 設計概要

| クラス | 役割 |
|---|---|
| `IO_HEIF` | `IO_ISOBMFF` の派生。`fromHEVC()` と `toHEVC()` を足す |
| `IO_HEIF_HEVC` | IO_HEVC で HEVC を解釈し、fromHEVC に必要なもの (mdat のデータ、ispe、pasp、hvcC) を作る |
| `IO_ISOBMFF` (別パッケージ) | box の木の parse / build / dump / tree、iloc のオフセットの書き直し |
| `IO_HEVC`, `IO_Bit`, `IO_ICC` (別パッケージ) | HEVC の NAL ユニットの解釈 / ビット単位の読み書き / ICC プロファイルの表示 |

- `fromHEVC()`: 最初の IDR ピクチャ (IDR_W_RADL、無ければ IDR_N_LP) の NAL ユニットを長さ付きにして mdat に入れ、
  ftyp (heic)、meta (hdlr, pitm, iloc, iinf, iprp) を組み立てる。property は ispe (SPS の画像サイズ)、pasp (1:1 固定)、hvcC (VPS/SPS/PPS)。
- `toHEVC()`: item の hvcC の NAL ユニット (VPS/SPS/PPS など、配列の順) と、iloc の全 extent をつないだデータの NAL ユニットを、
  それぞれ開始コード `00 00 00 01` を付けて出す。データの NAL ユニットの長さは 4 バイトとして読む。

# 機能

| 機能 | サンプル (`sample/`) |
|---|---|
| HEVC → HEIC | `heiffromhevc.php` |
| HEIC → HEVC (item の一覧と取り出し) | `heiftohevc.php` |
| box の木のダンプ | `heifdump.php` |
| property と item の一覧 | `heiftree.php` |
| item / property ごとの表示 | `heifitems.php`, `heifprops.php` |
| 作り直し / box の削除 / ICC プロファイルの追加 | `heifrebuild.php`, `heifdelbox.php`, `heifaddiccp.php` |

# 既知の制限

- IO_ISOBMFF の制限をそのまま受け継ぐ (IO_ISOBMFF の README の「既知の制限」を参照)。
- `fromHEVC()` は最初の IDR の NAL ユニット 1 つだけを使う。複数のスライスに分かれたピクチャや、SEI などは入らない。
- `toHEVC()` は hvcC を持つ item だけを取り出せる。grid などの派生画像の item は取り出せない (元のタイルの item を指定する)。
- `toHEVC()` は結果を返さずに標準出力に書く。

# Test

```
% php tests/run.php
```

# Reference

- ISO/IEC 23008-12 (HEIF)
- https://mpeg.chiariglione.org/standards/mpeg-h/image-file-format
- https://github.com/yoya/IO_ISOBMFF
- https://github.com/yoya/IO_HEVC

## IO_Bit の利用箇所

box の処理に加え、`toHEVC()` では `IO_Bit::getUI32BE()` で `mdat` 内の NAL ユニットの長さを読み取ります。

[IO_Bit Wiki](https://github.com/yoya/IO_Bit/wiki)
