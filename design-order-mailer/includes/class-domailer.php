<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Design Order Mailer コアクラス
 *
 * 機能:
 *  1. デザインファイル（PSD/PNG等）をサーバーの非公開ディレクトリにアップロード保存
 *  2. トークン付きダウンロードリンクを発行
 *  3. 注文メールには添付せず、上記リンクを本文に記載して送信
 *  4. アップロードから60日経過したファイルをcronで自動削除
 */
class DOMailer {

	private static $instance = null;
	private $table_name;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'domailer_files';

		// アップロード（非ログインユーザーも利用可）
		add_action( 'wp_ajax_domailer_upload', array( $this, 'handle_upload' ) );
		add_action( 'wp_ajax_nopriv_domailer_upload', array( $this, 'handle_upload' ) );

		// ダウンロード（メール内リンクから、非ログイン状態でアクセスされる）
		add_action( 'wp_ajax_domailer_download', array( $this, 'handle_download' ) );
		add_action( 'wp_ajax_nopriv_domailer_download', array( $this, 'handle_download' ) );

		// 注文メール送信
		add_action( 'wp_ajax_domailer_send_order', array( $this, 'handle_send_order' ) );
		add_action( 'wp_ajax_nopriv_domailer_send_order', array( $this, 'handle_send_order' ) );

		// 期限切れファイルの自動削除（daily cron）
		add_action( 'domailer_cleanup_event', array( $this, 'cleanup_expired_files' ) );

		// 管理画面設定
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// フロントエンド用JS
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	/* =========================================================
	 * 有効化・無効化
	 * ========================================================= */

