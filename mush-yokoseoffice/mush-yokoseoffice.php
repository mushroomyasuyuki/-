<?php
/**
 * Plugin Name: ヨコセオフィス業務システム(WordPress DB版)
 * Description: Firebase/Firestoreを使わず、WordPress自体のデータベースだけで従業員登録・日報などのデータを管理するプラグインです。固定ページの「カスタムHTML」ブロックから、このプラグインが提供するREST APIを呼び出して使います。
 * Version: 2.7.29
 * Author: 社内システム
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // 直接アクセス禁止
}

define( 'MUSH_OFFICE_DB_VERSION', '1.47.0' ); // 1.47.0: 給与明細に「公開日時」(published_at)を追加
define( 'MUSH_OFFICE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
// 固定ページ自動作成の版。作成するページの構成(ページ数・スラッグ)を変えたら上げる。
define( 'MUSH_OFFICE_PAGES_VERSION', '1.7.0' );
// このプラグインが自動作成した固定ページに付ける目印(postmetaキー)。
define( 'MUSH_OFFICE_PAGE_META', '_mush_office_page' );
// ログイントークンの有効期限(秒)。日報ページでの本人確認に使う。
define( 'MUSH_OFFICE_AUTH_TTL', 12 * HOUR_IN_SECONDS );

/**
 * ============================================================
 * 操作ログ
 * ============================================================
 * ログイン・見積もり/請求書の作成・変更・印刷・削除・重要な管理操作を記録する。
 * 印刷はブラウザのwindow.print()呼び出しのみでサーバーを経由しないため、
 * フロント側から明示的に記録用APIを呼んでもらう(mush_office_log_activity_endpoint)。
 */
function mush_office_log_activity( $actor_name, $action, $target_label = '', $detail = '' ) {
    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'mush_activity_log',
        array(
            'occurred_at'  => current_time( 'mysql' ),
            'actor_name'   => sanitize_text_field( $actor_name ),
            'action'       => sanitize_text_field( $action ),
            'target_label' => sanitize_text_field( $target_label ),
            'detail'       => sanitize_text_field( $detail ),
        )
    );
}

function mush_office_get_activity_log( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_activity_log';
    $rows = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY id DESC LIMIT 1000", ARRAY_A );
    if ( null === $rows ) {
        $rows = array();
    }
    return new WP_REST_Response( array_map( function ( $row ) {
        return array(
            'id'          => intval( $row['id'] ),
            'occurredAt'  => $row['occurred_at'],
            'actorName'   => $row['actor_name'],
            'action'      => $row['action'],
            'targetLabel' => $row['target_label'],
            'detail'      => $row['detail'],
        );
    }, $rows ), 200 );
}

// 印刷など、サーバーを経由しない操作をフロント側から明示的に記録するための汎用API。
function mush_office_log_activity_endpoint( WP_REST_Request $request ) {
    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }
    $action = isset( $p['action'] ) ? sanitize_text_field( $p['action'] ) : '';
    if ( '' === $action ) {
        return new WP_REST_Response( array( 'error' => 'actionを指定してください。' ), 400 );
    }
    mush_office_log_activity(
        isset( $p['actorName'] ) ? $p['actorName'] : '',
        $action,
        isset( $p['targetLabel'] ) ? $p['targetLabel'] : '',
        isset( $p['detail'] ) ? $p['detail'] : ''
    );
    return new WP_REST_Response( array( 'logged' => true ), 200 );
}

/**
 * ============================================================
 * テーブル作成(プラグイン有効化時)
 * ============================================================
 * 従業員マスタ用のテーブルを1つ作成します。
 * 他のページ(日報・進捗・朝礼・管理者集計・タイムカード)を
 * 移行する際は、同じ要領でテーブルを追加していきます。
 */
function mush_office_install() {
    global $wpdb;
    $mush_prev_db_version = strval( get_option( 'mush_office_db_version', '' ) ); // 更新前のDBバージョン(新規インストールなら空)
    $charset_collate = $wpdb->get_charset_collate();
    $emp_table = $wpdb->prefix . 'mush_employees';

    $sql1 = "CREATE TABLE {$emp_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        employee_number INT NOT NULL,
        name VARCHAR(191) NOT NULL,
        department VARCHAR(50) NOT NULL,
        birthdate VARCHAR(20) DEFAULT '',
        zip VARCHAR(20) DEFAULT '',
        address VARCHAR(255) DEFAULT '',
        phone VARCHAR(30) DEFAULT '',
        mobile VARCHAR(30) DEFAULT '',
        memo TEXT NULL,
        bank_name VARCHAR(100) DEFAULT '',
        bank_branch VARCHAR(100) DEFAULT '',
        bank_account_type VARCHAR(20) DEFAULT '',
        bank_account_number VARCHAR(50) DEFAULT '',
        bank_account_holder VARCHAR(191) DEFAULT '',
        password_hash VARCHAR(255) DEFAULT NULL,
        login_password VARCHAR(191) DEFAULT '',
        is_admin TINYINT(1) NOT NULL DEFAULT 0,
        wage_type VARCHAR(10) DEFAULT '',
        wage_rate DECIMAL(10,0) NOT NULL DEFAULT 0,
        housing_allowance DECIMAL(10,0) NOT NULL DEFAULT 0,
        position_allowance DECIMAL(10,0) NOT NULL DEFAULT 0,
        commute_allowance DECIMAL(10,0) NOT NULL DEFAULT 0,
        qualification_allowance DECIMAL(10,0) NOT NULL DEFAULT 0,
        family_allowance DECIMAL(10,0) NOT NULL DEFAULT 0,
        other1_label VARCHAR(50) DEFAULT '',
        other1_amount DECIMAL(10,0) NOT NULL DEFAULT 0,
        other2_label VARCHAR(50) DEFAULT '',
        other2_amount DECIMAL(10,0) NOT NULL DEFAULT 0,
        other3_label VARCHAR(50) DEFAULT '',
        other3_amount DECIMAL(10,0) NOT NULL DEFAULT 0,
        housing_taxable TINYINT(1) NOT NULL DEFAULT 1,
        position_taxable TINYINT(1) NOT NULL DEFAULT 1,
        commute_taxable TINYINT(1) NOT NULL DEFAULT 0,
        qualification_taxable TINYINT(1) NOT NULL DEFAULT 1,
        family_taxable TINYINT(1) NOT NULL DEFAULT 1,
        other1_taxable TINYINT(1) NOT NULL DEFAULT 1,
        other2_taxable TINYINT(1) NOT NULL DEFAULT 1,
        other3_taxable TINYINT(1) NOT NULL DEFAULT 1,
        employment_insurance_fixed DECIMAL(10,0) NOT NULL DEFAULT 0,
        resident_tax_fixed DECIMAL(10,0) NOT NULL DEFAULT 0,
        life_insurance_fixed DECIMAL(10,0) NOT NULL DEFAULT 0,
        wage_savings_fixed DECIMAL(10,0) NOT NULL DEFAULT 0,
        stock_ownership_fixed DECIMAL(10,0) NOT NULL DEFAULT 0,
        dependents_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
        hire_date VARCHAR(20) DEFAULT '',
        show_only_when_scheduled TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY employee_number (employee_number)
    ) {$charset_collate};";

    // 日報(予定・実績)用テーブル。
    // client/projectをNULL許容にしているのは、「まとめ入力(事務)」のように
    // 顧客名・案件名を使わない案件が複数あってもユニーク制約に引っかからないようにするため
    // (MySQLはUNIQUE KEYの列にNULLが含まれる行同士は重複とみなしません)。
    $task_table = $wpdb->prefix . 'mush_task_records';
    $sql2 = "CREATE TABLE {$task_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        record_date VARCHAR(10) NOT NULL,
        staff_id BIGINT UNSIGNED NOT NULL,
        staff_name VARCHAR(191) NOT NULL,
        department VARCHAR(50) NOT NULL,
        category VARCHAR(100) NOT NULL DEFAULT '',
        client VARCHAR(191) NULL,
        project VARCHAR(191) NULL,
        start_time VARCHAR(5) DEFAULT '',
        end_time VARCHAR(5) DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT '予定',
        is_outside TINYINT(1) NOT NULL DEFAULT 0,
        area VARCHAR(20) DEFAULT '',
        prefecture VARCHAR(50) DEFAULT '',
        travel_type VARCHAR(20) DEFAULT '',
        note TEXT NULL,
        bulk_mode TINYINT(1) NOT NULL DEFAULT 0,
        bulk_count VARCHAR(10) DEFAULT '',
        progress INT NOT NULL DEFAULT 0,
        current_day INT NOT NULL DEFAULT 0,
        total_days INT NOT NULL DEFAULT 0,
        due_date VARCHAR(10) DEFAULT '',
        holiday_work TINYINT(1) NOT NULL DEFAULT 0,
        early_work TINYINT(1) NOT NULL DEFAULT 0,
        night_work TINYINT(1) NOT NULL DEFAULT 0,
        images_cleared TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_task (record_date, staff_id, client, project),
        KEY idx_date (record_date),
        KEY idx_staff (staff_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql1 );
    dbDelta( $sql2 );

    // 進捗(予定・実績)用テーブル。
    // Firestore版は「1日1ドキュメントに複数案件を配列で格納」という構造でしたが、
    // WordPress DB版では「1案件=1行」というシンプルな構造にしています。
    // 繰越案件は record_date に元々の日付を保持したまま、進捗ページ上では
    // 本日分と一緒に表示・編集できるようにしています。
    $sched_table = $wpdb->prefix . 'mush_schedule_tasks';
    $sql3 = "CREATE TABLE {$sched_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        record_date VARCHAR(10) NOT NULL,
        staff_id BIGINT UNSIGNED NOT NULL,
        staff_name VARCHAR(191) NOT NULL,
        department VARCHAR(50) NOT NULL,
        name VARCHAR(191) NOT NULL DEFAULT '',
        start_time VARCHAR(5) DEFAULT '',
        end_time VARCHAR(5) DEFAULT '',
        due_date VARCHAR(10) DEFAULT '',
        memo TEXT NULL,
        progress INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY  (id),
        KEY idx_date (record_date),
        KEY idx_staff (staff_id)
    ) {$charset_collate};";
    dbDelta( $sql3 );

    // タイムカード(出退勤)用テーブル。タイムカード画面自体は今後移行予定だが、
    // 管理者集計画面から先に参照できるよう、テーブルだけ先に用意しておく。
    $tc_table = $wpdb->prefix . 'mush_time_cards';
    $sql4 = "CREATE TABLE {$tc_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        record_date VARCHAR(10) NOT NULL,
        staff_id BIGINT UNSIGNED NOT NULL,
        staff_name VARCHAR(191) NOT NULL,
        department VARCHAR(50) NOT NULL,
        clock_in VARCHAR(5) DEFAULT '',
        clock_out VARCHAR(5) DEFAULT '',
        clock_in_lat DECIMAL(10,7) NULL,
        clock_in_lng DECIMAL(10,7) NULL,
        clock_in_accuracy INT NULL,
        clock_out_lat DECIMAL(10,7) NULL,
        clock_out_lng DECIMAL(10,7) NULL,
        clock_out_accuracy INT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_timecard (record_date, staff_id),
        KEY idx_date (record_date),
        KEY idx_staff (staff_id)
    ) {$charset_collate};";
    dbDelta( $sql4 );

    // 管理者⇔従業員チャット用テーブル。1行=1メッセージ、staff_idでスレッドをまとめる。
    // is_readは「受け取った側が読んだか」を表す(admin送信分=従業員が読んだか、
    // staff送信分=管理者が読んだか)。
    $chat_table = $wpdb->prefix . 'mush_chat_messages';
    $sql5 = "CREATE TABLE {$chat_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        staff_id BIGINT UNSIGNED NOT NULL,
        sender VARCHAR(10) NOT NULL DEFAULT 'staff',
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_staff (staff_id)
    ) {$charset_collate};";
    dbDelta( $sql5 );

    // 従業員同士の個別チャット(DM)用テーブル。1行=1メッセージ。
    // is_readは「受信者(to_staff_id)が読んだか」。管理者は閲覧しない(当事者2人だけのやり取り)。
    $dm_table = $wpdb->prefix . 'mush_dm_messages';
    $sql_dm = "CREATE TABLE {$dm_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        from_staff_id BIGINT UNSIGNED NOT NULL,
        to_staff_id BIGINT UNSIGNED NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_from_to (from_staff_id, to_staff_id),
        KEY idx_to_read (to_staff_id, is_read)
    ) {$charset_collate};";
    dbDelta( $sql_dm );

    // 経理(見積もり・顧客一覧・商品一覧)用テーブル群。
    // 顧客マスタ: 見積もり・請求書で使う顧客情報。案件名は複数持てるよう改行区切りのテキストで保持する
    // (件数が多くなければこれで十分。専用テーブルにする必要が出てきたら分離する)。
    $client_table = $wpdb->prefix . 'mush_clients';
    $sql6 = "CREATE TABLE {$client_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(191) NOT NULL,
        rep1 VARCHAR(191) DEFAULT '',
        rep2 VARCHAR(191) DEFAULT '',
        postal_code VARCHAR(10) DEFAULT '',
        address VARCHAR(255) DEFAULT '',
        phone VARCHAR(50) DEFAULT '',
        projects TEXT NULL,
        memo TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY  (id)
    ) {$charset_collate};";
    dbDelta( $sql6 );

    // 商品マスタ: 見積もりの品目選択時に原価・売値を自動入力するために使う。
    $product_table = $wpdb->prefix . 'mush_products';
    $sql7 = "CREATE TABLE {$product_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(191) NOT NULL,
        cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        price DECIMAL(12,2) NOT NULL DEFAULT 0,
        maker VARCHAR(191) DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY  (id)
    ) {$charset_collate};";
    dbDelta( $sql7 );

    // 見積もり: 品目はitems_jsonにJSON配列でまとめて持たせるシンプルな構造にしている
    // (品目ごとに専用テーブルを分けるほどの複雑な集計は今のところ不要なため)。
    // quote_noが「見積もりNO」で、原価計算書・請求書と連携する際のキーになる想定。
    $quote_table = $wpdb->prefix . 'mush_quotes';
    $sql8 = "CREATE TABLE {$quote_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        quote_no VARCHAR(30) NOT NULL,
        client_id BIGINT UNSIGNED NULL,
        client_name VARCHAR(191) DEFAULT '',
        project_name VARCHAR(191) DEFAULT '',
        staff_id BIGINT UNSIGNED NULL,
        staff_name VARCHAR(191) DEFAULT '',
        worker1_id BIGINT UNSIGNED NULL,
        worker1_name VARCHAR(191) DEFAULT '',
        worker2_id BIGINT UNSIGNED NULL,
        worker2_name VARCHAR(191) DEFAULT '',
        issue_date VARCHAR(10) DEFAULT '',
        valid_months INT NOT NULL DEFAULT 1,
        cost_no VARCHAR(30) DEFAULT '',
        items_json LONGTEXT NULL,
        total_amount DECIMAL(12,0) NOT NULL DEFAULT 0,
        memo TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_quote_no (quote_no)
    ) {$charset_collate};";
    dbDelta( $sql8 );

    // 請求書: 見積もりと同じ構造(品目はJSON)。quote_noで元になった見積もりと紐づけるが、
    // 1見積もりに対して複数の請求書(分割請求)を作れるよう、ユニーク制約は付けない。
    $invoice_table = $wpdb->prefix . 'mush_invoices';
    $sql9 = "CREATE TABLE {$invoice_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        invoice_no VARCHAR(30) NOT NULL,
        quote_no VARCHAR(30) DEFAULT '',
        client_id BIGINT UNSIGNED NULL,
        client_name VARCHAR(191) DEFAULT '',
        project_name VARCHAR(191) DEFAULT '',
        staff_id BIGINT UNSIGNED NULL,
        staff_name VARCHAR(191) DEFAULT '',
        worker1_id BIGINT UNSIGNED NULL,
        worker1_name VARCHAR(191) DEFAULT '',
        worker2_id BIGINT UNSIGNED NULL,
        worker2_name VARCHAR(191) DEFAULT '',
        issue_date VARCHAR(10) DEFAULT '',
        items_json LONGTEXT NULL,
        total_amount DECIMAL(12,0) NOT NULL DEFAULT 0,
        memo TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_invoice_no (invoice_no),
        KEY idx_quote_no (quote_no)
    ) {$charset_collate};";
    dbDelta( $sql9 );

    // 原価計算書: 見積もり・請求書と同じ構造(原価品目はitems_jsonにJSON配列)。
    // revenue_amountは売上側の金額(見積もり・請求書から引き継ぐか手入力)で、
    // 粗利・粗利率はフロント側でrevenue_amountとcost_totalから都度計算する
    // (保存時点のスナップショットとして数値を持たせるほどの複雑さは今のところ不要)。
    $cost_table = $wpdb->prefix . 'mush_costs';
    $sql10 = "CREATE TABLE {$cost_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        report_no VARCHAR(30) NOT NULL,
        quote_no VARCHAR(30) DEFAULT '',
        invoice_no VARCHAR(30) DEFAULT '',
        client_id BIGINT UNSIGNED NULL,
        client_name VARCHAR(191) DEFAULT '',
        project_name VARCHAR(191) DEFAULT '',
        staff_id BIGINT UNSIGNED NULL,
        staff_name VARCHAR(191) DEFAULT '',
        worker1_id BIGINT UNSIGNED NULL,
        worker1_name VARCHAR(191) DEFAULT '',
        worker2_id BIGINT UNSIGNED NULL,
        worker2_name VARCHAR(191) DEFAULT '',
        issue_date VARCHAR(10) DEFAULT '',
        revenue_amount DECIMAL(12,0) NOT NULL DEFAULT 0,
        items_json LONGTEXT NULL,
        cost_total DECIMAL(12,0) NOT NULL DEFAULT 0,
        memo TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_report_no (report_no),
        KEY idx_quote_no (quote_no),
        KEY idx_invoice_no (invoice_no)
    ) {$charset_collate};";
    dbDelta( $sql10 );

    // 操作ログ: ログイン・見積もり/請求書の作成・変更・印刷・削除・パスワード変更などを記録する。
    // 全データ削除の対象には含めない(削除操作自体の記録を残すため)。
    $log_table = $wpdb->prefix . 'mush_activity_log';
    $sql11 = "CREATE TABLE {$log_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        occurred_at DATETIME NOT NULL,
        actor_name VARCHAR(191) DEFAULT '',
        action VARCHAR(50) NOT NULL,
        target_label VARCHAR(191) DEFAULT '',
        detail VARCHAR(255) DEFAULT '',
        PRIMARY KEY  (id)
    ) {$charset_collate};";
    dbDelta( $sql11 );

    // 全員へのお知らせ(管理者チャットから送信し、朝礼画面の上部に一定期間表示する)。
    $announcement_table = $wpdb->prefix . 'mush_announcements';
    $sql12 = "CREATE TABLE {$announcement_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        message TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id)
    ) {$charset_collate};";
    dbDelta( $sql12 );

    // 給与計算ページ用: 年度ごとの社会保険・厚生年金保険料額表(Excelからアップロード)。
    // 等級ごとの一覧(標準報酬月額・報酬月額の範囲・保険料)はtiers_jsonにまとめて保存し、
    // 元のExcelファイル自体もfile_urlに保存して一覧からダウンロード・再確認できるようにする。
    $insurance_table = $wpdb->prefix . 'mush_insurance_tables';
    $sql13 = "CREATE TABLE {$insurance_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        fiscal_year INT NOT NULL,
        region VARCHAR(20) DEFAULT '',
        title VARCHAR(191) DEFAULT '',
        file_name VARCHAR(255) DEFAULT '',
        file_url VARCHAR(500) DEFAULT '',
        tiers_json LONGTEXT NULL,
        uploaded_by VARCHAR(191) DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_fiscal_year (fiscal_year)
    ) {$charset_collate};";
    dbDelta( $sql13 );

    // 給与計算ページ用: 年度ごとの源泉所得税額表(国税庁の公式Excelからアップロード)。
    // 甲欄(扶養人数0〜7人)・乙欄・最低額未満の特例・表の上限額をdata_jsonにまとめて保存する。
    $tax_table = $wpdb->prefix . 'mush_tax_tables';
    $sql14 = "CREATE TABLE {$tax_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        fiscal_year INT NOT NULL,
        title VARCHAR(191) DEFAULT '',
        file_name VARCHAR(255) DEFAULT '',
        file_url VARCHAR(500) DEFAULT '',
        data_json LONGTEXT NULL,
        uploaded_by VARCHAR(191) DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_fiscal_year (fiscal_year)
    ) {$charset_collate};";
    dbDelta( $sql14 );

    // 給与計算ページ用: 年度ごとの賞与に対する源泉徴収税額の算出率の表(国税庁の公式Excel)。
    // 甲欄(扶養人数0〜7人以上)ごとの前月給与レンジ×算出率、乙欄のレンジ×算出率をdata_jsonに保存する。
    $bonus_table = $wpdb->prefix . 'mush_bonus_tax_tables';
    $sql15 = "CREATE TABLE {$bonus_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        fiscal_year INT NOT NULL,
        title VARCHAR(191) DEFAULT '',
        file_name VARCHAR(255) DEFAULT '',
        file_url VARCHAR(500) DEFAULT '',
        data_json LONGTEXT NULL,
        uploaded_by VARCHAR(191) DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_fiscal_year (fiscal_year)
    ) {$charset_collate};";
    dbDelta( $sql15 );

    // 給与計算ページ用: 年度ごとの源泉所得税額表(日額表・国税庁の公式Excel)。
    // 月額表と同じ構造に加え、日雇労働者向けの丙欄をdata_jsonに保存する。
    $daily_table = $wpdb->prefix . 'mush_daily_tax_tables';
    $sql16 = "CREATE TABLE {$daily_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        fiscal_year INT NOT NULL,
        title VARCHAR(191) DEFAULT '',
        file_name VARCHAR(255) DEFAULT '',
        file_url VARCHAR(500) DEFAULT '',
        data_json LONGTEXT NULL,
        uploaded_by VARCHAR(191) DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_fiscal_year (fiscal_year)
    ) {$charset_collate};";
    dbDelta( $sql16 );

    // 自社情報ページ用: 労働契約書などの書類をアップロード・保管する(自由な種類のファイル)。
    $doc_table = $wpdb->prefix . 'mush_company_documents';
    $sql17 = "CREATE TABLE {$doc_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(191) DEFAULT '',
        file_name VARCHAR(255) DEFAULT '',
        file_url VARCHAR(500) DEFAULT '',
        uploaded_by VARCHAR(191) DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id)
    ) {$charset_collate};";
    dbDelta( $sql17 );

    // 有休付与の記録。1行=1回の付与(入社時・毎年の定期付与など)。残日数は
    // 「付与日数の合計」-「mush_attendance_daysでday_type='有休'の日数」で都度計算する。
    $leave_grant_table = $wpdb->prefix . 'mush_leave_grants';
    $sql18 = "CREATE TABLE {$leave_grant_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        staff_id BIGINT UNSIGNED NOT NULL,
        grant_date VARCHAR(10) NOT NULL,
        days DECIMAL(4,1) NOT NULL DEFAULT 0,
        note VARCHAR(255) DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_staff (staff_id)
    ) {$charset_collate};";
    dbDelta( $sql18 );

    // 勤怠(日ごとの区分)。タイムカードの打刻(mush_time_cards)とは別に、
    // 「通常・欠勤・有休・特休・休日出勤」の区分と、給与明細確定用の出退勤時刻を保持する。
    // 未登録の日はタイムカードの打刻から都度補完して表示する(APIレスポンス側で行う)。
    $attendance_table = $wpdb->prefix . 'mush_attendance_days';
    $sql19 = "CREATE TABLE {$attendance_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        staff_id BIGINT UNSIGNED NOT NULL,
        record_date VARCHAR(10) NOT NULL,
        day_type VARCHAR(10) NOT NULL DEFAULT '通常',
        clock_in VARCHAR(5) DEFAULT '',
        clock_out VARCHAR(5) DEFAULT '',
        early_minutes INT NULL DEFAULT NULL,
        overtime_minutes INT NULL DEFAULT NULL,
        from_report TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_attendance (staff_id, record_date),
        KEY idx_date (record_date)
    ) {$charset_collate};";
    dbDelta( $sql19 );

    // 給与明細書。従業員×年月で1件。表示・印刷に使う値は全てdata_jsonにまとめて保持する
    // (勤怠集計・支給・控除の内訳など、項目数が多く可変のためJSON化している)。
    $payslip_table = $wpdb->prefix . 'mush_payslips';
    $sql20 = "CREATE TABLE {$payslip_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        staff_id BIGINT UNSIGNED NOT NULL,
        staff_name VARCHAR(191) DEFAULT '',
        department VARCHAR(50) DEFAULT '',
        employee_number INT NULL,
        pay_year INT NOT NULL,
        pay_month INT NOT NULL,
        period_start VARCHAR(10) DEFAULT '',
        period_end VARCHAR(10) DEFAULT '',
        data_json LONGTEXT NULL,
        created_by VARCHAR(191) DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        published_at DATETIME NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_payslip (staff_id, pay_year, pay_month)
    ) {$charset_collate};";
    dbDelta( $sql20 );

    // 給与明細に「公開」の状態を追加した版(1.47.0)へ更新したときだけ、すでにある明細は、従業員から見えるまま(公開済み)にする。
    // (以前は、保存するとすぐ従業員の「給与照会」に出ていたため、見えなくなると困る)。有効化・更新のどちらの経路でも通る。
    if ( $mush_prev_db_version && version_compare( $mush_prev_db_version, '1.47.0', '<' ) ) {
        $wpdb->query( "UPDATE {$payslip_table} SET published_at = COALESCE(updated_at, created_at) WHERE published_at IS NULL" );
    }

    // 給与明細書の保存履歴: 保存し直して内容が変わった(または削除した)ときに、上書き前の内容を1行ずつ残す。
    $payslip_hist_table = $wpdb->prefix . 'mush_payslip_history';
    $sql20h = "CREATE TABLE {$payslip_hist_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        payslip_id BIGINT UNSIGNED NOT NULL,
        staff_id BIGINT UNSIGNED NOT NULL,
        staff_name VARCHAR(191) DEFAULT '',
        pay_year INT NOT NULL,
        pay_month INT NOT NULL,
        data_json LONGTEXT NULL,
        saved_at DATETIME NULL,
        replaced_at DATETIME NOT NULL,
        replaced_by VARCHAR(191) DEFAULT '',
        reason VARCHAR(20) DEFAULT '',
        PRIMARY KEY  (id),
        KEY idx_payslip_hist (staff_id, pay_year, pay_month),
        KEY idx_payslip_id (payslip_id)
    ) {$charset_collate};";
    dbDelta( $sql20h );

    // 年末調整: 従業員×年で1件。入力した控除・計算結果はdata_jsonにまとめ、給与明細書に反映する過不足額(adjustment)と
    // 反映する月(apply_month)は、明細書の作成時に使うので別の列にも持つ。
    $year_end_table = $wpdb->prefix . 'mush_year_end';
    $sql_ye = "CREATE TABLE {$year_end_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        staff_id BIGINT UNSIGNED NOT NULL,
        staff_name VARCHAR(191) DEFAULT '',
        adjust_year INT NOT NULL,
        apply_month INT NOT NULL DEFAULT 12,
        adjustment INT NOT NULL DEFAULT 0,
        data_json LONGTEXT NULL,
        updated_by VARCHAR(191) DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_year_end (staff_id, adjust_year)
    ) {$charset_collate};";
    dbDelta( $sql_ye );

    // 年末調整の申告(従業員本人が「有給照会」ページから入力する、扶養・保険料などの申告内容)。従業員×年で1件。
    $year_end_decl_table = $wpdb->prefix . 'mush_year_end_decl';
    $sql_yed = "CREATE TABLE {$year_end_decl_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        staff_id BIGINT UNSIGNED NOT NULL,
        staff_name VARCHAR(191) DEFAULT '',
        decl_year INT NOT NULL,
        data_json LONGTEXT NULL,
        submitted_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_year_end_decl (staff_id, decl_year)
    ) {$charset_collate};";
    dbDelta( $sql_yed );

    // 日報の案件(mush_task_records)に添付する画像。1案件につき最大10枚まで
    // (アップロード時にAPI側で件数を制限する)。案件が「完了」になると、その30日後(expires_at)に自動で削除する。
    $task_image_table = $wpdb->prefix . 'mush_task_images';
    $sql21 = "CREATE TABLE {$task_image_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        task_record_id BIGINT UNSIGNED NOT NULL,
        file_url VARCHAR(500) DEFAULT '',
        file_name VARCHAR(255) DEFAULT '',
        uploaded_by VARCHAR(191) DEFAULT '',
        created_at DATETIME NOT NULL,
        expires_at DATETIME NULL,
        PRIMARY KEY  (id),
        KEY idx_task_record (task_record_id),
        KEY idx_expires (expires_at)
    ) {$charset_collate};";
    dbDelta( $sql21 );

    mush_office_hash_plain_login_passwords();

    // 1.35.0より前は、従業員→管理者のメッセージに既読を記録していなかった。
    // 過去分がすべて「未読」と表示されないよう、更新時に1回だけ既読扱いにする。
    if ( ! get_option( 'mush_office_chat_staff_read_migrated' ) ) {
        $wpdb->query( "UPDATE {$chat_table} SET is_read = 1 WHERE sender = 'staff'" );
        update_option( 'mush_office_chat_staff_read_migrated', 1 );
    }

    // 完了した案件の画像を30日後に削除する処理(1日1回)。
    if ( ! wp_next_scheduled( 'mush_office_purge_images_event' ) ) {
        wp_schedule_event( time() + 600, 'daily', 'mush_office_purge_images_event' );
    }

    // ヨコセ: 社員番号101の人は、全体スケジュールなどに普段は出さず、予定を入れた日だけ出す(初回の1回だけ設定し、以後は従業員登録で変更できる)。
    if ( ! get_option( 'mush_yokose_show_only_101_set' ) ) {
        $wpdb->update( $emp_table, array( 'show_only_when_scheduled' => 1 ), array( 'employee_number' => 101 ), array( '%d' ), array( '%d' ) );
        update_option( 'mush_yokose_show_only_101_set', 1 );
    }

    update_option( 'mush_office_db_version', MUSH_OFFICE_DB_VERSION );
}
register_activation_hook( __FILE__, 'mush_office_install' );

/**
 * ============================================================
 * 仮データ(デモ・動作確認用)の投入と削除
 * ============================================================
 * 設定→ヨコセオフィス業務システムの「仮データを投入」ボタンからだけ実行する
 * (プラグインの有効化時には何も入れない。販売先のサイトに勝手に架空の従業員が入らないようにするため)。
 * 投入した行のIDはオプション mush_office_sample_data_ids に記録し、「仮データを削除」でその行だけを消す。
 * 仮の従業員はメモ欄の先頭に MUSH_OFFICE_SAMPLE_MARK を付けて見分ける。
 * 日付はすべて実行した日を基準にする(日報は前後1ヶ月、勤怠は2ヶ月前の1日から昨日まで、給与は直近2ヶ月)。
 */
define( 'MUSH_OFFICE_SAMPLE_MARK', '【仮データ】' );

// 仮の従業員(社員番号・氏名・部署・給与形態・基本給・通勤手当・生年月日・入社日・扶養人数)。
function mush_office_sample_employee_defs() {
    return array(
        array( 124, '佐藤太郎', '営業', '月給', 250000, 12000, '1988-04-12', '2015-04-01', 2 ),
        array( 125, '佐藤花子', '営業', '月給', 240000, 8000, '1993-09-03', '2018-04-01', 0 ),
        array( 224, '鈴木一郎', 'デザイン', '月給', 260000, 10000, '1985-01-25', '2012-10-01', 3 ),
        array( 225, '鈴木美咲', 'デザイン', '月給', 250000, 6000, '1996-06-18', '2020-04-01', 0 ),
        array( 324, '高橋健太', '製作', '月給', 280000, 15000, '1979-11-30', '2008-04-01', 2 ),
        array( 325, '高橋由美', '製作', '月給', 270000, 9000, '1990-02-14', '2016-07-01', 1 ),
        array( 423, '山田太郎', '事務', '月給', 220000, 7000, '1982-08-08', '2011-04-01', 1 ),
        array( 424, '山田由香', '事務', '月給', 210000, 5000, '1998-12-01', '2022-04-01', 0 ),
    );
}

// 部署ごとの業務区分(日報ページ nippo1-fixed.html の CATEGORY_BY_DEPT と同じもの)。
function mush_office_sample_categories() {
    return array(
        '営業'     => array( '顧客訪問', '案件打合せ', '見積作成', '電話・メール対応', '現場管理', '納品・配達' ),
        'デザイン' => array( 'デザイン作成／修正', '出力データ作成', '入稿対応', '校正対応' ),
        '製作'     => array( '印刷／出力', '生産・加工', '現場での施工', '出荷準備', '機械調整／メンテナンス' ),
        '事務'     => array( '経理処理', '請求処理', '発注', '書類作成', '電話対応', '来客対応' ),
    );
}

// 会社の休日設定(自社情報の定休曜日・休日)から、その日が出勤日かどうかを判定する。
function mush_office_sample_is_workday( $date, $company ) {
    $weekly = isset( $company['weeklyHolidays'] ) && is_array( $company['weeklyHolidays'] ) ? array_map( 'intval', $company['weeklyHolidays'] ) : array( 0 );
    $holidays = isset( $company['holidayDates'] ) && is_array( $company['holidayDates'] ) ? $company['holidayDates'] : array();
    if ( in_array( intval( date( 'w', strtotime( $date ) ) ), $weekly, true ) ) {
        return false;
    }
    return ! in_array( $date, $holidays, true );
}

function mush_office_sample_minutes( $hhmm ) {
    $p = explode( ':', $hhmm );
    return intval( $p[0] ) * 60 + intval( isset( $p[1] ) ? $p[1] : 0 );
}

function mush_office_sample_hhmm( $minutes ) {
    return sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
}

// 経理の仮データ: 顧客・商品・見積書・請求書・原価計算書を各10件。見積書→請求書→原価計算書は同じ案件でつなぐ
// (見積NO・請求NOも対応させる)。番号は仮データと分かるよう「-901〜-910」にする。
function mush_office_insert_sample_keiri( $staff, $now, &$ids ) {
    global $wpdb;
    $mark  = MUSH_OFFICE_SAMPLE_MARK;
    $today = current_time( 'Y-m-d' );

    $clients = array(
        array( '株式会社山本工務店', '山本 隆', '山本 恵', '320-0811', '栃木県宇都宮市大通り2-1-5', '028-111-2201', "店舗改装\n看板更新" ),
        array( '青葉商事株式会社', '青木 誠', '', '321-0953', '栃木県宇都宮市東宿郷3-4-8', '028-222-3302', "展示会ブース\n名刺・封筒" ),
        array( 'みどり歯科クリニック', '緑川 晴美', '', '320-0033', '栃木県宇都宮市本町5-12', '028-333-4403', "院内サイン\nリーフレット" ),
        array( '北斗不動産株式会社', '北山 剛', '星野 愛', '321-0904', '栃木県宇都宮市陽東6-2-1', '028-444-5504', "物件看板\n分譲チラシ" ),
        array( '社会福祉法人さくら保育園', '桜井 千鶴', '', '320-0066', '栃木県宇都宮市一の沢1-8-3', '028-555-6605', "園児募集ポスター\n行事案内" ),
        array( '丸栄食品株式会社', '丸山 栄一', '', '321-0974', '栃木県宇都宮市竹林町1030', '028-666-7706', "パッケージ\n店舗サイン" ),
        array( '中央市役所 商工観光課', '', '', '320-8540', '栃木県宇都宮市旭1-1-5', '028-777-8807', "観光パンフレット\nイベント看板" ),
        array( 'ひかり薬局', '光田 俊', '', '321-0118', '栃木県宇都宮市雀宮町20-4', '028-888-9908', "店頭POP\n壁面サイン" ),
        array( '有限会社東都ホーム', '東海林 守', '', '320-0846', '栃木県宇都宮市西原町9-9', '028-999-1109', "モデルハウス看板\nカタログ" ),
        array( '宇都宮スポーツクラブ', '岡本 健二', '', '321-0961', '栃木県宇都宮市宿郷4-5-2', '028-123-4510', "会員募集チラシ\n施設案内サイン" ),
    );
    $client_ids = array();
    foreach ( $clients as $c ) {
        $ok = $wpdb->insert( $wpdb->prefix . 'mush_clients', array(
            'name' => $c[0], 'rep1' => $c[1], 'rep2' => $c[2], 'postal_code' => $c[3], 'address' => $c[4], 'phone' => $c[5],
            'projects' => $c[6], 'memo' => $mark, 'created_at' => $now,
        ) );
        if ( $ok ) {
            $cid = intval( $wpdb->insert_id );
            $ids['clients'][] = $cid;
            $client_ids[]     = array( 'id' => $cid, 'name' => $c[0], 'projects' => explode( "\n", $c[6] ) );
        }
    }

    // name, 原価, 売値, メーカー
    $products = array(
        array( 'カッティングシート(1m)', 800, 1500, '中川ケミカル' ), array( 'アクリル板 3mm A3', 1200, 2800, '三菱ケミカル' ),
        array( 'ターポリン幕 1㎡', 1500, 3500, '' ), array( 'A4チラシ 1000枚', 4000, 9800, '' ),
        array( '名刺 100枚', 600, 1800, '' ), array( 'ポスター B1 1枚', 1800, 4200, '' ),
        array( 'LEDスポットライト', 3200, 6800, 'パナソニック' ), array( 'アルミフレーム看板 W900', 9500, 22000, '' ),
        array( 'デザイン作成費(1式)', 0, 15000, '' ), array( '取付・施工費(1式)', 6000, 18000, '' ),
    );
    $plist = array();
    foreach ( $products as $pr ) {
        $ok = $wpdb->insert( $wpdb->prefix . 'mush_products', array(
            'name' => $pr[0], 'cost' => $pr[1], 'price' => $pr[2], 'maker' => $pr[3], 'created_at' => $now,
        ) );
        if ( $ok ) {
            $ids['products'][] = intval( $wpdb->insert_id );
            $plist[]           = $pr;
        }
    }
    if ( ! $client_ids || ! $plist ) {
        return;
    }

    // 担当者は営業の従業員から順に選ぶ(いなければ全員から)。
    $sales = array_values( array_filter( $staff, function ( $s ) { return '営業' === $s['department']; } ) );
    $pool  = $sales ? $sales : $staff;
    $quotes_t = $wpdb->prefix . 'mush_quotes';
    $inv_t    = $wpdb->prefix . 'mush_invoices';
    $cost_t   = $wpdb->prefix . 'mush_costs';
    for ( $i = 0; $i < 10; $i++ ) {
        $c      = $client_ids[ $i % count( $client_ids ) ];
        $proj   = $c['projects'][ $i % count( $c['projects'] ) ];
        $person = $pool ? $pool[ $i % count( $pool ) ] : null;
        $issue  = date( 'Y-m-d', strtotime( $today . ' -' . ( 5 + $i * 8 ) . ' days' ) );
        $ym     = date( 'Ym', strtotime( $issue ) );
        $seq    = str_pad( 901 + $i, 3, '0', STR_PAD_LEFT );
        $items  = array();
        $costs  = array();
        $subtotal = 0;
        $cost_total = 0;
        $n_items = 2 + ( $i % 3 );
        for ( $k = 0; $k < $n_items; $k++ ) {
            $pr  = $plist[ ( $i * 3 + $k * 2 ) % count( $plist ) ];
            $qty = 1 + ( ( $i + $k ) % 4 );
            $amount = $qty * $pr[2];
            $items[] = array( 'name' => $pr[0], 'quantity' => $qty, 'unitPrice' => $pr[2], 'amount' => $amount );
            $subtotal += $amount;
            if ( $pr[1] > 0 ) {
                $costs[]    = array( 'name' => $pr[0], 'quantity' => $qty, 'unitPrice' => $pr[1], 'amount' => $qty * $pr[1] );
                $cost_total += $qty * $pr[1];
            }
        }
        $total = $subtotal + floor( $subtotal * 0.1 );
        $who = array(
            'client_id' => $c['id'], 'client_name' => $c['name'], 'project_name' => $proj,
            'staff_id' => $person ? $person['id'] : null, 'staff_name' => $person ? $person['name'] : '',
        );
        $quote_no = 'Q' . $ym . '-' . $seq;
        $inv_no   = 'I' . $ym . '-' . $seq;
        $rep_no   = 'C' . $ym . '-' . $seq;
        $inv_date = date( 'Y-m-d', min( strtotime( $today ), strtotime( $issue . ' +14 days' ) ) );

        if ( $wpdb->insert( $quotes_t, array_merge( $who, array(
            'quote_no' => $quote_no, 'issue_date' => $issue, 'valid_months' => 1 + ( $i % 2 ),
            'items_json' => wp_json_encode( $items ), 'total_amount' => $total, 'memo' => $mark, 'created_at' => $now, 'updated_at' => $now,
        ) ) ) ) {
            $ids['quotes'][] = intval( $wpdb->insert_id );
        }
        if ( $wpdb->insert( $inv_t, array_merge( $who, array(
            'invoice_no' => $inv_no, 'quote_no' => $quote_no, 'issue_date' => $inv_date,
            'items_json' => wp_json_encode( $items ), 'total_amount' => $total, 'memo' => $mark, 'created_at' => $now, 'updated_at' => $now,
        ) ) ) ) {
            $ids['invoices'][] = intval( $wpdb->insert_id );
        }
        if ( $wpdb->insert( $cost_t, array_merge( $who, array(
            'report_no' => $rep_no, 'quote_no' => $quote_no, 'invoice_no' => $inv_no, 'issue_date' => $inv_date,
            'revenue_amount' => $subtotal, 'items_json' => wp_json_encode( $costs ), 'cost_total' => $cost_total,
            'memo' => $mark, 'created_at' => $now, 'updated_at' => $now,
        ) ) ) ) {
            $ids['costs'][] = intval( $wpdb->insert_id );
        }
    }
}

function mush_office_insert_sample_data() {
    global $wpdb;
    mush_office_delete_sample_data(); // 投入し直す場合は、前回の仮データを消してから入れる。

    $now     = current_time( 'mysql' );
    $today   = current_time( 'Y-m-d' );
    $company = get_option( 'mush_office_company_info', array() );
    if ( ! is_array( $company ) ) {
        $company = array();
    }
    $work_start = isset( $company['workStartTime'] ) ? $company['workStartTime'] : '09:00';
    $work_end   = isset( $company['workEndTime'] ) ? $company['workEndTime'] : '18:00';
    $ids = array( 'employees' => array(), 'task_records' => array(), 'time_cards' => array(), 'payslips' => array(),
        'clients' => array(), 'products' => array(), 'quotes' => array(), 'invoices' => array(), 'costs' => array() );
    $wpdb->suppress_errors( true ); // 一意キーの重複(すでに本物のデータがある日など)は黙ってスキップする。

    // ---- 従業員(既存の従業員はそのまま、仮の従業員を追加) ----
    $emp_table = $wpdb->prefix . 'mush_employees';
    foreach ( mush_office_sample_employee_defs() as $d ) {
        $row = array(
            'employee_number'  => $d[0],
            'name'             => $d[1],
            'department'       => $d[2],
            'wage_type'        => $d[3],
            'wage_rate'        => $d[4],
            'commute_allowance'=> $d[5],
            'birthdate'        => $d[6],
            'hire_date'        => $d[7],
            'dependents_count' => $d[8],
            'resident_tax_fixed' => round( $d[4] * 0.05, -2 ),
            'memo'             => MUSH_OFFICE_SAMPLE_MARK . ' 動作確認用の架空の従業員です。',
            'created_at'       => $now,
        );
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$emp_table} WHERE employee_number = %d", $d[0] ) );
        if ( $existing ) {
            // 同じ社員番号・氏名の行がある場合(以前の仮データ)は、それを仮データとして引き継ぐ。
            if ( $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$emp_table} WHERE id = %d", $existing ) ) !== $d[1] ) {
                continue; // 本物の従業員が同じ番号を使っているので触らない。
            }
            unset( $row['created_at'] );
            $wpdb->update( $emp_table, $row, array( 'id' => $existing ) );
            $ids['employees'][] = intval( $existing );
        } elseif ( $wpdb->insert( $emp_table, $row ) ) {
            $ids['employees'][] = intval( $wpdb->insert_id );
        }
    }
    // 仮の従業員の古い記録(以前のバージョンが入れたもの)を消してから入れ直す。
    if ( $ids['employees'] ) {
        $in = implode( ',', array_map( 'intval', $ids['employees'] ) );
        foreach ( array( 'mush_task_records', 'mush_schedule_tasks', 'mush_time_cards', 'mush_payslips', 'mush_payslip_history', 'mush_attendance_days' ) as $t ) {
            $wpdb->query( "DELETE FROM {$wpdb->prefix}{$t} WHERE staff_id IN ({$in})" );
        }
    }

    // 日報・勤怠は、既存の従業員も含めた全員分を入れる(カレンダーの「全員」表示を確認できるように)。
    $staff = $wpdb->get_results( "SELECT id, name, department, wage_type, wage_rate, commute_allowance, birthdate, dependents_count, resident_tax_fixed, employee_number FROM {$emp_table} ORDER BY employee_number", ARRAY_A );
    $categories = mush_office_sample_categories();
    $clients  = array( '山本工務店', '青葉商事', 'みどり歯科', '北斗不動産', 'さくら保育園', '丸栄食品', '中央市役所', 'ひかり薬局' );
    $projects = array( '看板製作', 'チラシ制作', '店舗サイン', 'パンフレット', 'ロゴデザイン', '展示会ブース', 'ウェブバナー', '名刺', 'ポスター' );
    $prefs    = array( '東京都', '神奈川県', '埼玉県', '千葉県' );

    // ---- 日報・予定(30日前〜30日後。過去は実績、今日以降は予定) ----
    $task_table = $wpdb->prefix . 'mush_task_records';
    foreach ( $staff as $s ) {
        $dept = isset( $categories[ $s['department'] ] ) ? $s['department'] : '事務';
        for ( $offset = -30; $offset <= 30; $offset++ ) {
            $date = date( 'Y-m-d', strtotime( $today . ' ' . sprintf( '%+d', $offset ) . ' days' ) );
            if ( ! mush_office_sample_is_workday( $date, $company ) ) {
                continue;
            }
            if ( $offset > 0 && wp_rand( 1, 100 ) > 60 ) {
                continue; // 先の予定は、全日ではなくところどころに入れる。
            }
            $span = wp_rand( 1, 100 ) <= 20 ? wp_rand( 2, 4 ) : 1; // ときどき数日がかりの案件にする。
            $due  = date( 'Y-m-d', strtotime( $date . ' +' . ( $span - 1 ) . ' days' ) );
            if ( $offset < 0 ) {
                $status   = $due < $today ? ( wp_rand( 1, 100 ) <= 90 ? '完了' : '保留' ) : '継続';
                $progress = '完了' === $status ? 100 : wp_rand( 3, 8 ) * 10;
            } elseif ( 0 === $offset ) {
                $status   = '継続';
                $progress = wp_rand( 2, 6 ) * 10;
            } else {
                $status   = '予定';
                $progress = 0;
            }
            $start = mush_office_sample_minutes( $work_start ) + wp_rand( 0, 4 ) * 30;
            $end   = min( mush_office_sample_minutes( $work_end ), $start + wp_rand( 3, 8 ) * 30 + 60 );
            $outside = '営業' === $dept && wp_rand( 1, 100 ) <= 40;
            $row = array(
                'record_date' => $date,
                'staff_id'    => $s['id'],
                'staff_name'  => $s['name'],
                'department'  => $s['department'],
                'category'    => $categories[ $dept ][ array_rand( $categories[ $dept ] ) ],
                'client'      => $clients[ array_rand( $clients ) ],
                'project'     => $projects[ array_rand( $projects ) ],
                'start_time'  => mush_office_sample_hhmm( $start ),
                'end_time'    => mush_office_sample_hhmm( $end ),
                'status'      => $status,
                'is_outside'  => $outside ? 1 : 0,
                'area'        => $outside ? '県内' : '',
                'prefecture'  => $outside ? $prefs[ array_rand( $prefs ) ] : '',
                'travel_type' => $outside ? '直行' : '',
                'note'        => MUSH_OFFICE_SAMPLE_MARK,
                'progress'    => $progress,
                'current_day' => 1,
                'total_days'  => $span,
                'due_date'    => $due,
                'created_at'  => $now,
                'updated_at'  => $now,
            );
            if ( $wpdb->insert( $task_table, $row ) ) {
                $ids['task_records'][] = intval( $wpdb->insert_id );
            }
        }
    }

    // ---- 勤怠(タイムカード。2ヶ月前の1日〜昨日) ----
    $tc_table  = $wpdb->prefix . 'mush_time_cards';
    $first     = date( 'Y-m-01', strtotime( date( 'Y-m-01', strtotime( $today ) ) . ' -2 months' ) );
    $worked    = array(); // [staff_id][date] = array(出勤分, 退勤分)
    foreach ( $staff as $s ) {
        for ( $date = $first; $date < $today; $date = date( 'Y-m-d', strtotime( $date . ' +1 day' ) ) ) {
            if ( ! mush_office_sample_is_workday( $date, $company ) || wp_rand( 1, 100 ) <= 4 ) {
                continue; // 休日と、たまの欠勤
            }
            $in  = mush_office_sample_minutes( $work_start ) - wp_rand( 0, 25 );
            $out = mush_office_sample_minutes( $work_end ) + ( wp_rand( 1, 100 ) <= 35 ? wp_rand( 1, 6 ) * 15 : wp_rand( 0, 10 ) );
            $ok = $wpdb->insert( $tc_table, array(
                'record_date' => $date,
                'staff_id'    => $s['id'],
                'staff_name'  => $s['name'],
                'department'  => $s['department'],
                'clock_in'    => mush_office_sample_hhmm( $in ),
                'clock_out'   => mush_office_sample_hhmm( $out ),
                'created_at'  => $now,
                'updated_at'  => $now,
            ) );
            if ( $ok ) {
                $ids['time_cards'][] = intval( $wpdb->insert_id );
                $worked[ $s['id'] ][ $date ] = array( $in, $out );
            }
        }
    }

    // ---- 給与明細(先月・先々月。給与計算ページで保存したときと同じ形式) ----
    $payslip_table = $wpdb->prefix . 'mush_payslips';
    $sched_minutes = mush_office_sample_minutes( $work_end ) - mush_office_sample_minutes( $work_start ) - 60; // 休憩1時間
    $overtime_rate = isset( $company['overtimeRate'] ) ? floatval( $company['overtimeRate'] ) / 100 : 0.25;
    foreach ( $staff as $s ) {
        if ( '月給' !== $s['wage_type'] || floatval( $s['wage_rate'] ) <= 0 ) {
            continue; // 給与設定のない従業員(本物の従業員で未設定のもの)には作らない。
        }
        $cum_gross = 0;
        $cum_taxable = 0;
        for ( $back = 2; $back >= 1; $back-- ) {
            $ym     = strtotime( date( 'Y-m-01', strtotime( $today ) ) . " -{$back} months" );
            $year   = intval( date( 'Y', $ym ) );
            $month  = intval( date( 'n', $ym ) );
            $pstart = date( 'Y-m-01', $ym );
            $pend   = date( 'Y-m-t', $ym );
            $sched_days = 0;
            for ( $d = $pstart; $d <= $pend; $d = date( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
                if ( mush_office_sample_is_workday( $d, $company ) ) {
                    $sched_days++;
                }
            }
            $worked_days = 0;
            $actual = 0;
            $overtime = 0;
            foreach ( isset( $worked[ $s['id'] ] ) ? $worked[ $s['id'] ] : array() as $d => $io ) {
                if ( $d < $pstart || $d > $pend ) {
                    continue;
                }
                $worked_days++;
                // 始業前の早出は残業に数えない(始業時刻から退勤までを実働とする)。
                $mins = max( 0, $io[1] - max( $io[0], mush_office_sample_minutes( $work_start ) ) - 60 );
                $actual += $mins;
                $overtime += max( 0, $mins - $sched_minutes );
            }
            $wage   = intval( $s['wage_rate'] );
            $hourly = $sched_days > 0 ? $wage / ( $sched_days * $sched_minutes / 60 ) : 0;
            $overtime_pay = intval( round( $hourly * ( $overtime / 60 ) * ( 1 + $overtime_rate ) ) );
            $commute = intval( $s['commute_allowance'] );
            $gross_ins = $wage + $overtime_pay + $commute;
            $health  = intval( floor( $gross_ins * 0.0499 ) );
            $age     = intval( date( 'Y', $ym ) ) - intval( substr( (string) $s['birthdate'], 0, 4 ) );
            $care    = ( $s['birthdate'] && $age >= 40 && $age < 65 ) ? intval( floor( $gross_ins * 0.008 ) ) : 0;
            $pension = intval( floor( $gross_ins * 0.0915 ) );
            $child   = intval( floor( $gross_ins * 0.00115 ) ); // 子ども・子育て支援金(本人負担の概算)
            $employ  = intval( floor( $gross_ins * 0.0055 ) );
            $taxable_income = max( 0, $gross_ins - $commute - $health - $care - $child - $pension - $employ );
            $income_tax = intval( max( 0, floor( ( $taxable_income - 88000 - intval( $s['dependents_count'] ) * 31667 ) * 0.05 / 10 ) * 10 ) );
            $resident = intval( $s['resident_tax_fixed'] );
            $gross    = $wage + $overtime_pay + $commute;
            $taxable  = $wage + $overtime_pay;
            $deduct   = $health + $care + $child + $pension + $employ + $income_tax + $resident;
            $data = array(
                'companyName' => isset( $company['name'] ) ? $company['name'] : '',
                'periodStart' => $pstart, 'periodEnd' => $pend, 'paymentDate' => $pend,
                'staffName' => $s['name'], 'employeeNumber' => intval( $s['employee_number'] ), 'department' => $s['department'],
                'scheduledDays' => $sched_days, 'workedDays' => $worked_days, 'absentDays' => max( 0, $sched_days - $worked_days ),
                'specialLeaveDays' => 0, 'paidLeaveDays' => 0, 'paidLeaveRemaining' => 0,
                'scheduledMinutes' => $sched_days * $sched_minutes, 'lateEarlyMinutes' => 0,
                'actualMinutes' => $actual, 'overtimeMinutes' => $overtime, 'holidayMinutes' => 0, 'nightMinutes' => 0,
                'basicPay' => $wage, 'overtimePay' => $overtime_pay, 'holidayPay' => 0, 'nightPay' => 0,
                'commuteAllowance' => $commute, 'commuteTaxable' => false,
                'qualificationAllowance' => 0, 'qualificationTaxable' => true,
                'positionAllowance' => 0, 'positionTaxable' => true,
                'housingAllowance' => 0, 'housingTaxable' => true,
                'familyAllowance' => 0, 'familyTaxable' => true,
                'other1Label' => '', 'other1Amount' => 0, 'other1Taxable' => true,
                'other2Label' => '', 'other2Amount' => 0, 'other2Taxable' => true,
                'other3Label' => '', 'other3Amount' => 0, 'other3Taxable' => true,
                'employmentInsurance' => $employ, 'healthInsurance' => $health, 'careInsurance' => $care,
                'pensionInsurance' => $pension, 'childSupport' => $child, 'incomeTax' => $income_tax,
                'residentTax' => $resident, 'lifeInsurance' => 0, 'wageSavings' => 0, 'stockOwnership' => 0,
                'dependents' => intval( $s['dependents_count'] ),
                'taxableCumulativePrev' => $cum_taxable, 'grossCumulativePrev' => $cum_gross,
                'grossPay' => $gross, 'taxableThisMonth' => $taxable, 'totalDeduction' => $deduct, 'netPay' => $gross - $deduct,
            );
            $ok = $wpdb->insert( $payslip_table, array(
                'staff_id'        => $s['id'],
                'staff_name'      => $s['name'],
                'department'      => $s['department'],
                'employee_number' => intval( $s['employee_number'] ),
                'pay_year'        => $year,
                'pay_month'       => $month,
                'period_start'    => $pstart,
                'period_end'      => $pend,
                'data_json'       => wp_json_encode( $data ),
                'created_by'      => MUSH_OFFICE_SAMPLE_MARK,
                'created_at'      => $now,
                'updated_at'      => $now,
                'published_at'    => $now, // 仮データは、従業員の「給与照会」にも出す
            ) );
            if ( $ok ) {
                $ids['payslips'][] = intval( $wpdb->insert_id );
            }
            $cum_gross += $gross;
            $cum_taxable += $taxable;
        }
    }

    // 経理(顧客・商品・見積書・請求書・原価計算書)
    mush_office_insert_sample_keiri( $staff, $now, $ids );

    $wpdb->suppress_errors( false );
    update_option( 'mush_office_sample_data_ids', $ids, false );
    return array_map( 'count', $ids );
}

// 投入した仮データだけを削除する(本物のデータには触らない)。
function mush_office_delete_sample_data() {
    global $wpdb;
    $ids = get_option( 'mush_office_sample_data_ids', array() );
    $tables = array(
        'task_records' => 'mush_task_records',
        'time_cards'   => 'mush_time_cards',
        'payslips'     => 'mush_payslips',
        'clients'      => 'mush_clients',
        'products'     => 'mush_products',
        'quotes'       => 'mush_quotes',
        'invoices'     => 'mush_invoices',
        'costs'        => 'mush_costs',
    );
    $deleted = 0;
    if ( ! empty( $ids['payslips'] ) ) {
        // 仮データの給与明細書の履歴も消す(消さないと、同じ従業員・年月で作り直した明細書に古い履歴が付いてしまう)。
        $wpdb->query( "DELETE FROM {$wpdb->prefix}mush_payslip_history WHERE payslip_id IN (" . implode( ',', array_map( 'intval', $ids['payslips'] ) ) . ')' );
    }
    foreach ( $tables as $key => $t ) {
        if ( ! empty( $ids[ $key ] ) ) {
            $deleted += intval( $wpdb->query( "DELETE FROM {$wpdb->prefix}{$t} WHERE id IN (" . implode( ',', array_map( 'intval', $ids[ $key ] ) ) . ')' ) );
        }
    }
    // 仮の従業員と、その人に紐づく記録をまとめて削除する。
    $emp_table = $wpdb->prefix . 'mush_employees';
    $emp_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$emp_table} WHERE memo LIKE %s", $wpdb->esc_like( MUSH_OFFICE_SAMPLE_MARK ) . '%' ) );
    if ( $emp_ids ) {
        $in = implode( ',', array_map( 'intval', $emp_ids ) );
        foreach ( array( 'mush_task_records', 'mush_schedule_tasks', 'mush_time_cards', 'mush_payslips', 'mush_payslip_history', 'mush_attendance_days', 'mush_leave_grants' ) as $t ) {
            $deleted += intval( $wpdb->query( "DELETE FROM {$wpdb->prefix}{$t} WHERE staff_id IN ({$in})" ) );
        }
        $deleted += intval( $wpdb->query( "DELETE FROM {$emp_table} WHERE id IN ({$in})" ) );
    }
    delete_option( 'mush_office_sample_data_ids' );
    delete_option( 'mush_office_sample_data_created' ); // v1.78〜1.80の旧フラグ
    return $deleted;
}

// 設定画面の「仮データを投入/削除」ボタンの処理。
add_action( 'admin_post_mush_office_sample_data', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( '権限がありません。' );
    }
    check_admin_referer( 'mush_office_sample_data' );
    if ( isset( $_POST['mush_sample_action'] ) && 'delete' === $_POST['mush_sample_action'] ) {
        $n = mush_office_delete_sample_data();
        $msg = '仮データを削除しました(' . $n . '件)。';
    } else {
        $c = mush_office_insert_sample_data();
        $msg = sprintf( '仮データを投入しました(従業員%d人・日報%d件・勤怠%d件・給与明細%d件・顧客%d件・商品%d件・見積書%d件・請求書%d件・原価計算書%d件)。',
            $c['employees'], $c['task_records'], $c['time_cards'], $c['payslips'], $c['clients'], $c['products'], $c['quotes'], $c['invoices'], $c['costs'] );
    }
    set_transient( 'mush_office_sample_data_msg', $msg, 60 );
    wp_safe_redirect( admin_url( 'options-general.php?page=mush-office-settings' ) );
    exit;
} );

/**
 * プラグイン更新時にもテーブル作成処理を実行する。
 * (すでに有効化済みの環境に新しいバージョンのファイルを上書きアップロードしただけでは
 *  activation_hookが再実行されないため、バージョン番号の変化を見て追従させる)
 */
add_action( 'plugins_loaded', function () {
    if ( get_option( 'mush_office_db_version' ) !== MUSH_OFFICE_DB_VERSION ) {
        mush_office_install();
    }
} );

/**
 * ============================================================
 * 固定ページの自動作成
 * ============================================================
 * プラグイン有効化時に、業務システム用の固定ページ(トップ・日報・進捗など)を
 * 自動で作成する。各ページには、対応するHTMLファイル(プラグイン本体と同じ
 * フォルダに同梱)の中身をそのまま「カスタムHTML」ブロックとして登録し、
 * Cocoonテーマの「ページ設定」を『本文のみ(フルワイド)』
 * ・タイトル/読む時間/目次を表示しない、に設定する。
 *
 * スラッグ・ページタイトルはREADME.md記載の構成、および各HTMLファイル内の
 * リンク生成スクリプト(getSiteBasePath()を使う箇所)が参照している値と
 * 一致させている。ここを変更する場合は該当HTMLファイル側も合わせて直すこと。
 */
function mush_office_page_definitions() {
    return array(
        array( 'slug' => 'トップ', 'title' => 'トップ', 'file' => 'top-page.html' ),
        array( 'slug' => '日報', 'title' => '個別スケジュール', 'file' => 'nippo1-fixed.html' ),
        array( 'slug' => '朝礼', 'title' => '全体スケジュール', 'file' => 'chorei-fixed.html' ),
        array( 'slug' => '従業員登録', 'title' => '従業員登録', 'file' => 'employee-register.html' ),
        array( 'slug' => '管理者集計', 'title' => '管理者集計', 'file' => 'kanri-shukei.html' ),
        array( 'slug' => '管理者チャット', 'title' => '連絡用チャット', 'file' => 'kanri-chat.html' ),
        array( 'slug' => '経理', 'title' => '経理', 'file' => 'kanri-keiri.html' ),
        array( 'slug' => 'タイムカード', 'title' => 'タイムカード', 'file' => 'timecard.html' ),
        array( 'slug' => 'QRコード', 'title' => 'QRコード生成', 'file' => 'qr-generate.html' ),
        array( 'slug' => '給与計算', 'title' => '給与計算', 'file' => 'kanri-kyuyo.html' ),
        array( 'slug' => '自社情報', 'title' => '自社情報', 'file' => 'kanri-jisha.html' ),
        array( 'slug' => '有給照会', 'title' => '有給休暇照会', 'file' => 'my-leave.html' ),
        array( 'slug' => '有給取得一覧', 'title' => '有給取得一覧', 'file' => 'kanri-yukyu.html' ),
    );
}

// Cocoonテーマの「ページ設定」メタボックスが保存する値と同じpostmetaキーを直接設定する。
// (実際に固定ページを1件保存して、admin-ajax経由の「カスタムフィールド」欄で
//  キー名・値を確認済み: page_type / the_page_title_novisible /
//  the_page_read_time_novisible / the_page_toc_novisible)
function mush_office_apply_cocoon_page_settings( $post_id ) {
    update_post_meta( $post_id, 'page_type', 'content_only_full_wide' );
    update_post_meta( $post_id, 'the_page_title_novisible', '1' );
    update_post_meta( $post_id, 'the_page_read_time_novisible', '1' );
    update_post_meta( $post_id, 'the_page_toc_novisible', '1' );
}

// 同じスラッグの固定ページが既にあれば、その投稿ID(ゴミ箱にある場合も含む)を返す。
// 無ければnull。運用者が手動でスラッグを変更・削除したページを勝手に作り直さないよう、
// 「新規作成するかどうか」の判定に使う(中身の上書きはしない)。
function mush_office_find_page_id_by_slug( $slug ) {
    $page = get_page_by_path( $slug, OBJECT, 'page' );
    if ( $page ) {
        return $page->ID;
    }
    $trashed = get_posts( array(
        'post_type'      => 'page',
        'name'           => $slug,
        'post_status'    => 'trash',
        'numberposts'    => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ) );
    return ! empty( $trashed ) ? intval( $trashed[0] ) : null;
}

function mush_office_create_pages() {
    foreach ( mush_office_page_definitions() as $def ) {
        $existing_id = mush_office_find_page_id_by_slug( $def['slug'] );
        if ( $existing_id ) {
            // 既存ページの中身は上書きしないが、Cocoonの表示設定だけは
            // 未設定であれば揃える(以前に手動で作成されたページも同じ見た目にするため)。
            mush_office_apply_cocoon_page_settings( $existing_id );
            continue;
        }

        $file_path = MUSH_OFFICE_PLUGIN_DIR . $def['file'];
        if ( ! file_exists( $file_path ) ) {
            continue;
        }
        $html = file_get_contents( $file_path );
        if ( false === $html ) {
            continue;
        }

        // script/style/onXXX属性などを含む同梱HTMLをそのまま保存するため、
        // 実行タイミング(有効化時の管理者、または更新後plugins_loadedの一般訪問者)に
        // 関わらずKSESフィルタを一時的に外す。中身はプラグイン同梱の信頼できるファイルのみ。
        //
        // wp_insert_post()は$_POST由来の「スラッシュ済み」データを想定しており、
        // 内部でstripslashesを行う。wp_slash()を通さずに生の文字列を渡すと、
        // 正規表現の \/ のようなバックスラッシュが1つ消費されて壊れてしまうため、
        // 事前に必ずwp_slash()を通す。
        kses_remove_filters();
        $post_id = wp_insert_post(
            wp_slash( array(
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => $def['title'],
                'post_name'    => $def['slug'],
                'post_content' => "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->",
            ) ),
            true
        );
        kses_init_filters();

        if ( ! is_wp_error( $post_id ) && $post_id ) {
            mush_office_apply_cocoon_page_settings( $post_id );
            // このプラグインが作ったページの目印。停止時の削除対象の判定に使う。
            update_post_meta( $post_id, MUSH_OFFICE_PAGE_META, $def['slug'] );
            // 作成した時点の中身の指紋。後で「手作業で編集されたか」を見分けるのに使う。
            update_post_meta( $post_id, '_mush_office_page_hash', md5( trim( str_replace( "\r\n", "\n", $html ) ) ) );
        }
    }
}
register_activation_hook( __FILE__, 'mush_office_create_pages' );

// 固定ページの中身が同梱HTMLファイルと同一か(前後の空白・wp:htmlブロックの囲みは無視)。
function mush_office_page_matches_bundled_file( $post_id, $file ) {
    $file_path = MUSH_OFFICE_PLUGIN_DIR . $file;
    if ( ! file_exists( $file_path ) ) {
        return false;
    }
    $bundled = file_get_contents( $file_path );
    $post    = get_post( $post_id );
    if ( false === $bundled || ! $post ) {
        return false;
    }
    $content = str_replace( "\r\n", "\n", $post->post_content );
    $content = preg_replace( '/^\s*<!-- wp:html -->/', '', $content );
    $content = preg_replace( '/<!-- \/wp:html -->\s*$/', '', $content );
    return trim( $content ) === trim( str_replace( "\r\n", "\n", $bundled ) );
}

/**
 * ============================================================
 * 固定ページを、同梱HTMLの最新の内容に更新する
 * ============================================================
 * プラグインを新しい版に更新しても、すでに作成済みの固定ページの中身は自動では変わらない(手作業の編集を
 * 勝手に消さないため)。そこで、このプラグインが作ったページ(目印付き)について、同梱HTMLと違えば最新の内容に
 * 書き換える。ただし、作成・前回の更新のあとに手作業で編集されたページは、既定では更新しない(「手動で変更
 * したページ」として扱う)。更新はWordPressのリビジョン(履歴)に残るので、元の内容に戻せる。
 *   - 自動: プラグインのファイルが更新された最初のアクセスで1回、手作業の編集が無いページだけ更新する。
 *   - 手動: 設定→ヨコセオフィス業務システムの「固定ページを最新の内容に更新」ボタン(手動で変更したページも
 *           上書きするかどうかを選べる)。
 * 戻り値: [スラッグ => 'current'(最新) | 'updated'(更新した) | 'outdated'(更新が必要・未更新) | 'modified'(手動で変更あり) | 'missing'(ページなし)]
 */
function mush_office_normalize_page_html( $html ) {
    $html = str_replace( "\r\n", "\n", strval( $html ) );
    $html = preg_replace( '/^\s*<!-- wp:html -->/', '', $html );
    $html = preg_replace( '/<!-- \/wp:html -->\s*$/', '', $html );
    return trim( $html );
}

function mush_office_sync_pages( $force = false, $apply = true ) {
    $report = array();
    foreach ( mush_office_page_definitions() as $def ) {
        $page = get_page_by_path( $def['slug'], OBJECT, 'page' );
        $file_path = MUSH_OFFICE_PLUGIN_DIR . $def['file'];
        if ( ! $page || ! file_exists( $file_path ) ) {
            $report[ $def['slug'] ] = 'missing';
            continue;
        }
        $html = file_get_contents( $file_path );
        if ( false === $html ) {
            $report[ $def['slug'] ] = 'missing';
            continue;
        }
        $tagged = '' !== strval( get_post_meta( $page->ID, MUSH_OFFICE_PAGE_META, true ) );
        // ヨコセのサイトには、プラグインがページを自動で作るようになる前に手作業で作ったページ(目印なし)がある。
        // 同じスラッグのページは、自動では触らず「手動で変更あり」として扱い、設定画面で上書きを選んだときだけ
        // このプラグインのページとして引き継ぐ(以後は目印が付き、更新のたびに自動で最新になる)。
        if ( ! $tagged && ! mush_office_page_matches_bundled_file( $page->ID, $def['file'] ) && ! $force ) {
            $report[ $def['slug'] ] = 'modified';
            continue;
        }
        // ページ名(タイトル)を変更した版への更新: 以前の既定のタイトルのままのページだけ、新しいタイトルにする(手で付けた名前は変えない)。
        if ( $apply && $tagged ) {
            $legacy_titles = array( '日報' => '日報', '朝礼' => '朝礼', '管理者チャット' => '管理者チャット' );
            if ( isset( $legacy_titles[ $def['slug'] ] ) && $page->post_title === $legacy_titles[ $def['slug'] ] && $def['title'] !== $page->post_title ) {
                wp_update_post( wp_slash( array( 'ID' => $page->ID, 'post_title' => $def['title'] ) ) );
            }
        }
        $new_norm = mush_office_normalize_page_html( $html );
        $cur_norm = mush_office_normalize_page_html( $page->post_content );
        $new_hash = md5( $new_norm );
        if ( $cur_norm === $new_norm ) {
            update_post_meta( $page->ID, '_mush_office_page_hash', $new_hash );
            $report[ $def['slug'] ] = 'current';
            continue;
        }
        $stored   = strval( get_post_meta( $page->ID, '_mush_office_page_hash', true ) );
        $modified = ( '' !== $stored && md5( $cur_norm ) !== $stored ); // 前回の同期のあとに手作業で変わっている
        if ( $modified && ! $force ) {
            $report[ $def['slug'] ] = 'modified';
            continue;
        }
        if ( ! $apply ) {
            $report[ $def['slug'] ] = $modified ? 'modified' : 'outdated';
            continue;
        }
        kses_remove_filters();
        $result = wp_update_post( wp_slash( array(
            'ID'           => $page->ID,
            'post_content' => "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->",
        ) ), true );
        kses_init_filters();
        if ( is_wp_error( $result ) || ! $result ) {
            $report[ $def['slug'] ] = 'outdated';
            continue;
        }
        mush_office_apply_cocoon_page_settings( $page->ID );
        update_post_meta( $page->ID, MUSH_OFFICE_PAGE_META, $def['slug'] );
        update_post_meta( $page->ID, '_mush_office_page_hash', $new_hash );
        $report[ $def['slug'] ] = 'updated';
    }
    return $report;
}

// 同梱HTMLのファイルが変わっていたら(プラグインを更新した直後)、最初のアクセスで1回だけ、
// 手作業の編集が無いページを最新の内容に更新する。毎回ファイルを読まないよう、更新日時とサイズだけで変化を見る。
add_action( 'init', function () {
    $sig = '';
    foreach ( mush_office_page_definitions() as $def ) {
        $path = MUSH_OFFICE_PLUGIN_DIR . $def['file'];
        $sig .= $def['file'] . ':' . ( file_exists( $path ) ? filemtime( $path ) . '/' . filesize( $path ) : '-' ) . ';';
    }
    $sig = md5( $sig );
    if ( get_option( 'mush_office_pages_bundle_sig' ) !== $sig ) {
        update_option( 'mush_office_pages_bundle_sig', $sig, false );
        mush_office_sync_pages( false, true );
    }
}, 30 );

// 設定画面の「固定ページを最新の内容に更新」ボタンの処理。
add_action( 'admin_post_mush_office_sync_pages', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( '権限がありません。' );
    }
    check_admin_referer( 'mush_office_sync_pages' );
    $force  = ! empty( $_POST['mush_sync_force'] );
    $report = mush_office_sync_pages( $force, true );
    $count  = array_count_values( $report );
    $msg = sprintf( '固定ページを更新しました(更新 %d / すでに最新 %d / 手動で変更されていて未更新 %d / 見つからない %d)。',
        isset( $count['updated'] ) ? $count['updated'] : 0, isset( $count['current'] ) ? $count['current'] : 0,
        isset( $count['modified'] ) ? $count['modified'] : 0, isset( $count['missing'] ) ? $count['missing'] : 0 );
    $actor = wp_get_current_user();
    mush_office_log_activity( $actor ? $actor->display_name : '', '固定ページを最新の内容に更新', $force ? '手動で変更したページも上書き' : '', $msg );
    set_transient( 'mush_office_pages_sync_msg', $msg, 60 );
    wp_safe_redirect( admin_url( 'options-general.php?page=mush-office-settings' ) );
    exit;
} );

// プラグイン停止時に、このプラグインのページを完全削除する(ゴミ箱を経由しない)。
// 再有効化すると同梱HTMLから作り直されるため、ページはいつでもプラグインだけで再現できる。
// 削除するのは(1)目印付き=このプラグインが作ったページ(ページ上で編集されていても削除)と、
// (2)目印導入前の版で作られたページのうち、中身が同梱HTMLと同一のものだけ。
// 同じスラッグでも運用者が独自に作ったページ(中身が異なり目印もない)は削除しない。
function mush_office_delete_pages() {
    $ids = get_posts( array(
        'post_type'        => 'page',
        'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
        'meta_key'         => MUSH_OFFICE_PAGE_META,
        'numberposts'      => -1,
        'fields'           => 'ids',
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ) );
    foreach ( mush_office_page_definitions() as $def ) {
        $existing_id = mush_office_find_page_id_by_slug( $def['slug'] );
        if ( $existing_id && ! in_array( $existing_id, $ids, true )
            && mush_office_page_matches_bundled_file( $existing_id, $def['file'] ) ) {
            $ids[] = $existing_id;
        }
    }
    foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
        wp_delete_post( $id, true );
    }
    delete_option( 'mush_office_pages_version' );
    wp_clear_scheduled_hook( 'mush_office_license_check_event' );
    wp_clear_scheduled_hook( 'mush_office_purge_images_event' );
}
register_deactivation_hook( __FILE__, 'mush_office_delete_pages' );

// 既にプラグインが有効化済みの環境にこのバージョンのファイルを上書きアップロードしただけの
// 場合(activation_hookは再実行されない)にも、次回アクセス時に1回だけページ作成を追従させる。
add_action( 'plugins_loaded', function () {
    if ( get_option( 'mush_office_pages_version' ) !== MUSH_OFFICE_PAGES_VERSION ) {
        mush_office_create_pages();
        update_option( 'mush_office_pages_version', MUSH_OFFICE_PAGES_VERSION );
    }
} );

/**
 * ============================================================
 * 部署の登録
 * ============================================================
 * 標準の4部署(営業・デザイン・製作・事務)に加え、従業員登録ページから新しい部署を追加できる。
 * 新しい部署は 500番台・600番台…と順に番台(従業員Noの百の位)を割り当てる。
 * 追加した部署はオプション mush_office_custom_departments に保存する(標準の4部署は削除できない)。
 */
function mush_office_builtin_departments() {
    return array(
        array( 'name' => '営業',   'band' => 100, 'fg' => '#0369a1', 'bg' => '#e0f2fe' ),
        array( 'name' => 'デザイン', 'band' => 200, 'fg' => '#6d28d9', 'bg' => '#ede9fe' ),
        array( 'name' => '製作',   'band' => 300, 'fg' => '#047857', 'bg' => '#d1fae5' ),
        array( 'name' => '事務',   'band' => 400, 'fg' => '#b45309', 'bg' => '#fef3c7' ),
    );
}

// 管理部門(000番台)を、追加部署として一度だけ登録する。標準の4部署より前の番台なので、
// 従業員No.は 001, 002… と付く。他の追加部署と同じく、所属する従業員がいなければ削除できる
// (一度削除したら、あとから自動で復活はしない)。
add_action( 'init', function () {
    if ( get_option( 'mush_office_admin_dept_seeded' ) ) {
        return;
    }
    $custom = get_option( 'mush_office_custom_departments', array() );
    if ( ! is_array( $custom ) ) {
        $custom = array();
    }
    $exists = false;
    foreach ( mush_office_all_departments() as $d ) {
        if ( '管理部門' === $d['name'] ) {
            $exists = true;
        }
    }
    if ( ! $exists ) {
        array_unshift( $custom, array( 'name' => '管理部門', 'band' => 0, 'fg' => '#be185d', 'bg' => '#fce7f3' ) );
        update_option( 'mush_office_custom_departments', $custom, false );
    }
    update_option( 'mush_office_admin_dept_seeded', 1, false );
}, 31 );

// 新しい部署に順番に割り当てる色(文字色, 背景色)。
function mush_office_department_palette() {
    return array(
        array( '#be185d', '#fce7f3' ), array( '#0e7490', '#cffafe' ), array( '#4d7c0f', '#ecfccb' ),
        array( '#4338ca', '#e0e7ff' ), array( '#a16207', '#fef9c3' ), array( '#475569', '#e2e8f0' ),
        array( '#b91c1c', '#fee2e2' ),
    );
}

// 標準の部署+追加した部署の一覧([name, band, fg, bg, builtin])。
function mush_office_all_departments() {
    $out = array();
    foreach ( mush_office_builtin_departments() as $d ) {
        $d['builtin'] = true;
        $out[]        = $d;
    }
    $custom = get_option( 'mush_office_custom_departments', array() );
    if ( is_array( $custom ) ) {
        foreach ( $custom as $d ) {
            if ( is_array( $d ) && ! empty( $d['name'] ) && isset( $d['band'] ) && is_numeric( $d['band'] ) ) { // band 0 = 000番台(管理部門)
                $out[] = array(
                    'name' => strval( $d['name'] ), 'band' => intval( $d['band'] ),
                    'fg' => isset( $d['fg'] ) ? strval( $d['fg'] ) : '#475569', 'bg' => isset( $d['bg'] ) ? strval( $d['bg'] ) : '#e2e8f0',
                    'builtin' => false,
                );
            }
        }
    }
    usort( $out, function ( $a, $b ) { return $a['band'] <=> $b['band']; } ); // 000番台(管理部門)が先頭
    return $out;
}

function mush_office_get_departments( WP_REST_Request $request ) {
    global $wpdb;
    $counts = array();
    $rows   = $wpdb->get_results( "SELECT department, COUNT(*) AS n FROM {$wpdb->prefix}mush_employees GROUP BY department", ARRAY_A );
    foreach ( (array) $rows as $r ) {
        $counts[ $r['department'] ] = intval( $r['n'] );
    }
    $out = array();
    foreach ( mush_office_all_departments() as $d ) {
        $d['employeeCount'] = isset( $counts[ $d['name'] ] ) ? $counts[ $d['name'] ] : 0;
        $out[]              = $d;
    }
    return new WP_REST_Response( $out, 200 );
}

function mush_office_add_department( WP_REST_Request $request ) {
    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }
    $raw  = isset( $p['name'] ) ? trim( strval( $p['name'] ) ) : '';
    $name = sanitize_text_field( $raw );
    if ( '' === $name || $name !== $raw || preg_match( '#[\\s\\x{3000}&<>"\'\\\\/]#u', $name ) ) {
        return new WP_REST_Response( array( 'error' => '部署名を入力してください(空白と & < > " \' / \\ は使えません)。' ), 400 );
    }
    if ( mb_strlen( $name ) > 20 ) {
        return new WP_REST_Response( array( 'error' => '部署名は20文字以内にしてください。' ), 400 );
    }
    $all  = mush_office_all_departments();
    $bands = array( 400 ); // 追加部署は500番台から(管理部門の000番台は別枠)
    foreach ( $all as $d ) {
        if ( 0 === strcasecmp( $d['name'], $name ) ) {
            return new WP_REST_Response( array( 'error' => '同じ名前の部署がすでにあります。' ), 400 );
        }
        $bands[] = $d['band'];
    }
    $custom_count = count( $all ) - count( mush_office_builtin_departments() );
    if ( $custom_count >= 20 ) {
        return new WP_REST_Response( array( 'error' => '追加できる部署は20個までです。' ), 400 );
    }
    $palette = mush_office_department_palette();
    $color   = $palette[ $custom_count % count( $palette ) ];
    $custom  = get_option( 'mush_office_custom_departments', array() );
    if ( ! is_array( $custom ) ) {
        $custom = array();
    }
    $entry    = array( 'name' => $name, 'band' => max( $bands ) + 100, 'fg' => $color[0], 'bg' => $color[1] );
    $custom[] = $entry;
    update_option( 'mush_office_custom_departments', $custom, false );
    $actor = mush_office_request_actor( $request );
    mush_office_log_activity( $actor['name'], '部署を登録', $name, sprintf( '%03d', $entry['band'] + 1 ) . 'から採番' );
    $entry['builtin'] = false;
    $entry['employeeCount'] = 0;
    return new WP_REST_Response( $entry, 201 );
}

// 追加した部署の削除。標準の4部署と、従業員が1人でも所属している部署は削除できない。
function mush_office_delete_department( WP_REST_Request $request ) {
    global $wpdb;
    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }
    $name = isset( $p['name'] ) ? trim( strval( $p['name'] ) ) : '';
    foreach ( mush_office_builtin_departments() as $d ) {
        if ( $d['name'] === $name ) {
            return new WP_REST_Response( array( 'error' => '標準の部署は削除できません。' ), 400 );
        }
    }
    $custom = get_option( 'mush_office_custom_departments', array() );
    $kept   = array();
    $found  = false;
    foreach ( (array) $custom as $d ) {
        if ( is_array( $d ) && isset( $d['name'] ) && $d['name'] === $name ) {
            $found = true;
            continue;
        }
        $kept[] = $d;
    }
    if ( ! $found ) {
        return new WP_REST_Response( array( 'error' => '部署が見つかりません。' ), 404 );
    }
    $n = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mush_employees WHERE department = %s", $name ) ) );
    if ( $n > 0 ) {
        return new WP_REST_Response( array( 'error' => 'この部署には従業員が' . $n . '人所属しています。先に従業員の部署を変更するか削除してください。' ), 400 );
    }
    update_option( 'mush_office_custom_departments', $kept, false );
    $actor = mush_office_request_actor( $request );
    mush_office_log_activity( $actor['name'], '部署を削除', $name );
    return new WP_REST_Response( array( 'deleted' => true ), 200 );
}

/**
 * ============================================================
 * 部署ごとの従業員番号の採番ルール
 * 管理部門: 000番台 / 営業: 100番台 / デザイン: 200番台 / 製作: 300番台 / 事務: 400番台 / 追加した部署: 500番台以降
 * 各番台とも21番から付ける(例: 営業は121・122…、管理部門は021・022…)。
 * ============================================================
 */
// 部署の番台(従業員Noの百の位)。登録の無い部署は900番台。
function mush_office_department_band( $department ) {
    foreach ( mush_office_all_departments() as $d ) {
        if ( $d['name'] === $department ) {
            return intval( $d['band'] );
        }
    }
    return 900;
}

function mush_office_next_employee_number( $department ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_employees';

    $base = mush_office_department_band( $department );

    $max = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT MAX(employee_number) FROM {$table_name} WHERE employee_number >= %d AND employee_number < %d",
            $base,
            $base + 100
        )
    );

    $first = $base + 21;
    $next  = empty( $max ) ? $first : max( intval( $max ) + 1, $first );
    // 社員番号は手で自由に変えられるため、ほかの番台の人が使っている番号は飛ばす(重複すると登録できないため)。
    while ( $next <= 999 && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE employee_number = %d", $next ) ) ) {
        $next++;
    }
    return $next;
}

/**
 * ============================================================
 * 従業員ログイン(本人確認)関連
 * ============================================================
 * WordPressのユーザーアカウントではなく、wp_mush_employeesの各行に
 * 対して「パスワード」を持たせ、日報ページなどから本人確認するための
 * 仕組み。セッションテーブルを持たず、HMAC署名付きトークン(有効期限つき)を
 * 発行するステートレス方式にしている。
 *
 * パスワードは従業員登録ページで管理者が設定する。login_password列には
 * WordPressのパスワードハッシュ(wp_hash_password)で保存し、平文は残さない。
 * 一覧には「設定済み/未設定」だけを表示し、忘れた場合は管理者が再設定する。
 * 従業員によるセルフサービスでの初回登録は行わない。
 */
// 従業員のログインパスワードは6文字以上にする(新しく設定・変更するときに確認する。今のパスワードでのログインはそのまま使える)。
define( 'MUSH_OFFICE_MIN_PASSWORD_LENGTH', 6 );
function mush_office_login_password_too_short( $password ) {
    return mb_strlen( (string) $password ) < MUSH_OFFICE_MIN_PASSWORD_LENGTH;
}

function mush_office_hash_login_password( $password ) {
    return wp_hash_password( $password );
}

// login_password列の値がハッシュ済みかどうか(1.70.0以前は平文で保存していた)。
function mush_office_is_hashed_login_password( $stored ) {
    return (bool) preg_match( '/^\$(P\$|H\$|wp\$|2y\$|argon2)/', (string) $stored );
}

// 平文のまま残っているlogin_passwordをハッシュに置き換える(更新時・バックアップ復元時に実行)。
function mush_office_hash_plain_login_passwords() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_employees';
    $rows = $wpdb->get_results( "SELECT id, login_password FROM {$table_name} WHERE login_password <> ''", ARRAY_A );
    foreach ( (array) $rows as $r ) {
        if ( mush_office_is_hashed_login_password( $r['login_password'] ) ) {
            continue;
        }
        $wpdb->update(
            $table_name,
            array( 'login_password' => mush_office_hash_login_password( $r['login_password'] ) ),
            array( 'id' => intval( $r['id'] ) ),
            array( '%s' ),
            array( '%d' )
        );
    }
}

// 従業員の行を画面に返す前に、パスワード(ハッシュ)を取り除き「設定済みか」だけを付ける。
function mush_office_public_employee_row( $row ) {
    if ( ! is_array( $row ) ) {
        return $row;
    }
    $row['has_login_password'] = ! empty( $row['login_password'] );
    unset( $row['login_password'], $row['password_hash'] );
    return $row;
}

function mush_office_issue_token( $staff_id ) {
    $expires = time() + MUSH_OFFICE_AUTH_TTL;
    $payload = $staff_id . '.' . $expires;
    $sig     = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
    return base64_encode( $payload . '.' . $sig );
}

// トークンを検証し、有効であれば従業員IDを返す。無効・期限切れならnull。
function mush_office_verify_token( $token ) {
    if ( empty( $token ) ) {
        return null;
    }
    $decoded = base64_decode( $token, true );
    if ( false === $decoded ) {
        return null;
    }
    $parts = explode( '.', $decoded );
    if ( count( $parts ) !== 3 ) {
        return null;
    }
    list( $staff_id, $expires, $sig ) = $parts;
    $expected = hash_hmac( 'sha256', $staff_id . '.' . $expires, wp_salt( 'auth' ) );
    if ( ! hash_equals( $expected, $sig ) ) {
        return null;
    }
    if ( intval( $expires ) < time() ) {
        return null;
    }
    return intval( $staff_id );
}

// リクエストのAuthorizationヘッダー(Bearer方式)から、ログイン中の従業員IDを取り出す。
function mush_office_get_bearer_staff_id( WP_REST_Request $request ) {
    $auth = $request->get_header( 'authorization' );
    if ( ! $auth || stripos( $auth, 'Bearer ' ) !== 0 ) {
        return null;
    }
    return mush_office_verify_token( trim( substr( $auth, 7 ) ) );
}

// 従業員テーブルのis_adminフラグを確認する(従業員リストで個別に付与する管理者権限)。
function mush_office_staff_is_admin( $staff_id ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_employees';
    $val = $wpdb->get_var( $wpdb->prepare( "SELECT is_admin FROM {$table_name} WHERE id = %d", $staff_id ) );
    return '1' === strval( $val );
}

// 管理者ログインで発行したトークン(従業員ID=0の共通パスワード、または
// is_admin=1の従業員が自分のパスワードでログインしたトークン)が付いたリクエストかどうか。
function mush_office_is_admin_request( WP_REST_Request $request ) {
    $staff_id = mush_office_get_bearer_staff_id( $request );
    if ( null === $staff_id ) {
        return false;
    }
    if ( 0 === $staff_id ) {
        return true;
    }
    return mush_office_staff_is_admin( $staff_id );
}

// 管理者トークン必須のエンドポイント用のpermission_callback。
function mush_office_admin_permission( WP_REST_Request $request ) {
    if ( mush_office_is_admin_request( $request ) ) {
        return true;
    }
    return new WP_Error( 'mush_admin_required', '管理者ログインが必要です。', array( 'status' => 401 ) );
}

// 管理者または従業員のログイントークンが付いたリクエストだけを許可するpermission_callback。
function mush_office_login_permission( WP_REST_Request $request ) {
    if ( null !== mush_office_get_bearer_staff_id( $request ) ) {
        return true;
    }
    return new WP_Error( 'mush_login_required', 'ログインが必要です。', array( 'status' => 401 ) );
}

// チャットの閲覧: 管理者(共通パスワード・管理者権限のある従業員)、または自分自身(staffIdが本人)の
// スレッドだけを許可する。
function mush_office_chat_read_permission( WP_REST_Request $request ) {
    $auth_staff_id = mush_office_get_bearer_staff_id( $request );
    if ( null !== $auth_staff_id ) {
        if ( mush_office_is_admin_request( $request ) || $auth_staff_id === intval( $request->get_param( 'staffId' ) ) ) {
            return true;
        }
    }
    return new WP_Error( 'mush_login_required', 'ログインが必要です。', array( 'status' => 401 ) );
}

function mush_office_auth_login( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_employees';

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }

    $staff_id = isset( $p['staffId'] ) ? intval( $p['staffId'] ) : 0;
    $password = isset( $p['password'] ) ? (string) $p['password'] : '';

    if ( ! $staff_id || '' === $password ) {
        return new WP_REST_Response( array( 'error' => '従業員とパスワードを指定してください。' ), 400 );
    }

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $staff_id ), ARRAY_A );
    if ( ! $row ) {
        return new WP_REST_Response( array( 'error' => '従業員が見つかりません。' ), 404 );
    }

    if ( empty( $row['login_password'] ) ) {
        return new WP_REST_Response( array( 'error' => 'パスワードが設定されていません。管理者にお問い合わせください。' ), 403 );
    }
    $stored = (string) $row['login_password'];
    if ( mush_office_is_hashed_login_password( $stored ) ) {
        $ok = wp_check_password( $password, $stored );
    } else {
        // 平文が残っている場合(ハッシュ化前のバックアップを復元した直後など)は照合後にハッシュ化する。
        $ok = hash_equals( $stored, $password );
        if ( $ok ) {
            $wpdb->update( $table_name, array( 'login_password' => mush_office_hash_login_password( $password ) ), array( 'id' => $staff_id ), array( '%s' ), array( '%d' ) );
        }
    }
    if ( ! $ok ) {
        return new WP_REST_Response( array( 'error' => 'パスワードが違います。' ), 403 );
    }

    mush_office_log_activity( $row['name'], 'ログイン', $row['department'] );

    return new WP_REST_Response(
        array(
            'token'     => mush_office_issue_token( $staff_id ),
            'staffId'   => strval( $staff_id ),
            'staffName' => $row['name'],
            'dept'      => $row['department'],
            'isAdmin'   => '1' === strval( $row['is_admin'] ),
            'expiresIn' => MUSH_OFFICE_AUTH_TTL,
        ),
        200
    );
}

/**
 * ============================================================
 * 管理者ページ用ログイン(従業員登録・管理者チャット・管理者集計)
 * ============================================================
 * WordPress自体のパスワード保護機能は使わず、日報ページの従業員ログインと
 * 同じ「パスワード入力→トークン発行」の仕組みを管理者用に流用する。
 * 従業員ID(staff_id)には実在しない0を割り当てることで、
 * mush_office_issue_token/verify_tokenをそのまま再利用できるようにしている。
 */
function mush_office_admin_login( WP_REST_Request $request ) {
    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }
    $password = isset( $p['password'] ) ? (string) $p['password'] : '';
    $stored   = get_option( 'mush_office_admin_password', '' );

    if ( '' === $stored ) {
        return new WP_REST_Response( array( 'error' => '管理者パスワードがまだ設定されていません。経理ページの自社情報から設定してください。' ), 400 );
    }
    if ( '' === $password || ! hash_equals( (string) $stored, $password ) ) {
        return new WP_REST_Response( array( 'error' => 'パスワードが違います。' ), 403 );
    }

    return new WP_REST_Response(
        array(
            'token'     => mush_office_issue_token( 0 ),
            'expiresIn' => MUSH_OFFICE_AUTH_TTL,
        ),
        200
    );
}

function mush_office_get_admin_password_status( WP_REST_Request $request ) {
    $stored = get_option( 'mush_office_admin_password', '' );
    return new WP_REST_Response( array( 'isSet' => ( '' !== $stored ) ), 200 );
}

// 管理者パスワードの設定・変更(経理ページの自社情報タブから使う)。
// 未設定の間(初回設定)は誰でも設定できる。設定後は、管理者ログイン済み(トークン付き)か、
// WordPressの管理者ユーザー(忘れた場合の復旧用)だけが変更できる。
function mush_office_admin_or_setup_permission( WP_REST_Request $request ) {
    if ( '' === get_option( 'mush_office_admin_password', '' ) ) {
        return true;
    }
    if ( mush_office_is_admin_request( $request ) || current_user_can( 'manage_options' ) ) {
        return true;
    }
    return new WP_Error( 'mush_admin_required', '管理者ログインが必要です。', array( 'status' => 401 ) );
}

function mush_office_set_admin_password( WP_REST_Request $request ) {
    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }
    $new_password = isset( $p['password'] ) ? (string) $p['password'] : '';

    if ( '' === $new_password ) {
        return new WP_REST_Response( array( 'error' => '新しいパスワードを入力してください。' ), 400 );
    }

    update_option( 'mush_office_admin_password', $new_password );
    return new WP_REST_Response( array( 'saved' => true ), 200 );
}

// 共通の管理者パスワードを削除し、未設定状態に戻す(従業員個別の管理者権限には影響しない)。
function mush_office_reset_admin_password( WP_REST_Request $request ) {
    delete_option( 'mush_office_admin_password' );
    mush_office_log_activity( wp_get_current_user()->display_name, '共通の管理者パスワードをリセット', '' );
    return new WP_REST_Response( array( 'saved' => true ), 200 );
}

/**
 * ============================================================
 * WordPress管理画面(設定ページ)から、共通の管理者パスワードを設定できるようにする。
 * これまでは経理ページの自社情報タブからしか変更できなかったが、
 * WordPressにログインできる人(manage_options権限)ならここからも設定・削除できる。
 * ============================================================
 */
add_action( 'admin_menu', function () {
    add_options_page(
        'ヨコセオフィス業務システム',
        'ヨコセオフィス業務システム',
        'manage_options',
        'mush-office-settings',
        'mush_office_render_settings_page'
    );
} );

add_action( 'admin_init', function () {
    register_setting( 'mush_office_settings_group', 'mush_office_admin_password', array(
        'type'              => 'string',
        'sanitize_callback' => 'mush_office_sanitize_admin_password_setting',
        'default'           => '',
    ) );
} );

// 設定画面は現在のパスワードを表示しないため、空欄のまま保存したら「変更なし」として扱い、
// 削除は専用のチェックボックスでだけ行う。WordPressはupdate_option()のたびにこの関数を通すが、
// REST APIの設定処理は空文字を保存せず、リセットはdelete_option()なので影響しない。
function mush_office_sanitize_admin_password_setting( $value ) {
    if ( ! empty( $_POST['mush_office_admin_password_clear'] ) ) {
        return '';
    }
    $value = sanitize_text_field( (string) $value );
    if ( '' === $value ) {
        return get_option( 'mush_office_admin_password', '' );
    }
    return $value;
}


function mush_office_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $current = get_option( 'mush_office_admin_password', '' );
    ?>
    <div class="wrap">
        <h1>ヨコセオフィス業務システム設定</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'mush_office_settings_group' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="mush_office_admin_password">管理者共通パスワード</label></th>
                    <td>
                        <input type="password" id="mush_office_admin_password" name="mush_office_admin_password" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo '' !== $current ? esc_attr( '変更する場合のみ入力' ) : esc_attr( '新しいパスワードを入力' ); ?>">
                        <p class="description">
                            従業員登録・連絡用チャット・管理者集計・トップページの「管理者ログイン」で、
                            誰でも使える共通パスワードです(従業員ごとに個別付与する管理者権限とは別の仕組みです)。<br>
                            現在のパスワードは表示しません。空欄のまま保存すると、今のパスワードがそのまま残ります。
                        </p>
                        <?php if ( '' !== $current ) : ?>
                        <p>
                            <label><input type="checkbox" name="mush_office_admin_password_clear" value="1"> 共通パスワードを削除する(未設定に戻す)</label>
                        </p>
                        <?php endif; ?>
                        <p style="<?php echo '' !== $current ? 'color:#3F8F6B;' : 'color:#B45309;'; ?>">
                            現在: <?php echo '' !== $current ? '設定済み' : '未設定'; ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button( '保存' ); ?>
        </form>
        <h2>固定ページ</h2>
        <?php $pages_msg = get_transient( 'mush_office_pages_sync_msg' ); ?>
        <?php if ( $pages_msg ) : delete_transient( 'mush_office_pages_sync_msg' ); ?>
        <div class="notice notice-success inline"><p><?php echo esc_html( $pages_msg ); ?></p></div>
        <?php endif; ?>
        <?php
        $page_status = mush_office_sync_pages( false, false );
        $status_label = array( 'current' => '最新', 'outdated' => '更新あり', 'modified' => '手動で変更あり', 'missing' => 'ページなし' );
        $outdated_n = count( array_filter( $page_status, function ( $v ) { return 'outdated' === $v; } ) );
        $modified_n = count( array_filter( $page_status, function ( $v ) { return 'modified' === $v; } ) );
        ?>
        <p class="description">
            システムの固定ページ(日報・朝礼・給与計算など)の中身は、プラグインを更新しても自動では置き換わりません(手作業の編集を消さないため)。
            下のボタンで、プラグインに同梱された最新の内容に更新できます。更新前の内容は、WordPressのリビジョン(固定ページの編集画面の「リビジョン」)に残ります。
        </p>
        <table class="widefat striped" style="max-width:520px;margin:8px 0;">
            <tbody>
            <?php foreach ( $page_status as $slug => $st ) : ?>
                <tr><td><?php echo esc_html( $slug ); ?></td><td><?php echo esc_html( isset( $status_label[ $st ] ) ? $status_label[ $st ] : $st ); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('固定ページを最新の内容に更新します。更新前の内容はリビジョンに残ります。よろしいですか？');">
            <input type="hidden" name="action" value="mush_office_sync_pages">
            <?php wp_nonce_field( 'mush_office_sync_pages' ); ?>
            <?php if ( $modified_n > 0 ) : ?>
            <p><label><input type="checkbox" name="mush_sync_force" value="1"> 手動で変更したページ(<?php echo intval( $modified_n ); ?>件)も、最新の内容で上書きする</label></p>
            <?php endif; ?>
            <?php submit_button( $outdated_n > 0 ? "固定ページを最新の内容に更新({$outdated_n}件)" : '固定ページを最新の内容に更新', 'secondary', 'submit', false ); ?>
        </form>

        <h2>仮データ(動作確認用)</h2>
        <?php $sample_msg = get_transient( 'mush_office_sample_data_msg' ); ?>
        <?php if ( $sample_msg ) : delete_transient( 'mush_office_sample_data_msg' ); ?>
        <div class="notice notice-success inline"><p><?php echo esc_html( $sample_msg ); ?></p></div>
        <?php endif; ?>
        <?php $sample_ids = get_option( 'mush_office_sample_data_ids', array() ); ?>
        <p class="description">
            架空の従業員8人と、全員分の日報・予定(今日の前後1ヶ月)、勤怠(2ヶ月前の1日〜昨日)、給与明細(直近2ヶ月)、
            経理の顧客・商品・見積書・請求書・原価計算書(各10件。同じ案件でつながっています)を入れます。<br>
            もう一度押すと、前回の仮データを消してから入れ直します。「仮データを削除」で、仮データだけを消します(本物のデータは消えません)。
        </p>
        <p>現在: <?php echo $sample_ids ? '投入済み' : '未投入'; ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
            <input type="hidden" name="action" value="mush_office_sample_data">
            <input type="hidden" name="mush_sample_action" value="insert">
            <?php wp_nonce_field( 'mush_office_sample_data' ); ?>
            <?php submit_button( $sample_ids ? '仮データを入れ直す' : '仮データを投入', 'secondary', 'submit', false ); ?>
        </form>
        <?php if ( $sample_ids ) : ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;" onsubmit="return confirm('仮データを削除します。よろしいですか？');">
            <input type="hidden" name="action" value="mush_office_sample_data">
            <input type="hidden" name="mush_sample_action" value="delete">
            <?php wp_nonce_field( 'mush_office_sample_data' ); ?>
            <?php submit_button( '仮データを削除', 'delete', 'submit', false ); ?>
        </form>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * ============================================================
 * REST APIエンドポイントの登録
 * 名前空間: mush-office/v1
 * ============================================================
 * セキュリティについて:
 * 元のFirestoreルールが「read, write: if true」(社内利用限定・URLを知っていれば
 * 誰でも読み書き可)という前提だったため、当初このREST APIも同様に
 * permission_callback を常に許可(__return_true)にしていた。
 * 現在は、日報の保存・削除(mush_office_upsert_task_record /
 * mush_office_delete_task_record)にログイントークンの検証を追加し、
 * 本人以外の日報を保存できないようにしている(詳細は各関数のコメント参照)。
 * それ以外のエンドポイント(一覧取得など)は、朝礼・管理者集計ページが
 * 全員分のデータを読む必要があるため、引き続き認証なしのままにしている。
 */
add_action( 'rest_api_init', function () {

    // ログイン(本人確認)。成功するとログイントークンを返す。
    register_rest_route( 'mush-office/v1', '/auth/login', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_auth_login',
        'permission_callback' => '__return_true',
    ) );

    // 管理者ページ(従業員登録・管理者チャット・管理者集計)用ログイン
    register_rest_route( 'mush-office/v1', '/admin/login', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_admin_login',
        'permission_callback' => '__return_true',
    ) );
    register_rest_route( 'mush-office/v1', '/admin/password-status', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_admin_password_status',
        'permission_callback' => '__return_true',
    ) );
    register_rest_route( 'mush-office/v1', '/admin/set-password', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_set_admin_password',
        'permission_callback' => 'mush_office_admin_or_setup_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/admin/reset-password', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_reset_admin_password',
        'permission_callback' => 'mush_office_admin_or_setup_permission',
    ) );

    // 従業員一覧の取得
    register_rest_route( 'mush-office/v1', '/employees', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'mush_office_get_employees',
            'permission_callback' => '__return_true',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'mush_office_create_employee',
            'permission_callback' => 'mush_office_admin_permission',
        ),
    ) );

    // 部署の一覧(誰でも取得可)・追加・削除(管理者)
    register_rest_route( 'mush-office/v1', '/departments', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'mush_office_get_departments',
            'permission_callback' => '__return_true',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'mush_office_add_department',
            'permission_callback' => 'mush_office_admin_permission',
        ),
    ) );
    register_rest_route( 'mush-office/v1', '/departments/delete', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_delete_department',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 従業員の削除(idはWordPress DB上の内部ID)
    register_rest_route( 'mush-office/v1', '/employees/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'callback'            => 'mush_office_delete_employee',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 従業員登録ページの一覧から、氏名・部署・生年月日・住所・連絡先・入社日・備考をまとめて編集する。
    register_rest_route( 'mush-office/v1', '/employees/(?P<id>\d+)/profile', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_update_employee_profile',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 従業員のログインパスワードを管理者が設定・変更する。
    register_rest_route( 'mush-office/v1', '/employees/(?P<id>\d+)/password', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_set_employee_password',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 従業員リストから、個別に管理者権限を付与・剥奪する(ID発行はせず、
    // 既存のログインパスワードのまま管理者ページに入れるようになる)。
    register_rest_route( 'mush-office/v1', '/employees/(?P<id>\d+)/admin', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_set_employee_admin',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 従業員の給与形態(時給・日給・月給)と単価を設定・変更する。
    register_rest_route( 'mush-office/v1', '/employees/(?P<id>\d+)/wage', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_set_employee_wage',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 従業員の入社日を設定・変更する(有給の法定付与日数の自動計算に使用)。
    register_rest_route( 'mush-office/v1', '/employees/(?P<id>\d+)/hire-date', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_set_employee_hire_date',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 従業員の毎月の手当(住宅・役職・通勤・資格・家族・その他1〜3)を設定・変更する。
    register_rest_route( 'mush-office/v1', '/employees/(?P<id>\d+)/allowances', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_set_employee_allowances',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 従業員の固定控除額(雇用保険・住民税・生命保険・財形貯蓄・従業員持株会)と扶養人数を設定・変更する。
    // 給与明細書を新規作成する際の初期値として使う(明細書ごとに個別に上書き可能)。
    register_rest_route( 'mush-office/v1', '/employees/(?P<id>\d+)/deductions', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_set_employee_deductions',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 給与計算ページ用: 年度ごとの社会保険・厚生年金保険料額表(Excelアップロード)
    register_rest_route( 'mush-office/v1', '/insurance-tables', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'mush_office_get_insurance_tables',
            'permission_callback' => 'mush_office_admin_permission',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'mush_office_upload_insurance_table',
            'permission_callback' => 'mush_office_admin_permission',
        ),
    ) );
    register_rest_route( 'mush-office/v1', '/insurance-tables/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'callback'            => 'mush_office_delete_insurance_table',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 給与計算ページ用: 年度ごとの源泉所得税額表(Excelアップロード)
    register_rest_route( 'mush-office/v1', '/tax-tables', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'mush_office_get_tax_tables',
            'permission_callback' => 'mush_office_admin_permission',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'mush_office_upload_tax_table',
            'permission_callback' => 'mush_office_admin_permission',
        ),
    ) );
    register_rest_route( 'mush-office/v1', '/tax-tables/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'callback'            => 'mush_office_delete_tax_table',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 給与計算ページ用: 年度ごとの賞与に対する源泉徴収税額の算出率の表(Excelアップロード)
    register_rest_route( 'mush-office/v1', '/bonus-tax-tables', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'mush_office_get_bonus_tax_tables',
            'permission_callback' => 'mush_office_admin_permission',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'mush_office_upload_bonus_tax_table',
            'permission_callback' => 'mush_office_admin_permission',
        ),
    ) );
    register_rest_route( 'mush-office/v1', '/bonus-tax-tables/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'callback'            => 'mush_office_delete_bonus_tax_table',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 給与計算ページ用: 年度ごとの源泉所得税額表(日額表・Excelアップロード)
    register_rest_route( 'mush-office/v1', '/daily-tax-tables', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'mush_office_get_daily_tax_tables',
            'permission_callback' => 'mush_office_admin_permission',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'mush_office_upload_daily_tax_table',
            'permission_callback' => 'mush_office_admin_permission',
        ),
    ) );
    register_rest_route( 'mush-office/v1', '/daily-tax-tables/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'callback'            => 'mush_office_delete_daily_tax_table',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 自社情報ページ用: 労働契約書などの書類アップロード
    register_rest_route( 'mush-office/v1', '/company-documents', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'mush_office_get_company_documents',
            'permission_callback' => 'mush_office_admin_permission',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'mush_office_upload_company_document',
            'permission_callback' => 'mush_office_admin_permission',
        ),
    ) );
    register_rest_route( 'mush-office/v1', '/company-documents/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'callback'            => 'mush_office_delete_company_document',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 給与明細書用: 有休付与の記録
    register_rest_route( 'mush-office/v1', '/leave-grants', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'mush_office_get_leave_grants',
            'permission_callback' => 'mush_office_admin_permission',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'mush_office_create_leave_grant',
            'permission_callback' => 'mush_office_admin_permission',
        ),
    ) );
    register_rest_route( 'mush-office/v1', '/leave-grants/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'callback'            => 'mush_office_delete_leave_grant',
        'permission_callback' => 'mush_office_admin_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/leave-balance/(?P<staffId>\d+)', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_leave_balance',
        'permission_callback' => 'mush_office_admin_permission',
    ) );
    // 年5日取得義務のチェック(従業員1人分・全従業員分)
    register_rest_route( 'mush-office/v1', '/leave-five-day-check/(?P<staffId>\d+)', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_five_day_check',
        'permission_callback' => 'mush_office_admin_permission',
    ) );
    // 有給取得一覧ページ用: 全従業員の付与・取得・残日数をまとめて返す
    register_rest_route( 'mush-office/v1', '/leave-summary', array(
        'methods'             => 'GET',
        'callback'            => 'mush_yokose_get_leave_summary',
        'permission_callback' => 'mush_office_admin_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/leave-five-day-warnings', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_five_day_warnings',
        'permission_callback' => 'mush_office_admin_permission',
    ) );
    // 従業員本人が自分の有休残日数を確認する(ログインしていれば誰でも、自分の分だけ取得できる)
    register_rest_route( 'mush-office/v1', '/my-leave-balance', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_my_leave_balance',
        'permission_callback' => 'mush_office_login_permission',
    ) );

    // 給与明細書用: 月ごとの勤怠区分(通常・欠勤・有休・特休・休日出勤)と出退勤時刻
    register_rest_route( 'mush-office/v1', '/attendance-month', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'mush_office_get_attendance_month',
            'permission_callback' => 'mush_office_admin_permission',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'mush_office_save_attendance_month',
            'permission_callback' => 'mush_office_admin_permission',
        ),
    ) );

    // 年末調整(従業員×年で1件)
    register_rest_route( 'mush-office/v1', '/year-end', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_list_year_end',
        'permission_callback' => 'mush_office_admin_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/year-end/(?P<staffId>\\d+)/(?P<year>\\d+)', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'mush_office_get_year_end',
            'permission_callback' => 'mush_office_admin_permission',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'mush_office_save_year_end',
            'permission_callback' => 'mush_office_admin_permission',
        ),
        array(
            'methods'             => 'DELETE',
            'callback'            => 'mush_office_delete_year_end',
            'permission_callback' => 'mush_office_admin_permission',
        ),
    ) );

    // 年末調整の申告: 従業員本人が入力・提出し、管理者が取り込む。
    register_rest_route( 'mush-office/v1', '/my-year-end/(?P<year>\\d+)', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'mush_office_get_my_year_end_decl',
            'permission_callback' => 'mush_office_login_permission',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'mush_office_save_my_year_end_decl',
            'permission_callback' => 'mush_office_login_permission',
        ),
    ) );
    register_rest_route( 'mush-office/v1', '/year-end-decl', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_list_year_end_decl',
        'permission_callback' => 'mush_office_admin_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/year-end-decl/(?P<staffId>\\d+)/(?P<year>\\d+)', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_year_end_decl',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 給与明細書(従業員×年月で1件)
    register_rest_route( 'mush-office/v1', '/payslips', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_payslips',
        'permission_callback' => 'mush_office_admin_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/payslips/(?P<staffId>\d+)/(?P<year>\d+)/(?P<month>\d+)', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'mush_office_get_payslip',
            'permission_callback' => 'mush_office_admin_permission',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'mush_office_save_payslip',
            'permission_callback' => 'mush_office_admin_permission',
        ),
    ) );
    register_rest_route( 'mush-office/v1', '/payslips/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'callback'            => 'mush_office_delete_payslip',
        'permission_callback' => 'mush_office_admin_permission',
    ) );
    // 従業員への公開(保存しただけの明細は下書きで、従業員の「給与照会」には出ない)
    register_rest_route( 'mush-office/v1', '/payslips/(?P<staffId>\d+)/(?P<year>\d+)/(?P<month>\d+)/publish', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_publish_payslip',
        'permission_callback' => 'mush_office_admin_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/payslips/publish-month', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_publish_payslip_month',
        'permission_callback' => 'mush_office_admin_permission',
    ) );
    // 給与明細書の保存履歴(上書き・削除される前の内容)
    register_rest_route( 'mush-office/v1', '/payslips/(?P<staffId>\d+)/(?P<year>\d+)/(?P<month>\d+)/history', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_payslip_history',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 従業員本人が自分の過去の給与明細書を確認する(ログインしていれば誰でも、自分の分だけを取得できる)。
    register_rest_route( 'mush-office/v1', '/my-payslips', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_my_payslips',
        'permission_callback' => 'mush_office_login_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/my-payslips/(?P<year>\d+)/(?P<month>\d+)', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_my_payslip',
        'permission_callback' => 'mush_office_login_permission',
    ) );

    // 日報(予定・実績)の一覧取得
    register_rest_route( 'mush-office/v1', '/task-records', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_task_records',
        'permission_callback' => 'mush_office_login_permission',
    ) );

    // 日報の保存(新規作成 or 同じ日・スタッフ・顧客名/案件名があれば更新)
    register_rest_route( 'mush-office/v1', '/task-records/upsert', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_upsert_task_record',
        'permission_callback' => '__return_true',
    ) );

    // 日報の削除
    register_rest_route( 'mush-office/v1', '/task-records/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'callback'            => 'mush_office_delete_task_record',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 日報の複製(管理者ページ用。upsertとは違い、必ず新しい行として複製する)
    register_rest_route( 'mush-office/v1', '/task-records/(?P<id>\d+)/duplicate', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_duplicate_task_record',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 日報の部分更新(管理者集計ページ専用)。通常のupsertは本人確認トークンが
    // 必須で管理者からは使えないため、送られた項目だけを更新する専用APIを用意する。
    register_rest_route( 'mush-office/v1', '/task-records/(?P<id>\d+)/admin-update', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_admin_update_task_record',
        'permission_callback' => 'mush_office_login_permission',
    ) );

    // 日報の案件に添付する画像(1案件につき最大10枚)
    register_rest_route( 'mush-office/v1', '/task-records/(?P<id>\d+)/images', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_upload_task_image',
        'permission_callback' => 'mush_office_login_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/task-images/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'callback'            => 'mush_office_delete_task_image',
        'permission_callback' => 'mush_office_login_permission',
    ) );

    // 進捗(予定・実績)の一覧取得
    register_rest_route( 'mush-office/v1', '/schedule-tasks', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_schedule_tasks',
        'permission_callback' => 'mush_office_login_permission',
    ) );

    // 進捗の保存(idを指定すれば更新、なければ新規作成)
    register_rest_route( 'mush-office/v1', '/schedule-tasks/upsert', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_upsert_schedule_task',
        'permission_callback' => 'mush_office_login_permission',
    ) );

    // 進捗の削除
    register_rest_route( 'mush-office/v1', '/schedule-tasks/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'callback'            => 'mush_office_delete_schedule_task',
        'permission_callback' => 'mush_office_login_permission',
    ) );

    // 進捗の複製(管理者ページ用)
    register_rest_route( 'mush-office/v1', '/schedule-tasks/(?P<id>\d+)/duplicate', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_duplicate_schedule_task',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // タイムカードの一覧取得
    register_rest_route( 'mush-office/v1', '/time-cards', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_time_cards',
        'permission_callback' => '__return_true',
    ) );

    // タイムカードの保存(出勤・退勤打刻。同じ日・スタッフの行があれば更新)
    register_rest_route( 'mush-office/v1', '/time-cards/upsert', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_upsert_time_card',
        'permission_callback' => '__return_true',
    ) );

    // 管理者⇔従業員チャット: 一覧取得・送信
    register_rest_route( 'mush-office/v1', '/chat-messages', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'mush_office_get_chat_messages',
            'permission_callback' => 'mush_office_chat_read_permission',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'mush_office_send_chat_message',
            'permission_callback' => '__return_true',
        ),
    ) );

    // 管理者画面用: 従業員からの未読メッセージがある従業員IDと件数
    register_rest_route( 'mush-office/v1', '/chat-messages/admin-unread', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_admin_unread_chat_counts',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 従業員同士の個別チャット(DM)。すべて従業員本人のログインが必要。
    register_rest_route( 'mush-office/v1', '/dm/threads', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_dm_threads',
        'permission_callback' => 'mush_office_login_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/dm/messages', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'mush_office_get_dm_messages',
            'permission_callback' => 'mush_office_login_permission',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'mush_office_send_dm_message',
            'permission_callback' => 'mush_office_login_permission',
        ),
    ) );

    // チャットのメッセージを既読にする(従業員本人、または管理者のログインが必要)
    register_rest_route( 'mush-office/v1', '/chat-messages/mark-read', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_mark_chat_read',
        'permission_callback' => '__return_true',
    ) );

    // 管理者からの未読メッセージが1件以上ある従業員IDの一覧(朝礼ページの目印表示用)
    register_rest_route( 'mush-office/v1', '/chat-messages/unread-staff-ids', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_unread_chat_staff_ids',
        'permission_callback' => 'mush_office_login_permission',
    ) );

    // 全員へのお知らせ(管理者チャットの「全員に送信」→朝礼画面の上部に2日間表示)
    register_rest_route( 'mush-office/v1', '/announcements', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_create_announcement',
        'permission_callback' => 'mush_office_admin_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/announcements/active', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_active_announcement',
        'permission_callback' => 'mush_office_login_permission',
    ) );

    // 経理: 顧客マスタ
    register_rest_route( 'mush-office/v1', '/clients', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_clients',
        'permission_callback' => 'mush_office_login_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/clients/upsert', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_upsert_client',
        'permission_callback' => 'mush_office_login_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/clients/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'callback'            => 'mush_office_delete_client',
        'permission_callback' => 'mush_office_login_permission',
    ) );

    // 経理: 商品マスタ
    register_rest_route( 'mush-office/v1', '/products', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_products',
        'permission_callback' => 'mush_office_login_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/products/upsert', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_upsert_product',
        'permission_callback' => 'mush_office_login_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/products/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'callback'            => 'mush_office_delete_product',
        'permission_callback' => 'mush_office_login_permission',
    ) );

    // 経理: 見積もり
    register_rest_route( 'mush-office/v1', '/quotes', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_quotes',
        'permission_callback' => 'mush_office_login_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/quotes/upsert', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_upsert_quote',
        'permission_callback' => 'mush_office_login_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/quotes/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'callback'            => 'mush_office_delete_quote',
        'permission_callback' => 'mush_office_login_permission',
    ) );

    // 経理: 請求書
    register_rest_route( 'mush-office/v1', '/invoices', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_invoices',
        'permission_callback' => 'mush_office_login_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/invoices/upsert', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_upsert_invoice',
        'permission_callback' => 'mush_office_login_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/invoices/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'callback'            => 'mush_office_delete_invoice',
        'permission_callback' => 'mush_office_login_permission',
    ) );

    // 経理: 原価計算書
    register_rest_route( 'mush-office/v1', '/costs', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_costs',
        'permission_callback' => 'mush_office_login_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/costs/upsert', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_upsert_cost',
        'permission_callback' => 'mush_office_login_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/costs/(?P<id>\d+)', array(
        'methods'             => 'DELETE',
        'callback'            => 'mush_office_delete_cost',
        'permission_callback' => 'mush_office_login_permission',
    ) );

    // 全データバックアップ: ダウンロード・復元・最終ダウンロード日時の記録
    register_rest_route( 'mush-office/v1', '/backup/export', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_backup_export',
        'permission_callback' => 'mush_office_admin_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/backup/restore', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_backup_restore',
        'permission_callback' => 'mush_office_admin_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/backup/status', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_backup_status',
        'permission_callback' => 'mush_office_admin_permission',
    ) );
    register_rest_route( 'mush-office/v1', '/backup/mark-downloaded', array(
        'methods'             => 'POST',
        'callback'            => 'mush_office_mark_backup_downloaded',
        'permission_callback' => 'mush_office_admin_permission',
    ) );
    // 全データ削除(取り返しがつかない最重要操作。フロント側で確認・自動バックアップ・
    // 確認文字列入力を必須にしている)
    register_rest_route( 'mush-office/v1', '/backup/delete-all', array(
        'methods'             => 'DELETE',
        'callback'            => 'mush_office_backup_delete_all',
        'permission_callback' => 'mush_office_admin_permission',
    ) );

    // 操作ログ(ログイン・見積もり/請求書の作成・変更・印刷・削除・重要操作の記録)
    register_rest_route( 'mush-office/v1', '/activity-log', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'mush_office_get_activity_log',
            'permission_callback' => 'mush_office_admin_permission',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'mush_office_log_activity_endpoint',
            'permission_callback' => 'mush_office_login_permission',
        ),
    ) );

    // 自社情報(見積もり・請求書・原価計算書に発行元として印字する)
    register_rest_route( 'mush-office/v1', '/company-info', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'mush_office_get_company_info',
            'permission_callback' => 'mush_office_login_permission',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'mush_office_save_company_info',
            'permission_callback' => 'mush_office_login_permission',
        ),
    ) );
    register_rest_route( 'mush-office/v1', '/company-name', array(
        'methods'             => 'GET',
        'callback'            => 'mush_office_get_company_name',
        'permission_callback' => '__return_true',
    ) );
    register_rest_route( 'mush-office/v1', '/company-seal', array(
        array(
            'methods'             => 'POST',
            'callback'            => 'mush_office_upload_company_seal',
            'permission_callback' => 'mush_office_admin_permission',
        ),
        array(
            'methods'             => 'DELETE',
            'callback'            => 'mush_office_delete_company_seal',
            'permission_callback' => 'mush_office_admin_permission',
        ),
    ) );
} );

// 管理者が従業員登録ページの一覧から、氏名・部署・生年月日・住所・連絡先・入社日・備考をまとめて編集する。
// 保存時に、社員番号が所属部署の番台と合っていなければ、その部署の番台の次の番号に付け替える(合っていればそのまま)。
// 過去の給与明細に保存してある社員番号は、当時のまま残る。
function mush_office_update_employee_profile( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_employees';
    $id = intval( $request['id'] );

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }

    $name = isset( $p['name'] ) ? sanitize_text_field( $p['name'] ) : '';
    $department = isset( $p['department'] ) ? sanitize_text_field( $p['department'] ) : '';
    if ( '' === $name ) {
        return new WP_REST_Response( array( 'error' => '氏名を入力してください。' ), 400 );
    }
    if ( '' === $department ) {
        return new WP_REST_Response( array( 'error' => '部署を選択してください。' ), 400 );
    }

    $data = array(
        'name'       => $name,
        'department' => $department,
        'birthdate'  => isset( $p['birthdate'] ) ? sanitize_text_field( $p['birthdate'] ) : '',
        'zip'        => isset( $p['zip'] ) ? sanitize_text_field( $p['zip'] ) : '',
        'address'    => isset( $p['address'] ) ? sanitize_text_field( $p['address'] ) : '',
        'phone'      => isset( $p['phone'] ) ? sanitize_text_field( $p['phone'] ) : '',
        'mobile'     => isset( $p['mobile'] ) ? sanitize_text_field( $p['mobile'] ) : '',
        'memo'       => isset( $p['memo'] ) ? sanitize_textarea_field( $p['memo'] ) : '',
        'hire_date'  => isset( $p['hireDate'] ) ? sanitize_text_field( $p['hireDate'] ) : '',
        'bank_name'            => isset( $p['bankName'] ) ? sanitize_text_field( $p['bankName'] ) : '',
        'bank_branch'          => isset( $p['bankBranch'] ) ? sanitize_text_field( $p['bankBranch'] ) : '',
        'bank_account_type'    => isset( $p['bankAccountType'] ) ? sanitize_text_field( $p['bankAccountType'] ) : '',
        'bank_account_number'  => isset( $p['bankAccountNumber'] ) ? sanitize_text_field( $p['bankAccountNumber'] ) : '',
        'bank_account_holder'  => isset( $p['bankAccountHolder'] ) ? sanitize_text_field( $p['bankAccountHolder'] ) : '',
    );
    if ( isset( $p['showOnlyWhenScheduled'] ) ) {
        $data['show_only_when_scheduled'] = ! empty( $p['showOnlyWhenScheduled'] ) ? 1 : 0;
    }

    $current = $wpdb->get_row( $wpdb->prepare( "SELECT department, employee_number FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    $number_note = '';
    // 社員番号を手で指定したとき: 1〜999の、ほかの人と重ならない番号なら、部署の番台に関係なく受け付ける。
    $wanted_raw = isset( $p['employeeNumber'] ) ? trim( strval( $p['employeeNumber'] ) ) : '';
    $wanted_no  = '' !== $wanted_raw ? intval( $wanted_raw ) : 0;
    if ( '' !== $wanted_raw && ( ! preg_match( '/^\d+$/', $wanted_raw ) || $wanted_no < 1 || $wanted_no > 999 ) ) {
        return new WP_REST_Response( array( 'error' => '社員番号は1〜999の数字で入力してください。' ), 400 );
    }
    if ( $current && $wanted_no > 0 && $wanted_no !== intval( $current['employee_number'] ) ) {
        $taken = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE employee_number = %d AND id <> %d", $wanted_no, $id ) );
        if ( $taken ) {
            return new WP_REST_Response( array( 'error' => sprintf( '社員番号 %03d は、ほかの人が使っています。', $wanted_no ) ), 409 );
        }
        $data['employee_number'] = $wanted_no;
        $number_note = sprintf( '社員番号 %03d → %03d', intval( $current['employee_number'] ), $wanted_no );
    } elseif ( $current && $current['department'] !== $department ) { // 番号を指定せずに部署だけ変えたときは、新しい部署の番台の次の番号に付け替える
        $old_no = intval( $current['employee_number'] );
        $band   = mush_office_department_band( $department );
        if ( $old_no < $band || $old_no >= $band + 100 ) {
            $new_no = mush_office_next_employee_number( $department );
            $data['employee_number'] = $new_no;
            $number_note = sprintf( '社員番号 %03d → %03d', $old_no, $new_no );
        }
    }

    $updated = $wpdb->update( $table_name, $data, array( 'id' => $id ) );
    if ( false === $updated ) {
        return new WP_REST_Response( array( 'error' => '更新に失敗しました。' ), 500 );
    }
    mush_office_log_activity( wp_get_current_user()->display_name, '従業員情報を変更', $name, $number_note );
    return new WP_REST_Response( array( 'saved' => true ), 200 );
}

function mush_office_delete_employee( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_employees';
    $id = intval( $request['id'] );

    $existing = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );

    $deleted = $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );
    if ( false === $deleted ) {
        return new WP_REST_Response( array( 'error' => '削除に失敗しました。' ), 500 );
    }
    mush_office_log_activity( wp_get_current_user()->display_name, '従業員削除', $existing ? $existing['name'] : '' );
    return new WP_REST_Response( array( 'deleted' => true ), 200 );
}

// 管理者が従業員のログインパスワードを設定・変更する(ハッシュ化して保存する)。
function mush_office_set_employee_password( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_employees';
    $id = intval( $request['id'] );

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }
    $password = isset( $p['password'] ) ? sanitize_text_field( $p['password'] ) : '';
    if ( '' === $password ) {
        return new WP_REST_Response( array( 'error' => 'パスワードを入力してください。' ), 400 );
    }
    if ( mush_office_login_password_too_short( $password ) ) {
        return new WP_REST_Response( array( 'error' => 'ログインパスワードは6文字以上にしてください。' ), 400 );
    }

    $updated = $wpdb->update(
        $table_name,
        array( 'login_password' => mush_office_hash_login_password( $password ) ),
        array( 'id' => $id ),
        array( '%s' ),
        array( '%d' )
    );
    if ( false === $updated ) {
        return new WP_REST_Response( array( 'error' => '更新に失敗しました。' ), 500 );
    }
    $emp = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    mush_office_log_activity( wp_get_current_user()->display_name, 'パスワード変更', $emp ? $emp['name'] : '' );
    return new WP_REST_Response( array( 'saved' => true ), 200 );
}

// 管理者が、従業員リストから個別に管理者権限を付与・剥奪する(ID発行はせず、
// 既存の従業員アカウント・パスワードのまま「管理者かどうか」だけを切り替える)。
function mush_office_set_employee_admin( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_employees';
    $id = intval( $request['id'] );

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }
    $is_admin = ! empty( $p['isAdmin'] ) ? 1 : 0;

    $updated = $wpdb->update(
        $table_name,
        array( 'is_admin' => $is_admin ),
        array( 'id' => $id ),
        array( '%d' ),
        array( '%d' )
    );
    if ( false === $updated ) {
        return new WP_REST_Response( array( 'error' => '更新に失敗しました。' ), 500 );
    }
    $emp = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    mush_office_log_activity( wp_get_current_user()->display_name, $is_admin ? '管理者権限を付与' : '管理者権限を剥奪', $emp ? $emp['name'] : '' );
    return new WP_REST_Response( array( 'saved' => true, 'isAdmin' => (bool) $is_admin ), 200 );
}

// 管理者が従業員の給与形態(時給・日給・月給)と単価を設定・変更する。
function mush_office_set_employee_wage( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_employees';
    $id = intval( $request['id'] );

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }
    $wage_type = isset( $p['wageType'] ) ? sanitize_text_field( $p['wageType'] ) : '';
    $wage_rate = isset( $p['wageRate'] ) ? intval( $p['wageRate'] ) : 0;
    if ( '' !== $wage_type && ! in_array( $wage_type, array( '時給', '日給', '月給' ), true ) ) {
        return new WP_REST_Response( array( 'error' => '給与形態は時給・日給・月給のいずれかを選択してください。' ), 400 );
    }

    $updated = $wpdb->update(
        $table_name,
        array( 'wage_type' => $wage_type, 'wage_rate' => $wage_rate ),
        array( 'id' => $id ),
        array( '%s', '%d' ),
        array( '%d' )
    );
    if ( false === $updated ) {
        return new WP_REST_Response( array( 'error' => '更新に失敗しました。' ), 500 );
    }
    $emp = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    mush_office_log_activity( wp_get_current_user()->display_name, '給与形態を変更', $emp ? $emp['name'] : '' );
    return new WP_REST_Response( array( 'saved' => true, 'wageType' => $wage_type, 'wageRate' => $wage_rate ), 200 );
}

// 管理者が従業員の入社日を設定・変更する。
function mush_office_set_employee_hire_date( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_employees';
    $id = intval( $request['id'] );

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }
    $hire_date = isset( $p['hireDate'] ) ? sanitize_text_field( $p['hireDate'] ) : '';

    $updated = $wpdb->update( $table_name, array( 'hire_date' => $hire_date ), array( 'id' => $id ) );
    if ( false === $updated ) {
        return new WP_REST_Response( array( 'error' => '更新に失敗しました。' ), 500 );
    }
    $emp = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    mush_office_log_activity( wp_get_current_user()->display_name, '入社日を変更', $emp ? $emp['name'] : '' );
    return new WP_REST_Response( array( 'saved' => true, 'hireDate' => $hire_date ), 200 );
}

// 管理者が従業員の毎月の手当(住宅・役職・通勤・資格・家族・その他1〜3)を設定・変更する。
function mush_office_set_employee_allowances( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_employees';
    $id = intval( $request['id'] );

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }

    $data = array(
        'housing_allowance'       => isset( $p['housingAllowance'] ) ? intval( $p['housingAllowance'] ) : 0,
        'position_allowance'      => isset( $p['positionAllowance'] ) ? intval( $p['positionAllowance'] ) : 0,
        'commute_allowance'       => isset( $p['commuteAllowance'] ) ? intval( $p['commuteAllowance'] ) : 0,
        'qualification_allowance' => isset( $p['qualificationAllowance'] ) ? intval( $p['qualificationAllowance'] ) : 0,
        'family_allowance'        => isset( $p['familyAllowance'] ) ? intval( $p['familyAllowance'] ) : 0,
        'other1_label'            => isset( $p['other1Label'] ) ? sanitize_text_field( $p['other1Label'] ) : '',
        'other1_amount'           => isset( $p['other1Amount'] ) ? intval( $p['other1Amount'] ) : 0,
        'other2_label'            => isset( $p['other2Label'] ) ? sanitize_text_field( $p['other2Label'] ) : '',
        'other2_amount'           => isset( $p['other2Amount'] ) ? intval( $p['other2Amount'] ) : 0,
        'other3_label'            => isset( $p['other3Label'] ) ? sanitize_text_field( $p['other3Label'] ) : '',
        'other3_amount'           => isset( $p['other3Amount'] ) ? intval( $p['other3Amount'] ) : 0,
        'housing_taxable'         => ! empty( $p['housingTaxable'] ) ? 1 : 0,
        'position_taxable'        => ! empty( $p['positionTaxable'] ) ? 1 : 0,
        'commute_taxable'         => ! empty( $p['commuteTaxable'] ) ? 1 : 0,
        'qualification_taxable'   => ! empty( $p['qualificationTaxable'] ) ? 1 : 0,
        'family_taxable'          => ! empty( $p['familyTaxable'] ) ? 1 : 0,
        'other1_taxable'          => ! empty( $p['other1Taxable'] ) ? 1 : 0,
        'other2_taxable'          => ! empty( $p['other2Taxable'] ) ? 1 : 0,
        'other3_taxable'          => ! empty( $p['other3Taxable'] ) ? 1 : 0,
    );

    $updated = $wpdb->update( $table_name, $data, array( 'id' => $id ) );
    if ( false === $updated ) {
        return new WP_REST_Response( array( 'error' => '更新に失敗しました。' ), 500 );
    }
    $emp = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    mush_office_log_activity( wp_get_current_user()->display_name, '手当を変更', $emp ? $emp['name'] : '' );
    return new WP_REST_Response( array( 'saved' => true ), 200 );
}

// 管理者が従業員の固定控除額(雇用保険・住民税・生命保険・財形貯蓄・従業員持株会)と扶養人数を設定・変更する。
// 給与明細書の新規作成時の初期値として使う。
function mush_office_set_employee_deductions( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_employees';
    $id = intval( $request['id'] );

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }

    $data = array(
        'employment_insurance_fixed' => isset( $p['employmentInsurance'] ) ? intval( $p['employmentInsurance'] ) : 0,
        'resident_tax_fixed'         => isset( $p['residentTax'] ) ? intval( $p['residentTax'] ) : 0,
        'life_insurance_fixed'       => isset( $p['lifeInsurance'] ) ? intval( $p['lifeInsurance'] ) : 0,
        'wage_savings_fixed'         => isset( $p['wageSavings'] ) ? intval( $p['wageSavings'] ) : 0,
        'stock_ownership_fixed'      => isset( $p['stockOwnership'] ) ? intval( $p['stockOwnership'] ) : 0,
        'dependents_count'           => isset( $p['dependents'] ) ? max( 0, intval( $p['dependents'] ) ) : 0,
    );

    $updated = $wpdb->update( $table_name, $data, array( 'id' => $id ) );
    if ( false === $updated ) {
        return new WP_REST_Response( array( 'error' => '更新に失敗しました。' ), 500 );
    }
    $emp = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    mush_office_log_activity( wp_get_current_user()->display_name, '固定控除額を変更', $emp ? $emp['name'] : '' );
    return new WP_REST_Response( array( 'saved' => true ), 200 );
}

// 「その他」部門の従業員は、どのページ(日報・朝礼・集計・給与など)にも出さない。従業員登録ページで管理するときだけ一覧に出す。
define( 'MUSH_OFFICE_HIDDEN_DEPARTMENT', 'その他' );
function mush_office_hidden_staff_ids() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_employees';
    return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE department = %s", MUSH_OFFICE_HIDDEN_DEPARTMENT ) ) );
}

function mush_office_get_employees( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_employees';

    $rows = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY employee_number ASC", ARRAY_A );
    if ( null === $rows ) {
        $rows = array();
    }
    $include_hidden = ! empty( $request->get_param( 'includeHidden' ) ) && mush_office_is_admin_request( $request );
    if ( ! $include_hidden ) {
        $rows = array_values( array_filter( $rows, function ( $r ) {
            return MUSH_OFFICE_HIDDEN_DEPARTMENT !== $r['department'];
        } ) );
    }
    // 住所・生年月日などを含む全項目は管理者トークンがある場合だけ返す(パスワードは
    // 管理者にも返さず、設定済みかどうかだけ)。それ以外(日報・朝礼などの一般ページ)には、
    // 選択肢の表示に必要な項目だけを返す。
    if ( mush_office_is_admin_request( $request ) ) {
        $rows = array_map( 'mush_office_public_employee_row', $rows );
    } else {
        $rows = array_map( function ( $r ) {
            return array(
                'id'              => $r['id'],
                'employee_number' => $r['employee_number'],
                'name'            => $r['name'],
                'department'      => $r['department'],
                'position'        => isset( $r['position'] ) ? $r['position'] : '',
                'show_only_when_scheduled' => ! empty( $r['show_only_when_scheduled'] ),
            );
        }, $rows );
    }
    return new WP_REST_Response( $rows, 200 );
}

function mush_office_create_employee( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_employees';

    $params = $request->get_json_params();
    if ( empty( $params ) ) {
        $params = $request->get_params();
    }

    $name       = isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';
    $department = isset( $params['department'] ) ? sanitize_text_field( $params['department'] ) : '';
    $birthdate  = isset( $params['birthdate'] ) ? sanitize_text_field( $params['birthdate'] ) : '';
    $zip        = isset( $params['zip'] ) ? sanitize_text_field( $params['zip'] ) : '';
    $address    = isset( $params['address'] ) ? sanitize_text_field( $params['address'] ) : '';
    $phone      = isset( $params['phone'] ) ? sanitize_text_field( $params['phone'] ) : '';
    $mobile     = isset( $params['mobile'] ) ? sanitize_text_field( $params['mobile'] ) : '';
    $memo       = isset( $params['memo'] ) ? sanitize_textarea_field( $params['memo'] ) : '';
    $bank_name             = isset( $params['bankName'] ) ? sanitize_text_field( $params['bankName'] ) : '';
    $bank_branch           = isset( $params['bankBranch'] ) ? sanitize_text_field( $params['bankBranch'] ) : '';
    $bank_account_type     = isset( $params['bankAccountType'] ) ? sanitize_text_field( $params['bankAccountType'] ) : '';
    $bank_account_number   = isset( $params['bankAccountNumber'] ) ? sanitize_text_field( $params['bankAccountNumber'] ) : '';
    $bank_account_holder   = isset( $params['bankAccountHolder'] ) ? sanitize_text_field( $params['bankAccountHolder'] ) : '';
    $password   = isset( $params['password'] ) ? sanitize_text_field( $params['password'] ) : '';
    if ( '' !== $password && mush_office_login_password_too_short( $password ) ) {
        return new WP_REST_Response( array( 'error' => 'ログインパスワードは6文字以上にしてください。' ), 400 );
    }
    $wage_type  = isset( $params['wageType'] ) ? sanitize_text_field( $params['wageType'] ) : '';
    $wage_rate  = isset( $params['wageRate'] ) ? intval( $params['wageRate'] ) : 0;
    $housing_allowance       = isset( $params['housingAllowance'] ) ? intval( $params['housingAllowance'] ) : 0;
    $position_allowance      = isset( $params['positionAllowance'] ) ? intval( $params['positionAllowance'] ) : 0;
    $commute_allowance       = isset( $params['commuteAllowance'] ) ? intval( $params['commuteAllowance'] ) : 0;
    $qualification_allowance = isset( $params['qualificationAllowance'] ) ? intval( $params['qualificationAllowance'] ) : 0;
    $family_allowance        = isset( $params['familyAllowance'] ) ? intval( $params['familyAllowance'] ) : 0;
    $other1_label  = isset( $params['other1Label'] ) ? sanitize_text_field( $params['other1Label'] ) : '';
    $other1_amount = isset( $params['other1Amount'] ) ? intval( $params['other1Amount'] ) : 0;
    $other2_label  = isset( $params['other2Label'] ) ? sanitize_text_field( $params['other2Label'] ) : '';
    $other2_amount = isset( $params['other2Amount'] ) ? intval( $params['other2Amount'] ) : 0;
    $other3_label  = isset( $params['other3Label'] ) ? sanitize_text_field( $params['other3Label'] ) : '';
    $other3_amount = isset( $params['other3Amount'] ) ? intval( $params['other3Amount'] ) : 0;
    $housing_taxable       = isset( $params['housingTaxable'] ) ? ( ! empty( $params['housingTaxable'] ) ? 1 : 0 ) : 1;
    $position_taxable      = isset( $params['positionTaxable'] ) ? ( ! empty( $params['positionTaxable'] ) ? 1 : 0 ) : 1;
    $commute_taxable       = isset( $params['commuteTaxable'] ) ? ( ! empty( $params['commuteTaxable'] ) ? 1 : 0 ) : 0;
    $qualification_taxable = isset( $params['qualificationTaxable'] ) ? ( ! empty( $params['qualificationTaxable'] ) ? 1 : 0 ) : 1;
    $family_taxable        = isset( $params['familyTaxable'] ) ? ( ! empty( $params['familyTaxable'] ) ? 1 : 0 ) : 1;
    $other1_taxable        = isset( $params['other1Taxable'] ) ? ( ! empty( $params['other1Taxable'] ) ? 1 : 0 ) : 1;
    $other2_taxable        = isset( $params['other2Taxable'] ) ? ( ! empty( $params['other2Taxable'] ) ? 1 : 0 ) : 1;
    $other3_taxable        = isset( $params['other3Taxable'] ) ? ( ! empty( $params['other3Taxable'] ) ? 1 : 0 ) : 1;
    $hire_date  = isset( $params['hireDate'] ) ? sanitize_text_field( $params['hireDate'] ) : '';

    if ( '' === $name ) {
        return new WP_REST_Response( array( 'error' => '氏名を入力してください。' ), 400 );
    }
    if ( '' === $department ) {
        return new WP_REST_Response( array( 'error' => '部署を選択してください。' ), 400 );
    }
    if ( '' !== $wage_type && ! in_array( $wage_type, array( '時給', '日給', '月給' ), true ) ) {
        return new WP_REST_Response( array( 'error' => '給与形態は時給・日給・月給のいずれかを選択してください。' ), 400 );
    }

    $employee_number = mush_office_next_employee_number( $department );

    $inserted = $wpdb->insert(
        $table_name,
        array(
            'employee_number' => $employee_number,
            'name'            => $name,
            'department'      => $department,
            'birthdate'       => $birthdate,
            'zip'             => $zip,
            'address'         => $address,
            'phone'           => $phone,
            'mobile'          => $mobile,
            'memo'            => $memo,
            'bank_name'            => $bank_name,
            'bank_branch'          => $bank_branch,
            'bank_account_type'    => $bank_account_type,
            'bank_account_number'  => $bank_account_number,
            'bank_account_holder'  => $bank_account_holder,
            'login_password'  => '' !== $password ? mush_office_hash_login_password( $password ) : '',
            'wage_type'       => $wage_type,
            'wage_rate'       => $wage_rate,
            'housing_allowance'       => $housing_allowance,
            'position_allowance'      => $position_allowance,
            'commute_allowance'       => $commute_allowance,
            'qualification_allowance' => $qualification_allowance,
            'family_allowance'        => $family_allowance,
            'other1_label'    => $other1_label,
            'other1_amount'   => $other1_amount,
            'other2_label'    => $other2_label,
            'other2_amount'   => $other2_amount,
            'other3_label'    => $other3_label,
            'other3_amount'   => $other3_amount,
            'housing_taxable'       => $housing_taxable,
            'position_taxable'      => $position_taxable,
            'commute_taxable'       => $commute_taxable,
            'qualification_taxable' => $qualification_taxable,
            'family_taxable'        => $family_taxable,
            'other1_taxable'  => $other1_taxable,
            'other2_taxable'  => $other2_taxable,
            'other3_taxable'  => $other3_taxable,
            'hire_date'       => $hire_date,
            'is_admin'        => ! empty( $params['isAdmin'] ) ? 1 : 0, // 新規登録時に管理者権限を付与(このAPIは管理者だけが呼べる)
            'created_at'      => current_time( 'mysql' ),
        )
    );

    if ( false === $inserted ) {
        return new WP_REST_Response( array( 'error' => '登録に失敗しました。' ), 500 );
    }

    $new_row = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $wpdb->insert_id ),
        ARRAY_A
    );

    // 操作した人は、ログイントークンの持ち主(従業員)または管理者。ファイルからの取り込みは内容欄に記す。
    $actor = mush_office_request_actor( $request );
    mush_office_log_activity( $actor['name'], '従業員登録', $name, ! empty( $params['importSource'] ) ? 'ファイル取り込み' : ( ! empty( $params['isAdmin'] ) ? '管理者権限つきで登録' : '' ) );
    return new WP_REST_Response( mush_office_public_employee_row( $new_row ), 201 );
}

/**
 * ============================================================
 * 日報(予定・実績 / taskRecords)関連
 * ============================================================
 */

// 一覧取得。クエリパラメータ:
//   date     … その日付だけに絞り込み(例: 2026-09-07)
//   staffId  … そのスタッフだけに絞り込み
//   dateFrom / dateTo … 日付の範囲指定(管理者集計ページなどで使用予定)
function mush_office_get_task_records( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_task_records';

    // 完了から30日が過ぎた画像の削除。cronが動かない環境でも消えるよう、一覧取得時にも1時間に1回まで実行する。
    if ( ! get_transient( 'mush_office_image_purge_lock' ) ) {
        set_transient( 'mush_office_image_purge_lock', 1, HOUR_IN_SECONDS );
        mush_office_purge_expired_task_images();
    }

    $where  = array();
    $params = array();

    $date = $request->get_param( 'date' );
    if ( ! empty( $date ) ) {
        $where[]  = 'record_date = %s';
        $params[] = sanitize_text_field( $date );
    }
    $date_from = $request->get_param( 'dateFrom' );
    if ( ! empty( $date_from ) ) {
        $where[]  = 'record_date >= %s';
        $params[] = sanitize_text_field( $date_from );
    }
    $date_to = $request->get_param( 'dateTo' );
    if ( ! empty( $date_to ) ) {
        $where[]  = 'record_date <= %s';
        $params[] = sanitize_text_field( $date_to );
    }
    $staff_id = $request->get_param( 'staffId' );
    if ( ! empty( $staff_id ) ) {
        $where[]  = 'staff_id = %d';
        $params[] = intval( $staff_id );
    }
    $hidden_ids = mush_office_hidden_staff_ids(); // 「その他」部門の人の分は、どのページにも出さない
    if ( ! empty( $hidden_ids ) ) {
        $where[] = 'staff_id NOT IN (' . implode( ',', $hidden_ids ) . ')';
    }

    $sql = "SELECT * FROM {$table_name}";
    if ( ! empty( $where ) ) {
        $sql .= ' WHERE ' . implode( ' AND ', $where );
    }
    $sql .= ' ORDER BY record_date DESC, start_time ASC';

    if ( ! empty( $params ) ) {
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    } else {
        $rows = $wpdb->get_results( $sql, ARRAY_A );
    }
    if ( null === $rows ) {
        $rows = array();
    }

    // フロント側(JS)の既存の呼び方(キャメルケース)に合わせて整形して返す。
    // 添付画像は1クエリでまとめて取得し、案件ごとに振り分ける(N+1を避けるため)。
    $ids = array_map( function ( $r ) { return intval( $r['id'] ); }, $rows );
    $images_by_task = mush_office_fetch_task_images_map( $ids );
    $rows = array_map( function ( $row ) use ( $images_by_task ) {
        $tid = intval( $row['id'] );
        return mush_office_format_task_record( $row, isset( $images_by_task[ $tid ] ) ? $images_by_task[ $tid ] : array() );
    }, $rows );

    return new WP_REST_Response( $rows, 200 );
}

// 指定した案件ID群に紐づく画像を1クエリでまとめて取得し、案件ID => 画像配列 のマップを返す。
function mush_office_fetch_task_images_map( $task_ids ) {
    global $wpdb;
    $task_ids = array_values( array_filter( array_map( 'intval', (array) $task_ids ) ) );
    if ( empty( $task_ids ) ) {
        return array();
    }
    $table_name = $wpdb->prefix . 'mush_task_images';
    $placeholders = implode( ', ', array_fill( 0, count( $task_ids ), '%d' ) );
    $rows = $wpdb->get_results(
        $wpdb->prepare( "SELECT id, task_record_id, file_url, expires_at FROM {$table_name} WHERE task_record_id IN ({$placeholders}) ORDER BY id ASC", $task_ids ),
        ARRAY_A
    );
    $map = array();
    foreach ( (array) $rows as $r ) {
        $tid = intval( $r['task_record_id'] );
        if ( ! isset( $map[ $tid ] ) ) {
            $map[ $tid ] = array();
        }
        $map[ $tid ][] = array(
            'id'        => intval( $r['id'] ),
            'url'       => $r['file_url'],
            'expiresAt' => ! empty( $r['expires_at'] ) ? substr( $r['expires_at'], 0, 10 ) : '', // 完了から30日後の削除予定日
        );
    }
    return $map;
}

// 完了した案件の画像は、完了から30日間は残し、その後に自動で削除する。
// 案件のステータスが「完了」になったとき、画像に削除予定日時(expires_at = 今から30日後)を付ける。
// すでに削除予定日がある画像は、完了のまま再保存しても延ばさない。
// 「完了」から別のステータスに戻したときは、削除予定を取り消す(画像はそのまま残す)。
define( 'MUSH_OFFICE_TASK_IMAGE_KEEP_DAYS', 30 );
function mush_office_update_task_image_expiry( $task_record_id, $status ) {
    global $wpdb;
    $image_table = $wpdb->prefix . 'mush_task_images';
    if ( '完了' !== $status ) {
        $wpdb->query( $wpdb->prepare( "UPDATE {$image_table} SET expires_at = NULL WHERE task_record_id = %d AND expires_at IS NOT NULL", $task_record_id ) );
        return;
    }
    $expires = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + MUSH_OFFICE_TASK_IMAGE_KEEP_DAYS * DAY_IN_SECONDS );
    $wpdb->query( $wpdb->prepare( "UPDATE {$image_table} SET expires_at = %s WHERE task_record_id = %d AND expires_at IS NULL", $expires, $task_record_id ) );
}

// 削除予定日時を過ぎた画像(ファイル・DB行とも)を削除し、その案件には images_cleared を立てる
// (「完了から30日が過ぎたため削除された」ことを画面に表示するため)。1日1回のcronと、案件一覧の取得時(1時間に1回まで)に実行する。
function mush_office_purge_expired_task_images() {
    global $wpdb;
    $image_table = $wpdb->prefix . 'mush_task_images';
    $task_table  = $wpdb->prefix . 'mush_task_records';
    $now  = current_time( 'mysql' );
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, task_record_id, file_url FROM {$image_table} WHERE expires_at IS NOT NULL AND expires_at <= %s LIMIT 500", $now ), ARRAY_A );
    if ( empty( $rows ) ) {
        return 0;
    }
    $task_ids = array();
    foreach ( $rows as $r ) {
        mush_office_delete_local_upload_by_url( $r['file_url'] );
        $wpdb->delete( $image_table, array( 'id' => intval( $r['id'] ) ), array( '%d' ) );
        $task_ids[ intval( $r['task_record_id'] ) ] = true;
    }
    foreach ( array_keys( $task_ids ) as $tid ) {
        $left = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$image_table} WHERE task_record_id = %d", $tid ) ) );
        if ( 0 === $left ) {
            $wpdb->update( $task_table, array( 'images_cleared' => 1 ), array( 'id' => $tid ), array( '%d' ), array( '%d' ) );
        }
    }
    return count( $rows );
}
add_action( 'mush_office_purge_images_event', 'mush_office_purge_expired_task_images' );
add_action( 'init', function () {
    // 更新で有効化処理を通らなかった環境でも、毎日の削除処理が登録されるようにする。
    if ( ! wp_next_scheduled( 'mush_office_purge_images_event' ) ) {
        wp_schedule_event( time() + 600, 'daily', 'mush_office_purge_images_event' );
    }
}, 32 );

function mush_office_format_task_record( $row, $images = array() ) {
    return array(
        'id'         => intval( $row['id'] ),
        'date'       => $row['record_date'],
        'staffId'    => strval( $row['staff_id'] ),
        'staffName'  => $row['staff_name'],
        'dept'       => $row['department'],
        'category'   => $row['category'],
        'client'     => $row['client'],
        'project'    => $row['project'],
        'start'      => $row['start_time'],
        'end'        => $row['end_time'],
        'status'     => $row['status'],
        'isOutside'  => (bool) intval( $row['is_outside'] ),
        'area'       => $row['area'],
        'prefecture' => $row['prefecture'],
        'travelType' => $row['travel_type'],
        'note'       => $row['note'],
        'bulkMode'   => (bool) intval( $row['bulk_mode'] ),
        'bulkCount'  => $row['bulk_count'],
        'progress'   => intval( $row['progress'] ),
        'currentDay' => intval( $row['current_day'] ),
        'totalDays'  => intval( $row['total_days'] ),
        'dueDate'    => $row['due_date'],
        'holidayWork' => ! empty( $row['holiday_work'] ),
        'earlyWork'  => ! empty( $row['early_work'] ),
        'nightWork'  => ! empty( $row['night_work'] ),
        'images'         => $images,
        'imagesCleared'  => isset( $row['images_cleared'] ) ? (bool) intval( $row['images_cleared'] ) : false,
        'createdAt'  => $row['created_at'],
        'updatedAt'  => $row['updated_at'],
    );
}

/**
 * ============================================================
 * 予定(日報の案件)の操作ログ
 * ============================================================
 * 「誰が」「誰の予定を」「登録・更新・削除などしたか」を操作ログ(mush_activity_log)に残す。
 * 操作者はリクエストのログイントークンの持ち主(従業員)。共通パスワードの管理者ログインは
 * 「管理者(共通パスワード)」と記録する。予定の持ち主(担当者)と操作者が違う場合(代理入力・管理者の操作)は
 * 詳細の先頭に「代理操作」と付ける。
 */
function mush_office_request_actor( WP_REST_Request $request ) {
    global $wpdb;
    $sid = mush_office_get_bearer_staff_id( $request );
    if ( null === $sid ) {
        return array( 'id' => null, 'name' => '(不明)' );
    }
    if ( 0 === $sid ) {
        return array( 'id' => 0, 'name' => '管理者(共通パスワード)' );
    }
    $name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}mush_employees WHERE id = %d", $sid ) );
    return array( 'id' => intval( $sid ), 'name' => $name ? $name : '(不明)' );
}

// ログに載せる予定の見出し(例: 鈴木美咲さんの予定: 2026-10-06 見積作成 青葉商事/名刺)。
function mush_office_task_log_label( $row ) {
    if ( ! empty( $row['bulk_mode'] ) && '0' !== strval( $row['bulk_mode'] ) ) {
        $what = trim( $row['record_date'] . ' ' . $row['category'] . '(まとめ入力 ' . $row['bulk_count'] . '件)' );
    } else {
        $what = trim( $row['record_date'] . ' ' . $row['category'] . ' ' . implode( '/', array_filter( array( $row['client'], $row['project'] ) ) ) );
    }
    return mb_substr( $row['staff_name'] . 'さんの予定: ' . $what, 0, 190 );
}

// 変更前後の行を比べ、「状態: 予定→継続」のような変更点の一覧を作る(変更がなければ空文字)。
function mush_office_task_changes( $old, $new ) {
    $fields = array(
        'record_date' => '日付', 'category' => '業務区分', 'client' => '顧客名', 'project' => '案件名', 'start_time' => '開始', 'end_time' => '終了',
        'status' => '状態', 'due_date' => '終了予定日', 'is_outside' => '外出', 'area' => '区域', 'prefecture' => '都道府県',
        'travel_type' => '移動', 'bulk_count' => 'まとめ件数',
    );
    $out = array();
    foreach ( $fields as $key => $label ) {
        $a = isset( $old[ $key ] ) ? strval( $old[ $key ] ) : '';
        $b = isset( $new[ $key ] ) ? strval( $new[ $key ] ) : '';
        if ( $a !== $b ) {
            $out[] = 'is_outside' === $key ? ( $label . ': ' . ( '1' === $a ? 'あり' : 'なし' ) . '→' . ( '1' === $b ? 'あり' : 'なし' ) )
                : ( $label . ': ' . ( '' === $a ? '(空)' : $a ) . '→' . ( '' === $b ? '(空)' : $b ) );
        }
    }
    $na = isset( $old['note'] ) ? strval( $old['note'] ) : '';
    $nb = isset( $new['note'] ) ? strval( $new['note'] ) : '';
    if ( $na !== $nb ) {
        $out[] = '備考を変更';
    }
    return implode(' / ', $out);
}

function mush_office_log_task_activity( WP_REST_Request $request, $action, $row, $detail = '' ) {
    if ( ! $row ) {
        return;
    }
    $actor = mush_office_request_actor( $request );
    if ( null === $actor['id'] || intval( $row['staff_id'] ) !== $actor['id'] ) {
        $detail = trim( '代理操作 ' . $detail );
    }
    mush_office_log_activity( $actor['name'], $action, mush_office_task_log_label( $row ), mb_substr( $detail, 0, 250 ) );
}

// 保存(新規作成 or 更新)。
// bulkModeがfalseの場合、同じ日・同じスタッフ・同じ顧客名/案件名の行がすでにあれば
// 新規追加ではなく更新することで、1案件=1行にまとめる(重複防止)。
// これをJS側の2段階処理(検索→保存)ではなく、1回のDBクエリ(INSERT ... ON DUPLICATE KEY UPDATE)で
// 行うことで、連続保存時のタイミングのズレによる重複作成を防いでいる。
function mush_office_upsert_task_record( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_task_records';

    // ログイン(日報の個人ログイン、または管理者ログイン)が必要。日報の「全員カレンダー」から
    // 同僚の予定を代理登録できるようにするため、トークンの持ち主(auth_staff_id)以外の
    // 名義での保存もあえて許可している(社内限定システムとして、なりすまし防止よりも
    // 代理登録の使い勝手を優先した仕様変更)。
    $auth_staff_id = mush_office_get_bearer_staff_id( $request );
    if ( null === $auth_staff_id ) {
        return new WP_REST_Response( array( 'error' => 'ログインが必要です。' ), 401 );
    }

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }

    $date       = isset( $p['date'] ) ? sanitize_text_field( $p['date'] ) : '';
    $staff_id   = isset( $p['staffId'] ) ? intval( $p['staffId'] ) : 0;
    $staff_name = isset( $p['staffName'] ) ? sanitize_text_field( $p['staffName'] ) : '';
    $dept       = isset( $p['dept'] ) ? sanitize_text_field( $p['dept'] ) : '';
    $category   = isset( $p['category'] ) ? sanitize_text_field( $p['category'] ) : '';
    $bulk_mode  = ! empty( $p['bulkMode'] ) ? 1 : 0;
    // まとめ入力(bulkMode)のときはNULLにして、ユニーク制約に引っかからないようにする
    $client  = $bulk_mode ? null : ( isset( $p['client'] ) ? sanitize_text_field( trim( $p['client'] ) ) : '' );
    $project = $bulk_mode ? null : ( isset( $p['project'] ) ? sanitize_text_field( trim( $p['project'] ) ) : '' );

    if ( '' === $date || 0 === $staff_id ) {
        return new WP_REST_Response( array( 'error' => '日付とスタッフを指定してください。' ), 400 );
    }

    $data = array(
        'record_date' => $date,
        'staff_id'    => $staff_id,
        'staff_name'  => $staff_name,
        'department'  => $dept,
        'category'    => $category,
        'client'      => $client,
        'project'     => $project,
        'start_time'  => isset( $p['start'] ) ? sanitize_text_field( $p['start'] ) : '',
        'end_time'    => isset( $p['end'] ) ? sanitize_text_field( $p['end'] ) : '',
        'status'      => isset( $p['status'] ) ? sanitize_text_field( $p['status'] ) : '予定',
        'is_outside'  => ! empty( $p['isOutside'] ) ? 1 : 0,
        'area'        => isset( $p['area'] ) ? sanitize_text_field( $p['area'] ) : '',
        'prefecture'  => isset( $p['prefecture'] ) ? sanitize_text_field( $p['prefecture'] ) : '',
        'travel_type' => isset( $p['travelType'] ) ? sanitize_text_field( $p['travelType'] ) : '',
        'note'        => isset( $p['note'] ) ? sanitize_textarea_field( $p['note'] ) : '',
        'bulk_mode'   => $bulk_mode,
        'bulk_count'  => isset( $p['bulkCount'] ) ? sanitize_text_field( $p['bulkCount'] ) : '',
        'progress'    => isset( $p['progress'] ) ? intval( $p['progress'] ) : 0,
        'current_day' => isset( $p['currentDay'] ) ? intval( $p['currentDay'] ) : 0,
        'total_days'  => isset( $p['totalDays'] ) ? intval( $p['totalDays'] ) : 0,
        'due_date'    => isset( $p['dueDate'] ) ? sanitize_text_field( $p['dueDate'] ) : '',
        'holiday_work' => ! empty( $p['holidayWork'] ) ? 1 : 0,
        'early_work'  => ! empty( $p['earlyWork'] ) ? 1 : 0,
        'night_work'  => ! empty( $p['nightWork'] ) ? 1 : 0,
    );

    $now = current_time( 'mysql' );

    // client/projectがNULL(まとめ入力)の場合はON DUPLICATE KEYが働かないため、
    // 常にINSERTでよい(MySQLはUNIQUE KEY内のNULL同士を別物として扱うため)。
    $columns       = array_keys( $data );
    $columns[]     = 'created_at';
    $columns[]     = 'updated_at';
    $placeholders  = array();
    $values        = array();
    foreach ( $data as $key => $value ) {
        if ( null === $value ) {
            $placeholders[] = 'NULL';
        } elseif ( is_int( $value ) ) {
            $placeholders[] = '%d';
            $values[]       = $value;
        } else {
            $placeholders[] = '%s';
            $values[]       = $value;
        }
    }
    $placeholders[] = '%s'; // created_at
    $values[]       = $now;
    $placeholders[] = '%s'; // updated_at
    $values[]       = $now;

    $update_clauses = array();
    foreach ( $data as $key => $value ) {
        if ( in_array( $key, array( 'record_date', 'staff_id', 'client', 'project' ), true ) ) {
            continue; // キーとなる項目は更新しない
        }
        $update_clauses[] = "{$key} = VALUES({$key})";
    }
    $update_clauses[] = 'updated_at = VALUES(updated_at)';

    $sql = "INSERT INTO {$table_name} (" . implode( ', ', $columns ) . ")
            VALUES (" . implode( ', ', $placeholders ) . ")
            ON DUPLICATE KEY UPDATE " . implode( ', ', $update_clauses );

    // ログ用に、同じキー(日付・担当者・顧客名・案件名)の既存の行(=更新前の内容)を控えておく。
    $old_row = null;
    if ( ! $bulk_mode ) {
        $old_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE record_date = %s AND staff_id = %d AND client = %s AND project = %s",
                $date, $staff_id, $client, $project
            ),
            ARRAY_A
        );
    }

    $prepared = $wpdb->prepare( $sql, $values );
    $result   = $wpdb->query( $prepared );

    if ( false === $result ) {
        return new WP_REST_Response( array( 'error' => '保存に失敗しました。', 'detail' => $wpdb->last_error ), 500 );
    }

    // 保存(または更新)された行を取得して返す
    if ( $bulk_mode ) {
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $wpdb->insert_id ),
            ARRAY_A
        );
    } else {
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE record_date = %s AND staff_id = %d AND client = %s AND project = %s",
                $date, $staff_id, $client, $project
            ),
            ARRAY_A
        );
    }

    // 業務区分「休暇取得」の予定を、勤怠(日ごとの区分)の有休に反映する。
    if ( $old_row ) {
        mush_yokose_sync_leave_from_report( $old_row['staff_id'], $old_row['record_date'] );
    }
    if ( $row ) {
        mush_yokose_sync_leave_from_report( $row['staff_id'], $row['record_date'] );
    }

    // 操作ログ: 誰が誰の予定を登録/更新したか(変更点がない保存は記録しない)。
    if ( $row ) {
        if ( null === $old_row ) {
            mush_office_log_task_activity( $request, '予定を登録', $row, '状態: ' . $row['status'] );
        } else {
            $changes = mush_office_task_changes( $old_row, $row );
            if ( '' !== $changes ) {
                mush_office_log_task_activity( $request, '予定を更新', $row, $changes );
            }
        }
    }

    $images = array();
    if ( $row ) {
        mush_office_update_task_image_expiry( intval( $row['id'] ), $data['status'] );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", intval( $row['id'] ) ), ARRAY_A );
        $images_map = mush_office_fetch_task_images_map( array( intval( $row['id'] ) ) );
        $images = isset( $images_map[ intval( $row['id'] ) ] ) ? $images_map[ intval( $row['id'] ) ] : array();
    }

    return new WP_REST_Response( $row ? mush_office_format_task_record( $row, $images ) : array( 'saved' => true ), 200 );
}

// 個別スケジュールで業務区分「休暇取得」の予定がある日を、勤怠(日ごとの区分)の「有休」にする。
// 有休の消化日数・残日数は mush_attendance_days の day_type='有休' から数えるため、これで管理者ページに反映される。
// 予定を消した・区分を変えたときは、この仕組みで有休にした日(from_report=1)だけ元に戻す
// (管理者が勤怠ページで手入力した有休はそのまま残す)。
function mush_yokose_sync_leave_from_report( $staff_id, $date ) {
    global $wpdb;
    $staff_id = intval( $staff_id );
    $date = strval( $date );
    if ( ! $staff_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
        return;
    }
    $task_table = $wpdb->prefix . 'mush_task_records';
    $att_table  = $wpdb->prefix . 'mush_attendance_days';
    $has_leave = intval( $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$task_table} WHERE staff_id = %d AND record_date = %s AND category = %s AND status <> 'キャンセル'",
        $staff_id, $date, '休暇取得'
    ) ) ) > 0;
    $att = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, day_type, clock_in, clock_out, from_report FROM {$att_table} WHERE staff_id = %d AND record_date = %s",
        $staff_id, $date
    ), ARRAY_A );
    $now = current_time( 'mysql' );
    if ( $has_leave ) {
        if ( ! $att ) {
            $wpdb->insert( $att_table, array(
                'staff_id' => $staff_id, 'record_date' => $date, 'day_type' => '有休',
                'clock_in' => '', 'clock_out' => '', 'from_report' => 1, 'created_at' => $now, 'updated_at' => $now,
            ) );
        } elseif ( '有休' !== $att['day_type'] ) {
            $wpdb->update( $att_table, array( 'day_type' => '有休', 'from_report' => 1, 'updated_at' => $now ), array( 'id' => intval( $att['id'] ) ) );
        }
        return;
    }
    if ( $att && '有休' === $att['day_type'] && 1 === intval( $att['from_report'] ) ) {
        if ( '' === strval( $att['clock_in'] ) && '' === strval( $att['clock_out'] ) ) {
            // 未区分に戻す(タイムカードの打刻補完に任せる)。
            $wpdb->delete( $att_table, array( 'id' => intval( $att['id'] ) ) );
        } else {
            $wpdb->update( $att_table, array( 'day_type' => '通常', 'from_report' => 0, 'updated_at' => $now ), array( 'id' => intval( $att['id'] ) ) );
        }
    }
}

function mush_office_delete_task_record( WP_REST_Request $request ) {
    global $wpdb;

    // 日報のリセットと集計画面の案件削除に使う。管理者ログイン(permission_callback側で確認)が必須。
    $table_name = $wpdb->prefix . 'mush_task_records';
    $id = intval( $request['id'] );

    // 案件を削除する前に、添付画像(ファイル・DB行)も削除しておく(孤立ファイルを残さないため)。
    $image_table = $wpdb->prefix . 'mush_task_images';
    $row_before = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A ); // ログ用
    $image_rows = $wpdb->get_results( $wpdb->prepare( "SELECT file_url FROM {$image_table} WHERE task_record_id = %d", $id ), ARRAY_A );
    foreach ( (array) $image_rows as $r ) {
        mush_office_delete_local_upload_by_url( $r['file_url'] );
    }
    $wpdb->delete( $image_table, array( 'task_record_id' => $id ), array( '%d' ) );

    $deleted = $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );
    if ( false === $deleted ) {
        return new WP_REST_Response( array( 'error' => '削除に失敗しました。' ), 500 );
    }
    if ( $row_before ) {
        mush_yokose_sync_leave_from_report( $row_before['staff_id'], $row_before['record_date'] );
    }
    mush_office_log_task_activity( $request, '予定を削除', $row_before, $row_before ? '状態: ' . $row_before['status'] : '' );
    return new WP_REST_Response( array( 'deleted' => true ), 200 );
}

/**
 * ============================================================
 * 日報の案件に添付する画像(1案件につき最大10枚)
 * ============================================================
 */
function mush_office_upload_task_image( WP_REST_Request $request ) {
    global $wpdb;
    $task_record_id = intval( $request['id'] );
    $task_table  = $wpdb->prefix . 'mush_task_records';
    $image_table = $wpdb->prefix . 'mush_task_images';

    $task = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$task_table} WHERE id = %d", $task_record_id ), ARRAY_A );
    if ( ! $task ) {
        return new WP_REST_Response( array( 'error' => '案件が見つかりません。' ), 404 );
    }

    $current_count = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$image_table} WHERE task_record_id = %d", $task_record_id ) ) );
    if ( $current_count >= 10 ) {
        return new WP_REST_Response( array( 'error' => '画像は1案件につき10枚までです。' ), 400 );
    }

    $files = $request->get_file_params();
    if ( empty( $files['file'] ) || UPLOAD_ERR_OK !== $files['file']['error'] ) {
        return new WP_REST_Response( array( 'error' => '画像ファイルを選択してください。' ), 400 );
    }
    $allowed_types = array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp' );
    if ( ! in_array( $files['file']['type'], $allowed_types, true ) ) {
        return new WP_REST_Response( array( 'error' => 'png・jpg・gif・webp形式の画像を選択してください。' ), 400 );
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $upload = wp_handle_upload( $files['file'], array( 'test_form' => false ) );
    if ( isset( $upload['error'] ) ) {
        return new WP_REST_Response( array( 'error' => '画像の保存に失敗しました: ' . $upload['error'] ), 500 );
    }

    $wpdb->insert(
        $image_table,
        array(
            'task_record_id' => $task_record_id,
            'file_url'        => $upload['url'],
            'file_name'       => sanitize_file_name( $files['file']['name'] ),
            'uploaded_by'     => wp_get_current_user() ? wp_get_current_user()->display_name : '',
            'created_at'      => current_time( 'mysql' ),
        )
    );
    // すでに完了している案件に画像を足した場合も、完了から30日後ではなく、足した日から30日後に削除する。
    mush_office_update_task_image_expiry( $task_record_id, $task['status'] );

    $task_full = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$task_table} WHERE id = %d", $task_record_id ), ARRAY_A );
    mush_office_log_task_activity( $request, '予定に画像を追加', $task_full, sanitize_file_name( $files['file']['name'] ) );

    $images_map = mush_office_fetch_task_images_map( array( $task_record_id ) );
    $images = isset( $images_map[ $task_record_id ] ) ? $images_map[ $task_record_id ] : array();
    return new WP_REST_Response( array( 'images' => $images ), 201 );
}

function mush_office_delete_task_image( WP_REST_Request $request ) {
    global $wpdb;
    $image_table = $wpdb->prefix . 'mush_task_images';
    $id = intval( $request['id'] );

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$image_table} WHERE id = %d", $id ), ARRAY_A );
    if ( ! $row ) {
        return new WP_REST_Response( array( 'error' => '画像が見つかりません。' ), 404 );
    }
    $deleted = $wpdb->delete( $image_table, array( 'id' => $id ), array( '%d' ) );
    if ( false === $deleted ) {
        return new WP_REST_Response( array( 'error' => '削除に失敗しました。' ), 500 );
    }
    mush_office_delete_local_upload_by_url( $row['file_url'] );
    $task_full = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mush_task_records WHERE id = %d", intval( $row['task_record_id'] ) ), ARRAY_A );
    mush_office_log_task_activity( $request, '予定の画像を削除', $task_full, $row['file_name'] );
    return new WP_REST_Response( array( 'deleted' => true ), 200 );
}

/**
 * ============================================================
 * 進捗(予定・実績 / schedules)関連
 * ============================================================
 */

function mush_office_format_schedule_task( $row ) {
    return array(
        'id'        => intval( $row['id'] ),
        'date'      => $row['record_date'],
        'staffId'   => strval( $row['staff_id'] ),
        'staffName' => $row['staff_name'],
        'dept'      => $row['department'],
        'name'      => $row['name'],
        'start'     => $row['start_time'],
        'end'       => $row['end_time'],
        'dueDate'   => $row['due_date'],
        'memo'      => $row['memo'],
        'progress'  => intval( $row['progress'] ),
        'createdAt' => $row['created_at'],
        'updatedAt' => $row['updated_at'],
    );
}

// 一覧取得。クエリパラメータ: date / dateFrom / dateTo / staffId (task-recordsと同様)
function mush_office_get_schedule_tasks( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_schedule_tasks';

    $where  = array();
    $params = array();

    $date = $request->get_param( 'date' );
    if ( ! empty( $date ) ) {
        $where[]  = 'record_date = %s';
        $params[] = sanitize_text_field( $date );
    }
    $date_from = $request->get_param( 'dateFrom' );
    if ( ! empty( $date_from ) ) {
        $where[]  = 'record_date >= %s';
        $params[] = sanitize_text_field( $date_from );
    }
    $date_to = $request->get_param( 'dateTo' );
    if ( ! empty( $date_to ) ) {
        $where[]  = 'record_date <= %s';
        $params[] = sanitize_text_field( $date_to );
    }
    $staff_id = $request->get_param( 'staffId' );
    if ( ! empty( $staff_id ) ) {
        $where[]  = 'staff_id = %d';
        $params[] = intval( $staff_id );
    }
    $hidden_ids = mush_office_hidden_staff_ids(); // 「その他」部門の人の分は、どのページにも出さない
    if ( ! empty( $hidden_ids ) ) {
        $where[] = 'staff_id NOT IN (' . implode( ',', $hidden_ids ) . ')';
    }

    $sql = "SELECT * FROM {$table_name}";
    if ( ! empty( $where ) ) {
        $sql .= ' WHERE ' . implode( ' AND ', $where );
    }
    $sql .= ' ORDER BY record_date DESC, start_time ASC';

    if ( ! empty( $params ) ) {
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    } else {
        $rows = $wpdb->get_results( $sql, ARRAY_A );
    }
    if ( null === $rows ) {
        $rows = array();
    }

    $rows = array_map( 'mush_office_format_schedule_task', $rows );
    return new WP_REST_Response( $rows, 200 );
}

// 保存。 idがあれば更新(record_date・staff_idなどキー項目は変更しない。
// 繰越案件は元の日付のまま更新するため)。idがなければ新規作成。
function mush_office_upsert_schedule_task( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_schedule_tasks';

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }

    $id   = isset( $p['id'] ) ? intval( $p['id'] ) : 0;
    $name = isset( $p['name'] ) ? sanitize_text_field( $p['name'] ) : '';
    $start = isset( $p['start'] ) ? sanitize_text_field( $p['start'] ) : '';
    $end   = isset( $p['end'] ) ? sanitize_text_field( $p['end'] ) : '';
    $due_date = isset( $p['dueDate'] ) ? sanitize_text_field( $p['dueDate'] ) : '';
    $memo     = isset( $p['memo'] ) ? sanitize_textarea_field( $p['memo'] ) : '';
    $progress = isset( $p['progress'] ) ? intval( $p['progress'] ) : 0;
    $now = current_time( 'mysql' );

    if ( $id > 0 ) {
        // 更新(既存の案件。繰越案件も含め、日付・スタッフ情報は変更しない)
        $updated = $wpdb->update(
            $table_name,
            array(
                'name' => $name, 'start_time' => $start, 'end_time' => $end,
                'due_date' => $due_date, 'memo' => $memo, 'progress' => $progress,
                'updated_at' => $now,
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
            array( '%d' )
        );
        if ( false === $updated ) {
            return new WP_REST_Response( array( 'error' => '更新に失敗しました。' ), 500 );
        }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
        return new WP_REST_Response( $row ? mush_office_format_schedule_task( $row ) : array( 'saved' => true ), 200 );
    }

    // 新規作成
    $date       = isset( $p['date'] ) ? sanitize_text_field( $p['date'] ) : '';
    $staff_id   = isset( $p['staffId'] ) ? intval( $p['staffId'] ) : 0;
    $staff_name = isset( $p['staffName'] ) ? sanitize_text_field( $p['staffName'] ) : '';
    $dept       = isset( $p['dept'] ) ? sanitize_text_field( $p['dept'] ) : '';

    if ( '' === $date || 0 === $staff_id ) {
        return new WP_REST_Response( array( 'error' => '日付とスタッフを指定してください。' ), 400 );
    }

    $inserted = $wpdb->insert(
        $table_name,
        array(
            'record_date' => $date, 'staff_id' => $staff_id, 'staff_name' => $staff_name,
            'department' => $dept, 'name' => $name, 'start_time' => $start, 'end_time' => $end,
            'due_date' => $due_date, 'memo' => $memo, 'progress' => $progress,
            'created_at' => $now, 'updated_at' => $now,
        ),
        array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
    );
    if ( false === $inserted ) {
        return new WP_REST_Response( array( 'error' => '登録に失敗しました。' ), 500 );
    }
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $wpdb->insert_id ), ARRAY_A );
    return new WP_REST_Response( $row ? mush_office_format_schedule_task( $row ) : array( 'saved' => true ), 201 );
}

function mush_office_delete_schedule_task( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_schedule_tasks';
    $id = intval( $request['id'] );

    $deleted = $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );
    if ( false === $deleted ) {
        return new WP_REST_Response( array( 'error' => '削除に失敗しました。' ), 500 );
    }
    return new WP_REST_Response( array( 'deleted' => true ), 200 );
}

// 管理者ページ用の複製。upsertとは違い、内容が同じでも必ず新しい行として複製する。
// (client/projectの組み合わせに重複があっても保存できるよう、
//  もし将来ユニーク制約を追加する場合はこの関数側で衝突時のフォールバックを行う)
function mush_office_duplicate_task_record( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_task_records';
    $id = intval( $request['id'] );

    $orig = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    if ( ! $orig ) {
        return new WP_REST_Response( array( 'error' => '複製元が見つかりません。' ), 404 );
    }

    $now = current_time( 'mysql' );
    $data = $orig;
    unset( $data['id'] );
    $data['created_at'] = $now;
    $data['updated_at'] = $now;
    // 複製した行は画像を持たない新しい行なので、元の案件が完了済みで画像削除済みだったとしても
    // 「完了に伴い画像は削除されました」という表示は引き継がない。
    $data['images_cleared'] = 0;

    $inserted = $wpdb->insert( $table_name, $data );
    if ( false === $inserted ) {
        // ユニーク制約などに引っかかった場合は、名称に(複製)を付けて回避する
        if ( ! empty( $data['project'] ) ) {
            $data['project'] .= '(複製)';
        } elseif ( ! empty( $data['client'] ) ) {
            $data['client'] .= '(複製)';
        }
        $inserted = $wpdb->insert( $table_name, $data );
    }
    if ( false === $inserted ) {
        return new WP_REST_Response( array( 'error' => '複製に失敗しました。', 'detail' => $wpdb->last_error ), 500 );
    }

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $wpdb->insert_id ), ARRAY_A );
    if ( $row ) {
        mush_yokose_sync_leave_from_report( $row['staff_id'], $row['record_date'] );
    }
    mush_office_log_task_activity( $request, '予定を複製', $row, '複製元: ' . $orig['record_date'] . ' ' . $orig['category'] );
    return new WP_REST_Response( $row ? mush_office_format_task_record( $row ) : array( 'duplicated' => true ), 201 );
}

// 管理者集計ページから日報を部分的に修正するための専用API。
// 通常のupsert(mush_office_upsert_task_record)は本人確認トークンが必須で
// 未指定の項目を空欄で上書きしてしまう仕様のため、ここでは送られてきた
// 項目だけをピンポイントで更新する(トークン不要・他の項目は一切触らない)。
function mush_office_admin_update_task_record( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_task_records';
    $id = intval( $request['id'] );

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }

    $data    = array();
    $formats = array();
    if ( isset( $p['progress'] ) ) {
        $data['progress'] = intval( $p['progress'] );
        $formats[]        = '%d';
    }
    if ( isset( $p['status'] ) ) {
        $data['status'] = sanitize_text_field( $p['status'] );
        $formats[]      = '%s';
    }
    if ( isset( $p['currentDay'] ) ) {
        $data['current_day'] = intval( $p['currentDay'] );
        $formats[]           = '%d';
    }
    if ( isset( $p['totalDays'] ) ) {
        $data['total_days'] = intval( $p['totalDays'] );
        $formats[]          = '%d';
    }
    if ( isset( $p['note'] ) ) {
        $data['note'] = sanitize_textarea_field( $p['note'] );
        $formats[]    = '%s';
    }
    // 日報の一覧・詳細にある「外出」のチェックは、従業員本人のログインからも変更できる(区域・移動の種類も一緒に送られる)。
    if ( isset( $p['isOutside'] ) ) {
        $data['is_outside'] = ! empty( $p['isOutside'] ) ? 1 : 0;
        $formats[]          = '%d';
        foreach ( array( 'area' => 'area', 'travelType' => 'travel_type' ) as $key => $col ) {
            if ( isset( $p[ $key ] ) ) {
                $data[ $col ] = sanitize_text_field( strval( $p[ $key ] ) );
                $formats[]    = '%s';
            }
        }
    }
    foreach ( array( 'holidayWork' => 'holiday_work', 'earlyWork' => 'early_work', 'nightWork' => 'night_work' ) as $key => $col ) {
        if ( isset( $p[ $key ] ) ) {
            $data[ $col ] = ! empty( $p[ $key ] ) ? 1 : 0;
            $formats[]    = '%d';
        }
    }
    $old_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A ); // ログ用・まとめ入力の判定用
    if ( ! $old_row ) {
        return new WP_REST_Response( array( 'error' => '案件が見つかりません。' ), 404 );
    }
    // 管理者以外(従業員のログイン)が直せるのは自分の予定だけ。他の人の予定は本人と管理者だけが修正できる。
    if ( ! mush_office_is_admin_request( $request ) ) {
        $auth_staff_id = mush_office_get_bearer_staff_id( $request );
        if ( null === $auth_staff_id || intval( $old_row['staff_id'] ) !== intval( $auth_staff_id ) ) {
            return new WP_REST_Response( array( 'error' => 'この予定は本人と管理者だけが修正できます。' ), 403 );
        }
    }
    // 日付・終了予定日・時刻は、個別スケジュールの案件の詳細画面から本人のログインでも直せる。
    foreach ( array( 'date' => 'record_date', 'dueDate' => 'due_date', 'start' => 'start_time', 'end' => 'end_time' ) as $key => $col ) {
        if ( ! isset( $p[ $key ] ) ) {
            continue;
        }
        $v = sanitize_text_field( trim( strval( $p[ $key ] ) ) );
        if ( 'record_date' === $col && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
            return new WP_REST_Response( array( 'error' => '日付は 2026-10-05 の形式で入力してください。' ), 400 );
        }
        if ( 'due_date' === $col && '' !== $v && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
            return new WP_REST_Response( array( 'error' => '終了予定日は 2026-10-05 の形式で入力してください。' ), 400 );
        }
        if ( ( 'start_time' === $col || 'end_time' === $col ) && '' !== $v && ! preg_match( '/^\d{2}:\d{2}$/', $v ) ) {
            return new WP_REST_Response( array( 'error' => '時刻は 09:00 の形式で入力してください。' ), 400 );
        }
        $data[ $col ] = $v;
        $formats[]    = '%s';
    }
    // 管理者集計ページの「案件の編集」から送られる項目は、管理者ログインのときだけ受け付ける
    // (日報ページの状態チェックなど、従業員本人のログインからは従来の項目だけ変更できる)。
    if ( mush_office_is_admin_request( $request ) ) {
        $text_fields = array(
            'category' => 'category', 'prefecture' => 'prefecture', // 日付・終了予定日・時刻は上で受け付け済み
        );
        if ( ! isset( $p['isOutside'] ) ) {
            $text_fields['area']       = 'area';
            $text_fields['travelType'] = 'travel_type';
        }
        if ( empty( $old_row['bulk_mode'] ) || '0' === strval( $old_row['bulk_mode'] ) ) {
            $text_fields['client']  = 'client';
            $text_fields['project'] = 'project';
        }
        foreach ( $text_fields as $key => $col ) {
            if ( isset( $p[ $key ] ) ) {
                $data[ $col ] = sanitize_text_field( trim( strval( $p[ $key ] ) ) );
                $formats[]    = '%s';
            }
        }
        if ( isset( $data['category'] ) && '' === $data['category'] ) {
            return new WP_REST_Response( array( 'error' => '業務区分を入力してください。' ), 400 );
        }
    }
    if ( empty( $data ) ) {
        return new WP_REST_Response( array( 'error' => '更新する項目がありません。' ), 400 );
    }
    $data['updated_at'] = current_time( 'mysql' );
    $formats[]           = '%s';

    $wpdb->suppress_errors( true );
    $updated = $wpdb->update( $table_name, $data, array( 'id' => $id ), $formats, array( '%d' ) );
    $db_error = $wpdb->last_error;
    $wpdb->suppress_errors( false );
    if ( false === $updated ) {
        if ( false !== stripos( $db_error, 'Duplicate' ) ) {
            return new WP_REST_Response( array( 'error' => '同じ日・同じ担当者・同じ顧客名・案件名の案件がすでにあります。日付や顧客名・案件名を変えてください。' ), 409 );
        }
        return new WP_REST_Response( array( 'error' => '更新に失敗しました。' ), 500 );
    }
    if ( isset( $data['status'] ) ) {
        mush_office_update_task_image_expiry( $id, $data['status'] );
    }
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    mush_yokose_sync_leave_from_report( $old_row['staff_id'], $old_row['record_date'] );
    if ( $row ) {
        mush_yokose_sync_leave_from_report( $row['staff_id'], $row['record_date'] );
    }
    if ( $old_row && $row ) {
        $changes = mush_office_task_changes( $old_row, $row );
        if ( '' !== $changes ) {
            mush_office_log_task_activity( $request, '予定を更新', $row, $changes );
        }
    }
    $images_map = mush_office_fetch_task_images_map( array( $id ) );
    $images = isset( $images_map[ $id ] ) ? $images_map[ $id ] : array();
    return new WP_REST_Response( $row ? mush_office_format_task_record( $row, $images ) : array( 'saved' => true ), 200 );
}

function mush_office_duplicate_schedule_task( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_schedule_tasks';
    $id = intval( $request['id'] );

    $orig = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    if ( ! $orig ) {
        return new WP_REST_Response( array( 'error' => '複製元が見つかりません。' ), 404 );
    }

    $now = current_time( 'mysql' );
    $data = $orig;
    unset( $data['id'] );
    $data['created_at'] = $now;
    $data['updated_at'] = $now;

    $inserted = $wpdb->insert( $table_name, $data );
    if ( false === $inserted ) {
        return new WP_REST_Response( array( 'error' => '複製に失敗しました。', 'detail' => $wpdb->last_error ), 500 );
    }

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $wpdb->insert_id ), ARRAY_A );
    return new WP_REST_Response( $row ? mush_office_format_schedule_task( $row ) : array( 'duplicated' => true ), 201 );
}

/**
 * ============================================================
 * タイムカード関連(タイムカード画面自体は今後移行予定。
 * 管理者集計ページから先に参照できるよう一覧取得のみ用意)
 * ============================================================
 */
// $with_location: 打刻位置(GPS)を含めるか。位置は個人情報のため管理者リクエストにだけ返す。
function mush_office_format_time_card( $row, $with_location = false ) {
    $card = array(
        'id'        => intval( $row['id'] ),
        'date'      => $row['record_date'],
        'staffId'   => strval( $row['staff_id'] ),
        'staffName' => $row['staff_name'],
        'dept'      => $row['department'],
        'clockIn'   => $row['clock_in'],
        'clockOut'  => $row['clock_out'],
    );
    if ( $with_location ) {
        foreach ( array( 'in' => 'clockInLocation', 'out' => 'clockOutLocation' ) as $kind => $key ) {
            $lat = isset( $row[ "clock_{$kind}_lat" ] ) ? $row[ "clock_{$kind}_lat" ] : null;
            $lng = isset( $row[ "clock_{$kind}_lng" ] ) ? $row[ "clock_{$kind}_lng" ] : null;
            $acc = isset( $row[ "clock_{$kind}_accuracy" ] ) ? $row[ "clock_{$kind}_accuracy" ] : null;
            $card[ $key ] = ( null === $lat || null === $lng ) ? null : array(
                'lat'      => floatval( $lat ),
                'lng'      => floatval( $lng ),
                'accuracy' => null === $acc ? null : intval( $acc ),
            );
        }
    }
    return $card;
}

// 打刻時にブラウザから送られた位置情報({lat, lng, accuracy})を検証する。不正・未送信ならnull。
function mush_office_sanitize_punch_location( $loc ) {
    if ( ! is_array( $loc ) || ! isset( $loc['lat'], $loc['lng'] ) || ! is_numeric( $loc['lat'] ) || ! is_numeric( $loc['lng'] ) ) {
        return null;
    }
    $lat = floatval( $loc['lat'] );
    $lng = floatval( $loc['lng'] );
    if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
        return null;
    }
    $accuracy = isset( $loc['accuracy'] ) && is_numeric( $loc['accuracy'] ) ? max( 0, min( 1000000, intval( round( floatval( $loc['accuracy'] ) ) ) ) ) : null;
    return array( 'lat' => round( $lat, 7 ), 'lng' => round( $lng, 7 ), 'accuracy' => $accuracy );
}

function mush_office_get_time_cards( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_time_cards';

    $where  = array();
    $params = array();

    $date = $request->get_param( 'date' );
    if ( ! empty( $date ) ) {
        $where[]  = 'record_date = %s';
        $params[] = sanitize_text_field( $date );
    }
    $date_from = $request->get_param( 'dateFrom' );
    if ( ! empty( $date_from ) ) {
        $where[]  = 'record_date >= %s';
        $params[] = sanitize_text_field( $date_from );
    }
    $date_to = $request->get_param( 'dateTo' );
    if ( ! empty( $date_to ) ) {
        $where[]  = 'record_date <= %s';
        $params[] = sanitize_text_field( $date_to );
    }
    $staff_id = $request->get_param( 'staffId' );
    if ( ! empty( $staff_id ) ) {
        $where[]  = 'staff_id = %d';
        $params[] = intval( $staff_id );
    }
    $hidden_ids = mush_office_hidden_staff_ids(); // 「その他」部門の人の分は、どのページにも出さない
    if ( ! empty( $hidden_ids ) ) {
        $where[] = 'staff_id NOT IN (' . implode( ',', $hidden_ids ) . ')';
    }

    $sql = "SELECT * FROM {$table_name}";
    if ( ! empty( $where ) ) {
        $sql .= ' WHERE ' . implode( ' AND ', $where );
    }
    $sql .= ' ORDER BY record_date DESC';

    $limit = $request->get_param( 'limit' );
    if ( ! empty( $limit ) ) {
        $sql .= $wpdb->prepare( ' LIMIT %d', intval( $limit ) );
    }

    if ( ! empty( $params ) ) {
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    } else {
        $rows = $wpdb->get_results( $sql, ARRAY_A );
    }
    if ( null === $rows ) {
        $rows = array();
    }

    $with_location = mush_office_is_admin_request( $request );
    $rows = array_map( function ( $r ) use ( $with_location ) {
        return mush_office_format_time_card( $r, $with_location );
    }, $rows );
    return new WP_REST_Response( $rows, 200 );
}

// 出勤・退勤の打刻を保存する。同じ日・同じスタッフの行がすでにあれば更新し、
// 無ければ新規作成する(1日1スタッフにつき1行になるようDB側のユニーク制約で保証)。
function mush_office_upsert_time_card( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_time_cards';

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }

    $date       = isset( $p['date'] ) ? sanitize_text_field( $p['date'] ) : '';
    $staff_id   = isset( $p['staffId'] ) ? intval( $p['staffId'] ) : 0;
    $staff_name = isset( $p['staffName'] ) ? sanitize_text_field( $p['staffName'] ) : '';
    $dept       = isset( $p['dept'] ) ? sanitize_text_field( $p['dept'] ) : '';
    $clock_in   = isset( $p['clockIn'] ) ? sanitize_text_field( $p['clockIn'] ) : '';
    $clock_out  = isset( $p['clockOut'] ) ? sanitize_text_field( $p['clockOut'] ) : '';

    if ( '' === $date || 0 === $staff_id ) {
        return new WP_REST_Response( array( 'error' => '日付とスタッフを指定してください。' ), 400 );
    }

    $now = current_time( 'mysql' );
    $sql = "INSERT INTO {$table_name}
                (record_date, staff_id, staff_name, department, clock_in, clock_out, created_at, updated_at)
            VALUES (%s, %d, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                staff_name = VALUES(staff_name), department = VALUES(department),
                clock_in = VALUES(clock_in), clock_out = VALUES(clock_out), updated_at = VALUES(updated_at)";
    $result = $wpdb->query( $wpdb->prepare( $sql, $date, $staff_id, $staff_name, $dept, $clock_in, $clock_out, $now, $now ) );

    if ( false === $result ) {
        return new WP_REST_Response( array( 'error' => '打刻の保存に失敗しました。', 'detail' => $wpdb->last_error ), 500 );
    }

    // 打刻位置(GPS)。送られてきた側(出勤/退勤)だけ上書きし、送られていなければ既存の位置を残す
    // (管理者が時刻だけ修正した場合も、打刻時の位置は消えない)。
    $saved_location = array();
    foreach ( array( 'in' => 'clockInLocation', 'out' => 'clockOutLocation' ) as $kind => $key ) {
        $loc = isset( $p[ $key ] ) ? mush_office_sanitize_punch_location( $p[ $key ] ) : null;
        if ( null === $loc ) {
            continue;
        }
        $wpdb->update(
            $table_name,
            array( "clock_{$kind}_lat" => $loc['lat'], "clock_{$kind}_lng" => $loc['lng'], "clock_{$kind}_accuracy" => $loc['accuracy'] ),
            array( 'record_date' => $date, 'staff_id' => $staff_id ),
            array( '%s', '%s', '%d' ),
            array( '%s', '%d' )
        );
        $saved_location[] = $kind;
    }

    $row = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$table_name} WHERE record_date = %s AND staff_id = %d", $date, $staff_id ),
        ARRAY_A
    );
    $out = $row ? mush_office_format_time_card( $row ) : array( 'saved' => true );
    $out['locationSaved'] = $saved_location;
    return new WP_REST_Response( $out, 200 );
}

/**
 * ============================================================
 * 管理者⇔従業員チャット関連
 * ============================================================
 * 従業員は自分の日報ページ(ログイン後)でスレッドを確認・返信する。
 * 管理者は専用の管理者チャットページ(kanri-chat.html)から、従業員を
 * 選んで同じスレッドを読み書きする。管理者側は従業員ログインの仕組みを
 * 持たないため、他のエンドポイント(管理者集計など)と同様に認証なしで
 * 呼び出せるようにしている。
 */
function mush_office_format_chat_message( $row ) {
    return array(
        'id'        => intval( $row['id'] ),
        'staffId'   => strval( $row['staff_id'] ),
        'sender'    => $row['sender'],
        'message'   => $row['message'],
        'isRead'    => (bool) intval( $row['is_read'] ),
        'createdAt' => $row['created_at'],
    );
}

// 特定の従業員とのスレッドを、古い順に全件取得する。
function mush_office_get_chat_messages( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_chat_messages';

    $staff_id = intval( $request->get_param( 'staffId' ) );
    if ( ! $staff_id ) {
        return new WP_REST_Response( array( 'error' => 'staffIdを指定してください。' ), 400 );
    }

    $rows = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$table_name} WHERE staff_id = %d ORDER BY created_at ASC, id ASC", $staff_id ),
        ARRAY_A
    );
    if ( null === $rows ) {
        $rows = array();
    }
    return new WP_REST_Response( array_map( 'mush_office_format_chat_message', $rows ), 200 );
}

// メッセージを1件送信する。senderが'staff'の場合のみ、送信者本人のログイン
// トークンを必須にする(他の従業員になりすまして送れないようにするため)。
// senderが'admin'の場合は、管理者ログインのトークンを必須にしている。
function mush_office_send_chat_message( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_chat_messages';

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }

    $staff_id = isset( $p['staffId'] ) ? intval( $p['staffId'] ) : 0;
    $sender   = isset( $p['sender'] ) ? sanitize_text_field( $p['sender'] ) : '';
    $message  = isset( $p['message'] ) ? sanitize_textarea_field( $p['message'] ) : '';

    if ( ! $staff_id || ! in_array( $sender, array( 'admin', 'staff' ), true ) || '' === trim( $message ) ) {
        return new WP_REST_Response( array( 'error' => '入力内容を確認してください。' ), 400 );
    }

    if ( 'admin' === $sender && ! mush_office_is_admin_request( $request ) ) {
        return new WP_REST_Response( array( 'error' => '管理者ログインが必要です。' ), 401 );
    }
    if ( 'staff' === $sender ) {
        $auth_staff_id = mush_office_get_bearer_staff_id( $request );
        if ( null === $auth_staff_id || $auth_staff_id !== $staff_id ) {
            return new WP_REST_Response( array( 'error' => 'ログインが必要です。' ), 401 );
        }
    }

    $inserted = $wpdb->insert(
        $table_name,
        array(
            'staff_id'   => $staff_id,
            'sender'     => $sender,
            'message'    => $message,
            'is_read'    => 0,
            'created_at' => current_time( 'mysql' ),
        ),
        array( '%d', '%s', '%s', '%d', '%s' )
    );
    if ( false === $inserted ) {
        return new WP_REST_Response( array( 'error' => '送信に失敗しました。' ), 500 );
    }

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $wpdb->insert_id ), ARRAY_A );
    return new WP_REST_Response( $row ? mush_office_format_chat_message( $row ) : array( 'sent' => true ), 201 );
}

/**
 * ============================================================
 * 全員へのお知らせ(管理者チャットの「全員に送信」から投稿する)
 * ============================================================
 * 従業員ごとのチャットスレッドとは別に持つ、1件だけの共有お知らせ。
 * 朝礼画面の上部に、送信から2日間だけ表示する。
 */
function mush_office_create_announcement( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_announcements';

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }
    $message = isset( $p['message'] ) ? sanitize_textarea_field( $p['message'] ) : '';
    if ( '' === trim( $message ) ) {
        return new WP_REST_Response( array( 'error' => 'お知らせの内容を入力してください。' ), 400 );
    }

    $inserted = $wpdb->insert(
        $table_name,
        array( 'message' => $message, 'created_at' => current_time( 'mysql' ) ),
        array( '%s', '%s' )
    );
    if ( false === $inserted ) {
        return new WP_REST_Response( array( 'error' => '送信に失敗しました。' ), 500 );
    }

    mush_office_log_activity( wp_get_current_user()->display_name, '全員へのお知らせ送信', mb_substr( $message, 0, 40 ) );

    return new WP_REST_Response( array( 'sent' => true ), 201 );
}

// 直近48時間以内に送られたお知らせがあれば返す(朝礼画面用)。無ければ空を返す。
function mush_office_get_active_announcement( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_announcements';

    $cutoff = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 2 * DAY_IN_SECONDS );
    $row    = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$table_name} WHERE created_at >= %s ORDER BY id DESC LIMIT 1", $cutoff ),
        ARRAY_A
    );
    if ( ! $row ) {
        return new WP_REST_Response( array( 'message' => '', 'createdAt' => '' ), 200 );
    }
    return new WP_REST_Response( array( 'message' => $row['message'], 'createdAt' => $row['created_at'] ), 200 );
}

// チャットのメッセージを「既読」にする。
// reader='admin'なら管理者がそのスレッドの従業員からのメッセージを、それ以外は
// 従業員本人が管理者からのメッセージを既読にする。
function mush_office_mark_chat_read( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_chat_messages';

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }
    $staff_id = isset( $p['staffId'] ) ? intval( $p['staffId'] ) : 0;

    if ( isset( $p['reader'] ) && 'admin' === $p['reader'] ) {
        if ( ! $staff_id || ! mush_office_is_admin_request( $request ) ) {
            return new WP_REST_Response( array( 'error' => '管理者ログインが必要です。' ), 401 );
        }
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table_name} SET is_read = 1 WHERE staff_id = %d AND sender = 'staff' AND is_read = 0",
            $staff_id
        ) );
        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }

    $auth_staff_id = mush_office_get_bearer_staff_id( $request );
    if ( null === $auth_staff_id || ! $staff_id || $auth_staff_id !== $staff_id ) {
        return new WP_REST_Response( array( 'error' => 'ログインが必要です。' ), 401 );
    }

    $wpdb->query( $wpdb->prepare(
        "UPDATE {$table_name} SET is_read = 1 WHERE staff_id = %d AND sender = 'admin' AND is_read = 0",
        $staff_id
    ) );
    return new WP_REST_Response( array( 'ok' => true ), 200 );
}

// 管理者からの未読メッセージ(is_read=0)が1件以上ある従業員IDの一覧を返す。
// 朝礼ページで、未読がある人の名前の頭に目印を表示するために使う。
function mush_office_get_unread_chat_staff_ids( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_chat_messages';

    $rows = $wpdb->get_col(
        "SELECT DISTINCT staff_id FROM {$table_name} WHERE sender = 'admin' AND is_read = 0"
    );
    if ( null === $rows ) {
        $rows = array();
    }
    return new WP_REST_Response( array_map( 'strval', $rows ), 200 );
}

// 管理者画面用: 従業員から届いた未読メッセージの件数を、従業員IDごとに返す。
function mush_office_get_admin_unread_chat_counts( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_chat_messages';
    $rows = $wpdb->get_results(
        "SELECT staff_id, COUNT(*) AS cnt FROM {$table_name} WHERE sender = 'staff' AND is_read = 0 GROUP BY staff_id",
        ARRAY_A
    );
    $out = array();
    foreach ( (array) $rows as $r ) {
        $out[ strval( $r['staff_id'] ) ] = intval( $r['cnt'] );
    }
    return new WP_REST_Response( (object) $out, 200 );
}

/**
 * ============================================================
 * 従業員同士の個別チャット(DM)
 * ============================================================
 * 日報ページにログインした従業員どうしが1対1でやり取りする。送信者・閲覧者は
 * ログイントークンの本人に限定し、管理者(共通パスワード)からは閲覧できない。
 */
// ログイン中の従業員ID(管理者の共通パスワードでのログイン=0は対象外)。
function mush_office_dm_me( WP_REST_Request $request ) {
    $me = mush_office_get_bearer_staff_id( $request );
    return ( null === $me || 0 === $me ) ? 0 : $me;
}

function mush_office_format_dm_message( $row, $me ) {
    return array(
        'id'        => intval( $row['id'] ),
        'fromId'    => strval( $row['from_staff_id'] ),
        'toId'      => strval( $row['to_staff_id'] ),
        'mine'      => intval( $row['from_staff_id'] ) === $me,
        'message'   => $row['message'],
        'isRead'    => (bool) intval( $row['is_read'] ),
        'createdAt' => $row['created_at'],
    );
}

// 自分宛ての未読件数と、相手ごとの最新メッセージ(一覧表示用)。
function mush_office_get_dm_threads( WP_REST_Request $request ) {
    global $wpdb;
    $me = mush_office_dm_me( $request );
    if ( ! $me ) {
        return new WP_REST_Response( array( 'error' => '従業員としてログインしてください。' ), 403 );
    }
    $table_name = $wpdb->prefix . 'mush_dm_messages';

    $unread_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT from_staff_id, COUNT(*) AS cnt FROM {$table_name} WHERE to_staff_id = %d AND is_read = 0 GROUP BY from_staff_id",
        $me
    ), ARRAY_A );
    $last_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT m.* FROM {$table_name} m
         INNER JOIN (
             SELECT MAX(id) AS id FROM {$table_name}
             WHERE from_staff_id = %d OR to_staff_id = %d
             GROUP BY CASE WHEN from_staff_id = %d THEN to_staff_id ELSE from_staff_id END
         ) x ON x.id = m.id",
        $me, $me, $me
    ), ARRAY_A );

    $threads = array();
    foreach ( (array) $last_rows as $r ) {
        $other = intval( $r['from_staff_id'] ) === $me ? intval( $r['to_staff_id'] ) : intval( $r['from_staff_id'] );
        $threads[ $other ] = array(
            'staffId'     => strval( $other ),
            'unread'      => 0,
            'lastMessage' => mb_substr( $r['message'], 0, 40 ),
            'lastAt'      => $r['created_at'],
        );
    }
    foreach ( (array) $unread_rows as $r ) {
        $other = intval( $r['from_staff_id'] );
        if ( isset( $threads[ $other ] ) ) {
            $threads[ $other ]['unread'] = intval( $r['cnt'] );
        }
    }
    return new WP_REST_Response( array_values( $threads ), 200 );
}

// 相手(with)とのやり取りを古い順に返し、相手から自分宛ての未読を既読にする。
function mush_office_get_dm_messages( WP_REST_Request $request ) {
    global $wpdb;
    $me    = mush_office_dm_me( $request );
    $other = intval( $request->get_param( 'with' ) );
    if ( ! $me ) {
        return new WP_REST_Response( array( 'error' => '従業員としてログインしてください。' ), 403 );
    }
    if ( ! $other || $other === $me ) {
        return new WP_REST_Response( array( 'error' => '相手を選んでください。' ), 400 );
    }
    $table_name = $wpdb->prefix . 'mush_dm_messages';

    $wpdb->query( $wpdb->prepare(
        "UPDATE {$table_name} SET is_read = 1 WHERE from_staff_id = %d AND to_staff_id = %d AND is_read = 0",
        $other, $me
    ) );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table_name}
         WHERE (from_staff_id = %d AND to_staff_id = %d) OR (from_staff_id = %d AND to_staff_id = %d)
         ORDER BY created_at ASC, id ASC",
        $me, $other, $other, $me
    ), ARRAY_A );
    $out = array_map( function ( $r ) use ( $me ) {
        return mush_office_format_dm_message( $r, $me );
    }, (array) $rows );
    return new WP_REST_Response( $out, 200 );
}

function mush_office_send_dm_message( WP_REST_Request $request ) {
    global $wpdb;
    $me = mush_office_dm_me( $request );
    if ( ! $me ) {
        return new WP_REST_Response( array( 'error' => '従業員としてログインしてください。' ), 403 );
    }
    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }
    $to      = isset( $p['to'] ) ? intval( $p['to'] ) : 0;
    $message = isset( $p['message'] ) ? sanitize_textarea_field( $p['message'] ) : '';
    if ( ! $to || $to === $me || '' === trim( $message ) ) {
        return new WP_REST_Response( array( 'error' => '入力内容を確認してください。' ), 400 );
    }
    $emp_table = $wpdb->prefix . 'mush_employees';
    if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$emp_table} WHERE id = %d", $to ) ) ) {
        return new WP_REST_Response( array( 'error' => '送信先の従業員が見つかりません。' ), 404 );
    }

    $table_name = $wpdb->prefix . 'mush_dm_messages';
    $inserted = $wpdb->insert(
        $table_name,
        array(
            'from_staff_id' => $me,
            'to_staff_id'   => $to,
            'message'       => $message,
            'is_read'       => 0,
            'created_at'    => current_time( 'mysql' ),
        ),
        array( '%d', '%d', '%s', '%d', '%s' )
    );
    if ( false === $inserted ) {
        return new WP_REST_Response( array( 'error' => '送信に失敗しました。' ), 500 );
    }
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $wpdb->insert_id ), ARRAY_A );
    return new WP_REST_Response( mush_office_format_dm_message( $row, $me ), 201 );
}

/**
 * ============================================================
 * 経理: 顧客マスタ関連
 * ============================================================
 */
function mush_office_format_client( $row ) {
    return array(
        'id'        => intval( $row['id'] ),
        'name'      => $row['name'],
        'rep1'      => $row['rep1'],
        'rep2'      => $row['rep2'],
        'postalCode' => $row['postal_code'],
        'address'   => $row['address'],
        'phone'     => $row['phone'],
        'projects'  => $row['projects'],
        'memo'      => $row['memo'],
        'createdAt' => $row['created_at'],
        'updatedAt' => $row['updated_at'],
    );
}

function mush_office_get_clients( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_clients';
    $rows = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY name ASC", ARRAY_A );
    if ( null === $rows ) {
        $rows = array();
    }
    return new WP_REST_Response( array_map( 'mush_office_format_client', $rows ), 200 );
}

// 保存(idがあれば更新、なければ新規作成)。
function mush_office_upsert_client( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_clients';

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }

    $id   = isset( $p['id'] ) ? intval( $p['id'] ) : 0;
    $name = isset( $p['name'] ) ? sanitize_text_field( $p['name'] ) : '';
    if ( '' === $name ) {
        return new WP_REST_Response( array( 'error' => '顧客名を入力してください。' ), 400 );
    }

    $data = array(
        'name'     => $name,
        'rep1'     => isset( $p['rep1'] ) ? sanitize_text_field( $p['rep1'] ) : '',
        'rep2'     => isset( $p['rep2'] ) ? sanitize_text_field( $p['rep2'] ) : '',
        'postal_code' => isset( $p['postalCode'] ) ? sanitize_text_field( $p['postalCode'] ) : '',
        'address'  => isset( $p['address'] ) ? sanitize_text_field( $p['address'] ) : '',
        'phone'    => isset( $p['phone'] ) ? sanitize_text_field( $p['phone'] ) : '',
        'projects' => isset( $p['projects'] ) ? sanitize_textarea_field( $p['projects'] ) : '',
        'memo'     => isset( $p['memo'] ) ? sanitize_textarea_field( $p['memo'] ) : '',
    );
    $now    = current_time( 'mysql' );
    $is_new = ! ( $id > 0 );

    if ( $id > 0 ) {
        $data['updated_at'] = $now;
        $updated = $wpdb->update( $table_name, $data, array( 'id' => $id ) );
        if ( false === $updated ) {
            return new WP_REST_Response( array( 'error' => '更新に失敗しました。' ), 500 );
        }
    } else {
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $inserted = $wpdb->insert( $table_name, $data );
        if ( false === $inserted ) {
            return new WP_REST_Response( array( 'error' => '登録に失敗しました。' ), 500 );
        }
        $id = $wpdb->insert_id;
    }

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    mush_office_log_activity( wp_get_current_user()->display_name, $is_new ? '顧客新規登録' : '顧客情報変更', $name );
    return new WP_REST_Response( $row ? mush_office_format_client( $row ) : array( 'saved' => true ), 200 );
}

function mush_office_delete_client( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_clients';
    $id = intval( $request['id'] );

    $deleted = $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );
    if ( false === $deleted ) {
        return new WP_REST_Response( array( 'error' => '削除に失敗しました。' ), 500 );
    }
    return new WP_REST_Response( array( 'deleted' => true ), 200 );
}

/**
 * ============================================================
 * 経理: 商品マスタ関連
 * ============================================================
 */
function mush_office_format_product( $row ) {
    return array(
        'id'        => intval( $row['id'] ),
        'name'      => $row['name'],
        'cost'      => round( floatval( $row['cost'] ), 2 ),   // 小数第2位まで(整数なら 1500 のまま返る)
        'price'     => round( floatval( $row['price'] ), 2 ),
        'maker'     => $row['maker'],
        'createdAt' => $row['created_at'],
        'updatedAt' => $row['updated_at'],
    );
}

function mush_office_get_products( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_products';
    $rows = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY name ASC", ARRAY_A );
    if ( null === $rows ) {
        $rows = array();
    }
    return new WP_REST_Response( array_map( 'mush_office_format_product', $rows ), 200 );
}

function mush_office_upsert_product( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_products';

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }

    $id   = isset( $p['id'] ) ? intval( $p['id'] ) : 0;
    $name = isset( $p['name'] ) ? sanitize_text_field( $p['name'] ) : '';
    if ( '' === $name ) {
        return new WP_REST_Response( array( 'error' => '商品名を入力してください。' ), 400 );
    }

    $data = array(
        'name'  => $name,
        'cost'  => isset( $p['cost'] ) ? round( floatval( $p['cost'] ), 2 ) : 0,
        'price' => isset( $p['price'] ) ? round( floatval( $p['price'] ), 2 ) : 0,
        'maker' => isset( $p['maker'] ) ? sanitize_text_field( $p['maker'] ) : '',
    );
    $now    = current_time( 'mysql' );
    $is_new = ! ( $id > 0 );

    if ( $id > 0 ) {
        $data['updated_at'] = $now;
        $updated = $wpdb->update( $table_name, $data, array( 'id' => $id ) );
        if ( false === $updated ) {
            return new WP_REST_Response( array( 'error' => '更新に失敗しました。' ), 500 );
        }
    } else {
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $inserted = $wpdb->insert( $table_name, $data );
        if ( false === $inserted ) {
            return new WP_REST_Response( array( 'error' => '登録に失敗しました。' ), 500 );
        }
        $id = $wpdb->insert_id;
    }

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    mush_office_log_activity( wp_get_current_user()->display_name, $is_new ? '商品新規登録' : '商品情報変更', $name );
    return new WP_REST_Response( $row ? mush_office_format_product( $row ) : array( 'saved' => true ), 200 );
}

function mush_office_delete_product( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_products';
    $id = intval( $request['id'] );

    $deleted = $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );
    if ( false === $deleted ) {
        return new WP_REST_Response( array( 'error' => '削除に失敗しました。' ), 500 );
    }
    return new WP_REST_Response( array( 'deleted' => true ), 200 );
}

/**
 * ============================================================
 * 経理: 見積もり関連
 * ============================================================
 * 品目はitems_jsonにJSON配列でまとめて保存するシンプルな構造。
 * quote_noは「見積もりNO」で、YYYYMM(発行月)+連番で自動採番する
 * (経理用ページ(f17)の設計メモに沿ったもの。原価計算書・請求書との
 * 連携キーとして使う想定)。
 */
function mush_office_format_quote( $row ) {
    $items = json_decode( $row['items_json'], true );
    if ( ! is_array( $items ) ) {
        $items = array();
    }
    return array(
        'id'          => intval( $row['id'] ),
        'quoteNo'     => $row['quote_no'],
        'clientId'    => $row['client_id'] ? strval( $row['client_id'] ) : '',
        'clientName'  => $row['client_name'],
        'projectName' => $row['project_name'],
        'staffId'     => $row['staff_id'] ? strval( $row['staff_id'] ) : '',
        'staffName'   => $row['staff_name'],
        'worker1Id'   => $row['worker1_id'] ? strval( $row['worker1_id'] ) : '',
        'worker1Name' => $row['worker1_name'],
        'worker2Id'   => $row['worker2_id'] ? strval( $row['worker2_id'] ) : '',
        'worker2Name' => $row['worker2_name'],
        'issueDate'   => $row['issue_date'],
        'validMonths' => isset( $row['valid_months'] ) ? intval( $row['valid_months'] ) : 1,
        'costNo'      => isset( $row['cost_no'] ) ? strval( $row['cost_no'] ) : '', // 引き継ぎ元の原価計算書NO
        'items'       => $items,
        'totalAmount' => intval( $row['total_amount'] ),
        'memo'        => $row['memo'],
        'createdAt'   => $row['created_at'],
        'updatedAt'   => $row['updated_at'],
    );
}

function mush_office_get_quotes( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_quotes';
    $rows = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY created_at DESC", ARRAY_A );
    if ( null === $rows ) {
        $rows = array();
    }
    return new WP_REST_Response( array_map( 'mush_office_format_quote', $rows ), 200 );
}

function mush_office_generate_quote_no() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_quotes';
    $prefix = 'Q' . current_time( 'Ym' );

    $count = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_name} WHERE quote_no LIKE %s",
        $wpdb->esc_like( $prefix ) . '%'
    ) );
    $seq = intval( $count ) + 1;
    return $prefix . '-' . str_pad( $seq, 3, '0', STR_PAD_LEFT );
}

function mush_office_upsert_quote( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_quotes';

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }

    $id = isset( $p['id'] ) ? intval( $p['id'] ) : 0;

    $items_raw = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();
    $items     = array();
    $subtotal  = 0;
    foreach ( $items_raw as $item ) {
        $qty   = isset( $item['quantity'] ) ? floatval( $item['quantity'] ) : 0;
        $price = isset( $item['unitPrice'] ) ? floatval( $item['unitPrice'] ) : 0;
        $amount = round( $qty * $price ); // 明細の金額は円未満を四捨五入(単価は小数第2位まで入力できるため)
        $items[] = array(
            'name'      => isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '',
            'quantity'  => $qty,
            'unitPrice' => $price,
            'amount'    => $amount,
        );
        $subtotal += $amount;
    }
    // 消費税10%(端数切り捨て)を加えた税込金額をtotal_amountとして保存する。
    $tax   = floor( $subtotal * 0.1 );
    $total = $subtotal + $tax;

    $data = array(
        'client_id'    => ! empty( $p['clientId'] ) ? intval( $p['clientId'] ) : null,
        'client_name'  => isset( $p['clientName'] ) ? sanitize_text_field( $p['clientName'] ) : '',
        'project_name' => isset( $p['projectName'] ) ? sanitize_text_field( $p['projectName'] ) : '',
        'staff_id'     => ! empty( $p['staffId'] ) ? intval( $p['staffId'] ) : null,
        'staff_name'   => isset( $p['staffName'] ) ? sanitize_text_field( $p['staffName'] ) : '',
        'worker1_id'   => ! empty( $p['worker1Id'] ) ? intval( $p['worker1Id'] ) : null,
        'worker1_name' => isset( $p['worker1Name'] ) ? sanitize_text_field( $p['worker1Name'] ) : '',
        'worker2_id'   => ! empty( $p['worker2Id'] ) ? intval( $p['worker2Id'] ) : null,
        'worker2_name' => isset( $p['worker2Name'] ) ? sanitize_text_field( $p['worker2Name'] ) : '',
        'issue_date'   => isset( $p['issueDate'] ) ? sanitize_text_field( $p['issueDate'] ) : current_time( 'Y-m-d' ),
        'valid_months' => isset( $p['validMonths'] ) ? intval( $p['validMonths'] ) : 1,
        'items_json'   => wp_json_encode( $items ),
        'total_amount' => $total,
        'memo'         => isset( $p['memo'] ) ? sanitize_textarea_field( $p['memo'] ) : '',
    );
    // 引き継ぎ元の原価計算書NO(送られてきたときだけ更新する)。
    if ( isset( $p['costNo'] ) ) {
        $data['cost_no'] = sanitize_text_field( $p['costNo'] );
    }
    $now = current_time( 'mysql' );

    $is_new = ( $id <= 0 );
    if ( $id > 0 ) {
        $data['updated_at'] = $now;
        $updated = $wpdb->update( $table_name, $data, array( 'id' => $id ) );
        if ( false === $updated ) {
            return new WP_REST_Response( array( 'error' => '更新に失敗しました。' ), 500 );
        }
    } else {
        $data['quote_no']   = mush_office_generate_quote_no();
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $inserted = $wpdb->insert( $table_name, $data );
        if ( false === $inserted ) {
            return new WP_REST_Response( array( 'error' => '登録に失敗しました。', 'detail' => $wpdb->last_error ), 500 );
        }
        $id = $wpdb->insert_id;
    }

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    // 原価計算書→見積もりの順で入力する運用のため、引き継ぎ元の原価計算書に、この見積もりNOを(未設定なら)書き戻してつなぐ。
    if ( $row && ! empty( $row['cost_no'] ) ) {
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}mush_costs SET quote_no = %s WHERE report_no = %s AND ( quote_no = '' OR quote_no IS NULL )",
            $row['quote_no'], $row['cost_no']
        ) );
    }
    mush_office_log_activity( $data['staff_name'], $is_new ? '見積もり新規作成' : '見積もり変更', $row ? $row['quote_no'] : '' );
    return new WP_REST_Response( $row ? mush_office_format_quote( $row ) : array( 'saved' => true ), 200 );
}

function mush_office_delete_quote( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_quotes';
    $id = intval( $request['id'] );

    $deleted = $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );
    if ( false === $deleted ) {
        return new WP_REST_Response( array( 'error' => '削除に失敗しました。' ), 500 );
    }
    return new WP_REST_Response( array( 'deleted' => true ), 200 );
}

/**
 * ============================================================
 * 給与計算ページ用: 年度ごとの社会保険・厚生年金保険料額表
 * ============================================================
 * Excelの読み取り自体はブラウザ側(SheetJS)で行い、等級ごとの一覧を
 * JSON配列(tiersJson)にしてアップロードする。元のExcelファイルも
 * そのまま保存し、一覧からダウンロード・再確認できるようにする。
 */
function mush_office_format_insurance_table( $row ) {
    $tiers = json_decode( $row['tiers_json'], true );
    if ( ! is_array( $tiers ) ) {
        $tiers = array();
    }
    return array(
        'id'         => intval( $row['id'] ),
        'fiscalYear' => intval( $row['fiscal_year'] ),
        'region'     => isset( $row['region'] ) ? $row['region'] : '',
        'title'      => $row['title'],
        'fileName'   => $row['file_name'],
        'fileUrl'    => $row['file_url'],
        'tiers'      => $tiers,
        'uploadedBy' => $row['uploaded_by'],
        'createdAt'  => $row['created_at'],
    );
}

function mush_office_get_insurance_tables( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_insurance_tables';
    $rows = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY fiscal_year DESC, created_at DESC", ARRAY_A );
    if ( null === $rows ) {
        $rows = array();
    }
    return new WP_REST_Response( array_map( 'mush_office_format_insurance_table', $rows ), 200 );
}

function mush_office_upload_insurance_table( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_insurance_tables';

    $p = $request->get_params();
    $fiscal_year = isset( $p['fiscalYear'] ) ? intval( $p['fiscalYear'] ) : 0;
    $region      = isset( $p['region'] ) ? sanitize_text_field( $p['region'] ) : '';
    $title       = isset( $p['title'] ) ? sanitize_text_field( $p['title'] ) : '';
    $tiers_json  = isset( $p['tiersJson'] ) ? wp_unslash( $p['tiersJson'] ) : '[]';

    if ( ! $fiscal_year ) {
        return new WP_REST_Response( array( 'error' => '年度を入力してください。' ), 400 );
    }
    $tiers = json_decode( $tiers_json, true );
    if ( ! is_array( $tiers ) || empty( $tiers ) ) {
        return new WP_REST_Response( array( 'error' => '保険料額表の読み取りに失敗しました。Excelの内容(列見出し)をご確認ください。' ), 400 );
    }

    $file_url  = '';
    $file_name = '';
    $files = $request->get_file_params();
    if ( ! empty( $files['file'] ) ) {
        if ( UPLOAD_ERR_OK === $files['file']['error'] ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $upload = wp_handle_upload( $files['file'], array( 'test_form' => false ) );
            if ( isset( $upload['error'] ) ) {
                return new WP_REST_Response( array( 'error' => 'ファイルの保存に失敗しました: ' . $upload['error'] ), 500 );
            }
            $file_url  = $upload['url'];
            $file_name = sanitize_file_name( $files['file']['name'] );
        }
    }

    $inserted = $wpdb->insert(
        $table_name,
        array(
            'fiscal_year' => $fiscal_year,
            'region'      => $region,
            'title'       => $title,
            'file_name'   => $file_name,
            'file_url'    => $file_url,
            'tiers_json'  => wp_json_encode( $tiers ),
            'uploaded_by' => wp_get_current_user() ? wp_get_current_user()->display_name : '',
            'created_at'  => current_time( 'mysql' ),
        ),
        array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
    );
    if ( false === $inserted ) {
        return new WP_REST_Response( array( 'error' => '登録に失敗しました。' ), 500 );
    }

    $id  = $wpdb->insert_id;
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    mush_office_log_activity( wp_get_current_user() ? wp_get_current_user()->display_name : '', '保険料額表アップロード', $fiscal_year . '年度 ' . $region . ' ' . $title );
    return new WP_REST_Response( $row ? mush_office_format_insurance_table( $row ) : array( 'saved' => true ), 201 );
}

function mush_office_delete_insurance_table( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_insurance_tables';
    $id = intval( $request['id'] );

    $row     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    $deleted = $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );
    if ( false === $deleted ) {
        return new WP_REST_Response( array( 'error' => '削除に失敗しました。' ), 500 );
    }
    if ( $row ) {
        if ( ! empty( $row['file_url'] ) ) {
            $upload_dir = wp_upload_dir();
            $file_path  = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $row['file_url'] );
            if ( file_exists( $file_path ) ) {
                @unlink( $file_path );
            }
        }
    }
    mush_office_log_activity( wp_get_current_user() ? wp_get_current_user()->display_name : '', '保険料額表削除', $row ? ( $row['fiscal_year'] . '年度 ' . $row['title'] ) : '' );
    return new WP_REST_Response( array( 'deleted' => true ), 200 );
}

/**
 * ============================================================
 * 給与計算ページ用: 年度ごとの源泉所得税額表(国税庁の公式Excelアップロード)
 * ============================================================
 * Excelの読み取り自体はブラウザ側(SheetJS)で行い、甲欄(扶養人数0〜7人)・
 * 乙欄・最低額未満の特例・表の上限額をまとめたJSON(dataJson)をアップロードする。
 * 保険料額表と構造が異なる(甲欄が配列)ため、別テーブル・別APIにしている。
 */
function mush_office_format_tax_table( $row ) {
    $data = json_decode( $row['data_json'], true );
    if ( ! is_array( $data ) ) {
        $data = array();
    }
    return array(
        'id'         => intval( $row['id'] ),
        'fiscalYear' => intval( $row['fiscal_year'] ),
        'title'      => $row['title'],
        'fileName'   => $row['file_name'],
        'fileUrl'    => $row['file_url'],
        'data'       => $data,
        'uploadedBy' => $row['uploaded_by'],
        'createdAt'  => $row['created_at'],
    );
}

function mush_office_get_tax_tables( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_tax_tables';
    $rows = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY fiscal_year DESC, created_at DESC", ARRAY_A );
    if ( null === $rows ) {
        $rows = array();
    }
    return new WP_REST_Response( array_map( 'mush_office_format_tax_table', $rows ), 200 );
}

function mush_office_upload_tax_table( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_tax_tables';

    $p = $request->get_params();
    $fiscal_year = isset( $p['fiscalYear'] ) ? intval( $p['fiscalYear'] ) : 0;
    $title       = isset( $p['title'] ) ? sanitize_text_field( $p['title'] ) : '';
    $data_json   = isset( $p['dataJson'] ) ? wp_unslash( $p['dataJson'] ) : '';

    if ( ! $fiscal_year ) {
        return new WP_REST_Response( array( 'error' => '年度を入力してください。' ), 400 );
    }
    $data = json_decode( $data_json, true );
    if ( ! is_array( $data ) || empty( $data['brackets'] ) ) {
        return new WP_REST_Response( array( 'error' => '源泉所得税額表の読み取りに失敗しました。' ), 400 );
    }

    $file_url  = '';
    $file_name = '';
    $files = $request->get_file_params();
    if ( ! empty( $files['file'] ) ) {
        if ( UPLOAD_ERR_OK === $files['file']['error'] ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $upload = wp_handle_upload( $files['file'], array( 'test_form' => false ) );
            if ( isset( $upload['error'] ) ) {
                return new WP_REST_Response( array( 'error' => 'ファイルの保存に失敗しました: ' . $upload['error'] ), 500 );
            }
            $file_url  = $upload['url'];
            $file_name = sanitize_file_name( $files['file']['name'] );
        }
    }

    $inserted = $wpdb->insert(
        $table_name,
        array(
            'fiscal_year' => $fiscal_year,
            'title'       => $title,
            'file_name'   => $file_name,
            'file_url'    => $file_url,
            'data_json'   => wp_json_encode( $data ),
            'uploaded_by' => wp_get_current_user() ? wp_get_current_user()->display_name : '',
            'created_at'  => current_time( 'mysql' ),
        ),
        array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
    );
    if ( false === $inserted ) {
        return new WP_REST_Response( array( 'error' => '登録に失敗しました。' ), 500 );
    }

    $id  = $wpdb->insert_id;
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    mush_office_log_activity( wp_get_current_user() ? wp_get_current_user()->display_name : '', '源泉所得税額表アップロード', $fiscal_year . '年度 ' . $title );
    return new WP_REST_Response( $row ? mush_office_format_tax_table( $row ) : array( 'saved' => true ), 201 );
}

function mush_office_delete_tax_table( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_tax_tables';
    $id = intval( $request['id'] );

    $row     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    $deleted = $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );
    if ( false === $deleted ) {
        return new WP_REST_Response( array( 'error' => '削除に失敗しました。' ), 500 );
    }
    if ( $row ) {
        if ( ! empty( $row['file_url'] ) ) {
            $upload_dir = wp_upload_dir();
            $file_path  = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $row['file_url'] );
            if ( file_exists( $file_path ) ) {
                @unlink( $file_path );
            }
        }
    }
    mush_office_log_activity( wp_get_current_user() ? wp_get_current_user()->display_name : '', '源泉所得税額表削除', $row ? ( $row['fiscal_year'] . '年度 ' . $row['title'] ) : '' );
    return new WP_REST_Response( array( 'deleted' => true ), 200 );
}

/**
 * ============================================================
 * 給与計算ページ用: 年度ごとの賞与に対する源泉徴収税額の算出率の表(国税庁の公式Excel)
 * ============================================================
 * Excelの読み取りはブラウザ側(SheetJS)で行い、甲欄(扶養人数0〜7人以上)ごとの
 * 前月給与レンジ×算出率、乙欄のレンジ×算出率をまとめたJSON(dataJson)を保存する。
 */
function mush_office_format_bonus_tax_table( $row ) {
    $data = json_decode( $row['data_json'], true );
    if ( ! is_array( $data ) ) {
        $data = array();
    }
    return array(
        'id'         => intval( $row['id'] ),
        'fiscalYear' => intval( $row['fiscal_year'] ),
        'title'      => $row['title'],
        'fileName'   => $row['file_name'],
        'fileUrl'    => $row['file_url'],
        'data'       => $data,
        'uploadedBy' => $row['uploaded_by'],
        'createdAt'  => $row['created_at'],
    );
}

function mush_office_get_bonus_tax_tables( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_bonus_tax_tables';
    $rows = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY fiscal_year DESC, created_at DESC", ARRAY_A );
    if ( null === $rows ) {
        $rows = array();
    }
    return new WP_REST_Response( array_map( 'mush_office_format_bonus_tax_table', $rows ), 200 );
}

function mush_office_upload_bonus_tax_table( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_bonus_tax_tables';

    $p = $request->get_params();
    $fiscal_year = isset( $p['fiscalYear'] ) ? intval( $p['fiscalYear'] ) : 0;
    $title       = isset( $p['title'] ) ? sanitize_text_field( $p['title'] ) : '';
    $data_json   = isset( $p['dataJson'] ) ? wp_unslash( $p['dataJson'] ) : '';

    if ( ! $fiscal_year ) {
        return new WP_REST_Response( array( 'error' => '年度を入力してください。' ), 400 );
    }
    $data = json_decode( $data_json, true );
    if ( ! is_array( $data ) || empty( $data['kouBrackets'] ) ) {
        return new WP_REST_Response( array( 'error' => '算出率表の読み取りに失敗しました。' ), 400 );
    }

    $file_url  = '';
    $file_name = '';
    $files = $request->get_file_params();
    if ( ! empty( $files['file'] ) ) {
        if ( UPLOAD_ERR_OK === $files['file']['error'] ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $upload = wp_handle_upload( $files['file'], array( 'test_form' => false ) );
            if ( isset( $upload['error'] ) ) {
                return new WP_REST_Response( array( 'error' => 'ファイルの保存に失敗しました: ' . $upload['error'] ), 500 );
            }
            $file_url  = $upload['url'];
            $file_name = sanitize_file_name( $files['file']['name'] );
        }
    }

    $inserted = $wpdb->insert(
        $table_name,
        array(
            'fiscal_year' => $fiscal_year,
            'title'       => $title,
            'file_name'   => $file_name,
            'file_url'    => $file_url,
            'data_json'   => wp_json_encode( $data ),
            'uploaded_by' => wp_get_current_user() ? wp_get_current_user()->display_name : '',
            'created_at'  => current_time( 'mysql' ),
        ),
        array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
    );
    if ( false === $inserted ) {
        return new WP_REST_Response( array( 'error' => '登録に失敗しました。' ), 500 );
    }

    $id  = $wpdb->insert_id;
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    mush_office_log_activity( wp_get_current_user() ? wp_get_current_user()->display_name : '', '賞与源泉税率表アップロード', $fiscal_year . '年度 ' . $title );
    return new WP_REST_Response( $row ? mush_office_format_bonus_tax_table( $row ) : array( 'saved' => true ), 201 );
}

function mush_office_delete_bonus_tax_table( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_bonus_tax_tables';
    $id = intval( $request['id'] );

    $row     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    $deleted = $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );
    if ( false === $deleted ) {
        return new WP_REST_Response( array( 'error' => '削除に失敗しました。' ), 500 );
    }
    if ( $row ) {
        if ( ! empty( $row['file_url'] ) ) {
            $upload_dir = wp_upload_dir();
            $file_path  = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $row['file_url'] );
            if ( file_exists( $file_path ) ) {
                @unlink( $file_path );
            }
        }
    }
    mush_office_log_activity( wp_get_current_user() ? wp_get_current_user()->display_name : '', '賞与源泉税率表削除', $row ? ( $row['fiscal_year'] . '年度 ' . $row['title'] ) : '' );
    return new WP_REST_Response( array( 'deleted' => true ), 200 );
}

/**
 * ============================================================
 * 給与計算ページ用: 年度ごとの源泉所得税額表(日額表・国税庁の公式Excel)
 * ============================================================
 * 月額表と同じ構造に加え、日雇労働者向けの丙欄を含むJSON(dataJson)を保存する。
 */
function mush_office_format_daily_tax_table( $row ) {
    $data = json_decode( $row['data_json'], true );
    if ( ! is_array( $data ) ) {
        $data = array();
    }
    return array(
        'id'         => intval( $row['id'] ),
        'fiscalYear' => intval( $row['fiscal_year'] ),
        'title'      => $row['title'],
        'fileName'   => $row['file_name'],
        'fileUrl'    => $row['file_url'],
        'data'       => $data,
        'uploadedBy' => $row['uploaded_by'],
        'createdAt'  => $row['created_at'],
    );
}

function mush_office_get_daily_tax_tables( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_daily_tax_tables';
    $rows = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY fiscal_year DESC, created_at DESC", ARRAY_A );
    if ( null === $rows ) {
        $rows = array();
    }
    return new WP_REST_Response( array_map( 'mush_office_format_daily_tax_table', $rows ), 200 );
}

function mush_office_upload_daily_tax_table( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_daily_tax_tables';

    $p = $request->get_params();
    $fiscal_year = isset( $p['fiscalYear'] ) ? intval( $p['fiscalYear'] ) : 0;
    $title       = isset( $p['title'] ) ? sanitize_text_field( $p['title'] ) : '';
    $data_json   = isset( $p['dataJson'] ) ? wp_unslash( $p['dataJson'] ) : '';

    if ( ! $fiscal_year ) {
        return new WP_REST_Response( array( 'error' => '年度を入力してください。' ), 400 );
    }
    $data = json_decode( $data_json, true );
    if ( ! is_array( $data ) || empty( $data['brackets'] ) ) {
        return new WP_REST_Response( array( 'error' => '源泉所得税額表(日額表)の読み取りに失敗しました。' ), 400 );
    }

    $file_url  = '';
    $file_name = '';
    $files = $request->get_file_params();
    if ( ! empty( $files['file'] ) ) {
        if ( UPLOAD_ERR_OK === $files['file']['error'] ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $upload = wp_handle_upload( $files['file'], array( 'test_form' => false ) );
            if ( isset( $upload['error'] ) ) {
                return new WP_REST_Response( array( 'error' => 'ファイルの保存に失敗しました: ' . $upload['error'] ), 500 );
            }
            $file_url  = $upload['url'];
            $file_name = sanitize_file_name( $files['file']['name'] );
        }
    }

    $inserted = $wpdb->insert(
        $table_name,
        array(
            'fiscal_year' => $fiscal_year,
            'title'       => $title,
            'file_name'   => $file_name,
            'file_url'    => $file_url,
            'data_json'   => wp_json_encode( $data ),
            'uploaded_by' => wp_get_current_user() ? wp_get_current_user()->display_name : '',
            'created_at'  => current_time( 'mysql' ),
        ),
        array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
    );
    if ( false === $inserted ) {
        return new WP_REST_Response( array( 'error' => '登録に失敗しました。' ), 500 );
    }

    $id  = $wpdb->insert_id;
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    mush_office_log_activity( wp_get_current_user() ? wp_get_current_user()->display_name : '', '源泉所得税額表(日額表)アップロード', $fiscal_year . '年度 ' . $title );
    return new WP_REST_Response( $row ? mush_office_format_daily_tax_table( $row ) : array( 'saved' => true ), 201 );
}

function mush_office_delete_daily_tax_table( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_daily_tax_tables';
    $id = intval( $request['id'] );

    $row     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    $deleted = $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );
    if ( false === $deleted ) {
        return new WP_REST_Response( array( 'error' => '削除に失敗しました。' ), 500 );
    }
    if ( $row ) {
        if ( ! empty( $row['file_url'] ) ) {
            $upload_dir = wp_upload_dir();
            $file_path  = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $row['file_url'] );
            if ( file_exists( $file_path ) ) {
                @unlink( $file_path );
            }
        }
    }
    mush_office_log_activity( wp_get_current_user() ? wp_get_current_user()->display_name : '', '源泉所得税額表(日額表)削除', $row ? ( $row['fiscal_year'] . '年度 ' . $row['title'] ) : '' );
    return new WP_REST_Response( array( 'deleted' => true ), 200 );
}

/**
 * ============================================================
 * 自社情報ページ用: 労働契約書などの書類のアップロード・保管
 * ============================================================
 * 保険料額表などと違い、中身を解析せずファイルをそのまま保管するだけの
 * シンプルな書類庫。ファイル形式は問わない(PDF・Word・Excel等)。
 */
function mush_office_format_company_document( $row ) {
    return array(
        'id'         => intval( $row['id'] ),
        'title'      => $row['title'],
        'fileName'   => $row['file_name'],
        'fileUrl'    => $row['file_url'],
        'uploadedBy' => $row['uploaded_by'],
        'createdAt'  => $row['created_at'],
    );
}

function mush_office_get_company_documents( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_company_documents';
    $rows = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY created_at DESC", ARRAY_A );
    if ( null === $rows ) {
        $rows = array();
    }
    return new WP_REST_Response( array_map( 'mush_office_format_company_document', $rows ), 200 );
}

function mush_office_upload_company_document( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_company_documents';

    $p     = $request->get_params();
    $title = isset( $p['title'] ) ? sanitize_text_field( $p['title'] ) : '';

    $files = $request->get_file_params();
    if ( empty( $files['file'] ) || UPLOAD_ERR_OK !== $files['file']['error'] ) {
        return new WP_REST_Response( array( 'error' => 'ファイルを選択してください。' ), 400 );
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $upload = wp_handle_upload( $files['file'], array( 'test_form' => false ) );
    if ( isset( $upload['error'] ) ) {
        return new WP_REST_Response( array( 'error' => 'ファイルの保存に失敗しました: ' . $upload['error'] ), 500 );
    }
    $file_name = sanitize_file_name( $files['file']['name'] );
    if ( '' === $title ) {
        $title = $file_name;
    }

    $inserted = $wpdb->insert(
        $table_name,
        array(
            'title'       => $title,
            'file_name'   => $file_name,
            'file_url'    => $upload['url'],
            'uploaded_by' => wp_get_current_user() ? wp_get_current_user()->display_name : '',
            'created_at'  => current_time( 'mysql' ),
        ),
        array( '%s', '%s', '%s', '%s', '%s' )
    );
    if ( false === $inserted ) {
        return new WP_REST_Response( array( 'error' => '登録に失敗しました。' ), 500 );
    }

    $id  = $wpdb->insert_id;
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    mush_office_log_activity( wp_get_current_user() ? wp_get_current_user()->display_name : '', '書類アップロード', $title );
    return new WP_REST_Response( $row ? mush_office_format_company_document( $row ) : array( 'saved' => true ), 201 );
}

function mush_office_delete_company_document( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_company_documents';
    $id = intval( $request['id'] );

    $row     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    $deleted = $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );
    if ( false === $deleted ) {
        return new WP_REST_Response( array( 'error' => '削除に失敗しました。' ), 500 );
    }
    if ( $row ) {
        if ( ! empty( $row['file_url'] ) ) {
            $upload_dir = wp_upload_dir();
            $file_path  = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $row['file_url'] );
            if ( file_exists( $file_path ) ) {
                @unlink( $file_path );
            }
        }
    }
    mush_office_log_activity( wp_get_current_user() ? wp_get_current_user()->display_name : '', '書類削除', $row ? $row['title'] : '' );
    return new WP_REST_Response( array( 'deleted' => true ), 200 );
}

// アップロードディレクトリ配下のURLから実ファイルを削除する(社印画像の差し替え・削除用)。
// 外部URLや既にファイルが無い場合は何もしない。
function mush_office_delete_local_upload_by_url( $url ) {
    if ( empty( $url ) ) {
        return;
    }
    $upload_dir = wp_upload_dir();
    $file_path  = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $url );
    if ( file_exists( $file_path ) ) {
        @unlink( $file_path );
    }
}

// 自社情報ページ用: 社印(角印・丸印などのスタンプ画像)のアップロード・削除。
// 会社ごとに1点だけを想定し、company_infoオプションのsealImageUrlに保存する
// (見積もり・請求書の印刷時、会社名の右横に印字するために使う)。差し替え時は
// 古い画像ファイルが残り続けないよう、保存先を上書きした後に旧ファイルを削除する。
function mush_office_upload_company_seal( WP_REST_Request $request ) {
    $files = $request->get_file_params();
    if ( empty( $files['file'] ) || UPLOAD_ERR_OK !== $files['file']['error'] ) {
        return new WP_REST_Response( array( 'error' => '画像ファイルを選択してください。' ), 400 );
    }
    $allowed_types = array( 'image/png', 'image/jpeg', 'image/gif' );
    if ( ! in_array( $files['file']['type'], $allowed_types, true ) ) {
        return new WP_REST_Response( array( 'error' => 'png・jpg・gif形式の画像を選択してください。' ), 400 );
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $upload = wp_handle_upload( $files['file'], array( 'test_form' => false ) );
    if ( isset( $upload['error'] ) ) {
        return new WP_REST_Response( array( 'error' => '画像の保存に失敗しました: ' . $upload['error'] ), 500 );
    }

    $p = $request->get_params();
    $whitened = ! empty( $p['whitened'] );

    $info = get_option( 'mush_office_company_info', array() );
    if ( ! is_array( $info ) ) {
        $info = array();
    }
    $old_url = isset( $info['sealImageUrl'] ) ? $info['sealImageUrl'] : '';
    $info['sealImageUrl'] = $upload['url'];
    $info['sealWhitened'] = $whitened;
    update_option( 'mush_office_company_info', $info );
    if ( $old_url && $old_url !== $upload['url'] ) {
        mush_office_delete_local_upload_by_url( $old_url );
    }

    mush_office_log_activity( wp_get_current_user() ? wp_get_current_user()->display_name : '', '社印画像をアップロード', '' );
    return new WP_REST_Response( array( 'sealImageUrl' => $upload['url'], 'sealWhitened' => $whitened ), 200 );
}

function mush_office_delete_company_seal( WP_REST_Request $request ) {
    $info = get_option( 'mush_office_company_info', array() );
    if ( ! is_array( $info ) ) {
        $info = array();
    }
    $old_url = isset( $info['sealImageUrl'] ) ? $info['sealImageUrl'] : '';
    $info['sealImageUrl'] = '';
    $info['sealWhitened'] = false;
    update_option( 'mush_office_company_info', $info );
    if ( $old_url ) {
        mush_office_delete_local_upload_by_url( $old_url );
    }

    mush_office_log_activity( wp_get_current_user() ? wp_get_current_user()->display_name : '', '社印画像を削除', '' );
    return new WP_REST_Response( array( 'deleted' => true ), 200 );
}

/**
 * ============================================================
 * 経理: 請求書関連
 * ============================================================
 * 見積もり(mush_quotes)と同じ構造(品目はitems_jsonにJSON配列)。
 * quote_noで元になった見積もりを参照するが、1見積もりに対して
 * 複数の請求書(分割請求)を作れるよう、あえてユニーク制約にはしていない。
 */
function mush_office_format_invoice( $row ) {
    $items = json_decode( $row['items_json'], true );
    if ( ! is_array( $items ) ) {
        $items = array();
    }
    return array(
        'id'          => intval( $row['id'] ),
        'invoiceNo'   => $row['invoice_no'],
        'quoteNo'     => $row['quote_no'],
        'clientId'    => $row['client_id'] ? strval( $row['client_id'] ) : '',
        'clientName'  => $row['client_name'],
        'projectName' => $row['project_name'],
        'staffId'     => $row['staff_id'] ? strval( $row['staff_id'] ) : '',
        'staffName'   => $row['staff_name'],
        'worker1Id'   => $row['worker1_id'] ? strval( $row['worker1_id'] ) : '',
        'worker1Name' => $row['worker1_name'],
        'worker2Id'   => $row['worker2_id'] ? strval( $row['worker2_id'] ) : '',
        'worker2Name' => $row['worker2_name'],
        'issueDate'   => $row['issue_date'],
        'items'       => $items,
        'totalAmount' => intval( $row['total_amount'] ),
        'memo'        => $row['memo'],
        'createdAt'   => $row['created_at'],
        'updatedAt'   => $row['updated_at'],
    );
}

function mush_office_get_invoices( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_invoices';
    $rows = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY created_at DESC", ARRAY_A );
    if ( null === $rows ) {
        $rows = array();
    }
    return new WP_REST_Response( array_map( 'mush_office_format_invoice', $rows ), 200 );
}

function mush_office_generate_invoice_no() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_invoices';
    $prefix = 'I' . current_time( 'Ym' );

    $count = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_name} WHERE invoice_no LIKE %s",
        $wpdb->esc_like( $prefix ) . '%'
    ) );
    $seq = intval( $count ) + 1;
    return $prefix . '-' . str_pad( $seq, 3, '0', STR_PAD_LEFT );
}

function mush_office_upsert_invoice( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_invoices';

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }

    $id = isset( $p['id'] ) ? intval( $p['id'] ) : 0;

    $items_raw = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();
    $items     = array();
    $subtotal  = 0;
    foreach ( $items_raw as $item ) {
        $qty    = isset( $item['quantity'] ) ? floatval( $item['quantity'] ) : 0;
        $price  = isset( $item['unitPrice'] ) ? floatval( $item['unitPrice'] ) : 0;
        $amount = round( $qty * $price ); // 明細の金額は円未満を四捨五入(単価は小数第2位まで入力できるため)
        $items[] = array(
            'name'      => isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '',
            'quantity'  => $qty,
            'unitPrice' => $price,
            'amount'    => $amount,
        );
        $subtotal += $amount;
    }
    // 消費税10%(端数切り捨て)を加えた税込金額をtotal_amountとして保存する。
    $tax   = floor( $subtotal * 0.1 );
    $total = $subtotal + $tax;

    $data = array(
        'quote_no'     => isset( $p['quoteNo'] ) ? sanitize_text_field( $p['quoteNo'] ) : '',
        'client_id'    => ! empty( $p['clientId'] ) ? intval( $p['clientId'] ) : null,
        'client_name'  => isset( $p['clientName'] ) ? sanitize_text_field( $p['clientName'] ) : '',
        'project_name' => isset( $p['projectName'] ) ? sanitize_text_field( $p['projectName'] ) : '',
        'staff_id'     => ! empty( $p['staffId'] ) ? intval( $p['staffId'] ) : null,
        'staff_name'   => isset( $p['staffName'] ) ? sanitize_text_field( $p['staffName'] ) : '',
        'worker1_id'   => ! empty( $p['worker1Id'] ) ? intval( $p['worker1Id'] ) : null,
        'worker1_name' => isset( $p['worker1Name'] ) ? sanitize_text_field( $p['worker1Name'] ) : '',
        'worker2_id'   => ! empty( $p['worker2Id'] ) ? intval( $p['worker2Id'] ) : null,
        'worker2_name' => isset( $p['worker2Name'] ) ? sanitize_text_field( $p['worker2Name'] ) : '',
        'issue_date'   => isset( $p['issueDate'] ) ? sanitize_text_field( $p['issueDate'] ) : current_time( 'Y-m-d' ),
        'items_json'   => wp_json_encode( $items ),
        'total_amount' => $total,
        'memo'         => isset( $p['memo'] ) ? sanitize_textarea_field( $p['memo'] ) : '',
    );
    $now = current_time( 'mysql' );

    $is_new = ( $id <= 0 );
    if ( $id > 0 ) {
        $data['updated_at'] = $now;
        $updated = $wpdb->update( $table_name, $data, array( 'id' => $id ) );
        if ( false === $updated ) {
            return new WP_REST_Response( array( 'error' => '更新に失敗しました。' ), 500 );
        }
    } else {
        $data['invoice_no'] = mush_office_generate_invoice_no();
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $inserted = $wpdb->insert( $table_name, $data );
        if ( false === $inserted ) {
            return new WP_REST_Response( array( 'error' => '登録に失敗しました。', 'detail' => $wpdb->last_error ), 500 );
        }
        $id = $wpdb->insert_id;
    }

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    // 見積もりから作った請求書は、その見積もりにつながっている原価計算書に、請求書NOを(未設定なら)書き戻してつなぐ。
    if ( $row && ! empty( $row['quote_no'] ) ) {
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}mush_costs SET invoice_no = %s WHERE quote_no = %s AND ( invoice_no = '' OR invoice_no IS NULL )",
            $row['invoice_no'], $row['quote_no']
        ) );
    }
    mush_office_log_activity( $data['staff_name'], $is_new ? '請求書新規作成' : '請求書変更', $row ? $row['invoice_no'] : '' );
    return new WP_REST_Response( $row ? mush_office_format_invoice( $row ) : array( 'saved' => true ), 200 );
}

function mush_office_delete_invoice( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_invoices';
    $id = intval( $request['id'] );

    $deleted = $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );
    if ( false === $deleted ) {
        return new WP_REST_Response( array( 'error' => '削除に失敗しました。' ), 500 );
    }
    return new WP_REST_Response( array( 'deleted' => true ), 200 );
}

/**
 * ============================================================
 * 経理: 原価計算書関連
 * ============================================================
 * 見積もり・請求書と同じ構造(原価品目はitems_jsonにJSON配列)。
 * revenue_amount(売上金額)はフロント側で見積もり・請求書から
 * 引き継ぐか手入力し、粗利・粗利率はフロント側で都度計算して表示する。
 */
function mush_office_format_cost( $row ) {
    $items = json_decode( $row['items_json'], true );
    if ( ! is_array( $items ) ) {
        $items = array();
    }
    return array(
        'id'            => intval( $row['id'] ),
        'reportNo'      => $row['report_no'],
        'quoteNo'       => $row['quote_no'],
        'invoiceNo'     => $row['invoice_no'],
        'clientId'      => $row['client_id'] ? strval( $row['client_id'] ) : '',
        'clientName'    => $row['client_name'],
        'projectName'   => $row['project_name'],
        'staffId'       => $row['staff_id'] ? strval( $row['staff_id'] ) : '',
        'staffName'     => $row['staff_name'],
        'worker1Id'     => $row['worker1_id'] ? strval( $row['worker1_id'] ) : '',
        'worker1Name'   => $row['worker1_name'],
        'worker2Id'     => $row['worker2_id'] ? strval( $row['worker2_id'] ) : '',
        'worker2Name'   => $row['worker2_name'],
        'issueDate'     => $row['issue_date'],
        'revenueAmount' => intval( $row['revenue_amount'] ),
        'items'         => $items,
        'costTotal'     => intval( $row['cost_total'] ),
        'memo'          => $row['memo'],
        'createdAt'     => $row['created_at'],
        'updatedAt'     => $row['updated_at'],
    );
}

function mush_office_get_costs( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_costs';
    $rows = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY created_at DESC", ARRAY_A );
    if ( null === $rows ) {
        $rows = array();
    }
    return new WP_REST_Response( array_map( 'mush_office_format_cost', $rows ), 200 );
}

function mush_office_generate_report_no() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_costs';
    $prefix = 'C' . current_time( 'Ym' );

    $count = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_name} WHERE report_no LIKE %s",
        $wpdb->esc_like( $prefix ) . '%'
    ) );
    $seq = intval( $count ) + 1;
    return $prefix . '-' . str_pad( $seq, 3, '0', STR_PAD_LEFT );
}

function mush_office_upsert_cost( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_costs';

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }

    $id = isset( $p['id'] ) ? intval( $p['id'] ) : 0;

    $items_raw = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();
    $items     = array();
    $total     = 0;
    foreach ( $items_raw as $item ) {
        $qty    = isset( $item['quantity'] ) ? floatval( $item['quantity'] ) : 0;
        $price  = isset( $item['unitPrice'] ) ? floatval( $item['unitPrice'] ) : 0;
        $amount = round( $qty * $price ); // 明細の金額は円未満を四捨五入(単価は小数第2位まで入力できるため)
        $row_item = array(
            'name'      => isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '',
            'quantity'  => $qty,
            'unitPrice' => $price,
            'amount'    => $amount,
        );
        // 手入力した参考売値単価(任意品目用)。空欄なら保存せず、商品マスタの売値を使う。
        if ( isset( $item['refPrice'] ) && '' !== trim( strval( $item['refPrice'] ) ) ) {
            $row_item['refPrice'] = round( floatval( $item['refPrice'] ), 2 );
        }
        $items[] = $row_item;
        $total += $amount;
    }

    $data = array(
        'quote_no'       => isset( $p['quoteNo'] ) ? sanitize_text_field( $p['quoteNo'] ) : '',
        'invoice_no'     => isset( $p['invoiceNo'] ) ? sanitize_text_field( $p['invoiceNo'] ) : '',
        'client_id'      => ! empty( $p['clientId'] ) ? intval( $p['clientId'] ) : null,
        'client_name'    => isset( $p['clientName'] ) ? sanitize_text_field( $p['clientName'] ) : '',
        'project_name'   => isset( $p['projectName'] ) ? sanitize_text_field( $p['projectName'] ) : '',
        'staff_id'       => ! empty( $p['staffId'] ) ? intval( $p['staffId'] ) : null,
        'staff_name'     => isset( $p['staffName'] ) ? sanitize_text_field( $p['staffName'] ) : '',
        'worker1_id'     => ! empty( $p['worker1Id'] ) ? intval( $p['worker1Id'] ) : null,
        'worker1_name'   => isset( $p['worker1Name'] ) ? sanitize_text_field( $p['worker1Name'] ) : '',
        'worker2_id'     => ! empty( $p['worker2Id'] ) ? intval( $p['worker2Id'] ) : null,
        'worker2_name'   => isset( $p['worker2Name'] ) ? sanitize_text_field( $p['worker2Name'] ) : '',
        'issue_date'     => isset( $p['issueDate'] ) ? sanitize_text_field( $p['issueDate'] ) : current_time( 'Y-m-d' ),
        'revenue_amount' => isset( $p['revenueAmount'] ) ? intval( $p['revenueAmount'] ) : 0,
        'items_json'     => wp_json_encode( $items ),
        'cost_total'     => $total,
        'memo'           => isset( $p['memo'] ) ? sanitize_textarea_field( $p['memo'] ) : '',
    );
    $now    = current_time( 'mysql' );
    $is_new = ! ( $id > 0 );

    if ( $id > 0 ) {
        $data['updated_at'] = $now;
        $updated = $wpdb->update( $table_name, $data, array( 'id' => $id ) );
        if ( false === $updated ) {
            return new WP_REST_Response( array( 'error' => '更新に失敗しました。' ), 500 );
        }
    } else {
        $data['report_no']  = mush_office_generate_report_no();
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $inserted = $wpdb->insert( $table_name, $data );
        if ( false === $inserted ) {
            return new WP_REST_Response( array( 'error' => '登録に失敗しました。', 'detail' => $wpdb->last_error ), 500 );
        }
        $id = $wpdb->insert_id;
    }

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );
    mush_office_log_activity( $data['staff_name'], $is_new ? '原価計算書新規作成' : '原価計算書変更', $row ? $row['report_no'] : '' );
    return new WP_REST_Response( $row ? mush_office_format_cost( $row ) : array( 'saved' => true ), 200 );
}

function mush_office_delete_cost( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mush_costs';
    $id = intval( $request['id'] );

    $deleted = $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );
    if ( false === $deleted ) {
        return new WP_REST_Response( array( 'error' => '削除に失敗しました。' ), 500 );
    }
    return new WP_REST_Response( array( 'deleted' => true ), 200 );
}

/**
 * ============================================================
 * 全データバックアップ(ダウンロード・復元)関連
 * ============================================================
 * DBの生の行(スネークケースのカラム名そのまま)をテーブル単位でやり取りする。
 * 既存の一覧取得API(camelCase整形・一部フィルタ必須)を流用せず専用のエンドポイントに
 * しているのは、(1)チャットのようにstaffId必須のAPIがある、(2)employeesのpassword_hashなど
 * 復元に必要な列を漏れなく含める、という理由から。エクスポート・インポートで
 * 全く同じ列名を使うことで、変換ミスの起きようがない単純な作りにしている。
 */
function mush_office_backup_table_map() {
    global $wpdb;
    return array(
        'employees'     => $wpdb->prefix . 'mush_employees',
        'taskRecords'   => $wpdb->prefix . 'mush_task_records',
        'scheduleTasks' => $wpdb->prefix . 'mush_schedule_tasks',
        'timeCards'     => $wpdb->prefix . 'mush_time_cards',
        'chatMessages'  => $wpdb->prefix . 'mush_chat_messages',
        'dmMessages'    => $wpdb->prefix . 'mush_dm_messages',
        'clients'       => $wpdb->prefix . 'mush_clients',
        'products'      => $wpdb->prefix . 'mush_products',
        'quotes'        => $wpdb->prefix . 'mush_quotes',
        'invoices'      => $wpdb->prefix . 'mush_invoices',
        'costs'         => $wpdb->prefix . 'mush_costs',
        // 導入後に追加した機能のデータ(給与・勤怠・有給・年末調整・書類・お知らせ・画像の記録・操作ログ・料額表)。
        // 画像・書類のファイルそのものはExcelに入らないため、ファイルの場所(URL)の記録だけを保存する。
        'payslips'         => $wpdb->prefix . 'mush_payslips',
        'payslipHistory'   => $wpdb->prefix . 'mush_payslip_history',
        'attendanceDays'   => $wpdb->prefix . 'mush_attendance_days',
        'leaveGrants'      => $wpdb->prefix . 'mush_leave_grants',
        'yearEnd'          => $wpdb->prefix . 'mush_year_end',
        'yearEndDecl'      => $wpdb->prefix . 'mush_year_end_decl',
        'companyDocuments' => $wpdb->prefix . 'mush_company_documents',
        'announcements'    => $wpdb->prefix . 'mush_announcements',
        'taskImages'       => $wpdb->prefix . 'mush_task_images',
        'activityLog'      => $wpdb->prefix . 'mush_activity_log',
        'insuranceTables'  => $wpdb->prefix . 'mush_insurance_tables',
        'taxTables'        => $wpdb->prefix . 'mush_tax_tables',
        'dailyTaxTables'   => $wpdb->prefix . 'mush_daily_tax_tables',
        'bonusTaxTables'   => $wpdb->prefix . 'mush_bonus_tax_tables',
    );
}

// 表ではなく設定(wp_options)に保存している業務データ。バックアップの「設定」シートに入れる。
function mush_office_backup_option_keys() {
    return array( 'mush_office_company_info', 'mush_office_custom_departments' );
}

function mush_office_backup_export( WP_REST_Request $request ) {
    global $wpdb;
    $result = array();
    foreach ( mush_office_backup_table_map() as $key => $table ) {
        $rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
        $result[ $key ] = null === $rows ? array() : $rows;
    }
    $settings = array();
    foreach ( mush_office_backup_option_keys() as $i => $name ) {
        $value = get_option( $name, null );
        if ( null !== $value ) {
            $settings[] = array( 'id' => $i + 1, 'option_name' => $name, 'value_json' => wp_json_encode( $value ) );
        }
    }
    $result['settings'] = $settings;
    return new WP_REST_Response( $result, 200 );
}

// 復元: 全件置換方式。ファイルに含まれるテーブルだけを対象に、
// 既存データを全削除してからファイルの内容をそのまま入れ直す。
// (誤操作で消えることのないよう、実行前の確認・自動バックアップはフロント側で行う)
function mush_office_backup_restore( WP_REST_Request $request ) {
    global $wpdb;
    $data = $request->get_json_params();
    if ( empty( $data ) || ! is_array( $data ) ) {
        return new WP_REST_Response( array( 'error' => '復元データが空です。' ), 400 );
    }

    $restored = array();
    $errors   = array();
    foreach ( mush_office_backup_table_map() as $key => $table ) {
        if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
            continue; // ファイルにそのテーブルのシートがなければ、既存データはそのまま残す
        }
        $rows = $data[ $key ];

        $wpdb->query( "TRUNCATE TABLE {$table}" );

        $count = 0;
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['id'] ) || '' === $row['id'] ) {
                continue;
            }
            if ( 'payslips' === $key && ! array_key_exists( 'published_at', $row ) ) {
                // 「公開日時」ができる前のバックアップの明細は、そのとき従業員から見えていたので、公開済みとして戻す。
                $row['published_at'] = ! empty( $row['updated_at'] ) ? $row['updated_at'] : ( isset( $row['created_at'] ) ? $row['created_at'] : current_time( 'mysql' ) );
            }
            $inserted = $wpdb->insert( $table, $row );
            if ( false === $inserted ) {
                $errors[] = "{$key}: id={$row['id']} の復元に失敗しました({$wpdb->last_error})";
                continue;
            }
            $count++;
        }
        $restored[ $key ] = $count;
    }
    // 設定(自社情報・追加した部署)。ファイルに「設定」シートがあるときだけ置き換える。
    if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
        $count = 0;
        foreach ( $data['settings'] as $row ) {
            if ( ! is_array( $row ) || empty( $row['option_name'] ) || ! isset( $row['value_json'] ) ) {
                continue;
            }
            $name = strval( $row['option_name'] );
            if ( ! in_array( $name, mush_office_backup_option_keys(), true ) ) {
                continue;
            }
            $value = json_decode( strval( $row['value_json'] ), true );
            if ( null === $value ) {
                $errors[] = "settings: {$name} の復元に失敗しました(内容を読み取れません)";
                continue;
            }
            update_option( $name, $value );
            $count++;
        }
        $restored['settings'] = $count;
    }
    // 1.70.0以前のバックアップには平文のパスワードが入っているため、復元後にハッシュ化する。
    mush_office_hash_plain_login_passwords();

    return new WP_REST_Response( array( 'restored' => $restored, 'errors' => $errors ), 200 );
}

function mush_office_get_backup_status( WP_REST_Request $request ) {
    $last = get_option( 'mush_office_last_backup_download', '' );
    return new WP_REST_Response( array( 'lastDownloadAt' => '' !== $last ? $last : null ), 200 );
}

function mush_office_mark_backup_downloaded( WP_REST_Request $request ) {
    $now = current_time( 'mysql' );
    update_option( 'mush_office_last_backup_download', $now );
    return new WP_REST_Response( array( 'lastDownloadAt' => $now ), 200 );
}

// 業務データの全テーブルを空にする(従業員・日報・進捗・タイムカード・チャット・
// 顧客・商品・見積もり・請求書・原価計算書、すべて)。元に戻せないため、
// このエンドポイント自体は確認を行わない(確認・自動バックアップ・確認文字列入力は
// 必ずフロント側で済ませてから呼び出すこと)。
function mush_office_backup_delete_all( WP_REST_Request $request ) {
    global $wpdb;
    $deleted = array();
    // 全データ削除の対象は、これまでどおりの業務データだけ(給与・勤怠・有給・年末調整・料額表などのバックアップ対象は消さない)。
    $delete_keys = array( 'employees', 'taskRecords', 'scheduleTasks', 'timeCards', 'chatMessages', 'dmMessages', 'clients', 'products', 'quotes', 'invoices', 'costs' );
    foreach ( mush_office_backup_table_map() as $key => $table ) {
        if ( ! in_array( $key, $delete_keys, true ) ) {
            continue;
        }
        $wpdb->query( "TRUNCATE TABLE {$table}" );
        $deleted[] = $key;
    }
    $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mush_payslip_history" ); // 給与明細書の保存履歴も消す
    // 操作ログ自体は削除対象に含めていないため、この記録は残る。
    mush_office_log_activity( wp_get_current_user()->display_name, '全データ削除' );
    return new WP_REST_Response( array( 'deleted' => $deleted ), 200 );
}

/**
 * ============================================================
 * 自社情報(見積もり・請求書・原価計算書の発行元として印字する)
 * ============================================================
 * 1件しか存在しない設定情報なので、専用テーブルは作らずwp_optionsに
 * JSONとして保存する(バックアップ機能の対象外でよい設定値のため)。
 */
// 会社名だけを返す公開エンドポイント(未ログインでも表示するトップページの見出しなどに使う)。
// /company-infoは口座情報など機密性のある項目を含むためログイン必須だが、
// 会社名だけなら公開しても問題ないので、あえて別の軽量なエンドポイントに分けている。
function mush_office_get_company_name( WP_REST_Request $request ) {
    $info = get_option( 'mush_office_company_info', array() );
    $name = ( is_array( $info ) && isset( $info['name'] ) ) ? $info['name'] : '';
    return new WP_REST_Response( array( 'name' => $name ), 200 );
}

function mush_office_get_company_info( WP_REST_Request $request ) {
    $defaults = array(
        'name'              => '',
        'repName'           => '',
        'postalCode'        => '',
        'address'           => '',
        'phone'             => '',
        'fax'               => '',
        'website'           => '',
        'invoiceRegNumber'  => '',
        'bankName'          => '',
        'bankBranch'        => '',
        'bankAccountType'   => '',
        'bankAccountNumber' => '',
        'bankAccountHolder' => '',
        'bankName2'          => '',
        'bankBranch2'        => '',
        'bankAccountType2'   => '',
        'bankAccountNumber2' => '',
        'bankAccountHolder2' => '',
        'sealImageUrl'      => '',
        'sealSize'          => 44,
        'sealWhitened'      => false,
        'workStartTime'     => '09:00',
        'workEndTime'       => '18:00',
        'breakMinutes'      => 60,
        'timeRoundingMinutes' => 1,
        'overtimeRate'      => 25,
        'nightRate'         => 25,
        'holidayRate'       => 35,
        'employmentInsuranceIndustry' => 'general',
        'weeklyHolidays'    => array( 0 ),
        'holidayDates'      => array(),
        // 給与の締め日・支払日。締め日'末日'(デフォルト)は暦月そのまま(従来通りの挙動)。
        // 締め日を日数(1〜31)にすると、給与計算ページの対象年月は「その締め日を含む期間」の
        // 終了月として扱われ、勤怠集計期間が前月の翌日〜当月の締め日に切り替わる。
        'payrollCutoffDay'           => '末日',
        'payrollPaymentOffsetMonths' => 0, // 支払月: 0=当月, 1=翌月, 2=翌々月(締め日の期間終了月を基準)
        'payrollPaymentDay'          => '末日',
    );
    $saved = get_option( 'mush_office_company_info', array() );
    if ( ! is_array( $saved ) ) {
        $saved = array();
    }
    return new WP_REST_Response( array_merge( $defaults, $saved ), 200 );
}

// 曜日番号(0=日〜6=土)の配列に正規化する(範囲外・重複・非数値を除去)。
function mush_office_sanitize_weekly_holidays( $raw ) {
    if ( ! is_array( $raw ) ) {
        return array();
    }
    $out = array();
    foreach ( $raw as $v ) {
        $n = intval( $v );
        if ( $n >= 0 ) {
            if ( $n <= 6 ) {
                if ( ! in_array( $n, $out, true ) ) {
                    $out[] = $n;
                }
            }
        }
    }
    sort( $out );
    return $out;
}

// 給与の締め日・支払日の入力値を正規化する。'末日'ならそのまま、それ以外は1〜31の日数にクランプする。
function mush_office_sanitize_day_or_eom( $raw ) {
    $s = sanitize_text_field( (string) $raw );
    if ( '末日' === $s ) {
        return '末日';
    }
    $n = intval( $s );
    if ( $n < 1 ) {
        return '末日';
    }
    return (string) max( 1, min( 31, $n ) );
}

// "YYYY-MM-DD"形式の日付文字列の配列に正規化する(不正な形式は除去)。
function mush_office_sanitize_holiday_dates( $raw ) {
    if ( ! is_array( $raw ) ) {
        return array();
    }
    $out = array();
    foreach ( $raw as $v ) {
        $s = sanitize_text_field( $v );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ) ) {
            if ( ! in_array( $s, $out, true ) ) {
                $out[] = $s;
            }
        }
    }
    sort( $out );
    return $out;
}

// 指定した日付が、自社情報の休日設定(毎週の定休日 または 個別指定日)に該当するかを判定する。
function mush_office_is_company_holiday( $date_str ) {
    $info = get_option( 'mush_office_company_info', array() );
    if ( ! is_array( $info ) ) {
        return false;
    }
    $weekly = isset( $info['weeklyHolidays'] ) ? (array) $info['weeklyHolidays'] : array();
    $dates = isset( $info['holidayDates'] ) ? (array) $info['holidayDates'] : array();
    if ( in_array( $date_str, $dates, true ) ) {
        return true;
    }
    $timestamp = strtotime( $date_str );
    if ( false === $timestamp ) {
        return false;
    }
    $dow = intval( date( 'w', $timestamp ) );
    return in_array( $dow, array_map( 'intval', $weekly ), true );
}

function mush_office_save_company_info( WP_REST_Request $request ) {
    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }
    $info = array(
        'name'              => isset( $p['name'] ) ? sanitize_text_field( $p['name'] ) : '',
        'repName'           => isset( $p['repName'] ) ? sanitize_text_field( $p['repName'] ) : '',
        'postalCode'        => isset( $p['postalCode'] ) ? sanitize_text_field( $p['postalCode'] ) : '',
        'address'           => isset( $p['address'] ) ? sanitize_text_field( $p['address'] ) : '',
        'phone'             => isset( $p['phone'] ) ? sanitize_text_field( $p['phone'] ) : '',
        'fax'               => isset( $p['fax'] ) ? sanitize_text_field( $p['fax'] ) : '',
        'website'           => isset( $p['website'] ) ? sanitize_text_field( $p['website'] ) : '',
        'invoiceRegNumber'  => isset( $p['invoiceRegNumber'] ) ? sanitize_text_field( $p['invoiceRegNumber'] ) : '',
        'bankName'          => isset( $p['bankName'] ) ? sanitize_text_field( $p['bankName'] ) : '',
        'bankBranch'        => isset( $p['bankBranch'] ) ? sanitize_text_field( $p['bankBranch'] ) : '',
        'bankAccountType'   => isset( $p['bankAccountType'] ) ? sanitize_text_field( $p['bankAccountType'] ) : '',
        'bankAccountNumber' => isset( $p['bankAccountNumber'] ) ? sanitize_text_field( $p['bankAccountNumber'] ) : '',
        'bankAccountHolder' => isset( $p['bankAccountHolder'] ) ? sanitize_text_field( $p['bankAccountHolder'] ) : '',
        'bankName2'          => isset( $p['bankName2'] ) ? sanitize_text_field( $p['bankName2'] ) : '',
        'bankBranch2'        => isset( $p['bankBranch2'] ) ? sanitize_text_field( $p['bankBranch2'] ) : '',
        'bankAccountType2'   => isset( $p['bankAccountType2'] ) ? sanitize_text_field( $p['bankAccountType2'] ) : '',
        'bankAccountNumber2' => isset( $p['bankAccountNumber2'] ) ? sanitize_text_field( $p['bankAccountNumber2'] ) : '',
        'bankAccountHolder2' => isset( $p['bankAccountHolder2'] ) ? sanitize_text_field( $p['bankAccountHolder2'] ) : '',
        // 社印画像は専用の/company-sealエンドポイント(アップロード)から設定するが、
        // 通常保存でも上書きで消えないよう、送られてきた値をそのまま保持する。
        'sealImageUrl'      => isset( $p['sealImageUrl'] ) ? esc_url_raw( $p['sealImageUrl'] ) : '',
        'sealSize'          => isset( $p['sealSize'] ) ? max( 20, min( 120, intval( $p['sealSize'] ) ) ) : 44,
        'sealWhitened'      => ! empty( $p['sealWhitened'] ),
        'workStartTime'     => isset( $p['workStartTime'] ) ? sanitize_text_field( $p['workStartTime'] ) : '09:00',
        'workEndTime'       => isset( $p['workEndTime'] ) ? sanitize_text_field( $p['workEndTime'] ) : '18:00',
        // 休憩時間(分)。6時間を超えて働いた日は、給与計算でこの分を実働から差し引く。
        'breakMinutes'      => isset( $p['breakMinutes'] ) && '' !== $p['breakMinutes'] ? max( 0, min( 480, intval( $p['breakMinutes'] ) ) ) : 60,
        // 勤怠の時刻の丸め単位(1〜30分)。1 = 丸めない。給与計算で、出勤は切り上げ・退勤は切り捨てる。
        'timeRoundingMinutes' => isset( $p['timeRoundingMinutes'] ) && '' !== $p['timeRoundingMinutes'] ? max( 1, min( 30, intval( $p['timeRoundingMinutes'] ) ) ) : 1,
        'overtimeRate'      => isset( $p['overtimeRate'] ) ? floatval( $p['overtimeRate'] ) : 25,
        'nightRate'         => isset( $p['nightRate'] ) ? floatval( $p['nightRate'] ) : 25,
        'holidayRate'       => isset( $p['holidayRate'] ) ? floatval( $p['holidayRate'] ) : 35,
        'employmentInsuranceIndustry' => in_array( isset( $p['employmentInsuranceIndustry'] ) ? $p['employmentInsuranceIndustry'] : '', array( 'general', 'agriculture', 'construction' ), true )
            ? $p['employmentInsuranceIndustry']
            : 'general',
        'weeklyHolidays'    => mush_office_sanitize_weekly_holidays( isset( $p['weeklyHolidays'] ) ? $p['weeklyHolidays'] : array() ),
        'holidayDates'      => mush_office_sanitize_holiday_dates( isset( $p['holidayDates'] ) ? $p['holidayDates'] : array() ),
        'payrollCutoffDay'           => isset( $p['payrollCutoffDay'] ) ? mush_office_sanitize_day_or_eom( $p['payrollCutoffDay'] ) : '末日',
        'payrollPaymentOffsetMonths' => isset( $p['payrollPaymentOffsetMonths'] ) ? max( 0, min( 2, intval( $p['payrollPaymentOffsetMonths'] ) ) ) : 0,
        'payrollPaymentDay'          => isset( $p['payrollPaymentDay'] ) ? mush_office_sanitize_day_or_eom( $p['payrollPaymentDay'] ) : '末日',
    );
    $existing = get_option( 'mush_office_company_info', array() );
    $is_new   = empty( $existing ) || ! is_array( $existing );
    update_option( 'mush_office_company_info', $info );
    mush_office_log_activity( wp_get_current_user()->display_name, $is_new ? '自社情報を登録' : '自社情報を変更', $info['name'] );
    return new WP_REST_Response( $info, 200 );
}

/**
 * ============================================================
 * 有休付与・勤怠区分・給与明細書
 * ============================================================
 */

function mush_office_get_leave_grants( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mush_leave_grants';
    $staff_id = intval( $request->get_param( 'staffId' ) );
    if ( ! $staff_id ) {
        return new WP_REST_Response( array( 'error' => 'staffIdを指定してください。' ), 400 );
    }
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE staff_id = %d ORDER BY grant_date DESC, id DESC", $staff_id ), ARRAY_A );
    return new WP_REST_Response( $rows ? $rows : array(), 200 );
}

function mush_office_create_leave_grant( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mush_leave_grants';
    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }
    $staff_id = isset( $p['staffId'] ) ? intval( $p['staffId'] ) : 0;
    $grant_date = isset( $p['grantDate'] ) ? sanitize_text_field( $p['grantDate'] ) : '';
    $days = isset( $p['days'] ) ? floatval( $p['days'] ) : 0;
    $note = isset( $p['note'] ) ? sanitize_text_field( $p['note'] ) : '';
    if ( ! $staff_id || ! $grant_date || $days <= 0 ) {
        return new WP_REST_Response( array( 'error' => '従業員・付与日・日数を入力してください。' ), 400 );
    }
    $inserted = $wpdb->insert( $table, array(
        'staff_id'   => $staff_id,
        'grant_date' => $grant_date,
        'days'       => $days,
        'note'       => $note,
        'created_at' => current_time( 'mysql' ),
    ) );
    if ( false === $inserted ) {
        return new WP_REST_Response( array( 'error' => '登録に失敗しました。' ), 500 );
    }
    return new WP_REST_Response( array( 'id' => $wpdb->insert_id ), 201 );
}

function mush_office_delete_leave_grant( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mush_leave_grants';
    $id = intval( $request['id'] );
    $deleted = $wpdb->delete( $table, array( 'id' => $id ) );
    if ( false === $deleted ) {
        return new WP_REST_Response( array( 'error' => '削除に失敗しました。' ), 500 );
    }
    return new WP_REST_Response( array( 'deleted' => true ), 200 );
}

/**
 * ============================================================
 * 有給休暇: 付与・消化の台帳計算(2年時効・年5日取得義務)
 * ============================================================
 * 有給休暇は労働基準法上、付与日から2年で時効消滅する。単純な「付与合計-消化合計」ではなく、
 * 古い付与から順に消化を割り当てる(先入れ先出し)台帳形式で計算し、期限切れ分は残日数から除外する。
 */

// 労働基準法の原則的な付与日数表(週5日以上・週30時間以上勤務の通常の労働者向け)。
// 短時間労働者の比例付与には対応していない(必要な項目がまだ従業員マスタに無いため)。
function mush_office_legal_grant_table() {
    return array(
        array( 'months' => 6,  'days' => 10 ),
        array( 'months' => 18, 'days' => 11 ),
        array( 'months' => 30, 'days' => 12 ),
        array( 'months' => 42, 'days' => 14 ),
        array( 'months' => 54, 'days' => 16 ),
        array( 'months' => 66, 'days' => 18 ),
        array( 'months' => 78, 'days' => 20 ),
    );
}

// 入社日から起算して、指定日(通常は付与予定日)時点で法定上何日付与すべきかの目安を返す。
// 入社6ヶ月未満、または入社日が未設定の場合はnull(まだ付与対象外、または判定不可)。
function mush_office_standard_grant_days( $hire_date, $as_of_date ) {
    if ( empty( $hire_date ) ) {
        return null;
    }
    $hire_ts = strtotime( $hire_date );
    $as_of_ts = strtotime( $as_of_date );
    if ( false === $hire_ts ) {
        return null;
    }
    if ( false === $as_of_ts ) {
        return null;
    }
    if ( $as_of_ts < $hire_ts ) {
        return null;
    }
    $hire_dt = new DateTime( $hire_date );
    $as_of_dt = new DateTime( $as_of_date );
    $diff = $hire_dt->diff( $as_of_dt );
    $months = ( $diff->y * 12 ) + $diff->m;
    $applicable = null;
    foreach ( mush_office_legal_grant_table() as $row ) {
        if ( $months >= $row['months'] ) {
            $applicable = $row['days'];
        }
    }
    return $applicable;
}

// 付与(古い順)に消化日(古い順)を先入れ先出しで割り当て、2年時効・残日数・直近の失効予定をまとめて返す。
function mush_office_compute_leave_ledger( $staff_id, $as_of ) {
    global $wpdb;
    $grant_table = $wpdb->prefix . 'mush_leave_grants';
    $attendance_table = $wpdb->prefix . 'mush_attendance_days';

    $grant_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT grant_date, days FROM {$grant_table} WHERE staff_id = %d AND grant_date <= %s ORDER BY grant_date ASC",
        $staff_id, $as_of
    ), ARRAY_A );
    $usage_dates = $wpdb->get_col( $wpdb->prepare(
        "SELECT record_date FROM {$attendance_table} WHERE staff_id = %d AND day_type = '有休' AND record_date <= %s ORDER BY record_date ASC",
        $staff_id, $as_of
    ) );

    $ledger = array();
    foreach ( (array) $grant_rows as $g ) {
        $ledger[] = array(
            'grantDate' => $g['grant_date'],
            'days'      => floatval( $g['days'] ),
            'remaining' => floatval( $g['days'] ),
            'expiry'    => date( 'Y-m-d', strtotime( $g['grant_date'] . ' +2 years' ) ),
        );
    }

    $used_details = array(); // 有休を使った日ごとの内訳(画面の「有休消化日数」の詳細に出す)
    foreach ( (array) $usage_dates as $u ) {
        $from_grant = '';
        foreach ( $ledger as &$g ) {
            if ( $g['remaining'] <= 0 ) {
                continue;
            }
            if ( $g['grantDate'] > $u ) {
                continue;
            }
            if ( $g['expiry'] <= $u ) {
                continue; // 消化日の時点で既に失効している付与からは消化できない
            }
            $g['remaining'] -= 1;
            $from_grant = $g['grantDate'];
            break;
        }
        unset( $g );
        $used_details[] = array( 'date' => $u, 'grantDate' => $from_grant );
    }

    $granted_total = 0;
    $expired_total = 0;
    $remaining_total = 0;
    $next_expiry = null;
    foreach ( $ledger as $g ) {
        $granted_total += $g['days'];
        if ( $g['expiry'] <= $as_of ) {
            $expired_total += max( 0, $g['remaining'] );
        } else {
            $remaining_total += max( 0, $g['remaining'] );
            if ( $g['remaining'] > 0 ) {
                if ( null === $next_expiry ) {
                    $next_expiry = array( 'date' => $g['expiry'], 'days' => $g['remaining'] );
                } else if ( $g['expiry'] < $next_expiry['date'] ) {
                    $next_expiry = array( 'date' => $g['expiry'], 'days' => $g['remaining'] );
                }
            }
        }
    }

    return array(
        'granted'    => $granted_total,
        'used'       => count( (array) $usage_dates ),
        'usedDetails' => $used_details,
        'expired'    => $expired_total,
        'remaining'  => $remaining_total,
        'nextExpiry' => $next_expiry,
    );
}

// 有休残日数(2年時効を反映)。asOf(YYYY-MM-DD)を指定すると、その日時点の残日数を出す
// (明細書の対象月時点の残日数を出すため)。
function mush_office_get_leave_balance( WP_REST_Request $request ) {
    global $wpdb;
    $staff_id = intval( $request['staffId'] );
    $as_of = sanitize_text_field( $request->get_param( 'asOf' ) );
    if ( '' === $as_of ) {
        $as_of = current_time( 'Y-m-d' );
    }
    $ledger = mush_office_compute_leave_ledger( $staff_id, $as_of );

    $emp_table = $wpdb->prefix . 'mush_employees';
    $hire_date = $wpdb->get_var( $wpdb->prepare( "SELECT hire_date FROM {$emp_table} WHERE id = %d", $staff_id ) );
    $ledger['standardGrantDays'] = mush_office_standard_grant_days( $hire_date, $as_of );
    $ledger['hireDate'] = $hire_date ? $hire_date : '';

    return new WP_REST_Response( $ledger, 200 );
}

// 付与日数が10日以上の付与について、付与日から1年以内に5日以上消化しているかを判定する
// (労働基準法の「年5日取得義務」)。status: ok(達成済み) / warning(未達だが期限内) / violated(期限超過で未達)。
function mush_office_check_five_day_obligations( $staff_id ) {
    global $wpdb;
    $grant_table = $wpdb->prefix . 'mush_leave_grants';
    $attendance_table = $wpdb->prefix . 'mush_attendance_days';

    $grants = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, grant_date, days FROM {$grant_table} WHERE staff_id = %d AND days >= 10 ORDER BY grant_date ASC",
        $staff_id
    ), ARRAY_A );

    $today = current_time( 'Y-m-d' );
    $out = array();
    foreach ( (array) $grants as $g ) {
        $deadline = date( 'Y-m-d', strtotime( $g['grant_date'] . ' +1 year' ) );
        $used = intval( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$attendance_table} WHERE staff_id = %d AND day_type = '有休' AND record_date >= %s AND record_date < %s",
            $staff_id, $g['grant_date'], $deadline
        ) ) );
        $shortfall = max( 0, 5 - $used );
        $status = 'ok';
        if ( $shortfall > 0 ) {
            $status = ( $deadline < $today ) ? 'violated' : 'warning';
        }
        $out[] = array(
            'grantDate' => $g['grant_date'],
            'days'      => floatval( $g['days'] ),
            'deadline'  => $deadline,
            'used'      => $used,
            'shortfall' => $shortfall,
            'status'    => $status,
        );
    }
    return $out;
}

function mush_office_get_five_day_check( WP_REST_Request $request ) {
    $staff_id = intval( $request['staffId'] );
    return new WP_REST_Response( mush_office_check_five_day_obligations( $staff_id ), 200 );
}

// 全従業員のうち、年5日取得義務が未達(警告・違反)の付与だけをまとめて返す(管理者画面の一覧表示用)。
// 有給取得一覧ページ用。「その他」部門(非表示)を除く全従業員について、本日時点の付与合計・取得済み・
// 時効消滅・残日数と、取得した日・今後の取得予定日(勤怠で「有休」になっている明日以降の日)・付与履歴・
// 年5日取得義務の状況を返す。
function mush_yokose_get_leave_summary( WP_REST_Request $request ) {
    global $wpdb;
    $emp_table   = $wpdb->prefix . 'mush_employees';
    $grant_table = $wpdb->prefix . 'mush_leave_grants';
    $att_table   = $wpdb->prefix . 'mush_attendance_days';
    $today = current_time( 'Y-m-d' );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, employee_number, name, department, hire_date FROM {$emp_table} WHERE department <> %s ORDER BY employee_number ASC",
        MUSH_OFFICE_HIDDEN_DEPARTMENT
    ), ARRAY_A );
    $out = array();
    foreach ( (array) $rows as $r ) {
        $sid = intval( $r['id'] );
        $ledger = mush_office_compute_leave_ledger( $sid, $today );
        $planned = $wpdb->get_col( $wpdb->prepare(
            "SELECT record_date FROM {$att_table} WHERE staff_id = %d AND day_type = '有休' AND record_date > %s ORDER BY record_date ASC",
            $sid, $today
        ) );
        $grants = $wpdb->get_results( $wpdb->prepare(
            "SELECT grant_date, days, note FROM {$grant_table} WHERE staff_id = %d ORDER BY grant_date DESC",
            $sid
        ), ARRAY_A );
        $out[] = array(
            'staffId'      => $sid,
            'employeeNo'   => intval( $r['employee_number'] ),
            'name'         => $r['name'],
            'department'   => $r['department'],
            'hireDate'     => $r['hire_date'] ? $r['hire_date'] : '',
            'granted'      => $ledger['granted'],
            'used'         => $ledger['used'],
            'usedDetails'  => $ledger['usedDetails'],
            'expired'      => $ledger['expired'],
            'remaining'    => $ledger['remaining'],
            'nextExpiry'   => $ledger['nextExpiry'],
            'plannedDates' => array_values( (array) $planned ),
            'grants'       => array_map( function ( $g ) {
                return array(
                    'grantDate' => $g['grant_date'],
                    'days'      => floatval( $g['days'] ),
                    'note'      => $g['note'],
                    'expiry'    => date( 'Y-m-d', strtotime( $g['grant_date'] . ' +2 years' ) ),
                );
            }, (array) $grants ),
            'fiveDayCheck' => mush_office_check_five_day_obligations( $sid ),
        );
    }
    return new WP_REST_Response( array( 'asOf' => $today, 'staff' => $out ), 200 );
}

function mush_office_get_five_day_warnings( WP_REST_Request $request ) {
    global $wpdb;
    $emp_table = $wpdb->prefix . 'mush_employees';
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name FROM {$emp_table} WHERE department <> %s ORDER BY employee_number ASC", MUSH_OFFICE_HIDDEN_DEPARTMENT ), ARRAY_A );

    $out = array();
    foreach ( (array) $rows as $r ) {
        $checks = mush_office_check_five_day_obligations( intval( $r['id'] ) );
        foreach ( $checks as $c ) {
            if ( 'ok' !== $c['status'] ) {
                $c['staffId'] = intval( $r['id'] );
                $c['staffName'] = $r['name'];
                $out[] = $c;
            }
        }
    }
    return new WP_REST_Response( $out, 200 );
}

// 従業員本人が自分の有休残日数・付与履歴・年5日取得義務の状況を確認する(ログインしていれば誰でも、
// 自分自身の分だけを取得できる。他人のstaffIdを指定させない = トークンから取得したIDを使う)。
function mush_office_get_my_leave_balance( WP_REST_Request $request ) {
    global $wpdb;
    $staff_id = mush_office_get_bearer_staff_id( $request );
    if ( null === $staff_id ) {
        return new WP_REST_Response( array( 'error' => 'ログインが必要です。' ), 401 );
    }
    if ( 0 === $staff_id ) {
        return new WP_REST_Response( array( 'error' => '共通管理者パスワードでのログインでは利用できません。' ), 400 );
    }
    $as_of = current_time( 'Y-m-d' );
    $ledger = mush_office_compute_leave_ledger( $staff_id, $as_of );

    $emp_table = $wpdb->prefix . 'mush_employees';
    $emp = $wpdb->get_row( $wpdb->prepare( "SELECT name, department, hire_date FROM {$emp_table} WHERE id = %d", $staff_id ), ARRAY_A );
    $ledger['hireDate'] = $emp && $emp['hire_date'] ? $emp['hire_date'] : '';
    $ledger['staffName'] = $emp ? $emp['name'] : '';
    $ledger['department'] = $emp ? $emp['department'] : '';

    $grant_table = $wpdb->prefix . 'mush_leave_grants';
    $grants = $wpdb->get_results( $wpdb->prepare(
        "SELECT grant_date, days, note FROM {$grant_table} WHERE staff_id = %d ORDER BY grant_date DESC",
        $staff_id
    ), ARRAY_A );
    $ledger['grants'] = array_map( function ( $g ) {
        return array(
            'grantDate' => $g['grant_date'],
            'days'      => floatval( $g['days'] ),
            'note'      => $g['note'],
            'expiry'    => date( 'Y-m-d', strtotime( $g['grant_date'] . ' +2 years' ) ),
        );
    }, (array) $grants );

    $ledger['fiveDayCheck'] = mush_office_check_five_day_obligations( $staff_id );

    return new WP_REST_Response( $ledger, 200 );
}

// 指定した年月の全日について、勤怠区分・出退勤時刻を返す。
// mush_attendance_daysに保存済みの日はその内容を、未保存の日はmush_time_cardsの打刻から
// 「通常」区分で補完する(打刻も無ければ未区分の空欄のまま = 所定就労日には数えない)。
function mush_office_get_attendance_month( WP_REST_Request $request ) {
    global $wpdb;
    $staff_id = intval( $request->get_param( 'staffId' ) );
    if ( ! $staff_id ) {
        return new WP_REST_Response( array( 'error' => 'staffIdを指定してください。' ), 400 );
    }

    // 給与計算ページで締め日設定(自社情報)により月をまたぐ期間(例: 前月21日〜当月20日)を
    // 指定された場合は、periodStart/periodEndをそのまま使う。指定が無ければ従来通り
    // year・monthから暦月の範囲を組み立てる(後方互換)。
    $period_start_param = sanitize_text_field( (string) $request->get_param( 'periodStart' ) );
    $period_end_param   = sanitize_text_field( (string) $request->get_param( 'periodEnd' ) );
    $has_custom_period  = (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $period_start_param )
        && (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $period_end_param );

    if ( $has_custom_period ) {
        $month_start = $period_start_param;
        $month_end   = $period_end_param;
    } else {
        $year  = intval( $request->get_param( 'year' ) );
        $month = intval( $request->get_param( 'month' ) );
        if ( ! $year || ! $month ) {
            return new WP_REST_Response( array( 'error' => 'staffId・year・monthを指定してください。' ), 400 );
        }
        $month_start   = sprintf( '%04d-%02d-01', $year, $month );
        $days_in_month = intval( date( 't', strtotime( $month_start ) ) );
        $month_end     = sprintf( '%04d-%02d-%02d', $year, $month, $days_in_month );
    }

    $attendance_table = $wpdb->prefix . 'mush_attendance_days';
    $tc_table = $wpdb->prefix . 'mush_time_cards';

    $saved_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT record_date, day_type, clock_in, clock_out, early_minutes, overtime_minutes FROM {$attendance_table} WHERE staff_id = %d AND record_date BETWEEN %s AND %s",
        $staff_id, $month_start, $month_end
    ), ARRAY_A );
    $saved_by_date = array();
    foreach ( (array) $saved_rows as $r ) {
        $saved_by_date[ $r['record_date'] ] = $r;
    }

    $tc_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT record_date, clock_in, clock_out FROM {$tc_table} WHERE staff_id = %d AND record_date BETWEEN %s AND %s",
        $staff_id, $month_start, $month_end
    ), ARRAY_A );
    $tc_by_date = array();
    foreach ( (array) $tc_rows as $r ) {
        $tc_by_date[ $r['record_date'] ] = $r;
    }

    $out = array();
    $cursor = strtotime( $month_start );
    $end_ts = strtotime( $month_end );
    while ( $cursor <= $end_ts ) {
        $date = date( 'Y-m-d', $cursor );
        $is_holiday = mush_office_is_company_holiday( $date );
        if ( isset( $saved_by_date[ $date ] ) ) {
            $row = $saved_by_date[ $date ];
            $out[] = array(
                'date'      => $date,
                'dayType'   => $row['day_type'],
                'clockIn'   => $row['clock_in'],
                'clockOut'  => $row['clock_out'],
                // 早出・残業の分数。null = 手で変えていない(画面側で自社情報の始業・終業時刻から自動で出す)。
                'earlyMinutes'    => null === $row['early_minutes'] ? null : intval( $row['early_minutes'] ),
                'overtimeMinutes' => null === $row['overtime_minutes'] ? null : intval( $row['overtime_minutes'] ),
                'isHoliday' => $is_holiday,
            );
        } else if ( isset( $tc_by_date[ $date ] ) && '' !== $tc_by_date[ $date ]['clock_in'] ) {
            // 未確定(mush_attendance_daysに保存されていない)日で、かつ打刻がある場合。
            // 休日設定に該当する日は「休日出勤」を提案し、それ以外は従来通り「通常」を提案する。
            $out[] = array(
                'date'      => $date,
                'dayType'   => $is_holiday ? '休日出勤' : '通常',
                'clockIn'   => $tc_by_date[ $date ]['clock_in'],
                'clockOut'  => $tc_by_date[ $date ]['clock_out'],
                'earlyMinutes'    => null,
                'overtimeMinutes' => null,
                'isHoliday' => $is_holiday,
            );
        } else {
            $out[] = array( 'date' => $date, 'dayType' => '', 'clockIn' => '', 'clockOut' => '', 'earlyMinutes' => null, 'overtimeMinutes' => null, 'isHoliday' => $is_holiday );
        }
        $cursor = strtotime( '+1 day', $cursor );
    }
    return new WP_REST_Response( $out, 200 );
}

function mush_office_save_attendance_month( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mush_attendance_days';
    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }
    $staff_id = isset( $p['staffId'] ) ? intval( $p['staffId'] ) : 0;
    $days = isset( $p['days'] ) && is_array( $p['days'] ) ? $p['days'] : array();
    if ( ! $staff_id || empty( $days ) ) {
        return new WP_REST_Response( array( 'error' => 'staffId・daysを指定してください。' ), 400 );
    }
    $now = current_time( 'mysql' );
    foreach ( $days as $d ) {
        $date = isset( $d['date'] ) ? sanitize_text_field( $d['date'] ) : '';
        if ( '' === $date ) {
            continue;
        }
        $day_type = isset( $d['dayType'] ) ? sanitize_text_field( $d['dayType'] ) : '';
        $clock_in = isset( $d['clockIn'] ) ? sanitize_text_field( $d['clockIn'] ) : '';
        $clock_out = isset( $d['clockOut'] ) ? sanitize_text_field( $d['clockOut'] ) : '';
        if ( '' === $day_type ) {
            // 未区分に戻す場合は行ごと削除し、タイムカードの打刻補完に任せる。
            $wpdb->delete( $table, array( 'staff_id' => $staff_id, 'record_date' => $date ) );
            continue;
        }
        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE staff_id = %d AND record_date = %s",
            $staff_id, $date
        ) );
        $row = array(
            'staff_id'    => $staff_id,
            'record_date' => $date,
            'day_type'    => $day_type,
            'clock_in'    => $clock_in,
            'clock_out'   => $clock_out,
            // 手で変えた早出・残業の分数だけを保存する(送られてこない・空のときはnull = 自動)。
            'early_minutes'    => ( isset( $d['earlyMinutes'] ) && '' !== $d['earlyMinutes'] && null !== $d['earlyMinutes'] ) ? max( 0, intval( $d['earlyMinutes'] ) ) : null,
            'overtime_minutes' => ( isset( $d['overtimeMinutes'] ) && '' !== $d['overtimeMinutes'] && null !== $d['overtimeMinutes'] ) ? max( 0, intval( $d['overtimeMinutes'] ) ) : null,
            'updated_at'  => $now,
        );
        if ( $existing_id ) {
            $wpdb->update( $table, $row, array( 'id' => intval( $existing_id ) ) );
        } else {
            $row['created_at'] = $now;
            $wpdb->insert( $table, $row );
        }
    }
    return new WP_REST_Response( array( 'saved' => true ), 200 );
}

// 指定した従業員の、指定した年の指定月より前(同年1月〜前月)までの給与明細書を合算し、
// 明細書の「課税対象累計額」「総支給累計額」を出すための下地を返す。
function mush_office_get_payslips( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mush_payslips';
    $staff_id = intval( $request->get_param( 'staffId' ) );
    $year = intval( $request->get_param( 'year' ) );
    $before_month = intval( $request->get_param( 'beforeMonth' ) );
    if ( ! $staff_id || ! $year ) {
        return new WP_REST_Response( array( 'error' => 'staffId・yearを指定してください。' ), 400 );
    }
    if ( $before_month > 0 ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pay_month, data_json FROM {$table} WHERE staff_id = %d AND pay_year = %d AND pay_month < %d ORDER BY pay_month ASC",
            $staff_id, $year, $before_month
        ), ARRAY_A );
    } else {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pay_month, data_json FROM {$table} WHERE staff_id = %d AND pay_year = %d ORDER BY pay_month ASC",
            $staff_id, $year
        ), ARRAY_A );
    }
    $out = array();
    foreach ( (array) $rows as $r ) {
        $out[] = array( 'payMonth' => intval( $r['pay_month'] ), 'data' => json_decode( $r['data_json'], true ) );
    }
    return new WP_REST_Response( $out, 200 );
}

/**
 * 年末調整(従業員×年で1件)
 * 入力した控除・計算結果(data)と、給与明細書に反映する過不足額(adjustment: 正=徴収、負=還付)・反映月(applyMonth)を保存する。
 */
function mush_office_year_end_row_out( $row, $with_data ) {
    $out = array(
        'staffId'    => intval( $row['staff_id'] ),
        'staffName'  => $row['staff_name'],
        'year'       => intval( $row['adjust_year'] ),
        'applyMonth' => intval( $row['apply_month'] ),
        'adjustment' => intval( $row['adjustment'] ),
        'updatedBy'  => $row['updated_by'],
        'updatedAt'  => $row['updated_at'],
    );
    if ( $with_data ) {
        $out['data'] = json_decode( strval( $row['data_json'] ), true );
    }
    return $out;
}

function mush_office_list_year_end( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mush_year_end';
    $year  = intval( $request->get_param( 'year' ) );
    if ( ! $year ) {
        return new WP_REST_Response( array( 'error' => 'yearを指定してください。' ), 400 );
    }
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE adjust_year = %d ORDER BY staff_id ASC", $year ), ARRAY_A );
    $out  = array();
    foreach ( (array) $rows as $r ) {
        $out[] = mush_office_year_end_row_out( $r, false );
    }
    return new WP_REST_Response( $out, 200 );
}

function mush_office_get_year_end( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mush_year_end';
    $row   = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE staff_id = %d AND adjust_year = %d",
        intval( $request['staffId'] ), intval( $request['year'] )
    ), ARRAY_A );
    return new WP_REST_Response( $row ? mush_office_year_end_row_out( $row, true ) : false, 200 );
}

function mush_office_save_year_end( WP_REST_Request $request ) {
    global $wpdb;
    $table    = $wpdb->prefix . 'mush_year_end';
    $staff_id = intval( $request['staffId'] );
    $year     = intval( $request['year'] );
    $p        = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }
    if ( ! $staff_id || $year < 2000 || $year > 2100 ) {
        return new WP_REST_Response( array( 'error' => '従業員と年を指定してください。' ), 400 );
    }
    $apply_month = isset( $p['applyMonth'] ) ? intval( $p['applyMonth'] ) : 12;
    if ( $apply_month < 1 || $apply_month > 12 ) {
        $apply_month = 12;
    }
    $actor = mush_office_request_actor( $request );
    $now   = current_time( 'mysql' );
    $row   = array(
        'staff_id'    => $staff_id,
        'staff_name'  => isset( $p['staffName'] ) ? sanitize_text_field( $p['staffName'] ) : '',
        'adjust_year' => $year,
        'apply_month' => $apply_month,
        'adjustment'  => isset( $p['adjustment'] ) ? intval( $p['adjustment'] ) : 0,
        'data_json'   => wp_json_encode( isset( $p['data'] ) ? $p['data'] : array() ),
        'updated_by'  => $actor['name'],
        'updated_at'  => $now,
    );
    $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE staff_id = %d AND adjust_year = %d", $staff_id, $year ) );
    if ( $existing ) {
        $wpdb->update( $table, $row, array( 'id' => intval( $existing ) ) );
    } else {
        $row['created_at'] = $now;
        $wpdb->insert( $table, $row );
    }
    mush_office_log_activity( $actor['name'], '年末調整を保存', $row['staff_name'], $year . '年分' );
    $saved = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE staff_id = %d AND adjust_year = %d", $staff_id, $year ), ARRAY_A );
    return new WP_REST_Response( $saved ? mush_office_year_end_row_out( $saved, true ) : array( 'saved' => true ), 200 );
}

function mush_office_delete_year_end( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mush_year_end';
    $staff_id = intval( $request['staffId'] );
    $year     = intval( $request['year'] );
    $wpdb->delete( $table, array( 'staff_id' => $staff_id, 'adjust_year' => $year ), array( '%d', '%d' ) );
    $actor = mush_office_request_actor( $request );
    mush_office_log_activity( $actor['name'], '年末調整を削除', '', $year . '年分' );
    return new WP_REST_Response( array( 'deleted' => true ), 200 );
}

/**
 * 年末調整の申告(従業員本人が入力)
 * 入力できる項目は下の一覧だけに絞り、数字の項目は数字以外を取り除いて保存する。
 */
function mush_office_year_end_decl_sanitize( $raw ) {
    $raw     = is_array( $raw ) ? $raw : array();
    $numeric = array(
        'prevWages', 'prevSocial', 'prevWithheld', 'extraSocial',
        'lifeNewGeneral', 'lifeNewCare', 'lifeNewPension', 'lifeOldGeneral', 'lifeOldPension',
        'quake', 'quakeOld', 'smallBiz', 'spouseIncome',
        'depGeneral', 'depSpecial', 'depElderly', 'depElderlyLive', 'depSpecialRelative', 'depUnder16',
        'depDisabled', 'depSpecialDisabled', 'depLiveSpecialDisabled', 'housingLoan',
    );
    $choices = array(
        'spouse'         => array( 'none', 'yes' ),
        'selfDisability' => array( 'none', 'general', 'special' ),
        'single'         => array( 'none', 'singleParent', 'widow' ),
    );
    $out = array();
    foreach ( $numeric as $k ) {
        $v = isset( $raw[ $k ] ) ? preg_replace( '/[^0-9]/', '', strval( $raw[ $k ] ) ) : '';
        $out[ $k ] = substr( $v, 0, 12 );
    }
    foreach ( $choices as $k => $allowed ) {
        $v = isset( $raw[ $k ] ) ? strval( $raw[ $k ] ) : $allowed[0];
        $out[ $k ] = in_array( $v, $allowed, true ) ? $v : $allowed[0];
    }
    $out['spouseElderly'] = ! empty( $raw['spouseElderly'] );
    $out['student']       = ! empty( $raw['student'] );
    $out['memo']          = isset( $raw['memo'] ) ? mb_substr( sanitize_textarea_field( strval( $raw['memo'] ) ), 0, 1000 ) : '';
    return $out;
}

function mush_office_year_end_decl_out( $row ) {
    return array(
        'staffId'     => intval( $row['staff_id'] ),
        'staffName'   => $row['staff_name'],
        'year'        => intval( $row['decl_year'] ),
        'data'        => json_decode( strval( $row['data_json'] ), true ),
        'submittedAt' => $row['submitted_at'],
        'updatedAt'   => $row['updated_at'],
    );
}

function mush_office_get_my_year_end_decl( WP_REST_Request $request ) {
    global $wpdb;
    $staff_id = mush_office_get_bearer_staff_id( $request );
    if ( null === $staff_id ) {
        return new WP_REST_Response( array( 'error' => 'ログインが必要です。' ), 401 );
    }
    if ( 0 === $staff_id ) {
        return new WP_REST_Response( array( 'error' => '共通管理者パスワードでのログインでは利用できません。' ), 400 );
    }
    $table = $wpdb->prefix . 'mush_year_end_decl';
    $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE staff_id = %d AND decl_year = %d", $staff_id, intval( $request['year'] ) ), ARRAY_A );
    return new WP_REST_Response( $row ? mush_office_year_end_decl_out( $row ) : false, 200 );
}

function mush_office_save_my_year_end_decl( WP_REST_Request $request ) {
    global $wpdb;
    $staff_id = mush_office_get_bearer_staff_id( $request );
    if ( null === $staff_id ) {
        return new WP_REST_Response( array( 'error' => 'ログインが必要です。' ), 401 );
    }
    if ( 0 === $staff_id ) {
        return new WP_REST_Response( array( 'error' => '共通管理者パスワードでのログインでは利用できません。' ), 400 );
    }
    $year = intval( $request['year'] );
    if ( $year < 2000 || $year > 2100 ) {
        return new WP_REST_Response( array( 'error' => '年が正しくありません。' ), 400 );
    }
    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }
    $emp  = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}mush_employees WHERE id = %d", $staff_id ), ARRAY_A );
    $name = $emp ? $emp['name'] : '';
    $table = $wpdb->prefix . 'mush_year_end_decl';
    $now   = current_time( 'mysql' );
    $row   = array(
        'staff_id'     => $staff_id,
        'staff_name'   => $name,
        'decl_year'    => $year,
        'data_json'    => wp_json_encode( mush_office_year_end_decl_sanitize( isset( $p['data'] ) ? $p['data'] : array() ) ),
        'submitted_at' => $now,
        'updated_at'   => $now,
    );
    $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE staff_id = %d AND decl_year = %d", $staff_id, $year ) );
    if ( $existing ) {
        $wpdb->update( $table, $row, array( 'id' => intval( $existing ) ) );
    } else {
        $wpdb->insert( $table, $row );
    }
    mush_office_log_activity( $name, '年末調整の申告を提出', $name, $year . '年分' );
    $saved = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE staff_id = %d AND decl_year = %d", $staff_id, $year ), ARRAY_A );
    return new WP_REST_Response( $saved ? mush_office_year_end_decl_out( $saved ) : array( 'saved' => true ), 200 );
}

function mush_office_list_year_end_decl( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mush_year_end_decl';
    $year  = intval( $request->get_param( 'year' ) );
    $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT staff_id, staff_name, decl_year, submitted_at FROM {$table} WHERE decl_year = %d ORDER BY submitted_at DESC", $year ), ARRAY_A );
    $out   = array();
    foreach ( (array) $rows as $r ) {
        $out[] = array( 'staffId' => intval( $r['staff_id'] ), 'staffName' => $r['staff_name'], 'year' => intval( $r['decl_year'] ), 'submittedAt' => $r['submitted_at'] );
    }
    return new WP_REST_Response( $out, 200 );
}

function mush_office_get_year_end_decl( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mush_year_end_decl';
    $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE staff_id = %d AND decl_year = %d", intval( $request['staffId'] ), intval( $request['year'] ) ), ARRAY_A );
    return new WP_REST_Response( $row ? mush_office_year_end_decl_out( $row ) : false, 200 );
}

function mush_office_get_payslip( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mush_payslips';
    $staff_id = intval( $request['staffId'] );
    $year = intval( $request['year'] );
    $month = intval( $request['month'] );
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE staff_id = %d AND pay_year = %d AND pay_month = %d",
        $staff_id, $year, $month
    ), ARRAY_A );
    if ( ! $row ) {
        return new WP_REST_Response( false, 200 );
    }
    $row['data'] = json_decode( $row['data_json'], true );
    unset( $row['data_json'] );
    $row['published'] = ! empty( $row['published_at'] ); // 従業員に公開しているか(false=下書き)
    return new WP_REST_Response( $row, 200 );
}

// 従業員本人が過去の自分の給与明細書の一覧を確認する(ログインしていれば誰でも、自分の分だけを取得できる)。
function mush_office_get_my_payslips( WP_REST_Request $request ) {
    global $wpdb;
    $staff_id = mush_office_get_bearer_staff_id( $request );
    if ( null === $staff_id ) {
        return new WP_REST_Response( array( 'error' => 'ログインが必要です。' ), 401 );
    }
    if ( 0 === $staff_id ) {
        return new WP_REST_Response( array( 'error' => '共通管理者パスワードでのログインでは利用できません。' ), 400 );
    }
    $table = $wpdb->prefix . 'mush_payslips';
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, pay_year, pay_month, period_start, period_end FROM {$table} WHERE staff_id = %d AND published_at IS NOT NULL ORDER BY pay_year DESC, pay_month DESC", // 公開済みの明細だけ(下書きは本人には見せない)
        $staff_id
    ), ARRAY_A );
    $out = array();
    foreach ( (array) $rows as $r ) {
        $out[] = array(
            'id'          => intval( $r['id'] ),
            'payYear'     => intval( $r['pay_year'] ),
            'payMonth'    => intval( $r['pay_month'] ),
            'periodStart' => $r['period_start'],
            'periodEnd'   => $r['period_end'],
        );
    }
    return new WP_REST_Response( $out, 200 );
}

// 従業員本人が、指定した年月の自分の給与明細書の詳細を確認する。
function mush_office_get_my_payslip( WP_REST_Request $request ) {
    global $wpdb;
    $staff_id = mush_office_get_bearer_staff_id( $request );
    if ( null === $staff_id ) {
        return new WP_REST_Response( array( 'error' => 'ログインが必要です。' ), 401 );
    }
    if ( 0 === $staff_id ) {
        return new WP_REST_Response( array( 'error' => '共通管理者パスワードでのログインでは利用できません。' ), 400 );
    }
    $year = intval( $request['year'] );
    $month = intval( $request['month'] );
    $table = $wpdb->prefix . 'mush_payslips';
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE staff_id = %d AND pay_year = %d AND pay_month = %d",
        $staff_id, $year, $month
    ), ARRAY_A );
    if ( ! $row || empty( $row['published_at'] ) ) {
        return new WP_REST_Response( false, 200 ); // 下書きの明細は、本人には見せない
    }
    $row['data'] = json_decode( $row['data_json'], true );
    unset( $row['data_json'] );
    $row['published'] = true;
    return new WP_REST_Response( $row, 200 );
}

function mush_office_save_payslip( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mush_payslips';
    $staff_id = intval( $request['staffId'] );
    $year = intval( $request['year'] );
    $month = intval( $request['month'] );

    $p = $request->get_json_params();
    if ( empty( $p ) ) {
        $p = $request->get_params();
    }
    $staff_name = isset( $p['staffName'] ) ? sanitize_text_field( $p['staffName'] ) : '';
    $department = isset( $p['department'] ) ? sanitize_text_field( $p['department'] ) : '';
    $employee_number = isset( $p['employeeNumber'] ) ? intval( $p['employeeNumber'] ) : null;
    $period_start = isset( $p['periodStart'] ) ? sanitize_text_field( $p['periodStart'] ) : '';
    $period_end = isset( $p['periodEnd'] ) ? sanitize_text_field( $p['periodEnd'] ) : '';
    $data = isset( $p['data'] ) ? $p['data'] : array();
    $actor = mush_office_request_actor( $request ); // 操作ログ・作成者に、保存した人の名前を残す

    $existing_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE staff_id = %d AND pay_year = %d AND pay_month = %d",
        $staff_id, $year, $month
    ), ARRAY_A );
    $existing_id = $existing_row ? $existing_row['id'] : null;
    // 内容が変わる保存のときだけ、上書き前の内容を履歴に残す(同じ内容の保存し直しでは増やさない)。
    if ( $existing_row ) {
        $old_json = wp_json_encode( json_decode( strval( $existing_row['data_json'] ), true ) );
        if ( $old_json !== wp_json_encode( $data ) ) {
            mush_office_snapshot_payslip( $existing_row, $actor['name'], '更新' );
        }
    }
    $now = current_time( 'mysql' );
    $row = array(
        'staff_id'        => $staff_id,
        'staff_name'      => $staff_name,
        'department'      => $department,
        'employee_number' => $employee_number,
        'pay_year'        => $year,
        'pay_month'       => $month,
        'period_start'    => $period_start,
        'period_end'      => $period_end,
        'data_json'       => wp_json_encode( $data ),
        'updated_at'      => $now,
    );
    if ( $existing_id ) {
        $wpdb->update( $table, $row, array( 'id' => intval( $existing_id ) ) );
        $id = intval( $existing_id );
        // 仮データとして入れた明細書を保存し直したときは、本物の明細書になるので、仮データの削除の対象から外す。
        $sample_ids = get_option( 'mush_office_sample_data_ids', array() );
        if ( is_array( $sample_ids ) && ! empty( $sample_ids['payslips'] ) ) {
            $sample_ids['payslips'] = array_values( array_diff( array_map( 'intval', $sample_ids['payslips'] ), array( $id ) ) );
            update_option( 'mush_office_sample_data_ids', $sample_ids, false );
        }
        $wpdb->update( $table, array( 'created_by' => $actor['name'] ), array( 'id' => $id, 'created_by' => MUSH_OFFICE_SAMPLE_MARK ) );
    } else {
        $row['created_by'] = $actor['name'];
        $row['created_at'] = $now;
        $wpdb->insert( $table, $row );
        $id = $wpdb->insert_id;
    }
    mush_office_log_activity( $actor['name'], '給与明細書を保存', "{$staff_name}({$year}年{$month}月分)" );
    return new WP_REST_Response( array( 'saved' => true, 'id' => $id ), 200 );
}

// 上書き・削除される前の給与明細書の内容を、履歴テーブルに1行残す。
/**
 * 明細を、従業員に公開する／下書きに戻す({ published: true|false }。省略は公開)。
 */
function mush_office_publish_payslip( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mush_payslips';
    $staff_id = intval( $request['staffId'] );
    $year = intval( $request['year'] );
    $month = intval( $request['month'] );
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, staff_name FROM {$table} WHERE staff_id = %d AND pay_year = %d AND pay_month = %d",
        $staff_id, $year, $month
    ), ARRAY_A );
    if ( ! $row ) {
        return new WP_REST_Response( array( 'error' => '先に、明細書を保存してください。' ), 404 );
    }
    $p = $request->get_json_params();
    if ( ! is_array( $p ) ) {
        $p = array();
    }
    $publish = ! array_key_exists( 'published', $p ) || ! empty( $p['published'] );
    if ( $publish ) {
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET published_at = %s WHERE id = %d", current_time( 'mysql' ), intval( $row['id'] ) ) );
    } else {
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET published_at = NULL WHERE id = %d", intval( $row['id'] ) ) );
    }
    $actor = mush_office_request_actor( $request );
    mush_office_log_activity( $actor['name'], $publish ? '給与明細書を公開' : '給与明細書を下書きに戻す', "{$row['staff_name']}({$year}年{$month}月分)" );
    return new WP_REST_Response( array( 'published' => $publish ), 200 );
}

/**
 * ある月の明細を、まとめて公開する／下書きに戻す({ year, month, published })。変えた件数を返す。
 */
function mush_office_publish_payslip_month( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mush_payslips';
    $p = $request->get_json_params();
    if ( ! is_array( $p ) ) {
        $p = array();
    }
    $year = isset( $p['year'] ) ? intval( $p['year'] ) : 0;
    $month = isset( $p['month'] ) ? intval( $p['month'] ) : 0;
    if ( $year < 2000 || $year > 2100 || $month < 1 || $month > 12 ) {
        return new WP_REST_Response( array( 'error' => '年・月を指定してください。' ), 400 );
    }
    $publish = ! array_key_exists( 'published', $p ) || ! empty( $p['published'] );
    if ( $publish ) {
        $n = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET published_at = %s WHERE pay_year = %d AND pay_month = %d AND published_at IS NULL", current_time( 'mysql' ), $year, $month ) ); // まだ公開していないものだけ
    } else {
        $n = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET published_at = NULL WHERE pay_year = %d AND pay_month = %d AND published_at IS NOT NULL", $year, $month ) ); // 公開済みのものだけ
    }
    $n = false === $n ? 0 : intval( $n );
    $actor = mush_office_request_actor( $request );
    mush_office_log_activity( $actor['name'], $publish ? '給与明細書をまとめて公開' : '給与明細書をまとめて下書きに戻す', "{$year}年{$month}月分({$n}件)" );
    return new WP_REST_Response( array( 'published' => $publish, 'count' => $n ), 200 );
}

function mush_office_snapshot_payslip( $row, $actor_name, $reason ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'mush_payslip_history', array(
        'payslip_id'  => intval( $row['id'] ),
        'staff_id'    => intval( $row['staff_id'] ),
        'staff_name'  => $row['staff_name'],
        'pay_year'    => intval( $row['pay_year'] ),
        'pay_month'   => intval( $row['pay_month'] ),
        'data_json'   => $row['data_json'],
        'saved_at'    => ! empty( $row['updated_at'] ) ? $row['updated_at'] : $row['created_at'],
        'replaced_at' => current_time( 'mysql' ),
        'replaced_by' => $actor_name,
        'reason'      => $reason,
    ) );
}

// 指定した従業員・年月の保存履歴(新しい順、最大100件)。
function mush_office_get_payslip_history( WP_REST_Request $request ) {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}mush_payslip_history WHERE staff_id = %d AND pay_year = %d AND pay_month = %d ORDER BY id DESC LIMIT 100",
        intval( $request['staffId'] ), intval( $request['year'] ), intval( $request['month'] )
    ), ARRAY_A );
    $out = array();
    foreach ( (array) $rows as $r ) {
        $out[] = array(
            'id'         => intval( $r['id'] ),
            'savedAt'    => $r['saved_at'],
            'replacedAt' => $r['replaced_at'],
            'replacedBy' => $r['replaced_by'],
            'reason'     => $r['reason'],
            'data'       => json_decode( strval( $r['data_json'] ), true ),
        );
    }
    return new WP_REST_Response( $out, 200 );
}

function mush_office_delete_payslip( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mush_payslips';
    $id = intval( $request['id'] );
    $to_delete = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
    $actor = mush_office_request_actor( $request );
    if ( $to_delete ) {
        mush_office_snapshot_payslip( $to_delete, $actor['name'], '削除' );
    }
    $deleted = $wpdb->delete( $table, array( 'id' => $id ) );
    if ( false === $deleted ) {
        return new WP_REST_Response( array( 'error' => '削除に失敗しました。' ), 500 );
    }
    if ( $to_delete ) {
        // 削除も、操作ログに残す(保存・公開と同じ)
        mush_office_log_activity( $actor['name'], '給与明細書を削除', "{$to_delete['staff_name']}({$to_delete['pay_year']}年{$to_delete['pay_month']}月分)" );
    }
    return new WP_REST_Response( array( 'deleted' => true ), 200 );
}
