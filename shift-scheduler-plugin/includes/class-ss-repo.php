<?php
/**
 * お客様単位のデータ操作の唯一の入口。
 *
 * - すべての読み書きに tenant_id の条件を必ず付ける。
 * - 書き込み時に渡された tenant_id は無視し、自分のお客様IDで上書きする。
 * - 取り扱えるテーブルは TABLES に登録したものだけ。
 * - tenant_id は現在のログインユーザーの所属（SS_Context）か、システム処理が
 *   明示的に渡した値からだけ決まる。
 */

if (!defined('ABSPATH')) {
    exit;
}

class SS_Repo_Exception extends Exception {
}

final class SS_Repo {
    /** お客様ごとに分離するテーブル（`shift_` 接頭辞を除いた名前） */
    const TABLES = array('staff', 'users', 'patterns', 'rules', 'request_periods', 'requests', 'request_submissions', 'schedules', 'entries',
        'task_records', 'task_images', 'schedule_tasks', 'activity_log');

    private $tenant_id;

    private function __construct($tenant_id) {
        $this->tenant_id = (int) $tenant_id;
        if ($this->tenant_id <= 0) {
            throw new SS_Repo_Exception('tenant is not resolved');
        }
    }

    /** ログイン中ユーザーのお客様 */
    public static function current() {
        return new self(SS_Context::tenant_id());
    }

    /** システム処理（定期実行など）が、明示的にお客様を指定する場合 */
    public static function for_tenant($tenant_id) {
        return new self($tenant_id);
    }

    public function tenant_id() {
        return $this->tenant_id;
    }

