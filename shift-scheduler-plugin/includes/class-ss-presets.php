<?php
/** 業種ごとの初期設定（勤務区分・全体ルール）。登録完了時と、「ひな形を入れる」ボタンで使う。 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Presets {
    /** 全体ルールの種類と、画面に出す名前・単位・範囲 */
    public static function global_rule_defs() {
        return array(
            'max_consecutive_days' => array('label' => '連勤の上限', 'unit' => '日', 'min' => 1, 'max' => 31),
            'min_rest_hours'       => array('label' => '勤務と勤務の間の休息', 'unit' => '時間以上', 'min' => 0, 'max' => 24),
            'monthly_days_off'     => array('label' => '月の公休日数', 'unit' => '日以上', 'min' => 0, 'max' => 31),
            'max_nights_month'     => array('label' => '月の夜勤回数の上限', 'unit' => '回', 'min' => 0, 'max' => 31),
            'after_night_off'      => array('label' => '夜勤明けの翌日は休み', 'unit' => '（1=する）', 'min' => 0, 'max' => 1),
        );
    }

    public static function patterns($industry) {
        $night = array('夜勤', '夜', '16:00', '09:00', 120, 1, '#7e57c2');
        if ($industry === 'restaurant') {
            return array(
                array('ランチ', 'ラ', '11:00', '15:00', 0, 0, '#f2b84b'),
                array('ディナー', 'デ', '17:00', '22:30', 0, 0, '#4a7fd1'),
                array('通し', '通', '11:00', '22:30', 120, 0, '#6aa84f'),
            );
        }
        if ($industry === 'care') {
            return array(
                array('早番', '早', '07:00', '16:00', 60, 0, '#f2b84b'),
                array('日勤', '日', '09:00', '18:00', 60, 0, '#6aa84f'),
                array('遅番', '遅', '11:00', '20:00', 60, 0, '#4a7fd1'),
                $night,
            );
        }
        return array(
            array('早番', '早', '07:00', '16:00', 60, 0, '#f2b84b'),
            array('日勤', '日', '09:00', '18:00', 60, 0, '#6aa84f'),
            array('遅番', '遅', '12:00', '21:00', 60, 0, '#4a7fd1'),
        );
    }

    public static function global_rules($industry) {
        if ($industry === 'care') {
            return array('max_consecutive_days' => 5, 'min_rest_hours' => 11, 'monthly_days_off' => 8, 'max_nights_month' => 8, 'after_night_off' => 1);
        }
        if ($industry === 'restaurant') {
            return array('max_consecutive_days' => 6, 'min_rest_hours' => 8, 'monthly_days_off' => 8);
        }
        return array('max_consecutive_days' => 6, 'min_rest_hours' => 8, 'monthly_days_off' => 8);
    }

    /** 勤務区分：まだ1つもないときだけ入れる。入れた件数を返す。 */
    public static function apply_patterns(SS_Repo $repo, $industry) {
        if ($repo->count('patterns') > 0) {
            return 0;
        }
        $n = 0;
        foreach (self::patterns($industry) as $i => $p) {
            $repo->insert('patterns', array(
                'name' => $p[0], 'short_name' => $p[1], 'start_time' => $p[2], 'end_time' => $p[3],
                'break_minutes' => $p[4], 'crosses_midnight' => $p[3] <= $p[2] ? 1 : 0,
                'counts_as_night' => $p[5], 'color' => $p[6], 'active' => 1, 'sort_order' => $i,
                'created_at' => SS_System::now(),
            ));
            $n++;
        }
        return $n;
    }

    /** 全体ルール：まだ設定されていない種類だけ入れる。入れた件数を返す。 */
    public static function apply_rules(SS_Repo $repo, $industry) {
        $n = 0;
        foreach (self::global_rules($industry) as $type => $value) {
            if ($repo->count('rules', array('type' => $type)) === 0) {
                $repo->insert('rules', array(
                    'type' => $type, 'params' => wp_json_encode(array('value' => $value)),
                    'hard' => 1, 'weight' => 1, 'active' => 1, 'created_at' => SS_System::now(),
                ));
                $n++;
            }
        }
        return $n;
    }

    public static function apply(SS_Repo $repo, $industry) {
        return self::apply_patterns($repo, $industry) + self::apply_rules($repo, $industry);
    }
}
