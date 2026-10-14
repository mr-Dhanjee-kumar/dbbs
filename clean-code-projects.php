<?php
/**
 * Plugin Name:       Clean Code Projects
 * Plugin URI:        https://github.com/mr-Dhanjee-kumar/dbbs
 * Description:       Store HTML/CSS/JS projects in a custom post type and open each one on a 100% clean page — no theme, no wp_head, no WordPress CSS or JS. Includes a beautiful grid shortcode to list every project.
 * Version:           1.1.0
 * Author:            mr-Dhanjee-kumar
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       clean-code-projects
 * Requires PHP:      7.4
 * Requires at least: 5.9
 *
 * @package CCP\Clean_Code_Projects
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access denied.
}

if ( ! class_exists( 'CCP_Clean_Code_Projects' ) ) {

	/**
	 * Everything the plugin does lives here.
	 */
	final class CCP_Clean_Code_Projects {

		/* ------------------------------------------------------------------ *
		 * Constants
		 * ------------------------------------------------------------------ */

		const VERSION   = '1.1.0';
		const CPT       = 'ccp_project';
		const META_LINK = '_ccp_link';
		const META_CODE = '_ccp_code';
		const META_MODE = '_ccp_mode';
		const OPTION    = 'ccp_options';
		const META_FILE = '_ccp_file';
		const UPLOAD_DIR = 'clean-code-projects';
		const PAGE_OPT  = 'ccp_projects_page_id';

		/** @var CCP_Clean_Code_Projects|null */
		private static $instance = null;

		/**
		 * Singleton.
		 *
		 * @return CCP_Clean_Code_Projects
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Wire up every hook.
		 */
		private function __construct() {

			// Content type + storage.
			add_action( 'init', array( $this, 'register_post_type' ) );
			add_action( 'init', array( $this, 'register_shortcodes' ) );
			add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
			add_action( 'save_post_' . self::CPT, array( $this, 'save_meta' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

			// Admin list table.
			add_filter( 'manage_' . self::CPT . '_posts_columns', array( $this, 'columns' ) );
			add_action( 'manage_' . self::CPT . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
			add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
			add_filter( 'post_updated_messages', array( $this, 'post_updated_messages' ) );

			// THE clean page: no theme, no CSS, no JS.
			add_action( 'template_redirect', array( $this, 'maybe_render_clean_page' ), 0 );

			// HTML files mirrored into wp-content/uploads/clean-code-projects/.
			add_action( 'before_delete_post', array( $this, 'delete_project_file_for_post' ) );

			// Super Admin control centre (actions are nonce + capability checked).
			add_action( 'admin_init', array( $this, 'handle_super_admin_actions' ) );

			// Settings + tooling.
			add_action( 'admin_menu', array( $this, 'settings_menu' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'action_links' ) );
			add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		}

		/* ------------------------------------------------------------------ *
		 * 1. Custom post type
		 * ------------------------------------------------------------------ */

		/**
		 * Register the "Projects" post type.
		 *
		 * @return void
		 */
		public function register_post_type() {

			$labels = array(
				'name'                  => __( 'Projects', 'clean-code-projects' ),
				'singular_name'         => __( 'Project', 'clean-code-projects' ),
				'add_new'               => __( 'Add New Project', 'clean-code-projects' ),
				'add_new_item'          => __( 'Add New Project', 'clean-code-projects' ),
				'edit_item'             => __( 'Edit Project', 'clean-code-projects' ),
				'new_item'              => __( 'New Project', 'clean-code-projects' ),
				'view_item'             => __( 'View Clean Page', 'clean-code-projects' ),
				'view_items'            => __( 'View Projects', 'clean-code-projects' ),
				'search_items'          => __( 'Search Projects', 'clean-code-projects' ),
				'not_found'             => __( 'No projects found.', 'clean-code-projects' ),
				'not_found_in_trash'    => __( 'No projects found in Trash.', 'clean-code-projects' ),
				'all_items'             => __( 'All Projects', 'clean-code-projects' ),
				'menu_name'             => __( 'Clean Projects', 'clean-code-projects' ),
				'featured_image'        => __( 'Project thumbnail', 'clean-code-projects' ),
				'set_featured_image'    => __( 'Set project thumbnail', 'clean-code-projects' ),
				'remove_featured_image' => __( 'Remove project thumbnail', 'clean-code-projects' ),
				'use_featured_image'    => __( 'Use as project thumbnail', 'clean-code-projects' ),
			);

			$args = array(
				'labels'              => $labels,
				'public'              => true,
				'publicly_queryable'  => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => true,
				'menu_position'       => 24,
				'menu_icon'           => 'dashicons-html',
				'has_archive'         => false,     // We use the grid shortcode instead of an archive.
				'exclude_from_search' => true,
				'hierarchical'        => false,
				'supports'            => array( 'title', 'thumbnail', 'author', 'revisions' ),
				'rewrite'             => array(
					'slug'       => apply_filters( 'ccp_rewrite_slug', 'project' ),
					'with_front' => false,
				),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			);

			register_post_type( self::CPT, $args );
		}

		/**
		 * Nicer "View project" wording than the default messages.
		 *
		 * @param array $messages Existing messages.
		 * @return array
		 */
		public function post_updated_messages( $messages ) {
			$permalink = '';
			$post      = get_post();

			if ( $post && self::CPT === $post->post_type ) {
				$permalink = sprintf(
					' <a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
					esc_url( get_permalink( $post ) ),
					esc_html__( 'Open clean page', 'clean-code-projects' )
				);
			}

			$messages[ self::CPT ] = array(
				0  => '',
				1  => __( 'Project updated.', 'clean-code-projects' ) . $permalink,
				2  => __( 'Custom field updated.', 'clean-code-projects' ),
				3  => __( 'Custom field deleted.', 'clean-code-projects' ),
				4  => __( 'Project updated.', 'clean-code-projects' ),
				5  => '',
				6  => __( 'Project published.', 'clean-code-projects' ) . $permalink,
				7  => __( 'Project saved.', 'clean-code-projects' ),
				8  => __( 'Project submitted.', 'clean-code-projects' ),
				9  => __( 'Project scheduled.', 'clean-code-projects' ),
				10 => __( 'Project draft updated.', 'clean-code-projects' ) . $permalink,
			);

			return $messages;
		}

		/* ------------------------------------------------------------------ *
		 * 2. Meta box: Link + Code
		 * ------------------------------------------------------------------ */

		/**
		 * Add the "Project Code" meta box.
		 *
		 * @return void
		 */
		public function add_meta_boxes() {
			add_meta_box(
				'ccp_project_code',
				__( 'Project Code (HTML / CSS / JS)', 'clean-code-projects' ),
				array( $this, 'render_meta_box' ),
				self::CPT,
				'normal',
				'high'
			);

			remove_meta_box( 'slugdiv', self::CPT, 'normal' );
		}

		/**
		 * Render the meta box.
		 *
		 * @param WP_Post $post Current post.
		 * @return void
		 */
		public function render_meta_box( $post ) {
			wp_nonce_field( 'ccp_save_project', 'ccp_project_nonce' );

			$link = get_post_meta( $post->ID, self::META_LINK, true );
			$code = get_post_meta( $post->ID, self::META_CODE, true );
			$mode = get_post_meta( $post->ID, self::META_MODE, true );
			$mode = $mode ? $mode : 'auto';
			$url  = get_permalink( $post );
			?>
			<p class="ccp-field">
				<label for="ccp_link"><strong><?php esc_html_e( 'Project link (optional)', 'clean-code-projects' ); ?></strong></label>
				<input type="url" id="ccp_link" name="ccp_link" class="widefat code" placeholder="https://example.com/demo"
					value="<?php echo esc_attr( $link ); ?>" />
				<span class="description"><?php esc_html_e( 'The live/original URL of the project. Shown on the grid card.', 'clean-code-projects' ); ?></span>
			</p>

			<p class="ccp-field">
				<label for="ccp_mode"><strong><?php esc_html_e( 'Output mode', 'clean-code-projects' ); ?></strong></label>
				<select id="ccp_mode" name="ccp_mode">
					<option value="auto" <?php selected( $mode, 'auto' ); ?>><?php esc_html_e( 'Auto — detect a full HTML document', 'clean-code-projects' ); ?></option>
					<option value="full" <?php selected( $mode, 'full' ); ?>><?php esc_html_e( 'Verbatim — print my code byte-for-byte (no doctype, head or body added)', 'clean-code-projects' ); ?></option>
					<option value="fragment" <?php selected( $mode, 'fragment' ); ?>><?php esc_html_e( 'Skeleton — wrap my snippet in a bare doctype + charset + viewport', 'clean-code-projects' ); ?></option>
				</select>
				<span class="description"><?php esc_html_e( 'Either way the page contains ONLY your code: no theme, no wp_head, no body classes, no WordPress CSS or JS of any kind.', 'clean-code-projects' ); ?></span>
			</p>

			<p class="ccp-field">
				<label for="ccp_code"><strong><?php esc_html_e( 'Code', 'clean-code-projects' ); ?></strong></label>
				<textarea id="ccp_code" name="ccp_code" class="widefat code" rows="20"
					spellcheck="false" autocapitalize="off" autocomplete="off"
					placeholder="<h1>Hello world</h1>&#10;<style>h1{color:red}</style>"><?php echo esc_textarea( $code ); ?></textarea>
				<span class="description">
					<?php esc_html_e( 'Paste a complete HTML document (with <html>, <head>, <style>, <script>) or just a snippet.', 'clean-code-projects' ); ?>
				</span>
			</p>

			<?php $ccp_file = (string) get_post_meta( $post->ID, self::META_FILE, true ); ?>
			<?php if ( $ccp_file ) : ?>
				<p class="ccp-field">
					<strong><?php esc_html_e( 'Stored HTML file (uploads folder)', 'clean-code-projects' ); ?></strong><br>
					<code class="ccp-url"><?php echo esc_html( 'uploads/' . self::UPLOAD_DIR . '/' . $ccp_file ); ?></code>
					<a class="button button-small" href="<?php echo esc_url( $this->uploads_url( $ccp_file ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open file ↗', 'clean-code-projects' ); ?></a>
				</p>
			<?php endif; ?>

			<?php if ( $url && 'publish' === $post->post_status ) : ?>
				<p class="ccp-field">
					<a class="button button-primary" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Open clean page ↗', 'clean-code-projects' ); ?>
					</a>
					<button type="button" class="button ccp-copy" data-ccp-copy="<?php echo esc_url( $url ); ?>">
						<?php esc_html_e( 'Copy clean page URL', 'clean-code-projects' ); ?>
					</button>
					<code class="ccp-url"><?php echo esc_html( $url ); ?></code>
				</p>
			<?php endif; ?>
			<?php
		}

		/**
		 * Save the meta box values.
		 *
		 * @param int     $post_id Post ID.
		 * @param WP_Post $post    Post object.
		 * @return void
		 */
		public function save_meta( $post_id, $post ) {

			$valid_nonce = isset( $_POST['ccp_project_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ccp_project_nonce'] ) ), 'ccp_save_project' );

			if ( ! $valid_nonce ) {
				return;
			}
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}
			if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
				return;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			// Link — always a plain URL (or empty).
			$link = isset( $_POST['ccp_link'] ) ? esc_url_raw( trim( wp_unslash( $_POST['ccp_link'] ) ) ) : '';
			if ( $link ) {
				update_post_meta( $post_id, self::META_LINK, $link );
			} else {
				delete_post_meta( $post_id, self::META_LINK );
			}

			// Mode.
			$mode = isset( $_POST['ccp_mode'] ) ? sanitize_key( wp_unslash( $_POST['ccp_mode'] ) ) : 'auto';
			if ( ! in_array( $mode, array( 'auto', 'full', 'fragment' ), true ) ) {
				$mode = 'auto';
			}
			update_post_meta( $post_id, self::META_MODE, $mode );

			// Code — stored raw on purpose. The whole point of the plugin is to
			// output untouched HTML/CSS/JS, so we only check the capability.
			if ( isset( $_POST['ccp_code'] ) ) {
				$code = wp_unslash( $_POST['ccp_code'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$code = str_replace( "\r\n", "\n", $code );
				update_post_meta( $post_id, self::META_CODE, $code );
			}

			// Mirror the final document to a real .html file in uploads.
			$this->write_project_file( $post_id );
		}

		/**
		 * Enable the core code editor (syntax highlighting) on the textarea.
		 *
		 * @param string $hook Current admin page.
		 * @return void
		 */
		public function admin_assets( $hook ) {

			$screen = get_current_screen();

			if ( ! $screen || self::CPT !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
				return;
			}

			$settings = wp_enqueue_code_editor( array( 'type' => 'text/html' ) );

			if ( false === $settings ) {
				return;
			}

			wp_add_inline_script(
				'code-editor',
				'jQuery(function($){ if ($.fn.wpCodeEditorInit && $("#ccp_code").length) { wp.codeEditor.initialize($("#ccp_code"), ' . wp_json_encode( $settings ) . '); } });'
			);

			wp_add_inline_style(
				'code-editor',
				'.ccp-field{margin:0 0 18px}.ccp-field label{display:block;margin-bottom:6px}.ccp-field .description{display:block;margin-top:6px;color:#646970}.ccp-url{background:#f6f7f7;padding:4px 8px;border-radius:4px;margin-left:6px;word-break:break-all}'
			);

			wp_add_inline_script(
				'code-editor',
				'jQuery(function($){ $(".ccp-copy").on("click", function(e){ e.preventDefault(); var t=this; if (navigator.clipboard) { navigator.clipboard.writeText($(this).data("ccp-copy")); } var old=$(t).text(); $(t).text("' . esc_js( __( 'Copied!', 'clean-code-projects' ) ) . '"); setTimeout(function(){ $(t).text(old); }, 1500); }); });'
			);
		}

		/* ------------------------------------------------------------------ *
		 * 3. Admin list table
		 * ------------------------------------------------------------------ */

		/**
		 * Add columns.
		 *
		 * @param array $columns Columns.
		 * @return array
		 */
		public function columns( $columns ) {
			$new = array();
			foreach ( $columns as $key => $label ) {
				$new[ $key ] = $label;
				if ( 'title' === $key ) {
					$new['ccp_link'] = __( 'Link', 'clean-code-projects' );
					$new['ccp_code'] = __( 'Code', 'clean-code-projects' );
					$new['ccp_view'] = __( 'Clean page', 'clean-code-projects' );
				}
			}
			unset( $new['date'] );
			$new['date'] = __( 'Date', 'clean-code-projects' );
			return $new;
		}

		/**
		 * Column output.
		 *
		 * @param string $column  Column name.
		 * @param int    $post_id Post ID.
		 * @return void
		 */
		public function column_content( $column, $post_id ) {

			$link = get_post_meta( $post_id, self::META_LINK, true );
			$code = (string) get_post_meta( $post_id, self::META_CODE, true );

			if ( 'ccp_link' === $column ) {
				if ( $link ) {
					printf(
						'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
						esc_url( $link ),
						esc_html( wp_parse_url( $link, PHP_URL_HOST ) )
					);
				} else {
					echo '<span aria-hidden="true">—</span>';
				}
				return;
			}

			if ( 'ccp_code' === $column ) {
				if ( '' === trim( $code ) ) {
					echo '<span aria-hidden="true">—</span>';
					return;
				}
				printf(
					'<code>%1$s</code> <span class="description">(%2$s)</span>',
					esc_html( number_format_i18n( strlen( $code ) ) . ' ' . __( 'chars', 'clean-code-projects' ) ),
					esc_html( 'full' === $this->resolve_mode( $post_id, $code ) ? __( 'full document', 'clean-code-projects' ) : __( 'fragment', 'clean-code-projects' ) )
				);
				return;
			}

			if ( 'ccp_view' === $column ) {
				$url = get_permalink( $post_id );
				printf(
					'<a class="button button-small" href="%1$s" target="_blank" rel="noopener">%2$s</a>',
					esc_url( $url ),
					esc_html__( 'Open ↗', 'clean-code-projects' )
				);
				printf(
					'<br><code class="description" style="word-break:break-all">%1$s</code>',
					esc_html( '[ccp_project id="' . $post_id . '"]' )
				);
			}
		}

		/**
		 * Add a "Clean page" row action.
		 *
		 * @param array   $actions Actions.
		 * @param WP_Post $post    Post.
		 * @return array
		 */
		public function row_actions( $actions, $post ) {
			if ( self::CPT === $post->post_type ) {
				$actions['ccp_view'] = sprintf(
					'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
					esc_url( get_permalink( $post->ID ) ),
					esc_html__( 'Clean page', 'clean-code-projects' )
				);
			}
			return $actions;
		}

		/* ------------------------------------------------------------------ *
		 * 3b. HTML files in the uploads folder
		 * ------------------------------------------------------------------ */

		/**
		 * Absolute path of (a file inside) uploads/clean-code-projects/.
		 *
		 * @param string $file Optional basename.
		 * @return string
		 */
		private function uploads_path( $file = '' ) {
			$up  = wp_upload_dir();
			$dir = trailingslashit( $up['basedir'] ) . self::UPLOAD_DIR;
			return $file ? $dir . '/' . basename( $file ) : $dir;
		}

		/**
		 * Public URL of (a file inside) uploads/clean-code-projects/.
		 *
		 * @param string $file Optional basename.
		 * @return string
		 */
		public function uploads_url( $file = '' ) {
			$up  = wp_upload_dir();
			$url = trailingslashit( $up['baseurl'] ) . self::UPLOAD_DIR;
			return $file ? $url . '/' . basename( $file ) : $url;
		}

		/**
		 * Create the storage folder plus guards (no directory listing, and
		 * uploaded HTML can never be executed as PHP on Apache servers).
		 *
		 * @return bool Whether the folder is writable.
		 */
		private function ensure_uploads_dir() {
			$dir = $this->uploads_path();
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			if ( is_dir( $dir ) && ! file_exists( $dir . '/index.html' ) ) {
				file_put_contents( $dir . '/index.html', '' ); // Silence is golden.
				file_put_contents( $dir . '/.htaccess', "<FilesMatch \"\\.(php[0-9]*|phtml|phar|cgi|pl)$\">\n  Require all denied\n</FilesMatch>\nOptions -ExecCGI\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			return is_dir( $dir ) && wp_is_writable( $dir );
		}

		/**
		 * Deterministic, safe file name for a project: slug-ID.html.
		 *
		 * @param WP_Post $post Project.
		 * @return string
		 */
		private function project_file_name( $post ) {
			$slug = $post->post_name ? $post->post_name : sanitize_title( $post->post_title );
			$name = sanitize_file_name( $slug . '-' . $post->ID );
			return ( $name ? $name : 'project-' . $post->ID ) . '.html';
		}

		/**
		 * Write the project's final clean document to its .html file.
		 *
		 * @param int $post_id Project ID.
		 * @return bool
		 */
		public function write_project_file( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || self::CPT !== $post->post_type || ! $this->ensure_uploads_dir() ) {
				return false;
			}
			$code = (string) get_post_meta( $post_id, self::META_CODE, true );
			$old  = (string) get_post_meta( $post_id, self::META_FILE, true );
			$name = $this->project_file_name( $post );

			if ( '' === trim( $code ) ) {
				$this->delete_project_file( $post_id );
				return false;
			}
			$document = $this->build_clean_document( $code, $this->resolve_mode( $post_id, $code ), $post );
			$ok       = false !== file_put_contents( $this->uploads_path( $name ), $document ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( $ok ) {
				if ( $old && $old !== $name ) {
					$this->unlink_upload( $old ); // Slug changed: drop the stale file.
				}
				update_post_meta( $post_id, self::META_FILE, $name );
			}
			return $ok;
		}

		/**
		 * Delete only the stored .html file of a project (post stays intact).
		 *
		 * @param int $post_id Project ID.
		 * @return bool
		 */
		public function delete_project_file( $post_id ) {
			$file = (string) get_post_meta( $post_id, self::META_FILE, true );
			if ( $file ) {
				$this->unlink_upload( $file );
				delete_post_meta( $post_id, self::META_FILE );
				return true;
			}
			$post = get_post( $post_id );
			return $post ? $this->unlink_upload( $this->project_file_name( $post ) ) : false;
		}

		/**
		 * before_delete_post hook: a permanently deleted project loses its file.
		 *
		 * @param int $post_id Post ID.
		 * @return void
		 */
		public function delete_project_file_for_post( $post_id ) {
			if ( self::CPT === get_post_type( $post_id ) ) {
				$this->delete_project_file( $post_id );
			}
		}

		/**
		 * Unlink one file, refusing anything outside the uploads sub-folder.
		 *
		 * @param string $basename File basename.
		 * @return bool
		 */
		private function unlink_upload( $basename ) {
			$path = $this->uploads_path( basename( $basename ) );
			return file_exists( $path ) ? unlink( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		/**
		 * Every .html file currently in the uploads sub-folder.
		 *
		 * @return array[] Each: name, path, size, modified (unix ts).
		 */
		private function list_stored_files() {
			$files = array();
			foreach ( glob( $this->uploads_path() . '/*.html' ) ?: array() as $path ) {
				if ( 'index.html' === basename( $path ) ) {
					continue;
				}
				$files[] = array(
					'name'     => basename( $path ),
					'path'     => $path,
					'size'     => (int) filesize( $path ),
					'modified' => (int) filemtime( $path ),
				);
			}
			usort( $files, function ( $a, $b ) { return strcmp( $a['name'], $b['name'] ); } );
			return $files;
		}

		/* ------------------------------------------------------------------ *
		 * 3c. Super Admin — manage & delete everything
		 * ------------------------------------------------------------------ */

		/**
		 * Who counts as Super Admin here: network Super Admins on multisite,
		 * Administrators (manage_options) on a single site. Filterable.
		 *
		 * @return bool
		 */
		private function is_super_admin_user() {
			$ok = is_multisite() ? is_super_admin() : current_user_can( 'manage_options' );
			return (bool) apply_filters( 'ccp_is_super_admin', $ok, get_current_user_id() );
		}

		/**
		 * Handle every Super Admin action. Nonce + capability checked, then a
		 * redirect back to the Super Admin page with a status message.
		 *
		 * @return void
		 */
		public function handle_super_admin_actions() {
			if ( ! isset( $_POST['ccp_sa_do'] ) || ! $this->is_super_admin_user() ) {
				return;
			}
			if ( ! isset( $_POST['ccp_sa_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ccp_sa_nonce'] ) ), 'ccp_super_admin' ) ) {
				return;
			}
			$do   = sanitize_key( wp_unslash( $_POST['ccp_sa_do'] ) );
			$ids  = isset( $_POST['ccp_ids'] ) ? array_filter( array_map( 'intval', (array) wp_unslash( $_POST['ccp_ids'] ) ) ) : array();
			$one  = isset( $_POST['ccp_id'] ) ? (int) $_POST['ccp_id'] : 0;
			if ( $one ) {
				$ids = array( $one );
			}
			$done = 0;

			if ( 'delete_all_files' === $do || 'delete_orphan_files' === $do ) {
				$known = array();
				foreach ( get_posts( array( 'post_type' => self::CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $pid ) {
					$f = (string) get_post_meta( $pid, self::META_FILE, true );
					if ( $f ) {
						$known[] = $f;
					}
				}
				foreach ( $this->list_stored_files() as $file ) {
					if ( 'delete_all_files' === $do || ! in_array( $file['name'], $known, true ) ) {
						$done += $this->unlink_upload( $file['name'] ) ? 1 : 0;
					}
				}
				if ( 'delete_all_files' === $do ) {
					foreach ( get_posts( array( 'post_type' => self::CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $pid ) {
						delete_post_meta( $pid, self::META_FILE );
					}
				}
			} elseif ( 'delete_everything' === $do ) {
				foreach ( get_posts( array( 'post_type' => self::CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $pid ) {
					$done += wp_delete_post( $pid, true ) ? 1 : 0; // Files go via before_delete_post.
				}
			} elseif ( 'regenerate_all' === $do ) {
				foreach ( get_posts( array( 'post_type' => self::CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $pid ) {
					$done += $this->write_project_file( $pid ) ? 1 : 0;
				}
			} else {
				foreach ( $ids as $pid ) {
					if ( self::CPT !== get_post_type( $pid ) ) {
						continue;
					}
					switch ( $do ) {
						case 'regenerate':
							$done += $this->write_project_file( $pid ) ? 1 : 0;
							break;
						case 'delete_file':
							$done += $this->delete_project_file( $pid ) ? 1 : 0;
							break;
						case 'trash':
							$done += wp_trash_post( $pid ) ? 1 : 0;
							break;
						case 'restore':
							$done += wp_untrash_post( $pid ) ? 1 : 0;
							break;
						case 'delete':
							$done += wp_delete_post( $pid, true ) ? 1 : 0;
							break;
					}
				}
			}

			wp_safe_redirect( add_query_arg( array( 'ccp_sa_msg' => $do, 'ccp_sa_n' => $done ), admin_url( 'edit.php?post_type=' . self::CPT . '&page=ccp-super-admin' ) ) );
			exit;
		}

		/**
		 * Super Admin dashboard: stats, every project (any status), every
		 * stored file, bulk tools and a danger zone. Super Admins only.
		 *
		 * @return void
		 */
		public function render_super_admin() {
			if ( ! $this->is_super_admin_user() ) {
				wp_die( esc_html__( 'Only a Super Admin can open this page.', 'clean-code-projects' ) );
			}
			$projects = get_posts( array( 'post_type' => self::CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'modified', 'order' => 'DESC' ) );
			$files    = $this->list_stored_files();
			$known    = array();
			$chars    = 0;
			foreach ( $projects as $pr ) {
				$f = (string) get_post_meta( $pr->ID, self::META_FILE, true );
				if ( $f ) {
					$known[] = $f;
				}
				$chars += strlen( (string) get_post_meta( $pr->ID, self::META_CODE, true ) );
			}
			$counts  = wp_count_posts( self::CPT );
			$bytes   = array_sum( array_column( $files, 'size' ) );
			$orphans = array_filter( $files, function ( $f ) use ( $known ) { return ! in_array( $f['name'], $known, true ); } );
			$msg     = isset( $_GET['ccp_sa_msg'] ) ? sanitize_key( wp_unslash( $_GET['ccp_sa_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$msg_n   = isset( $_GET['ccp_sa_n'] ) ? (int) $_GET['ccp_sa_n'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			?>
			<div class="wrap ccp-sa">
				<style>
				.ccp-sa .ccp-sa-hero{background:linear-gradient(135deg,#4f46e5,#0ea5e9 55%,#ec4899);color:#fff;border-radius:20px;padding:26px 28px;margin:18px 0;box-shadow:0 20px 40px -18px rgba(79,70,229,.55)}
				.ccp-sa-hero h1{color:#fff;margin:0 0 6px;font-size:26px}.ccp-sa-hero p{margin:0;opacity:.92}
				.ccp-sa-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin:0 0 18px}
				.ccp-sa-stat{background:rgba(255,255,255,.65);backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,.7);border-radius:16px;padding:16px;box-shadow:0 10px 24px -14px rgba(15,23,42,.25)}
				.ccp-sa-stat b{display:block;font-size:24px}.ccp-sa-stat span{color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:.06em}
				.ccp-sa-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px;margin:0 0 18px}
				.ccp-sa-danger{border-color:#fecaca;background:#fff7f7}
				.ccp-sa .button-danger{background:#dc2626;border-color:#b91c1c;color:#fff}
				</style>
				<div class="ccp-sa-hero">
					<h1>🛡️ <?php esc_html_e( 'Super Admin — Clean Code Projects', 'clean-code-projects' ); ?></h1>
					<p><?php esc_html_e( 'Manage and delete everything: all projects (any status), their stored HTML files in the uploads folder, orphan files and the trash. Only Super Admins can see this page.', 'clean-code-projects' ); ?></p>
				</div>
				<?php if ( $msg ) : ?>
					<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( /* translators: 1: action, 2: count */ __( 'Done: %1$s — %2$d item(s) affected.', 'clean-code-projects' ), $msg, $msg_n ) ); ?></p></div>
				<?php endif; ?>
				<div class="ccp-sa-stats">
					<div class="ccp-sa-stat"><b><?php echo esc_html( number_format_i18n( count( $projects ) ) ); ?></b><span><?php esc_html_e( 'All projects', 'clean-code-projects' ); ?></span></div>
					<div class="ccp-sa-stat"><b><?php echo esc_html( number_format_i18n( (int) ( $counts->publish ?? 0 ) ) ); ?></b><span><?php esc_html_e( 'Published', 'clean-code-projects' ); ?></span></div>
					<div class="ccp-sa-stat"><b><?php echo esc_html( number_format_i18n( (int) ( $counts->trash ?? 0 ) ) ); ?></b><span><?php esc_html_e( 'In trash', 'clean-code-projects' ); ?></span></div>
					<div class="ccp-sa-stat"><b><?php echo esc_html( number_format_i18n( count( $files ) ) ); ?></b><span><?php esc_html_e( 'HTML files stored', 'clean-code-projects' ); ?></span></div>
					<div class="ccp-sa-stat"><b><?php echo esc_html( size_format( $bytes ) ); ?></b><span><?php esc_html_e( 'Uploads folder size', 'clean-code-projects' ); ?></span></div>
					<div class="ccp-sa-stat"><b><?php echo esc_html( number_format_i18n( count( $orphans ) ) ); ?></b><span><?php esc_html_e( 'Orphan files', 'clean-code-projects' ); ?></span></div>
				</div>
				<p class="description"><?php esc_html_e( 'Storage folder:', 'clean-code-projects' ); ?> <code><?php echo esc_html( $this->uploads_path() ); ?></code> · <?php echo esc_html( number_format_i18n( $chars ) . ' ' . __( 'characters of code stored in projects.', 'clean-code-projects' ) ); ?></p>

				<div class="ccp-sa-card">
					<h2><?php esc_html_e( 'All projects', 'clean-code-projects' ); ?></h2>
					<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Apply this action to the selected projects?', 'clean-code-projects' ) ); ?>');">
						<?php wp_nonce_field( 'ccp_super_admin', 'ccp_sa_nonce' ); ?>
						<table class="widefat striped">
							<thead><tr><th style="width:34px"><input type="checkbox" onclick="var c=this.closest('table').querySelectorAll('.ccp-sa-check');for(var i=0;i<c.length;i++){c[i].checked=this.checked}"></th><th><?php esc_html_e( 'Project', 'clean-code-projects' ); ?></th><th><?php esc_html_e( 'Status', 'clean-code-projects' ); ?></th><th><?php esc_html_e( 'Stored file', 'clean-code-projects' ); ?></th><th><?php esc_html_e( 'Modified', 'clean-code-projects' ); ?></th><th><?php esc_html_e( 'Actions', 'clean-code-projects' ); ?></th></tr></thead>
							<tbody>
							<?php if ( ! $projects ) : ?>
								<tr><td colspan="6"><?php esc_html_e( 'No projects yet.', 'clean-code-projects' ); ?></td></tr>
							<?php endif; ?>
							<?php foreach ( $projects as $pr ) : $f = (string) get_post_meta( $pr->ID, self::META_FILE, true ); ?>
								<tr>
									<td><input class="ccp-sa-check" type="checkbox" name="ccp_ids[]" value="<?php echo (int) $pr->ID; ?>"></td>
									<td><strong><?php echo esc_html( get_the_title( $pr ) ? get_the_title( $pr ) : __( '(no title)', 'clean-code-projects' ) ); ?></strong> <span class="description">#<?php echo (int) $pr->ID; ?></span><br>
										<a href="<?php echo esc_url( get_edit_post_link( $pr->ID ) ); ?>"><?php esc_html_e( 'Edit', 'clean-code-projects' ); ?></a>
										<?php if ( 'trash' !== $pr->post_status ) : ?> · <a href="<?php echo esc_url( get_permalink( $pr ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Clean page ↗', 'clean-code-projects' ); ?></a><?php endif; ?>
									</td>
									<td><?php echo esc_html( $pr->post_status ); ?></td>
									<td><?php echo $f ? '<code>' . esc_html( $f ) . '</code>' : '—'; ?></td>
									<td><?php echo esc_html( get_post_modified_time( 'Y-m-d H:i', false, $pr ) ); ?></td>
									<td>
										<button class="button button-small" name="ccp_id" value="<?php echo (int) $pr->ID; ?>" onclick="this.form.ccp_sa_do.value='regenerate'">↻ <?php esc_html_e( 'File', 'clean-code-projects' ); ?></button>
										<?php if ( 'trash' === $pr->post_status ) : ?>
											<button class="button button-small" name="ccp_id" value="<?php echo (int) $pr->ID; ?>" onclick="this.form.ccp_sa_do.value='restore'"><?php esc_html_e( 'Restore', 'clean-code-projects' ); ?></button>
										<?php else : ?>
											<button class="button button-small" name="ccp_id" value="<?php echo (int) $pr->ID; ?>" onclick="this.form.ccp_sa_do.value='trash'"><?php esc_html_e( 'Trash', 'clean-code-projects' ); ?></button>
										<?php endif; ?>
										<button class="button button-small button-danger" name="ccp_id" value="<?php echo (int) $pr->ID; ?>" onclick="this.form.ccp_sa_do.value='delete'"><?php esc_html_e( 'Delete forever', 'clean-code-projects' ); ?></button>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p>
							<input type="hidden" name="ccp_sa_do" value="regenerate">
							<button class="button" onclick="this.form.ccp_sa_do.value='regenerate'">↻ <?php esc_html_e( 'Regenerate selected files', 'clean-code-projects' ); ?></button>
							<button class="button" onclick="this.form.ccp_sa_do.value='delete_file'"><?php esc_html_e( 'Delete selected files only', 'clean-code-projects' ); ?></button>
							<button class="button" onclick="this.form.ccp_sa_do.value='trash'"><?php esc_html_e( 'Trash selected', 'clean-code-projects' ); ?></button>
							<button class="button button-danger" onclick="this.form.ccp_sa_do.value='delete'"><?php esc_html_e( 'Delete selected forever', 'clean-code-projects' ); ?></button>
						</p>
					</form>
				</div>

				<div class="ccp-sa-card">
					<h2><?php esc_html_e( 'Stored HTML files', 'clean-code-projects' ); ?> — <code>uploads/<?php echo esc_html( self::UPLOAD_DIR ); ?>/</code></h2>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'File', 'clean-code-projects' ); ?></th><th><?php esc_html_e( 'Size', 'clean-code-projects' ); ?></th><th><?php esc_html_e( 'Modified', 'clean-code-projects' ); ?></th><th><?php esc_html_e( 'State', 'clean-code-projects' ); ?></th></tr></thead><tbody>
					<?php if ( ! $files ) : ?><tr><td colspan="4"><?php esc_html_e( 'No files stored yet — save a project with code, or regenerate below.', 'clean-code-projects' ); ?></td></tr><?php endif; ?>
					<?php foreach ( $files as $file ) : $orphan = ! in_array( $file['name'], $known, true ); ?>
						<tr><td><a href="<?php echo esc_url( $this->uploads_url( $file['name'] ) ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $file['name'] ); ?></code> ↗</a></td><td><?php echo esc_html( size_format( $file['size'] ) ); ?></td><td><?php echo esc_html( date_i18n( 'Y-m-d H:i', $file['modified'] ) ); ?></td><td><?php echo $orphan ? '<strong style="color:#dc2626">' . esc_html__( 'Orphan', 'clean-code-projects' ) . '</strong>' : esc_html__( 'Linked', 'clean-code-projects' ); ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
					<form method="post" style="margin-top:12px" onsubmit="return confirm('<?php echo esc_js( __( 'Continue?', 'clean-code-projects' ) ); ?>');">
						<?php wp_nonce_field( 'ccp_super_admin', 'ccp_sa_nonce' ); ?>
						<button class="button" name="ccp_sa_do" value="regenerate_all">↻ <?php esc_html_e( 'Regenerate all files', 'clean-code-projects' ); ?></button>
						<button class="button" name="ccp_sa_do" value="delete_orphan_files"><?php esc_html_e( 'Delete orphan files only', 'clean-code-projects' ); ?></button>
					</form>
				</div>

				<div class="ccp-sa-card ccp-sa-danger">
					<h2>⚠️ <?php esc_html_e( 'Danger zone — delete everything', 'clean-code-projects' ); ?></h2>
					<p><?php esc_html_e( 'These cannot be undone. Projects deleted here are gone forever (including their stored HTML files).', 'clean-code-projects' ); ?></p>
					<form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete ALL stored HTML files? Projects themselves stay.', 'clean-code-projects' ) ); ?>');">
						<?php wp_nonce_field( 'ccp_super_admin', 'ccp_sa_nonce' ); ?>
						<button class="button button-danger" name="ccp_sa_do" value="delete_all_files"><?php esc_html_e( 'Delete ALL stored HTML files', 'clean-code-projects' ); ?></button>
					</form>
					<form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'PERMANENTLY delete EVERY project and its file? This cannot be undone!', 'clean-code-projects' ) ); ?>');">
						<?php wp_nonce_field( 'ccp_super_admin', 'ccp_sa_nonce' ); ?>
						<button class="button button-danger" name="ccp_sa_do" value="delete_everything"><?php esc_html_e( 'Delete EVERYTHING (all projects + files)', 'clean-code-projects' ); ?></button>
					</form>
				</div>
			</div>
			<?php
		}

		/* ------------------------------------------------------------------ *
		 * 4. THE CLEAN PAGE  (no theme / no CSS / no JS)
		 * ------------------------------------------------------------------ */

		/**
		 * Decide the output mode for a project.
		 *
		 * @param int    $post_id Post ID.
		 * @param string $code    Raw code.
		 * @return string 'full' or 'fragment'.
		 */
		private function resolve_mode( $post_id, $code ) {
			$mode = get_post_meta( $post_id, self::META_MODE, true );
			if ( 'full' === $mode || 'fragment' === $mode ) {
				return $mode;
			}
			// Auto: treat anything that already looks like a document as one.
			$haystack = strtolower( substr( $code, 0, 2048 ) );
			if ( false !== strpos( $haystack, '<!doctype' ) || false !== strpos( $haystack, '<html' ) ) {
				return 'full';
			}
			return 'fragment';
		}

		/**
		 * Kill every enqueued script/style and every head/footer hook.
		 *
		 * @return void
		 */
		private function disable_all_assets() {

			// Nothing gets printed: wp_head / wp_footer are never called because we
			// exit before the template loads — but clear the hooks anyway, in case a
			// plugin calls them itself.
			foreach ( array( 'wp_head', 'wp_footer', 'wp_enqueue_scripts', 'wp_print_styles', 'wp_print_scripts', 'wp_print_head_scripts', 'wp_print_footer_scripts', 'admin_bar_menu', 'wp_before_admin_bar_render', 'wp_after_admin_bar_render', 'login_head', 'embed_head', 'embed_footer', 'rss2_head', 'rss_head', 'the_content', 'loop_start', 'loop_end', 'get_header', 'wp_body_open' ) as $hook ) {
				remove_all_actions( $hook );
				remove_all_filters( $hook );
			}

			// Emoji / oEmbed / REST / shortlink / feed leftovers.
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
			remove_action( 'wp_head', 'wp_generator' );
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'rest_output_link_wp_head' );
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );

			// Belt and braces: any `src` still requested becomes empty.
			add_filter( 'style_loader_src', '__return_empty_string', PHP_INT_MAX );
			add_filter( 'script_loader_src', '__return_empty_string', PHP_INT_MAX );
			add_filter( 'script_loader_tag', '__return_empty_string', PHP_INT_MAX );
			add_filter( 'style_loader_tag', '__return_empty_string', PHP_INT_MAX );

			// No admin bar.
			add_filter( 'show_admin_bar', '__return_false', PHP_INT_MAX );

			// Drop any queued assets for good measure.
			if ( function_exists( 'wp_styles' ) && wp_styles() ) {
				wp_styles()->queue = array();
				wp_styles()->registered = array();
			}
			if ( function_exists( 'wp_scripts' ) && wp_scripts() ) {
				wp_scripts()->queue = array();
				wp_scripts()->registered = array();
			}
		}

		/**
		 * Bail out of the theme completely and print only the stored code.
		 *
		 * Runs on `template_redirect` priority 0, i.e. before the theme template
		 * (header.php / wp_head / wp_footer) is ever loaded, so WordPress can
		 * never emit a single line of its own markup, CSS or JS.
		 *
		 * @return void
		 */
		public function maybe_render_clean_page() {

			$is_project = is_singular( self::CPT );

			// Draft preview: /?post_type=ccp_project&p=123&preview=true
			if ( ! $is_project && is_preview() && self::CPT === get_query_var( 'post_type' ) ) {
				$is_project = true;
			}

			if ( ! $is_project ) {
				return;
			}

			$post = get_queried_object();

			if ( ! $post instanceof WP_Post || self::CPT !== $post->post_type ) {
				return;
			}

			// Password protected posts keep the normal (theme) password form.
			if ( post_password_required( $post ) ) {
				return;
			}

			$post_id = $post->ID;
			$code    = (string) get_post_meta( $post_id, self::META_CODE, true );
			$mode    = $this->resolve_mode( $post_id, $code );

			/**
			 * Short-circuit the clean page (return false to let the theme render it).
			 *
			 * @param bool    $clean   Whether to print the clean page.
			 * @param WP_Post $post    The project.
			 * @param string  $code    Raw stored code.
			 */
			if ( ! apply_filters( 'ccp_clean_page_enabled', true, $post, $code ) ) {
				return;
			}

			/** Filter the raw code before it is printed. */
			$code = apply_filters( 'ccp_clean_page_code', $code, $post, $mode );

			// 1) Nothing from WordPress may load or print.
			$this->disable_all_assets();

			// 2) Discard anything already buffered (theme/plugins output).
			while ( ob_get_level() > 0 ) {
				if ( ! ob_end_clean() ) {
					break;
				}
			}

			// 3) Send it.
			nocache_headers();
			status_header( 200 );
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );

			// Only hide from search engines when the whole site asks for it.
			if ( ! get_option( 'blog_public' ) ) {
				header( 'X-Robots-Tag: noindex, nofollow' );
			}

			$document = $this->build_clean_document( $code, $mode, $post );

			/** Filter the final document (fragment mode only). */
			$document = apply_filters( 'ccp_clean_page_document', $document, $post, $mode );

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw, author-written code, printed verbatim on purpose.
			echo $document;

			exit;
		}

		/**
		 * Build the final HTML string.
		 *
		 * @param string  $code Raw code.
		 * @param string  $mode 'full' or 'fragment'.
		 * @param WP_Post $post Post object.
		 * @return string
		 */
		private function build_clean_document( $code, $mode, $post ) {

			if ( 'full' === $mode ) {
				return $code;
			}

			$title = get_the_title( $post );
			$lang  = get_bloginfo( 'language' );

			return '<!DOCTYPE html>' . "\n"
				. '<html lang="' . esc_attr( $lang ) . '">' . "\n"
				. '<head>' . "\n"
				. '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">' . "\n"
				. '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
				. '<title>' . esc_html( $title ) . '</title>' . "\n"
				. '</head>' . "\n"
				. '<body>' . "\n"
				. $code . "\n"
				. '</body>' . "\n"
				. '</html>';
		}

		/* ------------------------------------------------------------------ *
		 * 5. Shortcodes — grid + single
		 * ------------------------------------------------------------------ */

		/**
		 * Register shortcodes.
		 *
		 * @return void
		 */
		public function register_shortcodes() {
			add_shortcode( 'ccp_projects', array( $this, 'shortcode_grid' ) );
			add_shortcode( 'ccp_project', array( $this, 'shortcode_single' ) );
		}

		/**
		 * Merged settings (defaults + saved options).
		 *
		 * @return array
		 */
		private function get_options() {
			$defaults = array(
				'columns'     => 3,
				'per_page'    => -1,
				'show_code'    => 1,
				'show_preview' => 1,
				'new_tab'     => 1,
				'search'      => 1,
			);
			$saved    = (array) get_option( self::OPTION, array() );
			return array_merge( $defaults, $saved );
		}

		/**
		 * The stylesheet for the grid. Scoped with .ccp-* so it cannot leak
		 * into the rest of the theme.
		 *
		 * @return string
		 */
		private function grid_css() {
			return <<<'CSS'
.ccp-wrap{--ccp-text:#14161a;--ccp-muted:#5b6472;--ccp-accent:#4f46e5;--ccp-accent2:#0ea5e9;--ccp-accent3:#ec4899;--ccp-radius:22px;--ccp-glass:rgba(255,255,255,.58);--ccp-glass-line:rgba(255,255,255,.65);font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--ccp-text);width:min(1200px,100%);margin:0 auto;padding:34px 26px 40px;border-radius:30px;position:relative;isolation:isolate;background:radial-gradient(560px 300px at 8% 0%,rgba(79,70,229,.20),transparent 60%),radial-gradient(520px 300px at 96% 12%,rgba(236,72,153,.16),transparent 60%),radial-gradient(600px 340px at 50% 108%,rgba(14,165,233,.18),transparent 60%),linear-gradient(160deg,rgba(255,255,255,.5),rgba(255,255,255,.14));box-shadow:inset 0 1px 0 rgba(255,255,255,.6),0 30px 60px -30px rgba(15,23,42,.28)}
.ccp-wrap *{box-sizing:border-box}
.ccp-head{display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:center;text-align:center;margin:4px 0 26px;flex-direction:column}
.ccp-title{margin:0;font-size:clamp(1.6rem,3.4vw,2.3rem);font-weight:800;letter-spacing:-.03em;line-height:1.1;background:linear-gradient(92deg,var(--ccp-accent),var(--ccp-accent2) 55%,var(--ccp-accent3));-webkit-background-clip:text;background-clip:text;color:transparent}
.ccp-title:empty{display:none}
.ccp-count{margin:6px 0 0;color:var(--ccp-muted);font-size:.9rem}
.ccp-search{flex:0 1 420px;width:min(420px,100%);min-width:200px}
.ccp-search input{width:100%;padding:13px 20px;border:1px solid var(--ccp-glass-line);border-radius:999px;font:inherit;font-size:.92rem;background:var(--ccp-glass);color:inherit;backdrop-filter:blur(16px) saturate(1.5);-webkit-backdrop-filter:blur(16px) saturate(1.5);box-shadow:0 10px 24px -14px rgba(15,23,42,.35);transition:border-color .2s,box-shadow .2s}
.ccp-search input:focus{outline:none;border-color:var(--ccp-accent);box-shadow:0 0 0 4px rgba(79,70,229,.16),0 10px 24px -14px rgba(15,23,42,.35)}
.ccp-grid{display:grid;grid-template-columns:repeat(var(--ccp-cols,3),minmax(0,1fr));gap:24px;justify-content:center;align-items:stretch}
@media (max-width:1024px){.ccp-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:600px){.ccp-grid{grid-template-columns:minmax(0,1fr)}.ccp-wrap{padding:24px 14px 30px;border-radius:22px}}
.ccp-card{display:flex;flex-direction:column;background:var(--ccp-glass);border:1px solid var(--ccp-glass-line);border-radius:var(--ccp-radius);overflow:hidden;backdrop-filter:blur(18px) saturate(1.5);-webkit-backdrop-filter:blur(18px) saturate(1.5);box-shadow:0 18px 36px -18px rgba(15,23,42,.30),inset 0 1px 0 rgba(255,255,255,.55);transition:transform .25s ease,box-shadow .25s ease,border-color .25s ease}
.ccp-card:hover{transform:translateY(-6px);box-shadow:0 30px 50px -20px rgba(79,70,229,.38),inset 0 1px 0 rgba(255,255,255,.6);border-color:rgba(255,255,255,.9)}
.ccp-preview{position:relative;height:178px;overflow:hidden;background:#0f172a;border-bottom:1px solid rgba(255,255,255,.35)}
.ccp-preview iframe{position:absolute;top:0;left:0;width:400%;height:400%;transform:scale(.25);transform-origin:top left;border:0;pointer-events:none;background:#fff}
.ccp-preview--empty{display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:.85rem;background:linear-gradient(135deg,#312e81,#0ea5e9)}
.ccp-body{padding:18px 20px 4px;flex:1 1 auto}
.ccp-card__title{margin:0 0 7px;font-size:1.08rem;font-weight:700;line-height:1.35;letter-spacing:-.01em;word-break:break-word}
.ccp-card__title a{color:inherit;text-decoration:none}
.ccp-card__title a:hover{color:var(--ccp-accent)}
.ccp-meta{display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center;font-size:.8rem;color:var(--ccp-muted);margin:0 0 10px}
.ccp-meta a{color:var(--ccp-muted);text-decoration:none;display:inline-flex;align-items:center;gap:5px}
.ccp-meta a:hover{color:var(--ccp-accent);text-decoration:underline}
.ccp-dot{width:3px;height:3px;border-radius:50%;background:currentColor;opacity:.5}
.ccp-code{margin:12px 0 0;background:rgba(15,23,42,.92);color:#e2e8f0;border-radius:14px;padding:12px 14px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.74rem;line-height:1.55;max-height:132px;overflow:auto;white-space:pre;tab-size:2}
.ccp-code::-webkit-scrollbar{width:8px;height:8px}
.ccp-code::-webkit-scrollbar-thumb{background:#334155;border-radius:8px}
.ccp-actions{display:flex;gap:10px;padding:16px 20px 20px;flex-wrap:wrap;justify-content:center}
.ccp-btn{display:inline-flex;align-items:center;gap:6px;padding:10px 17px;border-radius:999px;font-size:.855rem;font-weight:600;text-decoration:none;border:1px solid var(--ccp-glass-line);background:rgba(255,255,255,.55);color:var(--ccp-text);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);transition:filter .2s,transform .15s,box-shadow .2s}
.ccp-btn:hover{box-shadow:0 10px 20px -10px rgba(15,23,42,.35)}
.ccp-btn:active{transform:translateY(1px)}
.ccp-btn--primary{background:linear-gradient(135deg,var(--ccp-accent),var(--ccp-accent2) 60%,var(--ccp-accent3));color:#fff;border-color:transparent;box-shadow:0 12px 24px -10px rgba(79,70,229,.65)}
.ccp-btn--primary:hover{filter:brightness(1.08);color:#fff}
.ccp-empty{padding:54px 20px;text-align:center;border:1px dashed rgba(100,116,139,.45);border-radius:var(--ccp-radius);color:var(--ccp-muted);background:var(--ccp-glass);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px)}
.ccp-empty h3{margin:0 0 6px;color:var(--ccp-text);font-size:1.05rem}
.ccp-empty p{margin:0}
.ccp-single{overflow:hidden;margin:12px auto}
.ccp-single__bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;padding:13px 18px;border-bottom:1px solid var(--ccp-glass-line);background:rgba(255,255,255,.4)}
.ccp-single__bar strong{font-size:.95rem}
.ccp-dot-row{display:flex;gap:6px}
.ccp-dot-row i{width:10px;height:10px;border-radius:50%;background:#cbd5e1;display:block}
.ccp-dot-row i:nth-child(1){background:#f87171}.ccp-dot-row i:nth-child(2){background:#fbbf24}.ccp-dot-row i:nth-child(3){background:#34d399}
.ccp-frame{width:100%;height:520px;border:0;display:block;background:#fff}
@media (max-width:600px){.ccp-frame{height:400px}}
@media (prefers-color-scheme:dark){
.ccp-wrap{--ccp-text:#eef2ff;--ccp-muted:#a5b4c8;--ccp-glass:rgba(15,23,42,.55);--ccp-glass-line:rgba(148,163,184,.28);background:radial-gradient(560px 300px at 8% 0%,rgba(99,102,241,.32),transparent 60%),radial-gradient(520px 300px at 96% 12%,rgba(236,72,153,.22),transparent 60%),radial-gradient(600px 340px at 50% 108%,rgba(14,165,233,.26),transparent 60%),linear-gradient(160deg,rgba(15,23,42,.72),rgba(15,23,42,.4));box-shadow:inset 0 1px 0 rgba(255,255,255,.08),0 30px 60px -30px rgba(0,0,0,.8)}
.ccp-card:hover{border-color:rgba(148,163,184,.5)}
.ccp-btn{background:rgba(15,23,42,.5);color:var(--ccp-text)}
.ccp-single__bar{background:rgba(15,23,42,.4)}
}
@media (prefers-reduced-motion:reduce){.ccp-card,.ccp-btn{transition:none}}
CSS;
		}

		/**
		 * Grid of all projects.
		 *
		 * Usage: [ccp_projects columns="3" limit="12" orderby="date" order="DESC"
		 *         show_code="1" preview="1" search="1" ids="1,2,3"]
		 *
		 * @param array|string $atts Shortcode attributes.
		 * @return string
		 */
		public function shortcode_grid( $atts ) {

			$o = $this->get_options();

			$atts = shortcode_atts(
				array(
					'columns'    => $o['columns'],
					'limit'      => $o['per_page'],
					'per_page'   => $o['per_page'],
					'orderby'    => 'date',
					'order'      => 'DESC',
					'ids'        => '',
					'search'     => $o['search'],
					'show_code'  => $o['show_code'],
					'preview'    => $o['show_preview'],
					'new_tab'    => $o['new_tab'],
					'title'      => '',
				),
				$atts,
				'ccp_projects'
			);

			$limit = ( '' !== $atts['limit'] ) ? (int) $atts['limit'] : (int) $atts['per_page'];

			$query_args = array(
				'post_type'           => self::CPT,
				'post_status'         => 'publish',
				'posts_per_page'      => $limit > 0 ? $limit : -1,
				'orderby'             => sanitize_key( $atts['orderby'] ),
				'order'               => 'DESC' === strtoupper( $atts['order'] ) ? 'DESC' : 'ASC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			);

			if ( ! empty( $atts['ids'] ) ) {
				$query_args['post__in']            = array_filter( array_map( 'intval', explode( ',', $atts['ids'] ) ) );
				$query_args['orderby']             = 'post__in';
				$query_args['posts_per_page']      = count( $query_args['post__in'] );
			}

			$projects = new WP_Query( $query_args );

			$cols   = max( 1, min( 6, (int) $atts['columns'] ) );
			$target = (bool) $atts['new_tab'] ? ' target="_blank" rel="noopener"' : '';

			$html  = '<div class="ccp-wrap" style="--ccp-cols:' . esc_attr( $cols ) . '">';
			$html .= '<style>' . $this->grid_css() . '</style>';

			if ( $atts['title'] || $atts['search'] ) {
				$html .= '<div class="ccp-head">';
				$html .= '<div><h2 class="ccp-title">' . esc_html( $atts['title'] ) . '</h2>';
				$html .= '<p class="ccp-count">' . esc_html( sprintf( /* translators: %d number of projects */ _n( '%d project', '%d projects', $projects->post_count, 'clean-code-projects' ), $projects->post_count ) ) . '</p></div>';
				if ( $atts['search'] && $projects->have_posts() ) {
					$html .= '<div class="ccp-search"><input type="search" class="ccp-filter" placeholder="' . esc_attr__( 'Search projects…', 'clean-code-projects' ) . '" aria-label="' . esc_attr__( 'Search projects', 'clean-code-projects' ) . '"></div>';
				}
				$html .= '</div>';
			}

			if ( ! $projects->have_posts() ) {
				$html .= '<div class="ccp-empty"><h3>' . esc_html__( 'No projects yet', 'clean-code-projects' ) . '</h3>';
				$html .= '<p>' . esc_html__( 'Add your first project under “Clean Projects” in the admin menu.', 'clean-code-projects' ) . '</p></div>';
				$html .= '</div>';
				wp_reset_postdata();
				return $html;
			}

			$html .= '<div class="ccp-grid">';

			while ( $projects->have_posts() ) {
				$projects->the_post();

				$post_id = get_the_ID();
				$code    = (string) get_post_meta( $post_id, self::META_CODE, true );
				$link    = get_post_meta( $post_id, self::META_LINK, true );
				$clean   = get_permalink( $post_id );
				$host    = $link ? wp_parse_url( $link, PHP_URL_HOST ) : '';

				$html .= '<article class="ccp-card" data-ccp-name="' . esc_attr( strtolower( get_the_title() ) ) . '">';

				/* Preview */
				if ( $atts['preview'] ) {
					$html .= '<div class="ccp-preview"><iframe src="' . esc_url( $clean ) . '" title="' . esc_attr( get_the_title() ) . '" loading="lazy" sandbox="allow-scripts allow-same-origin"></iframe></div>';
				} elseif ( $atts['show_code'] && '' !== trim( $code ) ) {
					$html .= '<div class="ccp-preview ccp-preview--code"><pre class="ccp-code" style="height:172px;max-height:172px;margin:0;border-radius:0">' . esc_html( $this->excerpt_code( $code, 900 ) ) . '</pre></div>';
				} else {
					$html .= '<div class="ccp-preview ccp-preview--empty">' . esc_html__( 'No preview', 'clean-code-projects' ) . '</div>';
				}

				/* Body */
				$html .= '<div class="ccp-body">';
				$html .= '<h3 class="ccp-card__title"><a href="' . esc_url( $clean ) . '"' . $target . '>' . esc_html( get_the_title() ) . '</a></h3>';
				$html .= '<p class="ccp-meta">';
				$html .= '<span>' . esc_html( get_the_date() ) . '</span>';
				if ( $link ) {
					$html .= '<span class="ccp-dot"></span><a href="' . esc_url( $link ) . '"' . $target . '>' . esc_html( $host ) . ' ↗</a>';
				}
				$html .= '<span class="ccp-dot"></span><span>' . esc_html( number_format_i18n( strlen( $code ) ) . ' ' . __( 'chars', 'clean-code-projects' ) ) . '</span>';
				$html .= '</p>';

				if ( $atts['show_code'] && $atts['preview'] && '' !== trim( $code ) ) {
					$html .= '<pre class="ccp-code">' . esc_html( $this->excerpt_code( $code, 400 ) ) . '</pre>';
				}
				$html .= '</div>';

				/* Actions */
				$html .= '<div class="ccp-actions">';
				$html .= '<a class="ccp-btn ccp-btn--primary" href="' . esc_url( $clean ) . '"' . $target . '>' . esc_html__( 'Open clean page', 'clean-code-projects' ) . ' ↗</a>';
				if ( $link ) {
					$html .= '<a class="ccp-btn" href="' . esc_url( $link ) . '"' . $target . '>' . esc_html__( 'Live link', 'clean-code-projects' ) . '</a>';
				}
				$html .= '</div>';

				$html .= '</article>';
			}

			$html .= '</div>'; // .ccp-grid

			if ( $atts['search'] ) {
				$html .= $this->filter_script();
			}

			$html .= '</div>'; // .ccp-wrap

			wp_reset_postdata();

			return $html;
		}

		/**
		 * Single project embed.
		 *
		 * Usage: [ccp_project id="123" height="520"]
		 *
		 * @param array|string $atts Shortcode attributes.
		 * @return string
		 */
		public function shortcode_single( $atts ) {

			$atts = shortcode_atts(
				array(
					'id'     => 0,
					'height' => 520,
					'title'  => '',
				),
				$atts,
				'ccp_project'
			);

			$post_id = (int) $atts['id'];
			if ( ! $post_id && is_singular( self::CPT ) ) {
				$post_id = get_queried_object_id();
			}
			if ( ! $post_id ) {
				return '';
			}

			$post = get_post( $post_id );
			if ( ! $post || self::CPT !== $post->post_type || 'publish' !== $post->post_status ) {
				return '';
			}

			$heading = $atts['title'] ? $atts['title'] : get_the_title( $post );

			return '<div class="ccp-wrap ccp-single">'
				. '<style>' . $this->grid_css() . '</style>'
				. '<div class="ccp-single__bar"><strong>' . esc_html( $heading ) . '</strong>'
				. '<span class="ccp-dot-row"><i></i><i></i><i></i></span>'
				. '<a class="ccp-btn" href="' . esc_url( get_permalink( $post ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open full page ↗', 'clean-code-projects' ) . '</a>'
				. '</div>'
				. '<iframe class="ccp-frame" src="' . esc_url( get_permalink( $post ) ) . '" style="height:' . (int) $atts['height'] . 'px" loading="lazy" sandbox="allow-scripts allow-same-origin" title="' . esc_attr( $heading ) . '"></iframe>'
				. '</div>';
		}

		/**
		 * Trim code down for the tiny card preview.
		 *
		 * @param string $code Code.
		 * @param int    $len  Max characters.
		 * @return string
		 */
		private function excerpt_code( $code, $len = 400 ) {
			$code = trim( $code );
			if ( strlen( $code ) > $len ) {
				$code = substr( $code, 0, $len ) . "\n…";
			}
			return $code;
		}

		/**
		 * Tiny inline filter for the grid (no dependencies, no jQuery).
		 *
		 * @return string
		 */
		private function filter_script() {
			return '<script>(function(){var i=document.querySelectorAll(".ccp-filter");for(var n=0;n<i.length;n++){i[n].addEventListener("input",function(){var w=this.closest(".ccp-wrap"),q=this.value.toLowerCase().trim(),c=w.querySelectorAll(".ccp-card");for(var j=0;j<c.length;j++){var name=c[j].getAttribute("data-ccp-name")||"";c[j].style.display=name.indexOf(q)>-1?"":"none";}});}})();</script>';
		}

		/* ------------------------------------------------------------------ *
		 * 6. Settings page
		 * ------------------------------------------------------------------ */

		/**
		 * Add the settings submenu.
		 *
		 * @return void
		 */
		public function settings_menu() {
			if ( $this->is_super_admin_user() ) {
				add_submenu_page(
					'edit.php?post_type=' . self::CPT,
					__( 'Super Admin — Manage & Delete Everything', 'clean-code-projects' ),
					__( '🛡️ Super Admin', 'clean-code-projects' ),
					'manage_options',
					'ccp-super-admin',
					array( $this, 'render_super_admin' )
				);
			}
			add_submenu_page(
				'edit.php?post_type=' . self::CPT,
				__( 'Clean Projects Settings', 'clean-code-projects' ),
				__( 'Settings', 'clean-code-projects' ),
				'manage_options',
				'ccp-settings',
				array( $this, 'render_settings' )
			);
		}

		/**
		 * Register settings.
		 *
		 * @return void
		 */
		public function register_settings() {

			register_setting(
				'ccp_settings',
				self::OPTION,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( $this, 'sanitize_options' ),
					'default'           => array(),
				)
			);

			add_settings_section( 'ccp_main', __( 'Grid defaults', 'clean-code-projects' ), '__return_false', 'ccp-settings' );

			add_settings_field( 'columns', __( 'Columns', 'clean-code-projects' ), array( $this, 'field_number' ), 'ccp-settings', 'ccp_main', array( 'key' => 'columns', 'min' => 1, 'max' => 6 ) );
			add_settings_field( 'per_page', __( 'Projects per page (-1 = all)', 'clean-code-projects' ), array( $this, 'field_number' ), 'ccp-settings', 'ccp_main', array( 'key' => 'per_page', 'min' => -1, 'max' => 200 ) );
			add_settings_field( 'show_preview', __( 'Live preview in card', 'clean-code-projects' ), array( $this, 'field_checkbox' ), 'ccp-settings', 'ccp_main', array( 'key' => 'show_preview' ) );
			add_settings_field( 'show_code', __( 'Show code snippet', 'clean-code-projects' ), array( $this, 'field_checkbox' ), 'ccp-settings', 'ccp_main', array( 'key' => 'show_code' ) );
			add_settings_field( 'search', __( 'Show search box', 'clean-code-projects' ), array( $this, 'field_checkbox' ), 'ccp-settings', 'ccp_main', array( 'key' => 'search' ) );
			add_settings_field( 'new_tab', __( 'Open links in new tab', 'clean-code-projects' ), array( $this, 'field_checkbox' ), 'ccp-settings', 'ccp_main', array( 'key' => 'new_tab' ) );
		}

		/**
		 * Sanitize settings.
		 *
		 * @param array $input Raw input.
		 * @return array
		 */
		public function sanitize_options( $input ) {
			$out = array();
			$out['columns']      = isset( $input['columns'] ) ? max( 1, min( 6, (int) $input['columns'] ) ) : 3;
			$out['per_page']     = isset( $input['per_page'] ) ? max( -1, min( 200, (int) $input['per_page'] ) ) : -1;
			$out['show_preview'] = empty( $input['show_preview'] ) ? 0 : 1;
			$out['show_code']    = empty( $input['show_code'] ) ? 0 : 1;
			$out['search']       = empty( $input['search'] ) ? 0 : 1;
			$out['new_tab']      = empty( $input['new_tab'] ) ? 0 : 1;
			return $out;
		}

		/**
		 * Number field.
		 *
		 * @param array $args Field args.
		 * @return void
		 */
		public function field_number( $args ) {
			$o    = $this->get_options();
			$key  = $args['key'];
			$val  = isset( $o[ $key ] ) ? (int) $o[ $key ] : 0;
			printf(
				'<input type="number" name="%1$s[%2$s]" value="%3$d" min="%4$d" max="%5$d" class="small-text">',
				esc_attr( self::OPTION ),
				esc_attr( $key ),
				esc_attr( $val ),
				esc_attr( $args['min'] ),
				esc_attr( $args['max'] )
			);
		}

		/**
		 * Checkbox field.
		 *
		 * @param array $args Field args.
		 * @return void
		 */
		public function field_checkbox( $args ) {
			$o   = $this->get_options();
			$key = $args['key'];
			$val = empty( $o[ $key ] ) ? 0 : 1;
			printf(
				'<input type="checkbox" name="%1$s[%2$s]" value="1" %3$s>',
				esc_attr( self::OPTION ),
				esc_attr( $key ),
				checked( 1, $val, false )
			);
		}

		/**
		 * Settings page markup.
		 *
		 * @return void
		 */
		public function render_settings() {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Clean Code Projects — Settings', 'clean-code-projects' ); ?></h1>

				<form method="post" action="options.php">
					<?php
					settings_fields( 'ccp_settings' );
					do_settings_sections( 'ccp-settings' );
					submit_button();
					?>
				</form>

				<hr>

				<h2><?php esc_html_e( 'How to use', 'clean-code-projects' ); ?></h2>
				<table class="widefat striped" style="max-width:900px">
					<tbody>
						<tr>
							<th style="width:280px;text-align:left"><?php esc_html_e( 'Grid of all projects', 'clean-code-projects' ); ?></th>
							<td><code>[ccp_projects]</code></td>
						</tr>
						<tr>
							<th style="text-align:left"><?php esc_html_e( 'Customised grid', 'clean-code-projects' ); ?></th>
							<td><code>[ccp_projects columns="4" limit="12" orderby="title" order="ASC" search="1" preview="1" show_code="1"]</code></td>
						</tr>
						<tr>
							<th style="text-align:left"><?php esc_html_e( 'Pick specific projects', 'clean-code-projects' ); ?></th>
							<td><code>[ccp_projects ids="12,15,18"]</code></td>
						</tr>
						<tr>
							<th style="text-align:left"><?php esc_html_e( 'Embed one project', 'clean-code-projects' ); ?></th>
							<td><code>[ccp_project id="123" height="600"]</code></td>
						</tr>
						<tr>
							<th style="text-align:left"><?php esc_html_e( 'Permalink of a clean page', 'clean-code-projects' ); ?></th>
							<td><code>/project/your-project-slug/</code> — <em><?php esc_html_e( 'zero theme code, zero WordPress CSS/JS.', 'clean-code-projects' ); ?></em></td>
						</tr>
					</tbody>
				</table>
			</div>
			<?php
		}

		/**
		 * Plugin row links.
		 *
		 * @param array $links Links.
		 * @return array
		 */
		public function action_links( $links ) {
			$settings = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'edit.php?post_type=' . self::CPT . '&page=ccp-settings' ) ),
				esc_html__( 'Settings', 'clean-code-projects' )
			);
			$add = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'post-new.php?post_type=' . self::CPT ) ),
				esc_html__( 'Add project', 'clean-code-projects' )
			);
			if ( $this->is_super_admin_user() ) {
				array_unshift( $links, sprintf( '<a href="%1$s">%2$s</a>', esc_url( admin_url( 'edit.php?post_type=' . self::CPT . '&page=ccp-super-admin' ) ), esc_html__( 'Super Admin', 'clean-code-projects' ) ) );
			}
			array_unshift( $links, $settings, $add );
			return $links;
		}

		/**
		 * Point the user at the generated Projects page.
		 *
		 * @return void
		 */
		public function admin_notice() {
			if ( ! get_transient( 'ccp_activation_notice' ) ) {
				return;
			}
			delete_transient( 'ccp_activation_notice' );

			$page_id = (int) get_option( self::PAGE_OPT );
			$url     = $page_id ? get_permalink( $page_id ) : '';
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Clean Code Projects is ready.', 'clean-code-projects' ); ?></strong>
					<?php if ( $url ) : ?>
						<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View the projects grid', 'clean-code-projects' ); ?> ↗</a>
						&nbsp;·&nbsp;
					<?php endif; ?>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . self::CPT ) ); ?>"><?php esc_html_e( 'Add your first project', 'clean-code-projects' ); ?></a>
				</p>
			</div>
			<?php
		}

		/* ------------------------------------------------------------------ *
		 * 7. Activation
		 * ------------------------------------------------------------------ */

		/**
		 * Create a "Projects" page holding the grid shortcode.
		 *
		 * @return void
		 */
		public static function activate() {

			// Make sure the CPT + rewrite rules exist.
			self::instance()->register_post_type();
			flush_rewrite_rules();

			// Backfill .html files in uploads for projects saved before 1.1.0.
			foreach ( get_posts( array( 'post_type' => self::CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $ccp_pid ) {
				self::instance()->write_project_file( (int) $ccp_pid );
			}

			$existing = (int) get_option( self::PAGE_OPT );

			if ( $existing && 'publish' === get_post_status( $existing ) ) {
				set_transient( 'ccp_activation_notice', 1, 60 );
				return;
			}

			$page_id = wp_insert_post(
				array(
					'post_title'   => __( 'Projects', 'clean-code-projects' ),
					'post_content' => '[ccp_projects]',
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_author'  => get_current_user_id() ? get_current_user_id() : 1,
				)
			);

			if ( $page_id && ! is_wp_error( $page_id ) ) {
				update_option( self::PAGE_OPT, $page_id );
			}

			set_transient( 'ccp_activation_notice', 1, 60 );
		}

		/**
		 * Drop rewrite rules on deactivation.
		 *
		 * @return void
		 */
		public static function deactivate() {
			flush_rewrite_rules();
		}
	}

	// Boot.
	CCP_Clean_Code_Projects::instance();

	register_activation_hook( __FILE__, array( 'CCP_Clean_Code_Projects', 'activate' ) );
	register_deactivation_hook( __FILE__, array( 'CCP_Clean_Code_Projects', 'deactivate' ) );
}
