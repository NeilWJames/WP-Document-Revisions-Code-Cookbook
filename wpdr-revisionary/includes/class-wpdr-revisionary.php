<?php
/**
 * File containing class to implement WPDR and PublishPress Revisions compatibility.
 *
 * @package wpdr-revisionary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add compatibility with PublishPress Revisions, formerly known as Revisionary.
 */
class WPDR_Revisionary {

	/**
	 * Whether debug is turned on.
	 *
	 * @var bool $debug
	 */
	private $debug = false;

	/**
	 * Last child element of a PP Revisions document.
	 *
	 * @var int|null $revn
	 */
	private static $revn = null;

	/**
	 * Copy of wpdr object.
	 *
	 * @var object $wpdr
	 */
	private static $wpdr;

	/**
	 * Copy of revisionary statuses.
	 *
	 * @var string[]|null $rvy_statuses
	 */
	private static $rvy_statuses = null;

	/**
	 * Function to return revisionary statuses.
	 */
	private function rvy_revision_statuses() {
		if ( is_null( self::$rvy_statuses ) ) {
			self::$rvy_statuses = rvy_revision_statuses();
		}
		return self::$rvy_statuses;
	}

	/**
	 * Initiates an instance of the class and adds hooks.
	 *
	 * @since 0.5
	 */
	public function __construct() {
		$this->debug = defined( 'WP_DEBUG' ) && WP_DEBUG ?? false;

		if ( ! $this->is_plugin_active( 'revisionary/revisionary.php' ) ) {
			$this->log( 'PublishPress Revisions (Revisionary) is not active' );
			return;
		}

		if ( ! $this->is_plugin_active( 'wp-document-revisions/wp-document-revisions.php' ) ) {
			$this->log( 'WP Document Revisions is not active' );
			return;
		}

		// Ensure documents configured for PP Revisions.
		add_action( 'init', array( &$this, 'init' ), PHP_INT_MAX );
	}

	/**
	 * Add front end hooks.
	 *
	 * @since 0.5
	 */
	public function init(): void {
		// Check whether documents are configured for PP Revisions.
		global $revisionary;
		if ( ! is_null( $revisionary ) && ! array_key_exists( 'document', $revisionary->enabled_post_types ) ) {
			return;
		}

		// create a local copy of the global $wpdr object (to have functions local).
		global $wpdr;
		self::$wpdr = $wpdr;

		// Batch processes are hung on wp_loaded, so declare action points very early.
		add_action( 'wp_loaded', array( &$this, 'wp_loaded' ), 5 );

		// Reorder the admin menu - Revisions below Documents.
		add_action( 'admin_menu', array( &$this, 'reorder_admin_menu' ), 30 );

		add_action( 'admin_init', array( &$this, 'admin_init' ) );

		// Don't show document revisions in the Revision Archive.
		// Has the user switched it on for documents.
		$post_types_archive = get_option( 'rvy_enabled_post_types_archive', false );
		if ( false !== $post_types_archive && array_key_exists( 'document', $post_types_archive ) ) {
			// Switch it off.
			unset( $post_types_archive['document'] );
			update_option( 'rvy_enabled_post_types_archive', $post_types_archive );
		}
		// Belt and Braces.
		add_filter( 'revisionary_archive_post_types', array( &$this, 'archive_post_types' ) );
	}

