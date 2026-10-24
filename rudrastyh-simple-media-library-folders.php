<?php
/*
 * Plugin name: Simple Media Library Folders (Free)
 * Description: The plugin allows you to organize the WordPress media library in folders
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Version: 1.9
 * Domain Path: /languages
 * Text Domain: rudrastyh-simple-media-library-folders
 * License: GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if( ! class_exists( 'SMLF_Simple_Media_Library_Folders' ) ) {

	class SMLF_Simple_Media_Library_Folders{

		const TAXONOMY_NAME = 'smlf_folder';
		const SETTINGS_PAGE = 'smlf-media-library-folders';

		const PLUGINS_FOR_IMPORT = array(
			'happyfiles' => array(
				'name' => 'HappyFiles',
				'taxonomy' => 'happyfiles_category',
			),
			'filebird' => array(
				'name' => 'FileBird',
			),
			'real-media-library' => array(
				'name' => 'Real Media Library',
			),
			'wickedfolders' => array(
				'name' => 'Wicked Folders',
				'taxonomy' => 'wf_attachment_folders',
			),
			'wp-media-folder' => array(
				'name' => 'WP Media Folder (by JoomUnited)',
				'taxonomy' => 'wpmf-category',
			),
			'premio' => array(
				'name' => 'Folders (by Premio)',
				'taxonomy' => 'media_folder',
			),
		);

		public function __construct() {

			add_action( 'init', array( $this, 'init' ) );
			add_filter( 'taxonomy_parent_dropdown_args', array( $this, 'limit_taxonomy_depth' ), 25, 2 );
			add_action( 'admin_enqueue_scripts', array( $this, 'css_and_js' ) );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
			// settings page
			add_action( 'admin_menu', array( $this, 'add_options_page' ) );
			add_action( 'admin_post_rudrsmlfpagesave', array( $this, 'settings_save' ) );
			add_filter( 'plugin_action_links', array( $this, 'settings_link' ), 10, 2 );

			// folder selection
			add_action( 'pre-upload-ui', array( $this, 'uploader_select_folder' ) );
			add_action( 'add_attachment', array( $this, 'upload_to_folder' ) );

			// folder selection when editing attachment
			add_filter( 'attachment_fields_to_edit', array( $this, 'edit_attachment_fields' ), 25, 2 );
			add_filter( 'attachment_fields_to_save', array( $this, 'save_attachment_fields' ), 25 );

			// list view filters
			add_action( 'restrict_manage_posts', array( $this, 'list_view_filter' ) );

			//	some slight query modifications (for uncategorized items)
			add_filter( 'ajax_query_attachments_args', array( $this, 'filter_query' ), 9999 );

			add_action( 'in_admin_header', array( $this, 'show_sb' ) );
			add_action( 'wp_ajax_smlf_sb_width', array( $this, 'save_sb_width' ) );

			add_action( 'wp_ajax_smlf_add_to_folder', array( $this, 'add_to_folder' ) );
			add_action( 'wp_ajax_smlf_delete_folder', array( $this, 'delete_folder' ) );
			add_action( 'wp_ajax_smlf_add_edit_folder', array( $this, 'add_edit_folder' ) );
			add_action( 'wp_ajax_smlf_sort_folders', array( $this, 'sort_folders' ) );
			add_action( 'wp_ajax_smlf_counts', array( $this, 'recalculate_counts' ) );

			add_action( 'pre_get_terms', array( $this, 'filter_query_folders' ), 25 );
			// downloads
			add_action( 'admin_post_smlf_download', array( $this, 'download_folder' ) );
			// screen options
			add_filter( 'screen_settings', array( $this, 'screen_options' ), 25, 2 );

			// imports
			add_action( 'admin_post_mlf_import', array( $this, 'run_import' ) );
			add_action( 'admin_post_mlf_delete_data', array( $this, 'delete_plugin_data' ) );
			add_action( 'admin_notices', array( $this, 'admin_notices' ) );

			// third-party compatibility
			add_action( 'admin_head', array( $this, 'flatsome_ux_builder' ) );

		}

		/****************************/
		/*     HELPER FUNCTIONS     */
		/****************************/
		public function get_folders_taxonomy_name( $post_type = 'attachment' ){
			$folders_taxonomy_name = 'attachment' === $post_type ? self::TAXONOMY_NAME : self::TAXONOMY_NAME . '_' . $post_type;

			return $folders_taxonomy_name;

		}
		public function get_media_count( $post_type = 'attachment' ) {
			global $wpdb;

			return $wpdb->get_var(
				$wpdb->prepare(
					"
					SELECT COUNT(ID)
					FROM {$wpdb->posts}
					WHERE post_type = %s
					AND post_status != 'auto-draft'
					",
					$post_type
				)
			);
		}

		public function get_media_without_folders_count( $post_type = 'attachment' ) {
			global $wpdb;

			return $wpdb->get_var(
				$wpdb->prepare(
					"
					SELECT COUNT(p.ID)
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
					AND tr.term_taxonomy_id IN (SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s )
					WHERE p.post_type = %s
					AND tr.object_id IS NULL
					AND p.post_status != 'auto-draft'
					",
					( 'attachment' === $post_type ? self::TAXONOMY_NAME : self::TAXONOMY_NAME . '_' . $post_type ),
					$post_type
				)
			);

		}

		public function get_parent_and_children_folder_count( $parent_folder_id, $child_folders = array() ) {

			global $wpdb;

			$term_ids = $child_folders ? wp_list_pluck( $child_folders, 'term_id' ) : array();
			$term_ids[] = $parent_folder_id;

			return $wpdb->get_var(
				$wpdb->prepare(
					"
					SELECT COUNT(p.ID)
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
					WHERE tr.term_taxonomy_id IN (%s)
					AND p.post_type = 'attachment'
					",
					join( ',', $term_ids )
				)
			);

		}

		private function is_folder_selected( $taxonomy_name ) {
			if(
				isset( $_GET[ $taxonomy_name ] ) && ! in_array( $_GET[ $taxonomy_name ], array( 'all', 'uncategorized' ) )
				|| isset( $_GET[ 'taxonomy' ] ) && $taxonomy_name === $_GET[ 'taxonomy' ] && isset( $_GET[ 'term' ] ) && ! in_array( $_GET[ 'term' ], array( 'all', 'uncategorized' ) )
			) {
				return true;
			} else {
				return false;
			}
		}

		private function url_params_not_set( $taxonomy_name = '' ) {

			$taxonomy_name = $taxonomy_name ? $taxonomy_name : self::TAXONOMY_NAME;

			return (
				// first option of filtering
				! isset( $_GET[ $taxonomy_name ] )
				&& (
					// first option of filtering
					! isset( $_GET[ 'taxonomy' ] )
					|| isset( $_GET[ 'taxonomy' ] ) && $taxonomy_name !== $_GET[ 'taxonomy' ]
					|| ! isset( $_GET[ 'term' ] )
				)
			);

		}

		private function get_current_folder_slug() {
			// scenario 1
			$taxonomy_1 = isset( $_GET[ 'taxonomy' ] ) ? sanitize_text_field( $_GET[ 'taxonomy' ] ) : '';
			$term = isset( $_GET[ 'term' ] ) ? sanitize_text_field( $_GET[ 'term' ] ) : '';
			// scenario 2
			$taxonomy_2 = isset( $_GET[ self::TAXONOMY_NAME ] ) ? sanitize_text_field( $_GET[ self::TAXONOMY_NAME ] ) : '';

			return self::TAXONOMY_NAME === $taxonomy_1 && $term ? $term : ( $taxonomy_2 ? $taxonomy_2 : '' );
		}

		private function is_current_folder( $term, $taxonomy_name = '' ) {
			$slug = is_a( $term, 'WP_Term' ) ? $term->slug : $term;
			$taxonomy_name = is_a( $term, 'WP_Term' ) ? $term->taxonomy : $taxonomy_name;
			if(
				isset( $_GET[ $taxonomy_name ] ) && $slug === $_GET[ $taxonomy_name ]
				|| isset( $_GET[ 'taxonomy' ] ) && $taxonomy_name === $_GET[ 'taxonomy' ] && isset( $_GET[ 'term' ] ) && $slug === $_GET[ 'term' ]
			) {
				return true;
			} else {
				return false;
			}
		}

		private function is_current_child_folder( $terms, $args = array() ) {
			$is_current_child_folder = false;
			if( $terms ) {
				foreach( $terms as $term ) {
					if( $this->is_current_folder( $term ) ) {
						$is_current_child_folder = true;
						break;
					}
					// 3-level support for list view
					if( 2 === $args[ 'depth' ] && ( $child_terms = get_terms(
						array(
							'taxonomy' => self::TAXONOMY_NAME,
							'hide_empty' => false,
							'orderby' => 'name',
							'order' => 'ASC',
							'parent' => $term->term_id,
						)
					) ) ) {
						foreach( $child_terms as $child_term ) {
							if( $this->is_current_folder( $child_term ) ) {
								$is_current_child_folder = true;
								break;
							}
						}
					}
				}
			}
			return $is_current_child_folder;
		}

		public function get_folders() {

			$terms = get_terms(
				array(
					'taxonomy' => self::TAXONOMY_NAME,
					'hide_empty' => false,
					'orderby' => 'term_group',
					'order' => 'ASC',
				)
			);
			return $terms;
		}

		public function filter_query_folders( $query ) { 
//return;
			// check the taxonomy name
			if( empty( $query->query_vars[ 'taxonomy' ] ) ) {
				return;
			}
			// not our taxonomy
			$taxonomy_name = reset( $query->query_vars[ 'taxonomy' ] );
			if( self::TAXONOMY_NAME !== $taxonomy_name ) {
				return;
			}

			$query->query_vars[ 'orderby' ] = 'term_group';
   			$query->query_vars[ 'order' ] = 'ASC';

		}

		public function body_class( $classes ){
			if( ! function_exists( 'get_current_screen' ) ) {
				return $classes;
			}

			$screen = get_current_screen();

			if( 'attachment' === $screen->post_type ) {
				$classes .= ' is-smlf-enabled-for-cpt ';
			}

			return $classes;
		}
		
		public function css_and_js() {

			wp_enqueue_style( 'smlf-styles', plugin_dir_url( __FILE__ ) . 'assets/media-folders.css', array(), filemtime( __DIR__ . '/assets/media-folders.css' ) );

			wp_enqueue_script( 'jquery-ui-draggable' );
			wp_enqueue_script( 'jquery-ui-droppable' );

			wp_register_script(
				'smlf-media-category',
				plugin_dir_url( __FILE__ ) . 'assets/media-folders.js',
				array( 'jquery', 'jquery-ui-draggable', 'jquery-ui-droppable', 'plupload', 'media-models' ),
				filemtime( __DIR__ . '/assets/media-folders.js' ),
				true
			);

			$script_data = array(
				'folders' => $this->get_folders(),
				'nonce' => wp_create_nonce( 'add_to_folder' ),
				'nonce2' => wp_create_nonce( 'del_folder' ),
				'nonce3' => wp_create_nonce( 'sb_width' ),
				'nonce4' => wp_create_nonce( 'download_folder' ),
				'nonce5' => wp_create_nonce( 'media-taxonomy-ajax-nonce' ),
				'show_count' => get_option( '_smlf_show_count', 'both' ),
				// string translations
				'filterlabel' => __( 'Folders', 'rudrastyh-simple-media-library-folders' ),
				'uncategorized' => __( 'Uncategorized', 'rudrastyh-simple-media-library-folders' ),
				'allfolders' => __( 'All folders', 'rudrastyh-simple-media-library-folders' ),
				'moveitem' => __( 'Move 1 item', 'rudrastyh-simple-media-library-folders' ),
				/* translators: 1: plural number of items */
				'moveitems' => __( 'Move %d items', 'rudrastyh-simple-media-library-folders' ),
				'downloadfolder' => __( 'Download Folder', 'rudrastyh-simple-media-library-folders' ),
				'deletefolderconfirmtext' => __( "You are about to permanently delete this folder from your site.\nThis action cannot be undone.\n'Cancel' to stop, 'OK' to delete.", 'rudrastyh-simple-media-library-folders' ),
			);

			$script_data[ 'folders' ] = $this->get_folders();

			wp_localize_script( 'smlf-media-category', 'SMLFMediaFoldersData', $script_data );

			wp_enqueue_script( 'smlf-media-category' );

		}

		public function add_options_page(){

			add_options_page(
				__( 'Media Library Folders Settings', 'rudrastyh-simple-media-library-folders' ),
				__( 'Media Folders', 'rudrastyh-simple-media-library-folders' ),
				'manage_options',
				self::SETTINGS_PAGE,
				array( $this, 'options_fields' )
			);

		}

		public function options_fields() {

			$show_count = get_option( '_smlf_show_count', 'both' );
			$show_search = get_option( '_smlf_show_search', 'yes' );
			$show_in_admin_menu = get_option( '_smlf_show_in_admin_menu' );
			$allow_svg = get_option( '_smlf_allow_svg' );

			?><div class="wrap">
				<h1><?php esc_html_e( 'Media Library Folders Settings', 'rudrastyh-simple-media-library-folders' ) ?></h1>
				<?php

					$tabs = array(
						'general' => esc_html__( 'General', 'rudrastyh-simple-media-library-folders' ),
						'caps' => esc_html__( 'Capabilities', 'rudrastyh-simple-media-library-folders' ),
						'post_types' => esc_html__( 'Post Types', 'rudrastyh-simple-media-library-folders' ),
						'import' => esc_html__( 'Import', 'rudrastyh-simple-media-library-folders' ),
					);

					if( ! $this->is_import_available() ) {
						unset( $tabs[ 'import' ] );
					}
					$current_tab = ! empty( $_GET[ 'tab' ] ) && array_key_exists( $_GET[ 'tab' ], $tabs ) ? esc_attr( $_GET[ 'tab' ] ) : 'general';

				?>
				<form method="post" action="<?php echo esc_attr( add_query_arg( array( 'action' => 'rudrsmlfpagesave' ), 'admin-post.php' ) ) ?>">

					<?php if( count( $tabs ) > 1 ) : ?>
						<nav class="nav-tab-wrapper">
							<?php
							foreach( $tabs as $tab => $name ){
								echo '<a class="nav-tab' . ( ( $tab == $current_tab ) ? ' nav-tab-active' : '' ) . '" href="' . esc_attr( add_query_arg( array( 'page' => self::SETTINGS_PAGE, 'tab' => $tab ), '' ) ) . '">' . $name . '</a>';
							}
							?>
						</nav>
					<?php endif; ?>
					<?php wp_nonce_field( 'smlf-validate' ); ?>

					<?php if( 'post_types' === $current_tab ) : ?>
						<table class="form-table">

							<?php 
								$allowed_post_types = array( 'attachment' );
								$post_types = get_post_types( array( 'show_ui' => true ), 'objects' );
							?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Allowed Post Types', 'rudrastyh-simple-media-library-folders' ) ?></th>
								<td>
									<fieldset>
										<?php foreach( $post_types as $post_type ) : ?>
											<?php
												if( in_array( $post_type->name, array( 'wp_block', 'wp_navigation', 'shop_order', 'shop_coupon' ) ) ) {
													continue;
												}
											?>
											<label>
												<input type="checkbox" name="allowed_post_types[]"<?php if( in_array( $post_type->name, $allowed_post_types ) ) echo ' checked="checked"' ?> disabled /> <?php echo esc_html( $post_type->labels->singular_name ) ?>
											</label><br />
										<?php endforeach; ?>
									</fieldset>
									<p class="description">(<a href="https://rudrastyh.com/plugins/wordpress-media-library-folders">Pro</a>) <?php esc_html_e( 'You can use folders for custom post types as well. Folders for each post type will be unique.', 'rudrastyh-simple-media-library-folders' ) ?></p>
								</td>
							</tr>
						</table>
						<?php submit_button(); ?>
					<?php elseif( 'caps' === $current_tab ) : ?>
						<table class="form-table">
							<?php 
								$roles = get_editable_roles();
								$folders_caps = array( 
									'view_folders',
									'edit_folders',
									'delete_folders',
									'assign_folders',
								);

								foreach( $roles as $role_slug => $role ) :
							?>
								<tr>
									<th scope="row"><?php echo $role[ 'name' ] ?></th>
									<td>
										<fieldset>
											<?php foreach( $folders_caps as $cap ) : ?>
												<label><input type="checkbox" name="folder_caps[<?php echo $role_slug ?>][]" value="<?php echo $cap ?>" disabled checked> <?php echo $cap ?></label><br>
											<?php endforeach; ?>
											<p class="description">(<a href="https://rudrastyh.com/plugins/wordpress-media-library-folders">Pro</a>) <?php esc_html_e( 'You can configure folder managemenent permissions for each user role.', 'rudrastyh-simple-media-library-folders' ) ?></p>
										</fieldset>
									</td>
								</tr>
							<?php 
								endforeach 
							?>
						</table>
						<?php submit_button(); ?>
					<?php elseif( 'import' === $current_tab ) : ?>
						<p><?php esc_html_e( 'It seems that before you used the following media library folder plugins. You can import their data or completely remove it from the database.', 'rudrastyh-simple-media-library-folders' ) ?></p>
						<table class="widefat importers striped plugins"><tbody>
							<?php
								foreach( self::PLUGINS_FOR_IMPORT as $plugin_slug => $plugin ) :
									// having at least one folder is required for import
									$found_folders = $this->get_folder_count_to_import( $plugin_slug );
									if( ! $found_folders ) {
										continue;
									}

									$media_count = $this->get_media_count_to_import( $plugin_slug );

									$import_url = wp_nonce_url( add_query_arg( array( 'action' => 'mlf_import', 'plugin_slug' => $plugin_slug ), admin_url( 'admin-post.php' ) ), "mlf-import-{$plugin_slug}" );
									$delete_data_url = wp_nonce_url( add_query_arg( array( 'action' => 'mlf_delete_data', 'plugin_slug' => $plugin_slug ), admin_url( 'admin-post.php' ) ), "mlf-delete-data-{$plugin_slug}" );

									?><tr class="importer-item">
										<td class="import-system">
											<span class="importer-title"><?php echo $plugin[ 'name' ] ?></span>
											<span class="importer-action">
												<a href="<?php echo $import_url ?>"><?php esc_html_e( 'Run import', 'rudrastyh-simple-media-library-folders' ) ?></a> |
												<a href="<?php echo $delete_data_url ?>" class="delete"><?php esc_html_e( 'Delete plugin data', 'rudrastyh-simple-media-library-folders' ) ?></a>
											</span>
										</td>
										<td class="desc">
											<span class="importer-desc"><?php echo sprintf( _n( '1 folder', '%s folders', $found_folders, 'rudrastyh-simple-media-library-folders' ), $found_folders ); ?> <?php echo sprintf( _n( 'with 1 associated media file found to import', 'with %s associated media files found to import', $media_count, 'rudrastyh-simple-media-library-folders' ), $media_count ); ?>.</span>
										</td>
									</tr>
									<?php
								endforeach;
							?>
						</tbody></table>
					<?php else: ?>
						<table class="form-table">
							<tr valign="top">
								<th scope="row"><?php esc_html_e( 'Show Count', 'rudrastyh-simple-media-library-folders' ) ?></th>
								<td>
									<fieldset>
										<p>
											<label><input name="show_count" type="radio" value="both"<?php checked( $show_count, 'both' ) ?>> <?php esc_html_e( 'Count files in both parent folder and its subfolders', 'rudrastyh-simple-media-library-folders' ) ?></label><br>
											<label><input name="show_count" type="radio" value="per-folder"<?php checked( $show_count, 'per-folder' ) ?>> <?php esc_html_e( 'Count files only in each folder itself', 'rudrastyh-simple-media-library-folders' ) ?></label><br />
											<label><input name="show_count" type="radio" value="no"<?php checked( $show_count, 'no' ) ?>> <?php esc_html_e( 'Hide file count', 'rudrastyh-simple-media-library-folders' ) ?></label>
										</p>
										<p class="description"><?php esc_html_e( 'This option allows you to show the total number of files in every folder.', 'rudrastyh-simple-media-library-folders' ) ?></p>
									</fieldset>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><?php esc_html_e( 'Show Search', 'rudrastyh-simple-media-library-folders' ) ?></th>
								<td>
									<label><input type="checkbox" name="show_search" <?php checked( 'yes', $show_search ) ?>>&nbsp;<?php esc_html_e( 'Yes' ) ?></label>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><?php esc_html_e( 'Show in Admin Menu', 'rudrastyh-simple-media-library-folders' ) ?></th>
								<td>
									<label><input type="checkbox" name="show_in_admin_menu" <?php checked( 'yes', $show_in_admin_menu ) ?>>&nbsp;<?php esc_html_e( 'Yes' ) ?></label>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><?php esc_html_e( 'Show In List View', 'rudrastyh-simple-media-library-folders' ) ?></th>
								<td>
									<label><input type="checkbox" name="show_in_list_view" disabled>&nbsp;<?php esc_html_e( 'Yes' ) ?></label>
									<p class="description">(<a href="https://rudrastyh.com/plugins/wordpress-media-library-folders">Pro</a>) <?php esc_html_e( 'By default, the folders are only displayed in the grid view to keep the Media Library page nice and clean.', 'rudrastyh-simple-media-library-folders' ) ?></p>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><?php esc_html_e( 'Show In Media Modals', 'rudrastyh-simple-media-library-folders' ) ?></th>
								<td>
									<label><input type="checkbox" name="show_in_media_modals" disabled>&nbsp;<?php esc_html_e( 'Yes' ) ?></label>
									<p class="description">(<a href="https://rudrastyh.com/plugins/wordpress-media-library-folders">Pro</a>) <?php esc_html_e( 'By default, only a simple filter by folder is available, but with this option you can display the entire folder sidebar.', 'rudrastyh-simple-media-library-folders' ) ?></p>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><?php esc_html_e( 'Delete Media Files with a Folder', 'rudrastyh-simple-media-library-folders' ) ?></th>
								<td>
									<label><input type="checkbox" name="delete_media_with_folder" disabled>&nbsp;<?php esc_html_e( 'Yes' ) ?></label>
									<p class="description">(<a href="https://rudrastyh.com/plugins/wordpress-media-library-folders">Pro</a>) <?php esc_html_e( 'With this option selected, when you delete a folder, all the media files in this folder will be deleted as well.', 'rudrastyh-simple-media-library-folders' ) ?></p>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><label for="default_folder"><?php esc_html_e( 'Default Folder', 'rudrastyh-simple-media-library-folders' ) ?></label></th>
								<td>
									<fieldset>
										<select disabled style="min-width:200px"><option value="0"><?php esc_html_e( 'All files', 'rudrastyh-simple-media-library-folders' ) ?></option></select>
										<p  style="margin-top:10px;"><label><input type="checkbox" name="previously_selected" disabled>&nbsp;<?php esc_html_e( 'Previously selected folder', 'rudrastyh-simple-media-library-folders' ) ?></label></p>
										<p class="description">(<a href="https://rudrastyh.com/plugins/wordpress-media-library-folders">Pro</a>) <?php esc_html_e( 'Select a folder which will be opened by default in the media library.', 'rudrastyh-simple-media-library-folders' ) ?></p>
									</fieldset>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><?php esc_html_e( 'Allow SVG Uploads', 'rudrastyh-simple-media-library-folders' ) ?></th>
								<td>
									<label><input type="checkbox" name="allow_svg" <?php checked( 'yes', $allow_svg ) ?>>&nbsp;<?php esc_html_e( 'Yes' ) ?></label>
									<p class="description"><?php esc_html_e( 'Allow SVG uploads and sanitization.', 'rudrastyh-simple-media-library-folders' ) ?></p>
								</td>
							</tr>
						</table>
						<?php submit_button(); ?>
					<?php endif; ?>
				</form></div>
			<?php

		}


		function settings_save(){

			check_admin_referer( 'smlf-validate' ); // Nonce security check

			$show_count = ! empty( $_POST[ 'show_count' ] ) && in_array( $_POST[ 'show_count' ], array( 'both', 'per-folder', 'no' ) ) ? sanitize_text_field( wp_unslash( $_POST[ 'show_count' ] ) ) : 'no';
			update_option( '_smlf_show_count', $show_count );

			$show_search = ! empty( $_POST[ 'show_search' ] ) && 'on' == $_POST[ 'show_search' ] ? 'yes' : 'no';
			update_option( '_smlf_show_search', $show_search );

			$show_in_admin_menu = ! empty( $_POST[ 'show_in_admin_menu' ] ) && 'on' == $_POST[ 'show_in_admin_menu' ] ? 'yes' : 'no';
			update_option( '_smlf_show_in_admin_menu', $show_in_admin_menu );
			
			$allow_svg = ! empty( $_POST[ 'allow_svg' ] ) && 'on' == $_POST[ 'allow_svg' ] ? 'yes' : 'no';
			update_option( '_smlf_allow_svg', $allow_svg );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => self::SETTINGS_PAGE,
						'updated' => true,
						'tab' => $tab
					),
					admin_url( 'options-general.php' )
				)
			);

			exit;

		}

		public function settings_link( $links, $plugin_file_name ) {
			if( false !== strpos( $plugin_file_name, basename(__FILE__) ) ) {
				array_unshift(
					$links,
					sprintf(
						'<a href="https://rudrastyh.com/plugins/wordpress-media-library-folders">%s</a>',
						esc_html__( 'Upgrade to Pro', 'rudrastyh-simple-media-library-folders' )
					),
					sprintf(
						'<a href="%s">%s</a>',
						esc_attr( add_query_arg(
							array(
								'page' => self::SETTINGS_PAGE,
							),
							'options-general.php'
						) ),
						esc_html__( 'Settings','rudrastyh-simple-media-library-folders' )
					)
				);
			}
			return $links;

		}

		public function init() {
			
			$show_ui = ( 'yes' === get_option( '_smlf_show_in_admin_menu' ) ) ? true : false;

			register_taxonomy(
				self::TAXONOMY_NAME,
				array( 'attachment' ),
				array(
					'hierarchical'      => true,
					'labels'            => array(
						'name'              => _x( 'Folders', 'taxonomy general name', 'rudrastyh-simple-media-library-folders' ),
						'singular_name'     => _x( 'Folder', 'taxonomy singular name', 'rudrastyh-simple-media-library-folders' ),
						'search_items'      => __( 'Search Folders', 'rudrastyh-simple-media-library-folders' ),
						'all_items'         => __( 'All Folders', 'rudrastyh-simple-media-library-folders' ),
						'parent_item'       => __( 'Parent Folder', 'rudrastyh-simple-media-library-folders' ),
						'parent_item_colon' => __( 'Parent Folder:', 'rudrastyh-simple-media-library-folders' ),
						'edit_item'         => __( 'Edit Folder', 'rudrastyh-simple-media-library-folders' ),
						'update_item'       => __( 'Update Folder', 'rudrastyh-simple-media-library-folders' ),
						'add_new_item'      => __( 'Add New Folder', 'rudrastyh-simple-media-library-folders' ),
						'new_item_name'     => __( 'New Folder Name', 'rudrastyh-simple-media-library-folders' ),
						'menu_name'         => __( 'Folders', 'rudrastyh-simple-media-library-folders' ),
					),
					'show_ui'           => $show_ui,
					'show_admin_column' => true,
					'show_in_nav_menus' => false,
					'query_var'         => true,
					//'rewrite' => array('slug' => self::TAXONOMY_NAME),
					'public' => true,
					'update_count_callback' => '_update_generic_term_count',
				)
			);

			add_filter( 'set_screen_option_smlf_show_folders_attachment', function( $status, $option, $value ){
				return 'on' === $value ? 'yes' : 'no';
			}, 10, 3 );

		}

		public function limit_taxonomy_depth( $args, $taxonomy ) {

			$screen = get_current_screen();
			if( $screen->id !== 'edit-' . self::TAXONOMY_NAME ) {
				return $args;
			}

			if( $taxonomy !== self::TAXONOMY_NAME ) {
				return $args;
			}

			// Get all term IDs in this taxonomy.
			$term_ids = get_terms( array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			) );

			if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
				return $args;
			}

			$exclude = array();

			foreach ( $term_ids as $term_id ) {
				// Level: root=1; child=2; etc.
				$level = 1 + count( get_ancestors( $term_id, $taxonomy, 'taxonomy' ) );

				// If a term is already at (or beyond) the max level,
				// don’t allow selecting it as a parent (would create level $max_levels+1).
				if ( $level >= 5 ) {
					$exclude[] = (int) $term_id;
				}
			}

			if ( $exclude ) {
				$existing          = isset( $args[ 'exclude' ] ) ? (array) $args[ 'exclude' ] : array();
				$args[ 'exclude' ]   = array_unique( array_merge( $existing, $exclude ) );
			}

			return $args;

		}


		public function uploader_select_folder() {

			if( ! taxonomy_exists( self::TAXONOMY_NAME ) ) {
				return;
			}

			$dropdown_args = array(
				'hide_empty'       => 0,
				'hide_if_empty'    => false,
				'taxonomy'         => self::TAXONOMY_NAME,
				'name'             => 'folder_id',
				'id' => 'smlf_folder_id',
				//'class' => 'dsfsdfdsfsdfds',
				'orderby'          => 'name',
				'hierarchical'     => true,
				'show_option_none' => __( 'Choose folder', 'rudrastyh-simple-media-library-folders' ),
			);
			?><div style="margin-bottom: 10px"><?php
				wp_dropdown_categories( $dropdown_args );
			?></div><?php

		}

		public function upload_to_folder( $attachment_id ) {
//file_put_contents( __DIR__ . '/log.txt', print_r( $_POST, true ) );
			if( ! isset( $_POST[ '_wpnonce' ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ '_wpnonce' ] ) ), 'media-form' ) ) {
				return;
			}

			$folder_id = ! empty( $_POST[ 'folder_id' ] ) ? absint( $_POST[ 'folder_id' ] ) : 0;
			if( $folder_id ) {
				wp_set_object_terms( $attachment_id, $folder_id, self::TAXONOMY_NAME );
			}
		}

		public function edit_attachment_fields( $form_fields, $post ) {
			
			$folders_taxonomy_name = $this->get_folders_taxonomy_name();
			$folders_taxonomy_object  = get_taxonomy( $folders_taxonomy_name );
    
			if( ! $folders_taxonomy_object ) {
				return $form_fields;
			}

			// first of all, unset the standard selection
			if( isset( $form_fields[ $folders_taxonomy_name ] ) ) {
				unset( $form_fields[ $folders_taxonomy_name ] );
			}

			// use it only on upload.php
			if( isset( $_SERVER[ 'HTTP_REFERER' ] ) ) {
        		$referer_path = wp_parse_url( $_SERVER[ 'HTTP_REFERER' ], PHP_URL_PATH );
				if( $referer_path && false === strpos( $referer_path, 'upload.php' ) ) {
					return $form_fields;
				}
			}

			ob_start();
			?>
				<div class="smlf-modal-folder-selection">
					<div class="tabs-panel">
						<ul id="folders_checklist" data-wp-lists="list:<?php echo esc_attr( $folders_taxonomy_name ); ?>" class="categorychecklist form-no-clear">
							<?php
								$selected_ids = wp_get_object_terms( $post->ID, $folders_taxonomy_name, array( 'fields' => 'ids' ) );
								if( is_wp_error( $selected_ids ) ) {
									$selected_ids = array();
								}

								wp_terms_checklist( 
									$post->ID, 
									array(
										'taxonomy'      => $folders_taxonomy_name,
										'selected_cats' => $selected_ids,
										'popular_cats'  => false,
										'walker' => new SMLF_Term_List_Walker(),
										'checked_ontop' => false,
									) 
								);
							?>
						</ul>
					</div>

					<div>
						<a href="#smlf_quick_add_folder" class="smlf-modal-folder-selection__add">
							<?php printf( __( '+ Add New %s' ), $folders_taxonomy_object->labels->singular_name ); ?>
						</a>
						<div id="smlf_quick_add_folder" class="smlf-modal-folder-selection__adder">
							<input type="text" name="newfolder" id="newfolder" value="" placeholder="<?php esc_attr_e( 'New Folder Name', 'rudrastyh-simple-media-library-folders' ) ?>" />
							<?php
								echo $this->get_dropdown_folders_field( array( 
									'taxonomy' => $folders_taxonomy_name, 
									'name' => 'newfolder_parent',
									'show_option_none' => '— ' . $folders_taxonomy_object->labels->parent_item . ' —' 
								) );
							?>
							<button type="button" class="button button-small smlf-quick-add-folder">
								<?php printf( __( 'Add %s' ), $folders_taxonomy_object->labels->singular_name ); ?>
							</button>
						</div>
					</div>

				</div>
			<?php
		
			$html = ob_get_clean();

			$form_fields[ $folders_taxonomy_name ] = array(
				'label' => $folders_taxonomy_object->labels->name,
				'show_in_edit' => false,
				'input' => 'html',
				'html'  => $html,
			);

			return $form_fields;

		}

		public function save_attachment_fields( $post ) {
			$folders_taxonomy_name = $this->get_folders_taxonomy_name();

			if( ! taxonomy_exists( $folders_taxonomy_name ) ) {
				return $post;
			}

			$id = $post[ 'ID' ];
//file_put_contents( __DIR__ . '/log.txt', print_r( $_REQUEST[ 'smlf_folders_input' ], true ) );
			if( isset( $_POST[ 'smlf_folders_input' ] ) ) {

				$folder_slugs = array_map( 'sanitize_text_field', (array) $_POST[ 'smlf_folders_input' ] );
				wp_set_object_terms( $id, $folder_slugs, $folders_taxonomy_name, false );

			} else {

				if( isset( $_POST[ 'action' ] ) && 'save-attachment-compat' === $_POST[ 'action' ] ) {
					wp_set_object_terms( $id, array(), $folders_taxonomy_name, false );
				}
			}

			return $post;
		
    	}

		public function list_view_filter() {
			global $typenow;

			if( 'attachment' !== $typenow ) {
				return;
			}

			if( 'yes' === get_option( '_smlf_show_in_list_view' ) ) {
				return;
			}

			$selected = isset( $_GET[ self::TAXONOMY_NAME ] ) ? $_GET[ self::TAXONOMY_NAME ] : false;
			wp_dropdown_categories(
				array(
					'show_option_all' =>  __( 'All folders', 'rudrastyh-simple-media-library-folders' ),
					'taxonomy'        =>  self::TAXONOMY_NAME,
					'name'            =>  self::TAXONOMY_NAME,
					'orderby'					=>  'name',
					'selected'        =>  $selected,
					'hierarchical'    =>  true,
					'value_field'     => 'slug',
					'depth'           =>  5,
					'hide_empty'      =>  true,
				)
			);

		}

		public function filter_query( $query ) {
			if( isset( $_REQUEST[ 'query' ][ self::TAXONOMY_NAME ] ) ) {
				if( 'all' === $_REQUEST[ 'query' ][ self::TAXONOMY_NAME ] ) {
					unset( $query[ self::TAXONOMY_NAME ] );
				} elseif( ! $_REQUEST[ 'query' ][ self::TAXONOMY_NAME ] ) { // if set to 0
					unset( $query[ self::TAXONOMY_NAME ] );
					$query[ 'tax_query' ] = array(
						array(
							'taxonomy' => self::TAXONOMY_NAME,
							'operator' => 'NOT EXISTS'
						),
					);
				}
			}
			return $query;

		}

		public function show_sb() {

			if( ! function_exists( 'get_current_screen' ) ) {
				return;
			}

			global $mode;

			$show_count = get_option( '_smlf_show_count', 'both' );

			$screen = get_current_screen();

			if( ! isset( $screen->id ) || 'upload' !== $screen->id ) {
				return;
			}
			//print_r( $screen );exit;
			// display only for the "Grid" mode
			if( 'grid' !== $mode ) {
				return;
			}

			$taxonomy_name = str_starts_with( $screen->id, 'edit-' ) ? self::TAXONOMY_NAME . '_' . $screen->post_type : self::TAXONOMY_NAME;
			$sidebar_width = ( $sidebar_width = get_user_meta( get_current_user_id(), '_smlf_sb_width', true ) ) ? (int) $sidebar_width : 320;
			$folder_actions_class = ( 'grid' === $mode || ! $this->is_folder_selected( $taxonomy_name ) ) ? ' smlf-disabled' : '';

			?>
			<div id="smlf-sidebar" class="smlf-sidebar" data-show_count="<?php echo esc_attr( $show_count ) ?>" data-taxonomy="<?php echo esc_html( $taxonomy_name ) ?>" style="width:<?php echo absint( $sidebar_width ) ?>px">

				<h1 class="wp-heading-inline"><?php esc_html_e( 'Folders', 'rudrastyh-simple-media-library-folders' ) ?></h1>
					<a href="" class="button smlf-add-new-folder" role="button" aria-expanded="false"><?php esc_html_e( 'Add Folder', 'rudrastyh-simple-media-library-folders' ) ?></a>
					<div class="smlf-sidebar__toolbar">
						<div class="smlf-folder-actions">
							<a href="" class="smlf-edit-folder<?php echo esc_attr( $folder_actions_class ) ?>"><span class="screen-reader-text"><?php esc_html_e( 'Edit folder', 'rudrastyh-simple-media-library-folders' ) ?></span></a>
							<a href="" class="smlf-delete-folder<?php echo esc_attr( $folder_actions_class ) ?>"><span class="screen-reader-text"><?php esc_html_e( 'Delete folder', 'rudrastyh-simple-media-library-folders' ) ?></span></a>
						</div>

						<select id="smlf-sort-folders">
							<option value=""><?php esc_html_e( 'Sort by', 'rudrastyh-simple-media-library-folders' ) ?></option>
							<option value="a-z">A &rarr; Z</option>
							<option value="count"><?php esc_html_e( 'Media count', 'rudrastyh-simple-media-library-folders' ) ?></option>
						</select>
					</div>

					<?php $this->print_folders( $taxonomy_name, $mode ) ?>

			</div>
			<div id="smlf-handler"></div>

			<div class="smlf-modal" id="add-edit-folder" style="display:none;">

					<div class="smlf-modal__topbar">
						<h1><?php esc_html_e( 'Edit Folder', 'rudrastyh-simple-media-library-folders' ) ?></h1>
						<button class="smlf-modal__close dashicons dashicons-no-alt">
							<span class="screen-reader-text"><?php esc_html_e( 'Close modal panel', 'rudrastyh-simple-media-library-folders' ) ?></span>
						</button>
					</div>
					<form action="" id="add-edit-folder-form" method="post">
					<div class="smlf-modal__body">

							<label for="folder_name"><?php esc_html_e( 'Name', 'rudrastyh-simple-media-library-folders' ) ?></label>
							<fieldset>
								<input class="input-text regular-input" type="text" name="folder_name" id="folder_name" required />
							</fieldset>
							<label for="folder_parent"><?php esc_html_e( 'Parent Folder', 'rudrastyh-simple-media-library-folders' ) ?></label>
							<fieldset>
								<?php
									echo $this->get_dropdown_folders_field( array( 'taxonomy' => $taxonomy_name ) );
								?>
							</fieldset>
							<label for="folder_restrict">(<a href="https://rudrastyh.com/plugins/wordpress-media-library-folders">Pro</a>) <?php esc_html_e( 'Restrict Access', 'rudrastyh-simple-media-library-folders' ) ?></label>
							<fieldset>
								<select name="folder_restrict" id="folder_restrict" disabled>
									<option value="" selected><?php esc_html_e( 'No restrictions', 'rudrastyh-simple-media-library-folders' ) ?></option>
									<option value="roles"><?php esc_html_e( 'By user roles', 'rudrastyh-simple-media-library-folders' ) ?></option>
									<option value="usernames"><?php esc_html_e( 'By usernames', 'rudrastyh-simple-media-library-folders' ) ?></option>
								</select>
							</fieldset>

					</div>
					<div class="smlf-modal__bottombar">
						<input type="hidden" name="folder_taxonomy" value="<?php echo esc_attr( $taxonomy_name ) ?>" />
						<input type="hidden" name="folder_id" value="0" />
						<button id="smlf-ok" class="button button-primary button-large"><?php _e( 'Save' ) ?></button>
					</div>
				</form>
			</div>
			<div class="smlf-modal-backdrop" style="display:none"></div>

			<?php

		}

		private function get_dropdown_folders_field( $args ) {

			$defaults = array(
        		'show_option_none' => __( 'None' ),
				'name' => 'folder_parent',
				'hierarchical' => true,
				'value_field' => 'id',
				'depth' => 4,
				'echo' => false,
				'hide_empty' => false,
			);

			$args = wp_parse_args( $args, $defaults );

			return wp_dropdown_categories( $args );
		}

		private function print_folders( $taxonomy_name, $mode = 'grid' ) {

			$tag = 'grid' === $mode ? 'span' : 'a';
			$show_count = get_option( '_smlf_show_count', 'both' );
			$w_count_class = 'no' !== $show_count ? 'folder-nav--with-count' : '';
			// All {items} text
			if( self::TAXONOMY_NAME !== $taxonomy_name ) {
				$post_type_name = str_replace( self::TAXONOMY_NAME . '_', '', $taxonomy_name );
				$post_type_object = get_post_type_object( $post_type_name );
				$all_items_text = sprintf(
					'All %s',
					mb_strtolower( $post_type_object->labels->name )
				);
			} else {
				$post_type_name = 'attachment';
				$all_items_text = __( 'All files', 'rudrastyh-simple-media-library-folders' );
			}
			?>
			<div class="smlf-sidebar__navigation">
				<ul class="folders-toplevel folders-toplevel--main-nav">
					<?php if( 'grid' === $mode ) : ?>
						<li><span class="folder-nav folder-nav--all_files current dashicons-before"><?php echo esc_html( $all_items_text ) ?> <?php if( 'no' !== $show_count ) : ?><span class="folder-nav__count"><?php echo absint( $this->get_media_count( $post_type_name ) ) ?></span><?php endif; ?></span></li>
						<li><span class="folder-nav dashicons-before" data-id="0"><?php esc_html_e( 'Uncategorized', 'rudrastyh-simple-media-library-folders' ) ?> <?php if( 'no' !== $show_count ) : ?><span class="folder-nav__count"><?php echo absint( $this->get_media_without_folders_count( $post_type_name ) ) ?></span><?php endif; ?></span></li>
					<?php else : ?>
						<li><?php
							printf(
								'<a href="%s" class="folder-nav folder-nav--all_files %s dashicons-before">%s %s</a></li>',
								esc_attr( add_query_arg( array( 'taxonomy' => $taxonomy_name, 'term' => 'all' ) ) ),
								( $this->url_params_not_set( $taxonomy_name ) || $this->is_current_folder( 'all', $taxonomy_name ) ? 'current' : '' ),
								esc_html( $all_items_text ),
								( 'no' !== $show_count ? '<span class="folder-nav__count">' . absint( $this->get_media_count( $post_type_name ) ) . '</span>' : '' )
							)
						?></li>
						<li><?php
							printf(
								'<a href="%s" class="folder-nav %s dashicons-before" data-id="0">%s %s</a></li>',
								esc_attr( add_query_arg( array( 'taxonomy' => $taxonomy_name, 'term' => 'uncategorized' ) ) ),
								( $this->is_current_folder( 'uncategorized', $taxonomy_name ) ? 'current' : '' ),
								esc_html__( 'Uncategorized', 'rudrastyh-simple-media-library-folders' ),
								( 'no' !== $show_count ? '<span class="folder-nav__count">' . absint( $this->get_media_without_folders_count( $post_type_name ) ) . '</span>' : '' )
							)
						?></li>
					<?php endif; ?>
				</ul>

				<?php
					$tree = array();

					$folders = get_terms(
						array(
							'taxonomy' => $taxonomy_name,
							'hide_empty' => false,
							'orderby' => 'term_group',
							'order' => 'ASC',
						)
					);

					$args = array( 'tag' => $tag, 'mode' => $mode, 'w_count_class' => $w_count_class, 'show_count' => $show_count );

				?>

				
				<?php
					
					$this->print_folder_search();

					?><ul class="folders-toplevel folders-ul<?php echo $this->has_subfolders( $folders ) ? ' folders-ul--tree' : '' ?>"><?php
					
					$this->print_add_folder_form();
					
					if( $folders ) :
						foreach( $folders as $term ) :
							$tree = $this->prepare_folder_tree( $term, $tree, $args );
						endforeach;
					$this->print_folder_tree( $tree, $args );
					endif;
					?></ul><?php
					
				?>

				
			</div><?php

		}

		private function print_network_folders( $mode = 'grid' ) {

			if( ! class_exists( 'Rudr_Multisite_Shared_Media' ) ) {
				return;
			}

			$network_library_id = Rudr_Multisite_Shared_Media::get_network_library_id();

			switch_to_blog( $network_library_id );

			$this->print_folders( self::TAXONOMY_NAME, $mode );

			restore_current_blog();

		}

		private function print_folder_search() {

			if( 'yes' !== get_option( '_smlf_show_search', 'yes' ) ) {
				return;
			}

			?><div class="smlf-search"><input type="search" id="folder-search-input" class="search" placeholder="<?php esc_attr_e( 'Search folders', 'rudrastyh-simple-media-library-folders' ) ?>&hellip;" name="smlf_s" autocomplete="off"></div><?php
		}

		private function print_add_folder_form() {
			?><li id="folder-add-wrap">
				<form id="folder-add" class="folder-add dashicons-before">
					<input type="text" name="folder_name" placeholder="<?php esc_attr_e( 'New Folder Name', 'rudrastyh-simple-media-library-folders' ) ?>" />
					<input type="hidden" name="folder_parent" value="-1" />
					<span class="folder-add__buttons">
						<button type="button" class="button smlf-close-quick-add-form"><?php _e( 'Cancel' ) ?></button>
						<button type="submit" class="button-primary"><?php _e( 'Save' ) ?></button>
					</span>
				</form>
			</li><?php
		}

		private function prepare_folder_tree( $folder, $tree, $args ) {

			$href = 'a' === $args[ 'tag' ] ? ' href="' . esc_attr( add_query_arg( array( 'taxonomy' => $folder->taxonomy, 'term' => $folder->slug ) ) ) . '"' : '';
			$current_class = ( 'grid' !== $args[ 'mode' ] && $this->is_current_folder( $folder ) ) ? ' current' : '';

			$tree[ $folder->term_id ] = array(
				'id' => $folder->term_id,
				'parent' => $folder->parent,
				'slug' => $folder->slug,
				'count' => $folder->count,
				'is_open' => false,
				'has_children' => false,
				'taxonomy' => $folder->taxonomy,
			);

			$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10.8622 8.04053L14.2805 12.0286L10.8622 16.0167L9.72327 15.0405L12.3049 12.0286L9.72327 9.01672L10.8622 8.04053Z"></path></svg>';

			$html = '<li data-count="%d">';
			$html .= '<' . $args[ 'tag' ] . $href . ' class="folder-nav dashicons-before ' . $args[ 'w_count_class' ] . $current_class . '%s" data-id="' . absint( $folder->term_id ) . '">';
			$html .= '<span class="folder-nav__open-close">' . $svg . '</span>';
			$html .= '<span class="folder-nav__name">' . esc_html( $folder->name ) . '</span>';
			if( 'no' !== $args[ 'show_count' ] ) {
				$html .= '<span class="folder-nav__count">%d</span>';
			}
			$html .= "</{$args[ 'tag' ]}>";

			$tree[ $folder->term_id ][ 'html' ] = $html;

			return $tree;

		}

		private function has_subfolders( $folders ) {

			$has_subfolders = false;
			if( $folders ) {
				foreach( $folders as $term ) {
					if( $term->parent ) {
						$has_subfolders = true;
						break;
					}
				}
			}
			return $has_subfolders;

		}

		private function prepare_folder_classes( $folder ) {

			$classes = array();
			if( isset( $folder[ 'is_open' ] ) && $folder[ 'is_open' ] ) {
				$classes[] = 'opened';
			}

			if( isset( $folder[ 'has_children' ] ) && $folder[ 'has_children' ] ) {
				$classes[] = 'folder-nav--has-children';
			}

			return ! empty( $classes ) ? ' ' . join( ' ', $classes ) : '';

		}

		private function print_folder_tree( $tree, $args ) {
//echo '<pre>';print_r( $tree );exit;
			// 1. recalculate counts
			foreach( $tree as $folder_id => $folder ) {
				// only level 1 first
				if( $folder[ 'parent' ] ) {
					continue;
				}

				$tree = $this->update_level_counts_recursive( $folder_id, $tree, $args );
			}

			// print tree
			foreach( $tree as $folder_id => $folder ) {
				if( $folder[ 'parent' ] ) {
					continue;
				}

				$this->render_level_recursive( $folder_id, $tree );

			}

		}

		public function update_level_counts_recursive( $folder_id, $tree, $args ) {

			foreach( $tree as $child_folder_id => $child_folder ) {

				if( $folder_id !== $child_folder[ 'parent' ] ) {
					continue;
				}

				// current folder has children.
				$tree[ $folder_id ][ 'has_children' ] = true;

				// first process child, so its counts already include deeper levels.
				$tree = $this->update_level_counts_recursive( $child_folder_id, $tree, $args );

				// add child count to current folder.
				if( 'both' === $args[ 'show_count' ] ) {
					$tree[ $folder_id ][ 'count' ] += $tree[ $child_folder_id ][ 'count' ];
				}

				// open current folder if child is current/default or already open.
				if(
					$tree[ $child_folder_id ][ 'is_open' ]
					|| (
						'grid' !== $args[ 'mode' ]
						&& $this->is_current_folder( $tree[ $child_folder_id ][ 'slug' ], $tree[ $child_folder_id ][ 'taxonomy' ] )
					)
				) {
					$tree[ $folder_id ][ 'is_open' ] = true;
				}
			}

			return $tree;
		}

		public function render_level_recursive( $folder_id, $tree ) {

			$folder = $tree[ $folder_id ];

			
			printf(
				$folder[ 'html' ],
				absint( $folder[ 'count' ] ),
				esc_attr( $this->prepare_folder_classes( $folder ) ),
				absint( $folder[ 'count' ] )
			);

			$child_tree = array_filter( $tree, function( $c ) use ( $folder_id ) {
				return $folder_id === $c[ 'parent' ];
			} );

			if( $child_tree ) {
				echo '<ul class="folders-children folders-ul">';

				foreach( $child_tree as $child_folder_id => $child_folder ) {
					$this->render_level_recursive( $child_folder_id, $tree );
				}

				echo '</ul>';
			}

			echo '</li>';
		}

		public function save_sb_width() {

			check_ajax_referer( 'sb_width' );

			$sb_width = ! empty( $_POST[ 'width' ] ) ? absint( $_POST[ 'width' ] ) : 320;
			update_user_meta( get_current_user_id(), '_smlf_sb_width', $sb_width );
			die;
		}

		public function add_to_folder() {

			check_ajax_referer( 'add_to_folder' );

			$folder_id = (int) $_POST[ 'folder_id' ];
			$attachment_ids = array_map( 'absint', $_POST[ 'attachment_ids' ] );

			$show_counts = get_option( '_smlf_show_count', 'both' );

			foreach( $attachment_ids as $attachment_id ) {
				wp_set_object_terms( $attachment_id, $folder_id, self::TAXONOMY_NAME, false );
			}

			// COUNTS
			$counts = array();
			
			// skip unnecessary calculations
			if( 'no' !== $show_counts ) { 
				$counts = $this->get_all_counts( self::TAXONOMY_NAME, $show_counts );
			}

			wp_send_json_success( array( 'counts' => array_values( $counts ) ) );

		}

		public function recalculate_counts() {

			check_ajax_referer( 'add_to_folder' );

			// attachments only at this point
			$folders_taxonomy_name = $this->get_folders_taxonomy_name();
			$show_counts = get_option( '_smlf_show_count', 'both' );

			$counts = $this->get_all_counts( $folders_taxonomy_name, $show_counts );

			wp_send_json_success( array( 'counts' => array_values( $counts ) ) );

		}

		// get a JSON array of counts for each folder
		private function get_all_counts( $folders_taxonomy_name, $show_counts ){
			
			$counts = array();

			$folders_taxonomy_base = $this->get_folders_taxonomy_name();
			if( $folders_taxonomy_base !== $folders_taxonomy_name ) {
				$post_type_name = str_replace( $folders_taxonomy_base . '_', '', $folders_taxonomy_name );
			} else { 
				$post_type_name = 'attachment';
			}

			// uncategorized
			$counts[0] = array(
				'id' => 0,
				'count' => $this->get_media_without_folders_count( $post_type_name ),
			);

			$folders = get_terms(
				array(
					'taxonomy' => $folders_taxonomy_name,
					'hide_empty' => false,
					'orderby' => 'name',
					'order' => 'ASC',
				)
			);
			// an array of child folders for every parent Array( 2 => Array( 3, 4 ) )
			$child_folders_map = array();

			if( $folders ) {

				foreach( $folders as $term ) {

					$counts[ $term->term_id ] = array(
						'id'    => $term->term_id,
						'count' => $term->count,
					);

					$parent_id = (int) $term->parent;
					// not a child folder, do not need to map it
					if( ! $parent_id ) {
						continue;
					}

					// in case it is a first item in an array
					if( ! isset( $child_folders_map[ $parent_id ] ) ) {
						$child_folders_map[ $parent_id ] = array();
					}

					$child_folders_map[ $parent_id ][] = $term->term_id;
				}

				foreach( $folders as $term ) {
					if( $term->parent ) {
						continue;
					}
					$this->calculate_folder_counts_recursive( $term->term_id, $counts, $child_folders_map, $show_counts );
				}
			}

			return $counts;
		}

		// recalculate counts recursively
		public function calculate_folder_counts_recursive( $term_id, &$counts, $child_folders_map, $show_counts ) {
			// this term does not have any child folder, return its original counts
			if( empty( $child_folders_map[ $term_id ] ) || 'both' !== $show_counts ) {
				return $counts[ $term_id ][ 'count' ];
			}
			// otherwise loop through all the children
			foreach( $child_folders_map[ $term_id ] as $child_term_id ) {
				$counts[ $term_id ][ 'count' ] += $this->calculate_folder_counts_recursive( $child_term_id, $counts, $child_folders_map, $show_counts );
			}

			return $counts[ $term_id ][ 'count' ];
		}


		public function delete_folder() {

			check_ajax_referer( 'del_folder' );

			$folder_id = (int) $_POST[ 'folder_id' ];

			// handle children first
			$child_folders = get_term_children( $folder_id, self::TAXONOMY_NAME );
			if( ! empty( $child_folders ) ) {
				foreach ( $child_folders as $child_folder ) {
					wp_delete_term( $child_folder, self::TAXONOMY_NAME );
				}
			}
			// delete target folder now
			$deleted = wp_delete_term( $folder_id, self::TAXONOMY_NAME );

			if( ! is_wp_error( $deleted ) ) {
				wp_send_json_success(
					array(
						'folder_parent_selection' => $this->get_dropdown_folders_field( array( 'taxonomy' => self::TAXONOMY_NAME ) ),
					)
				);
			} else {
				wp_send_json_error( $deleted );
			}
			die;

		}

		public function add_edit_folder() {
			check_ajax_referer( 'del_folder' );

			$folder_id = (int) $_POST[ 'folder_id' ];
			$folder_name = sanitize_text_field( wp_unslash( $_POST[ 'folder_name' ] ) );
			$folder_taxonomy = self::TAXONOMY_NAME;
			$folder_parent_id = (int) $_POST[ 'folder_parent' ];
			$show_count = get_option( '_smlf_show_count', 'both' );

			if( ! $folder_id ) {
				// create a new folder
				$folder_term_taxonomy = wp_insert_term(
					$folder_name,
					$folder_taxonomy,
					array( 'parent' => $folder_parent_id )
				);

				if( ! is_wp_error( $folder_term_taxonomy ) ) {
					$folder_id = $folder_term_taxonomy[ 'term_id' ];
				}
			} else {
				// update folder
				$folder_term_taxonomy = wp_update_term(
					$folder_id,
					$folder_taxonomy,
					array( 'name' => $folder_name, 'parent' => $folder_parent_id )
				);
			}

			$folder_slug = get_term_field( 'slug', $folder_id, $folder_taxonomy );

			clean_term_cache( $folder_id, $folder_taxonomy );

			if( ! is_wp_error( $folder_term_taxonomy ) ) {
				wp_send_json_success(
					array(
						'folder_id' => $folder_id,
						'folder_name' => esc_html( $folder_name ),
						'folder_slug' => $folder_slug,
						'folder_parent_id' => $folder_parent_id,
						'folder_html' => sprintf(
							'<li data-count="0" style=""><span class="folder-nav dashicons-before %s ui-droppable" data-id="%d" data-restrict="%s"><span class="folder-nav__open-close"><svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10.8622 8.04053L14.2805 12.0286L10.8622 16.0167L9.72327 15.0405L12.3049 12.0286L9.72327 9.01672L10.8622 8.04053Z"></path></svg></span><span class="folder-nav__name">%s</span>%s</span></li>',
							( in_array( $show_count, array( 'both', 'per-folder') ) ? 'folder-nav--with-count' : '' ),
							$folder_id,
							'',
							$folder_name,
							( in_array( $show_count, array( 'both', 'per-folder') ) ? '<span class="folder-nav__count">0</span>' : '' )
						),
						'quick_add_html' => sprintf(
							'<li id="%s-%d"><label class="selectit"><input value="%s" type="checkbox" name="%s" id="%s" checked /> %s</label>',
							$folder_taxonomy,
							$folder_id,
							$folder_slug,
							"smlf_folders_input[{$folder_slug}]",
							"in-{$folder_taxonomy}-{$folder_id}",
							esc_html( $folder_name )
						),
						'folder_parent_selection' => $this->get_dropdown_folders_field( array( 'taxonomy' => $folder_taxonomy ) ),
					)
				);
			} else {
				wp_send_json_error( $folder_term_taxonomy );
			}
		}

		public function screen_options( $settings, $screen ) {

			global $mode;

			if( 'upload' !== $screen->id ) {
				return $settings;
			}

			$option = "smlf_show_folders_attachment";
			$value = get_user_meta( get_current_user_id(), $option, true );
			$value = $value ? $value : 'yes';

			$settings .= sprintf(
				'<fieldset class="metabox-prefs">
					<legend>%1$s</legend>
					<label><input type="checkbox" id="smlf_show_sb" name="wp_screen_options[value]" %2$s />%3$s</label>
					<input type="hidden" name="wp_screen_options[option]" value="%4$s" />
				</fieldset>',
				esc_html__( 'Folders', 'rudrastyh-simple-media-library-folders' ),
				checked( $value, 'yes', false ),
				esc_html__( 'Show folders', 'rudrastyh-simple-media-library-folders' ),
				$option
			);

			if( 'grid' === $mode ) {
				$settings .= '<p class="submit"><input type="submit" name="screen-options-apply" id="screen-options-apply" class="button button-primary" value="' . esc_html__( 'Apply' ) . '"></p>';
			}

			return $settings;

		}

		public function sort_folders() {

			global $wpdb;

			check_ajax_referer( 'del_folder' );

			if( ! current_user_can( 'edit_folders' ) ) {
				wp_send_json_error( array( 'message' => __( 'You need a higher level of permission.' ) ) );
			}

			$folders_order = isset( $_POST[ 'folders_order' ] ) ? $_POST[ 'folders_order' ] : array();
			
			// the folder we are currently moving
			$folder_id = isset( $_POST[ 'folder_id' ] ) ? absint( $_POST[ 'folder_id' ] ) : 0;
			$folder_parent = isset( $_POST[ 'folder_parent' ] ) && $_POST[ 'folder_parent' ] > 0 ? absint( $_POST[ 'folder_parent' ] ) : 0;

			if( $folder_id ) {
				wp_update_term( $folder_id, self::TAXONOMY_NAME, array( 'parent' => $folder_parent ) );
			}

			if ( ! is_array( $folders_order ) || empty( $folders_order ) ) {
				wp_send_json_error( array( 'message' => 'An error while sorting folders.' ) );
			}

			$sql_cases = array();
			$term_ids = array();

			foreach( $folders_order as $folder ) {
				$id = absint( $folder[ 'term_id' ] ?? 0 );
				$order   = absint( $folder[ 'order' ] ?? 0 );

				if( ! $id ) {
					continue;
				}

				$cases[] = $wpdb->prepare( 'WHEN %d THEN %d', $id, $order );
				$ids[] = $id;
			}

			if( $ids ) {
				$wpdb->query(
					"
					UPDATE {$wpdb->terms}
					SET term_group = CASE term_id " . join( ' ', $cases ) . "
					END
					WHERE term_id IN (" . join( ', ', $ids ) . ")"
				);

				clean_term_cache( $ids, self::TAXONOMY_NAME );
			}

			// prepare response
			$show_counts = get_option( '_smlf_show_count', 'both' );

			$counts = $this->get_all_counts( self::TAXONOMY_NAME, $show_counts );

			wp_send_json_success( 
				array( 
					'counts' => array_values( $counts ),
					'folder_parent_selection' => $this->get_dropdown_folders_field( array( 'taxonomy' => self::TAXONOMY_NAME ) ),
				) 
			);

		}

		/**********************/
		/*    import tools    */
		/**********************/
		private function get_folder_count_to_import( $plugin_name ) {

			global $wpdb;

			$count = 0;
			$plugins = self::PLUGINS_FOR_IMPORT;

			switch( $plugin_name ) {
				case 'wp-media-folder' :
				case 'wickedfolders' :
				case 'happyfiles' :
				case 'premio' : {

					$number_of_folders = $wpdb->get_var(
						$wpdb->prepare(
							"
							SELECT COUNT(1)
							FROM {$wpdb->term_taxonomy}
							WHERE taxonomy = %s
							",
							$plugins[ $plugin_name ][ 'taxonomy' ]
						)
					);
					// we need to remove the main category for 'wp-media-folder'
					if( $number_of_folders && 'wp-media-folder' === $plugin_name ) {
						$number_of_folders--;
					}
					$count = $number_of_folders;

					break;
				}
				case 'filebird' : {
					if( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}fbv'" ) ) {
						$count = $wpdb->get_var(
							"
							SELECT COUNT(1)
							FROM {$wpdb->prefix}fbv
							",
						);
					}
					break;
				}
				case 'real-media-library' : {
					if( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}realmedialibrary'" ) ) {
						$count = $wpdb->get_var(
							"
							SELECT COUNT(1)
							FROM {$wpdb->prefix}realmedialibrary
							",
						);
					}
					break;
				}
				default:{
					break;
				}
			}

			return $count;

		}

		private function get_media_count_to_import( $plugin_name ) {

			global $wpdb;

			$count = 0;
			$plugins = self::PLUGINS_FOR_IMPORT;

			switch( $plugin_name ) {
				case 'wp-media-folder' :
				case 'wickedfolders' :
				case 'happyfiles' :
				case 'premio' : {

					$number_of_items = $wpdb->get_var(
						$wpdb->prepare(
							"
							SELECT COUNT(DISTINCT p.ID)
							FROM {$wpdb->posts} AS p
							INNER JOIN {$wpdb->term_relationships} AS tr ON p.ID = tr.object_id
							INNER JOIN {$wpdb->term_taxonomy} AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
							WHERE tt.taxonomy = %s
							AND p.post_type = 'attachment'
							",
							$plugins[ $plugin_name ][ 'taxonomy' ]
						)
					);
					// we need to remove the main category from here
					$count = $number_of_items;

					break;
				}
				case 'filebird' : {
					$count = $wpdb->get_var(
						"
						SELECT COUNT(1)
						FROM {$wpdb->prefix}fbv_attachment_folder
						"
					);

					break;
				}
				case 'real-media-library' : {
					$count = $wpdb->get_var(
						"
						SELECT COUNT(1)
						FROM {$wpdb->prefix}realmedialibrary_posts
						"
					);

					break;
				}
				default:{
					break;
				}
			}

			return $count;

		}

		private function is_import_available() {
			$is_available = false;

			$plugin_slugs = array_keys( self::PLUGINS_FOR_IMPORT );

			foreach( $plugin_slugs as $plugin_slug ) {
				if( $this->get_folder_count_to_import( $plugin_slug ) ) {
					$is_available = true;
					break;
				}
			}

			return $is_available;
		}

		public function run_import() {

			$plugin_slug = ! empty( $_GET[ 'plugin_slug' ] ) ? $_GET[ 'plugin_slug' ] : '';

			check_admin_referer( "mlf-import-{$plugin_slug}");

			// create and map folders
			$mapped_folders = $this->import_folders( $plugin_slug );
			$mapped_folders_count = count( $mapped_folders );
			// add media to folders
			$categorized_media_count = $this->categorize_media( $plugin_slug, $mapped_folders );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => self::SETTINGS_PAGE,
						'tab' => 'import',
						'message' => "mlf-imported--{$mapped_folders_count}-{$categorized_media_count}",
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}


		public function delete_plugin_data() {

			$plugin_slug = ! empty( $_GET[ 'plugin_slug' ] ) ? $_GET[ 'plugin_slug' ] : '';
			$plugins = self::PLUGINS_FOR_IMPORT;

			check_admin_referer( "mlf-delete-data-{$plugin_slug}" );

			global $wpdb;

			switch( $plugin_slug ) {
				case 'wp-media-folder' :
				case 'wickedfolders' :
				case 'happyfiles' :
				case 'premio' : {

					$taxonomy = $plugins[ $plugin_slug ][ 'taxonomy' ];

					// start by gettings term IDs
					$term_ids = $wpdb->get_col(
						$wpdb->prepare(
							"
							SELECT t.term_id
							FROM {$wpdb->terms} AS t
							INNER JOIN $wpdb->term_taxonomy AS tt
							ON t.term_id = tt.term_id
							WHERE tt.taxonomy = %s
							",
							$taxonomy
						)
					);

					// delete everything
					if ( ! empty( $term_ids ) ) {

						$term_ids = join( ',', $term_ids );
				    $wpdb->query(
						$wpdb->prepare(
							"
							DELETE FROM {$wpdb->term_relationships}
							WHERE term_taxonomy_id IN (
								SELECT term_taxonomy_id
								FROM {$wpdb->term_taxonomy}
								WHERE term_id IN ( %s )
							)",
							$term_ids
						)

				    );

						$wpdb->delete( $wpdb->term_taxonomy, array( 'taxonomy' => $taxonomy ) );

				    $wpdb->query(
				    	"
							DELETE FROM $wpdb->terms
				      WHERE term_id IN ( $term_ids )
							"
						);

					}

					break;
				}
				case 'filebird' : {
					$wpdb->query(
						"
						DROP TABLE IF EXISTS {$wpdb->prefix}fbv
						"
					);
					$wpdb->query(
						"
						DROP TABLE IF EXISTS {$wpdb->prefix}fbv_attachment_folder
						"
					);
					break;
				}
				case 'real-media-library' : {
					$wpdb->query(
						"
						DROP TABLE IF EXISTS {$wpdb->prefix}realmedialibrary
						"
					);
					$wpdb->query(
						"
						DROP TABLE IF EXISTS {$wpdb->prefix}realmedialibrary_meta
						"
					);
					$wpdb->query(
						"
						DROP TABLE IF EXISTS {$wpdb->prefix}realmedialibrary_posts
						"
					);
					$wpdb->query(
						"
						DROP TABLE IF EXISTS {$wpdb->prefix}realmedialibrary_tmp
						"
					);
					break;
				}
				default : {
					break;
				}

			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => self::SETTINGS_PAGE,
						'tab' => 'import',
						'message' => "mlf-removed--{$plugin_slug}",
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}

		public function import_folders( $plugin_slug ) {

			global $wpdb;

			// old folder id => new folder id
			$mapped_folders = array();
			$plugins = self::PLUGINS_FOR_IMPORT;

			switch( $plugin_slug ) {

				case 'wp-media-folder' :
				case 'wickedfolders' :
				case 'happyfiles' :
				case 'premio' : {
					// we gel all the folders from this specific taxonomy
					$terms = $wpdb->get_results(
						$wpdb->prepare(
							"
							SELECT t.term_id, t.name, t.slug, tt.parent
							FROM {$wpdb->terms} AS t
							INNER JOIN {$wpdb->term_taxonomy} AS tt
							ON t.term_id = tt.term_id
							WHERE tt.taxonomy = %s
							",
							$plugins[ $plugin_slug ][ 'taxonomy' ]
						),
						ARRAY_A
					);

					if( ! $terms ) {
						return $mapped_folders;
					}

					// we need to support child items
					while( ! empty( $terms ) ) {
						foreach( $terms as $key => $term ) {
							// we don't need to import root folder
							if( 'wp-media-folder' === $plugin_slug && 'wp-media-folder-root' === $term[ 'slug' ] ) {
								unset( $terms[ $key ] );
								continue;
							}
							// if we have a parent term, not so fast
							if( $term[ 'parent' ] && ! array_key_exists( $term[ 'parent' ], $mapped_folders ) ) {
								continue;
							}

							$parent = $term[ 'parent' ] ? $mapped_folders[ $term[ 'parent' ] ] : 0;

							if( ! $new_term = term_exists( $term[ 'name' ], self::TAXONOMY_NAME, $parent ) ) {
								// create a term
								$new_term = wp_insert_term( $term[ 'name' ], self::TAXONOMY_NAME, array( 'parent' => $parent ) );
							}

							if( ! is_wp_error( $new_term ) ) {
								// add the mapped folder
								$mapped_folders[ $term[ 'term_id' ] ] = (int) $new_term[ 'term_id' ];
							}
							// unset element
							unset( $terms[ $key ] );
						}
					}
					break;
				}
				case 'filebird' : {
					$folders = $wpdb->get_results(
						"
						SELECT id, name, parent
						FROM {$wpdb->prefix}fbv
						",
						ARRAY_A
					);

					if( ! $folders ) {
						return $mapped_folders;
					}

					while( ! empty( $folders ) ) {
						foreach( $folders as $key => $folder ) {

							// if we have a parent term, not so fast
							if( $folder[ 'parent' ] && ! array_key_exists( $folder[ 'parent' ], $mapped_folders ) ) {
								continue;
							}

							$parent = $folder[ 'parent' ] ? $mapped_folders[ $folder[ 'parent' ] ] : 0;

							if( ! $new_term = term_exists( $folder[ 'name' ], self::TAXONOMY_NAME, $parent ) ) {
								// create a term
								$new_term = wp_insert_term( $folder[ 'name' ], self::TAXONOMY_NAME, array( 'parent' => $parent ) );
							}

							if( ! is_wp_error( $new_term ) ) {
								// add the mapped folder
								$mapped_folders[ $folder[ 'id' ] ] = (int) $new_term[ 'term_id' ];
							}
							// unset element
							unset( $folders[ $key ] );
						}
					}

					break;
				}
				case 'real-media-library' : {
					$folders = $wpdb->get_results(
						"
						SELECT id, name, parent
						FROM {$wpdb->prefix}realmedialibrary
						",
						ARRAY_A
					);

					if( ! $folders ) {
						return $mapped_folders;
					}

					while( ! empty( $folders ) ) {
						foreach( $folders as $key => $folder ) {

							// if we have a parent term, not so fast
							if( $folder[ 'parent' ] && -1 != $folder[ 'parent' ] && ! array_key_exists( $folder[ 'parent' ], $mapped_folders ) ) {
								continue;
							}

							$parent = $folder[ 'parent' ] ? $mapped_folders[ $folder[ 'parent' ] ] : 0;

							if( ! $new_term = term_exists( $folder[ 'name' ], self::TAXONOMY_NAME, $parent ) ) {
								// create a term
								$new_term = wp_insert_term( $folder[ 'name' ], self::TAXONOMY_NAME, array( 'parent' => $parent ) );
							}

							if( ! is_wp_error( $new_term ) ) {
								// add the mapped folder
								$mapped_folders[ $folder[ 'id' ] ] = (int) $new_term[ 'term_id' ];
							}
							// unset element
							unset( $folders[ $key ] );
						}
					}

					break;
				}
				default : {
					break;
				}

			} // endswitch

			return $mapped_folders;

		}

		private function categorize_media( $plugin_slug, $mapped_folders ) {

			global $wpdb;

			$count = 0;
			$plugins = self::PLUGINS_FOR_IMPORT;

			switch( $plugin_slug ) {

				case 'wp-media-folder' :
				case 'wickedfolders' :
				case 'happyfiles' :
				case 'premio' : {
					// get all attachment IDs that have a specific taxonomy term
					$attachments = $wpdb->get_results(
						$wpdb->prepare(
							"
							SELECT p.ID, t.term_id
							FROM {$wpdb->posts} AS p
							INNER JOIN {$wpdb->term_relationships} AS tr ON p.ID = tr.object_id
							INNER JOIN {$wpdb->term_taxonomy} AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
							INNER JOIN {$wpdb->terms} AS t ON tt.term_id = t.term_id
							WHERE tt.taxonomy = %s
							AND p.post_type = 'attachment'
							",
							$plugins[ $plugin_slug ][ 'taxonomy' ]
						),
						ARRAY_A
					);

					foreach( $attachments as $attachment ) {
						// let's check if this term ID is mapped
						if( ! array_key_exists( $attachment[ 'term_id' ], $mapped_folders ) ) {
							continue;
						}

						$set = wp_set_object_terms( $attachment[ 'ID' ], (int) $mapped_folders[ $attachment[ 'term_id' ] ], self::TAXONOMY_NAME, true );
						if( is_wp_error( $set ) ) {
							continue;
						}

						$count++;
					}

					break;

				}

				case 'filebird' : {

					$attachments = $wpdb->get_results(
						"
						SELECT folder_id, attachment_id
						FROM {$wpdb->prefix}fbv_attachment_folder
						",
						ARRAY_A
					);

					foreach( $attachments as $attachment ) {
						// let's check if this term ID is mapped
						if( ! array_key_exists( $attachment[ 'folder_id' ], $mapped_folders ) ) {
							continue;
						}

						$set = wp_set_object_terms( $attachment[ 'attachment_id' ], (int) $mapped_folders[ $attachment[ 'folder_id' ] ], self::TAXONOMY_NAME, true );
						if( is_wp_error( $set ) ) {
							continue;
						}

						$count++;
					}

					break;
				}

				case 'real-media-library' : {

					$attachments = $wpdb->get_results(
						"
						SELECT attachment, fid
						FROM {$wpdb->prefix}realmedialibrary_posts
						",
						ARRAY_A
					);

					foreach( $attachments as $attachment ) {
						// let's check if this term ID is mapped
						if( ! array_key_exists( $attachment[ 'fid' ], $mapped_folders ) ) {
							continue;
						}

						$set = wp_set_object_terms( $attachment[ 'attachment' ], (int) $mapped_folders[ $attachment[ 'fid' ] ], self::TAXONOMY_NAME, true );
						if( is_wp_error( $set ) ) {
							continue;
						}

						$count++;
					}

					break;
				}

				default : {
					break;
				}

			}

			return $count;

		}

		public function admin_notices() {

			if( empty( $_GET[ 'page' ] ) || self::SETTINGS_PAGE !== $_GET[ 'page' ] || empty( $_GET[ 'message' ] ) ) {
				return;
			}

			if( 0 === strpos( $_GET[ 'message' ], 'mlf-removed--' ) ) {
				$plugin_slug = str_replace( 'mlf-removed--', '', $_GET[ 'message' ] );
				$plugins = self::PLUGINS_FOR_IMPORT;
				$plugin_name = $plugins[ $plugin_slug ][ 'name' ];
				?>
					<div class="notice notice-success is-dismissible">
						<p><?php
							echo esc_html( sprintf( __( 'The database data left from %s was removed successfully.', 'rudrastyh-simple-media-library-folders' ), $plugin_name ) );
						?></p>
					</div>
				<?php
			}

			if( 0 === strpos( $_GET[ 'message' ], 'mlf-imported--' ) ) {
				$counts = str_replace( 'mlf-imported--', '', $_GET[ 'message' ] );
				$counts = explode( '-', $counts );
				?>
					<div class="notice notice-success is-dismissible">
						<p><?php
							echo esc_html(
								sprintf( _n( '1 folder imported', '%s folders imported', $counts[0], 'rudrastyh-simple-media-library-folders' ), $counts[0] )
								. ' ' .
								sprintf( _n( 'and 1 media file categorized.', 'and %s media files categorized.', $counts[1], 'rudrastyh-simple-media-library-folders' ), $counts[1] )
							);
						?></p>
					</div>
				<?php
			}

		}

		public function download_folder() {
			
			check_admin_referer( 'download_folder' );

			$folder_id = ! empty( $_REQUEST[ 'folder_id' ] ) ? absint( $_REQUEST[ 'folder_id' ] ) : 0;
			if( ! $folder_id ) {
				wp_die( 'Incorrect folder ID.' );
			}
   
			$attachments = get_posts( array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'tax_query'      => array(
					array(
						'taxonomy' => self::TAXONOMY_NAME,
						'field'    => 'term_id',
						'terms'    => $folder_id,
					),
				),
			) );

			if( empty( $attachments ) ) {
				wp_die( 'No files to download.' );
			}

			// Prepare ZIP streaming
			if( ob_get_level() ) {
				ob_end_clean();
			}

			$zip = new ZipArchive();
    
			$tmp_file = tempnam( sys_get_temp_dir(), 'zip' );

			// remove it so ZipArchive can create it properly
			unlink( $tmp_file );

			if( true !== $zip->open( $tmp_file, ZipArchive::CREATE ) ) {
				wp_die( 'Could not create zip.' );
			}

			foreach ($attachments as $attachment) {
				$file_path = get_attached_file( $attachment->ID );

				// Only original file, skip generated sizes
				if ( ! $file_path || ! file_exists( $file_path ) ) {
					continue;
				}

				// Use clean filename inside ZIP
				$filename = basename( $file_path );

				$zip->addFile( $file_path, $filename );
			}

			$zip->close();

			// let's get a folder slug
			$folder = get_term_by( 'id', $folder_id, self::TAXONOMY_NAME );

			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . $folder->slug . '.zip"' );
			header( 'Content-Length: ' . filesize( $tmp_file ) );

			readfile( $tmp_file );
			unlink( $tmp_file );
			exit;
		}

		public function flatsome_ux_builder() {

			if( ! isset( $_GET[ 'app' ] ) || 'uxbuilder' !== $_GET[ 'app' ] ) {
				return;
			}
			?><style>
			body.sticky-menu.modal-open .media-modal-content .media-frame select.attachment-filters{
				width: auto !important;
				grid-area: 2 / 3 / 3 / 4;
				max-width: 210px;
			}
			</style><?php
		}

	}

	new SMLF_Simple_Media_Library_Folders;
}

require_once( __DIR__ . '/classes/class-smlf-term-list-walker.php' );
require_once( __DIR__ . '/classes/class-smlf-allow-svg.php' );