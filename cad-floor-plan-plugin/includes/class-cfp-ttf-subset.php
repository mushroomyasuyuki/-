<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * TrueType（glyf）フォントを読み、PDF に埋め込むために「使う文字だけ」の小さなフォントを作る部品。
 *
 * グリフ番号（GID）はそのまま残し、使わないグリフの輪郭だけを空にする（PDF 側は Identity で GID を指す）。
 * 対応：cmap の形式 4・12、複合グリフ（部品のグリフも残す）。ヒンティングの表は持たないフォントを前提にする。
 */
class CFP_TTF_Subset {

    public $upem = 1000;
    public $ascent = 880;
    public $descent = -120;
    public $bbox = [0, -120, 1000, 880];
    public $num_glyphs = 0;

    private $data = '';
    private $tables = [];
    private $cmap = [];
    private $adv = [];
    private $loca = [];

    public function __construct($path) {
        $this->data = (string) file_get_contents($path);
        if (strlen($this->data) < 12) {
            throw new Exception('フォントを読めませんでした。');
        }
        $n = $this->u16(4);
        for ($i = 0; $i < $n; $i++) {
            $p = 12 + $i * 16;
            $this->tables[substr($this->data, $p, 4)] = [$this->u32($p + 8), $this->u32($p + 12)];
        }
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'loca', 'glyf', 'cmap'] as $t) {
            if (!isset($this->tables[$t])) {
                throw new Exception('フォントに ' . $t . ' の表がありません。');
            }
        }
        $h = $this->tables['head'][0];
        $this->upem = $this->u16($h + 18);
        $this->bbox = [$this->s16($h + 36), $this->s16($h + 38), $this->s16($h + 40), $this->s16($h + 42)];
        $long_loca = $this->s16($h + 50) === 1;
        $hh = $this->tables['hhea'][0];
        $this->ascent = $this->s16($hh + 4);
        $this->descent = $this->s16($hh + 6);
        $num_hm = $this->u16($hh + 34);
        $this->num_glyphs = $this->u16($this->tables['maxp'][0] + 4);
        $hm = $this->tables['hmtx'][0];
        $last = 0;
        for ($g = 0; $g < $this->num_glyphs; $g++) {
            if ($g < $num_hm) {
                $last = $this->u16($hm + $g * 4);
            }
            $this->adv[$g] = $last;
        }
        $lo = $this->tables['loca'][0];
        for ($g = 0; $g <= $this->num_glyphs; $g++) {
            $this->loca[$g] = $long_loca ? $this->u32($lo + $g * 4) : $this->u16($lo + $g * 2) * 2;
        }
        $this->read_cmap();
    }

    private function u16($p) { return unpack('n', substr($this->data, $p, 2))[1]; }
    private function s16($p) { $v = $this->u16($p); return $v >= 0x8000 ? $v - 0x10000 : $v; }
    private function u32($p) { return unpack('N', substr($this->data, $p, 4))[1]; }

    private function read_cmap() {
        $c = $this->tables['cmap'][0];
        $n = $this->u16($c + 2);
        $best = null;
        $best_fmt = 0;
        for ($i = 0; $i < $n; $i++) {
            $pid = $this->u16($c + 4 + $i * 8);
            $eid = $this->u16($c + 6 + $i * 8);
            $off = $c + $this->u32($c + 8 + $i * 8);
            $fmt = $this->u16($off);
            if (($pid === 3 && ($eid === 1 || $eid === 10)) || $pid === 0) {
                if ($fmt === 12 || ($fmt === 4 && $best_fmt !== 12)) {
                    $best = $off;
                    $best_fmt = $fmt;
                }
            }
        }
        if ($best === null) {
            throw new Exception('フォントの文字の表（cmap）を読めませんでした。');
        }
        if ($best_fmt === 12) {
            $groups = $this->u32($best + 12);
            for ($i = 0; $i < $groups; $i++) {
                $p = $best + 16 + $i * 12;
                $start = $this->u32($p);
                $end = $this->u32($p + 4);
                $gid = $this->u32($p + 8);
                for ($u = $start; $u <= $end && $u - $start < 70000; $u++) {
                    $this->cmap[$u] = $gid + ($u - $start);
                }
            }
            return;
        }
        $segx2 = $this->u16($best + 6);
        $ends = $best + 14;
        $starts = $ends + $segx2 + 2;
        $deltas = $starts + $segx2;
        $ranges = $deltas + $segx2;
        for ($s = 0; $s < $segx2 / 2; $s++) {
            $end = $this->u16($ends + $s * 2);
            $start = $this->u16($starts + $s * 2);
            $delta = $this->s16($deltas + $s * 2);
            $ro = $this->u16($ranges + $s * 2);
            for ($u = $start; $u <= $end && $u !== 0xFFFF; $u++) {
                if ($ro === 0) {
                    $g = ($u + $delta) & 0xFFFF;
                } else {
                    $gp = $ranges + $s * 2 + $ro + ($u - $start) * 2;
                    $g = $this->u16($gp);
                    if ($g !== 0) {
                        $g = ($g + $delta) & 0xFFFF;
                    }
                }
                if ($g) {
                    $this->cmap[$u] = $g;
                }
            }
        }
    }

    /** 文字（Unicode の番号）→ グリフ番号。フォントにない文字は 0（□）. */
    public function gid($u) {
        return $this->cmap[$u] ?? 0;
    }

    /** グリフの送り幅（1/1000 em）. */
    public function width($gid) {
        return (int) round(($this->adv[$gid] ?? 0) * 1000 / $this->upem);
    }

    /** 使うグリフだけを残したフォント（バイト列）. */
    public function subset(array $gids) {
        $keep = [0 => true];
        foreach ($gids as $g) {
            $keep[(int) $g] = true;
        }
        // 複合グリフの部品も残す
        $queue = array_keys($keep);
        $gl = $this->tables['glyf'][0];
        while ($queue) {
            $g = array_pop($queue);
            $a = $this->loca[$g] ?? 0;
            $b = $this->loca[$g + 1] ?? 0;
            if ($b - $a < 10 || $this->s16($gl + $a) >= 0) {
                continue;
            }
            $p = $gl + $a + 10;
            do {
                $flags = $this->u16($p);
                $cg = $this->u16($p + 2);
                if (!isset($keep[$cg])) {
                    $keep[$cg] = true;
                    $queue[] = $cg;
                }
                $p += 4 + (($flags & 1) ? 4 : 2);
                if ($flags & 8) {
                    $p += 2;
                } elseif ($flags & 0x40) {
                    $p += 4;
                } elseif ($flags & 0x80) {
                    $p += 8;
                }
            } while ($flags & 0x20);
        }
        $glyf = '';
        $loca = '';
        for ($g = 0; $g < $this->num_glyphs; $g++) {
            $loca .= pack('N', strlen($glyf));
            if (isset($keep[$g])) {
                $a = $this->loca[$g];
                $b = $this->loca[$g + 1];
                if ($b > $a) {
                    $glyf .= substr($this->data, $gl + $a, $b - $a);
                    while (strlen($glyf) % 4) {
                        $glyf .= "\0";
                    }
                }
            }
        }
        $loca .= pack('N', strlen($glyf));
        $out = [];
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'OS/2', 'post', 'cvt ', 'fpgm', 'prep'] as $t) {
            if (isset($this->tables[$t])) {
                $out[$t] = substr($this->data, $this->tables[$t][0], $this->tables[$t][1]);
            }
        }
        $out['head'] = substr_replace($out['head'], pack('n', 1), 50, 2); // 長い loca
        $out['head'] = substr_replace($out['head'], pack('N', 0), 8, 4);  // checkSumAdjustment は計算し直す
        $out['loca'] = $loca;
        $out['glyf'] = $glyf;
        ksort($out, SORT_STRING);
        $n = count($out);
        $es = (int) floor(log($n, 2));
        $sr = (1 << $es) * 16;
        $font = pack('Nnnnn', 0x00010000, $n, $sr, $es, $n * 16 - $sr);
        $off = 12 + $n * 16;
        $dir = '';
        $body = '';
        foreach ($out as $tag => $tb) {
            $len = strlen($tb);
            $pad = str_pad($tb, ($len + 3) & ~3, "\0");
            $dir .= $tag . pack('NNN', self::checksum($pad), $off + strlen($body), $len);
            $body .= $pad;
        }
        $font .= $dir . $body;
        $adj = (0xB1B0AFBA - self::checksum($font)) & 0xFFFFFFFF;
        $hp = strpos($dir, 'head');
        $head_off = unpack('N', substr($dir, $hp + 8, 4))[1];
        return substr_replace($font, pack('N', $adj), $head_off + 8, 4);
    }

    private static function checksum($s) {
        $s = str_pad($s, (strlen($s) + 3) & ~3, "\0");
        $sum = 0;
        foreach (unpack('N*', $s) as $v) {
            $sum = ($sum + $v) & 0xFFFFFFFF;
        }
        return $sum;
    }
}