	/**
	 * Add revisionary action hooks.
	 *
	 * @since 0.5
	 */
	public function wp_loaded(): void {
		// Add text replacements.
		add_filter( 'gettext_wp-document-revisions', array( &$this, 'update_text_wpdr' ), 90, 3 );
		add_filter( 'gettext_revisionary', array( &$this, 'update_text_revisionary' ), 90, 3 );

		// Allow PublishPress Revisions to delete revisions in some places.
		add_filter( 'document_allow_revision_deletion', array( &$this, 'possibly_delete_revision' ), 10, 2 );

		// Clean up any residual revision/attachment data after publishing the revision set (Future/Batch).
		add_action( 'revisionary_revision_published', array( &$this, 'wpdr_revision_published' ), 10, 2 );

		// Ensure excerpt is copied across.
		add_filter( 'revisionary_apply_revision_data', array( &$this, 'revision_data' ), 10, 3 );

		// Misuse of filter, so does nothing except to modify delete SQL.
		add_filter( 'revisionary_apply_revision_fields', array( &$this, 'revision_fields' ), 10, 4 );

		// Ensure only one PublishPress Revisions Document for documents.
		add_filter( 'default_options_rvy', array( &$this, 'options_set' ), 10 );
		add_filter( 'site_options_rvy', array( &$this, 'options_rvy' ), 10 );
		add_filter( 'options_rvy', array( &$this, 'options_rvy' ), 10 );

		// Ensure document post dates are updated on integration.
		add_filter( 'pp_revisions_option_pending_revision_update_post_date', array( &$this, 'pp_revisions_option' ), 90, 2 );
		add_filter( 'pp_revisions_option_pending_revision_update_modified_date', array( &$this, 'pp_revisions_option' ), 90, 2 );
		add_filter( 'pp_revisions_option_scheduled_revision_update_post_date', array( &$this, 'pp_revisions_option' ), 90, 2 );
		add_filter( 'pp_revisions_option_scheduled_revision_update_modified_date', array( &$this, 'pp_revisions_option' ), 90, 2 );
	}

	/**
	 * Add admin hooks.
	 *
	 * @since 0.5
	 */
	public function admin_init(): void {
		// Remove the revision log meta box from the document revision edit screen, swap it on document edit screen.
		// Remove UI elements that don't make sense when editing a revision.
		add_action( 'admin_head', array( $this, 'review_metaboxes' ), 10 );

		// Warning not to edit the Document if there if there is a PP Revisions revision.
		add_action( 'all_admin_notices', array( &$this, 'warning_for_revision' ), 5 );

		// Maybe redirect requests for revisions previews.
		add_action( 'template_redirect', array( &$this, 'revision_redirect' ), 15 );

		// Make sure reject.
		add_filter( 'wp_redirect', array( &$this, 'redirect_from_revision' ), 10, 2 );

		// Change the preview link format.
		add_filter( 'revisionary_preview_link_type', array( &$this, 'preview_link_type' ), 10, 2 );

		// Keep the summary on updating revisions.
		add_filter( 'document_keep_summary_on_update', array( &$this, 'possibly_keep_excerpt' ), 10, 2 );

		// Ignore excerpt and title as a change vector for PP Revision document (as their revisions will be deleted on integration).
		add_filter( '_wp_post_revision_fields', array( &$this, 'minimise_change' ), 10, 2 );

		// Do not validate PP Revisions Documents.
		add_filter( 'document_validate', array( &$this, 'document_validate' ), 10, 3 );

		// Ensure PublishPress Revisions document revisions limit only applies to itself.
		add_filter( 'wp_revisions_to_keep', array( &$this, 'revisions_to_keep' ), 90, 2 );
	}

	/**
	 * Ensure the preview link type for draft document revisions is id_only.
	 *
	 * @since 0.5
	 *
	 * @param string  $preview_link Link type format..
	 * @param WP_Post $post         Post object.
	 */
	public function preview_link_type( $preview_link, $post ): string {
		if ( ! $this->is_document_revision( $post ) ) {
			return $preview_link;
		}

		return 'id_only';
	}

	/**
	 * Control whether the existing post excerpt is shown in Revision Summary.
	 *
	 * @since 0.5
	 *
	 * @param bool    $keep  Whether to display existing data in the Revision Summary metabox.
	 * @param WP_Post $post  Post object.
	 */
	public function possibly_keep_excerpt( $keep, $post ): bool {
		// only for documents.
		if ( 'document' !== $post->post_type ) {
			return $keep;
		}

		// keep the excerpt when preparing a PP Revision (as we'll delete any of its revisions on integration).
		if ( ! get_post_meta( $post->ID, '_rvy_base_post_id', true ) ) {
			return $keep;
		}

		return true;
	}

