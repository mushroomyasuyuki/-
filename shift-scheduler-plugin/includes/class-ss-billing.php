<?php
/**
 * 契約・お支払い（無料期間→サブスクリプション）の処理。PAY.JP の定期課金を使う。
 *
 * 状態の決め方：
 *  - PAY.JP からの通知（Webhook）の内容は信用せず、必ずPAY.JPに問い合わせた最新の状態で決める。
 *  - 通知が届かなくても、毎日の同期（SS_Billing::daily）で追いつく。
 *
 * お客様（テナント）の状態：trial（無料期間中）／active（ご契約中）／grace（お支払い確認中）／readonly（閲覧のみ）
 *  - subscription: trial  → trial（無料期間が続いている間）または active
 *  - subscription: active → active
 *  - subscription: paused → grace（解約予約中なら、期間末まで今の状態）
 *  - subscription: canceled → 無料期間が残っていれば trial、なければ readonly
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Billing {
    const GRACE_DAYS = 7;
    const TRIAL_MAIL_DAYS = array(14, 7, 1);

    /* ---------- 補助 ---------- */

    public static function tenant($id) {
        return SS_System::tenant_by_id((int) $id);
    }

    private static function db_update($tenant_id, array $data) {
        global $wpdb;
        return $wpdb->update(SS_System::table('tenants'), $data, array('id' => (int) $tenant_id)) !== false;
    }

    public static function billing_settings(array $tenant) {
        $s = SS_Tenants::settings($tenant);
        return isset($s['billing']) && is_array($s['billing']) ? $s['billing'] : array();
    }

    /** お支払い関連の控え（解約の予約・支払い猶予の開始日・通知済みの記録など）を更新する。null を渡した項目は消す。 */
    public static function set_billing($tenant_id, array $changes) {
        $tenant = self::tenant($tenant_id);
        if (!$tenant) {
            return false;
        }
        $b = array_merge(self::billing_settings($tenant), $changes);
        foreach ($b as $k => $v) {
            if ($v === null) {
                unset($b[$k]);
            }
        }
        return SS_Tenants::save_settings($tenant_id, array('billing' => $b));
    }

    public static function staff_count($tenant_id) {
        global $wpdb;
        $t = SS_System::table('staff');
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE tenant_id = %d AND active = 1", (int) $tenant_id));
    }

    public static function owner_email($tenant_id) {
        global $wpdb;
        $t = SS_System::table('users');
        return (string) $wpdb->get_var($wpdb->prepare("SELECT email FROM {$t} WHERE tenant_id = %d AND role = 'owner' ORDER BY id ASC LIMIT 1", (int) $tenant_id));
    }

    public static function plan($id) {
        global $wpdb;
        $t = SS_System::table('plans');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $id), ARRAY_A);
    }

    /** お客様が選べるプラン：有効で、PAY.JP側のプランの金額が、画面に出す金額（税込）と一致しているもの */
    public static function plan_ready(array $plan) {
        return (int) $plan['active'] === 1 && $plan['payjp_plan_id'] !== '' && (int) $plan['payjp_amount'] === SS_Tax::incl($plan['price']);
    }

    public static function fits(array $plan, $staff_count) {
        return $staff_count <= (int) $plan['max_staff'];
    }

    public static function trial_running(array $tenant) {
        return !empty($tenant['trial_end']) && strtotime($tenant['trial_end'] . ' UTC') > time();
    }

    private static function ts_to_date($ts) {
        return $ts ? gmdate('Y-m-d H:i:s', (int) $ts) : null;
    }

    /* ---------- 契約 ---------- */

    /** @return array|WP_Error 更新後のお客様 */
    public static function subscribe(array $tenant, array $plan, $card_token) {
        if (!SS_Payjp::configured()) {
            return new WP_Error('not_configured', 'お支払いの準備中です。しばらくしてからお試しください。', array('status' => 503));
        }
        if (!empty($tenant['payjp_subscription_id'])) {
            return new WP_Error('already', 'すでにご契約中です。', array('status' => 409));
        }
        if (!self::plan_ready($plan)) {
            return new WP_Error('plan_not_ready', 'このプランは、現在お選びいただけません。', array('status' => 400));
        }
        $count = self::staff_count($tenant['id']);
        if (!self::fits($plan, $count)) {
            return new WP_Error('plan_too_small', 'スタッフが' . $count . '名いるため、「' . $plan['name'] . '」（' . $plan['max_staff'] . '名まで）は選べません。', array('status' => 400));
        }
        if (!is_string($card_token) || !preg_match('/^tok_[A-Za-z0-9]+$/', $card_token)) {
            return new WP_Error('invalid_card', 'カード情報が正しく受け取れませんでした。もう一度入力してください。', array('status' => 400));
        }
        $meta = array('tenant_id' => (int) $tenant['id'], 'tenant_public_id' => $tenant['public_id']);

        $customer_id = $tenant['payjp_customer_id'];
        if ($customer_id === '') {
            $c = SS_Payjp::create_customer(self::owner_email($tenant['id']), $tenant['name'], $card_token, $meta);
            if (is_wp_error($c)) {
                return $c;
            }
            $customer_id = (string) $c['id'];
            self::db_update($tenant['id'], array('payjp_customer_id' => $customer_id)); // 失敗してやり直しても、顧客が重複しないよう先に保存
        } else {
            $c = SS_Payjp::update_customer_card($customer_id, $card_token);
            // 解約後の再契約などで、同じカードがすでにある場合はエラーにせず、そのカードを使う
            if (is_wp_error($c) && $c->get_error_code() !== 'payjp_already_have_card') {
                return $c;
            }
            self::use_card_as_default($customer_id, $card_token);
        }

        // 無料期間が残っているときは、その終了まで課金しない
        $trial_end = self::trial_running($tenant) && strtotime($tenant['trial_end'] . ' UTC') > time() + 3600 ? strtotime($tenant['trial_end'] . ' UTC') : null;
        $sub = SS_Payjp::create_subscription($customer_id, $plan['payjp_plan_id'], $trial_end, $meta);
        if (is_wp_error($sub) && $sub->get_error_code() === 'payjp_already_subscribed') {
            // PAY.JPに同じプランの契約が残っている場合：有効なものはそのまま取り込み、
            // キャンセル済みのもの（PAY.JPでは新規作成が拒否される）は、再開して使う
            $found = self::find_subscription_for_plan($customer_id, $plan['payjp_plan_id']);
            if ($found) {
                if ($found['status'] === 'canceled') {
                    $found = SS_Payjp::resume_subscription($found['id'], $trial_end);
                }
                $sub = $found;
            }
        }
        if (is_wp_error($sub)) {
            return $sub;
        }
        self::db_update($tenant['id'], array('payjp_subscription_id' => (string) $sub['id'], 'plan_id' => (int) $plan['id']));
        self::set_billing($tenant['id'], array('payjp_plan_id' => $plan['payjp_plan_id'], 'cancel_at' => null, 'grace_since' => null));
        return self::apply_subscription_state(self::tenant($tenant['id']), $sub);
    }

    /** お客様の、そのプランの定期課金を探す。有効（無料期間中・利用中・停止中）を優先し、なければ新しいキャンセル済み。なければ null */
    private static function find_subscription_for_plan($customer_id, $payjp_plan_id) {
        $list = SS_Payjp::list_subscriptions($customer_id, $payjp_plan_id);
        if (is_wp_error($list) || empty($list['data']) || !is_array($list['data'])) {
            return null;
        }
        $canceled = null;
        foreach ($list['data'] as $sub) {
            if (!isset($sub['id'], $sub['status'])) {
                continue;
            }
            if (in_array($sub['status'], array('trial', 'active', 'paused'), true)) {
                return $sub;
            }
            if ($sub['status'] === 'canceled' && $canceled === null) {
                $canceled = $sub; // 一覧は新しい順
            }
        }
        return $canceled;
    }

    /** カードの変更。お支払いに失敗して止まっている契約は、新しいカードで再開する。 */
    public static function update_card(array $tenant, $card_token) {
        if ($tenant['payjp_customer_id'] === '') {
            return new WP_Error('no_customer', 'ご契約の情報が見つかりません。', array('status' => 400));
        }
        if (!is_string($card_token) || !preg_match('/^tok_[A-Za-z0-9]+$/', $card_token)) {
            return new WP_Error('invalid_card', 'カード情報が正しく受け取れませんでした。もう一度入力してください。', array('status' => 400));
        }
        $c = SS_Payjp::update_customer_card($tenant['payjp_customer_id'], $card_token);
        // すでに同じカードが登録されている場合（やり直しなど）は、エラーにせず、そのカードを使うカードにして続ける
        if (is_wp_error($c) && $c->get_error_code() !== 'payjp_already_have_card') {
            return $c;
        }
        self::use_card_as_default($tenant['payjp_customer_id'], $card_token);
        $b = self::billing_settings($tenant);
        if ($tenant['payjp_subscription_id'] !== '' && empty($b['cancel_at'])) {
            $sub = SS_Payjp::get_subscription($tenant['payjp_subscription_id']);
            if (is_wp_error($sub)) {
                return $sub;
            }
            if (isset($sub['status']) && $sub['status'] === 'paused') {
                $sub = SS_Payjp::resume_subscription($tenant['payjp_subscription_id']);
                if (is_wp_error($sub)) {
                    return $sub;
                }
            }
            return self::apply_subscription_state(self::tenant($tenant['id']), $sub);
        }
        return $tenant;
    }

    /**
     * 登録したカードを、課金に使うカードにする（PAY.JPでは、追加しただけでは切り替わらない場合がある）。
     * 調べられないときは何もしない（これまでの動作のまま）。
     */
    private static function use_card_as_default($customer_id, $card_token) {
        $tok = SS_Payjp::get_token($card_token);
        $cus = SS_Payjp::get_customer($customer_id);
        if (is_wp_error($tok) || is_wp_error($cus) || empty($tok['card']['fingerprint']) || empty($cus['cards']['data'])) {
            return;
        }
        $t = $tok['card'];
        foreach ($cus['cards']['data'] as $card) {
            if (isset($card['fingerprint'], $card['exp_month'], $card['exp_year'], $card['id'])
                && $card['fingerprint'] === $t['fingerprint']
                && (int) $card['exp_month'] === (int) $t['exp_month'] && (int) $card['exp_year'] === (int) $t['exp_year']) {
                if (!isset($cus['default_card']) || $cus['default_card'] !== $card['id']) {
                    SS_Payjp::set_default_card($customer_id, $card['id']);
                }
                return;
            }
        }
    }

    /**
     * プラン変更：新しい料金は、「次回の請求日」から。今すぐ課金されることはない。
     *
     * PAY.JPのプラン更新APIは、無料期間中でも新プランの料金を即時課金する（テストモードで確認）ため使わず、
     *   1) 今の定期課金を一時停止（請求を止める）
     *   2) 新しいプランで、新しい定期課金を作る（最初の請求日 = 無料期間の終了日、または現在の期間の終了日）
     *   3) 古い定期課金を解約
     * の順に行う。どこで失敗しても二重請求にならない：
     *   2で失敗 → 古い定期課金を、元の請求日で再開して終了／3で失敗 → 古いほうは停止のまま（請求なし）で、毎日の処理が解約をやり直す。
     */
    public static function change_plan(array $tenant, array $plan) {
        if ($tenant['payjp_subscription_id'] === '') {
            return new WP_Error('no_subscription', 'ご契約がありません。', array('status' => 400));
        }
        if (!self::plan_ready($plan)) {
            return new WP_Error('plan_not_ready', 'このプランは、現在お選びいただけません。', array('status' => 400));
        }
        $count = self::staff_count($tenant['id']);
        if (!self::fits($plan, $count)) {
            return new WP_Error('plan_too_small', 'スタッフが' . $count . '名いるため、「' . $plan['name'] . '」（' . $plan['max_staff'] . '名まで）には変更できません。', array('status' => 400));
        }
        $b = self::billing_settings($tenant);
        if ((int) $tenant['plan_id'] === (int) $plan['id'] && isset($b['payjp_plan_id']) && $b['payjp_plan_id'] === $plan['payjp_plan_id']) {
            return $tenant; // すでにこのプラン（この金額）なので、何もしない
        }
        if (!empty($b['cancel_at'])) {
            return new WP_Error('cancel_reserved', '解約を受け付けているため、プランは変更できません。先に解約の取り消しをしてください。', array('status' => 409));
        }
        $old_id = $tenant['payjp_subscription_id'];
        $cur = SS_Payjp::get_subscription($old_id);
        if (is_wp_error($cur)) {
            return $cur;
        }
        $st = isset($cur['status']) ? (string) $cur['status'] : '';
        if ($st === 'paused') {
            return new WP_Error('paused', 'お支払いを確認できていないため、先にカードを変更してください。', array('status' => 409));
        }
        if ($st !== 'trial' && $st !== 'active') {
            return new WP_Error('not_active', 'ご契約の状態を確認できませんでした。時間をおいて、もう一度お試しください。', array('status' => 409));
        }
        $until = $st === 'trial' ? (int) (isset($cur['trial_end']) ? $cur['trial_end'] : 0) : (int) (isset($cur['current_period_end']) ? $cur['current_period_end'] : 0);
        $trial_end = $until > time() + 3600 ? $until : null; // これまでの期間の終わりまでは請求しない

        $paused = SS_Payjp::pause_subscription($old_id);
        if (is_wp_error($paused)) {
            return $paused;
        }
        $meta = array('tenant_id' => (int) $tenant['id'], 'tenant_public_id' => $tenant['public_id']);
        $new = SS_Payjp::create_subscription($tenant['payjp_customer_id'], $plan['payjp_plan_id'], $trial_end, $meta);
        if (is_wp_error($new)) {
            SS_Payjp::resume_subscription($old_id, $trial_end); // 元に戻す（請求日は変えない）
            return $new;
        }
        self::db_update($tenant['id'], array('payjp_subscription_id' => (string) $new['id'], 'plan_id' => (int) $plan['id']));
        self::set_billing($tenant['id'], array('payjp_plan_id' => $plan['payjp_plan_id'], 'cancel_old_sub' => $old_id));
        $c = SS_Payjp::cancel_subscription($old_id);
        if (!is_wp_error($c)) {
            self::set_billing($tenant['id'], array('cancel_old_sub' => null));
        }
        return self::apply_subscription_state(self::tenant($tenant['id']), $new);
    }

    /**
     * 解約の予約：以後の請求を止め（一時停止）、今の期間の終わりまでは利用できる。
     * 期間の終わりを過ぎたら、毎日の処理（daily）で「閲覧のみ」にし、PAY.JP側も解約する。
     */
    public static function cancel(array $tenant) {
        if ($tenant['payjp_subscription_id'] === '') {
            return new WP_Error('no_subscription', 'ご契約がありません。', array('status' => 400));
        }
        $b = self::billing_settings($tenant);
        if (!empty($b['cancel_at'])) {
            return new WP_Error('already', 'すでに解約を受け付けています。', array('status' => 409));
        }
        $sub = SS_Payjp::get_subscription($tenant['payjp_subscription_id']);
        if (is_wp_error($sub)) {
            return $sub;
        }
        $end_ts = (isset($sub['status']) && $sub['status'] === 'trial')
            ? (int) (isset($sub['trial_end']) ? $sub['trial_end'] : 0)
            : (int) (isset($sub['current_period_end']) ? $sub['current_period_end'] : 0);
        if ($end_ts <= time()) {
            $end_ts = time(); // 期間末が分からない・過ぎている場合は、すぐに終了
        }
        $paused = SS_Payjp::pause_subscription($tenant['payjp_subscription_id']);
        if (is_wp_error($paused)) {
            return $paused;
        }
        self::set_billing($tenant['id'], array('cancel_at' => self::ts_to_date($end_ts)));
        return self::tenant($tenant['id']);
    }

    /** 解約の取り消し：請求を、期間末から再開する。 */
    public static function undo_cancel(array $tenant) {
        $b = self::billing_settings($tenant);
        if ($tenant['payjp_subscription_id'] === '' || empty($b['cancel_at'])) {
            return new WP_Error('no_cancel', '解約の予約はありません。', array('status' => 400));
        }
        $end_ts = strtotime($b['cancel_at'] . ' UTC');
        $sub = SS_Payjp::resume_subscription($tenant['payjp_subscription_id'], $end_ts > time() + 3600 ? $end_ts : null);
        if (is_wp_error($sub)) {
            return $sub;
        }
        self::set_billing($tenant['id'], array('cancel_at' => null));
        return self::apply_subscription_state(self::tenant($tenant['id']), $sub);
    }

    /* ---------- 状態の反映 ---------- */

    /** PAY.JP の定期課金の状態を、お客様の状態に反映する。 */
    public static function apply_subscription_state(array $tenant, array $sub) {
        $status = isset($sub['status']) ? (string) $sub['status'] : '';
        $b = self::billing_settings($tenant);
        $prev = $tenant['status'];
        $new = $prev;
        $data = array();
        $changes = array();

        if (in_array($prev, array('suspended', 'unverified'), true)) {
            $new = $prev; // 運営が止めているお客様の状態は、決済の通知では変えない
        } elseif ($status === 'trial') {
            $new = self::trial_running($tenant) ? 'trial' : 'active';
        } elseif ($status === 'active') {
            $new = 'active';
            $changes['grace_since'] = null;
        } elseif ($status === 'paused') {
            if (!empty($b['cancel_at'])) {
                $new = $prev === 'grace' ? 'active' : $prev; // 解約予約のための停止：期間末まで、今の状態のまま
            } else {
                $new = 'grace';
                if (empty($b['grace_since'])) {
                    $changes['grace_since'] = SS_System::now();
                }
            }
        } elseif ($status === 'canceled') {
            $new = self::trial_running($tenant) ? 'trial' : 'readonly';
            $data['payjp_subscription_id'] = '';
            $changes['cancel_at'] = null;
            $changes['grace_since'] = null;
        }

        $next = null;
        if ($status === 'trial' && !empty($sub['trial_end'])) {
            $next = self::ts_to_date($sub['trial_end']);
        } elseif (!empty($sub['current_period_end'])) {
            $next = self::ts_to_date($sub['current_period_end']);
        }
        if ($status === 'canceled' || $status === 'paused') {
            $next = null;
        }
        $data['status'] = $new;
        $data['next_billing_at'] = $next;
        self::db_update($tenant['id'], $data);
        if ($changes) {
            self::set_billing($tenant['id'], $changes);
        }
        if ($new === 'grace' && $prev !== 'grace') {
            self::mail_payment_failed($tenant);
        }
        return self::tenant($tenant['id']);
    }

    /** PAY.JP に問い合わせて、最新の状態を反映する。 */
    public static function sync_tenant(array $tenant) {
        if ($tenant['payjp_subscription_id'] === '') {
            return $tenant;
        }
        $sub = SS_Payjp::get_subscription($tenant['payjp_subscription_id']);
        if (is_wp_error($sub)) {
            return $sub;
        }
        return self::apply_subscription_state($tenant, $sub);
    }

    /* ---------- Webhook ---------- */

    /**
     * PAY.JP からの通知を処理する。同じ通知は1回だけ処理する。
     * @return true|WP_Error  エラーのときは、PAY.JPが再送できるよう、呼び出し側で5xxを返す。
     */
    public static function handle_event(array $event) {
        global $wpdb;
        $event_id = isset($event['id']) ? (string) $event['id'] : '';
        $type = isset($event['type']) ? (string) $event['type'] : '';
        if ($event_id === '' || $type === '') {
            return new WP_Error('invalid', '通知の形式が正しくありません。', array('status' => 400));
        }
        $events = SS_System::table('billing_events');
        if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$events} WHERE event_id = %s", $event_id)) > 0) {
            return true; // 処理済み
        }

        $obj = isset($event['data']) && is_array($event['data']) ? $event['data'] : array();
        if (isset($obj['object']) && is_array($obj['object'])) {
            $obj = $obj['object'];
        }
        $sub_id = '';
        if (strpos($type, 'subscription.') === 0 && isset($obj['id'])) {
            $sub_id = (string) $obj['id'];
        } elseif (isset($obj['subscription']) && is_string($obj['subscription'])) {
            $sub_id = $obj['subscription']; // charge.* など
        }

        $tenant_id = null;
        if ($sub_id !== '') {
            $t = SS_System::table('tenants');
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE payjp_subscription_id = %s AND deleted_at IS NULL", $sub_id), ARRAY_A);
            if ($row) {
                $tenant_id = (int) $row['id'];
                $r = self::sync_tenant($row); // 通知の中身ではなく、最新の状態を取り直して反映
                if (is_wp_error($r)) {
                    return $r;
                }
            }
        }
        $wpdb->insert($events, array(
            'tenant_id' => $tenant_id, 'event_id' => $event_id, 'type' => $type,
            'payload' => wp_json_encode(array('type' => $type, 'sub' => $sub_id)), 'processed_at' => SS_System::now(),
        ));
        return true;
    }

    /* ---------- 毎日の処理 ---------- */

    public static function daily() {
        global $wpdb;
        $t = SS_System::table('tenants');
        $now = SS_System::now();

        // 1) 支払い猶予（お支払い確認中）が7日を過ぎたら、閲覧のみ
        foreach ($wpdb->get_results("SELECT * FROM {$t} WHERE status = 'grace' AND deleted_at IS NULL LIMIT 500", ARRAY_A) as $row) {
            $b = self::billing_settings($row);
            if (!empty($b['grace_since']) && strtotime($b['grace_since'] . ' UTC') < time() - self::GRACE_DAYS * 86400) {
                self::db_update($row['id'], array('status' => 'readonly'));
            }
        }

        // 2) 解約の予約が期間末に来たら、PAY.JP側も解約して、閲覧のみ（無料期間が残っていれば無料期間に戻る）
        foreach ($wpdb->get_results("SELECT * FROM {$t} WHERE payjp_subscription_id <> '' AND deleted_at IS NULL LIMIT 500", ARRAY_A) as $row) {
            $b = self::billing_settings($row);
            if (empty($b['cancel_at']) || strtotime($b['cancel_at'] . ' UTC') > time()) {
                continue;
            }
            if (SS_Payjp::configured()) {
                $r = SS_Payjp::cancel_subscription($row['payjp_subscription_id']);
                if (is_wp_error($r)) {
                    continue; // 明日もう一度試す
                }
                self::apply_subscription_state($row, array('status' => 'canceled'));
            }
        }

        // 2b) プラン変更のとき、古い定期課金の解約に失敗していたら、やり直す（古いほうは停止中なので、請求はされない）
        if (SS_Payjp::configured()) {
            foreach ($wpdb->get_results("SELECT * FROM {$t} WHERE settings LIKE '%cancel_old_sub%' AND deleted_at IS NULL LIMIT 100", ARRAY_A) as $row) {
                $b = self::billing_settings($row);
                if (!empty($b['cancel_old_sub'])) {
                    $r = SS_Payjp::cancel_subscription($b['cancel_old_sub']);
                    if (!is_wp_error($r)) {
                        self::set_billing($row['id'], array('cancel_old_sub' => null));
                    }
                }
            }
        }

        // 3) 通知の取りこぼしに備えて、更新日が近い・過ぎた契約を問い合わせて同期（1日に最大50件）
        if (SS_Payjp::configured()) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$t} WHERE payjp_subscription_id <> '' AND deleted_at IS NULL AND status IN ('trial','active','grace')
                 AND (next_billing_at IS NULL OR next_billing_at < %s) ORDER BY next_billing_at ASC LIMIT 50", gmdate('Y-m-d H:i:s', time() + 86400)), ARRAY_A);
            foreach ($rows as $row) {
                self::sync_tenant($row);
            }
        }

        // 4) 無料期間の終わりの案内メール（14日前・7日前・前日）
        self::send_trial_reminders();
    }

    public static function send_trial_reminders() {
        global $wpdb;
        $t = SS_System::table('tenants');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE status = 'trial' AND payjp_subscription_id = '' AND deleted_at IS NULL AND trial_end > %s AND trial_end < %s LIMIT 500",
            SS_System::now(), gmdate('Y-m-d H:i:s', time() + 15 * 86400)), ARRAY_A);
        foreach ($rows as $row) {
            $left = (int) ceil((strtotime($row['trial_end'] . ' UTC') - time()) / 86400);
            $b = self::billing_settings($row);
            $sent = isset($b['trial_mail']) && is_array($b['trial_mail']) ? $b['trial_mail'] : array();
            $m = null;
            foreach (array_reverse(self::TRIAL_MAIL_DAYS) as $d) { // 1, 7, 14 の順に、残り日数以上のうち最小のもの
                if ($d >= $left) {
                    $m = $d;
                    break;
                }
            }
            if ($m === null || in_array($m, $sent, true)) {
                continue;
            }
            foreach (self::TRIAL_MAIL_DAYS as $d) {
                if ($d >= $m && !in_array($d, $sent, true)) {
                    $sent[] = $d;
                }
            }
            self::set_billing($row['id'], array('trial_mail' => $sent));
            self::mail_trial_ending($row, $left);
        }
    }

    /* ---------- メール ---------- */

    private static function mail_trial_ending(array $tenant, $days_left) {
        $to = self::owner_email($tenant['id']);
        if ($to === '') {
            return;
        }
        $end = substr($tenant['trial_end'], 0, 10);
        SS_System::mail($to, '無料期間の終了のお知らせ', $tenant['name'] . " 様\n\n無料期間は " . $end . "（あと" . $days_left . "日）で終了します。\n終了後は、閲覧のみとなり、シフトの編集ができなくなります。\n引き続きご利用の場合は、下記から、プランとお支払い方法をご登録ください。\n無料期間中にご登録いただいても、請求は無料期間の終了後から始まります。\n\n" . SS_View::app_url($tenant['public_id'], 'billing') . "\n");
    }

    private static function mail_payment_failed(array $tenant) {
        // 運営者にも知らせる（どのお客様のお支払いが確認できなかったか）
        $admin = get_option('admin_email');
        if ($admin) {
            SS_System::mail($admin, '【運営向け】お支払いを確認できなかったお客様がいます', "お客様：" . $tenant['name'] . "（ID: " . $tenant['public_id'] . "）\n状態：お支払い確認中（" . self::GRACE_DAYS . "日以内にカード更新がない場合、閲覧のみになります）\n");
        }
        $to = self::owner_email($tenant['id']);
        if ($to === '') {
            return;
        }
        $sent = SS_System::mail($to, 'お支払いを確認できませんでした', $tenant['name'] . " 様\n\n今回のご請求のお支払いを確認できませんでした。\n" . self::GRACE_DAYS . "日以内に、下記からカード情報を更新してください。\n期限を過ぎると、閲覧のみとなります。\n\n" . SS_View::app_url($tenant['public_id'], 'billing') . "\n");
        if (!$sent) {
            error_log('[shift-scheduler] payment-failed mail could not be sent: tenant ' . $tenant['public_id']);
        }
    }

    /* ---------- 運営用：プランの金額変更後に、既存の契約者を新しい金額へ切り替える ---------- */

    /** プランの金額変更のあと、まだ旧金額のプランのままの契約者 */
    public static function stale_subscribers($plan_id) {
        global $wpdb;
        $plan = self::plan($plan_id);
        if (!$plan || $plan['payjp_plan_id'] === '') {
            return array();
        }
        $t = SS_System::table('tenants');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE plan_id = %d AND payjp_subscription_id <> '' AND deleted_at IS NULL ORDER BY id ASC", (int) $plan_id), ARRAY_A);
        return array_values(array_filter($rows, function ($r) use ($plan) {
            $b = SS_Billing::billing_settings($r);
            return !isset($b['payjp_plan_id']) || $b['payjp_plan_id'] !== $plan['payjp_plan_id'];
        }));
    }

    /**
     * 金額変更後、既存の契約者を新しい金額のプランへ切り替える（新しい料金は、各お客様の次回の請求日から）。
     * お支払い確認中・解約予約中のお客様は、いまは切り替えられないのでスキップする（状態が戻ってから、もう一度）。
     * @return array ['migrated' => 件数, 'failed' => 件数, 'skipped' => 件数, 'remaining' => 切り替えできる残り件数]
     */
    public static function migrate_plan_subscribers($plan_id, $limit = 20) {
        $plan = self::plan($plan_id);
        if (!$plan || !self::plan_ready($plan)) {
            return array('migrated' => 0, 'failed' => 0, 'skipped' => 0, 'remaining' => 0);
        }
        $todo = self::stale_subscribers($plan_id);
        $migrated = 0;
        $failed = 0;
        $skipped = 0;
        $processed = 0;
        foreach ($todo as $row) {
            if ($processed >= (int) $limit) {
                break;
            }
            $r = self::change_plan($row, $plan);
            if (!is_wp_error($r)) {
                $migrated++;
                $processed++;
            } elseif (in_array($r->get_error_code(), array('paused', 'cancel_reserved', 'not_active', 'plan_too_small'), true)) {
                $skipped++; // いまは切り替えられない状態
            } else {
                $failed++;
                $processed++;
            }
        }
        return array('migrated' => $migrated, 'failed' => $failed, 'skipped' => $skipped, 'remaining' => max(0, count($todo) - $migrated - $failed - $skipped));
    }

    /**
     * キーを「テスト⇔本番」で切り替えたとき：PAY.JPのプランIDは、モードごとに別物なので、反映済みの状態を消す。
     * （プランを保存し直すと、新しいモードのPAY.JPにプランが作られる）
     */
    public static function reset_plan_sync() {
        global $wpdb;
        $wpdb->query('UPDATE ' . SS_System::table('plans') . " SET payjp_plan_id = '', payjp_amount = 0");
    }

    /* ---------- 運営用：プランの保存とPAY.JPへの反映 ---------- */

    /**
     * 金額が、PAY.JP側のプランと違う（または、まだ作っていない）ときは、新しいPAY.JPプランを作る。
     * （PAY.JPのプランの金額は、あとから変えられない前提で、常に新しく作る。既存の契約者は、切り替えるまで旧金額のまま。）
     * @return true|WP_Error|null  null=変更なし
     */
    public static function sync_plan($plan_id) {
        global $wpdb;
        $plan = self::plan($plan_id);
        if (!$plan) {
            return new WP_Error('not_found', 'プランが見つかりません。');
        }
        $amount = SS_Tax::incl($plan['price']); // PAY.JPには、税込の金額で請求する
        if ($plan['payjp_plan_id'] !== '' && (int) $plan['payjp_amount'] === $amount) {
            return null;
        }
        $r = SS_Payjp::create_plan($amount, $plan['name']);
        if (is_wp_error($r)) {
            return $r;
        }
        $wpdb->update(SS_System::table('plans'), array('payjp_plan_id' => (string) $r['id'], 'payjp_amount' => $amount), array('id' => (int) $plan['id']));
        return true;
    }

    /**
     * プランの一覧をまとめて保存する。rows：[{id, name, max_staff, price, active, sort_order}]（id=0 は新規）
     * @return array ['saved' => 件数, 'synced' => PAY.JPに作った件数, 'errors' => [文面...]]
     */
    public static function save_plans(array $rows) {
        global $wpdb;
        $t = SS_System::table('plans');
        $out = array('saved' => 0, 'synced' => 0, 'errors' => array());
        foreach ($rows as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            $name = trim(sanitize_text_field(isset($row['name']) ? (string) $row['name'] : ''));
            $max = isset($row['max_staff']) ? (int) $row['max_staff'] : 0;
            $price = isset($row['price']) ? (int) $row['price'] : -1;
            if ($id === 0 && $name === '') {
                continue; // 空の新規行
            }
            if ($name === '' || mb_strlen($name) > 60 || $max < 1 || $max > 1000 || $price < 50 || $price > 1000000) {
                $out['errors'][] = '「' . ($name !== '' ? $name : '（名前なし）') . '」：名前（60文字以内）、スタッフ数の上限（1〜1000）、月額（50〜1,000,000円）を確認してください。';
                continue;
            }
            $data = array('name' => $name, 'max_staff' => $max, 'price' => $price, // price = 税抜の月額
                           'active' => empty($row['active']) ? 0 : 1, 'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : 0);
            if ($id > 0) {
                if (!self::plan($id)) {
                    continue;
                }
                $wpdb->update($t, $data, array('id' => $id));
            } else {
                $wpdb->insert($t, $data);
                $id = (int) $wpdb->insert_id;
            }
            $out['saved']++;
            if (SS_Payjp::configured()) {
                $r = self::sync_plan($id);
                if (is_wp_error($r)) {
                    $out['errors'][] = '「' . $name . '」をPAY.JPに反映できませんでした：' . $r->get_error_message();
                } elseif ($r === true) {
                    $out['synced']++;
                }
            }
        }
        return $out;
    }
}