	public static function activate() {
		self::create_table();
		self::protect_upload_dir();

		if ( ! wp_next_scheduled( 'domailer_cleanup_event' ) ) {
			wp_schedule_event( time(), 'daily', 'domailer_cleanup_event' );
		}
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'domailer_cleanup_event' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'domailer_cleanup_event' );
		}
	}

	private static function create_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'domailer_files';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token VARCHAR(64) NOT NULL,
			original_filename VARCHAR(255) NOT NULL,
			stored_filename VARCHAR(255) NOT NULL,
			file_path VARCHAR(500) NOT NULL,
			mime_type VARCHAR(100) NOT NULL,
			file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
			order_email VARCHAR(255) DEFAULT NULL,
			download_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* =========================================================
	 * 保存先ディレクトリ
	 * ========================================================= */

	public static function get_upload_dir() {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'domailer-private';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * アップロードディレクトリへの直接アクセスを禁止する
	 * （ファイルは必ず handle_download() 経由・トークン認証でのみ配信する）
	 */
	private static function protect_upload_dir() {
		$dir = self::get_upload_dir();

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// Apache 2.2 / 2.4 両対応の記述
			$rules = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";
			file_put_contents( $htaccess, $rules );
		}

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/* =========================================================
	 * ① アップロード処理
	 * ========================================================= */

	public function handle_upload() {
		check_ajax_referer( 'domailer_nonce', 'nonce' );

		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => 'ファイルが送信されていません。' ), 400 );
		}

		$file = $_FILES['file'];

		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			wp_send_json_error( array( 'message' => 'アップロードに失敗しました。(エラーコード: ' . intval( $file['error'] ) . ')' ), 400 );
		}

		$allowed_ext = apply_filters( 'domailer_allowed_extensions', array( 'psd', 'png', 'jpg', 'jpeg', 'gif', 'webp' ) );
		$max_size    = apply_filters( 'domailer_max_file_size', 50 * 1024 * 1024 ); // 50MB

		$original_name = sanitize_file_name( $file['name'] );
		$ext           = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, $allowed_ext, true ) ) {
			wp_send_json_error( array( 'message' => '許可されていないファイル形式です。(' . esc_html( $ext ) . ')' ), 400 );
		}

		if ( $file['size'] > $max_size ) {
			wp_send_json_error( array( 'message' => 'ファイルサイズが上限（' . size_format( $max_size ) . '）を超えています。' ), 400 );
		}

		$mime = 'application/octet-stream';
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$detected = finfo_file( $finfo, $file['tmp_name'] );
			finfo_close( $finfo );
			if ( $detected ) {
				$mime = $detected;
			}
		}

		$token           = bin2hex( random_bytes( 20 ) );
		$stored_filename = $token . '.' . $ext;
		$target_dir      = self::get_upload_dir();
		$target_path     = trailingslashit( $target_dir ) . $stored_filename;

		if ( ! move_uploaded_file( $file['tmp_name'], $target_path ) ) {
			wp_send_json_error( array( 'message' => 'ファイルの保存に失敗しました。' ), 500 );
		}

		global $wpdb;
		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +' . DOMAILER_EXPIRY_DAYS . ' days' ) );

		$wpdb->insert(
			$this->table_name,
			array(
				'token'             => $token,
				'original_filename' => $original_name,
				'stored_filename'   => $stored_filename,
				'file_path'         => $target_path,
				'mime_type'         => $mime,
				'file_size'         => (int) $file['size'],
				'download_count'    => 0,
				'created_at'        => $now,
				'expires_at'        => $expires,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		wp_send_json_success(
			array(
				'token'        => $token,
				'download_url' => add_query_arg(
					array(
						'action' => 'domailer_download',
						'token'  => $token,
					),
					admin_url( 'admin-ajax.php' )
				),
				'expires_at'   => $expires,
			)
		);
	}

	/* =========================================================
	 * ② ダウンロード処理（メール内リンクからアクセス）
	 * ========================================================= */

	public function handle_download() {
		$token = isset( $_GET['token'] ) ? preg_replace( '/[^a-f0-9]/', '', wp_unslash( $_GET['token'] ) ) : '';

		if ( empty( $token ) ) {
			status_header( 400 );
			wp_die( '不正なリクエストです。', '', array( 'response' => 400 ) );
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE token = %s", $token )
		);

		if ( ! $row ) {
			status_header( 404 );
			wp_die( 'ファイルが見つかりません。リンクが間違っているか、既に削除された可能性があります。', '', array( 'response' => 404 ) );
		}

		if ( strtotime( $row->expires_at ) < time() ) {
			$this->delete_file_row( $row );
			status_header( 410 );
			wp_die( 'このファイルの保存期限（' . DOMAILER_EXPIRY_DAYS . '日間）が切れているため削除されました。', '', array( 'response' => 410 ) );
		}

		if ( ! file_exists( $row->file_path ) ) {
			status_header( 404 );
			wp_die( 'ファイルが見つかりません。', '', array( 'response' => 404 ) );
		}

		$wpdb->update(
			$this->table_name,
			array( 'download_count' => (int) $row->download_count + 1 ),
			array( 'id' => $row->id ),
			array( '%d' ),
			array( '%d' )
		);

		nocache_headers();
		header( 'Content-Type: ' . $row->mime_type );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $row->original_filename ) . '"' );
		header( 'Content-Length: ' . filesize( $row->file_path ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $row->file_path );
		exit;
	}

	/* =========================================================
	 * ③ 注文メール送信（添付なし・リンクのみ）
	 * ========================================================= */

	public function handle_send_order() {
		check_ajax_referer( 'domailer_nonce', 'nonce' );

		$tokens_raw = isset( $_POST['tokens'] ) ? (array) $_POST['tokens'] : array();
		$tokens     = array_filter(
			array_map(
				function ( $t ) {
					return preg_replace( '/[^a-f0-9]/', '', (string) $t );
				},
				$tokens_raw
			)
		);

		$name          = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$company       = isset( $_POST['company'] ) ? sanitize_text_field( wp_unslash( $_POST['company'] ) ) : '';
		$phone         = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$email         = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$address       = isset( $_POST['address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address'] ) ) : '';
		$memo          = isset( $_POST['memo'] ) ? sanitize_textarea_field( wp_unslash( $_POST['memo'] ) ) : '';
		$order_summary = isset( $_POST['order_summary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['order_summary'] ) ) : '';
		$need_receipt  = ! empty( $_POST['need_receipt'] );

		if ( empty( $name ) || empty( $phone ) || ! is_email( $email ) || empty( $address ) ) {
			wp_send_json_error( array( 'message' => '必須項目が未入力、またはメールアドレスの形式が正しくありません。' ), 400 );
		}

		if ( empty( $tokens ) ) {
			wp_send_json_error( array( 'message' => 'デザインファイルがアップロードされていません。' ), 400 );
		}

		global $wpdb;
		$links = array();

		foreach ( $tokens as $token ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE token = %s", $token )
			);

			if ( ! $row || strtotime( $row->expires_at ) < time() ) {
				continue; // 期限切れ・存在しないトークンはスキップ
			}

			$wpdb->update(
				$this->table_name,
				array( 'order_email' => $email ),
				array( 'id' => $row->id ),
				array( '%s' ),
				array( '%d' )
			);

			$download_url = add_query_arg(
				array(
					'action' => 'domailer_download',
					'token'  => $row->token,
				),
				admin_url( 'admin-ajax.php' )
			);

			$links[] = $row->original_filename . " : {$download_url}";
		}

		if ( empty( $links ) ) {
			wp_send_json_error( array( 'message' => '有効なデザインファイルが見つかりませんでした。お手数ですが再度アップロードしてください。' ), 400 );
		}

		$recipient = get_option( 'domailer_recipient_email' );
		if ( empty( $recipient ) ) {
			$recipient = get_option( 'admin_email' );
		}

		$subject = '【ご注文】' . get_bloginfo( 'name' ) . ' - ' . $name . ' 様';

		$body  = "以下の内容でご注文がありました。\n\n";
		$body .= "■ ご注文者様情報\n";
		$body .= "お名前: {$name}\n";
		if ( $company ) {
			$body .= "法人名・屋号: {$company}\n";
		}
		$body .= "電話番号: {$phone}\n";
		$body .= "メールアドレス: {$email}\n";
		$body .= "お届け先住所: {$address}\n";
		$body .= '領収書: ' . ( $need_receipt ? '必要' : '不要' ) . "\n";
		if ( $memo ) {
			$body .= "備考: {$memo}\n";
		}
		$body .= "\n";

		if ( $order_summary ) {
			$body .= "■ 注文内容（お見積もり明細）\n{$order_summary}\n\n";
		}

		$body .= "■ デザインデータ ダウンロードリンク（保存期間: " . DOMAILER_EXPIRY_DAYS . "日間）\n";
		foreach ( $links as $link ) {
			$body .= "- {$link}\n";
		}
		$body .= "\n※上記リンクはアップロードから" . DOMAILER_EXPIRY_DAYS . "日間のみ有効です。期限を過ぎるとファイルは自動的に削除されます。\n";

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( is_email( $email ) ) {
			$headers[] = 'Reply-To: ' . $email;
		}

		$sent = wp_mail( $recipient, $subject, $body, $headers );

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => 'メールの送信に失敗しました。時間をおいて再度お試しください。' ), 500 );
		}

		wp_send_json_success( array( 'message' => 'ご注文メールを送信しました。' ) );
	}

	/* =========================================================
	 * ④ 期限切れファイルの自動削除（daily cron）
	 * ========================================================= */

	public function cleanup_expired_files() {
		global $wpdb;
		$expired = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE expires_at < %s", current_time( 'mysql', true ) )
		);

		foreach ( $expired as $row ) {
			$this->delete_file_row( $row );
		}
	}

	private function delete_file_row( $row ) {
		global $wpdb;
		if ( ! empty( $row->file_path ) && file_exists( $row->file_path ) ) {
			@unlink( $row->file_path );
		}
		$wpdb->delete( $this->table_name, array( 'id' => $row->id ), array( '%d' ) );
	}

	/* =========================================================
	 * 管理画面設定
	 * ========================================================= */

	public function add_settings_page() {
		add_options_page(
			'Design Order Mailer 設定',
			'Design Order Mailer',
			'manage_options',
			'domailer-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'domailer_settings_group',
			'domailer_recipient_email',
			array( 'sanitize_callback' => 'sanitize_email' )
		);
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>Design Order Mailer 設定</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'domailer_settings_group' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="domailer_recipient_email">注文メール受信先アドレス</label></th>
						<td>
							<input type="email" id="domailer_recipient_email" name="domailer_recipient_email"
								value="<?php echo esc_attr( get_option( 'domailer_recipient_email', get_option( 'admin_email' ) ) ); ?>"
								class="regular-text" />
							<p class="description">未設定の場合、サイトの管理者メールアドレスが使用されます。</p>
						</td>
					</tr>
					<tr>
						<th scope="row">ファイル保存期間</th>
						<td>
							<?php echo esc_html( DOMAILER_EXPIRY_DAYS ); ?>日間<br />
							<p class="description">変更する場合は design-order-mailer.php 内の <code>DOMAILER_EXPIRY_DAYS</code> を編集してください。</p>
						</td>
					</tr>
					<tr>
						<th scope="row">保存先ディレクトリ</th>
						<td><code><?php echo esc_html( self::get_upload_dir() ); ?></code></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* =========================================================
	 * フロントエンド用JS
	 * ========================================================= */

	public function enqueue_frontend_assets() {
		wp_register_script(
			'domailer-frontend',
			DOMAILER_PLUGIN_URL . 'assets/js/frontend.js',
			array(),
			DOMAILER_VERSION,
			true
		);

		wp_localize_script(
			'domailer-frontend',
			'DOMailerConfig',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'domailer_nonce' ),
			)
		);

		wp_enqueue_script( 'domailer-frontend' );
	}
}
