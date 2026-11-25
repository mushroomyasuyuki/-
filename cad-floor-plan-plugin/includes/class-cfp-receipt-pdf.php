<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 領収書の PDF を作る小さな部品（ライブラリなし）。
 *
 * 和文は PDF ビューアーに入っている日本語フォント（小塚ゴシック相当・埋め込みなし）で表示し、
 * 英数字は Helvetica で表示する。A4 縦・1ページ。座標は左上が原点（pt）。
 */
class CFP_Receipt_PDF {

    const W = 595.28;
    const H = 841.89;

    /** Helvetica の文字幅（1/1000 em）：ASCII 32〜126. */
    private static $hw = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];

    private $ops = [];

    /** 文字列を、英数字（Helvetica）と和文（日本語フォント）のまとまりに分ける. */
    private static function runs($s) {
        $out = [];
        $chars = preg_split('//u', (string) $s, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($chars as $c) {
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

    private static function ord($c) {
        $u = mb_convert_encoding($c, 'UCS-4BE', 'UTF-8');
        $v = unpack('N', $u);
        return $v ? $v[1] : 0;
    }

    /** 文字列の幅（pt）. */
    public static function width($s, $size) {
        $w = 0;
        foreach (self::runs($s) as $r) {
            if ($r[0]) {
                foreach (preg_split('//u', $r[1], -1, PREG_SPLIT_NO_EMPTY) as $c) {
                    $o = self::ord($c);
                    $w += $o === 0xA5 ? 556 : self::$hw[$o - 32];
                }
            } else {
                $w += 1000 * mb_strlen($r[1], 'UTF-8');
            }
        }
        return $w * $size / 1000;
    }

    private static function num($v) {
        return rtrim(rtrim(sprintf('%.2F', $v), '0'), '.');
    }

    /** 文字を書く。$align は left / center / right（$x はその基準の位置）. */
    public function text($x, $y, $size, $s, $align = 'left', $gray = 0) {
        $w = self::width($s, $size);
        if ($align === 'center') {
            $x -= $w / 2;
        } elseif ($align === 'right') {
            $x -= $w;
        }
        $py = self::H - $y;
        $this->ops[] = self::num($gray) . ' g';
        foreach (self::runs($s) as $r) {
            if ($r[0]) {
                $bytes = '';
                foreach (preg_split('//u', $r[1], -1, PREG_SPLIT_NO_EMPTY) as $c) {
                    $o = self::ord($c);
                    $bytes .= chr($o === 0xA5 ? 0xA5 : $o);
                }
                $str = strtr($bytes, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
                $this->ops[] = 'BT /F2 ' . self::num($size) . ' Tf ' . self::num($x) . ' ' . self::num($py) . ' Td (' . $str . ') Tj ET';
            } else {
                $hex = strtoupper(bin2hex(mb_convert_encoding($r[1], 'UTF-16BE', 'UTF-8')));
                $this->ops[] = 'BT /F1 ' . self::num($size) . ' Tf ' . self::num($x) . ' ' . self::num($py) . ' Td <' . $hex . '> Tj ET';
            }
            $x += self::width($r[1], $size);
        }
    }

    public function line($x1, $y1, $x2, $y2, $lw = 0.6, $gray = 0) {
        $this->ops[] = self::num($gray) . ' G ' . self::num($lw) . ' w ' . self::num($x1) . ' ' . self::num(self::H - $y1) . ' m ' . self::num($x2) . ' ' . self::num(self::H - $y2) . ' l S';
    }

    /** 四角（$fill：塗りの灰色 0〜1、null で塗らない。$stroke：線の太さ、0 で線なし）. */
    public function rect($x, $y, $w, $h, $fill = null, $stroke = 0.6) {
        $r = self::num($x) . ' ' . self::num(self::H - $y - $h) . ' ' . self::num($w) . ' ' . self::num($h) . ' re';
        if ($fill !== null) {
            $this->ops[] = self::num($fill) . ' g ' . $r . ' f';
        }
        if ($stroke > 0) {
            $this->ops[] = '0 G ' . self::num($stroke) . ' w ' . $r . ' S';
        }
    }

    /** PDF のバイト列. */
    public function output($title = '') {
        $content = implode("\n", $this->ops);
        $filter = '';
        if (function_exists('gzcompress')) {
            $content = gzcompress($content, 6);
            $filter = ' /Filter /FlateDecode';
        }
        $font = 'KozGoPro-Medium';
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objs[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::num(self::W) . ' ' . self::num(self::H) . '] /Resources << /Font << /F1 5 0 R /F2 8 0 R >> >> /Contents 4 0 R >>';
        $objs[4] = '<< /Length ' . strlen($content) . $filter . " >>\nstream\n" . $content . "\nendstream";
        $objs[5] = '<< /Type /Font /Subtype /Type0 /BaseFont /' . $font . ' /Encoding /UniJIS-UCS2-H /DescendantFonts [6 0 R] >>';
        $objs[6] = '<< /Type /Font /Subtype /CIDFontType0 /BaseFont /' . $font . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Japan1) /Supplement 4 >> /FontDescriptor 7 0 R /DW 1000 >>';
        $objs[7] = '<< /Type /FontDescriptor /FontName /' . $font . ' /Flags 4 /FontBBox [-195 -272 1110 1075] /ItalicAngle 0 /Ascent 880 /Descent -120 /CapHeight 742 /StemV 70 >>';
        $objs[8] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[9] = '<< /Producer (Mush Office) /Title <FEFF' . strtoupper(bin2hex(mb_convert_encoding($title, 'UTF-16BE', 'UTF-8'))) . '> /CreationDate (D:' . gmdate('YmdHis') . 'Z) >>';
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $off = [];
        foreach ($objs as $i => $body) {
            $off[$i] = strlen($pdf);
            $pdf .= $i . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($off as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }
        $pdf .= 'trailer << /Size ' . (count($objs) + 1) . " /Root 1 0 R /Info 9 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }
}
