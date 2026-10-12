<?php
/**
 * マッシュオフィスシステムの画面（日報・予定進捗・朝礼・管理者集計）用のAPI（/wp-json/mo/v1/…）。
 *
 * 画面（HTML・JavaScript）は、マッシュオフィスシステムのものをそのまま使うため、
 * 返す形は、マッシュオフィスシステムの mush-office/v1 と同じにしてある。
 * 違いは次のとおり：
 *  - ログインは、WordPressのログイン（このサービスのログイン）で確かめる。画面が送るトークンは使わない。
 *  - すべてのデータは、ログイン中のユーザーのお客様の分だけを扱う（SS_Repo を通す）。
 *  - スタッフは、このサービスのスタッフ名簿。日報の名前・部署は、名簿の値で保存する（なりすまし防止）。
 *  - 日報の写真は、お客様ごとの非公開フォルダに保存し、署名つきURLで渡す。
 *  - 閲覧のみの状態では、書き込みできない。
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Rest_Office {
    const NS = 'mo/v1';
    const IMAGE_KEEP_DAYS = 30;   // 完了した案件の写真を残す日数
    const IMAGES_PER_TASK = 10;
    const MAX_ROWS = 10000;       // 一覧で返す最大の件数

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'routes'));
    }

    public static function routes() {
        $c = __CLASS__;
        $r = function ($path, $method, $cb, $perm) use ($c) {
            register_rest_route(self::NS, $path, array(array('methods' => $method, 'callback' => array($c, $cb), 'permission_callback' => array($c, $perm))));
        };
        $r('/auth/login', 'POST', 'auth_login', 'can_use');
        $r('/admin/login', 'POST', 'admin_login', 'can_use');
        $r('/employees', 'GET', 'employees', 'can_use');
        $r('/departments', 'GET', 'departments', 'can_use');
        $r('/company-info', 'GET', 'company_info', 'can_use');
        $r('/company-name', 'GET', 'company_name', 'can_use');
        $r('/task-records', 'GET', 'get_task_records', 'can_use');
        $r('/task-records/upsert', 'POST', 'upsert_task_record', 'can_write');
        $r('/task-records/(?P<id>\d+)', 'DELETE', 'delete_task_record', 'can_admin_write');
        $r('/task-records/(?P<id>\d+)/duplicate', 'POST', 'duplicate_task_record', 'can_admin_write');
        $r('/task-records/(?P<id>\d+)/admin-update', 'POST', 'admin_update_task_record', 'can_write');
        $r('/task-records/(?P<id>\d+)/images', 'POST', 'upload_task_image', 'can_write');
        $r('/task-images/(?P<id>\d+)', 'DELETE', 'delete_task_image', 'can_write');
        $r('/schedule-tasks', 'GET', 'get_schedule_tasks', 'can_use');
        $r('/schedule-tasks/upsert', 'POST', 'upsert_schedule_task', 'can_write');
        $r('/schedule-tasks/(?P<id>\d+)', 'DELETE', 'delete_schedule_task', 'can_write');
        $r('/schedule-tasks/(?P<id>\d+)/duplicate', 'POST', 'duplicate_schedule_task', 'can_admin_write');
        $r('/activity-log', 'GET', 'get_activity_log', 'can_admin');
        $r('/activity-log', 'POST', 'post_activity_log', 'can_use');
        // まだ移していない機能（連絡・タイムカード・バックアップ）：画面が止まらないよう、空の結果を返す
        $r('/dm/threads', 'GET', 'empty_list', 'can_use');
        $r('/dm/messages', 'GET', 'empty_list', 'can_use');
        $r('/dm/messages', 'POST', 'not_yet', 'can_use');
        $r('/chat-messages/unread-staff-ids', 'GET', 'empty_list', 'can_use');
        $r('/time-cards', 'GET', 'empty_list', 'can_use');
        $r('/time-cards/upsert', 'POST', 'not_yet', 'can_use');
        $r('/backup/status', 'GET', 'backup_status', 'can_admin');
        foreach (array('/backup/export', '/backup/restore', '/backup/delete-all', '/backup/mark-downloaded') as $p) {
            register_rest_route(self::NS, $p, array(array('methods' => 'GET,POST', 'callback' => array($c, 'not_yet'), 'permission_callback' => array($c, 'can_admin'))));
        }
    }

    /* ---------- 権限 ---------- */

    public static function can_use() {
        return is_user_logged_in() && SS_Context::tenant_id() > 0 && current_user_can('shift_view');
    }

    public static function can_write() {
        if (!self::can_use()) {
            return false;
        }
        $t = SS_Context::tenant();
        return $t && SS_Tenants::is_writable($t);
    }

    public static function can_admin() {
        return self::can_use() && current_user_can('shift_manage_staff');
    }

    public static function can_admin_write() {
        return self::can_write() && current_user_can('shift_manage_staff');
    }

    private static function is_admin() {
        return current_user_can('shift_manage_staff');
    }

    /* ---------- 共通 ---------- */

    /** マッシュオフィスシステムの画面は、エラーを { error: "…" } で受け取る */
    private static function err($message, $status) {
        return new WP_REST_Response(array('error' => $message, 'message' => $message), $status);
    }

    private static function ok($data, $status = 200) {
        return new WP_REST_Response($data, $status);
    }

    private static function params(WP_REST_Request $req) {
        $p = $req->get_json_params();
        if (!is_array($p) || !$p) {
            $p = $req->get_params();
        }
        return is_array($p) ? $p : array();
    }

    private static function now() {
        return current_time('mysql');
    }

    private static function text($v, $max) {
        return mb_substr(sanitize_text_field((string) $v), 0, $max);
    }

    private static function date_or_empty($v) {
        $v = sanitize_text_field((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }

    private static function time_or_empty($v) {
        $v = sanitize_text_field((string) $v);
        return preg_match('/^\d{1,2}:\d{2}$/', $v) ? $v : '';
    }

    /** ログイン中の人に紐づくスタッフ（いなければ null） */
    private static function me_staff() {
        $me = SS_Context::user();
        if (!$me || empty($me['staff_id'])) {
            return null;
        }
        $s = SS_Repo::current()->find('staff', (int) $me['staff_id']);
        return $s && (int) $s['active'] === 1 ? $s : null;
    }

    private static function actor_name() {
        $s = self::me_staff();
        if ($s) {
            return $s['name'];
        }
        $u = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        return $u && !empty($u->display_name) ? $u->display_name : '管理者';
    }

    /** 名簿の、有効なスタッフ（お客様の中だけ）。いなければ null */
    private static function staff_in_tenant($id) {
        $s = SS_Repo::current()->find('staff', (int) $id);
        return $s && (int) $s['active'] === 1 ? $s : null;
    }

    public static function log($action, $target = '', $detail = '') {
        SS_Repo::current()->insert('activity_log', array(
            'occurred_at'  => self::now(),
            'actor_name'   => mb_substr(self::actor_name(), 0, 191),
            'action'       => mb_substr((string) $action, 0, 50),
            'target_label' => mb_substr((string) $target, 0, 191),
            'detail'       => mb_substr((string) $detail, 0, 250),
        ));
    }

    /* ---------- ログイン（WordPressのログインで確認済み。画面の形に合わせて返すだけ） ---------- */

    public static function auth_login(WP_REST_Request $req) {
        $s = self::me_staff();
        if (!$s) {
            return self::err('このアカウントは、スタッフ名簿に紐づいていません。スタッフ管理で、ご自身を名簿に登録して紐づけてください。', 403);
        }
        return self::ok(array(
            'token' => 'wp-session', 'staffId' => (string) $s['id'], 'staffName' => $s['name'], 'dept' => (string) $s['department'],
            'isAdmin' => self::is_admin(), 'expiresIn' => 43200,
        ));
    }

    public static function admin_login(WP_REST_Request $req) {
        if (!self::is_admin()) {
            return self::err('このアカウントには管理者権限がありません。', 403);
        }
        return self::ok(array('token' => 'wp-session', 'expiresIn' => 43200));
    }

    /* ---------- 名簿・部署・会社情報 ---------- */

    public static function employees(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $staff = $repo->all('staff', array('active' => 1), 'sort_order', 'ASC', 1000);
        usort($staff, function ($a, $b) {
            $na = (string) $a['employee_number'];
            $nb = (string) $b['employee_number'];
            if ($na === '' || $nb === '') {
                return $na === $nb ? ((int) $a['id'] - (int) $b['id']) : ($na === '' ? 1 : -1);
            }
            return strnatcmp($na, $nb);
        });
        $admin = self::is_admin();
        $accounts = array();
        if ($admin) {
            foreach ($repo->all('users', array(), 'id', 'ASC', 2000) as $u) {
                if (!empty($u['staff_id'])) {
                    $accounts[(int) $u['staff_id']] = $u;
                }
            }
        }
        $out = array();
        foreach ($staff as $s) {
            $no = (string) $s['employee_number'];
            $row = array(
                'id'              => (string) $s['id'],
                'employee_number' => ctype_digit($no) ? (int) $no : $no,
                'name'            => $s['name'],
                'department'      => (string) $s['department'],
                'position'        => '',
            );
            if ($admin) {
                $u = isset($accounts[(int) $s['id']]) ? $accounts[(int) $s['id']] : null;
                $row['kana'] = $s['kana'];
                $row['employment'] = $s['employment'];
                $row['is_admin'] = $u && in_array($u['role'], array('owner', 'manager'), true) ? '1' : '0';
                $row['has_login_password'] = $u && $u['status'] === 'active';
            }
            $out[] = $row;
        }
        return self::ok($out);
    }

    /** 部署：名簿に登録された部署から作る（色は、順番に割り当てる） */
    public static function departments(WP_REST_Request $req) {
        $palette = array(
            array('#0369a1', '#e0f2fe'), array('#6d28d9', '#ede9fe'), array('#047857', '#d1fae5'), array('#b45309', '#fef3c7'),
            array('#be185d', '#fce7f3'), array('#0e7490', '#cffafe'), array('#4d7c0f', '#ecfccb'), array('#4338ca', '#e0e7ff'),
            array('#a16207', '#fef9c3'), array('#475569', '#e2e8f0'), array('#b91c1c', '#fee2e2'),
        );
        $counts = array();
        $order = array();
        foreach (SS_Repo::current()->all('staff', array('active' => 1), 'sort_order', 'ASC', 1000) as $s) {
            $d = (string) $s['department'];
            if ($d === '') {
                continue;
            }
            if (!isset($counts[$d])) {
                $counts[$d] = 0;
                $order[] = $d;
            }
            $counts[$d]++;
        }
        $out = array();
        foreach ($order as $i => $name) {
            $c = $palette[$i % count($palette)];
            $out[] = array('name' => $name, 'band' => ($i + 1) * 100, 'fg' => $c[0], 'bg' => $c[1], 'builtin' => false, 'employeeCount' => $counts[$name]);
        }
        return self::ok($out);
    }

    public static function company_info(WP_REST_Request $req) {
        $tenant = SS_Context::tenant();
        $defaults = array(
            'name' => $tenant ? $tenant['name'] : '', 'repName' => '', 'postalCode' => '', 'address' => '', 'phone' => '', 'fax' => '', 'website' => '',
            'invoiceRegNumber' => '', 'bankName' => '', 'bankBranch' => '', 'bankAccountType' => '', 'bankAccountNumber' => '', 'bankAccountHolder' => '',
            'bankName2' => '', 'bankBranch2' => '', 'bankAccountType2' => '', 'bankAccountNumber2' => '', 'bankAccountHolder2' => '',
            'sealImageUrl' => '', 'sealSize' => 44, 'sealWhitened' => false,
            'workStartTime' => '09:00', 'workEndTime' => '18:00', 'breakMinutes' => 60, 'timeRoundingMinutes' => 1,
            'overtimeRate' => 25, 'nightRate' => 25, 'holidayRate' => 35, 'employmentInsuranceIndustry' => 'general',
            'weeklyHolidays' => array(0), 'holidayDates' => array(),
            'payrollCutoffDay' => '末日', 'payrollPaymentOffsetMonths' => 0, 'payrollPaymentDay' => '末日',
        );
        $settings = $tenant ? SS_Tenants::settings($tenant) : array();
        $saved = isset($settings['company_info']) && is_array($settings['company_info']) ? $settings['company_info'] : array();
        unset($saved['sealImageUrl']); // 印影は、経理の移植（P5）で、非公開ファイルとして扱う
        return self::ok(array_merge($defaults, $saved));
    }

    public static function company_name(WP_REST_Request $req) {
        $tenant = SS_Context::tenant();
        return self::ok(array('name' => $tenant ? $tenant['name'] : ''));
    }

    /* ---------- 日報（予定・実績） ---------- */

    private static function format_task(array $row, array $images = array()) {
        return array(
            'id' => (int) $row['id'], 'date' => $row['record_date'], 'staffId' => (string) $row['staff_id'], 'staffName' => $row['staff_name'],
            'dept' => $row['department'], 'category' => $row['category'], 'client' => $row['client'], 'project' => $row['project'],
            'start' => $row['start_time'], 'end' => $row['end_time'], 'status' => $row['status'], 'isOutside' => (bool) (int) $row['is_outside'],
            'area' => $row['area'], 'prefecture' => $row['prefecture'], 'travelType' => $row['travel_type'], 'note' => $row['note'],
            'bulkMode' => (bool) (int) $row['bulk_mode'], 'bulkCount' => $row['bulk_count'], 'progress' => (int) $row['progress'],
            'currentDay' => (int) $row['current_day'], 'totalDays' => (int) $row['total_days'], 'dueDate' => $row['due_date'],
            'holidayWork' => !empty($row['holiday_work']), 'earlyWork' => !empty($row['early_work']), 'nightWork' => !empty($row['night_work']),
            'images' => $images, 'imagesCleared' => !empty($row['images_cleared']), 'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at'],
        );
    }

    private static function images_map(array $task_ids) {
        $task_ids = array_values(array_filter(array_map('intval', $task_ids)));
        if (!$task_ids) {
            return array();
        }
        $repo = SS_Repo::current();
        $map = array();
        foreach (array_chunk($task_ids, 500) as $chunk) {
            foreach ($repo->all('task_images', array('task_record_id IN' => $chunk), 'id', 'ASC', self::MAX_ROWS) as $r) {
                $map[(int) $r['task_record_id']][] = array(
                    'id' => (int) $r['id'], 'url' => SS_Files::url($repo->tenant_id(), 'task_image', $r['id']),
                    'expiresAt' => !empty($r['expires_at']) ? substr($r['expires_at'], 0, 10) : '',
                );
            }
        }
        return $map;
    }

    private static function task_with_images($id) {
        $repo = SS_Repo::current();
        $row = $repo->find('task_records', $id);
        if (!$row) {
            return null;
        }
        $m = self::images_map(array($id));
        return self::format_task($row, isset($m[(int) $id]) ? $m[(int) $id] : array());
    }

    /** 範囲の条件（日付・スタッフ） */
    private static function range_where(WP_REST_Request $req) {
        $w = array();
        $d = self::date_or_empty($req->get_param('date'));
        if ($d !== '') {
            $w['record_date'] = $d;
        }
        $from = self::date_or_empty($req->get_param('dateFrom'));
        if ($from !== '') {
            $w['record_date >='] = $from;
        }
        $to = self::date_or_empty($req->get_param('dateTo'));
        if ($to !== '') {
            $w['record_date <='] = $to;
        }
        $sid = (int) $req->get_param('staffId');
        if ($sid > 0) {
            $w['staff_id'] = $sid;
        }
        return $w;
    }

    private static function sort_rows(array $rows) {
        usort($rows, function ($a, $b) {
            $c = strcmp($b['record_date'], $a['record_date']);
            return $c !== 0 ? $c : strcmp($a['start_time'], $b['start_time']);
        });
        return $rows;
    }

    public static function get_task_records(WP_REST_Request $req) {
        self::purge_expired_images();
        $rows = self::sort_rows(SS_Repo::current()->all('task_records', self::range_where($req), 'id', 'DESC', self::MAX_ROWS));
        $images = self::images_map(array_map(function ($r) { return (int) $r['id']; }, $rows));
        $out = array();
        foreach ($rows as $r) {
            $out[] = self::format_task($r, isset($images[(int) $r['id']]) ? $images[(int) $r['id']] : array());
        }
        return self::ok($out);
    }

    /** 日報の保存。同じ日・同じ担当者・同じ顧客名・案件名があれば更新する（まとめ入力は常に追加） */
    public static function upsert_task_record(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $p = self::params($req);
        $date = self::date_or_empty(isset($p['date']) ? $p['date'] : '');
        $staff = isset($p['staffId']) ? self::staff_in_tenant((int) $p['staffId']) : null;
        if ($date === '' || !$staff) {
            return self::err('日付とスタッフを指定してください。', 400);
        }
        $bulk = !empty($p['bulkMode']) ? 1 : 0;
        $client = $bulk ? null : self::text(isset($p['client']) ? trim((string) $p['client']) : '', 191);
        $project = $bulk ? null : self::text(isset($p['project']) ? trim((string) $p['project']) : '', 191);
        $data = array(
            'record_date' => $date, 'staff_id' => (int) $staff['id'], 'staff_name' => $staff['name'], 'department' => (string) $staff['department'],
            'category' => self::text(isset($p['category']) ? $p['category'] : '', 100), 'client' => $client, 'project' => $project,
            'start_time' => self::time_or_empty(isset($p['start']) ? $p['start'] : ''), 'end_time' => self::time_or_empty(isset($p['end']) ? $p['end'] : ''),
            'status' => self::text(isset($p['status']) && $p['status'] !== '' ? $p['status'] : '予定', 20),
            'is_outside' => !empty($p['isOutside']) ? 1 : 0, 'area' => self::text(isset($p['area']) ? $p['area'] : '', 20),
            'prefecture' => self::text(isset($p['prefecture']) ? $p['prefecture'] : '', 50), 'travel_type' => self::text(isset($p['travelType']) ? $p['travelType'] : '', 20),
            'note' => isset($p['note']) ? mb_substr(sanitize_textarea_field((string) $p['note']), 0, 5000) : '',
            'bulk_mode' => $bulk, 'bulk_count' => self::text(isset($p['bulkCount']) ? $p['bulkCount'] : '', 10),
            'progress' => max(0, min(100, isset($p['progress']) ? (int) $p['progress'] : 0)),
            'current_day' => isset($p['currentDay']) ? (int) $p['currentDay'] : 0, 'total_days' => isset($p['totalDays']) ? (int) $p['totalDays'] : 0,
            'due_date' => self::date_or_empty(isset($p['dueDate']) ? $p['dueDate'] : ''),
            'holiday_work' => !empty($p['holidayWork']) ? 1 : 0, 'early_work' => !empty($p['earlyWork']) ? 1 : 0, 'night_work' => !empty($p['nightWork']) ? 1 : 0,
        );
        $now = self::now();
        $old = null;
        if (!$bulk) {
            $found = $repo->all('task_records', array('record_date' => $date, 'staff_id' => (int) $staff['id'], 'client' => $client, 'project' => $project), 'id', 'ASC', 1);
            $old = $found ? $found[0] : null;
        }
        if ($old) {
            $upd = $data;
            unset($upd['record_date'], $upd['staff_id'], $upd['client'], $upd['project']);
            $upd['updated_at'] = $now;
            $repo->update('task_records', (int) $old['id'], $upd);
            $id = (int) $old['id'];
        } else {
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            $id = $repo->insert('task_records', $data);
            if (!$id) {
                return self::err('保存に失敗しました。', 500);
            }
        }
        $row = $repo->find('task_records', $id);
        if ($old) {
            $changes = self::task_changes($old, $row);
            if ($changes !== '') {
                self::log_task('予定を更新', $row, $changes);
            }
        } else {
            self::log_task('予定を登録', $row, '状態: ' . $row['status']);
        }
        self::update_image_expiry($id, $row['status']);
        return self::ok(self::task_with_images($id));
    }

    public static function delete_task_record(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $id = (int) $req['id'];
        $row = $repo->find('task_records', $id);
        if (!$row) {
            return self::err('案件が見つかりません。', 404);
        }
        foreach ($repo->all('task_images', array('task_record_id' => $id), 'id', 'ASC', 100) as $img) {
            SS_Files::delete($repo->tenant_id(), $img['file_path']);
        }
        $repo->delete_where('task_images', array('task_record_id' => $id));
        $repo->delete('task_records', $id);
        self::log_task('予定を削除', $row, '状態: ' . $row['status']);
        return self::ok(array('deleted' => true));
    }

    public static function duplicate_task_record(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $orig = $repo->find('task_records', (int) $req['id']);
        if (!$orig) {
            return self::err('複製元が見つかりません。', 404);
        }
        $data = $orig;
        unset($data['id'], $data['tenant_id']);
        $data['created_at'] = self::now();
        $data['updated_at'] = $data['created_at'];
        $data['images_cleared'] = 0;
        if (empty($data['bulk_mode'])) {
            // 同じ日・担当者・顧客名・案件名があれば、名前に（複製）を付けて重ならないようにする
            for ($i = 0; $i < 5 && $repo->count('task_records', array('record_date' => $data['record_date'], 'staff_id' => (int) $data['staff_id'], 'client' => $data['client'], 'project' => $data['project'])) > 0; $i++) {
                if ((string) $data['project'] !== '') {
                    $data['project'] = mb_substr($data['project'] . '(複製)', 0, 191);
                } else {
                    $data['client'] = mb_substr((string) $data['client'] . '(複製)', 0, 191);
                }
            }
        }
        $id = $repo->insert('task_records', $data);
        if (!$id) {
            return self::err('複製に失敗しました。', 500);
        }
        $row = $repo->find('task_records', $id);
        self::log_task('予定を複製', $row, '複製元: ' . $orig['record_date'] . ' ' . $orig['category']);
        return self::ok(self::format_task($row), 201);
    }

    /** 状態・進捗などの更新。管理者は、日付・顧客名・案件名なども変えられる */
    public static function admin_update_task_record(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $id = (int) $req['id'];
        $old = $repo->find('task_records', $id);
        if (!$old) {
            return self::err('案件が見つかりません。', 404);
        }
        $p = self::params($req);
        $d = array();
        if (isset($p['progress'])) { $d['progress'] = max(0, min(100, (int) $p['progress'])); }
        if (isset($p['status'])) { $d['status'] = self::text($p['status'], 20); }
        if (isset($p['currentDay'])) { $d['current_day'] = (int) $p['currentDay']; }
        if (isset($p['totalDays'])) { $d['total_days'] = (int) $p['totalDays']; }
        if (isset($p['note'])) { $d['note'] = mb_substr(sanitize_textarea_field((string) $p['note']), 0, 5000); }
        if (isset($p['isOutside'])) {
            $d['is_outside'] = !empty($p['isOutside']) ? 1 : 0;
            if (isset($p['area'])) { $d['area'] = self::text($p['area'], 20); }
            if (isset($p['travelType'])) { $d['travel_type'] = self::text($p['travelType'], 20); }
        }
        foreach (array('holidayWork' => 'holiday_work', 'earlyWork' => 'early_work', 'nightWork' => 'night_work') as $k => $col) {
            if (isset($p[$k])) { $d[$col] = !empty($p[$k]) ? 1 : 0; }
        }
        if (self::is_admin()) {
            $text = array('category' => array('category', 100), 'start' => array('start_time', 5), 'end' => array('end_time', 5), 'prefecture' => array('prefecture', 50));
            if (!isset($p['isOutside'])) {
                $text['area'] = array('area', 20);
                $text['travelType'] = array('travel_type', 20);
            }
            if (empty($old['bulk_mode'])) {
                $text['client'] = array('client', 191);
                $text['project'] = array('project', 191);
            }
            foreach ($text as $k => $def) {
                if (isset($p[$k])) { $d[$def[0]] = self::text(trim((string) $p[$k]), $def[1]); }
            }
            if (isset($p['date'])) {
                $d['record_date'] = self::date_or_empty($p['date']);
                if ($d['record_date'] === '') {
                    return self::err('日付は 2026-10-05 の形式で入力してください。', 400);
                }
            }
            if (isset($p['dueDate'])) { $d['due_date'] = self::date_or_empty($p['dueDate']); }
            if (isset($d['category']) && $d['category'] === '') {
                return self::err('業務区分を入力してください。', 400);
            }
        }
        if (!$d) {
            return self::err('更新する項目がありません。', 400);
        }
        if (empty($old['bulk_mode']) && (isset($d['record_date']) || isset($d['client']) || isset($d['project']))) {
            $key = array(
                'record_date' => isset($d['record_date']) ? $d['record_date'] : $old['record_date'], 'staff_id' => (int) $old['staff_id'],
                'client' => isset($d['client']) ? $d['client'] : $old['client'], 'project' => isset($d['project']) ? $d['project'] : $old['project'],
            );
            foreach ($repo->all('task_records', $key, 'id', 'ASC', 2) as $other) {
                if ((int) $other['id'] !== $id) {
                    return self::err('同じ日・同じ担当者・同じ顧客名・案件名の案件がすでにあります。日付や顧客名・案件名を変えてください。', 409);
                }
            }
        }
        $d['updated_at'] = self::now();
        $repo->update('task_records', $id, $d);
        if (isset($d['status'])) {
            self::update_image_expiry($id, $d['status']);
        }
        $row = $repo->find('task_records', $id);
        $changes = self::task_changes($old, $row);
        if ($changes !== '') {
            self::log_task('予定を更新', $row, $changes);
        }
        return self::ok(self::task_with_images($id));
    }

    public static function upload_task_image(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $id = (int) $req['id'];
        $task = $repo->find('task_records', $id);
        if (!$task) {
            return self::err('案件が見つかりません。', 404);
        }
        if ($repo->count('task_images', array('task_record_id' => $id)) >= self::IMAGES_PER_TASK) {
            return self::err('画像は1案件につき' . self::IMAGES_PER_TASK . '枚までです。', 400);
        }
        $files = $req->get_file_params();
        $stored = SS_Files::store_image(isset($files['file']) ? $files['file'] : array(), $repo->tenant_id(), 'task_images');
        if (is_wp_error($stored)) {
            return self::err($stored->get_error_message(), 400);
        }
        $repo->insert('task_images', array(
            'task_record_id' => $id, 'file_path' => $stored['path'], 'file_name' => $stored['name'], 'mime' => $stored['mime'],
            'uploaded_by' => mb_substr(self::actor_name(), 0, 191), 'created_at' => self::now(),
        ));
        self::update_image_expiry($id, $task['status']);
        self::log_task('予定に画像を追加', $task, $stored['name']);
        $m = self::images_map(array($id));
        return self::ok(array('images' => isset($m[$id]) ? $m[$id] : array()), 201);
    }

    public static function delete_task_image(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $img = $repo->find('task_images', (int) $req['id']);
        if (!$img) {
            return self::err('画像が見つかりません。', 404);
        }
        $repo->delete('task_images', (int) $img['id']);
        SS_Files::delete($repo->tenant_id(), $img['file_path']);
        $task = $repo->find('task_records', (int) $img['task_record_id']);
        if ($task) {
            self::log_task('予定の画像を削除', $task, $img['file_name']);
        }
        return self::ok(array('deleted' => true));
    }

    /** 完了した案件の写真は、30日後に削除する。完了でなくなったら、削除の予定を取り消す */
    private static function update_image_expiry($task_id, $status) {
        $repo = SS_Repo::current();
        if ($status !== '完了') {
            $repo->update_where('task_images', array('task_record_id' => (int) $task_id, 'expires_at !=' => null), array('expires_at' => null));
            return;
        }
        $exp = gmdate('Y-m-d H:i:s', current_time('timestamp') + self::IMAGE_KEEP_DAYS * DAY_IN_SECONDS);
        $repo->update_where('task_images', array('task_record_id' => (int) $task_id, 'expires_at' => null), array('expires_at' => $exp));
    }

    /** 期限が過ぎた写真を削除する（このお客様の分だけ。1時間に1回まで） */
    private static function purge_expired_images() {
        $repo = SS_Repo::current();
        $lock = 'ss_img_purge_' . $repo->tenant_id();
        if (get_transient($lock)) {
            return;
        }
        set_transient($lock, 1, HOUR_IN_SECONDS);
        $rows = $repo->all('task_images', array('expires_at !=' => null, 'expires_at <=' => self::now()), 'id', 'ASC', 500);
        $tasks = array();
        foreach ($rows as $r) {
            SS_Files::delete($repo->tenant_id(), $r['file_path']);
            $repo->delete('task_images', (int) $r['id']);
            $tasks[(int) $r['task_record_id']] = true;
        }
        foreach (array_keys($tasks) as $tid) {
            if ($repo->count('task_images', array('task_record_id' => $tid)) === 0) {
                $repo->update('task_records', $tid, array('images_cleared' => 1));
            }
        }
    }

    private static function task_changes($old, $new) {
        $fields = array('record_date' => '日付', 'category' => '業務区分', 'client' => '顧客名', 'project' => '案件名', 'start_time' => '開始', 'end_time' => '終了',
            'status' => '状態', 'due_date' => '終了予定日', 'is_outside' => '外出', 'area' => '区域', 'prefecture' => '都道府県', 'travel_type' => '移動', 'bulk_count' => 'まとめ件数');
        $out = array();
        foreach ($fields as $k => $label) {
            $a = isset($old[$k]) ? (string) $old[$k] : '';
            $b = isset($new[$k]) ? (string) $new[$k] : '';
            if ($a !== $b) {
                $out[] = $k === 'is_outside' ? $label . ': ' . ($a === '1' ? 'あり' : 'なし') . '→' . ($b === '1' ? 'あり' : 'なし')
                    : $label . ': ' . ($a === '' ? '(空)' : $a) . '→' . ($b === '' ? '(空)' : $b);
            }
        }
        if ((string) (isset($old['note']) ? $old['note'] : '') !== (string) (isset($new['note']) ? $new['note'] : '')) {
            $out[] = '備考を変更';
        }
        return implode(' / ', $out);
    }

    private static function log_task($action, $row, $detail) {
        if (!$row) {
            return;
        }
        $me = self::me_staff();
        if (!$me || (int) $me['id'] !== (int) $row['staff_id']) {
            $detail = trim('代理操作 ' . $detail);
        }
        $label = $row['record_date'] . ' ' . $row['staff_name'] . ' ' . trim($row['category'] . ' ' . (string) $row['client'] . ' ' . (string) $row['project']);
        self::log($action, mb_substr($label, 0, 191), $detail);
    }

    /* ---------- 予定進捗 ---------- */

    private static function format_sched(array $row) {
        return array(
            'id' => (int) $row['id'], 'date' => $row['record_date'], 'staffId' => (string) $row['staff_id'], 'staffName' => $row['staff_name'],
            'dept' => $row['department'], 'name' => $row['name'], 'start' => $row['start_time'], 'end' => $row['end_time'],
            'dueDate' => $row['due_date'], 'memo' => $row['memo'], 'progress' => (int) $row['progress'],
            'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at'],
        );
    }

    public static function get_schedule_tasks(WP_REST_Request $req) {
        $rows = self::sort_rows(SS_Repo::current()->all('schedule_tasks', self::range_where($req), 'id', 'DESC', self::MAX_ROWS));
        return self::ok(array_map(array(__CLASS__, 'format_sched'), $rows));
    }

    public static function upsert_schedule_task(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $p = self::params($req);
        $fields = array(
            'name' => self::text(isset($p['name']) ? $p['name'] : '', 191),
            'start_time' => self::time_or_empty(isset($p['start']) ? $p['start'] : ''), 'end_time' => self::time_or_empty(isset($p['end']) ? $p['end'] : ''),
            'due_date' => self::date_or_empty(isset($p['dueDate']) ? $p['dueDate'] : ''),
            'memo' => isset($p['memo']) ? mb_substr(sanitize_textarea_field((string) $p['memo']), 0, 5000) : '',
            'progress' => max(0, min(100, isset($p['progress']) ? (int) $p['progress'] : 0)),
            'updated_at' => self::now(),
        );
        $id = isset($p['id']) ? (int) $p['id'] : 0;
        if ($id > 0) {
            if (!$repo->find('schedule_tasks', $id)) {
                return self::err('案件が見つかりません。', 404);
            }
            $repo->update('schedule_tasks', $id, $fields);
            return self::ok(self::format_sched($repo->find('schedule_tasks', $id)));
        }
        $date = self::date_or_empty(isset($p['date']) ? $p['date'] : '');
        $staff = isset($p['staffId']) ? self::staff_in_tenant((int) $p['staffId']) : null;
        if ($date === '' || !$staff) {
            return self::err('日付とスタッフを指定してください。', 400);
        }
        $fields += array('record_date' => $date, 'staff_id' => (int) $staff['id'], 'staff_name' => $staff['name'], 'department' => (string) $staff['department'], 'created_at' => self::now());
        $new = $repo->insert('schedule_tasks', $fields);
        if (!$new) {
            return self::err('登録に失敗しました。', 500);
        }
        return self::ok(self::format_sched($repo->find('schedule_tasks', $new)), 201);
    }

    public static function delete_schedule_task(WP_REST_Request $req) {
        if (!SS_Repo::current()->delete('schedule_tasks', (int) $req['id'])) {
            return self::err('案件が見つかりません。', 404);
        }
        return self::ok(array('deleted' => true));
    }

    public static function duplicate_schedule_task(WP_REST_Request $req) {
        $repo = SS_Repo::current();
        $orig = $repo->find('schedule_tasks', (int) $req['id']);
        if (!$orig) {
            return self::err('複製元が見つかりません。', 404);
        }
        $data = $orig;
        unset($data['id'], $data['tenant_id']);
        $data['created_at'] = self::now();
        $data['updated_at'] = $data['created_at'];
        $id = $repo->insert('schedule_tasks', $data);
        return $id ? self::ok(self::format_sched($repo->find('schedule_tasks', $id)), 201) : self::err('複製に失敗しました。', 500);
    }

    /* ---------- 操作ログ ---------- */

    public static function get_activity_log(WP_REST_Request $req) {
        $rows = SS_Repo::current()->all('activity_log', array(), 'id', 'DESC', 1000);
        return self::ok(array_map(function ($r) {
            return array('id' => (int) $r['id'], 'occurredAt' => $r['occurred_at'], 'actorName' => $r['actor_name'], 'action' => $r['action'], 'targetLabel' => $r['target_label'], 'detail' => $r['detail']);
        }, $rows));
    }

    /** 画面からの記録（印刷など）。記録する人の名前は、送られた値ではなく、ログイン中の人にする */
    public static function post_activity_log(WP_REST_Request $req) {
        $p = self::params($req);
        $action = self::text(isset($p['action']) ? $p['action'] : '', 50);
        if ($action === '') {
            return self::err('actionを指定してください。', 400);
        }
        self::log($action, self::text(isset($p['targetLabel']) ? $p['targetLabel'] : '', 191), self::text(isset($p['detail']) ? $p['detail'] : '', 250));
        return self::ok(array('logged' => true));
    }

    /* ---------- まだ移していない機能 ---------- */

    public static function empty_list(WP_REST_Request $req) {
        return self::ok(array());
    }

    public static function not_yet(WP_REST_Request $req) {
        return self::err('この機能は、準備中です。', 501);
    }

    public static function backup_status(WP_REST_Request $req) {
        // データは、運営者のサーバーで毎日バックアップしているため、お客様側の書き出しは不要（P7でお客様ごとの書き出しを用意）
        return self::ok(array('lastDownloadAt' => current_time('mysql'), 'managed' => true));
    }
}
