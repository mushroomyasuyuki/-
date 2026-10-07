<?php
/**
 * テナント分離のテスト（WordPress本体なしで実行できる）。
 * 実行：php tests/test-tenant-isolation.php
 *
 * SS_Repo が発行するSQL・書き込みのすべてに、お客様ID（tenant_id）の条件が
 * 付くことと、他のお客様のIDを指定できないことを確かめる。
 */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');

class FakeWpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $log = array();
    public function prepare($sql, $args = null) {
        $args = is_array($args) ? $args : array_slice(func_get_args(), 1);
        $i = 0;
        return preg_replace_callback('/%[dsf]/', function ($m) use (&$i, $args) {
            $v = $args[$i++];
            return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes((string) $v) . "'";
        }, $sql);
    }
    public function get_row($sql, $o = null) { $this->log[] = array('read', $sql); return null; }
    public function get_results($sql, $o = null) { $this->log[] = array('read', $sql); return array(); }
    public function get_var($sql) { $this->log[] = array('read', $sql); return 0; }
    public function insert($t, $data) { $this->log[] = array('insert', $t, $data); $this->insert_id = 1; return 1; }
    public function update($t, $data, $where) { $this->log[] = array('update', $t, $data, $where); return 1; }
    public function delete($t, $where) { $this->log[] = array('delete', $t, $where); return 1; }
    public function query($sql) { $this->log[] = array('query', $sql); return 1; }
}

$wpdb = new FakeWpdb();
function is_user_logged_in() { return false; }
function get_current_user_id() { return 0; }

require __DIR__ . '/../includes/class-ss-system.php';
require __DIR__ . '/../includes/class-ss-context.php';
require __DIR__ . '/../includes/class-ss-repo.php';

$failed = 0;
function check($name, $cond) {
    global $failed;
    echo ($cond ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$cond) { $failed++; }
}
function expect_exception($name, $fn) {
    try { $fn(); check($name, false); } catch (SS_Repo_Exception $e) { check($name, true); }
}

$repo = SS_Repo::for_tenant(7);

// 読み取り：すべてのSQLに自分のお客様IDの条件が付く
$repo->find('staff', 5);
$repo->all('staff', array('active' => 1, 'name' => 'x'), 'sort_order', 'DESC', 50, 10);
$repo->count('users', array('staff_id' => 3));
foreach ($wpdb->log as $entry) {
    check('read has tenant_id=7: ' . substr($entry[1], 0, 60), strpos($entry[1], 'tenant_id = 7') !== false);
}

// 呼び出し側が tenant_id を条件に入れて他社を指定しようとしても、自分の条件のみ
$wpdb->log = array();
$repo->all('staff', array('tenant_id' => 99));
check('where tenant_id=99 is ignored', strpos($wpdb->log[0][1], 'tenant_id = 99') === false && strpos($wpdb->log[0][1], 'tenant_id = 7') !== false);

// 書き込み：渡された tenant_id は無視して上書き
$wpdb->log = array();
$repo->insert('staff', array('tenant_id' => 99, 'id' => 1000, 'name' => 'A'));
check('insert overrides tenant_id', $wpdb->log[0][2]['tenant_id'] === 7 && !isset($wpdb->log[0][2]['id']));

// 更新：WHERE に tenant_id、データの tenant_id は無視
$wpdb->log = array();
$repo->update('staff', 5, array('tenant_id' => 99, 'name' => 'B'));
check('update where has tenant', $wpdb->log[0][3] === array('id' => 5, 'tenant_id' => 7));
check('update data drops tenant_id', !isset($wpdb->log[0][2]['tenant_id']));

// 削除：WHERE に tenant_id
$wpdb->log = array();
$repo->delete('staff', 5);
check('delete where has tenant', $wpdb->log[0][2] === array('id' => 5, 'tenant_id' => 7));

// 条件つき一括削除：tenant_id の条件が必ず付く。条件なしは拒否
$wpdb->log = array();
$repo->delete_where('requests', array('period_id' => 3, 'staff_id' => 4, 'tenant_id' => 99));
check('delete_where has tenant', strpos($wpdb->log[0][1], 'DELETE FROM wp_shift_requests WHERE tenant_id = 7') === 0 && strpos($wpdb->log[0][1], 'tenant_id = 99') === false);
expect_exception('delete_where without condition rejected', function () use ($repo) { $repo->delete_where('requests', array()); });
expect_exception('delete_where with only tenant_id rejected', function () use ($repo) { $repo->delete_where('requests', array('tenant_id' => 7)); });

// フェーズ2のテーブルもお客様で絞り込まれる
foreach (array('patterns', 'rules', 'request_periods', 'requests', 'request_submissions') as $tbl) {
    $wpdb->log = array();
    $repo->all($tbl);
    check("phase2 table {$tbl} filtered by tenant", strpos($wpdb->log[0][1], 'tenant_id = 7') !== false);
}

// まとめて追加：全行に自分のお客様IDが付き、渡された tenant_id は使われない
$wpdb->log = array();
$repo->insert_many('entries', array(
    array('schedule_id' => 1, 'staff_id' => 2, 'date' => '2026-11-02', 'tenant_id' => 99, 'pattern_id' => null),
    array('schedule_id' => 1, 'staff_id' => 3, 'date' => '2026-11-02', 'tenant_id' => 77, 'pattern_id' => 5),
));
$ins = $wpdb->log[0][1];
check('insert_many forces own tenant_id on every row', strpos($ins, 'INSERT INTO wp_shift_entries') === 0 && substr_count($ins, ',7)') === 2 && strpos($ins, '99') === false && strpos($ins, '77') === false && strpos($ins, 'NULL') !== false);
expect_exception('insert_many with different columns rejected', function () use ($repo) { $repo->insert_many('entries', array(array('schedule_id' => 1), array('staff_id' => 1))); });
expect_exception('insert_many on unscoped table rejected', function () use ($repo) { $repo->insert_many('tenants', array(array('name' => 'x'))); });

// 取り扱えないテーブル・不正な列名・お客様未確定
expect_exception('unscoped table rejected', function () use ($repo) { $repo->find('tenants', 1); });
expect_exception('tokens table rejected', function () use ($repo) { $repo->all('tokens'); });
expect_exception('invalid column rejected', function () use ($repo) { $repo->all('staff', array('name = 1 OR 1' => 'x')); });
expect_exception('invalid order column rejected', function () use ($repo) { $repo->all('staff', array(), 'id; DROP TABLE x'); });
expect_exception('no tenant (not logged in) rejected', function () { SS_Repo::current(); });
expect_exception('tenant 0 rejected', function () { SS_Repo::for_tenant(0); });

echo $failed ? "\n{$failed} FAILED\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
