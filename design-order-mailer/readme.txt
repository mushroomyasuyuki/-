=== Design Order Mailer ===
Contributors: yasu
Tags: email, upload, order, attachment
Requires at least: 5.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

注文フォームからアップロードされたデザインファイル（PSD/PNG等）をメールに直接添付せず、
サーバーに保存してダウンロードリンクをメール本文に記載して送信するプラグインです。
保存されたファイルは自動アップロード日から60日後に自動削除されます。

== インストール方法 ==

1. この design-order-mailer フォルダを ZIP 圧縮する
   （すでに zip 済みの場合はこの手順は不要です）
2. WordPress管理画面 > プラグイン > 新規追加 > プラグインのアップロード
3. ZIPファイルを選択してインストール、有効化する
4. 設定 > Design Order Mailer で、注文メールの受信先アドレスを確認・設定する
   （未設定の場合はサイトの管理者メールアドレス宛に送信されます）

== 使い方（フロントエンド実装） ==

有効化すると、サイトの全ページで以下のJavaScriptオブジェクトが利用可能になります。

    window.DOMailer.uploadDesignFile(fileBlob, filename)
    window.DOMailer.sendOrderEmail(tokens, orderInfo)

詳しい使用例は assets/js/frontend.js 内のコメントを参照してください。
既存の「この内容で注文メールを作成する」ボタンの処理を、
PSDファイルを直接ダウンロード＆mailto:で開く方式から、
上記2つの関数を呼び出す方式に置き換えることで、
「添付なし・リンクのみ記載」のメール送信に切り替えられます。

== 動作の仕組み ==

1. アップロード
   ブラウザから domailer_upload (admin-ajax.php) にファイルをPOST。
   サーバーは wp-content/uploads/domailer-private/ にランダムなトークン名で保存し、
   トークン・元ファイル名・保存日時・期限（60日後）をDBテーブル（wp_domailer_files）に記録します。
   このディレクトリは .htaccess で直接アクセスを禁止しているため、
   トークンを知らない第三者はファイルにアクセスできません。

2. メール送信
   domailer_send_order にご注文者様情報とトークンの配列をPOSTすると、
   サーバー側でトークンの有効性を確認したうえで、
   ダウンロード用リンク（例: https://example.com/wp-admin/admin-ajax.php?action=domailer_download&token=xxxx）
   を本文に記載したメールを wp_mail() で送信します。添付ファイルは一切含まれません。

3. ダウンロード
   受信者がメール内のリンクをクリックすると domailer_download が呼ばれ、
   トークンに対応するファイルをその場でダウンロードできます。
   期限切れの場合は自動的にファイルを削除し、エラーメッセージを表示します。

4. 自動削除
   毎日1回（WordPressのdaily cron）、保存から60日を過ぎたファイルとDBレコードを
   自動的に削除します（cleanup_expired_files）。

== 保存期間の変更 ==

保存期間は design-order-mailer.php の以下の定数で変更できます（デフォルト60日）。

    define( 'DOMAILER_EXPIRY_DAYS', 60 );

== 注意事項 ==

* WP-Cron はサイトへのアクセスがあったタイミングで実行される仕組みのため、
  アクセスが極端に少ないサイトでは削除が数時間〜1日程度遅れる場合があります。
  より確実に定時実行したい場合は、サーバーのシステムcronから
  wp-cron.php を直接叩く設定（DISABLE_WP_CRON + 実cron）を推奨します。
* アップロードを許可する拡張子はデフォルトで psd, png, jpg, jpeg, gif, webp です。
  変更したい場合は以下のフィルターフックを利用してください。

    add_filter( 'domailer_allowed_extensions', function( $exts ) {
        $exts[] = 'zip';
        return $exts;
    } );

* アップロード可能な最大ファイルサイズはデフォルト50MBです。変更する場合:

    add_filter( 'domailer_max_file_size', function( $bytes ) {
        return 100 * 1024 * 1024; // 100MB
    } );

== 変更履歴 ==

= 1.0.0 =
* 初回リリース
