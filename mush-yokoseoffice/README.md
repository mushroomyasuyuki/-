# ヨコセオフィス業務システム 設計図・一式(WordPress DB版)

最終更新: 2026年9月

---

## 1. システム概要

WordPress固定ページの「カスタムHTML」ブロックにHTML/JSを貼り付けて動かす業務システムです。
**当初はFirebase Firestoreをバックエンドにしていましたが、現在は全ページ、WordPress自体のデータベース(MySQL)のみで動作するように移行済みです。**

- フロントエンド: プレーンHTML / JavaScript(フレームワークなし)
- バックエンド: WordPressプラグイン「**ヨコセオフィス業務システム(WordPress DB版)**」が提供するREST API
- データベース: WordPress自体のMySQLデータベース(専用テーブルを追加)
- デプロイ環境: WordPress(Cocoonテーマ)の固定ページ「カスタムHTMLブロック」

### なぜFirebaseをやめたか
外部サービス(Firebase/Firestore)への依存をなくし、**WordPress単体で完結**するようにするため。プラグインを有効化するだけで動き、外部アカウントやAPIキーの管理が不要になった。

---

## 2. ファイル構成

| ファイル | 役割 | 状態 |
|---|---|---|
| `mush-yokoseoffice.php` | WordPressプラグイン本体(DBテーブル作成・REST API提供) | ✅完成 |
| `top-page.html` | トップページ(各ページへのリンク集) | ✅完成 |
| `employee-register.html` | 従業員登録(管理者用) | ✅完成 |
| `nippo1-fixed.html` | 日報(今日・明日の予定/実績入力) | ✅完成 |
| ~~`yotei-jisseki-input.html`~~ | 進捗ページ(**使用停止**。v2.7.30でファイルを同梱から外し、朝礼・管理者集計にも出さない。データ(`wp_mush_schedule_tasks`)とAPIは残してあり、バックアップにも含まれる) | ⏹停止 |
| `chorei-fixed.html` | 朝礼(本日の全体予定・未登録者確認) | ✅完成 |
| `kanri-shukei.html` | 管理者集計(部署別・担当者別実績、勤怠集計、案件の複製/削除/重複統合) | ✅完成 |
| `timecard.html` | タイムカード(出勤・退勤打刻、QRコード読み取り対応) | ✅完成 |
| `qr-generate.html` | タイムカード用QRコード生成(管理者用) | ✅完成 |

**導入方法**: `mush-yokoseoffice.php` を `wp-content/plugins/mush-yokoseoffice/` に配置して有効化 → 各`.html`ファイルの中身を対応する固定ページの「カスタムHTML」ブロックに貼り付け。

---

## 3. データベース設計(WordPressの専用テーブル)

プラグイン有効化時に自動作成されます(`wp_` は環境によって異なるテーブルプレフィックス)。

### `wp_mush_employees`(従業員マスタ)
| カラム | 型 | 説明 |
|---|---|---|
| id | BIGINT (PK, auto) | 内部ID |
| employee_number | INT (UNIQUE) | 従業員番号(管理部門000番台/営業100番台/デザイン200番台/製作300番台/事務400番台。各番台の21番から自動採番) |
| name | VARCHAR(191) | 氏名 |
| department | VARCHAR(50) | 部署 |
| birthdate / zip / address / phone / mobile / memo | - | 各種情報(電話番号・郵便番号は保存時に自動でハイフン挿入) |
| created_at | DATETIME | 登録日時 |

### `wp_mush_task_records`(日報:予定・実績)
| カラム | 型 | 説明 |
|---|---|---|
| id | BIGINT (PK) | 内部ID |
| record_date | VARCHAR(10) | 対象日(YYYY-MM-DD) |
| staff_id / staff_name / department | - | スタッフ情報 |
| category / client / project | - | 業務区分・顧客名・案件名(まとめ入力時はclient/projectがNULL) |
| start_time / end_time | VARCHAR(5) | 開始・終了予定時刻(15分刻み) |
| status | VARCHAR(20) | 予定 / 進行中 / 完了 |
| is_outside / area / prefecture / travel_type | - | 外出関連 |
| note | TEXT | 備考 |
| bulk_mode / bulk_count | - | まとめ入力(事務のみ) |
| progress | INT | 進捗(%)。状態と自動連動 |
| due_date | VARCHAR(10) | 終了予定日 |
| created_at / updated_at | DATETIME | - |
| **UNIQUE KEY** | (record_date, staff_id, client, project) | 同じ日・同じ案件の重複作成を防止(保存はupsert方式) |

### `wp_mush_schedule_tasks`(進捗:予定・実績)
1案件 = 1行のシンプルな構造(Firestore版は1日1ドキュメントに配列で格納していたが、DB移行時に整理)。

| カラム | 型 | 説明 |
|---|---|---|
| id | BIGINT (PK) | 内部ID |
| record_date | VARCHAR(10) | 対象日(繰越案件は元の日付を保持) |
| staff_id / staff_name / department | - | スタッフ情報 |
| name | VARCHAR(191) | 案件名 |
| start_time / end_time / due_date | - | 時刻・終了予定日 |
| memo | TEXT | メモ |
| progress | INT | 進捗(%) |
| created_at / updated_at | DATETIME | - |

