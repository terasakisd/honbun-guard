<?php
/**
 * Plugin Name: 本文誤削除防止プラグイン
 * Plugin URI:  https://github.com/terasakisd/honbun-guard
 * Description: 公開済み・非公開の記事を保存したとき、本文の文字数が前回より大きく減っていたら保存をブロックします。見出しが減っていた場合は警告を表示します。
 * Version: 1.5.0
 * Author: -
 * License: GPL-2.0-or-later
 * Text Domain: honbun-guard
 * Update URI: https://github.com/terasakisd/honbun-guard/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HBG_VERSION', '1.5.0' );
define( 'HBG_PLUGIN_FILE', __FILE__ );

class Honbun_Guard {

	const OPTION_KEY = 'hbg_settings';

	public static function init() {
		$instance = new self();

		// ブロックエディタ（REST）での保存をブロックするためのフック
		foreach ( $instance->target_post_types() as $post_type ) {
			add_filter( "rest_pre_insert_{$post_type}", array( $instance, 'check_rest_save' ), 10, 2 );
		}

		// クラシックエディタ等（REST以外）の保存チェック
		add_filter( 'wp_insert_post_data', array( $instance, 'check_classic_save' ), 10, 2 );

		// クラシックエディタ: 送信前チェック用のフィールドとスクリプト
		add_action( 'edit_form_top', array( $instance, 'print_override_field' ) );
		add_action( 'admin_footer', array( $instance, 'print_editor_script' ) );

		// ブロックエディタ: 見出し減少の警告スクリプト
		add_action( 'enqueue_block_editor_assets', array( $instance, 'enqueue_block_editor_script' ) );

		// 管理画面
		add_action( 'admin_menu', array( $instance, 'add_settings_page' ) );
		add_action( 'admin_init', array( $instance, 'register_settings' ) );
		add_action( 'admin_notices', array( $instance, 'show_admin_notice' ) );
	}

	/** チェック対象の投稿タイプ（フィルタで変更可能） */
	public function target_post_types() {
		return apply_filters( 'hbg_target_post_types', array( 'post', 'page' ) );
	}

	public function get_settings() {
		$defaults = array(
			'threshold_percent' => 10,   // この%以上減ったら発動
			'min_chars'         => 100,  // 前回本文がこの文字数未満なら対象外（短い記事の誤検知防止）
		);
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), $defaults );
	}

	/** 本文の実質文字数（ブロックコメント・HTMLタグ・空白を除去して数える） */
	public function count_chars( $content ) {
		$text = preg_replace( '/<!--.*?-->/s', '', (string) $content ); // Gutenbergブロックコメント等
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/\s+/u', '', $text ); // 空白・改行は数えない
		return mb_strlen( $text );
	}

	/** 本文中の見出し（h1〜h6）の数 */
	public function count_headings( $content ) {
		return (int) preg_match_all( '/<h[1-6][\s>]/i', (string) $content );
	}

	/**
	 * 減少チェック本体。
	 *
	 * @return array|null 発動時は情報配列、問題なければ null
	 */
	public function detect_shrink( $post_id, $new_content ) {
		if ( ! $post_id ) {
			return null;
		}

		$old_post = get_post( $post_id );
		if ( ! $old_post || ! in_array( $old_post->post_status, array( 'publish', 'private' ), true ) ) {
			return null; // 公開済み・非公開の記事のみ対象
		}

		if ( ! in_array( $old_post->post_type, $this->target_post_types(), true ) ) {
			return null;
		}

		$settings  = $this->get_settings();
		$old_chars = $this->count_chars( $old_post->post_content );
		$new_chars = $this->count_chars( $new_content );

		if ( $old_chars < (int) $settings['min_chars'] ) {
			return null;
		}

		$drop_percent = ( $old_chars - $new_chars ) / $old_chars * 100;

		if ( $drop_percent < (float) $settings['threshold_percent'] ) {
			return null;
		}

		return array(
			'post_id'      => $post_id,
			'post_title'   => $old_post->post_title,
			'old_chars'    => $old_chars,
			'new_chars'    => $new_chars,
			'drop_percent' => round( $drop_percent, 1 ),
		);
	}

	/**
	 * ブロックエディタ（REST API）経由の保存チェック。
	 * WP_Error を返して保存を中断（エディタ上にエラー表示）。
	 */
	public function check_rest_save( $prepared_post, $request ) {
		// オートセーブ・リビジョンは対象外
		if ( false !== strpos( $request->get_route(), '/autosaves' ) ) {
			return $prepared_post;
		}

		if ( empty( $prepared_post->ID ) || ! isset( $prepared_post->post_content ) ) {
			return $prepared_post;
		}

		$result = $this->detect_shrink( $prepared_post->ID, $prepared_post->post_content );
		if ( null === $result ) {
			return $prepared_post;
		}

		$message = sprintf(
			'本文の文字数が前回より %1$s%% 減っています（%2$s文字 → %3$s文字）。',
			$result['drop_percent'],
			number_format_i18n( $result['old_chars'] ),
			number_format_i18n( $result['new_chars'] )
		);

		return new WP_Error(
			'hbg_content_shrink_blocked',
			$message . ' 保存をブロックしました。内容を確認してください。（意図的な削除の場合は、一時的に「本文誤削除防止プラグイン」を無効化するか、しきい値を調整してください）',
			array( 'status' => 400 )
		);
	}

	/**
	 * クラシックエディタ等（REST以外）経由の保存チェック。
	 * 本文を前回の内容に戻して変更を無効化し、管理画面に通知を表示。
	 */
	public function check_classic_save( $data, $postarr ) {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $data; // REST は check_rest_save で処理済み
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $data;
		}
		if ( 'revision' === $data['post_type'] || empty( $postarr['ID'] ) ) {
			return $data;
		}

		// 確認ポップアップでチェックを入れて保存されたか
		$override = (
			isset( $_POST['hbg_override'], $_POST['hbg_nonce'] )
			&& '1' === $_POST['hbg_override']
			&& wp_verify_nonce( sanitize_key( $_POST['hbg_nonce'] ), 'hbg_override' )
		);

		$result = $this->detect_shrink( $postarr['ID'], $data['post_content'] );
		if ( null === $result ) {
			return $data;
		}

		if ( $override ) {
			// 確認ポップアップで承認済みのため、意図的な変更として保存を許可
			return $data;
		}

		// 本文を前回の内容に戻して、実質的に保存を無効化
		$old_post             = get_post( $postarr['ID'] );
		$data['post_content'] = $old_post->post_content;

		// 保存後のリダイレクト先で表示する通知を保存
		set_transient(
			'hbg_notice_' . get_current_user_id(),
			array(
				'post_title'   => $result['post_title'],
				'old_chars'    => $result['old_chars'],
				'new_chars'    => $result['new_chars'],
				'drop_percent' => $result['drop_percent'],
			),
			60
		);

		return $data;
	}

	/** 保存後の管理画面通知（クラシックエディタ向け） */
	public function show_admin_notice() {
		$key    = 'hbg_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );

		$message = sprintf(
			'【本文誤削除防止プラグイン】「%1$s」の本文が前回より %2$s%% 減っています（%3$s文字 → %4$s文字）。本文の変更は保存されていません。意図的な削除の場合は、更新時に表示される確認ポップアップでチェックを入れて保存してください。',
			esc_html( $notice['post_title'] ),
			esc_html( $notice['drop_percent'] ),
			number_format_i18n( $notice['old_chars'] ),
			number_format_i18n( $notice['new_chars'] )
		);

		printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', $message );
	}

	/** ガード対象の投稿かどうか（公開済み・非公開の対象投稿タイプ） */
	private function is_guarded_post( $post ) {
		if ( ! $post || ! in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
			return false;
		}
		if ( ! in_array( $post->post_type, $this->target_post_types(), true ) ) {
			return false;
		}
		return true;
	}

	/** クラシックエディタのフォーム内に、許可フラグ用の隠しフィールドを出力 */
	public function print_override_field( $post ) {
		if ( ! $this->is_guarded_post( $post ) ) {
			return;
		}
		wp_nonce_field( 'hbg_override', 'hbg_nonce' );
		echo '<input type="hidden" name="hbg_override" id="hbg_override" value="">';
	}

	/** クラシックエディタの送信前チェック＆確認ポップアップ */
	public function print_editor_script() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}

		global $post;
		if ( ! $this->is_guarded_post( $post ) ) {
			return;
		}

		$settings = $this->get_settings();
		$config   = array(
			'oldChars'    => $this->count_chars( $post->post_content ),
			'oldHeadings' => $this->count_headings( $post->post_content ),
			'threshold'   => (float) $settings['threshold_percent'],
			'minChars'    => (int) $settings['min_chars'],
		);
		?>
		<script>
		( function() {
			var cfg = <?php echo wp_json_encode( $config ); ?>;
			var form = document.getElementById( 'post' );
			if ( ! form ) {
				return;
			}

			function countChars( html ) {
				var text = String( html ).replace( /<!--[\s\S]*?-->/g, '' );
				var div = document.createElement( 'div' );
				div.innerHTML = text;
				text = ( div.textContent || '' ).replace( /\s+/g, '' );
				return Array.from( text ).length;
			}

			function countHeadings( html ) {
				var m = String( html ).match( /<h[1-6][\s>]/gi );
				return m ? m.length : 0;
			}

			function currentContent() {
				if ( window.tinymce && tinymce.get( 'content' ) && ! tinymce.get( 'content' ).isHidden() ) {
					return tinymce.get( 'content' ).getContent();
				}
				var textarea = document.getElementById( 'content' );
				return textarea ? textarea.value : '';
			}

			function showDialog( opts, onConfirm ) {
				var overlay = document.createElement( 'div' );
				overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:100100;display:flex;align-items:center;justify-content:center;';
				overlay.innerHTML =
					'<div style="background:#fff;border-radius:4px;max-width:480px;width:90%;padding:24px;box-shadow:0 3px 30px rgba(0,0,0,.3);">' +
						'<h2 style="margin:0 0 12px;font-size:16px;">' + opts.title + '</h2>' +
						'<p style="margin:0 0 16px;">' + opts.body + '</p>' +
						'<label style="display:block;margin:0 0 20px;"><input type="checkbox" id="hbg-dialog-check"> ' + opts.checkLabel + '</label>' +
						'<div style="text-align:right;">' +
							'<button type="button" class="button" id="hbg-dialog-cancel">キャンセル</button> ' +
							'<button type="button" class="button button-primary" id="hbg-dialog-ok" disabled>このまま更新</button>' +
						'</div>' +
					'</div>';
				document.body.appendChild( overlay );

				var check = overlay.querySelector( '#hbg-dialog-check' );
				check.addEventListener( 'change', function() {
					overlay.querySelector( '#hbg-dialog-ok' ).disabled = ! check.checked;
				} );
				overlay.querySelector( '#hbg-dialog-cancel' ).addEventListener( 'click', function() {
					overlay.remove();
				} );
				overlay.querySelector( '#hbg-dialog-ok' ).addEventListener( 'click', function() {
					overlay.remove();
					onConfirm();
				} );
			}

			var bypass = false;
			form.addEventListener( 'submit', function( e ) {
				if ( bypass ) {
					return;
				}

				// プレビューはチェック対象外
				var previewField = document.getElementById( 'wp-preview' );
				if ( previewField && 'dopreview' === previewField.value ) {
					return;
				}

				var content        = currentContent();
				var newChars       = countChars( content );
				var newHeadings    = countHeadings( content );
				var charDrop       = cfg.oldChars > 0 ? ( cfg.oldChars - newChars ) / cfg.oldChars * 100 : 0;
				var charTrigger    = cfg.oldChars >= cfg.minChars && charDrop >= cfg.threshold;
				var headingTrigger = newHeadings < cfg.oldHeadings;
				if ( ! charTrigger && ! headingTrigger ) {
					return;
				}

				e.preventDefault();
				// 送信をブロックしたら他プラグインの送信ハンドラも止める。
				// 止めないと、同じ送信に対して複数の確認ポップアップ
				// （HTMLチェックのalert等）が同時に表示されて操作できなくなる。
				if ( e.stopImmediatePropagation ) {
					e.stopImmediatePropagation();
				}
				// 「更新」押下でWPが無効化したボタンを戻す
				if ( window.jQuery ) {
					jQuery( document ).trigger( 'autosave-enable-buttons' );
					jQuery( '#publish' ).removeClass( 'disabled' );
					jQuery( '#publishing-action .spinner' ).removeClass( 'is-active' );
				}

				var headingNote = headingTrigger
					? '見出しも前回より減っています（' + cfg.oldHeadings + '個 → ' + newHeadings + '個）。<br>'
					: '';
				var opts;
				if ( charTrigger ) {
					// 文字数の大幅減少: サーバー側でもブロックされる（チェックで上書き可）
					opts = {
						title: '本文の文字数が大きく減っています',
						body: '本文が前回より <strong>' + ( Math.round( charDrop * 10 ) / 10 ) + '%</strong> 減っています' +
							'（' + cfg.oldChars.toLocaleString() + '文字 → ' + newChars.toLocaleString() + '文字）。<br>' +
							headingNote + '誤って本文を消していないかご確認ください。',
						checkLabel: '本文の削除は意図的なものです。このまま更新します。'
					};
				} else {
					// 見出しの減少のみ: 警告（確認すればそのまま保存）
					opts = {
						title: '見出しが減っています',
						body: '本文内の見出しが前回より減っています' +
							'（' + cfg.oldHeadings + '個 → ' + newHeadings + '個）。<br>誤って見出しを消していないかご確認ください。',
						checkLabel: '見出しの削除は意図的なものです。このまま更新します。'
					};
				}

				showDialog( opts, function() {
					var override = document.getElementById( 'hbg_override' );
					if ( override ) {
						override.value = '1';
					}
					bypass = true;
					if ( form.requestSubmit ) {
						form.requestSubmit( document.getElementById( 'publish' ) );
					} else {
						form.submit();
					}
					// requestSubmit は同期的に submit イベントを発火するため、
					// ここに到達した時点で今回の送信処理は終わっている。
					// 他プラグインに送信をブロックされた場合に備えてフラグを戻し、
					// 次回の送信では再度チェックが働くようにする。
					bypass = false;
				} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * ブロックエディタ: 保存時に見出し（h1〜h6）が前回より減っていたら警告を表示する。
	 * 保存自体はブロックしない（文字数の大幅減少は check_rest_save がブロックする）。
	 */
	public function enqueue_block_editor_script() {
		global $post;
		if ( ! $this->is_guarded_post( $post ) ) {
			return;
		}

		wp_register_script( 'hbg-block-editor', false, array( 'wp-data', 'wp-notices', 'wp-edit-post' ), HBG_VERSION, true );
		wp_enqueue_script( 'hbg-block-editor' );

		wp_add_inline_script(
			'hbg-block-editor',
			'window.hbgEditorCfg = ' . wp_json_encode( array( 'oldHeadings' => $this->count_headings( $post->post_content ) ) ) . ';'
		);

		$js = <<<'JS'
( function() {
	var baseline  = window.hbgEditorCfg ? window.hbgEditorCfg.oldHeadings : 0;
	var NOTICE_ID = 'hbg-heading-warning';

	function countHeadings( html ) {
		var m = String( html || '' ).match( /<h[1-6][\s>]/gi );
		return m ? m.length : 0;
	}

	var wasSaving = false;
	wp.data.subscribe( function() {
		var editor = wp.data.select( 'core/editor' );
		if ( ! editor ) {
			return;
		}
		var isSaving = editor.isSavingPost() && ! editor.isAutosavingPost();

		// 保存開始時に見出し数をチェック（保存はブロックしない）
		if ( isSaving && ! wasSaving ) {
			var count = countHeadings( editor.getEditedPostContent() );
			if ( count < baseline ) {
				wp.data.dispatch( 'core/notices' ).createWarningNotice(
					'【本文誤削除防止プラグイン】見出しが前回より減っています（' + baseline + '個 → ' + count + '個）。誤って見出しを消していないかご確認ください。',
					{ id: NOTICE_ID }
				);
			}
		}

		// 保存が成功したら基準値を更新（意図的な削減に対して同じ警告を繰り返さない）
		if ( ! isSaving && wasSaving && editor.didPostSaveRequestSucceed && editor.didPostSaveRequestSucceed() ) {
			baseline = countHeadings( editor.getEditedPostContent() );
		}

		wasSaving = isSaving;
	} );
} )();
JS;
		wp_add_inline_script( 'hbg-block-editor', $js );
	}

	/* ---------------- 設定画面 ---------------- */

	public function add_settings_page() {
		add_options_page(
			'本文誤削除防止プラグイン',
			'本文誤削除防止',
			'manage_options',
			'honbun-guard',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'hbg_settings_group',
			self::OPTION_KEY,
			array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
		);
	}

	public function sanitize_settings( $input ) {
		$output                      = array();
		$output['threshold_percent'] = isset( $input['threshold_percent'] ) ? max( 1, min( 100, (int) $input['threshold_percent'] ) ) : 10;
		$output['min_chars']         = isset( $input['min_chars'] ) ? max( 0, (int) $input['min_chars'] ) : 100;

		return $output;
	}

	public function render_settings_page() {
		$settings = $this->get_settings();
		?>
		<div class="wrap">
			<h1>本文誤削除防止プラグイン設定</h1>
			<p>公開済み・非公開の記事・固定ページを保存したとき、本文の文字数（タグ・空白を除く）が前回より大きく減っていた場合は保存をブロックします。また、見出し（h1〜h6）が前回より減っていた場合は警告を表示します（保存はブロックしません）。</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'hbg_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hbg_threshold">減少率のしきい値（%）</label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[threshold_percent]" id="hbg_threshold" type="number" min="1" max="100" value="<?php echo esc_attr( $settings['threshold_percent'] ); ?>" class="small-text"> %
							<p class="description">前回の本文よりこの割合以上文字数が減っていたら保存をブロックします（既定: 10）。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hbg_min_chars">対象の最小文字数</label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[min_chars]" id="hbg_min_chars" type="number" min="0" value="<?php echo esc_attr( $settings['min_chars'] ); ?>" class="small-text"> 文字
							<p class="description">前回の本文がこの文字数未満の記事はチェックしません（短い記事での誤検知防止）。</p>
						</td>
					</tr>
				</table>
				<p class="description">クラシックエディタでは、更新時の確認ポップアップでチェックを入れればそのまま保存できます。ブロックエディタで意図的に本文を減らす場合は、一時的にプラグインを無効化するか、しきい値を調整してください。</p>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

add_action( 'init', array( 'Honbun_Guard', 'init' ) );

/**
 * GitHubリリースからのプラグイン自動更新。
 * （リンクミス発見ツールの LMC_Updater と同方式）
 *
 * 仕組み:
 *  - GitHubの最新リリース（tag = vX.Y.Z）を6時間ごとに確認
 *  - 現在のバージョンより新しければ、WordPressの更新一覧に表示
 *  - リリースに添付された honbun-guard.zip を更新パッケージとして使用
 *
 * リリース手順（開発側）:
 *  1. honbun-guard.php の Version と HBG_VERSION を上げる
 *  2. honbun-guard/ フォルダをzip化
 *  3. gh release create vX.Y.Z honbun-guard.zip --notes "変更内容"
 */
class Honbun_Guard_Updater {

	const REPO          = 'terasakisd/honbun-guard';
	const ASSET_NAME    = 'honbun-guard.zip';
	const TRANSIENT_KEY = 'hbg_github_release';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		// 「ダッシュボード > 更新 > もう一度確認する」でGitHubキャッシュも破棄して即時反映。
		add_action( 'load-update-core.php', array( __CLASS__, 'maybe_purge_cache' ) );
		// プラグイン一覧の行に「更新を確認」リンクを追加。
		add_filter( 'plugin_action_links_' . plugin_basename( HBG_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_post_hbg_check_update', array( __CLASS__, 'handle_check_update' ) );
		add_action( 'admin_notices', array( __CLASS__, 'check_result_notice' ) );
	}

	/**
	 * プラグイン一覧の「更新を確認」リンク。
	 */
	public static function action_links( $links ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=hbg_check_update' ), 'hbg_check_update' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">更新を確認</a>' );
		return $links;
	}

	/**
	 * 「更新を確認」クリック時: キャッシュを全部捨てて今すぐGitHubを確認する。
	 */
	public static function handle_check_update() {
		if ( ! current_user_can( 'update_plugins' ) || ! check_admin_referer( 'hbg_check_update' ) ) {
			wp_die( '権限がありません' );
		}

		delete_transient( self::TRANSIENT_KEY );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$t   = get_site_transient( 'update_plugins' );
		$key = plugin_basename( HBG_PLUGIN_FILE );

		if ( isset( $t->response[ $key ] ) ) {
			$arg = 'found&hbg_new_version=' . rawurlencode( $t->response[ $key ]->new_version );
		} else {
			$arg = 'none';
		}

		wp_safe_redirect( admin_url( 'plugins.php?hbg_update_check=' . $arg ) );
		exit;
	}

	/**
	 * 確認結果の通知表示。
	 */
	public static function check_result_notice() {
		if ( ! isset( $_GET['hbg_update_check'] ) ) {
			return;
		}
		if ( 'found' === $_GET['hbg_update_check'] ) {
			$version = sanitize_text_field( wp_unslash( $_GET['hbg_new_version'] ?? '' ) );
			echo '<div class="notice notice-warning is-dismissible"><p>本文誤削除防止プラグインの新しいバージョン'
				. ( $version ? '（' . esc_html( $version ) . '）' : '' )
				. 'があります。下の一覧から更新してください。</p></div>';
		} else {
			echo '<div class="notice notice-success is-dismissible"><p>本文誤削除防止プラグインは最新バージョン（' . esc_html( HBG_VERSION ) . '）です。</p></div>';
		}
	}

	/**
	 * 更新画面の再確認（force-check）時にリリース情報のキャッシュを破棄する。
	 */
	public static function maybe_purge_cache() {
		if ( isset( $_GET['force-check'] ) ) {
			delete_transient( self::TRANSIENT_KEY );
		}
	}

	/**
	 * GitHubの最新リリース情報を取得（6時間キャッシュ）。
	 *
	 * @return array{version: string, package: string, url: string, body: string}|null
	 */
	public static function get_latest_release() {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return $cached ?: null;
		}

		$response = wp_remote_get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'honbun-guard/' . HBG_VERSION,
			),
		) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// 失敗時は1時間だけ空キャッシュして連続アクセスを防ぐ。
			set_transient( self::TRANSIENT_KEY, array(), HOUR_IN_SECONDS );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			set_transient( self::TRANSIENT_KEY, array(), HOUR_IN_SECONDS );
			return null;
		}

		// 添付アセット（honbun-guard.zip）を探す。
		$package = '';
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( self::ASSET_NAME === ( $asset['name'] ?? '' ) ) {
				$package = (string) $asset['browser_download_url'];
				break;
			}
		}
		if ( '' === $package ) {
			set_transient( self::TRANSIENT_KEY, array(), HOUR_IN_SECONDS );
			return null;
		}

		$release = array(
			'version' => ltrim( (string) $data['tag_name'], 'vV' ),
			'package' => $package,
			'url'     => (string) ( $data['html_url'] ?? 'https://github.com/' . self::REPO ),
			'body'    => (string) ( $data['body'] ?? '' ),
		);

		set_transient( self::TRANSIENT_KEY, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * WordPressの更新チェックに新バージョンを注入。
	 */
	public static function check_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = self::get_latest_release();
		if ( ! $release || ! version_compare( $release['version'], HBG_VERSION, '>' ) ) {
			return $transient;
		}

		$plugin_file = plugin_basename( HBG_PLUGIN_FILE );

		$transient->response[ $plugin_file ] = (object) array(
			'slug'        => 'honbun-guard',
			'plugin'      => $plugin_file,
			'new_version' => $release['version'],
			'package'     => $release['package'],
			'url'         => $release['url'],
		);

		return $transient;
	}

	/**
	 * 「詳細を表示」ポップアップの情報。
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || 'honbun-guard' !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$release = self::get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => '本文誤削除防止プラグイン',
			'slug'          => 'honbun-guard',
			'version'       => $release['version'],
			'author'        => '-',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => '公開済み・非公開の記事を保存したとき、本文の文字数が前回より大きく減っていたら保存をブロックします。',
				'changelog'   => nl2br( esc_html( $release['body'] ) ),
			),
		);
	}
}

Honbun_Guard_Updater::init();
