<?php
/**
 * 運営者向けの利用状況の集計（何社が使っているか など）。
 * WordPressの管理者だけが見る画面から使う。お客様のシフトの中身（勤務・希望）は集計に含めない。
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Stats {
    /** 全体の集計。$now は日時の文字列（テスト用）。 */
    public static function summary($now = null) {
        global $wpdb;
        $now = $now ? $now : SS_System::now();
        $ts = strtotime($now . ' UTC');
        $t = SS_System::table('tenants');
        $u = SS_System::table('users');

        // 状態別（無料期間が過ぎたものは「閲覧のみ」に数える）
        $by = array('unverified' => 0, 'trial' => 0, 'active' => 0, 'grace' => 0, 'readonly' => 0, 'suspended' => 0);
        foreach ($wpdb->get_results("SELECT status, COUNT(*) AS n FROM {$t} WHERE deleted_at IS NULL GROUP BY status", ARRAY_A) as $r) {
            if (isset($by[$r['status']])) {
                $by[$r['status']] = (int) $r['n'];
            }
        }
        $expired = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE deleted_at IS NULL AND status = 'trial' AND trial_end < %s", $now));
        $by['trial'] -= $expired;
        $by['readonly'] += $expired;
        $verified = $by['trial'] + $by['active'] + $by['grace'] + $by['readonly'] + $by['suspended'];

        $since = function ($days) use ($ts) { return gmdate('Y-m-d H:i:s', $ts - $days * 86400); };
        $signups = function ($from) use ($wpdb, $t) {
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE deleted_at IS NULL AND status <> 'unverified' AND created_at >= %s", $from));
        };
        $active_since = function ($days) use ($wpdb, $u, $t, $since) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT x.tenant_id) FROM {$u} x JOIN {$t} t ON t.id = x.tenant_id
                 WHERE t.deleted_at IS NULL AND t.status <> 'unverified' AND x.last_login_at >= %s", $since($days)));
        };

        // 月別の新規登録（直近12か月）。日付のまとめはPHP側で行う。
        $months = array();
        for ($i = 11; $i >= 0; $i--) {
            $months[gmdate('Y-m', strtotime(gmdate('Y-m-01', $ts) . ' UTC -' . $i . ' months'))] = 0;
        }
        $first = array_keys($months)[0] . '-01 00:00:00';
        foreach ($wpdb->get_col($wpdb->prepare("SELECT created_at FROM {$t} WHERE deleted_at IS NULL AND status <> 'unverified' AND created_at >= %s", $first)) as $created) {
            $k = substr($created, 0, 7);
            if (isset($months[$k])) {
                $months[$k]++;
            }
        }

        $industry = array('restaurant' => 0, 'care' => 0, 'other' => 0);
        foreach ($wpdb->get_results("SELECT industry, COUNT(*) AS n FROM {$t} WHERE deleted_at IS NULL AND status <> 'unverified' GROUP BY industry", ARRAY_A) as $r) {
            $industry[isset($industry[$r['industry']]) ? $r['industry'] : 'other'] += (int) $r['n'];
        }

        $st = SS_System::table('staff');
        $sc = SS_System::table('schedules');
        return array(
            'total'          => $verified,
            'by_status'      => $by,
            'signups'        => array('today' => $signups(gmdate('Y-m-d 00:00:00', $ts)), 'd7' => $signups($since(7)), 'd30' => $signups($since(30))),
            'active_30d'     => $active_since(30),
            'active_7d'      => $active_since(7),
            'trial_ending'   => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE deleted_at IS NULL AND status = 'trial' AND trial_end >= %s AND trial_end <= %s", $now, gmdate('Y-m-d H:i:s', $ts + 14 * 86400))),
            'months'         => $months,
            'industry'       => $industry,
            'staff_total'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$st} s JOIN {$t} t ON t.id = s.tenant_id WHERE s.active = 1 AND t.deleted_at IS NULL AND t.status <> 'unverified'"),
            'schedules'      => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sc} c JOIN {$t} t ON t.id = c.tenant_id WHERE t.deleted_at IS NULL AND t.status <> 'unverified'"),
            'schedules_pub'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sc} c JOIN {$t} t ON t.id = c.tenant_id WHERE c.status = 'published' AND t.deleted_at IS NULL AND t.status <> 'unverified'"),
        );
    }

    /**
     * お客様の一覧。$args：search（事業者名）、status（unverified/trial/active/grace/readonly/suspended）、per_page、page
     * 戻り値：['rows' => [...], 'total' => 件数]
     */
    public static function tenants(array $args = array(), $now = null) {
        global $wpdb;
        $now = $now ? $now : SS_System::now();
        $t = SS_System::table('tenants');
        $u = SS_System::table('users');
        $st = SS_System::table('staff');
        $sc = SS_System::table('schedules');

        $where = 'WHERE t.deleted_at IS NULL';
        $params = array();
        if (!empty($args['search'])) {
            $where .= ' AND t.name LIKE %s';
            $params[] = '%' . $wpdb->esc_like((string) $args['search']) . '%';
        }
        $status = isset($args['status']) ? (string) $args['status'] : '';
        if ($status === 'trial') {
            $where .= " AND t.status = 'trial' AND t.trial_end >= %s";
            $params[] = $now;
        } elseif ($status === 'readonly') {
            $where .= " AND (t.status = 'readonly' OR (t.status = 'trial' AND t.trial_end < %s))";
            $params[] = $now;
        } elseif (in_array($status, array('unverified', 'active', 'grace', 'suspended'), true)) {
            $where .= ' AND t.status = %s';
            $params[] = $status;
        }

        $count_sql = "SELECT COUNT(*) FROM {$t} t {$where}";
        $total = (int) $wpdb->get_var($params ? $wpdb->prepare($count_sql, $params) : $count_sql);

        $per = isset($args['per_page']) ? max(1, min(500, (int) $args['per_page'])) : 50;
        $page = isset($args['page']) ? max(1, (int) $args['page']) : 1;
        $sql = "SELECT t.id, t.name, t.industry, t.status, t.created_at, t.trial_end,
            (SELECT COUNT(*) FROM {$st} s WHERE s.tenant_id = t.id AND s.active = 1) AS staff_count,
            (SELECT COUNT(*) FROM {$sc} c WHERE c.tenant_id = t.id) AS schedule_count,
            (SELECT MAX(x.last_login_at) FROM {$u} x WHERE x.tenant_id = t.id) AS last_login,
            (SELECT y.email FROM {$u} y WHERE y.tenant_id = t.id AND y.role = 'owner' ORDER BY y.id ASC LIMIT 1) AS owner_email
            FROM {$t} t {$where} ORDER BY t.created_at DESC, t.id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, array($per, ($page - 1) * $per))), ARRAY_A);
        foreach ($rows as &$r) {
            $r['effective_status'] = SS_Tenants::effective_status($r);
        }
        unset($r);
        return array('rows' => $rows, 'total' => $total);
    }

    public static function industry_label($industry) {
        $map = array('restaurant' => '飲食', 'care' => '介護', 'other' => 'その他');
        return isset($map[$industry]) ? $map[$industry] : $industry;
    }

    /** CSV・表計算ソフトで式として実行されないよう、先頭の記号を無効にする。 */
    public static function csv_cell($v) {
        $v = (string) $v;
        if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) {
            $v = "'" . $v;
        }
        return '"' . str_replace('"', '""', $v) . '"';
    }

    public static function csv(array $rows) {
        $out = "\xEF\xBB\xBF" . implode(',', array_map(array(__CLASS__, 'csv_cell'), array('登録日', '事業者名', '業種', '状態', '管理者のメールアドレス', '無料期間の終了日', 'スタッフ数', 'シフト表の数', '最終利用日'))) . "\r\n";
        foreach ($rows as $r) {
            $out .= implode(',', array_map(array(__CLASS__, 'csv_cell'), array(
                substr((string) $r['created_at'], 0, 10), $r['name'], self::industry_label($r['industry']),
                SS_Tenants::status_label($r['effective_status']), isset($r['owner_email']) ? $r['owner_email'] : '',
                $r['trial_end'] ? substr($r['trial_end'], 0, 10) : '', $r['staff_count'], $r['schedule_count'],
                $r['last_login'] ? substr($r['last_login'], 0, 10) : '',
            ))) . "\r\n";
        }
        return $out;
    }
}
