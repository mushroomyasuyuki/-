<?php
/** ライセンスキーの発行と正規化。形式：MOS-XXXX-XXXX-XXXX-XXXX（紛らわしい文字 0/O/1/I は使わない） */

if (!defined('ABSPATH')) {
    exit;
}

final class MCO_Keys {
    const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function generate() {
        $parts = array();
        for ($g = 0; $g < 4; $g++) {
            $s = '';
            for ($i = 0; $i < 4; $i++) {
                $s .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $parts[] = $s;
        }
        return 'MOS-' . implode('-', $parts);
    }

    /** ①は、キーを大文字・前後の空白なしにして送ってくる。こちらも同じ形にそろえる。 */
    public static function normalize($key) {
        return strtoupper(trim(preg_replace('/\s+/', '', (string) $key)));
    }

    public static function looks_valid($key) {
        return (bool) preg_match('/^MOS(-[A-HJ-NP-Z2-9]{4}){4}$/', (string) $key);
    }
}
