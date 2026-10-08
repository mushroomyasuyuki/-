<?php
/**
 * 規約・表記ページ（特定商取引法に基づく表記／利用規約／プライバシーポリシー）へのリンク。
 *
 * リンク先の決め方（上が優先）：
 *  1) 管理画面で、URLを直接入力したもの
 *  2) 管理画面で、固定ページから選んだもの（公開中のものだけ）
 *  3) サイトの固定ページから、タイトル・URLの文字で自動で探したもの
 *  4) （利用規約のみ）旧設定 ss_terms_url
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SS_Legal {
    const KEYS = array('tokushoho', 'terms', 'privacy');

    /** 固定ページが公開・変更・削除されたら、自動検出の結果を取り直す。 */
    public static function init() {
        add_action('save_post_page', array(__CLASS__, 'clear_cache'));
        add_action('deleted_post', array(__CLASS__, 'clear_cache'));
        add_action('trashed_post', array(__CLASS__, 'clear_cache'));
    }

    public static function label($key) {
        $map = array('tokushoho' => '特定商取引法に基づく表記', 'terms' => '利用規約', 'privacy' => 'プライバシーポリシー');
        return isset($map[$key]) ? $map[$key] : $key;
    }

    /** 自動で探すときの、タイトル・スラッグに含まれる文字（小文字で比べる）。 */
    private static function keywords($key) {
        $map = array(
            'tokushoho' => array('特定商取引', '特商法', '特商取引', 'tokusho', 'tokutei', 'commercial-transaction', 'commercial'),
            'terms'     => array('利用規約', 'ご利用規約', 'サービス利用規約', 'terms'),
            'privacy'   => array('プライバシー', '個人情報', 'privacy'),
        );
        return $map[$key];
    }

    /** 自動で探す。公開中の固定ページから、最初に見つかったものの [ID, タイトル, URL] を返す。 */
    public static function guess($key) {
        $cache_key = 'ss_legal_guess_' . $key;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached ? $cached : null;
        }
        $found = array();
        $pages = get_pages(array('post_status' => 'publish', 'number' => 300, 'sort_column' => 'post_date', 'sort_order' => 'ASC'));
        foreach ((array) $pages as $p) {
            $hay = strtolower($p->post_title . ' ' . $p->post_name);
            foreach (self::keywords($key) as $kw) {
                if (strpos($hay, strtolower($kw)) !== false) {
                    // 「利用規約」と「特定商取引法」など、別のページを取り違えないよう、他の種類のキーワードを含むものは除く
                    $other = false;
                    foreach (self::KEYS as $k2) {
                        if ($k2 === $key) {
                            continue;
                        }
                        foreach (self::keywords($k2) as $kw2) {
                            if (strpos($hay, strtolower($kw2)) !== false && strpos($hay, strtolower($kw)) === false) {
                                $other = true;
                            }
                        }
                    }
                    if (!$other) {
                        $found = array('id' => (int) $p->ID, 'title' => $p->post_title, 'url' => get_permalink($p->ID));
                        break 2;
                    }
                }
            }
        }
        set_transient($cache_key, $found, $found ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS); // 見つからなかった結果は、短く保存する
        return $found ? $found : null;
    }

    public static function clear_cache() {
        foreach (self::KEYS as $k) {
            delete_transient('ss_legal_guess_' . $k);
        }
    }

    /** @return array ['url' => ..., 'source' => custom|page|auto|legacy|none, 'title' => ...] */
    public static function resolve($key) {
        if (!in_array($key, self::KEYS, true)) {
            return array('url' => '', 'source' => 'none', 'title' => '');
        }
        $custom = (string) get_option('ss_legal_url_' . $key, '');
        if ($custom !== '' && preg_match('#^https?://#i', $custom)) {
            return array('url' => $custom, 'source' => 'custom', 'title' => $custom);
        }
        $page_id = (int) get_option('ss_legal_page_' . $key, 0);
        if ($page_id > 0) {
            $post = get_post($page_id);
            if ($post && $post->post_status === 'publish' && $post->post_type === 'page') {
                return array('url' => get_permalink($page_id), 'source' => 'page', 'title' => $post->post_title);
            }
        }
        $g = self::guess($key);
        if ($g) {
            return array('url' => $g['url'], 'source' => 'auto', 'title' => $g['title']);
        }
        if ($key === 'terms') {
            $legacy = (string) get_option('ss_terms_url', '');
            if ($legacy !== '' && preg_match('#^https?://#i', $legacy)) {
                return array('url' => $legacy, 'source' => 'legacy', 'title' => $legacy);
            }
        }
        return array('url' => '', 'source' => 'none', 'title' => '');
    }

    public static function url($key) {
        $r = self::resolve($key);
        return $r['url'];
    }

    /** 画面のスクリプトに渡す：[キー => ['label' => ..., 'url' => ...]]（リンク先が無いものは含めない） */
    public static function links_map() {
        $out = array();
        foreach (self::KEYS as $k) {
            $u = self::url($k);
            if ($u !== '') {
                $out[$k] = array('label' => self::label($k), 'url' => $u);
            }
        }
        return $out;
    }

    /**
     * 管理画面からの保存。$input：['tokushoho' => ['page' => ID, 'url' => '...'], ...]
     * @return array ['errors' => [...]]
     */
    public static function save(array $input) {
        $errors = array();
        foreach (self::KEYS as $k) {
            $row = isset($input[$k]) && is_array($input[$k]) ? $input[$k] : array();
            $url = isset($row['url']) ? trim((string) $row['url']) : '';
            $page = isset($row['page']) ? (int) $row['page'] : 0;
            if ($url !== '' && !preg_match('#^https?://[^\s]+$#i', $url)) {
                $errors[] = '「' . self::label($k) . '」のURLは、http:// か https:// で始まる形式で入力してください。';
                $url = '';
            }
            if ($page > 0) {
                $post = get_post($page);
                if (!$post || $post->post_type !== 'page' || $post->post_status !== 'publish') {
                    $errors[] = '「' . self::label($k) . '」に選んだページは、公開されていません。';
                    $page = 0;
                }
            }
            update_option('ss_legal_url_' . $k, $url, false);
            update_option('ss_legal_page_' . $k, $page, false);
        }
        self::clear_cache();
        return array('errors' => $errors);
    }
}
