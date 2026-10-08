<?php
/**
 * PAY.JP のAPI（https://api.pay.jp/v1/）との通信。
 *
 * - 認証：HTTP Basic（ユーザー名にシークレットキー、パスワードは空）
 * - カード情報は、ブラウザ側の payjp.js でトークン化したものだけを扱う（サーバーにカード番号は届かない）
 * - 送り先・金額・プランIDなどは、すべてこのクラスを通す（テストでは $transport を差し替える）
 *
 * 【要確認】PAY.JPの正式な仕様のうち、次の点は、テストモードで動作を確かめること：
 *  解約・一時停止の効力が出る時期／再開（resume）の課金のタイミング
 * 【確認済み（テストモード）】プラン更新APIは、無料期間中でも即時課金される → 使わない。一時停止・再開・カード変更では課金されない。
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Payjp {
    const BASE = 'https://api.pay.jp/v1/';

    /** テスト用：function ($method, $url, $args) を指定すると、実際には通信せずにこれを呼ぶ */
    public static $transport = null;

    private static function opt($const, $option) {
        return defined($const) ? (string) constant($const) : (string) get_option($option, '');
    }

    public static function secret_key() { return self::opt('SS_PAYJP_SECRET_KEY', 'ss_payjp_secret'); }
    public static function public_key() { return self::opt('SS_PAYJP_PUBLIC_KEY', 'ss_payjp_public'); }
    public static function webhook_token() { return self::opt('SS_PAYJP_WEBHOOK_TOKEN', 'ss_payjp_webhook_token'); }

    public static function configured() {
        return self::secret_key() !== '' && self::public_key() !== '';
    }

    /** @return array|WP_Error */
    public static function request($method, $path, array $params = array()) {
        $secret = self::secret_key();
        if ($secret === '') {
            return new WP_Error('not_configured', '決済の設定がまだ完了していません。');
        }
        $url = self::BASE . ltrim($path, '/');
        $args = array(
            'method'  => $method,
            'timeout' => 30,
            'headers' => array('Authorization' => 'Basic ' . base64_encode($secret . ':')),
        );
        if ($method === 'GET') {
            if ($params) {
                $url .= '?' . http_build_query($params);
            }
        } else {
            $args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
            $args['body'] = http_build_query($params);
        }
        $res = self::$transport ? call_user_func(self::$transport, $method, $url, $args) : wp_remote_request($url, $args);
        if (is_wp_error($res)) {
            return new WP_Error('network', '決済サービスに接続できませんでした。時間をおいて、もう一度お試しください。');
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        if ($code >= 400 || !is_array($body)) {
            $err = is_array($body) && isset($body['error']) && is_array($body['error']) ? $body['error'] : array();
            $type = isset($err['type']) ? (string) $err['type'] : '';
            $ecode = isset($err['code']) ? (string) $err['code'] : '';
            // 運営者が原因を調べられるよう、直近のエラーを記録する（キーやカード情報は含まない）
            function_exists('update_option') && update_option('ss_last_payjp_error', array(
                'at'      => gmdate('Y-m-d H:i:s'),
                'request' => $method . ' ' . preg_replace('/(tok|cus|sub|pln|ch)_[A-Za-z0-9]+/', '$1_…', ltrim($path, '/')),
                'http'    => $code,
                'type'    => $type,
                'code'    => $ecode,
                'message' => isset($err['message']) ? mb_substr((string) $err['message'], 0, 300) : '',
            ), false);
            return new WP_Error('payjp_' . ($ecode !== '' ? $ecode : ($type !== '' ? $type : 'error')), self::friendly($type, $ecode), array('status' => $code ? $code : 502, 'payjp' => $err));
        }
        return $body;
    }

    /** お客様に見せるエラー文（PAY.JPの文面をそのまま出さない） */
    public static function friendly($type, $code) {
        $map = array(
            'invalid_number'       => 'カード番号が正しくありません。',
            'incorrect_number'     => 'カード番号が正しくありません。',
            'invalid_cvc'          => 'セキュリティコードが正しくありません。',
            'invalid_expiry_month' => 'カードの有効期限（月）が正しくありません。',
            'invalid_expiry_year'  => 'カードの有効期限（年）が正しくありません。',
            'expired_card'         => 'カードの有効期限が切れています。',
            'card_declined'        => 'カードが承認されませんでした。別のカードをお試しいただくか、カード会社にご確認ください。',
            'processing_error'     => 'カードの処理中にエラーが発生しました。時間をおいて、もう一度お試しください。',
        );
        if (isset($map[$code])) {
            return $map[$code];
        }
        if ($type === 'card_error') {
            return 'カードを処理できませんでした。カード番号・有効期限・セキュリティコードをご確認ください。';
        }
        return '決済サービスでエラーが発生しました。時間をおいて、もう一度お試しください。';
    }

    /* ---------- 各API ---------- */

    public static function create_plan($amount, $name) {
        return self::request('POST', 'plans', array('amount' => (int) $amount, 'currency' => 'jpy', 'interval' => 'month', 'name' => (string) $name));
    }

    public static function create_customer($email, $description, $card_token, array $metadata) {
        return self::request('POST', 'customers', array('email' => $email, 'description' => $description, 'card' => $card_token, 'metadata' => $metadata));
    }

    public static function update_customer_card($customer_id, $card_token) {
        return self::request('POST', 'customers/' . rawurlencode($customer_id), array('card' => $card_token));
    }

    public static function get_token($id) {
        return self::request('GET', 'tokens/' . rawurlencode($id));
    }

    public static function get_customer($id) {
        return self::request('GET', 'customers/' . rawurlencode($id));
    }

    public static function set_default_card($customer_id, $card_id) {
        return self::request('POST', 'customers/' . rawurlencode($customer_id), array('default_card' => $card_id));
    }

    /** $trial_end：Unix時刻。指定すると、その時刻まで課金しない。 */
    public static function create_subscription($customer_id, $plan_id, $trial_end, array $metadata) {
        $p = array('customer' => $customer_id, 'plan' => $plan_id, 'metadata' => $metadata);
        if ($trial_end) {
            $p['trial_end'] = (int) $trial_end;
        }
        return self::request('POST', 'subscriptions', $p);
    }

    /** お客様の定期課金の一覧（プランで絞り込み） */
    public static function list_subscriptions($customer_id, $plan_id = '') {
        $p = array('customer' => $customer_id, 'limit' => 20);
        if ($plan_id !== '') {
            $p['plan'] = $plan_id;
        }
        return self::request('GET', 'subscriptions', $p);
    }

    public static function get_subscription($id) {
        return self::request('GET', 'subscriptions/' . rawurlencode($id));
    }

    // 注意：PAY.JPの「定期課金のプラン変更（subscriptions/:id の plan 更新）」は使わない。
    // テストモードで、無料期間中でも新しいプランの料金が、すぐに課金されることを確認したため。
    // プラン変更は、SS_Billing::change_plan() が「一時停止→新しい定期課金を作る→古い定期課金を解約」で行う。

    public static function pause_subscription($id) {
        return self::request('POST', 'subscriptions/' . rawurlencode($id) . '/pause');
    }

    /** $trial_end を指定すると、その時刻まで課金せずに再開する。 */
    public static function resume_subscription($id, $trial_end = null) {
        return self::request('POST', 'subscriptions/' . rawurlencode($id) . '/resume', $trial_end ? array('trial_end' => (int) $trial_end) : array());
    }

    public static function cancel_subscription($id) {
        return self::request('POST', 'subscriptions/' . rawurlencode($id) . '/cancel');
    }
}
