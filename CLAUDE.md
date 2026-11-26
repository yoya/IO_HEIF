# IO_HEIF

HEIF (HEIC) のバイナリを解釈・編集し、HEVC と相互変換する PHP ライブラリ (IO_ISOBMFF を継承し、IO_HEVC・IO_Bit・IO_ICC に依存)。本体は [IO/HEIF.php](IO/HEIF.php) と [IO/HEIF/HEVC.php](IO/HEIF/HEVC.php)。box の処理は IO_ISOBMFF にある。

# 互換性

IO_HEIF に依存するライブラリは無い (`../IO_*/composer.json` と require を grep して確認)。
逆に IO_HEIF は IO_ISOBMFF の内部 (`boxTree`, `_isobmffData`, box の連想配列のキー、mdat の `_mdatId` / `_offsetRelative`) と、IO_HEVC の NAL・SPS のプロパティを直接使っている。
HEIF の不具合が IO_ISOBMFF や IO_HEVC 側にあるときは、そちらの CLAUDE.md に従って直す。

- 既存の挙動を変える修正は、依存側 (`../IO_*`) での使われ方を grep で確かめてから行う。
- 明らかなバグ修正以外で挙動が変わるもの (`fromHEVC()` が作る box の構成、`toHEVC()` の出力、`getHEVCBuildInfo()` の返り値の形、サンプルのオプションなど) は、メジャーバージョンを上げて扱う。

# テスト

`php tests/run.php` で回帰テストを実行する (失敗すると終了コード 1)。
IO_ISOBMFF・IO_HEVC・IO_Bit・IO_ICC は vendor/ (composer install) か include_path から読む。例: `php -d include_path=.:../IO_Bit:../IO_ICC:../IO_ISOBMFF:../IO_HEVC tests/run.php`。
追跡している HEIC は無い。実ファイルで確かめるときは、ffmpeg (libx265) で作った小さな HEVC から heiffromhevc.php で HEIC を作る。
バグを直したら、ここにテストを足す。

# リリース作業

バージョン番号は指示されたものを使う。進め方は過去のリリースの形に倣い、迷う点や手順に無い気づきがあれば報告する。

1. 変更の把握
   - 手元が公開済みの状態と揃っていて、リリースに含めない変更が混じっていないことを確かめる。
   - 前回のタグから何が変わったかを、コミットの本文まで読んで把握する。
2. 動作の確認
   - `php tests/run.php` が通ることを確かめる。今回変わった機能 (特に heiffromhevc.php / heiftohevc.php) を実際に動かして確かめる。
3. README の更新
   - README.md と README.en.md が、今回の変更を含めた現状を表しているか見直し、必要なら直す。両者の内容は揃える。
   - 直す必要が無ければ、そう判断したことを報告する。
   - 直したものは、バージョン番号の変更とは別のコミットにする。
4. バージョン番号の更新
   - [IO/HEIF.php](IO/HEIF.php) 冒頭の `IO_HEIF class - vX.Y` (今は `v4.0`) を新しい番号にし、それだけのコミットにする。
   - メッセージは `vX.Y => vA.B` の形にする (前回は `v3.0 => v4.0`)。
5. タグ
   - 前回と同じ形式 (`3.0` のように v なし、2 桁) でタグを打ち、ブランチと一緒に公開する。
6. GitHub リリース
   - 1.6 から 2.5 まではリリースがある (最新は 2.5 の「apple heif support」)。3.0 には無い。
   - タイトルは変更の要約にし、利用者が読んで分かる言葉で書く。
7. Wiki
   - Wiki はまだ作られていない (GitHub の Web 画面で最初のページを作るまで `git@github.com:yoya/IO_HEIF.wiki.git` は存在しない)。この手順は飛ばす。
8. 反映の確認
   - リリースが最新版として見えていること、Packagist (`yoya/io_heif`) に新しい版が反映されていることを確かめる。

## 依存の順序

- Packagist には io_icc → io_isobmff、io_hevc を出してから io_heif を出す。
- io_isobmff や io_hevc のメジャーバージョンが上がったら、composer.json の制約がそれを許すように直す。
  今は `"yoya/io_isobmff": "~2.6 || ^4.0"`、`"yoya/io_hevc": "~1.5 || ^2.0"`、`"yoya/io_icc": "^1.1.3 || ^2.0"`。
  IO_ISOBMFF 2.6 は IO_Bit 2.x を要求し、こちらの `"yoya/io_bit": "~3.0 || ^4.0"` と両立しないので、実際に選ばれるのは IO_ISOBMFF 4.x。

公開する文書 (コミットメッセージ、リリースノート、README、Wiki) には、`./tmp/` 由来のファイル名や中身を入れない。
