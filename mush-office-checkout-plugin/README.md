# マッシュオフィス 決済・ライセンス管理（mush-office.com 側）

マッシュオフィスシステム（お客様のサイトに入れる業務システム）が毎日問い合わせる、**ライセンス確認**と**更新ファイルの配布**を行うプラグインです。
mush-office.com に入れます。お客様のサイトには入れません。

## できること（0.1.1）

- ライセンスキーの発行（形式 `MOS-XXXX-XXXX-XXXX-XXXX`）と、状態の変更（有効／停止中／解約）、有効期限の設定
- ①からの確認の記録（最終確認の日時・回数・確認元のサイト）
- 配布するZIPの登録（版は、ZIP内の `Version:` から自動で読み取り）と、配布する版の選択
- 有効なライセンスにだけ、**10分だけ有効な署名つきURL**でZIPを渡す（ZIPは、外から直接開けないフォルダに保存）

## ①との取り決め（変えないこと）

| 窓口 | 応答 |
|---|---|
| `GET /wp-json/mush-checkout/v1/license-status?key=` | `{"status":"active"}`。**active 以外は、①が停止扱い**（suspended / canceled / expired / unknown） |
| `GET /wp-json/mush-checkout/v1/update-check?key=` | `{"version":"1.122.0","package":"…"}`。無効なライセンスの `package` は空 |
| `GET /wp-json/mush-checkout/v1/download?…` | ZIP本体（update-check が返したURLだけ） |

## 入れ方

1. **古い「決済・ライセンス管理」プラグインを無効化**します（同じ窓口が重なるため。削除は、データを確認してから）。
2. このZIPを入れて有効化します。
3. 管理画面の「ライセンス管理」で、①のZIPを登録し、お客様のライセンスを発行します。
4. お客様側の「設定 → マッシュオフィスシステム」に、キーを入れます。

## まだ入っていないもの（意図的）

- 申込みページと、PAY.JPでの月額決済（金額が未定のため）。ライセンスは、手作業で発行します。
- 決済と連動した、ライセンスの自動停止。ライセンスのテーブルには、PAY.JPの顧客ID・定期課金IDを入れる欄を用意してあります（`payjp_customer_id` / `payjp_subscription_id`）。

## シフト作成プラグインとの合体（今後）

- 名前の接頭辞は、`MCO_` / `mco_`（シフト作成は `SS_` / `ss_`）。クラス・テーブル・オプションが重なりません。
- 合体するときは、`includes/class-mco-*.php` を、そのままシフト作成のプラグインに移し、`mush-office-checkout.php` の `require_once` と `plugins_loaded` の起動を、シフト作成の起動に足します。
- PAY.JPのキー・Webhookは、シフト作成側の仕組み（`SS_Payjp` など）を共有できます。Webhookの受け口は、1つにまとめて振り分けます。

## 削除したとき

初期値では、**ライセンスの記録を残します**。管理画面の「その他」で、チェックを入れた場合だけ、テーブルと配布ファイルを削除します。

## テスト

`php tests/test-checkout.php`（SQLite＋模擬のWordPress関数。キー、状態、回数制限、ZIPの登録、署名つきURLの検証）
