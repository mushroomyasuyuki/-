<?php
/**
 * 料金・無料期間・請求・支払い・支払い失敗・プラン変更・解約の説明文。
 * システムの設定（プラン・税率・無料期間・猶予日数）から作るので、実際の動作と食い違わない。
 * 登録ページ・ご契約の画面・管理画面の「コピー用の文面」で、同じものを使う。
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Terms {
    const DEFAULT_REFUND = '日割りによる返金は行いません。';

    public static function refund_text() {
        $t = trim((string) get_option('ss_refund_text', ''));
        return $t !== '' ? $t : self::DEFAULT_REFUND;
    }

    /** 公開中のプランの、料金の説明の行 */
    public static function plan_lines() {
        $lines = array();
        foreach (SS_Tenants::plans() as $p) {
            $lines[] = $p['name'] . '：月額' . SS_Tax::yen(SS_Tax::incl($p['price'])) . '（税込。税抜' . SS_Tax::yen($p['price']) . '）、スタッフ' . (int) $p['max_staff'] . '名まで';
        }
        return $lines;
    }

    /** @return array [['title' => 見出し, 'body' => 本文（改行あり）], ...] */
    public static function sections() {
        $months = (int) SS_TRIAL_MONTHS;
        $grace = (int) SS_Billing::GRACE_DAYS;
        $rate = SS_Tax::rate();
        $plans = self::plan_lines();
        $price_body = ($plans ? implode("\n", $plans) . "\n" : '') . '消費税率は' . $rate . '％です（表示の金額は税込です）。お支払いは、毎月、同じ日に請求されます。';

        return array(
            array('title' => '料金', 'body' => $price_body),
            array('title' => '無料期間', 'body' => '登録の日から' . $months . 'か月間は、すべての機能を無料でご利用いただけます。カード情報の登録は不要です。' . "\n"
                . '無料期間が終了すると、お支払い方法とプランをご登録いただくまで、閲覧のみとなります（データは削除されません）。'),
            array('title' => '請求の開始', 'body' => '無料期間中にお申し込みいただいた場合は、無料期間の終了日から請求が始まります。それまでは、お支払いは発生しません。' . "\n"
                . '無料期間の終了後にお申し込みいただいた場合は、お申し込みの時点で最初の請求が行われ、以後、毎月同じ日に請求されます。'),
            array('title' => 'お支払い方法', 'body' => 'クレジットカード（決済代行サービス「PAY.JP」を通じて処理します）。カード情報は、PAY.JPに直接送信され、当サービスでは受け取ることも、保存することもありません。'),
            array('title' => 'お支払いに失敗したとき', 'body' => '請求日にカードのお支払いを確認できなかった場合は、メールでお知らせします。その日から' . $grace . '日間は、引き続きご利用いただけます（お支払い確認中）。' . "\n"
                . 'この間にカード情報を変更・再登録いただければ、すぐに通常の状態に戻ります。' . $grace . '日を過ぎてもお支払いを確認できない場合は、閲覧のみとなります（データは削除されません）。'),
            array('title' => 'プランの変更', 'body' => 'ご契約・お支払いの画面から、いつでも変更できます。新しい料金は、次回の請求日から適用されます（変更の時点で請求は発生しません）。' . "\n"
                . 'スタッフの人数が、変更後のプランの上限を超える場合は、変更できません。'),
            array('title' => '解約', 'body' => 'ご契約・お支払いの画面から、いつでも解約できます。解約をお申し込みいただくと、以後の請求は停止し、お支払い済みの期間の終了日まで、引き続きご利用いただけます。' . "\n"
                . '解約の期間が終了すると、閲覧のみとなります（データは削除されません。再度ご契約いただくと、すぐに編集できます）。' . "\n" . self::refund_text()),
            array('title' => '料金の改定', 'body' => '料金を改定する場合は、事前にお知らせします。'),
        );
    }

    /** 管理画面でコピーして貼るための、1つの文章 */
    public static function plain_text() {
        $out = array();
        foreach (self::sections() as $s) {
            $out[] = '■' . $s['title'] . "\n" . $s['body'];
        }
        return implode("\n\n", $out) . "\n";
    }
}