    public function find($table, $id) {
        global $wpdb;
        $t = $this->table($table);
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t} WHERE tenant_id = %d AND id = %d",
            $this->tenant_id, (int) $id
        ), ARRAY_A);
    }

    /**
     * 条件の書き方：
     *   'col' => 値          一致（値が null なら IS NULL）
     *   'col >=' => 値       比較（=, !=, >=, <=, >, <）
     *   'col IN' => 配列     いずれかに一致（空の配列なら、何も一致しない）
     * どの書き方でも、お客様IDの条件は必ず付く。
     */
    public function all($table, array $where = array(), $order_by = 'id', $dir = 'ASC', $limit = 200, $offset = 0) {
        global $wpdb;
        $t = $this->table($table);
        list($sql, $args) = $this->where($where);
        $order_by = $this->column($order_by);
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
        $args[] = max(1, min(10000, (int) $limit));
        $args[] = max(0, (int) $offset);
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE tenant_id = %d{$sql} ORDER BY {$order_by} {$dir} LIMIT %d OFFSET %d",
            array_merge(array($this->tenant_id), $args)
        ), ARRAY_A);
    }

    public function count($table, array $where = array()) {
        global $wpdb;
        $t = $this->table($table);
        list($sql, $args) = $this->where($where);
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE tenant_id = %d{$sql}",
            array_merge(array($this->tenant_id), $args)
        ));
    }

    public function insert($table, array $data) {
        global $wpdb;
        $t = $this->table($table);
        unset($data['id']);
        $data['tenant_id'] = $this->tenant_id; // 渡された値は無視して上書き
        foreach (array_keys($data) as $col) {
            $this->column($col);
        }
        if ($wpdb->insert($t, $data) === false) {
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

    /** 複数行をまとめて追加（全行に自分のお客様IDを付ける）。追加した行数を返す。 */
    public function insert_many($table, array $rows) {
        global $wpdb;
        $t = $this->table($table);
        $added = 0;
        foreach (array_chunk(array_values($rows), 200) as $chunk) {
            $cols = null;
            $sql_rows = array();
            $args = array();
            foreach ($chunk as $row) {
                unset($row['id']);
                $row['tenant_id'] = $this->tenant_id; // 渡された値は無視して上書き
                ksort($row);
                if ($cols === null) {
                    $cols = array_keys($row);
                    foreach ($cols as $col) {
                        $this->column($col);
                    }
                } elseif (array_keys($row) !== $cols) {
                    throw new SS_Repo_Exception('rows must have the same columns');
                }
                $ph = array();
                foreach ($row as $v) {
                    if ($v === null) {
                        $ph[] = 'NULL';
                    } elseif (is_int($v) || is_bool($v)) {
                        $ph[] = '%d';
                        $args[] = (int) $v;
                    } else {
                        $ph[] = '%s';
                        $args[] = (string) $v;
                    }
                }
                $sql_rows[] = '(' . implode(',', $ph) . ')';
            }
            if ($cols === null) {
                continue;
            }
            $sql = "INSERT INTO {$t} (" . implode(',', $cols) . ') VALUES ' . implode(',', $sql_rows);
            $n = $wpdb->query($args ? $wpdb->prepare($sql, $args) : $sql);
            $added += $n === false ? 0 : (int) $n;
        }
        return $added;
    }

    public function update($table, $id, array $data) {
        global $wpdb;
        $t = $this->table($table);
        unset($data['id'], $data['tenant_id']);
        foreach (array_keys($data) as $col) {
            $this->column($col);
        }
        if (!$data) {
            return false;
        }
        $r = $wpdb->update($t, $data, array('id' => (int) $id, 'tenant_id' => $this->tenant_id));
        return $r !== false;
    }

    public function delete($table, $id) {
        global $wpdb;
        $t = $this->table($table);
        $r = $wpdb->delete($t, array('id' => (int) $id, 'tenant_id' => $this->tenant_id));
        return $r !== false && $r > 0;
    }

    /** 条件に合う行をまとめて削除する（必ずお客様IDで絞り込み。条件が空の呼び出しは不可）。 */
    public function delete_where($table, array $where) {
        global $wpdb;
        $t = $this->table($table);
        unset($where['tenant_id']);
        if (!$where) {
            throw new SS_Repo_Exception('delete_where requires a condition');
        }
        list($sql, $args) = $this->where($where);
        $n = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$t} WHERE tenant_id = %d{$sql}",
            array_merge(array($this->tenant_id), $args)
        ));
        return $n === false ? 0 : (int) $n;
    }

    private function table($name) {
        if (!in_array($name, self::TABLES, true)) {
            throw new SS_Repo_Exception('table is not tenant-scoped: ' . $name);
        }
        return SS_System::table($name);
    }

    private function column($name) {
        if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new SS_Repo_Exception('invalid column');
        }
        return $name;
    }

    private function where(array $where) {
        $sql = '';
        $args = array();
        foreach ($where as $key => $val) {
            if (!is_string($key) || !preg_match('/^([a-z][a-z0-9_]*)(?:\s*(=|!=|>=|<=|>|<|IN))?$/', $key, $m)) {
                throw new SS_Repo_Exception('invalid condition');
            }
            $col = $this->column($m[1]);
            $op = isset($m[2]) && $m[2] !== '' ? $m[2] : '=';
            if ($col === 'tenant_id') {
                continue; // 呼び出し側から tenant_id は指定させない
            }
            if ($op === 'IN') {
                $vals = array_values((array) $val);
                if (!$vals) {
                    $sql .= ' AND 1 = 0';
                    continue;
                }
                $ph = array();
                foreach ($vals as $v) {
                    $ph[] = (is_int($v) || is_bool($v)) ? '%d' : '%s';
                    $args[] = (is_int($v) || is_bool($v)) ? (int) $v : (string) $v;
                }
                $sql .= " AND {$col} IN (" . implode(',', $ph) . ')';
                continue;
            }
            if ($val === null) {
                if ($op !== '=' && $op !== '!=') {
                    throw new SS_Repo_Exception('invalid condition');
                }
                $sql .= $op === '=' ? " AND {$col} IS NULL" : " AND {$col} IS NOT NULL";
                continue;
            }
            if (is_int($val) || is_bool($val)) {
                $sql .= " AND {$col} {$op} %d";
                $args[] = (int) $val;
            } else {
                $sql .= " AND {$col} {$op} %s";
                $args[] = (string) $val;
            }
        }
        return array($sql, $args);
    }

    /** 条件に合う行を、まとめて更新する（必ずお客様IDで絞り込み。条件が空の呼び出しは不可）。 */
    public function update_where($table, array $where, array $data) {
        global $wpdb;
        $t = $this->table($table);
        unset($where['tenant_id'], $data['id'], $data['tenant_id']);
        if (!$where || !$data) {
            throw new SS_Repo_Exception('update_where requires a condition and data');
        }
        $set = array();
        $args = array();
        foreach ($data as $col => $v) {
            $this->column($col);
            if ($v === null) {
                $set[] = "{$col} = NULL";
            } elseif (is_int($v) || is_bool($v)) {
                $set[] = "{$col} = %d";
                $args[] = (int) $v;
            } else {
                $set[] = "{$col} = %s";
                $args[] = (string) $v;
            }
        }
        list($wsql, $wargs) = $this->where($where);
        $n = $wpdb->query($wpdb->prepare(
            "UPDATE {$t} SET " . implode(', ', $set) . " WHERE tenant_id = %d{$wsql}",
            array_merge($args, array($this->tenant_id), $wargs)
        ));
        return $n === false ? 0 : (int) $n;
    }
}