### `wp_mush_time_cards`(タイムカード)
| カラム | 型 | 説明 |
|---|---|---|
| id | BIGINT (PK) | 内部ID |
| record_date / staff_id / staff_name / department | - | - |
| clock_in / clock_out | VARCHAR(5) | 出勤・退勤時刻 |
| created_at / updated_at | DATETIME | - |
| **UNIQUE KEY** | (record_date, staff_id) | 1日1スタッフにつき1行(upsert方式で出退勤を更新) |

---

## 4. REST API 一覧

ベースURL: `(サイトのURL)/wp-json/mush-office/v1/`
※ 認証なし(元のFirestoreルールが「読み書き自由」だったため同等の設計。社内限定利用が前提)

| メソッド | パス | 用途 |
|---|---|---|
| GET | `/employees` | 従業員一覧取得 |
| POST | `/employees` | 従業員登録(番号は自動採番) |
| DELETE | `/employees/{id}` | 従業員削除 |
| GET | `/task-records` | 日報一覧取得(`date`/`dateFrom`/`dateTo`/`staffId`で絞込) |
| POST | `/task-records/upsert` | 日報保存(同じ日・案件があれば更新/重複防止) |
| DELETE | `/task-records/{id}` | 日報削除 |
| POST | `/task-records/{id}/duplicate` | 日報の複製(管理者ページ専用。常に新規行を作成) |
| GET | `/schedule-tasks` | 進捗一覧取得 |
| POST | `/schedule-tasks/upsert` | 進捗保存(idがあれば更新、なければ新規) |
| DELETE | `/schedule-tasks/{id}` | 進捗削除 |
| POST | `/schedule-tasks/{id}/duplicate` | 進捗の複製(管理者ページ専用) |
| GET | `/time-cards` | タイムカード一覧取得(`date`/`staffId`/`limit`等) |
| POST | `/time-cards/upsert` | 出退勤打刻の保存 |

---

## 5. フロントエンド共通の仕組み

各HTMLファイルの`<script>`冒頭に、以下の共通関数を実装しています(コピー&ペーストで各ファイルに埋め込み):

```js
function getSiteBasePath(){
  // ページ内の /wp-content/ を含むCSS/JSの読み込み元URLから、
  // サイトの設置場所(ドメイン直下 or /yokose/ のようなサブディレクトリ)を自動判定する
}
function getRestApiBase(){
  // <link rel="https://api.w.org/"> があればそれを使い、無ければ getSiteBasePath()+'/wp-json' を使う
}
const API_BASE = getRestApiBase() + '/mush-office/v1';
```

これにより、`mush-office.com`(ドメイン直下)・`yokoseoffice.sakura.ne.jp/yokose/`(サブディレクトリ)のどちらでアクセスしても、正しいAPI・リンク先を自動的に使うようになっています。

### 各固定ページの実際のスラッグ(URL末尾)
`top-page.html`のリンク・各ページの「トップに戻る」リンクで使用。運用中にスラッグを変更した場合は、該当ファイル内の該当箇所を書き換える必要があります。

| ページ | スラッグ |
|---|---|
| トップ | `/トップ/` |
| 日報 | `/日報/` |
| 進捗 | `/予定進捗/` |
| 朝礼 | `/朝礼/` |
| 従業員登録 | `/従業員登録/` |
| 管理者集計 | `/管理者集計/` |
| タイムカード | `/タイムカード/`(未確認・推測) |
| QRコード生成 | `/QRコード/` |

---

## 6. 主な機能・仕様のポイント

- **日報**:今日・明日の分を1ページで入力。15分刻みの時刻選択。進捗スライダーで状態(予定/進行中/完了)が自動連動。終了予定日入力。「終了前(未完了)の顧客・案件から選ぶ」プルダウンで過去案件を呼び出し可能(直近30日以内)。保存時は同じ案件があれば更新(重複防止)。
- **進捗**:繰越案件は元の日付のまま、未完了(進捗100%未満)なら本日の画面にも表示・編集可能。
- **朝礼**:日報・進捗データを合算して、未登録者・終了していない業務・外出者などを表示。
- **管理者集計**:期間指定で部署別/担当者別集計、案件一覧(複製・削除・重複統合)、勤怠集計、Excel出力。
- **タイムカード**:通常の手動打刻に加え、QRコードの`?staff=`パラメータ付きURLアクセスで自動打刻。
- **従業員登録**:電話番号・郵便番号はハイフンなし全角入力→保存時に自動で半角+ハイフン整形。

---

## 7. 今後の運用に関する注意点

- タイムカードのデータは今回のテーブル追加まで存在しなかったため、移行直後は0件から始まります。
- QRコード画像は外部サービス(`api.qrserver.com`)の画像生成APIを利用しています(以前使用していたJSライブラリが環境によって読み込めなかったため変更)。
- セキュリティは全APIエンドポイントとも認証なし(社内限定・URLを知っていれば誰でも読み書き可能という、元のFirestoreルールと同等の設計)。より厳密にしたい場合は`permission_callback`をログイン必須に変更する必要があります。
