# シフト作成プラグイン 詳細設計（画面・データ項目）v0.1

基本方針は `shift-plugin-design.md`（v0.6）に従う。設計のみで、実装は未着手。

## 1. 画面一覧

### 1.1 公開（ログイン不要）
| ID | 画面 | URL | 内容 |
|---|---|---|---|
| P1 | 無料登録 | `/register` | 事業者名・業種・氏名・メール・パスワード・利用規約同意 |
| P2 | メール認証 | `/verify/<token>` | 認証後に自動ログインし、O1へ遷移 |
| P3 | ログイン | `/login` | メール＋パスワード。ログイン後は所属お客様のページへ |
| P4 | パスワード再設定 | `/reset` | メールで再設定リンクを送る |
| P5 | スタッフ招待の受諾 | `/invite/<token>` | 氏名確認・パスワード設定 |

### 1.2 お客様の管理者（`/s/<お客様ID>/…`）
| ID | 画面 | URL | 内容 |
|---|---|---|---|
| O1 | ダッシュボード | `/s/<id>/` | 今月のシフト状態、希望の提出状況、無料期間の残り、通知 |
| O2 | スタッフ一覧 | `/staff` | 検索・絞り込み（役割・資格・状態）、招待、CSV取り込み/書き出し |
| O3 | スタッフ詳細 | `/staff/<n>` | 雇用区分、役割、資格、勤務可能曜日、上限時間、個別ルール |
| O4 | 勤務区分 | `/patterns` | 早番・遅番・夜勤などの名称、開始・終了、休憩、色 |
| O5 | ルール設定 | `/rules` | 必要人数、連勤上限、休息時間、夜勤ルール等（業種プリセットから開始） |
| O6 | 希望の収集 | `/requests` | 期間と締切の設定、提出状況、未提出者への案内 |
| O7 | シフト表（作成・編集） | `/schedules/<n>` | 月表（スタッフ×日）、ドラッグで編集、違反の表示、自動作成ボタン |
| O8 | 自動作成 | O7内のパネル | 条件の確認、実行（ブラウザ側で計算）、結果の比較・採用 |
| O9 | 公開・出力 | `/schedules/<n>/publish` | 公開、PDF・Excel（CSV）出力、お知らせ送信 |
| O10 | お客様設定 | `/settings` | 事業者情報、通知のオン・オフ、スタッフの権限 |
| O11 | 契約・お支払い | `/billing` | プラン選択、カード登録（PAY.JP）、請求履歴、解約 |
| O12 | データ書き出し | `/export` | 全データのCSV（退会時にも利用） |

### 1.3 スタッフ
| ID | 画面 | URL | 内容 |
|---|---|---|---|
| S1 | マイページ | `/s/<id>/me` | 自分の確定シフト（月表・リスト）、次回の勤務 |
| S2 | 希望の提出 | `/me/requests` | カレンダーで希望休・出勤可否を入力、締切までは修正可 |
| S3 | 全体のシフト | `/me/schedule` | 公開済みの全体表（閲覧のみ。管理者が表示可否を設定） |
| S4 | プロフィール | `/me/profile` | 氏名・パスワード変更 |

### 1.4 運営（WordPress管理画面のメニュー）
| ID | 画面 | 内容 |
|---|---|---|
| A1 | お客様一覧 | 状態（無料中・有料・猶予・停止）、人数、登録日、無料期限 |
| A2 | お客様詳細 | 契約情報、手動で期限延長、停止・再開、データ削除 |
| A3 | プラン設定 | プラン名・人数上限・月額（PAY.JPプランとの対応） |
| A4 | 決済ログ | Webhookの履歴、失敗の確認 |
| A5 | システム設定 | 通知メールの文面、保存期間、メンテナンス表示 |

## 2. 主な画面遷移

1. 登録：P1 → メール → P2 → O1（初期設定ガイド：勤務区分 → スタッフ登録 → ルール確認）
2. 月次運用：O6（希望の収集開始）→ スタッフがS2で提出 → 締切 → O7で自動作成・微調整 → O9で公開 → S1で確認
3. 無料期間終了：14日前・7日前・前日にメール → O11でカード登録 → 即時に通常利用へ

## 3. データ設計（全テーブル共通：`tenant_id` 必須・索引つき）

テーブル接頭辞は `wp_shift_`（専用DBを推奨）。

### 3.1 shift_tenants（お客様）
`id`、`public_id`（URL用ランダム文字列）、`name`、`industry`（restaurant/care/other）、`status`（trial/active/grace/readonly/suspended/deleted）、`trial_start`、`trial_end`、`plan_id`、`payjp_customer_id`、`payjp_subscription_id`、`next_billing_at`、`settings`（JSON：通知・表示設定）、`created_at`、`deleted_at`

### 3.2 shift_users（ユーザー）
`id`、`tenant_id`、`wp_user_id`、`email`（全体で一意）、`role`（owner/manager/staff）、`staff_id`、`status`（invited/active/disabled）、`last_login_at`
※ メールは全体で一意（1メール＝1お客様）。

