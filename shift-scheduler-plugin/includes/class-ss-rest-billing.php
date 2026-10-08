<?php
/**
 * REST API：ご契約・お支払い（お客様の管理者向け）と、PAY.JP からの通知（Webhook）の受け口。
 * 契約の画面は、無料期間が終わって「閲覧のみ」になったお客様も使える（そこから契約する）。
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Rest_Billing {
    const NS = 'shift/v1';

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'routes'));
    }

    public static function routes() {
        $perm = array(__CLASS__, 'can_manage_billing');
        register_rest_route(self::NS, '/billing', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'get_billing'), 'permission_callback' => $perm),
        ));
        foreach (array('subscribe' => 'subscribe', 'card' => 'update_card', 'plan' => 'change_plan', 'cancel' => 'cancel', 'cancel/undo' => 'undo_cancel') as $path => $cb) {
            register_rest_route(self::NS, '/billing/' . $path, array(
                array('methods' => 'POST', 'callback' => array(__CLASS__, $cb), 'permission_callback' => $perm),
            ));
        }
        register_rest_route(self::NS, '/payjp/webhook', array(
            array('methods' => 'POST', 'callback' => array(__CLASS__, 'webhook'), 'permission_callback' => '__return_true'),
        ));
    }

    public static function can_manage_billing() {
        return is_user_logged_in() && current_user_can('shift_manage_billing') && SS_Context::tenant_id() > 0;
    }

    /* ---------- 画面に必要な情報 ---------- */

    public static function get_billing() {
        $tenant = SS_Context::tenant();
        $count = SS_Billing::staff_count($tenant['id']);
        $b = SS_Billing::billing_settings($tenant);
        $plans = array();
        foreach (SS_Tenants::plans() as $p) {
            $plans[] = array(
                'id' => (int) $p['id'], 'name' => $p['name'], 'price' => SS_Tax::incl($p['price']), 'price_ex' => (int) $p['price'], 'max_staff' => (int) $p['max_staff'],
                'fits' => SS_Billing::fits($p, $count), 'ready' => SS_Billing::plan_ready($p),
            );
        }
        return rest_ensure_response(array(
            'configured'   => SS_Payjp::configured(),
            'public_key'   => SS_Payjp::public_key(),
            'status'       => SS_Tenants::effective_status($tenant),
            'trial_end'    => $tenant['trial_end'],
            'trial_days_left' => SS_Tenants::trial_days_left($tenant),
            'trial_running' => SS_Billing::trial_running($tenant),
            'has_subscription' => $tenant['payjp_subscription_id'] !== '',
            'plan_id'      => $tenant['plan_id'] ? (int) $tenant['plan_id'] : null,
            'next_billing_at' => $tenant['next_billing_at'],
            'cancel_at'    => isset($b['cancel_at']) ? $b['cancel_at'] : null,
            'grace_since'  => isset($b['grace_since']) ? $b['grace_since'] : null,
            'staff_count'  => $count,
            'plans'        => $plans,
            'tax_rate'     => SS_Tax::rate(),
            'terms'        => SS_Terms::sections(),
        ));
    }

    /* ---------- 操作 ---------- */

    private static function body(WP_REST_Request $req) {
        $p = $req->get_json_params();
        return is_array($p) ? $p : array();
    }

    private static function done($result) {
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;
            return new WP_Error($result->get_error_code(), $result->get_error_message(), array('status' => $status >= 400 && $status < 600 ? ($status >= 500 ? 502 : 400) : 400));
        }
        return rest_ensure_response(array('ok' => true));
    }

    public static function subscribe(WP_REST_Request $req) {
        $p = self::body($req);
        $plan = SS_Billing::plan(isset($p['plan_id']) ? (int) $p['plan_id'] : 0);
        if (!$plan) {
            return new WP_Error('not_found', 'プランが見つかりません。', array('status' => 404));
        }
        return self::done(SS_Billing::subscribe(SS_Context::tenant(), $plan, isset($p['card_token']) ? (string) $p['card_token'] : ''));
    }

    public static function update_card(WP_REST_Request $req) {
        $p = self::body($req);
        return self::done(SS_Billing::update_card(SS_Context::tenant(), isset($p['card_token']) ? (string) $p['card_token'] : ''));
    }

    public static function change_plan(WP_REST_Request $req) {
        $p = self::body($req);
        $plan = SS_Billing::plan(isset($p['plan_id']) ? (int) $p['plan_id'] : 0);
        if (!$plan) {
            return new WP_Error('not_found', 'プランが見つかりません。', array('status' => 404));
        }
        return self::done(SS_Billing::change_plan(SS_Context::tenant(), $plan));
    }

    public static function cancel() {
        return self::done(SS_Billing::cancel(SS_Context::tenant()));
    }

    public static function undo_cancel() {
        return self::done(SS_Billing::undo_cancel(SS_Context::tenant()));
    }

    /* ---------- Webhook ---------- */

    /**
     * PAY.JP からの通知。ヘッダー X-Payjp-Webhook-Token が、設定したトークンと一致するものだけを受け付ける。
     * 処理に失敗したときは 5xx を返す（PAY.JPが、一定の間隔で数回、再送する）。
     */
    public static function webhook(WP_REST_Request $req) {
        $expected = SS_Payjp::webhook_token();
        $given = (string) $req->get_header('x-payjp-webhook-token');
        if ($expected === '' || !hash_equals($expected, $given)) {
            return new WP_Error('forbidden', '認証に失敗しました。', array('status' => 403));
        }
        $event = $req->get_json_params();
        if (!is_array($event)) {
            return new WP_Error('invalid', '形式が正しくありません。', array('status' => 400));
        }
        $r = SS_Billing::handle_event($event);
        if (is_wp_error($r)) {
            $data = $r->get_error_data();
            $status = is_array($data) && isset($data['status']) && (int) $data['status'] === 400 ? 400 : 500;
            return new WP_Error($r->get_error_code(), $r->get_error_message(), array('status' => $status));
        }
        return rest_ensure_response(array('ok' => true));
    }
}