	/**
	 * Changes in Revision Summary or Title does not create a revision for a PP Revision.
	 *
	 * @since 0.5
	 *
	 * @param string[] $fields List of fields to revision. Contains 'post_title',
	 *                         'post_content', and 'post_excerpt' by default.
	 * @param array    $post   Post object.
	 */
	public function minimise_change( $fields, $post ): array {
		// only for documents.
		if ( 'document' !== $post['post_type'] ) {
			return $fields;
		}

		// don't create a revision if only excerpt is changed for a PP Revision (as we'll delete any of its revisions on integration).
		if ( ! get_post_meta( $post['ID'], '_rvy_base_post_id', true ) ) {
			return $fields;
		}
		unset( $fields['post_excerpt'] );
		unset( $fields['post_title'] );

		return $fields;
	}
	/**
	 * Ensure that only one PP Revision per document and don't delete revisions.
	 *
	 * @since 0.5
	 *
	 * @param mixed[] $default_options revisionary default options.
	 */
	public function options_set( $default_options ): array {
		global $post;
		if ( ! is_null( $post ) && 'document' === $post->post_type ) {
			$default_options['revision_limit_per_post'] = 1;
			$default_options['extended_archive']        = 1;
		}
		return $default_options;
	}

	/**
	 * Ensure that only one PP Revision per document.
	 *
	 * @since 0.5
	 *
	 * @param mixed[] $options revisionary options.
	 */
	public function options_rvy( $options ): array {
		global $post;
		if ( ! is_null( $post ) && 'document' === $post->post_type ) {
			$options['revision_limit_per_post'] = 1;
			$options['extended_archive']        = 1;
		}
		return $options;
	}

	/**
	 * Ensure that published date are updated on integration.
	 *
	 * @since 0.5
	 *
	 * @param bool     $value revisionary option value.
	 * @param string[] $args  arguments.
	 */
	public function pp_revisions_option( $value, $args ): bool {
		if ( array_key_exists( 'post_id', $args ) && 'document' === get_post_type( $args['post_id'] ) ) {
			return true;
		}
		return $value;
	}

	/**
	 * Make sure documents are not in the Revisions Archive.
	 *
	 * @since 0.5
	 *
	 * @param string[] $post_types_archive Array of post_types that are in Revisions archive.
	 */
	public function post_types_archive( $post_types_archive ): array {
		if ( is_array( $post_types_archive ) && array_key_exists( 'document', $post_types_archive ) ) {
			// Remove it.
			unset( $post_types_archive['document'] );
		}
		return $post_types_archive;
	}

	/**
	 * Output the redirect link.
	 *
	 * @since 0.5
	 *
	 * @param string $location The path or URL to redirect to.
	 * @param int    $status   The HTTP response status code to use.
	 */
	public function redirect_from_revision( $location, $status ): string { //phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->log( 'location: ' . $location );

		return $location;
	}

	/**
	 * Check if a plugin is active. Works when WordPress's is_plugin_active has not been loaded.
	 *
	 * @since 0.5
	 *
	 * @param string $plugin The plugin file name.
	 * @return bool
	 */
	private function is_plugin_active( string $plugin ): bool {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return in_array( $plugin, apply_filters( 'active_plugins', (array) get_option( 'active_plugins', array() ) ), true );
	}

	/**
	 * Log to the error log.
	 *
	 * @since 0.5
	 *
	 * @param string $message The message to log.
	 * @param mixed  $data optional Any data to log.
	 */
	private function log( string $message, $data = null ): void {
		if ( $this->debug ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
			write_log( 'WPDR_RVY: ' . $message . ' ' . print_r( $data, true ) );
		}
	}

	/**
	 * Is the post a revision?
	 *
	 * @since 0.5
	 *
	 * @param int|WP_Post|stdClass|null $post Post identifier.
	 */
	private function is_document_revision( int|WP_Post|stdClass|null $post ): bool {
		if ( is_null( $post ) ) {
			return false;
		}

		global $wpdr;
		if ( ! $wpdr->verify_post_type( $post ) ) {
			return false;
		}

		if ( is_scalar( $post ) ) {
			$p = get_post( $post );
		} elseif ( $post instanceof WP_Post ) {
			$p = $post;
		} else {
			$p = get_post( $post->ID );
		}

		return 'revision' === $p->post_type || str_contains( $p->post_mime_type, 'revision' );
	}