### 3.3 shift_staff（スタッフ）
`id`、`tenant_id`、`name`、`kana`、`employment`（full/part/baito）、`roles`（JSON：ホール/キッチン等）、`qualifications`（JSON：看護師・介護福祉士等）、`available_weekdays`、`max_hours_week`、`max_hours_month`、`max_days_row`（連勤上限の個別設定）、`night_ok`、`active`、`sort_order`

### 3.4 shift_patterns（勤務区分）
`id`、`tenant_id`、`name`、`short_name`、`start_time`、`end_time`、`break_minutes`、`crosses_midnight`（夜勤）、`counts_as_night`、`color`、`active`

### 3.5 shift_rules（ルール）
`id`、`tenant_id`、`type`（下記）、`params`（JSON）、`hard`（必須／努力目標）、`weight`（努力目標の重み）、`active`
- `required_staff`：曜日・時間帯・役割/資格ごとの必要人数
- `max_consecutive_days`、`min_rest_hours`、`max_nights_month`、`after_night_off`、`monthly_days_off`、`max_hours`、`fair_distribution`、`avoid_pairs`（組ませない/組ませたい）など

### 3.6 shift_requests（希望）
`id`、`tenant_id`、`period_id`、`staff_id`、`date`、`kind`（off/ng/prefer/available）、`pattern_id`（任意）、`note`、`submitted_at`
### 3.6b shift_request_periods（希望の収集期間）
`id`、`tenant_id`、`start_date`、`end_date`、`deadline`、`status`（open/closed）

### 3.7 shift_schedules（シフト表）
`id`、`tenant_id`、`start_date`、`end_date`、`status`（draft/published）、`published_at`、`version`、`created_by`

### 3.8 shift_entries（日別の割当）
`id`、`tenant_id`、`schedule_id`、`date`、`staff_id`、`pattern_id`、`role`、`locked`（自動作成で動かさない）、`note`
索引：`(tenant_id, schedule_id, date)`、`(tenant_id, staff_id, date)`

### 3.9 shift_audit_log（変更履歴）
`id`、`tenant_id`、`user_id`、`action`、`target`、`before`、`after`、`created_at`（保存期間あり）

### 3.10 shift_billing_events（決済イベント）
`id`、`tenant_id`、`event_id`（PAY.JP。重複処理防止）、`type`、`payload`、`processed_at`

### 3.11 shift_plans（プラン）
`id`、`name`、`max_staff`、`price`、`payjp_plan_id`、`active`、`sort_order`

## 4. 自動作成の処理（ブラウザ側）

1. 画面が、期間・スタッフ・勤務区分・ルール・希望・既存の確定済み（`locked`）をAPIで取得する。
2. ブラウザ内（Web Worker）で、必須条件を満たす案を探索し、努力目標の点数を最大化する。
3. 制限時間（30秒程度）で打ち切り、最良案と「満たせなかった条件」の一覧を表示する。
4. 管理者が案を確認・微調整し、採用すると一括保存する（サーバーでも必須条件を再検証する）。
5. 案は複数（例：3案）生成して比較できるようにする。

サーバー側は、保存時の検証（人数・休息時間・連勤等）と権限確認のみ。

## 5. API・権限の基本ルール

- 通信は WordPress の REST API（`/wp-json/shift/v1/…`）。すべてログイン必須で、ノンスを確認する。
- `tenant_id` は常にログイン中ユーザーの所属から決める。リクエストの値は使わない。
- 権限：owner（全操作）／manager（スタッフ・シフト編集、課金は不可）／staff（自分の希望・閲覧のみ）。
- 契約状態による制限：`readonly` では閲覧とデータ書き出し以外を拒否する。

## 6. 通知メール一覧

| 種類 | 宛先 | 必須 | タイミング |
|---|---|---|---|
| メール認証 | お客様 | ○ | 登録時 |
| スタッフ招待 | スタッフ | ○ | 招待時 |
| パスワード再設定 | 全員 | ○ | 申請時 |
| 無料期間終了の案内 | お客様 | ○ | 14日前・7日前・前日 |
| 決済失敗・猶予の案内 | お客様 | ○ | 失敗時・猶予の終盤 |
| 希望の締切リマインド | スタッフ | 任意 | 締切の3日前・前日 |
| シフト公開のお知らせ | スタッフ | 任意 | 公開時 |
| 公開後の変更のお知らせ | 該当スタッフ | 任意 | 変更時（まとめて1通） |

## 7. 次に決めること

1. ヒアリング結果（飲食・介護のルール）→ O5のルール項目と自動作成の仕様を確定
2. さくらのPHP実行時間・メモリ・cron・メール送信上限の実測
3. 画面のモックアップ（O7のシフト表が最重要）を作るか
4. 開発の順序（設計書のフェーズ1：テナント基盤から）
