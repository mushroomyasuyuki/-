<?php
/**
 * 消費税。プランの金額は「税抜」で管理し、お客様への表示と PAY.JP への請求額は「税込」にする。
 * 消費税額の端数は、切り捨て。税率は、管理画面（プラン・決済設定）で変えられる（初期値 10%）。
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Tax {
    public static function rate() {
        if (defined('SS_TAX_RATE')) {
            $r = (int) SS_TAX_RATE;
        } else {
            $r = (int) get_option('ss_tax_rate', 10);
        }
        return max(0, min(30, $r));
    }

    /** 税抜の金額 → 税込の金額（消費税額は切り捨て） */
    public static function incl($price_ex) {
        $p = (int) $price_ex;
        return $p + (int) floor($p * self::rate() / 100);
    }

    public static function yen($n) {
        return number_format((int) $n) . '円';
    }
}
