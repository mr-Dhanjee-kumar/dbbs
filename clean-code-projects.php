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
			add_action( 'init', array( $this, 'maybe_flush_rules' ), 99 );
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
					'slug'       => apply_filters( 'ccp_rewrite_slug', 'p' ),
					'with_front' => false,
				),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			);

			register_post_type( self::CPT, $args );
		}

		/**
		 * Refresh permalinks when the stored version changes (e.g. after the
		 * slug changed from /project/ to /p/), so old installs keep working.
		 *
		 * @return void
		 */
		public function maybe_flush_rules() {
			if ( get_option( 'ccp_version' ) !== self::VERSION ) {
				flush_rewrite_rules();
				update_option( 'ccp_version', self::VERSION );
			}
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
				<span class="description"><?php esc_html_e( 'External / live URL of the project (another tool, a hosted demo, a site). Leave the code empty and this link is opened full screen in a borderless iframe on the clean page.', 'clean-code-projects' ); ?></span>
			</p>

			<p class="ccp-field">
				<label for="ccp_mode"><strong><?php esc_html_e( 'Output mode', 'clean-code-projects' ); ?></strong></label>
				<select id="ccp_mode" name="ccp_mode">
					<option value="auto" <?php selected( $mode, 'auto' ); ?>><?php esc_html_e( 'Auto — code if pasted, otherwise the link in an iframe', 'clean-code-projects' ); ?></option>
					<option value="link" <?php selected( $mode, 'link' ); ?>><?php esc_html_e( 'External link — full-screen, borderless iframe of the Project link', 'clean-code-projects' ); ?></option>
					<option value="full" <?php selected( $mode, 'full' ); ?>><?php esc_html_e( 'Code verbatim — print my code byte-for-byte (no doctype, head or body added)', 'clean-code-projects' ); ?></option>
					<option value="fragment" <?php selected( $mode, 'fragment' ); ?>><?php esc_html_e( 'Code skeleton — wrap my snippet in a bare doctype + charset + viewport', 'clean-code-projects' ); ?></option>
				</select>
				<span class="description"><?php esc_html_e( 'Every mode produces a page with ONLY what you asked for: no theme, no wp_head, no body classes, no WordPress CSS or JS of any kind.', 'clean-code-projects' ); ?></span>
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
			if ( ! in_array( $mode, array( 'auto', 'full', 'fragment', 'link' ), true ) ) {
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
				$mode = $this->resolve_mode( $post_id, $code );
				$labels = array(
					'link'     => __( 'external link (iframe)', 'clean-code-projects' ),
					'full'     => __( 'full document', 'clean-code-projects' ),
					'fragment' => __( 'snippet', 'clean-code-projects' ),
				);
				if ( '' === trim( $code ) && 'link' !== $mode ) {
					echo '<span aria-hidden="true">—</span>';
					return;
				}
				printf(
					'<code>%1$s</code> <span class="description">(%2$s)</span>',
					esc_html( '' === trim( $code ) ? __( 'link only', 'clean-code-projects' ) : number_format_i18n( strlen( $code ) ) . ' ' . __( 'chars', 'clean-code-projects' ) ),
					esc_html( isset( $labels[ $mode ] ) ? $labels[ $mode ] : $mode )
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
			if ( in_array( $mode, array( 'full', 'fragment', 'link' ), true ) ) {
				return $mode;
			}

			$link = trim( (string) get_post_meta( $post_id, self::META_LINK, true ) );

			// Auto: no code but a link? Show the link full screen instead.
			if ( '' === trim( $code ) && '' !== $link ) {
				return 'link';
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

			// External link: a borderless iframe that fills the whole viewport.
			if ( 'link' === $mode ) {
				return $this->build_link_document( (string) get_post_meta( $post->ID, self::META_LINK, true ), $post );
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

		/**
		 * Minimal full-screen, borderless iframe wrapper for an external URL.
		 *
		 * @param string  $url  External URL.
		 * @param WP_Post $post Project.
		 * @return string
		 */
		private function build_link_document( $url, $post ) {

			$title = get_the_title( $post );

			if ( '' === trim( $url ) ) {
				// No link after all: fall back to an empty skeleton.
				return '<!DOCTYPE html>' . "\n<html lang=\"" . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '"><title>' . esc_html( $title ) . '</title></head><body></body></html>';
			}

			$style = 'html,body{margin:0;padding:0;width:100%;height:100%;overflow:hidden;background:#fff}'
				. 'iframe{display:block;width:100%;height:100%;border:0;margin:0;padding:0;background:#fff}';

			return '<!DOCTYPE html>' . "\n"
				. '<html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '">' . "\n"
				. '<head>' . "\n"
				. '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">' . "\n"
				. '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
				. '<title>' . esc_html( $title ) . '</title>' . "\n"
				. '<style>' . $style . '</style>' . "\n"
				. '</head>' . "\n"
				. '<body>' . "\n"
				. '<iframe src="' . esc_url( $url ) . '" title="' . esc_attr( $title ) . '" allowfullscreen loading="eager" referrerpolicy="no-referrer"></iframe>' . "\n"
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
				'columns'      => 3,
				'per_page'     => -1,
				'show_preview' => 0,
				'new_tab'      => 1,
				'search'       => 1,
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
.ccp-wrap{--ccp-card:#fff;--ccp-text:#14161a;--ccp-muted:#6b7280;--ccp-line:#e5e7eb;--ccp-accent:#4f46e5;--ccp-radius:14px;font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--ccp-text);max-width:100%;margin:0 auto}
.ccp-wrap *{box-sizing:border-box}
.ccp-head{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;justify-content:space-between;margin:8px 0 20px}
.ccp-title{margin:0;font-size:1.35rem;font-weight:700;letter-spacing:-.02em}
.ccp-count{margin:4px 0 0;color:var(--ccp-muted);font-size:.85rem}
.ccp-search{flex:0 1 260px;min-width:190px}
.ccp-search input{width:100%;padding:10px 14px;border:1px solid var(--ccp-line);border-radius:999px;font:inherit;font-size:.875rem;background:var(--ccp-card);color:inherit;transition:border-color .2s,box-shadow .2s}
.ccp-search input:focus{outline:none;border-color:var(--ccp-accent);box-shadow:0 0 0 4px rgba(79,70,229,.12)}
.ccp-grid{display:grid;grid-template-columns:repeat(var(--ccp-cols,3),minmax(0,1fr));gap:20px}
@media (max-width:1024px){.ccp-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:600px){.ccp-grid{grid-template-columns:1fr}}
.ccp-card{background:var(--ccp-card);border:1px solid var(--ccp-line);border-radius:var(--ccp-radius);overflow:hidden;transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}
.ccp-card:hover{transform:translateY(-3px);border-color:#cdd2da;box-shadow:0 14px 28px -14px rgba(16,24,40,.3)}
.ccp-card__link{display:block;text-decoration:none;color:inherit}
.ccp-card__link:focus-visible{outline:2px solid var(--ccp-accent);outline-offset:2px}
.ccp-cover{position:relative;display:block;width:100%;aspect-ratio:16/10;background:#f3f5f8;border-bottom:1px solid var(--ccp-line);overflow:hidden}
.ccp-cover img{display:block;width:100%;height:100%;object-fit:cover;transition:transform .4s ease}
.ccp-card:hover .ccp-cover img{transform:scale(1.04)}
.ccp-cover iframe{position:absolute;top:0;left:0;width:400%;height:400%;transform:scale(.25);transform-origin:top left;border:0;pointer-events:none;background:#fff}
.ccp-cover__ph{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:22px;text-align:center;background:linear-gradient(135deg,var(--ccp-ph1,#eef2ff),var(--ccp-ph2,#f8fafc))}
.ccp-cover__ph span{position:relative;font-size:.95rem;font-weight:650;line-height:1.4;color:var(--ccp-ph3,#64748b);display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word}
.ccp-card__title{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;padding:13px 15px;font-size:.93rem;font-weight:600;line-height:1.4;letter-spacing:-.01em;word-break:break-word}
.ccp-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:9px;font-size:.82rem;font-weight:600;text-decoration:none;border:1px solid var(--ccp-line);background:var(--ccp-card);color:var(--ccp-text);transition:background .2s,border-color .2s}
.ccp-btn:hover{background:#f3f4f6;border-color:#d6d8de}
.ccp-empty{padding:52px 20px;text-align:center;border:1px dashed var(--ccp-line);border-radius:var(--ccp-radius);color:var(--ccp-muted)}
.ccp-empty h3{margin:0 0 6px;color:var(--ccp-text);font-size:1.05rem}
.ccp-empty p{margin:0}
.ccp-single{background:var(--ccp-card);border:1px solid var(--ccp-line);border-radius:var(--ccp-radius);overflow:hidden;margin:12px 0}
.ccp-single__bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;padding:11px 16px;border-bottom:1px solid var(--ccp-line);background:#fafbfc}
.ccp-single__bar strong{font-size:.92rem;font-weight:600}
.ccp-frame{display:block;width:100%;height:520px;border:0;background:#fff}
@media (max-width:600px){.ccp-frame{height:400px}}
@media (prefers-color-scheme:dark){
.ccp-wrap{--ccp-card:#121826;--ccp-text:#e8eaee;--ccp-muted:#98a2b3;--ccp-line:#242c3d}
.ccp-search input{background:var(--ccp-card);border-color:var(--ccp-line);color:inherit}
.ccp-card:hover{border-color:#33415a;box-shadow:0 14px 28px -14px rgba(0,0,0,.7)}
.ccp-cover{background:#0f172a;border-bottom-color:var(--ccp-line)}
.ccp-cover__ph::after{content:"";position:absolute;inset:0;background:rgba(11,15,25,.55)}
.ccp-cover__ph span{color:#e6e9ee}
.ccp-btn:hover{background:#1b2333;border-color:#33415a}
.ccp-single__bar{background:#0f1522}
}
CSS;
		}

		/**
		 * Grid of all projects — minimal bordered cards: cover image + title.
		 *
		 * Cover priority: featured image -> (optional live iframe) -> placeholder
		 * block showing the project title.
		 *
		 * Usage: [ccp_projects columns="3" limit="12" orderby="date" order="DESC"
		 *         search="1" preview="0" new_tab="1" ids="1,2,3"]
		 *
		 * @param array|string $atts Shortcode attributes.
		 * @return string
		 */
		public function shortcode_grid( $atts ) {

			$o = $this->get_options();

			$atts = shortcode_atts(
				array(
					'columns'  => $o['columns'],
					'limit'    => $o['per_page'],
					'per_page' => $o['per_page'],
					'orderby'  => 'date',
					'order'    => 'DESC',
					'ids'      => '',
					'search'   => $o['search'],
					'preview'  => $o['show_preview'],
					'new_tab'  => $o['new_tab'],
					'title'    => '',
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
				$query_args['post__in']       = array_filter( array_map( 'intval', explode( ',', $atts['ids'] ) ) );
				$query_args['orderby']        = 'post__in';
				$query_args['posts_per_page'] = count( $query_args['post__in'] );
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
				$clean   = get_permalink( $post_id );
				$title   = get_the_title();
				$thumb   = has_post_thumbnail( $post_id ) ? (int) get_post_thumbnail_id( $post_id ) : 0;

				$html .= '<article class="ccp-card" data-ccp-name="' . esc_attr( strtolower( $title ) ) . '">';
				$html .= '<a class="ccp-card__link" href="' . esc_url( $clean ) . '"' . $target . '>';

				if ( $thumb ) {
					$html .= '<span class="ccp-cover"><img src="' . esc_url( wp_get_attachment_image_url( $thumb, 'medium_large' ) ) . '" alt="' . esc_attr( $title ) . '" loading="lazy" decoding="async"></span>';
				} elseif ( $atts['preview'] ) {
					$html .= '<span class="ccp-cover"><iframe src="' . esc_url( $clean ) . '" title="' . esc_attr( $title ) . '" loading="lazy" sandbox="allow-scripts allow-same-origin"></iframe></span>';
				} else {
					$html .= '<span class="ccp-cover" style="' . esc_attr( $this->placeholder_style( $title ) ) . '"><span class="ccp-cover__ph"><span>' . esc_html( $title ) . '</span></span></span>';
				}

				$html .= '<span class="ccp-card__title">' . esc_html( $title ) . '</span>';
				$html .= '</a>';
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
		 * Deterministic pastel gradient for the "no featured image" placeholder.
		 *
		 * @param string $seed Project title (keeps the colour stable per project).
		 * @return string Inline custom-property declarations.
		 */
		private function placeholder_style( $seed ) {
			$hue = hexdec( substr( md5( $seed ), 0, 4 ) ) % 360;
			return sprintf(
				'--ccp-ph1:hsl(%1$d 68%% 96%%);--ccp-ph2:hsl(%2$d 60%% 90%%)',
				$hue,
				( $hue + 26 ) % 360
			);
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
				. '<a class="ccp-btn" href="' . esc_url( get_permalink( $post ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open full page ↗', 'clean-code-projects' ) . '</a>'
				. '</div>'
				. '<iframe class="ccp-frame" src="' . esc_url( get_permalink( $post ) ) . '" style="height:' . ( (int) $atts['height'] ) . 'px" loading="lazy" frameborder="0" scrolling="auto" sandbox="allow-scripts allow-same-origin" title="' . esc_attr( $heading ) . '"></iframe>'
				. '</div>';
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
			add_settings_field( 'show_preview', __( 'Live iframe cover (only when no featured image)', 'clean-code-projects' ), array( $this, 'field_checkbox' ), 'ccp-settings', 'ccp_main', array( 'key' => 'show_preview' ) );
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
							<td><code>[ccp_projects columns="4" limit="12" orderby="title" order="ASC" search="1" preview="1"]</code></td>
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