	/**
	 * Move the Revisions menu item below the Documents.
	 *
	 * @since 0.5
	 */
	public function reorder_admin_menu(): void {
		global $menu;

		$revisionary_key = null;
		$document_key    = null;

		// Loop over menu to get the keys for the revisionary-q and document menu items.
		foreach ( $menu as $key => $value ) {
			if ( 'revisionary-q' === $value[2] ) {
				$revisionary_key = $key;
			}
			if ( 'edit.php?post_type=document' === $value[2] ) {
				$document_key = $key;
			}
			if ( $revisionary_key && $document_key ) {
				break;
			}
		}

		if ( $revisionary_key && $document_key && intval( $revisionary_key ) < intval( $document_key ) ) {

			$new_revisionary_key = $document_key + 1;

			// Find a new key that doesn't exist.
			while ( isset( $menu[ $new_revisionary_key ] ) ) {
				++$new_revisionary_key;
			}

			// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
			$menu[ $new_revisionary_key ] = $menu[ $revisionary_key ];
			unset( $menu[ $revisionary_key ] );
			// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * Remove the revision log meta box from the document edit screen.
	 *
	 * @since 0.5
	 *
	 * If we're editing a draft or pending revision it does not make sense to show the Revision Log meta box.
	 * If we're editing a published revision, the Revision Log meta box is replaced with a custom one.
	 */
	public function review_metaboxes(): void {
		$id = get_the_ID();
		global $wpdr;
		if ( ! $wpdr->verify_post_type( $id ) ) {
			return;
		}
		remove_meta_box( 'revision-log', 'document', 'normal' );

		$is_revn = $this->is_document_revision( $id );
		if ( ! $is_revn ) {
			// add a modified revision log metabox.
			add_meta_box(
				'revision-log',
				__( 'Revision Log', 'wpdr-revisionary' ),
				array( $this, 'revision_metabox' ),
				'document',
				'normal',
				'low'
			);
		} else {
			// Remove compare option.
			echo "\n<style type='text/css'>\n";
			echo "#rvy_compare_button { display: none !important; }\n";
			echo "</style>\n";
			// Have we downloaded a new revision. If not the "current" belongs to the parent.
			global $wpdr;
			$content = get_post_field( 'post_content', $id );
			$attach  = $wpdr->extract_document_id( $content );
			$parent  = get_post_field( 'post_parent', $attach );
			if ( $parent !== $id ) {
				// Document belongs to the parent - can't view it here.
				echo "\n<style type='text/css'>\n";
				echo "#document p a { pointer-events: none; opacity: 0.7; cursor: not-allowed; }\n";
				echo "</style>\n";
			}
		}
	}

	/**
	 * Custom Revision Log metabox.
	 *
	 * @since 0.5
	 *
	 * This metabox is added to the document edit screen when editing a document (not a document revision).
	 *
	 * @param WP_Post $post The post object.
	 */
	public function revision_metabox( $post ): void {
		global $wpdr;

		if ( ! isset( $wpdr ) ) {
			return;
		}

		// text will have strong tags in them.
		$allowed_tags = array(
			'strong' => array(),
		);
		echo '<p>' . wp_kses( __( 'This table shows the <strong>published</strong> revisions for this document.', 'wpdr-revisionary' ), $allowed_tags ) . ' </p>';

		if ( (int) get_post_meta( get_the_ID(), '_rvy_has_revisions', true ) ) {
			echo '<p>';
			echo wp_kses( __( 'There are also <strong>non-published</strong> revision(s) in the ', 'wpdr-revisionary' ), $allowed_tags );
			// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- This is deliberate as we want the PP Revisions text and its translation.
			printf( '<a href="%s">%s</a>', esc_url( admin_url( '/admin.php?page=revisionary-q' ) ), esc_html__( 'New Revisions', 'revisionary' ) );
			echo '.</p>';
		}

		$wpdr->admin->revision_metabox( $post );
	}

	/**
	 * Update the text if we're editing a revision.
	 *
	 * @since 0.5
	 *
	 * @param string $translation The translated text.
	 * @param string $text        The text to translate.
	 * @param string $domain      The text domain.
	 * @return string The translated text.
	 */
	public function update_text_wpdr( string $translation, string $text, string $domain ): string {  //phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$id = get_the_ID();
		if ( $this->is_document_revision( $id ) ) {
			if ( 'Latest Version of the Document' === $text ) {
				return __( 'File for this Document Revision', 'wpdr-revisionary' );
			}

			if ( 'Download' === $text ) {
				return __( 'Preview', 'wpdr-revisionary' );
			}
		}

		return $translation;
	}

	/**
	 * Update the text if we're editing a revision (revisionary).
	 *
	 * @since 0.5
	 *
	 * @param string $translation The translated text.
	 * @param string $text        The text to translate.
	 * @param string $domain      The text domain.
	 * @return string The translated text.
	 */
	public function update_text_revisionary( string $translation, string $text, string $domain ): string {  //phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		if ( 'Has Revision' === $text ) {
			global $wpdr;
			$id = get_the_ID();
			if ( $wpdr->verify_post_type( $id ) ) {
				return __( 'Has a New Revision', 'wpdr-revisionary' );
			}
		}

		return $translation;
	}

	/**
	 * Output a warning notice if there is a PP Revisions revision.
	 *
	 * @since 0.5
	 */
	public function warning_for_revision(): void {
		// Only when editing a document.
		global $pagenow;
		if ( 'post.php' !== $pagenow ) {
			return;
		}
		// make sure a document that has a PP revision.
		global $wpdr;
		$id = get_the_ID();
		if ( ! $wpdr->verify_post_type( $id ) ) {
			return;
		}
		if ( ! get_post_meta( $id, '_rvy_has_revisions', true ) ) {
			return;
		}
		echo '<div class="notice notice-warning" id="wpdr_rvy_warning"><p>';
		esc_html_e( 'This document has a PP Revisions revision.', 'wpdr-revisionary' );
		echo '<br />';
		esc_html_e( 'Changes made to this document may be lost when that Revision is approved.', 'wpdr-revisionary' );
		echo '</p></div>';
	}

	/**
	 * Redirect document revision preview links - only on sites where home_url is different from site_url.
	 *
	 * This fixes the Preview button and the Download button
	 * (that has been renamed to Preview) on the revision edit screen.
	 * e.g. https://mysite.com/wp/?post_type=document&p=123 -> https://mysite.com/?post_type=document&p=25397
	 *
	 * @since 0.5
	 */
	public function revision_redirect(): void {
		// If we're not on a specific post return.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['p'] ) && ! isset( $_GET['post'] ) ) {
			return;
		}

		// Build up the current url from get_site_url and $_SERVER.
		$http_host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_url( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$current_url = wp_parse_url( get_site_url(), PHP_URL_SCHEME ) . '://' . $http_host . $request_uri;
		$input_url   = $current_url;

		// Are we trying to edit a revision that is now published.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$p    = ( isset( $_GET['p'] ) ? (int) $_GET['p'] : (int) $_GET['post'] );
		$post = get_post( $p );
		$this->log( 'Post: ', $post );
		if ( 'revision' === $post->post_type && 0 !== $post->post_parent ) {
			$parent = get_post( $post->post_parent );
			if ( 'document' === $parent->post_type ) {
				$current_url = str_replace( 'p=' . $p, 'p=' . $post->post_parent, $current_url );
			}
		}

		// If the home_url is the same as the site_url, we don't need to redirect.
		if ( get_site_url() !== get_home_url() ) {
			// Build a new url based on the current url.
			$current_url = str_replace( get_site_url(), get_home_url(), $current_url );
		}

		// If the new url is different from the input url, redirect.
		if ( $input_url !== $current_url ) {
			$this->log( 'Redirecting to ', $current_url );
			wp_safe_redirect( $current_url );
			exit;
		}
	}

	/**
	 * Check to see if the WPDR deletion protection should be turned off.
	 *
	 * @since 0.5
	 *
	 * @param bool    $allow_delete Whether to let the delete process happen.
	 * @param WP_Post $post         The post object.
	 */
	public function possibly_delete_revision( $allow_delete, $post ): bool {
		$this->log( 'del_revn', $post );
		// is this a PP_Revision post.
		if ( (bool) $post->comment_count && array_key_exists( $post->post_mime_type, $this->rvy_revision_statuses() ) ) {
			return $allow_delete;
		}
		// Was it called from within PP Revisions.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$traces = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
		// let any deletions occur.
		$allow = array(
			'actDeletePost',
			'applyRevisionLimit',
			'rvy_admin_init',
			'rvy_apply_revision',
			'revision_fields',
		);
		// stop deletions.
		$block = array(
			'rvy_revision_bulk_delete',
			'rvy_revision_delete',
		);
		foreach ( array_reverse( $traces ) as $trace ) {
			if ( in_array( $trace['function'], $allow, true ) ) {
				$this->log( 'delete:', $trace['function'] );
				return true;
			}
			if ( in_array( $trace['function'], $block, true ) ) {
				// Dont want standard plugin processing.
				$this->log( 'keep:' . $trace['function'] );
				return false;
			}
		}
		if ( 9 === count( $traces ) && 'wp_delete_post' === $traces[8]['function'] ) {
			// WP Core process to delete post and its revisions. OK to delete.
			return true;
		}
		$this->log( 'trace', $traces );
		return $allow_delete;
	}

	/**
	 * Review the Revision document chain (including attachments)and make whole (Future/Batch).
	 *
	 * @since 0.5
	 *
	 * In particular any attachment needed to have the original document as parent.
	 *
	 * @param WP_Post $published The published post object.
	 * @param WP_Post $revision  The revision post object (was a document).
	 */
	public function wpdr_revision_published( $published, $revision ): void {
		if ( 'document' !== $published->post_type ) {
			// Only for documents.
			return;
		}
		$this->log( 'published', $published );
		$this->log( 'revision', $revision );
		$parent = null;
		// Ensure that the published post is a document and the revision is no longer a document.
		if ( 'revision' === $revision->post_type ) {
			// We have already integrated the revision.
			$parent = $published->ID;
		} elseif ( 'document' === $revision->post_type && in_array( $revision->post_mime_type, $this->rvy_revision_statuses(), true ) ) {
			// A PP Revision, Find the parent.
			if ( (bool) $revision->comment_count ) {
				$parent = $revision->comment_count;
			} else {
				$parent = get_post_meta( $revision->ID, '_rvy_base_post_id', true );
				if ( ! $parent ) {
					// shouldn't happen, but...
					$parent = $published->ID;
				}
			}
		}

		if ( is_null( $parent ) ) {
			$this->log( 'Check this case: Pub: ' . $published->ID . ' PP Rev: ' . $revision->ID );
			return;
		}

		// The revision name needs to be a revision.
		$name = $parent . '-revision-v1';
		if ( $revision->post_name !== $name ) {
			$revision->post_name = $name;
			wp_update_post(
				array(
					'ID'        => $revision->ID,
					'post_name' => $name,
				)
			);
		}

		// There is a Document Revision created after the PP Revision and we can bump up its ID.
		global $wpdb;
		if ( ! is_null( self::$revn ) ) {
			$this->log( 'update ' . $revision->ID . ' to ' . self::$revn );
			// if records have been deleted, update the key of the revision to the highest by SQL.
			$guid = str_replace( 'p=' . $revision->ID, 'p=' . self::$revn, $revision->guid );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$sql = $wpdb->query(
				$wpdb->prepare(
					"UPDATE $wpdb->posts " .
					' SET ID = %d, ' .
					'     guid = %s ' .
					' WHERE ID = %d',
					self::$revn,
					$guid,
					$revision->ID,
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$sql        = $wpdb->query(
				$wpdb->prepare(
					"UPDATE $wpdb->postmeta " .
					' SET post_id = %d ' .
					' WHERE post_id = %d',
					self::$revn,
					$revision->ID,
				)
			);
			self::$revn = null;
		}
		self::$wpdr->clear_cache( $revision->ID, $revision, true );

		// Will call the redirect page and callit directly.
		// TODO - Not if in batch process.
		add_action( 'revision_approved', array( &$this, 'published_redirect' ), 999, 2 );
	}

	/**
	 * Review the Revision document data to ensure all relevant fields copied.
	 *
	 * @since 0.5
	 *
	 * @param mixed[] $update    Fields to be updated.
	 * @param WP_Post $revision  The revision post object (was a document).
	 * @param WP_Post $published The published post object.
	 * @return mixed[]
	 */
	public function revision_data( $update, $revision, $published ) {
		if ( 'document' !== $published->post_type ) {
			return $update;
		}
		$this->log( 'data: ' . $published->post_type );
		$this->log( 'update: ' . $update['ID'] . ' Pub: ' . $published->ID );
		$this->log( 'Uex: ' . $update['post_excerpt'] );
		$this->log( 'Rex: ' . $revision->post_excerpt );
		$this->log( 'Pex: ' . $published->post_excerpt );

		// Add fields to list.
		$update['post_name']         = $published->ID . '-revision-v1';
		$update['post_date']         = current_time( 'mysql' );
		$update['post_date_gmt']     = current_time( 'mysql', 1 );
		$update['post_modified']     = current_time( 'mysql' );
		$update['post_modified_gmt'] = current_time( 'mysql', 1 );

		return $update;
	}

	/**
	 * Add the SQL filter to stop deletion of original revisions (and also remove it).
	 *
	 * @since 0.5
	 *
	 * @param mixed[] $update_fields          Fields to be updated.
	 * @param WP_Post $revision               The revision post object (was a document).
	 * @param WP_Post $published              The published post object.
	 * @param string  $actual_revision_status The revision status.
	 * @return mixed[]
	 */
	public function revision_fields( $update_fields, $revision, $published, $actual_revision_status ) { //phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->log( 'fields: ' . $published->post_type );
		if ( 'document' !== $published->post_type ) {
			return $update_fields;
		}

		// The attachment on the revision document needs to have the original document as parent.
		global $wpdr;
		$attach = $wpdr->extract_document_id( $revision->post_content );
		$this->log( 'attach', $attach );
		if ( get_post_field( 'post_parent', $attach ) !== $published->ID ) {
			// Update attachment post in the database.
			$this->log( 'update parent to ' . $published->ID );
			wp_update_post(
				array(
					'ID'          => $attach,
					'post_parent' => $published->ID,
				)
			);
			$wpdr->clear_cache( $published->ID, $published, true );
			clean_post_cache( $published->ID );
		}

		// Check if there is any residual revision or attachments with revision as parent and delete them.
		// Need to do it here as the revision children would otherwise be deleted by SQL (and will ignore any redundant attachments).
		global $wpdr;
		$this->log( 'Revision:' . $revision->ID );
		$children = get_children(
			array(
				'post_parent'   => $revision->ID,
				'post_type'     => array( 'document', 'revision', 'attachment' ),
				'cache_results' => false,   // dont trust caching.
			),
		);
		if ( ! empty( $children ) ) {
			self::$revn = $revision->ID;
			foreach ( $children as $child ) {
				$id = $child->ID;
				$this->log( 'Post:' . $id . ' Type:' . $child->post_type );
				// Find the maximum post being deleted.
				if ( $id > self::$revn ) {
					self::$revn = $id;
				}
				switch ( $child->post_type ) {
					case 'revision':
						wp_delete_post_revision( $id );
						break;
					case 'attachment':
						// need to ensure that we're pointing to the document directory.
						$file = trailingslashit( $wpdr::$wpdr_document_dir ) . get_post_meta( $id, '_wp_attached_file', true );
						wp_delete_attachment( $id, true );

						// belt and braces.
						wp_delete_file( $file );
						break;
					default:
						// shouldn't be any of these, but ...
						wp_delete_post( $id, true );
				}
			}
		}

		// Is there a published revision created after the PP Revision.
		// If so, we need to keep them and also possibly keep their ordering by later manipulation.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rev_exist = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM $wpdb->posts" .
				' WHERE ID > %d AND ID < %d ' .
				" AND post_parent = %d AND post_type = 'revision' AND post_name <> %s",
				$revision->ID,
				self::$revn,
				$published->ID,
				$published->ID . '-autosave-v1',
			)
		);

		// If there is a value, then it is useful to bump up the revision ID as it will improve revision ordering.
		if ( $rev_exist ) {
			$this->log( 'WPDR rev exist: ', self::$revn );
			// Suppress the Published Document SQL later revision Deletes; and also switch off the filter.
			add_filter( 'query', array( &$this, 'delete_query' ) );
			add_action( 'revision_applied', array( &$this, 'remove_delete_query' ), 99, 2 );
		} else {
			// no WPDR revision found in the PP Revision range, no need to think of bumping PP Revision.
			self::$revn = null;
		}

		// check the published post has correct revisions metadata (belt and braces).
		revisionary_refresh_postmeta( $published->ID );

		return $update_fields;
	}

	/**
	 * Modify the Delete Revision SQL to render ineffective.
	 *
	 * @since 0.5
	 *
	 * @param string $query SQL query string.
	 */
	public function delete_query( $query ): string {
		global $wpdb;
		// SQL to change.
		if ( 0 === strpos( $query, "DELETE FROM $wpdb->posts " ) ) {
			$query .= ' AND 1=0';
			$this->log( 'query:', $query );
			// now stop looking and can switch off belt and braces.
			remove_filter( 'query', array( &$this, 'delete_query' ) );
			remove_action( 'revision_applied', array( &$this, 'remove_delete_query' ), 99, 2 );
		}
		return $query;
	}

	/**
	 * Inactivate the Delete Revision SQL changes if we have.
	 *
	 * @since 0.5
	 *
	 * @param int     $post     The Post id.
	 * @param WP_Post $revision Revision object.
	 */
	public function remove_delete_query( $post, $revision ): void { //phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// belt and braces as should have happened earlier.
		remove_filter( 'query', array( &$this, 'delete_query' ) );
		remove_action( 'revision_applied', array( &$this, 'remove_delete_query' ), 99, 2 );
	}

	/**
	 * Do not try to validate a PublishPress Revision document.
	 *
	 * @param bool   $validate Whether to validate the document (default true).
	 * @param int    $doc_id   Document post ID.
	 * @param string $content  Document post content.
	 */
	public function document_validate( $validate, $doc_id, $content ) { //phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( get_post_meta( $doc_id, '_rvy_base_post_id', true ) ) {
			// Revision.
			return false;
		}
		return $validate;
	}

	/**
	 * Do not archive document post_types.
	 *
	 * @param string[] $post_types Array of post_types to archive.
	 */
	public function archive_post_types( $post_types ) {
		if ( array_key_exists( 'document', $post_types ) ) {
			unset( $post_types['document'] );
		}
		return $post_types;
	}

	/**
	 * Do not use the Revisionary revisions limit for WPDR Documents..
	 *
	 * @param int $num     Number of revisions to keep.
	 * @param int $doc_id  Document post ID.
	 */
	public function revisions_to_keep( $num, $doc_id ) {
		if ( get_post_meta( $doc_id, '_rvy_base_post_id', true ) ) {
			// Revision.
			return $num;
		}
		return -1;
	}

	/**
	 * Where to go after a document has been published.
	 *
	 * @param int $post_id     Id of the Document.
	 * @param int $revision_id Id of the PP Revision Document (may no longer be there).
	 */
	public function published_redirect( $post_id, $revision_id ) { //phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$redirect = 'post.php?post=' . $post_id . '&action=edit&post_type=document';
		wp_safe_redirect( $redirect );
		exit;
	}
}
