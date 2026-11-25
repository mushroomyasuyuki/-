<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 領収書の PDF を作る小さな部品（ライブラリなし）。A4 縦・1ページ。座標は左上が原点（pt）。
 *
 * フォントの埋め込み：assets/fonts/receipt-gothic.ttf（本文）と receipt-seal.ttf（社判）があれば、
 * 使う文字だけの小さなフォントにして PDF に入れる（どのアプリでも同じ見え方になる）。
 * フォントのファイルがないときは、PDF を見るアプリの日本語フォント（埋め込みなし）と Helvetica で表示する。
 */
class CFP_Receipt_PDF {

    const W = 595.28;
    const H = 841.89;

    /** Helvetica の文字幅（1/1000 em）：ASCII 32〜126（埋め込みなしのときだけ使う）. */
    private static $hw = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];

    private $ops = [];
    /** 画像（社判）：[w, h, 色のデータ, 透明度のデータ]（どちらも zlib で圧縮済み） */
    private $images = [];
    /** 埋め込むフォント：'body' / 'seal' => ['ttf' => CFP_TTF_Subset, 'used' => [gid => unicode]] */
    private $fonts = [];

    public function __construct($body_ttf = '', $seal_ttf = '') {
        if (!class_exists('CFP_TTF_Subset')) {
            require_once __DIR__ . '/class-cfp-ttf-subset.php';
        }
        foreach (['body' => $body_ttf, 'seal' => $seal_ttf] as $k => $path) {
            if ($path !== '' && is_readable($path)) {
                try {
                    $this->fonts[$k] = ['ttf' => new CFP_TTF_Subset($path), 'used' => []];
                } catch (Exception $e) {
                    unset($this->fonts[$k]);
                }
            }
        }
    }

    /** フォントを埋め込んでいるか. */
    public function embedded() {
        return isset($this->fonts['body']);
    }

    private static function chars($s) {
        return preg_split('//u', (string) $s, -1, PREG_SPLIT_NO_EMPTY);
    }

    private static function ord($c) {
        $v = unpack('N', mb_convert_encoding($c, 'UCS-4BE', 'UTF-8'));
        return $v ? $v[1] : 0;
    }

    private static function num($v) {
        return rtrim(rtrim(sprintf('%.2F', $v), '0'), '.');
    }

    /** 色の指定：数値は灰色（0＝黒〜1＝白）、配列は RGB（0〜1）. */
    private static function color($c, $stroke = false) {
        if (is_array($c)) {
            return self::num($c[0]) . ' ' . self::num($c[1]) . ' ' . self::num($c[2]) . ($stroke ? ' RG' : ' rg');
        }
        return self::num($c) . ($stroke ? ' G' : ' g');
    }

    /** 埋め込みなしのとき：英数字（Helvetica）と和文のまとまりに分ける. */
    private static function runs($s) {
        $out = [];
        foreach (self::chars($s) as $c) {
            $o = self::ord($c);
            $latin = ($o >= 32 && $o <= 126) || $o === 0xA5;
            $k = count($out) - 1;
            if ($k >= 0 && $out[$k][0] === $latin) {
                $out[$k][1] .= $c;
            } else {
                $out[] = [$latin, $c];
            }
        }
        return $out;
    }

    /** 文字列の幅（pt）. */
    public function width($s, $size, $font = 'body') {
        $w = 0;
        if (isset($this->fonts[$font])) {
            $ttf = $this->fonts[$font]['ttf'];
            foreach (self::chars($s) as $c) {
                $w += $ttf->width($ttf->gid(self::ord($c)));
            }
            return $w * $size / 1000;
        }
        foreach (self::runs($s) as $r) {
            if ($r[0]) {
                foreach (self::chars($r[1]) as $c) {
                    $o = self::ord($c);
                    $w += $o === 0xA5 ? 556 : self::$hw[$o - 32];
                }
            } else {
                $w += 1000 * mb_strlen($r[1], 'UTF-8');
            }
        }
        return $w * $size / 1000;
    }

    /** 埋め込むフォントでの文字列（グリフ番号の16進）。使ったグリフを覚えておく. */
    private function hex($s, $font) {
        $ttf = $this->fonts[$font]['ttf'];
        $hex = '';
        foreach (self::chars($s) as $c) {
            $u = self::ord($c);
            $g = $ttf->gid($u);
            $this->fonts[$font]['used'][$g] = $u;
            $hex .= sprintf('%04X', $g);
        }
        return $hex;
    }

    /** 文字を書く。$align は left / center / right（$x はその基準の位置）. */
    public function text($x, $y, $size, $s, $align = 'left', $gray = 0) {
        $w = $this->width($s, $size);
        if ($align === 'center') {
            $x -= $w / 2;
        } elseif ($align === 'right') {
            $x -= $w;
        }
        $py = self::H - $y;
        $this->ops[] = self::color($gray);
        if ($this->embedded()) {
            $this->ops[] = 'BT /F1 ' . self::num($size) . ' Tf ' . self::num($x) . ' ' . self::num($py) . ' Td <' . $this->hex($s, 'body') . '> Tj ET';
            return;
        }
        foreach (self::runs($s) as $r) {
            if ($r[0]) {
                $bytes = '';
                foreach (self::chars($r[1]) as $c) {
                    $bytes .= chr(self::ord($c));
                }
                $str = strtr($bytes, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
                $this->ops[] = 'BT /F2 ' . self::num($size) . ' Tf ' . self::num($x) . ' ' . self::num($py) . ' Td (' . $str . ') Tj ET';
            } else {
                $hex = strtoupper(bin2hex(mb_convert_encoding($r[1], 'UTF-16BE', 'UTF-8')));
                $this->ops[] = 'BT /F1 ' . self::num($size) . ' Tf ' . self::num($x) . ' ' . self::num($py) . ' Td <' . $hex . '> Tj ET';
            }
            $x += $this->width($r[1], $size);
        }
    }

    public function line($x1, $y1, $x2, $y2, $lw = 0.6, $gray = 0) {
        $this->ops[] = self::color($gray, true) . ' ' . self::num($lw) . ' w ' . self::num($x1) . ' ' . self::num(self::H - $y1) . ' m ' . self::num($x2) . ' ' . self::num(self::H - $y2) . ' l S';
    }

    /** 四角（$fill：塗りの灰色 0〜1、null で塗らない。$stroke：線の太さ、0 で線なし）. */
    public function rect($x, $y, $w, $h, $fill = null, $stroke = 0.6) {
        $r = self::num($x) . ' ' . self::num(self::H - $y - $h) . ' ' . self::num($w) . ' ' . self::num($h) . ' re';
        if ($fill !== null) {
            $this->ops[] = self::color($fill) . ' ' . $r . ' f';
        }
        if ($stroke > 0) {
            $this->ops[] = '0 G ' . self::num($stroke) . ' w ' . $r . ' S';
        }
    }

    /**
     * 社判の画像（assets/img/seal.bin：tools/seal_image.py で作る、色と透明度）を押す。
     * $x, $y は左上、$size は横幅（pt）。読めなければ false.
     */
    public function seal_image($path, $x, $y, $size) {
        $d = is_readable($path) ? (string) file_get_contents($path) : '';
        if (strlen($d) < 16 || substr($d, 0, 4) !== 'CFPS') {
            return false;
        }
        $hd = unpack('nw/nh/Nrl/Nal', substr($d, 4, 12));
        if (strlen($d) < 16 + $hd['rl'] + $hd['al']) {
            return false;
        }
        $this->images[] = [$hd['w'], $hd['h'], substr($d, 16, $hd['rl']), substr($d, 16 + $hd['rl'], $hd['al'])];
        $name = 'Im' . count($this->images);
        $h = $size * $hd['h'] / $hd['w'];
        $this->ops[] = 'q ' . self::num($size) . ' 0 0 ' . self::num($h) . ' ' . self::num($x) . ' ' . self::num(self::H - $y - $h) . ' cm /' . $name . ' Do Q';
        return true;
    }

    /**
     * 社判（角印）を押す。$cols は右の列から順の文字列（縦書き）。朱色・少し透ける。
     * $x, $y は印の左上、$size は一辺（pt）.
     */
    public function seal($x, $y, $size, $cols, $rgb = [0.84, 0.16, 0.10]) {
        $this->ops[] = 'q /GS1 gs';
        $this->ops[] = self::color($rgb, true) . ' ' . self::num($size * 0.045) . ' w ' . self::num($x) . ' ' . self::num(self::H - $y - $size) . ' ' . self::num($size) . ' ' . self::num($size) . ' re S';
        $in = $size * 0.075;
        $this->ops[] = self::num($size * 0.012) . ' w ' . self::num($x + $in) . ' ' . self::num(self::H - $y - $size + $in) . ' ' . self::num($size - $in * 2) . ' ' . self::num($size - $in * 2) . ' re S';
        $pad = $size * 0.12;
        $inner = $size - $pad * 2;
        $colw = $inner / max(1, count($cols));
        $font = isset($this->fonts['seal']) ? 'seal' : (isset($this->fonts['body']) ? 'body' : '');
        foreach (array_values($cols) as $i => $col) {
            $chars = self::chars($col);
            $m = max(1, count($chars));
            $cx = $x + $size - $pad - $colw * ($i + 0.5);
            $fs = min($colw * 0.98, $inner / $m * 0.98);
            $step = $inner / $m;
            foreach ($chars as $j => $c) {
                $top = $y + $pad + $step * $j + ($step - $fs) / 2;
                if ($font !== '') {
                    // 1文字ずつ、枠の中央に置く（社判用のフォントは、小さい「ッ」なども縦書きの字形）
                    $cw = $this->width($c, $fs, $font);
                    // 印らしく、字の線を少し太くする（塗り＋同じ色のふち）
                    $this->ops[] = 'BT ' . self::color($rgb) . ' ' . self::color($rgb, true) . ' 2 Tr ' . self::num($fs * 0.035) . ' w /' . ($font === 'seal' ? 'F4' : 'F1') . ' ' . self::num($fs) . ' Tf ' . self::num($cx - $cw / 2) . ' ' . self::num(self::H - $top - $fs * 0.88) . ' Td <' . $this->hex($c, $font) . '> Tj ET';
                } else {
                    $hex = strtoupper(bin2hex(mb_convert_encoding($c, 'UTF-16BE', 'UTF-8')));
                    $this->ops[] = 'BT ' . self::color($rgb) . ' /F4 ' . self::num($fs) . ' Tf ' . self::num($cx) . ' ' . self::num(self::H - $top) . ' Td <' . $hex . '> Tj ET';
                }
            }
        }
        $this->ops[] = 'Q';
    }

    private static function stream($data, $extra = '') {
        $filter = '';
        if (function_exists('gzcompress')) {
            $data = gzcompress($data, 9);
            $filter = ' /Filter /FlateDecode';
        }
        return '<< /Length ' . strlen($data) . $filter . $extra . " >>\nstream\n" . $data . "\nendstream";
    }

    /** 埋め込むフォントの PDF オブジェクト（Type0 → CIDFontType2 → FontDescriptor → FontFile2・ToUnicode）. */
    private function font_objs(&$objs, $k, $first, $name) {
        $f = $this->fonts[$k];
        $ttf = $f['ttf'];
        $used = $f['used'];
        ksort($used);
        $tag = '';
        $seed = crc32($name . implode(',', array_keys($used)));
        for ($i = 0; $i < 6; $i++) {
            $tag .= chr(65 + ($seed >> ($i * 4)) % 26);
        }
        $base = $tag . '+' . $name;
        $w = '';
        foreach ($used as $g => $u) {
            $w .= $g . ' [' . $ttf->width($g) . '] ';
        }
        $tu = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
        $chunks = array_chunk($used, 100, true);
        foreach ($chunks as $ch) {
            $tu .= count($ch) . " beginbfchar\n";
            foreach ($ch as $g => $u) {
                $tu .= sprintf('<%04X> <%s>', $g, strtoupper(bin2hex(mb_convert_encoding(mb_chr($u, 'UTF-8'), 'UTF-16BE', 'UTF-8')))) . "\n";
            }
            $tu .= "endbfchar\n";
        }
        $tu .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
        $sc = 1000 / $ttf->upem;
        $bb = array_map(function ($v) use ($sc) { return (int) round($v * $sc); }, $ttf->bbox);
        $file = $ttf->subset(array_keys($used));
        $objs[$first] = '<< /Type /Font /Subtype /Type0 /BaseFont /' . $base . ' /Encoding /Identity-H /DescendantFonts [' . ($first + 1) . ' 0 R] /ToUnicode ' . ($first + 4) . ' 0 R >>';
        $objs[$first + 1] = '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /' . $base . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor ' . ($first + 2) . ' 0 R /CIDToGIDMap /Identity /DW 1000 /W [' . trim($w) . '] >>';
        $objs[$first + 2] = '<< /Type /FontDescriptor /FontName /' . $base . ' /Flags 4 /FontBBox [' . implode(' ', $bb) . '] /ItalicAngle 0 /Ascent ' . (int) round($ttf->ascent * $sc) . ' /Descent ' . (int) round($ttf->descent * $sc) . ' /CapHeight 733 /StemV 80 /FontFile2 ' . ($first + 3) . ' 0 R >>';
        $objs[$first + 3] = self::stream($file, ' /Length1 ' . strlen($file));
        $objs[$first + 4] = self::stream($tu);
    }

    /** PDF のバイト列. */
    public function output($title = '') {
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objs[4] = self::stream(implode("\n", $this->ops));
        $fonts = '';
        if ($this->embedded()) {
            $this->font_objs($objs, 'body', 10, 'NotoSansJP-Regular');
            $fonts = '/F1 10 0 R';
            if (isset($this->fonts['seal']) && $this->fonts['seal']['used']) {
                $this->font_objs($objs, 'seal', 20, 'NotoSerifJP-Regular');
                $fonts .= ' /F4 20 0 R';
            }
        } else {
            $gothic = 'KozGoPro-Medium';
            $mincho = 'KozMinPro-Regular';
            $objs[5] = '<< /Type /Font /Subtype /Type0 /BaseFont /' . $gothic . ' /Encoding /UniJIS-UCS2-H /DescendantFonts [6 0 R] >>';
            $objs[6] = '<< /Type /Font /Subtype /CIDFontType0 /BaseFont /' . $gothic . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Japan1) /Supplement 4 >> /FontDescriptor 7 0 R /DW 1000 >>';
            $objs[7] = '<< /Type /FontDescriptor /FontName /' . $gothic . ' /Flags 4 /FontBBox [-195 -272 1110 1075] /ItalicAngle 0 /Ascent 880 /Descent -120 /CapHeight 742 /StemV 70 >>';
            $objs[8] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
            $objs[11] = '<< /Type /Font /Subtype /Type0 /BaseFont /' . $mincho . ' /Encoding /UniJIS-UCS2-V /DescendantFonts [12 0 R] >>';
            $objs[12] = '<< /Type /Font /Subtype /CIDFontType0 /BaseFont /' . $mincho . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Japan1) /Supplement 4 >> /FontDescriptor 13 0 R /DW 1000 >>';
            $objs[13] = '<< /Type /FontDescriptor /FontName /' . $mincho . ' /Flags 6 /FontBBox [-195 -272 1110 1075] /ItalicAngle 0 /Ascent 880 /Descent -120 /CapHeight 742 /StemV 70 >>';
            $fonts = '/F1 5 0 R /F2 8 0 R /F4 11 0 R';
        }
        $objs[30] = '<< /Type /ExtGState /ca 0.88 /CA 0.88 >>';
        $xobj = '';
        foreach ($this->images as $i => $im) {
            $n = 40 + $i * 2;
            $objs[$n] = '<< /Type /XObject /Subtype /Image /Width ' . $im[0] . ' /Height ' . $im[1] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /SMask ' . ($n + 1) . ' 0 R /Length ' . strlen($im[2]) . " >>\nstream\n" . $im[2] . "\nendstream";
            $objs[$n + 1] = '<< /Type /XObject /Subtype /Image /Width ' . $im[0] . ' /Height ' . $im[1] . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length ' . strlen($im[3]) . " >>\nstream\n" . $im[3] . "\nendstream";
            $xobj .= ' /Im' . ($i + 1) . ' ' . $n . ' 0 R';
        }
        $objs[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::num(self::W) . ' ' . self::num(self::H) . '] /Resources << /Font << ' . $fonts . ' >> /ExtGState << /GS1 30 0 R >>' . ($xobj !== '' ? ' /XObject <<' . $xobj . ' >>' : '') . ' >> /Contents 4 0 R >>';
        $objs[9] = '<< /Producer (Mush Office) /Title <FEFF' . strtoupper(bin2hex(mb_convert_encoding($title, 'UTF-16BE', 'UTF-8'))) . '> /CreationDate (D:' . gmdate('YmdHis') . 'Z) >>';
        ksort($objs);
        $max = max(array_keys($objs));
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $off = [];
        foreach ($objs as $i => $body) {
            $off[$i] = strlen($pdf);
            $pdf .= $i . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= isset($off[$i]) ? sprintf("%010d 00000 n \n", $off[$i]) : "0000000000 65535 f \n";
        }
        $pdf .= 'trailer << /Size ' . ($max + 1) . " /Root 1 0 R /Info 9 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }
}
