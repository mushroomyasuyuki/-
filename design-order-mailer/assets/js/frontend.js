/**
 * Design Order Mailer - フロントエンド連携用スクリプト
 *
 * 既存の見積もり・シミュレーションツールから、この window.DOMailer を呼び出して
 * 「ファイルをサーバーに保存 → メールにはリンクのみ記載して送信」を行います。
 */
(function () {
	'use strict';

	/**
	 * デザインファイル（Blob）をサーバーにアップロードし、トークン・ダウンロードURLを取得する
	 *
	 * @param {Blob}   fileBlob 保存したいファイル（例: 生成したPSDのBlob）
	 * @param {string} filename ファイル名（例: 'carpet-design.psd'）
	 * @returns {Promise<{token:string, download_url:string, expires_at:string}>}
	 */
	async function uploadDesignFile( fileBlob, filename ) {
		const formData = new FormData();
		formData.append( 'action', 'domailer_upload' );
		formData.append( 'nonce', DOMailerConfig.nonce );
		formData.append( 'file', fileBlob, filename );

		const res = await fetch( DOMailerConfig.ajax_url, {
			method: 'POST',
			body: formData,
		} );
		const json = await res.json();

		if ( ! json.success ) {
			throw new Error( ( json.data && json.data.message ) || 'アップロードに失敗しました。' );
		}
		return json.data; // { token, download_url, expires_at }
	}

	/**
	 * 注文メールを送信する（添付ファイルなし。アップロード済みファイルへのリンクを本文に記載）
	 *
	 * @param {string[]} tokens    uploadDesignFile() で取得したトークンの配列
	 * @param {object}   orderInfo { name, company, phone, email, address, memo, order_summary, need_receipt }
	 * @returns {Promise<{message:string}>}
	 */
	async function sendOrderEmail( tokens, orderInfo ) {
		const formData = new FormData();
		formData.append( 'action', 'domailer_send_order' );
		formData.append( 'nonce', DOMailerConfig.nonce );

		tokens.forEach( function ( t ) {
			formData.append( 'tokens[]', t );
		} );

		Object.keys( orderInfo || {} ).forEach( function ( key ) {
			formData.append( key, orderInfo[ key ] );
		} );

		const res = await fetch( DOMailerConfig.ajax_url, {
			method: 'POST',
			body: formData,
		} );
		const json = await res.json();

		if ( ! json.success ) {
			throw new Error( ( json.data && json.data.message ) || 'メール送信に失敗しました。' );
		}
		return json.data;
	}

	/**
	 * 使用例（既存の「この内容で注文メールを作成する」ボタンのクリックハンドラ内で呼び出す）:
	 *
	 * document.querySelector('#send-order-btn').addEventListener('click', async function () {
	 *   try {
	 *     // 1) 既存処理でカーペット用PSD Blob・壁紙用PSD Blob を生成
	 *     const carpetBlob    = await generateCarpetPsdBlob();    // 既存関数（例）
	 *     const wallpaperBlob = await generateWallpaperPsdBlob(); // 既存関数（例）
	 *
	 *     // 2) それぞれサーバーへアップロードしてトークンを取得
	 *     const carpetResult    = await DOMailer.uploadDesignFile(carpetBlob, 'carpet-design.psd');
	 *     const wallpaperResult = await DOMailer.uploadDesignFile(wallpaperBlob, 'wallpaper-design.psd');
	 *
	 *     // 3) 注文フォームの入力内容とあわせて送信（添付なし・リンクのみ本文に記載される）
	 *     await DOMailer.sendOrderEmail(
	 *       [carpetResult.token, wallpaperResult.token],
	 *       {
	 *         name: document.querySelector('#order-name').value,
	 *         company: document.querySelector('#order-company').value,
	 *         phone: document.querySelector('#order-phone').value,
	 *         email: document.querySelector('#order-email').value,
	 *         address: document.querySelector('#order-address').value,
	 *         memo: '',
	 *         order_summary: document.querySelector('#order-summary').innerText,
	 *         need_receipt: document.querySelector('#order-receipt').checked ? '1' : ''
	 *       }
	 *     );
	 *
	 *     alert('ご注文メールを送信しました。');
	 *   } catch (err) {
	 *     alert('エラー: ' + err.message);
	 *   }
	 * });
	 */

	window.DOMailer = {
		uploadDesignFile: uploadDesignFile,
		sendOrderEmail: sendOrderEmail,
	};
})();
