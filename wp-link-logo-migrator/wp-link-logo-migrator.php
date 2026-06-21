<?php
/**
 * Plugin Name: 链接与 Logo 迁移工具
 * Plugin URI: https://www.maotk.com/
 * Description: 选择并迁移 WordPress 链接、分类、简介、评分和 Logo 图片。
 * Version: 2.0.0
 * Author: Mao TK
 * Author URI: https://www.maotk.com/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Link_Logo_Migrator {
	const VERSION            = '2.0.0';
	const PAGE               = 'wp-link-logo-migrator';
	const BRAND_URL          = 'https://www.maotk.com/';
	const BRAND_LOGO         = 'https://www.maotk.com/wp-content/uploads/maotk-favicon.svg';
	const MAX_PACKAGE_MB     = 500;
	const MAX_MANIFEST_MB    = 30;
	const MAX_IMAGE_MB       = 10;
	const MAX_PACKAGE_FILES  = 15000;
	const IMAGE_HASH_META    = '_wllm_sha256';
	const MANAGED_IMAGE_META = '_wllm_managed';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_wllm_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_wllm_import', array( __CLASS__, 'import' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'plugin_row_meta' ), 10, 2 );
	}

	public static function admin_menu() {
		add_management_page( '链接与 Logo 迁移', '链接与 Logo 迁移', 'manage_options', self::PAGE, array( __CLASS__, 'render_page' ) );
	}

	public static function plugin_row_meta( $links, $file ) {
		if ( plugin_basename( __FILE__ ) === $file ) {
			$links[] = '<a href="' . esc_url( self::BRAND_URL ) . '" target="_blank" rel="noopener noreferrer">访问 Mao TK</a>';
		}
		return $links;
	}

	private static function require_access() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '你没有执行此操作的权限。' );
		}
	}

	public static function render_page() {
		self::require_access();
		$links        = get_bookmarks( array( 'hide_invisible' => 0, 'orderby' => 'name', 'order' => 'ASC', 'limit' => -1 ) );
		$categories   = get_terms( array( 'taxonomy' => 'link_category', 'hide_empty' => false ) );
		$export_token = wp_generate_password( 20, false, false );
		$link_terms   = array();
		foreach ( $links as $link ) {
			$ids = wp_get_object_terms( (int) $link->link_id, 'link_category', array( 'fields' => 'ids' ) );
			$link_terms[ $link->link_id ] = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			echo '<div class="notice notice-error"><p>服务器未启用 PHP ZipArchive 扩展，无法创建或读取迁移包。</p></div>';
		}
		self::render_result();
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:10px">
				<a href="<?php echo esc_url( self::BRAND_URL ); ?>" target="_blank" rel="noopener noreferrer"><img src="<?php echo esc_url( self::BRAND_LOGO ); ?>" alt="Mao TK" width="38" height="38"></a>
				链接与 Logo 迁移
			</h1>
			<p>可以按分类或逐条选择链接，并决定是否迁移 Logo、简介、评分和分类。</p>

			<div class="card" style="max-width:1100px;padding:8px 20px 20px">
				<h2>第一步：在旧网站选择并导出</h2>
				<form id="wllm-export-form" method="post" target="wllm-download-frame" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wllm_export">
					<input type="hidden" name="export_token" value="<?php echo esc_attr( $export_token ); ?>">
					<?php wp_nonce_field( 'wllm_export' ); ?>

					<h3>1. 选择迁移内容</h3>
					<div style="display:flex;flex-wrap:wrap;gap:18px;margin-bottom:18px">
						<label><input type="checkbox" name="fields[]" value="logo" checked> Logo 图片</label>
						<label><input type="checkbox" name="fields[]" value="description" checked> 简介</label>
						<label><input type="checkbox" name="fields[]" value="rating" checked> 评分</label>
						<label><input type="checkbox" name="fields[]" value="categories" checked> 链接分类</label>
					</div>
					<p class="description">名称、网址、可见性和打开方式始终迁移；以上项目可以按需关闭。</p>

					<h3>2. 按分类筛选</h3>
					<div id="wllm-categories" style="display:flex;flex-wrap:wrap;gap:8px 16px;margin-bottom:16px">
						<label><input type="checkbox" class="wllm-category" value="all" checked> 全部分类</label>
						<?php if ( ! is_wp_error( $categories ) ) : ?>
							<?php foreach ( $categories as $category ) : ?>
								<label><input type="checkbox" class="wllm-category" value="<?php echo (int) $category->term_id; ?>"> <?php echo esc_html( $category->name ); ?>（<?php echo (int) $category->count; ?>）</label>
							<?php endforeach; ?>
						<?php endif; ?>
						<label><input type="checkbox" class="wllm-category" value="none"> 未分类</label>
					</div>

					<h3>3. 单独选择链接</h3>
					<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px">
						<input id="wllm-search" type="search" placeholder="搜索名称、网址或简介" style="min-width:300px">
						<button type="button" class="button" id="wllm-select-visible">选择当前结果</button>
						<button type="button" class="button" id="wllm-clear-visible">取消当前结果</button>
						<button type="button" class="button" id="wllm-select-all">全选</button>
						<button type="button" class="button" id="wllm-clear-all">取消全选</button>
						<strong id="wllm-selected-count" style="align-self:center"></strong>
					</div>
					<div style="max-height:430px;overflow:auto;border:1px solid #c3c4c7">
						<table class="widefat striped" id="wllm-link-table">
							<thead><tr><th style="width:38px"></th><th>名称</th><th>网址</th><th>分类</th><th>Logo</th></tr></thead>
							<tbody>
							<?php foreach ( $links as $link ) : ?>
								<?php
								$names = wp_get_object_terms( (int) $link->link_id, 'link_category', array( 'fields' => 'names' ) );
								$names = is_wp_error( $names ) ? array() : $names;
								$term_data = $link_terms[ $link->link_id ] ? implode( ',', $link_terms[ $link->link_id ] ) : 'none';
								$search = strtolower( $link->link_name . ' ' . $link->link_url . ' ' . $link->link_description );
								?>
								<tr class="wllm-link-row" data-categories="<?php echo esc_attr( $term_data ); ?>" data-search="<?php echo esc_attr( $search ); ?>">
									<td><input type="checkbox" class="wllm-link-check" name="link_ids[]" value="<?php echo (int) $link->link_id; ?>" checked></td>
									<td><strong><?php echo esc_html( $link->link_name ); ?></strong></td>
									<td><code style="word-break:break-all"><?php echo esc_html( $link->link_url ); ?></code></td>
									<td><?php echo esc_html( $names ? implode( '、', $names ) : '未分类' ); ?></td>
									<td><?php echo $link->link_image ? '<img src="' . esc_url( $link->link_image ) . '" alt="" width="32" height="32" style="object-fit:contain">' : '无'; ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<p class="description">分类和搜索只控制列表显示；最终只导出被勾选的链接。</p>
					<?php submit_button( '下载链接迁移包', 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="card" style="max-width:1100px;padding:8px 20px 20px;margin-top:20px">
				<h2>第二步：在新网站导入</h2>
				<form id="wllm-import-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wllm_import">
					<?php wp_nonce_field( 'wllm_import' ); ?>
					<p><input type="file" name="migration_package" accept=".zip,application/zip" required></p>
					<p><label><input type="checkbox" name="update_existing" value="1" checked> <strong>覆盖网址相同的已有链接</strong>（推荐，可修复之前失败的 Logo）</label></p>
					<?php submit_button( '开始导入', 'primary', 'submit', false ); ?>
				</form>
			</div>

			<iframe name="wllm-download-frame" style="display:none" title="链接迁移包下载"></iframe>
			<div id="wllm-progress" style="display:none;position:fixed;z-index:100000;inset:0;background:rgba(0,0,0,.48);align-items:center;justify-content:center">
				<div style="width:min(560px,calc(100vw - 40px));background:#fff;border-radius:8px;padding:24px;box-shadow:0 12px 50px rgba(0,0,0,.28)">
					<h2 id="wllm-progress-title" style="margin-top:0">正在处理</h2>
					<p id="wllm-progress-text">请不要关闭页面。</p>
					<div style="height:18px;background:#e5e7eb;border-radius:999px;overflow:hidden"><div id="wllm-progress-bar" style="height:100%;width:3%;background:#2271b1;border-radius:999px;transition:width .6s ease"></div></div>
					<p id="wllm-progress-time" style="color:#646970;margin-bottom:0">已用时：0 秒</p>
				</div>
			</div>
		</div>
		<script>
		(function () {
			const rows = Array.from(document.querySelectorAll('.wllm-link-row'));
			const checks = Array.from(document.querySelectorAll('.wllm-link-check'));
			const categoryChecks = Array.from(document.querySelectorAll('.wllm-category'));
			const search = document.getElementById('wllm-search');
			const count = document.getElementById('wllm-selected-count');
			const overlay = document.getElementById('wllm-progress');
			const progressTitle = document.getElementById('wllm-progress-title');
			const progressText = document.getElementById('wllm-progress-text');
			const progressBar = document.getElementById('wllm-progress-bar');
			const progressTime = document.getElementById('wllm-progress-time');
			let timer = null;

			function updateCount() {
				count.textContent = '已选择 ' + checks.filter(c => c.checked).length + ' / ' + checks.length + ' 条';
			}
			function filterRows() {
				const selected = categoryChecks.filter(c => c.checked).map(c => c.value);
				const all = selected.includes('all') || !selected.length;
				const keyword = search.value.trim().toLowerCase();
				rows.forEach(row => {
					const cats = row.dataset.categories.split(',');
					const categoryMatch = all || selected.some(value => value === 'none' ? cats.includes('none') : cats.includes(value));
					const searchMatch = !keyword || row.dataset.search.includes(keyword);
					row.style.display = categoryMatch && searchMatch ? '' : 'none';
				});
			}
			categoryChecks.forEach(box => box.addEventListener('change', function () {
				if (this.value === 'all' && this.checked) categoryChecks.filter(c => c !== this).forEach(c => c.checked = false);
				if (this.value !== 'all' && this.checked) categoryChecks.find(c => c.value === 'all').checked = false;
				filterRows();
			}));
			search.addEventListener('input', filterRows);
			checks.forEach(c => c.addEventListener('change', updateCount));
			document.getElementById('wllm-select-visible').addEventListener('click', () => { rows.filter(r => r.style.display !== 'none').forEach(r => r.querySelector('.wllm-link-check').checked = true); updateCount(); });
			document.getElementById('wllm-clear-visible').addEventListener('click', () => { rows.filter(r => r.style.display !== 'none').forEach(r => r.querySelector('.wllm-link-check').checked = false); updateCount(); });
			document.getElementById('wllm-select-all').addEventListener('click', () => { checks.forEach(c => c.checked = true); updateCount(); });
			document.getElementById('wllm-clear-all').addEventListener('click', () => { checks.forEach(c => c.checked = false); updateCount(); });
			updateCount();

			function cookieValue(name) {
				const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
				return match ? decodeURIComponent(match[1]) : '';
			}
			function clearCookie(name) {
				document.cookie = name + '=; Max-Age=0; path=<?php echo esc_js( COOKIEPATH ? COOKIEPATH : '/' ); ?>; SameSite=Lax';
			}
			function startProgress(mode) {
				let seconds = 0, percent = 3;
				overlay.style.display = 'flex';
				progressTitle.textContent = mode === 'export' ? '正在导出链接' : '正在导入链接';
				progressText.textContent = mode === 'export' ? '正在收集所选链接并打包 Logo。' : '正在上传并写入链接、分类和 Logo。';
				progressBar.style.width = percent + '%';
				timer = setInterval(() => {
					seconds++;
					percent = Math.min(94, percent + (percent < 60 ? 2.2 : percent < 82 ? .8 : .25));
					progressBar.style.width = percent + '%';
					progressTime.textContent = '已用时：' + seconds + ' 秒';
				}, 1000);
			}
			document.getElementById('wllm-export-form').addEventListener('submit', function (event) {
				if (!checks.some(c => c.checked)) {
					event.preventDefault();
					alert('请至少选择一条链接。');
					return;
				}
				const token = this.querySelector('[name="export_token"]').value;
				const cookie = 'wllm_export_' + token;
				clearCookie(cookie);
				startProgress('export');
				const poll = setInterval(() => {
					const result = cookieValue(cookie);
					if (result.indexOf('done-') === 0) {
						clearInterval(poll);
						clearCookie(cookie);
						clearInterval(timer);
						progressBar.style.width = '100%';
						progressTitle.textContent = '导出完成';
						progressText.textContent = '已导出 ' + (parseInt(result.substring(5), 10) || 0) + ' 条链接，浏览器应已开始下载。';
						setTimeout(() => overlay.style.display = 'none', 2400);
					}
				}, 700);
			});
			document.getElementById('wllm-import-form').addEventListener('submit', () => startProgress('import'));
		}());
		</script>
		<?php
	}

	private static function render_result() {
		$result = get_transient( 'wllm_result_' . get_current_user_id() );
		if ( ! $result ) {
			return;
		}
		delete_transient( 'wllm_result_' . get_current_user_id() );
		$failed_links = isset( $result['failed_links'] ) ? (array) $result['failed_links'] : array();
		$failed_logos = isset( $result['failed_logos'] ) ? (array) $result['failed_logos'] : array();
		$warnings     = isset( $result['warnings'] ) ? (array) $result['warnings'] : array();
		$problems     = count( $failed_links ) + count( $failed_logos ) + count( $warnings );
		echo '<div class="notice ' . ( $problems ? 'notice-warning' : 'notice-success' ) . ' is-dismissible"><p><strong>';
		echo esc_html(
			sprintf(
				'导入完成：新增 %1$d，覆盖 %2$d，跳过 %3$d，Logo 新增 %4$d，链接失败 %5$d，Logo 失败 %6$d，其他警告 %7$d。',
				isset( $result['created'] ) ? (int) $result['created'] : 0,
				isset( $result['updated'] ) ? (int) $result['updated'] : 0,
				isset( $result['skipped'] ) ? (int) $result['skipped'] : 0,
				isset( $result['logos'] ) ? (int) $result['logos'] : 0,
				count( $failed_links ),
				count( $failed_logos ),
				count( $warnings )
			)
		);
		echo '</strong></p>';
		if ( $failed_links ) {
			echo '<details open><summary><strong>失败链接（' . count( $failed_links ) . '）</strong></summary><ul>';
			foreach ( $failed_links as $failure ) {
				echo '<li><strong>' . esc_html( $failure['name'] ) . '</strong>：' . esc_html( $failure['reason'] );
				if ( ! empty( $failure['url'] ) ) echo '<br><code>' . esc_html( $failure['url'] ) . '</code>';
				echo '</li>';
			}
			echo '</ul></details>';
		}
		if ( $failed_logos ) {
			echo '<details open><summary><strong>失败 Logo（' . count( $failed_logos ) . '）</strong></summary><ul>';
			foreach ( $failed_logos as $failure ) {
				echo '<li><strong>' . esc_html( $failure['name'] ) . '</strong>：' . esc_html( $failure['reason'] );
				if ( ! empty( $failure['url'] ) ) echo '<br><code>' . esc_html( $failure['url'] ) . '</code>';
				echo '</li>';
			}
			echo '</ul></details>';
		}
		if ( $warnings ) {
			echo '<details><summary><strong>其他警告（' . count( $warnings ) . '）</strong></summary><ul>';
			foreach ( $warnings as $warning ) echo '<li>' . esc_html( $warning ) . '</li>';
			echo '</ul></details>';
		}
		if ( ! $problems ) echo '<p>全部所选链接和已打包 Logo 均导入成功。</p>';
		echo '</div>';
	}

	public static function export() {
		self::require_access();
		check_admin_referer( 'wllm_export' );
		if ( ! class_exists( 'ZipArchive' ) ) wp_die( '服务器未启用 PHP ZipArchive 扩展。' );
		require_once ABSPATH . 'wp-admin/includes/bookmark.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 0 );

		$ids = isset( $_POST['link_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['link_ids'] ) ) ) ) ) : array();
		if ( ! $ids ) wp_die( '请至少选择一条链接。', '未选择链接', array( 'back_link' => true ) );
		$allowed_fields = array( 'logo', 'description', 'rating', 'categories' );
		$fields = isset( $_POST['fields'] ) ? array_values( array_intersect( $allowed_fields, array_map( 'sanitize_key', (array) wp_unslash( $_POST['fields'] ) ) ) ) : array();

		$tmp = wp_tempnam( 'links-migration.zip' );
		if ( ! $tmp ) wp_die( '无法创建临时文件。' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			@unlink( $tmp );
			wp_die( '无法创建 ZIP 迁移包。' );
		}
		$manifest = array(
			'format' => 'wp-link-logo-migrator',
			'version' => self::VERSION,
			'exported_at' => gmdate( 'c' ),
			'source_url' => home_url( '/' ),
			'fields' => $fields,
			'links' => array(),
		);
		$packed = array();
		foreach ( $ids as $id ) {
			$link = get_bookmark( $id );
			if ( ! $link || is_wp_error( $link ) ) continue;
			$categories = in_array( 'categories', $fields, true ) ? wp_get_object_terms( $id, 'link_category', array( 'fields' => 'names' ) ) : array();
			if ( is_wp_error( $categories ) ) $categories = array();
			$item = array(
				'source_id' => $id,
				'name' => $link->link_name,
				'url' => $link->link_url,
				'description' => in_array( 'description', $fields, true ) ? $link->link_description : '',
				'image_url' => in_array( 'logo', $fields, true ) ? $link->link_image : '',
				'image_file' => '',
				'image_mime' => '',
				'target' => $link->link_target,
				'visible' => $link->link_visible,
				'rating' => in_array( 'rating', $fields, true ) ? (int) $link->link_rating : 0,
				'rel' => $link->link_rel,
				'notes' => $link->link_notes,
				'rss' => $link->link_rss,
				'categories' => array_values( $categories ),
			);
			if ( in_array( 'logo', $fields, true ) && $link->link_image ) {
				$image = self::read_image( $link->link_image );
				if ( ! is_wp_error( $image ) ) {
					$type = self::detect_image_type( $image['body'], $image['mime'], $link->link_image );
					if ( ! is_wp_error( $type ) ) {
						$bytes = $type['bytes'];
						$hash = hash( 'sha256', $bytes );
						$file = 'images/' . $hash . '.' . $type['extension'];
						$item['image_file'] = $file;
						$item['image_mime'] = $type['mime'];
						if ( empty( $packed[ $file ] ) ) {
							$zip->addFromString( $file, $bytes );
							$packed[ $file ] = true;
						}
					} else {
						$item['image_export_error'] = $type->get_error_message();
					}
				} else {
					$item['image_export_error'] = $image->get_error_message();
				}
			}
			$manifest['links'][] = $item;
		}
		$json = wp_json_encode( $manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json || ! $zip->addFromString( 'manifest.json', $json ) ) {
			$zip->close(); @unlink( $tmp ); wp_die( '无法写入迁移清单。' );
		}
		$zip->close();
		$token = isset( $_POST['export_token'] ) ? sanitize_key( wp_unslash( $_POST['export_token'] ) ) : '';
		if ( $token ) setcookie( 'wllm_export_' . $token, 'done-' . count( $manifest['links'] ), array( 'expires' => time() + 300, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax' ) );
		$filename = 'wordpress-links-' . gmdate( 'Y-m-d-His' ) . '.zip';
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp );
		@unlink( $tmp );
		exit;
	}

	private static function read_image( $url ) {
		$url = self::absolute_image_url( $url );
		if ( ! $url ) return new WP_Error( 'invalid_url', 'Logo 地址无效' );
		$attachment_id = attachment_url_to_postid( $url );
		if ( $attachment_id ) {
			$path = get_attached_file( $attachment_id );
			if ( $path && is_readable( $path ) && filesize( $path ) <= self::MAX_IMAGE_MB * MB_IN_BYTES ) {
				$body = file_get_contents( $path );
				if ( false !== $body && '' !== $body ) return array( 'body' => $body, 'mime' => (string) get_post_mime_type( $attachment_id ) );
			}
		}
		$response = wp_safe_remote_get( $url, array( 'timeout' => 25, 'redirection' => 5, 'limit_response_size' => self::MAX_IMAGE_MB * MB_IN_BYTES, 'user-agent' => 'MaoTK Link Migrator/' . self::VERSION ) );
		if ( is_wp_error( $response ) ) return $response;
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) return new WP_Error( 'http_error', '图片服务器返回状态码 ' . wp_remote_retrieve_response_code( $response ) );
		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) return new WP_Error( 'empty_image', 'Logo 内容为空' );
		return array( 'body' => $body, 'mime' => strtolower( trim( strtok( (string) wp_remote_retrieve_header( $response, 'content-type' ), ';' ) ) ) );
	}

	private static function absolute_image_url( $url ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
		if ( 0 === strpos( $url, '//' ) ) $url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		elseif ( 0 === strpos( $url, '/' ) ) $url = home_url( $url );
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	private static function detect_image_type( $bytes, $hint_mime = '', $hint_name = '' ) {
		$map = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif', 'image/bmp' => 'bmp', 'image/x-ms-bmp' => 'bmp', 'image/vnd.microsoft.icon' => 'ico', 'image/x-icon' => 'ico' );
		$info = @getimagesizefromstring( $bytes );
		if ( is_array( $info ) && ! empty( $info['mime'] ) && isset( $map[ $info['mime'] ] ) ) return array( 'mime' => $info['mime'], 'extension' => $map[ $info['mime'] ], 'bytes' => $bytes );
		$trimmed = ltrim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $bytes ) );
		if ( preg_match( '/<svg(?:\s|>)/i', substr( $trimmed, 0, 8192 ) ) ) {
			$clean = self::sanitize_svg( $trimmed );
			if ( is_wp_error( $clean ) ) return $clean;
			return array( 'mime' => 'image/svg+xml', 'extension' => 'svg', 'bytes' => $clean );
		}
		return new WP_Error( 'unsupported_image', '无法识别图片格式（' . sanitize_text_field( $hint_mime . ' ' . wp_basename( $hint_name ) ) . '）' );
	}

	private static function sanitize_svg( $svg ) {
		if ( ! class_exists( 'DOMDocument' ) ) return new WP_Error( 'svg_dom_missing', '服务器缺少 DOM 扩展，无法安全处理 SVG' );
		$previous = libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$loaded = $dom->loadXML( $svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors(); libxml_use_internal_errors( $previous );
		if ( ! $loaded || ! $dom->documentElement || 'svg' !== strtolower( $dom->documentElement->localName ) ) return new WP_Error( 'invalid_svg', 'SVG 文件结构无效' );
		$xpath = new DOMXPath( $dom );
		foreach ( array( 'script','style','foreignobject','iframe','object','embed','audio','video','animate','animatetransform','animatemotion','set' ) as $element ) {
			$nodes = $xpath->query( '//*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="' . $element . '"]' );
			if ( $nodes ) for ( $i = $nodes->length - 1; $i >= 0; $i-- ) if ( $nodes->item( $i )->parentNode ) $nodes->item( $i )->parentNode->removeChild( $nodes->item( $i ) );
		}
		$nodes = $xpath->query( '//*' );
		if ( $nodes ) foreach ( $nodes as $node ) for ( $i = $node->attributes->length - 1; $i >= 0; $i-- ) {
			$attribute = $node->attributes->item( $i );
			$name = strtolower( $attribute->name );
			$value = trim( html_entity_decode( $attribute->value, ENT_QUOTES, 'UTF-8' ) );
			$is_url = in_array( $name, array( 'href','xlink:href','src' ), true );
			$is_safe = 0 === strpos( $value, '#' ) || (bool) preg_match( '/^data:image\/(?:png|jpeg|gif|webp|avif|bmp);base64,/i', $value );
			if ( 0 === strpos( $name, 'on' ) || ( $is_url && ! $is_safe ) || ( 'style' === $name && preg_match( '/(?:expression\s*\(|javascript\s*:|url\s*\(\s*["\']?\s*(?:javascript|data:text\/html))/i', $value ) ) ) $node->removeAttributeNode( $attribute );
		}
		$clean = $dom->saveXML( $dom->documentElement );
		return $clean ? $clean : new WP_Error( 'svg_save_failed', '无法保存清理后的 SVG' );
	}

	public static function import() {
		self::require_access();
		check_admin_referer( 'wllm_import' );
		if ( ! class_exists( 'ZipArchive' ) ) wp_die( '服务器未启用 PHP ZipArchive 扩展。' );
		if ( empty( $_FILES['migration_package']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['migration_package']['error'] || ! is_uploaded_file( $_FILES['migration_package']['tmp_name'] ) ) wp_die( '迁移包上传失败。' );
		if ( (int) $_FILES['migration_package']['size'] > self::MAX_PACKAGE_MB * MB_IN_BYTES ) wp_die( '迁移包不能超过 ' . self::MAX_PACKAGE_MB . 'MB。' );
		require_once ABSPATH . 'wp-admin/includes/bookmark.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 0 );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $_FILES['migration_package']['tmp_name'] ) ) wp_die( '无法打开 ZIP 迁移包。' );
		if ( $zip->numFiles > self::MAX_PACKAGE_FILES ) { $zip->close(); wp_die( '迁移包内文件数量异常。' ); }
		$stat = $zip->statName( 'manifest.json' );
		if ( ! $stat || (int) $stat['size'] > self::MAX_MANIFEST_MB * MB_IN_BYTES ) { $zip->close(); wp_die( '迁移清单不存在或体积异常。' ); }
		$manifest = json_decode( (string) $zip->getFromName( 'manifest.json' ), true );
		if ( ! is_array( $manifest ) || 'wp-link-logo-migrator' !== ( isset( $manifest['format'] ) ? $manifest['format'] : '' ) || ! isset( $manifest['links'] ) || ! is_array( $manifest['links'] ) ) { $zip->close(); wp_die( '这不是有效的链接迁移包。' ); }
		$fields = isset( $manifest['fields'] ) && is_array( $manifest['fields'] ) ? $manifest['fields'] : array( 'logo','description','rating','categories' );
		$update = ! empty( $_POST['update_existing'] );
		$result = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'logos' => 0, 'failed_links' => array(), 'failed_logos' => array(), 'warnings' => array() );
		$seen = array();
		foreach ( $manifest['links'] as $index => $item ) {
			if ( ! is_array( $item ) ) { $result['failed_links'][] = array( 'name' => '第 ' . ( $index + 1 ) . ' 条', 'url' => '', 'reason' => '数据格式无效' ); continue; }
			$name = sanitize_text_field( isset( $item['name'] ) ? $item['name'] : '' );
			$url = esc_url_raw( isset( $item['url'] ) ? $item['url'] : '', array( 'http','https' ) );
			if ( ! $name || ! $url ) { $result['failed_links'][] = array( 'name' => $name ? $name : '第 ' . ( $index + 1 ) . ' 条', 'url' => $url, 'reason' => '缺少名称或有效网址' ); continue; }
			$normalized = self::normalize_url( $url );
			if ( isset( $seen[ $normalized ] ) ) $result['warnings'][] = $name . '：迁移包内存在重复网址，请检查旧站是否有重复链接。';
			$seen[ $normalized ] = true;
			$existing_id = self::find_existing_link_id( $url );
			if ( $existing_id && ! $update ) { ++$result['skipped']; continue; }
			$old = $existing_id ? get_bookmark( $existing_id ) : null;
			$old_image = $old && ! is_wp_error( $old ) ? (string) $old->link_image : '';
			$package_image = esc_url_raw( isset( $item['image_url'] ) ? $item['image_url'] : '', array( 'http','https' ) );
			$image_url = in_array( 'logo', $fields, true ) ? ( $old_image ? $old_image : $package_image ) : $old_image;
			$created_attachment = 0;
			if ( in_array( 'logo', $fields, true ) && ! empty( $item['image_file'] ) ) {
				$uploaded = self::import_image( $zip, $item, $name );
				if ( is_wp_error( $uploaded ) ) {
					$result['failed_logos'][] = array( 'name' => $name, 'url' => isset( $item['image_url'] ) ? $item['image_url'] : '', 'reason' => $uploaded->get_error_message() );
				} else {
					$image_url = $uploaded['url'];
					$created_attachment = $uploaded['created'] ? (int) $uploaded['attachment_id'] : 0;
					if ( $uploaded['created'] ) ++$result['logos'];
				}
			} elseif ( in_array( 'logo', $fields, true ) && ! empty( $item['image_export_error'] ) ) {
				$result['failed_logos'][] = array( 'name' => $name, 'url' => isset( $item['image_url'] ) ? $item['image_url'] : '', 'reason' => '旧站导出时未能打包：' . sanitize_text_field( $item['image_export_error'] ) );
			} elseif ( in_array( 'logo', $fields, true ) && ! $old_image ) {
				$image_url = $package_image;
			}
			$data = array(
				'link_name' => $name,
				'link_url' => $url,
				'link_description' => in_array( 'description', $fields, true ) ? sanitize_textarea_field( isset( $item['description'] ) ? $item['description'] : '' ) : ( $old ? $old->link_description : '' ),
				'link_image' => $image_url,
				'link_target' => self::sanitize_target( isset( $item['target'] ) ? $item['target'] : '' ),
				'link_visible' => isset( $item['visible'] ) && 'N' === $item['visible'] ? 'N' : 'Y',
				'link_rating' => in_array( 'rating', $fields, true ) ? max( 0, min( 10, (int) ( isset( $item['rating'] ) ? $item['rating'] : 0 ) ) ) : ( $old ? (int) $old->link_rating : 0 ),
				'link_rel' => sanitize_text_field( isset( $item['rel'] ) ? $item['rel'] : '' ),
				'link_notes' => sanitize_textarea_field( isset( $item['notes'] ) ? $item['notes'] : '' ),
				'link_rss' => esc_url_raw( isset( $item['rss'] ) ? $item['rss'] : '', array( 'http','https' ) ),
			);
			if ( $existing_id ) { $data['link_id'] = $existing_id; $link_id = wp_update_link( $data ); }
			else $link_id = wp_insert_link( $data, true );
			if ( is_wp_error( $link_id ) || ! $link_id ) {
				if ( $created_attachment ) wp_delete_attachment( $created_attachment, true );
				$result['failed_links'][] = array( 'name' => $name, 'url' => $url, 'reason' => is_wp_error( $link_id ) ? $link_id->get_error_message() : '数据库未返回链接 ID' );
				continue;
			}
			if ( in_array( 'categories', $fields, true ) ) {
				$term_ids = self::import_categories( isset( $item['categories'] ) ? $item['categories'] : array(), $name, $result );
				$set = wp_set_object_terms( (int) $link_id, $term_ids, 'link_category', false );
				if ( is_wp_error( $set ) ) $result['warnings'][] = $name . '：分类更新失败（' . $set->get_error_message() . '）';
			}
			if ( $existing_id ) {
				++$result['updated'];
				if ( $old_image && $old_image !== $image_url ) self::maybe_delete_old_managed_image( $old_image );
			} else ++$result['created'];
		}
		$zip->close();
		set_transient( 'wllm_result_' . get_current_user_id(), $result, 15 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE ) );
		exit;
	}

	private static function sanitize_target( $target ) {
		return in_array( (string) $target, array( '_blank','_top','_none' ), true ) ? $target : '';
	}

	private static function normalize_url( $url ) {
		$parts = wp_parse_url( trim( (string) $url ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) return untrailingslashit( strtolower( trim( (string) $url ) ) );
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
		$host = strtolower( rtrim( $parts['host'], '.' ) );
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		if ( ( 'http' === $scheme && ':80' === $port ) || ( 'https' === $scheme && ':443' === $port ) ) $port = '';
		$path = isset( $parts['path'] ) ? '/' . ltrim( $parts['path'], '/' ) : '';
		$path = '/' === $path ? '' : untrailingslashit( $path );
		$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
		return $scheme . '://' . $host . $port . $path . $query;
	}

	private static function find_existing_link_id( $url ) {
		global $wpdb;
		$exact = $wpdb->get_var( $wpdb->prepare( "SELECT link_id FROM {$wpdb->links} WHERE link_url = %s ORDER BY link_id ASC LIMIT 1", $url ) );
		if ( $exact ) return (int) $exact;
		$target = self::normalize_url( $url );
		$rows = $wpdb->get_results( "SELECT link_id, link_url FROM {$wpdb->links} ORDER BY link_id ASC" );
		foreach ( (array) $rows as $row ) if ( self::normalize_url( $row->link_url ) === $target ) return (int) $row->link_id;
		return 0;
	}

	private static function import_categories( $categories, $name, &$result ) {
		$ids = array();
		foreach ( (array) $categories as $category ) {
			$category = sanitize_text_field( $category );
			if ( '' === $category ) continue;
			$term = term_exists( $category, 'link_category' );
			if ( ! $term ) $term = wp_insert_term( $category, 'link_category' );
			if ( is_wp_error( $term ) ) { $result['warnings'][] = $name . '：无法创建分类“' . $category . '”（' . $term->get_error_message() . '）'; continue; }
			$ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
		}
		return array_values( array_unique( $ids ) );
	}

	private static function import_image( ZipArchive $zip, $item, $name ) {
		$file = ltrim( str_replace( '\\', '/', (string) $item['image_file'] ), '/' );
		if ( 0 !== strpos( $file, 'images/' ) || false !== strpos( $file, '../' ) || false !== strpos( $file, "\0" ) ) return new WP_Error( 'unsafe_path', '图片路径不安全' );
		$stat = $zip->statName( $file );
		if ( ! $stat || (int) $stat['size'] <= 0 || (int) $stat['size'] > self::MAX_IMAGE_MB * MB_IN_BYTES ) return new WP_Error( 'invalid_size', '图片不存在、为空或超过 ' . self::MAX_IMAGE_MB . 'MB' );
		$bytes = $zip->getFromName( $file );
		if ( false === $bytes ) return new WP_Error( 'read_failed', '无法读取图片' );
		$type = self::detect_image_type( $bytes, isset( $item['image_mime'] ) ? $item['image_mime'] : '', $file );
		if ( is_wp_error( $type ) ) return $type;
		$bytes = $type['bytes']; $hash = hash( 'sha256', $bytes );
		$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => self::IMAGE_HASH_META, 'meta_value' => $hash, 'no_found_rows' => true ) );
		if ( $ids ) {
			$url = wp_get_attachment_url( $ids[0] );
			if ( $url ) return array( 'url' => $url, 'attachment_id' => (int) $ids[0], 'created' => false );
		}
		$base = sanitize_file_name( $name . '-logo' );
		$upload = wp_upload_bits( ( $base ? $base : 'link-logo' ) . '-' . substr( $hash, 0, 8 ) . '.' . $type['extension'], null, $bytes );
		if ( ! empty( $upload['error'] ) ) return new WP_Error( 'upload_failed', $upload['error'] );
		$id = wp_insert_attachment( array( 'post_mime_type' => $type['mime'], 'post_title' => $name . ' Logo', 'post_status' => 'inherit' ), $upload['file'] );
		if ( is_wp_error( $id ) || ! $id ) { @unlink( $upload['file'] ); return is_wp_error( $id ) ? $id : new WP_Error( 'attachment_failed', '无法创建媒体库记录' ); }
		update_post_meta( $id, self::IMAGE_HASH_META, $hash );
		update_post_meta( $id, self::MANAGED_IMAGE_META, '1' );
		if ( 'image/svg+xml' !== $type['mime'] ) {
			$metadata = wp_generate_attachment_metadata( $id, $upload['file'] );
			if ( ! is_wp_error( $metadata ) && $metadata ) wp_update_attachment_metadata( $id, $metadata );
		}
		return array( 'url' => $upload['url'], 'attachment_id' => (int) $id, 'created' => true );
	}

	private static function maybe_delete_old_managed_image( $url ) {
		global $wpdb;
		$id = attachment_url_to_postid( $url );
		if ( ! $id || '1' !== get_post_meta( $id, self::MANAGED_IMAGE_META, true ) ) return;
		$uses = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->links} WHERE link_image = %s", $url ) );
		if ( 0 === $uses ) wp_delete_attachment( $id, true );
	}
}

WP_Link_Logo_Migrator::init();
