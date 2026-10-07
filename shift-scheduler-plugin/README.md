# シフト作成（マルチテナント）プラグイン

設計：リポジトリ直下の `shift-plugin-design.md` と `shift-plugin-detail-design.md`。

## 現在の範囲（フェーズ1：テナント基盤）

- 無料登録 `/register/` → メール認証 `/verify/<token>/` → 専用ページ `/s/<お客様ID>/`
- ログイン `/login/`、スタッフ招待 `/invite/<token>/`
- ロール：`shift_owner`（お客様の管理者）／`shift_manager`（副管理者）／`shift_staff`（スタッフ）。お客様側のユーザーはWordPress管理画面に入れない
- スタッフ管理（追加・無効化・招待メール）と、スタッフ数の上限チェック
- 無料期間（2か月・カード不要）と、期限切れ後の「閲覧のみ」
- 初期プラン（ライト50名500円／スタンダード100名1,000円）の登録

未実装：勤務区分・ルール・希望・シフト表・自動作成・PAY.JP決済・プラン設定画面・運営用の管理画面。

## お客様ごとのデータ分離

- 全テーブルに `tenant_id`。データ操作は `SS_Repo`（常にお客様IDで絞り込み）だけを通す。
- お客様IDはログイン中ユーザーの所属から決める。URL・フォーム・JSONの値は使わない。
- 他のお客様の `/s/<ID>/` を開くと、存在の有無が分からない「見つかりません」を返す。
- テスト：`php tests/test-tenant-isolation.php`、`sh tests/check-no-direct-db.sh`

## 設置

1. `shift-scheduler-plugin` フォルダを `wp-content/plugins/` に置いて有効化（テーブル・ロール・初期プランが作られる）。
2. 設定 → パーマリンク を一度保存（URLの割り当てを反映）。
3. （任意）利用規約のURL：オプション `ss_terms_url` に設定。
4. メール送信が不安定な場合は、SMTPプラグインを併用する。

## 注意

- 削除時にデータを消すには、`wp-config.php` に `define('SS_REMOVE_DATA_ON_UNINSTALL', true);` が必要。
- パスワード再設定は、WordPress標準の画面（`wp-login.php?action=lostpassword`）を使う。
