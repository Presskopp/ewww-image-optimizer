<?php
/**
 * Common functions for Standard and Cloud plugins
 *
 * This file contains functions that are shared by both the regular EWWW IO plugin, and the
 * Cloud version. Functions that differ between the two are stored in the main
 * ewww-image-optimizer(-cloud).php file.
 *
 * @link https://ewww.io
 * @package EWWW_Image_Optimizer
 */

// TODO: use <picture> element to serve webp.
// TODO: see if we can offer a rebuild option, to fill in missing thumbs.
// TODO: so, if lazy loading support stinks, can we roll our own? that's an image "optimization", right?...
// TODO: prevent bad ajax errors from firing when we click the toggle on the Optimization Log, and the plugin status from doing 403s...
// TODO: use a transient to do health checks on the schedule optimizer.
// TODO: add a column to track compression level used for each image, and later implement a way to (re)compress at a specific compression level.
// TODO: might be able to use the Custom Bulk Actions in 4.7 to support the bulk optimize drop-down menu.
// TODO: see if there is a way to query the last couple months (or 30 days) worth of attachments, but only when the user is not using year/month folders. This way, they can catch extraneous images if possible. Use the post_date field, and do a media_scan followed by the folder scan to catch remaining images.
// TODO: move resizing options into their own sub-menu.
// TODO: need to make the scheduler so it can resume without having to re-run the queue population, and then we can probably also flush the queue when scheduled opt starts, but later it would be nice to implement the bulk_loop as the aux_loop so that it could handle media properly.
// TODO: implement a search for the bulk table, or maybe we should just move it to it's own page?
// TODO: port bulk changes to NextGEN and FlaGallery.
// TODO: make a bulk restore function.
// TODO: Add a custom async function for parallel mode to store image as pending and use the row ID instead of relative path.
// TODO: write some tests for update_table and check_table, find_already_opt, and remove_dups.
// TODO: write some conversion tests.
// TODO: do a bottom paginator for the show optimized images table.
// TODO: use wp_http_supports( array( 'ssl' ) ) to detect whether we should use https.
// TODO: check this patch, to see if the use of 'full' causes any issues: https://core.trac.wordpress.org/ticket/37840 .
// TODO: perhaps have an optional footer thingy that says how many images have been optimized.
// TODO: integrate AGR, since it's "abandoned", but possibly using gifsicle for better GIFs.
// TODO: make sure resized images get compressed before comparison/discard.
// TODO: allow an S3 defer override for folks that have the thumbnail broken issue when uploads are delayed.
// TODO: link to wp-cli docs page in readme description.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EWWW_IMAGE_OPTIMIZER_VERSION', '350.0' );

// Initialize a couple globals.
$ewww_debug = '';
$ewww_defer = true;

if ( WP_DEBUG ) {
	$ewww_memory = 'plugin load: ' . memory_get_usage( true ) . "\n";
}

// Setup custom $wpdb attribute for our image-tracking table.
global $wpdb;
if ( ! isset( $wpdb->ewwwio_images ) ) {
	$wpdb->ewwwio_images = $wpdb->prefix . 'ewwwio_images';
}

// Check for Pantheon environment, and turn on relative folder usage at level 3.
if ( ! empty( $_ENV['PANTHEON_ENVIRONMENT'] ) && in_array( $_ENV['PANTHEON_ENVIRONMENT'], array( 'test', 'live', 'dev' ) ) ) {
	if ( ! defined( 'EWWW_IMAGE_OPTIMIZER_RELATIVE' ) ) {
		define( 'EWWW_IMAGE_OPTIMIZER_RELATIVE', 3 );
	}
}

// Used for manipulating exif info.
require_once( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'vendor/pel/autoload.php' );
use lsolesen\pel\PelJpeg;
use lsolesen\pel\PelTag;

/*
 * Hooks
 */

// If automatic optimization is NOT disabled.
if ( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_noauto' ) ) {
	if ( class_exists( 'S3_Uploads' ) ) {
		// Resizes and auto-rotates images.
		add_filter( 'wp_handle_upload_prefilter', 'ewww_image_optimizer_handle_upload', 8 );
	} else {
		// Resizes and auto-rotates images.
		add_filter( 'wp_handle_upload', 'ewww_image_optimizer_handle_upload' );
	}
	// Turns off the ewwwio_image_editor during uploads.
	add_action( 'add_attachment', 'ewww_image_optimizer_add_attachment' );
	// Turns off ewwwio_image_editor during Enable Media Replace.
	add_filter( 'emr_unfiltered_get_attached_file', 'ewww_image_optimizer_image_sizes' );
	// Enables direct integration to the editor's save function.
	add_filter( 'wp_image_editors', 'ewww_image_optimizer_load_editor', 60 );
	// Processes an image via the metadata after upload.
	add_filter( 'wp_generate_attachment_metadata', 'ewww_image_optimizer_resize_from_meta_data', 15, 2 );
	// Add hook for PTE confirmation to make sure new resizes are optimized.
	add_filter( 'wp_get_attachment_metadata', 'ewww_image_optimizer_pte_check' );
	// Resizes and auto-rotates MediaPress images.
	add_filter( 'mpp_handle_upload', 'ewww_image_optimizer_handle_mpp_upload' );
	// Processes a MediaPress image via the metadata after upload.
	add_filter( 'mpp_generate_metadata', 'ewww_image_optimizer_resize_from_meta_data', 15, 2 );
}
// Makes sure the optimizer never optimizes it's own testing images.
add_filter( 'ewww_image_optimizer_bypass', 'ewww_image_optimizer_ignore_self', 10, 2 );
// This filter turns off ewwwio_image_editor during save from the actual image editor and ensures that we parse the resizes list during the image editor save function.
add_filter( 'load_image_to_edit_path', 'ewww_image_optimizer_editor_save_pre' );
// Adds a column to the media library list view to display optimization results.
add_filter( 'manage_media_columns', 'ewww_image_optimizer_columns' );
// Outputs the actual column information for each attachment.
add_action( 'manage_media_custom_column', 'ewww_image_optimizer_custom_column', 10, 2 );
// Filters to set default permissions, admins can override these if they wish.
add_filter( 'ewww_image_optimizer_manual_permissions', 'ewww_image_optimizer_manual_permissions', 8 );
add_filter( 'ewww_image_optimizer_bulk_permissions', 'ewww_image_optimizer_admin_permissions', 8 );
add_filter( 'ewww_image_optimizer_admin_permissions', 'ewww_image_optimizer_admin_permissions', 8 );
add_filter( 'ewww_image_optimizer_superadmin_permissions', 'ewww_image_optimizer_superadmin_permissions', 8 );
// Add a link to the plugins page so the user can go straight to the settings page.
$ewww_plugin_slug = plugin_basename( EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE );
add_filter( "plugin_action_links_$ewww_plugin_slug", 'ewww_image_optimizer_settings_link' );
// Filter the registered sizes so we can remove any that the user disabled.
add_filter( 'intermediate_image_sizes_advanced', 'ewww_image_optimizer_image_sizes_advanced' );
// Ditto for PDF files (or anything non-image).
add_filter( 'fallback_intermediate_image_sizes', 'ewww_image_optimizer_fallback_sizes' );
// Filters the settings page output when cloud settings are enabled.
add_filter( 'ewww_image_optimizer_settings', 'ewww_image_optimizer_filter_settings_page' );
// Processes screenshots imported with MyArcadePlugin.
add_filter( 'myarcade_filter_screenshot', 'ewww_image_optimizer_myarcade_thumbnail' );
// Processes thumbnails created by MyArcadePlugin.
add_filter( 'myarcade_filter_thumbnail', 'ewww_image_optimizer_myarcade_thumbnail' );
// Allows the user to override the default JPG quality used by WordPress.
add_filter( 'jpeg_quality', 'ewww_image_optimizer_set_jpg_quality' );
// Makes sure the plugin bypasses any files affected by the Folders to Ignore setting.
add_filter( 'ewww_image_optimizer_bypass', 'ewww_image_optimizer_ignore_file', 10, 2 );
// Loads the plugin translations.
add_action( 'plugins_loaded', 'ewww_image_optimizer_preinit' );
// Checks for nextgen/nextcellent/flagallery existence, and loads the appropriate classes.
add_action( 'init', 'ewww_image_optimizer_gallery_support' );
// Initializes the plugin for admin interactions, like saving network settings and scheduling cron jobs.
add_action( 'admin_init', 'ewww_image_optimizer_admin_init' );
// Legacy (non-AJAX) action hook for manually optimizing an image.
add_action( 'admin_action_ewww_image_optimizer_manual_optimize', 'ewww_image_optimizer_manual' );
// Legacy (non-AJAX) action hook for manually restoring a converted image.
add_action( 'admin_action_ewww_image_optimizer_manual_restore', 'ewww_image_optimizer_manual' );
// Legacy (non-AJAX) action hook for manually restoring a backup from the API.
add_action( 'admin_action_ewww_image_optimizer_manual_cloud_restore', 'ewww_image_optimizer_manual' );
// Legacy (non-AJAX) action hook for manually attempting conversion on an image.
add_action( 'admin_action_ewww_image_optimizer_manual_convert', 'ewww_image_optimizer_manual' );
// Cleanup routine when an attachment is deleted.
add_action( 'delete_attachment', 'ewww_image_optimizer_delete' );
// Adds the EWWW IO pages to the admin menu.
add_action( 'admin_menu', 'ewww_image_optimizer_admin_menu', 60 );
// Adds the EWWW IO settings to the network admin menu.
add_action( 'network_admin_menu', 'ewww_image_optimizer_network_admin_menu' );
// Adds the hook to modify the media library bulk actions via admin_print_footer_scripts.
add_action( 'load-upload.php', 'ewww_image_optimizer_load_admin_js' );
// Non-AJAX handler to reroute selected IDs to the bulk optimizer.
add_action( 'admin_action_bulk_optimize', 'ewww_image_optimizer_bulk_action_handler' );
// Ditto, but handles the actions from the bottom of the media page.
add_action( 'admin_action_-1', 'ewww_image_optimizer_bulk_action_handler' );
// Adds scripts to ajaxify the one-click actions on the media library, and register tooltips for conversion links.
add_action( 'admin_enqueue_scripts', 'ewww_image_optimizer_media_scripts' );
// Adds scripts for the EWWW IO settings page.
add_action( 'admin_enqueue_scripts', 'ewww_image_optimizer_settings_script' );
// Enables scheduled optimization via wp-cron.
add_action( 'ewww_image_optimizer_auto', 'ewww_image_optimizer_auto' );
// Correct any records in the table created during retina generation.
add_action( 'wr2x_retina_file_added', 'ewww_image_optimizer_retina', 20, 2 );
// AJAX action hook for inserting WebP rewrite rules into .htaccess.
add_action( 'wp_ajax_ewww_webp_rewrite', 'ewww_image_optimizer_webp_rewrite' );
// AJAX action hook for manually optimizing/converting an image.
add_action( 'wp_ajax_ewww_manual_optimize', 'ewww_image_optimizer_manual' );
// AJAX action hook for manually restoring a converted image.
add_action( 'wp_ajax_ewww_manual_restore', 'ewww_image_optimizer_manual' );
// AJAX action hook for manually restoring an attachment from backups on the API.
add_action( 'wp_ajax_ewww_manual_cloud_restore', 'ewww_image_optimizer_manual' );
// AJAX action hook for manually restoring a single image backup from the API.
add_action( 'wp_ajax_ewww_manual_cloud_restore_single', 'ewww_image_optimizer_cloud_restore_single_image_handler' );
// Adds script to highlight mis-sized images on the front-end (for logged in admins only).
add_action( 'wp_enqueue_scripts', 'ewww_image_optimizer_resize_detection_script' );
// Adds a button on the admin bar to allow highlighting mis-sized images on-demand.
add_action( 'admin_bar_init', 'ewww_image_optimizer_admin_bar_init' );
// Non-AJAX handler to delete the API key, and reroute back to the settings page.
add_action( 'admin_action_ewww_image_optimizer_remove_cloud_key', 'ewww_image_optimizer_remove_cloud_key' );
// Non-AJAX handler to delete the debug log, and reroute back to the settings page.
add_action( 'admin_action_ewww_image_optimizer_delete_debug_log', 'ewww_image_optimizer_delete_debug_log' );
// Makes sure to flush out any scheduled jobs on deactivation.
register_deactivation_hook( EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE, 'ewww_image_optimizer_network_deactivate' );
// Removes the .htaccess rules for webp when uninstalled.
register_uninstall_hook( EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE, 'ewww_image_optimizer_uninstall' );
// add_action( 'shutdown', 'ewwwio_memory_output' );.
// Makes sure we flush the debug info to the log on shutdown.
add_action( 'shutdown', 'ewww_image_optimizer_debug_log' );
// If Alt WebP Rewriting is enabled.
if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_for_cdn' ) ) {
	// Start an output buffer before any output starts.
	add_action( 'template_redirect', 'ewww_image_optimizer_buffer_start' );
	if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
		// Load the non-minified, non-inline version of the webp rewrite script.
		add_action( 'wp_enqueue_scripts', 'ewww_image_optimizer_webp_debug_script' );
	} elseif ( defined( 'EWWW_IMAGE_OPTIMIZER_WEBP_EXTERNAL_SCRIPT' ) && EWWW_IMAGE_OPTIMIZER_WEBP_EXTERNAL_SCRIPT ) {
		// Load the minified, non-inline version of the webp rewrite script.
		add_action( 'wp_enqueue_scripts', 'ewww_image_optimizer_webp_min_script' );
	} else {
		// Loads jQuery and the minified inline webp rewrite script.
		add_action( 'wp_enqueue_scripts', 'ewww_image_optimizer_webp_load_jquery' );
		if ( ! function_exists( 'wp_add_inline_script' ) ) {
			add_action( 'wp_head', 'ewww_image_optimizer_webp_inline_script' );
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'classes/class-ewwwio-cli.php' );
}

/**
 * Skips optimization when a file is within EWWW IO's own folder.
 *
 * @param bool   $skip Defaults to false.
 * @param string $filename The name of the file about to be optimized.
 * @return bool True if the file is within the EWWW IO folder, unaltered otherwise.
 */
function ewww_image_optimizer_ignore_self( $skip, $filename ) {
	if ( 0 === strpos( $filename, EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH ) ) {
		return true;
	}
	return $skip;
}

/**
 * Gets the version number from a plugin file.
 *
 * @param string $plugin_file The path to the plugin's main file.
 * @return array With a single index, 'Version'.
 */
function ewww_image_optimizer_get_plugin_version( $plugin_file ) {
	$default_headers = array(
		'Version' => 'Version',
	);
	$plugin_data = get_file_data( $plugin_file, $default_headers, 'plugin' );
	return $plugin_data;
}

/**
 * Checks to see if the WebP option from the Cache Enabler plugin is enabled.
 *
 * @return bool True if the WebP option for CE is enabled.
 */
function ewww_image_optimizer_ce_webp_enabled() {
	if ( class_exists( 'Cache_Enabler' ) ) {
		$ce_options = Cache_Enabler::$options;
		if ( $ce_options['webp'] ) {
			ewwwio_debug_message( 'Cache Enabler webp option enabled' );
			return true;
		}
	}
	return false;
}

/**
 * Starts an output buffer and registers the callback function to do WebP replacement. functions to capture all page output, replace image urls with webp derivatives, and add webp fallback
 */
function ewww_image_optimizer_buffer_start() {
	ob_start( 'ewww_image_optimizer_filter_page_output' );
}

/**
 * Copies attributes from the original img element to the noscript element.
 *
 * @param object $image A DOMElement (extension of DOMNode) representing an img element.
 * @param object $nscript Passed by reference, a DOMElement that will be given all the (known) attributes of $image.
 * @param string $prefix Optional. Value to prepend to all attribute names. Default 'data-'.
 */
function ewww_image_optimizer_webp_attr_copy( $image, &$nscript, $prefix = 'data-' ) {
	if ( ! is_object( $image ) || ! is_object( $nscript ) ) {
		return;
	}
	$attributes = array(
		'align',
		'alt',
		'border',
		'crossorigin',
		'height',
		'hspace',
		'ismap',
		'longdesc',
		'usemap',
		'vspace',
		'width',
		'accesskey',
		'class',
		'contenteditable',
		'contextmenu',
		'dir',
		'draggable',
		'dropzone',
		'hidden',
		'id',
		'lang',
		'spellcheck',
		'style',
		'tabindex',
		'title',
		'translate',
		'sizes',
		'data-lazy-type',
		'data-attachment-id',
		'data-permalink',
		'data-orig-size',
		'data-comments-opened',
		'data-image-meta',
		'data-image-title',
		'data-image-description',
	);
	foreach ( $attributes as $attribute ) {
		if ( $image->getAttribute( $attribute ) ) {
			$nscript->setAttribute( $prefix . $attribute, $image->getAttribute( $attribute ) );
		}
	}
}

/**
 * Replaces images within a srcset attribute with their .webp derivatives.
 *
 * @param string $srcset A valid srcset attribute from an img element.
 * @param string $home_url The 'home' url for the WordPress site.
 * @param bool   $valid_path True if a CDN path has been specified and matches the img element.
 * @return bool|string False if no changes were made, or the new srcset if any WebP images replaced the originals.
 */
function ewww_image_optimizer_webp_srcset_replace( $srcset, $home_url, $valid_path ) {
	$srcset_urls = explode( ' ', $srcset );
	$found_webp = false;
	if ( ewww_image_optimizer_iterable( $srcset_urls ) ) {
		ewwwio_debug_message( 'found srcset urls' );
		foreach ( $srcset_urls as $srcurl ) {
			if ( is_numeric( substr( $srcurl, 0, 1 ) ) ) {
				continue;
			}
			$srcfilepath = ABSPATH . str_replace( $home_url, '', $srcurl );
			ewwwio_debug_message( "looking for srcurl on disk: $srcfilepath" );
			if ( $valid_path || is_file( $srcfilepath . '.webp' ) ) {
				$srcset = preg_replace( "|$srcurl|", $srcurl . '.webp', $srcset );
				$found_webp = true;
				ewwwio_debug_message( "replacing $srcurl in $srcset" );
			}
		}
	}
	if ( $found_webp ) {
		return $srcset;
	} else {
		return false;
	}
}

/**
 * Replaces images with the Jetpack data attributes with their .webp derivatives.
 *
 * @param object $image A DOMElement (extension of DOMNode) representing an img element.
 * @param object $nscript Passed by reference, a DOMElement that will be assigned the jetpack data attributes.
 * @param string $home_url The 'home' url for the WordPress site.
 * @param bool   $valid_path True if a CDN path has been specified and matches the img element.
 */
function ewww_image_optimizer_webp_jetpack_replace( $image, &$nscript, $home_url, $valid_path ) {
	if ( $image->getAttribute( 'data-orig-file' ) ) {
		$origfilepath = ABSPATH . str_replace( $home_url, '', $image->getAttribute( 'data-orig-file' ) );
		ewwwio_debug_message( "looking for data-orig-file on disk: $origfilepath" );
		if ( $valid_path || is_file( $origfilepath . '.webp' ) ) {
			$nscript->setAttribute( 'data-webp-orig-file', $image->getAttribute( 'data-orig-file' ) . '.webp' );
			ewwwio_debug_message( "replacing $origfilepath in data-orig-file" );
		}
	}
	if ( $image->getAttribute( 'data-medium-file' ) ) {
		$mediumfilepath = ABSPATH . str_replace( $home_url, '', $image->getAttribute( 'data-medium-file' ) );
		ewwwio_debug_message( "looking for data-medium-file on disk: $mediumfilepath" );
		if ( $valid_path || is_file( $mediumfilepath . '.webp' ) ) {
			$nscript->setAttribute( 'data-webp-medium-file', $image->getAttribute( 'data-medium-file' ) . '.webp' );
			ewwwio_debug_message( "replacing $mediumfilepath in data-medium-file" );
		}
	}
	if ( $image->getAttribute( 'data-large-file' ) ) {
		$largefilepath = ABSPATH . str_replace( $home_url, '', $image->getAttribute( 'data-large-file' ) );
		ewwwio_debug_message( "looking for data-large-file on disk: $largefilepath" );
		if ( $valid_path || is_file( $largefilepath . '.webp' ) ) {
			$nscript->setAttribute( 'data-webp-large-file', $image->getAttribute( 'data-large-file' ) . '.webp' );
			ewwwio_debug_message( "replacing $largefilepath in data-large-file" );
		}
	}
}

/**
 * Search for amp-img elements and rewrite them with fallback elements for WebP replacement.
 *
 * Any amp-img elements will be given the fallback attribute and wrapped inside another amp-img
 * element with a WebP src attribute if WebP derivatives exist.
 *
 * @param string $buffer The entire HTML page from the output buffer.
 * @return string The altered buffer containing the full page with WebP images inserted.
 */
function ewww_image_optimizer_filter_amp_webp( $buffer ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	// Modify buffer here, and then return the updated code.
	if ( class_exists( 'DOMDocument' ) ) {
		$expanded_head = false;
		$html = new DOMDocument;
		$libxml_previous_error_reporting = libxml_use_internal_errors( true );
		$html->formatOutput = false;
		$html->encoding = 'utf-8';
		if ( defined( 'LIBXML_VERSION' ) && LIBXML_VERSION < 20800 ) {
			ewwwio_debug_message( 'libxml version too old for amp: ' . LIBXML_VERSION );
			return $buffer;
		} elseif ( ! defined( 'LIBXML_VERSION' ) ) {
			// When libxml is too old, we dare not modify the buffer.
			ewwwio_debug_message( 'cannot detect libxml version for amp' );
			return $buffer;
		}
		$html->loadHTML( $buffer );
		ewwwio_debug_message( 'parsing AMP as html' );
		$html->encoding = 'utf-8';
		$home_url = get_site_url();
		$webp_paths = ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_paths' );
		if ( ! is_array( $webp_paths ) ) {
			$webp_paths = array();
		}
		$home_relative_url = preg_replace( '/https?:/', '', $home_url );
		$images = $html->getElementsByTagName( 'amp-img' );
		if ( ewww_image_optimizer_iterable( $images ) ) {
			foreach ( $images as $image ) {
				if ( 'amp-img' == $image->parentNode->tagName ) {
					continue;
				}
				$srcset = '';
				ewwwio_debug_message( 'parsing an AMP image' );
				$file = $image->getAttribute( 'src' );
				$valid_path = false;
				if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_force' ) ) {
					// Check for CDN paths within the img src attribute.
					foreach ( $webp_paths as $webp_path ) {
						if ( strpos( $file, $webp_path ) !== false ) {
							$valid_path = true;
						}
					}
				}
				if ( strpos( $file, $home_relative_url ) === 0 ) {
					$filepath = str_replace( $home_relative_url, ABSPATH, $file );
				} else {
					$filepath = str_replace( $home_url, ABSPATH, $file );
				}
				ewwwio_debug_message( "the AMP image is at $filepath" );
				// If a CDN path match was found, or .webp image existsence is confirmed, and this is not a lazy-load 'dummy' image.
				if ( ( $valid_path || is_file( $filepath . '.webp' ) ) && ! strpos( $file, 'assets/images/dummy.png' ) && ! strpos( $file, 'base64,R0lGOD' ) && ! strpos( $file, 'lazy-load/images/1x1' ) ) {
					$parent_image = $image->cloneNode();
					$parent_image->setAttribute( 'src', $file . '.webp' );
					if ( $image->getAttribute( 'srcset' ) ) {
						$srcset = $image->getAttribute( 'srcset' );
						$srcset_webp = ewww_image_optimizer_webp_srcset_replace( $srcset, $home_url, $valid_path );
						if ( $srcset_webp ) {
							$parent_image->setAttribute( 'srcset', $srcset_webp );
						}
					}
					$fallback_attr = $html->createAttribute( 'fallback' );
					$image->appendChild( $fallback_attr );
					$image->parentNode->replaceChild( $parent_image, $image );
					$parent_image->appendChild( $image );
				}
			} // End foreach().
		} // End if().
		ewwwio_debug_message( 'preparing to dump page back to $buffer' );
		$buffer = $html->saveHTML( $html->documentElement );
		libxml_clear_errors();
		libxml_use_internal_errors( $libxml_previous_error_reporting );
		if ( false ) { // Set to true for extra debugging.
			ewwwio_debug_message( 'html head' );
			ewwwio_debug_message( $html_head[0] );
			ewwwio_debug_message( 'buffer beginning' );
			ewwwio_debug_message( substr( $buffer, 0, 500 ) );
		}
		if ( false ) { // Set to true for extra debugging.
			ewwwio_debug_message( 'buffer after replacement' );
			ewwwio_debug_message( substr( $buffer, 0, 500 ) );
		}
	} // End if().
	if ( false ) { // Set to true for extra logging.
		ewww_image_optimizer_debug_log();
	}
	return $buffer;
}

/**
 * Search for img elements and rewrite them with noscript elements for WebP replacement.
 *
 * Any img elements or elements that may be used in place of img elements by JS are checked to see
 * if WebP derivatives exist. The element is then wrapped within a noscript element for fallback,
 * and noscript element receives a copy of the attributes from the img along with webp replacement
 * values for those attributes.
 *
 * @param string $buffer The full HTML page generated since the output buffer was started.
 * @return string The altered buffer containing the full page with WebP images inserted.
 */
function ewww_image_optimizer_filter_page_output( $buffer ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	// If this is an admin page, don't filter it.
	if ( ( empty( $buffer ) || is_admin() ) ) {
		return $buffer;
	}
	// If Cache Enabler's WebP option is enabled, don't filter it.
	if ( ewww_image_optimizer_ce_webp_enabled() ) {
		return $buffer;
	}
	$uri = $_SERVER['REQUEST_URI'];
	// Based on the uri, if this is a cornerstone editing page, don't filter the response.
	if ( strpos( $uri, '&cornerstone=1' ) || strpos( $uri, 'cornerstone-endpoint' ) !== false ) {
		return $buffer;
	}
	if ( ! empty( $_GET['et_fb'] ) ) {
		return $buffer;
	}
	// Modify buffer here, and then return the updated code.
	if ( class_exists( 'DOMDocument' ) ) {
		// If this is XML (not XHTML), don't modify the page.
		if ( preg_match( '/<\?xml/', $buffer ) ) {
			return $buffer;
		}
		$expanded_head = false;
		preg_match( '/.+<head ?\>/s', $buffer, $html_head );
		if ( empty( $html_head ) ) {
			ewwwio_debug_message( 'did not find head tag' );
			preg_match( '/.+<head [^>]*>/s', $buffer, $html_head );
			if ( empty( $html_head ) ) {
				ewwwio_debug_message( 'did not find expanded head tag either' );
				return $buffer;
			}
		}
		if ( strpos( $buffer, 'amp-boilerplate' ) ) {
			ewwwio_debug_message( 'AMP page processing' );
			return $buffer;
		}
		$html = new DOMDocument;
		$libxml_previous_error_reporting = libxml_use_internal_errors( true );
		$html->formatOutput = false;
		$html->encoding = 'utf-8';
		if ( defined( 'LIBXML_VERSION' ) && LIBXML_VERSION < 20800 ) {
			ewwwio_debug_message( 'libxml version: ' . LIBXML_VERSION );
			// Converts the buffer from utf-8 to html-entities.
			$buffer = mb_convert_encoding( $buffer, 'HTML-ENTITIES', 'UTF-8' );
		} elseif ( ! defined( 'LIBXML_VERSION' ) ) {
			// When libxml is too old, we dare not modify the buffer.
			ewwwio_debug_message( 'cannot detect libxml version' );
			return $buffer;
		}
		if ( preg_match( '/<.DOCTYPE.+xhtml/', $buffer ) ) {
			$html->recover = true;
			$xhtml_parse = $html->loadXML( $buffer );
			ewwwio_debug_message( 'parsing as xhtml' );
		} elseif ( empty( $xhtml_parse ) ) {
			$html->loadHTML( $buffer );
			ewwwio_debug_message( 'parsing as html' );
		}
		$html->encoding = 'utf-8';
		$home_url = get_site_url();
		$webp_paths = ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_paths' );
		if ( ! is_array( $webp_paths ) ) {
			$webp_paths = array();
		}
		$home_relative_url = preg_replace( '/https?:/', '', $home_url );
		$images = $html->getElementsByTagName( 'img' );
		if ( ewww_image_optimizer_iterable( $images ) ) {
			foreach ( $images as $image ) {
				if ( 'noscript' == $image->parentNode->tagName ) {
					continue;
				}
				$srcset = '';
				ewwwio_debug_message( 'parsing an image' );
				$file = $image->getAttribute( 'src' );
				$valid_path = false;
				if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_force' ) ) {
					// Check for CDN paths within the img src attribute.
					foreach ( $webp_paths as $webp_path ) {
						if ( strpos( $file, $webp_path ) !== false ) {
							$valid_path = true;
						}
					}
				}
				if ( strpos( $file, $home_relative_url ) === 0 ) {
					$filepath = str_replace( $home_relative_url, ABSPATH, $file );
				} else {
					$filepath = str_replace( $home_url, ABSPATH, $file );
				}
				ewwwio_debug_message( "the image is at $filepath" );
				// If a CDN path match was found, or .webp image existsence is confirmed, and this is not a lazy-load 'dummy' image.
				if ( ( $valid_path || is_file( $filepath . '.webp' ) ) && ! strpos( $file, 'assets/images/dummy.png' ) && ! strpos( $file, 'base64,R0lGOD' ) && ! strpos( $file, 'lazy-load/images/1x1' ) ) {
					$nscript = $html->createElement( 'noscript' );
					$nscript->setAttribute( 'data-img', $file );
					$nscript->setAttribute( 'data-webp', $file . '.webp' );
					if ( $image->getAttribute( 'srcset' ) ) {
						$srcset = $image->getAttribute( 'srcset' );
						$srcset_webp = ewww_image_optimizer_webp_srcset_replace( $srcset, $home_url, $valid_path );
						if ( $srcset_webp ) {
							$nscript->setAttribute( 'data-srcset-webp', $srcset_webp );
						}
						$nscript->setAttribute( 'data-srcset-img', $srcset );
					}
					if ( $image->getAttribute( 'data-orig-file' ) && $image->getAttribute( 'data-medium-file' ) && $image->getAttribute( 'data-large-file' ) ) {
						ewww_image_optimizer_webp_jetpack_replace( $image, $nscript, $home_url, $valid_path );
					}
					ewww_image_optimizer_webp_attr_copy( $image, $nscript );
					$nscript->setAttribute( 'class', 'ewww_webp' );
					$image->parentNode->replaceChild( $nscript, $image );
					$nscript->appendChild( $image );
				}
				// Look for NextGEN attributes that need to be altered.
				if ( empty( $file ) && $image->getAttribute( 'data-src' ) && $image->getAttribute( 'data-thumbnail' ) ) {
					$file = $image->getAttribute( 'data-src' );
					$thumb = $image->getAttribute( 'data-thumbnail' );
					ewwwio_debug_message( "checking webp for ngg data-src: $file" );
					$filepath = ABSPATH . str_replace( $home_url, '', $file );
					ewwwio_debug_message( "checking webp for ngg data-thumbnail: $thumb" );
					$thumbpath = ABSPATH . str_replace( $home_url, '', $thumb );
					if ( ! $valid_path && ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_force' ) ) {
						// Check the image for configured CDN paths.
						foreach ( $webp_paths as $webp_path ) {
							if ( strpos( $file, $webp_path ) !== false ) {
								$valid_path = true;
							}
						}
					}
					if ( $valid_path || is_file( $filepath . '.webp' ) ) {
						ewwwio_debug_message( "found webp for ngg data-src: $filepath" );
						$image->setAttribute( 'data-webp', $file . '.webp' );
					}
					if ( $valid_path || is_file( $thumbpath . '.webp' ) ) {
						ewwwio_debug_message( "found webp for ngg data-thumbnail: $thumbpath" );
						$image->setAttribute( 'data-webp-thumbnail', $thumb . '.webp' );
					}
				}
				// NOTE: lazy loads are shutoff for now, since they don't work consistently
				// WP Retina 2x lazy loads.
				if ( false && empty( $file ) && $image->getAttribute( 'data-srcset' ) && strpos( $image->getAttribute( 'class' ), 'lazyload' ) ) {
					$srcset = $image->getAttribute( 'data-srcset' );
					if ( ! $valid_path && ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_force' ) ) {
						// Check the image for configured CDN paths.
						foreach ( $webp_paths as $webp_path ) {
							if ( strpos( $srcset, $webp_path ) !== false ) {
								$valid_path = true;
							}
						}
					}
					$srcset_webp = ewww_image_optimizer_webp_srcset_replace( $srcset, $home_url, $valid_path );
					if ( $srcset_webp ) {
						$nimage = $html->createElement( 'img' );
						$nimage->setAttribute( 'data-srcset-webp', $srcset_webp );
						$nimage->setAttribute( 'data-srcset-img', $srcset );
						ewww_image_optimizer_webp_attr_copy( $image, $nimage, '' );
						$nimage->setAttribute( 'class', $image->getAttribute( 'class' ) . ' ewww_webp_lazy_retina' );
						$image->parentNode->replaceChild( $nimage, $image );
					}
				}
				// Hueman theme lazy-loads.
				if ( false && ! empty( $file ) && strpos( $file, 'image/gif;base64,R0lGOD' ) && $image->getAttribute( 'data-src' ) && $image->getAttribute( 'data-srcset' ) ) {
					$dummy = $file;
					$file = $image->getAttribute( 'data-src' );
					ewwwio_debug_message( "checking webp for hueman data-src: $file" );
					$filepath = ABSPATH . str_replace( $home_url, '', $file );
					if ( ! $valid_path && ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_force' ) ) {
						// Check the image for configured CDN paths.
						foreach ( $webp_paths as $webp_path ) {
							if ( strpos( $file, $webp_path ) !== false ) {
								$valid_path = true;
							}
						}
					}
					if ( $valid_path || is_file( $filepath . '.webp' ) ) {
						ewwwio_debug_message( "found webp for Hueman lazyload: $filepath" );
						$nscript = $html->createElement( 'noscript' );
						$nscript->setAttribute( 'data-src', $dummy );
						$nscript->setAttribute( 'data-img', $file );
						$nscript->setAttribute( 'data-webp-src', $file . '.webp' );
						$image->setAttribute( 'src', $file );
						if ( $image->getAttribute( 'data-srcset' ) ) {
							$srcset = $image->getAttribute( 'data-srcset' );
							$srcset_webp = ewww_image_optimizer_webp_srcset_replace( $srcset, $home_url, $valid_path );
							if ( $srcset_webp ) {
								$nscript->setAttribute( 'data-srcset-webp', $srcset_webp );
							}
							$nscript->setAttribute( 'data-srcset-img', $srcset );
						}
						ewww_image_optimizer_webp_attr_copy( $image, $nscript );
						$nscript->setAttribute( 'class', 'ewww_webp_lazy_hueman' );
						$image->parentNode->replaceChild( $nscript, $image );
						$nscript->appendChild( $image );
					}
				}
				// Lazy Load plugin (and hopefully Cherry variant) and BJ Lazy Load.
				if ( false && ! empty( $file ) && ( strpos( $file, 'image/gif;base64,R0lGOD' ) || strpos( $file, 'lazy-load/images/1x1' ) ) && $image->getAttribute( 'data-lazy-src' ) && ! empty( $image->nextSibling ) && 'noscript' == $image->nextSibling->nodeName ) {
					$dummy = $file;
					$nimage = $html->createElement( 'img' );
					$nimage->setAttribute( 'src', $dummy );
					$file = $image->getAttribute( 'data-lazy-src' );
					ewwwio_debug_message( "checking webp for Lazy Load data-lazy-src: $file" );
					$filepath = ABSPATH . str_replace( $home_url, '', $file );
					if ( ! $valid_path && ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_force' ) ) {
						// Check the image for configured CDN paths.
						foreach ( $webp_paths as $webp_path ) {
							if ( strpos( $file, $webp_path ) !== false ) {
								$valid_path = true;
							}
						}
					}
					if ( $valid_path || is_file( $filepath . '.webp' ) ) {
						ewwwio_debug_message( "found webp for Lazy Load: $filepath" );
						$nimage->setAttribute( 'data-lazy-img-src', $file );
						$nimage->setAttribute( 'data-lazy-webp-src', $file . '.webp' );
						if ( $image->getAttribute( 'srcset' ) ) {
							$srcset = $image->getAttribute( 'srcset' );
							$srcset_webp = ewww_image_optimizer_webp_srcset_replace( $srcset, $home_url, $valid_path );
							if ( $srcset_webp ) {
								$nimage->setAttribute( 'data-srcset-webp', $srcset_webp );
							}
							$nimage->setAttribute( 'data-srcset', $srcset );
						}
						if ( $image->getAttribute( 'data-lazy-srcset' ) ) {
							$srcset = $image->getAttribute( 'data-lazy-srcset' );
							$srcset_webp = ewww_image_optimizer_webp_srcset_replace( $srcset, $home_url, $valid_path );
							if ( $srcset_webp ) {
								$nimage->setAttribute( 'data-lazy-srcset-webp', $srcset_webp );
							}
							$nimage->setAttribute( 'data-lazy-srcset-img', $srcset );
						}
						ewww_image_optimizer_webp_attr_copy( $image, $nimage, '' );
						$nimage->setAttribute( 'class', $image->getAttribute( 'class' ) . ' ewww_webp_lazy_load' );
						$image->parentNode->replaceChild( $nimage, $image );
					}
				} // End if().
				if ( $image->getAttribute( 'data-lazyload' ) ) {
					$lazyload = $image->getAttribute( 'data-lazyload' );
					if ( ! empty( $lazyload ) ) {
						$lazyloadpath = ABSPATH . str_replace( $home_url, '', $lazyload );
						if ( ! $valid_path && ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_force' ) ) {
							// Check the image for configured CDN paths.
							foreach ( $webp_paths as $webp_path ) {
								if ( strpos( $lazyload, $webp_path ) !== false ) {
									$valid_path = true;
								}
							}
						}
						if ( $valid_path || is_file( $lazyloadpath . '.webp' ) ) {
							ewwwio_debug_message( "found webp for data-lazyload: $filepath" );
							$image->setAttribute( 'data-webp-lazyload', $lazyload . '.webp' );
						}
					}
				}
			} // End foreach().
		} // End if().
		// NextGEN slides listed as 'a' elements.
		$links = $html->getElementsByTagName( 'a' );
		if ( ewww_image_optimizer_iterable( $links ) ) {
			foreach ( $links as $link ) {
				ewwwio_debug_message( 'parsing a link' );
				if ( $link->getAttribute( 'data-src' ) && $link->getAttribute( 'data-thumbnail' ) ) {
					$file = $link->getAttribute( 'data-src' );
					$thumb = $link->getAttribute( 'data-thumbnail' );
					$valid_path = false;
					if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_force' ) ) {
						// Check the image for configured CDN paths.
						foreach ( $webp_paths as $webp_path ) {
							if ( strpos( $file, $webp_path ) !== false ) {
								$valid_path = true;
							}
						}
					}
					ewwwio_debug_message( "checking webp for ngg data-src: $file" );
					$filepath = ABSPATH . str_replace( $home_url, '', $file );
					ewwwio_debug_message( "checking webp for ngg data-thumbnail: $thumb" );
					$thumbpath = ABSPATH . str_replace( $home_url, '', $thumb );
					if ( $valid_path || is_file( $filepath . '.webp' ) ) {
						ewwwio_debug_message( "found webp for ngg data-src: $filepath" );
						$link->setAttribute( 'data-webp', $file . '.webp' );
					}
					if ( $valid_path || is_file( $thumbpath . '.webp' ) ) {
						ewwwio_debug_message( "found webp for ngg data-thumbnail: $thumbpath" );
						$link->setAttribute( 'data-webp-thumbnail', $thumb . '.webp' );
					}
				}
			}
		}
		// Revolution Slider 'li' elements.
		$listitems = $html->getElementsByTagName( 'li' );
		if ( ewww_image_optimizer_iterable( $listitems ) ) {
			foreach ( $listitems as $listitem ) {
				ewwwio_debug_message( 'parsing a listitem' );
				if ( $listitem->getAttribute( 'data-title' ) === 'Slide' && ( $listitem->getAttribute( 'data-lazyload' ) || $listitem->getAttribute( 'data-thumb' ) ) ) {
					$thumb = $listitem->getAttribute( 'data-thumb' );
					$valid_path = false;
					if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_force' ) ) {
						// Check the image for configured CDN paths.
						foreach ( $webp_paths as $webp_path ) {
							if ( strpos( $thumb, $webp_path ) !== false ) {
								$valid_path = true;
							}
						}
					}
					ewwwio_debug_message( "checking webp for revslider data-thumb: $thumb" );
					$thumbpath = str_replace( $home_url, ABSPATH, $thumb );
					if ( $valid_path || is_file( $thumbpath . '.webp' ) ) {
						ewwwio_debug_message( "found webp for revslider data-thumb: $thumbpath" );
						$listitem->setAttribute( 'data-webp-thumb', $thumb . '.webp' );
					}
					$param_num = 1;
					while ( $param_num < 11 ) {
						$parameter = '';
						if ( $listitem->getAttribute( 'data-param' . $param_num ) ) {
							$parameter = $listitem->getAttribute( 'data-param' . $param_num );
							ewwwio_debug_message( "checking webp for revslider data-param$param_num: $parameter" );
							if ( ! empty( $parameter ) && strpos( $parameter, 'http' ) === 0 ) {
								$parameter_path = str_replace( $home_url, ABSPATH, $parameter );
								ewwwio_debug_message( "looking for $parameter_path" );
								if ( $valid_path || is_file( $parameter_path . '.webp' ) ) {
									ewwwio_debug_message( "found webp for data-param$param_num: $parameter_path" );
									$listitem->setAttribute( 'data-webp-param' . $param_num, $parameter . '.webp' );
								}
							}
						}
						$param_num++;
					}
				}
			} // End foreach().
		} // End if().
		// Video elements, looking for poster attributes that are images.
		$videos = $html->getElementsByTagName( 'video' );
		if ( ewww_image_optimizer_iterable( $videos ) ) {
			foreach ( $videos as $video ) {
				ewwwio_debug_message( 'parsing a video element' );
				if ( $link->getAttribute( 'poster' ) ) {
					$file = $link->getAttribute( 'poster' );
					$valid_path = false;
					if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_force' ) ) {
						// Check the image for configured CDN paths.
						foreach ( $webp_paths as $webp_path ) {
							if ( strpos( $file, $webp_path ) !== false ) {
								$valid_path = true;
							}
						}
					}
					ewwwio_debug_message( "checking webp for video poster: $file" );
					$filepath = ABSPATH . str_replace( $home_url, '', $file );
					if ( $valid_path || is_file( $filepath . '.webp' ) ) {
						ewwwio_debug_message( "found webp for video poster: $filepath" );
						$video->setAttribute( 'data-poster-webp', $file . '.webp' );
						$video->setAttribute( 'data-poster-image', $file );
						$video->removeAttribute( 'poster' );
					}
				}
			}
		}
		ewwwio_debug_message( 'preparing to dump page back to $buffer' );
		if ( ! empty( $xhtml_parse ) ) {
			$buffer = $html->saveXML( $html->documentElement );
		} else {
			$buffer = $html->saveHTML( $html->documentElement );
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $libxml_previous_error_reporting );
		if ( false ) { // Set to true for extra debugging.
			ewwwio_debug_message( 'html head' );
			ewwwio_debug_message( $html_head[0] );
			ewwwio_debug_message( 'buffer beginning' );
			ewwwio_debug_message( substr( $buffer, 0, 500 ) );
		}
		if ( ! empty( $html_head ) ) {
			$buffer = preg_replace( '/<html.+>\s.*<head>/', $html_head[0], $buffer );
		}
		// Do some cleanup for the Easy Social Share Buttons for WordPress plugin (can't have <li> elements with newlines between them).
		$buffer = preg_replace( '/\s(<li class="essb_item)/', '$1', $buffer );
		if ( false ) { // Set to true for extra debugging.
			ewwwio_debug_message( 'buffer after replacement' );
			ewwwio_debug_message( substr( $buffer, 0, 500 ) );
		}
	} // End if().
	if ( false ) { // Set to true for extra logging.
		ewww_image_optimizer_debug_log();
	}
	return $buffer;
}

/**
 * Set default permissions for manual operations (single image optimize, convert, restore).
 *
 * @param string $permissions A valid WP capability level.
 * @return string Either the original value, unchanged, or the default capability level.
 */
function ewww_image_optimizer_manual_permissions( $permissions ) {
	if ( empty( $permissions ) ) {
		return 'edit_others_posts';
	}
	return $permissions;
}

/**
 * Set default permissions for admin (configuration) and bulk operations.
 *
 * @param string $permissions A valid WP capability level.
 * @return string Either the original value, unchanged, or the default capability level.
 */
function ewww_image_optimizer_admin_permissions( $permissions ) {
	if ( empty( $permissions ) ) {
		return 'activate_plugins';
	}
	return $permissions;
}

/**
 * Set default permissions for multisite/network admin (configuration) operations.
 *
 * @param string $permissions A valid WP capability level.
 * @return string Either the original value, unchanged, or the default capability level.
 */
function ewww_image_optimizer_superadmin_permissions( $permissions ) {
	if ( empty( $permissions ) ) {
		return 'manage_network_options';
	}
	return $permissions;
}

if ( ! function_exists( 'boolval' ) ) {
	/**
	 * Cast a value to boolean.
	 *
	 * @param mixed $value Any value that can be cast to boolean.
	 * @return bool The boolean version of the provided value.
	 */
	function boolval( $value ) {
		return (bool) $value;
	}
}

/**
 * Find out if set_time_limit() is allowed
 */
function ewww_image_optimizer_stl_check() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( ewww_image_optimizer_safemode_check() ) {
		return false;
	}
	if ( defined( 'EWWW_IMAGE_OPTIMIZER_DISABLE_STL' ) && EWWW_IMAGE_OPTIMIZER_DISABLE_STL ) {
		ewwwio_debug_message( 'stl disabled by user' );
		return false;
	}
	return ewww_image_optimizer_function_exists( 'set_time_limit' );
}

/**
 * Checks if a function is disabled or does not exist.
 *
 * @param string $function The name of a function to test.
 * @return bool True if the function is available, False if not.
 */
function ewww_image_optimizer_function_exists( $function ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( function_exists( 'ini_get' ) ) {
		$disabled = @ini_get( 'disable_functions' );
		ewwwio_debug_message( "disable_functions: $disabled" );
	}
	if ( extension_loaded( 'suhosin' ) && function_exists( 'ini_get' ) ) {
		$suhosin_disabled = @ini_get( 'suhosin.executor.func.blacklist' );
		ewwwio_debug_message( "suhosin_blacklist: $suhosin_disabled" );
		if ( ! empty( $suhosin_disabled ) ) {
			$suhosin_disabled = explode( ',', $suhosin_disabled );
			$suhosin_disabled = array_map( 'trim', $suhosin_disabled );
			$suhosin_disabled = array_map( 'strtolower', $suhosin_disabled );
			if ( function_exists( $function ) && ! in_array( $function, $suhosin_disabled ) ) {
				return true;
			}
			return false;
		}
	}
	return function_exists( $function );
}

/**
 * Runs on 'plugins_loaded' to make make sure the language files are loaded early.
 */
function ewww_image_optimizer_preinit() {
	load_plugin_textdomain( 'ewww-image-optimizer', false, EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'languages/' );
}

/**
 * Checks to see if NextGEN, Nextcellent, or GRAND FlaGallery are enabled.
 *
 * If a supported gallery is found, loads the class(es) to support integration with that gallery.
 */
function ewww_image_optimizer_gallery_support() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	$active_plugins = get_option( 'active_plugins' );
	if ( is_multisite() && is_array( $active_plugins ) ) {
		$sitewide_plugins = get_site_option( 'active_sitewide_plugins' );
		if ( is_array( $sitewide_plugins ) ) {
			$active_plugins = array_merge( $active_plugins, array_flip( $sitewide_plugins ) );
		}
	}
	if ( ewww_image_optimizer_iterable( $active_plugins ) ) {
		foreach ( $active_plugins as $active_plugin ) {
			if ( strpos( $active_plugin, '/nggallery.php' ) || strpos( $active_plugin, '\nggallery.php' ) ) {
				$ngg = ewww_image_optimizer_get_plugin_version( trailingslashit( WP_PLUGIN_DIR ) . $active_plugin );
				// Include the file that loads the nextgen gallery optimization functions.
				ewwwio_debug_message( 'Nextgen version: ' . $ngg['Version'] );
				if ( preg_match( '/^2\./', $ngg['Version'] ) ) { // For Nextgen 2 support.
					ewwwio_debug_message( "loading nextgen2 support for $active_plugin" );
					require_once( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'classes/class-ewww-nextgen.php' );
				} else {
					preg_match( '/\d+\.\d+\.(\d+)/', $ngg['Version'], $nextgen_minor_version );
					if ( ! empty( $nextgen_minor_version[1] ) && $nextgen_minor_version[1] < 14 ) {
						ewwwio_debug_message( "NOT loading nextgen legacy support for $active_plugin" );
					} elseif ( ! empty( $nextgen_minor_version[1] ) && $nextgen_minor_version[1] > 13 ) {
						ewwwio_debug_message( "loading nextcellent support for $active_plugin" );
						require_once( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'classes/class-ewww-nextcellent.php' );
					}
				}
			}
			if ( strpos( $active_plugin, '/flag.php' ) || strpos( $active_plugin, '\flag.php' ) ) {
				ewwwio_debug_message( "loading flagallery support for $active_plugin" );
				// Include the file that loads the grand flagallery optimization functions.
				require_once( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'classes/class-ewww-flag.php' );
			}
		}
	}
}

/**
 * Plugin upgrade function
 *
 * @global object $wpdb
 */
function ewww_image_optimizer_upgrade() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	ewwwio_memory( __FUNCTION__ );
	if ( get_option( 'ewww_image_optimizer_version' ) < EWWW_IMAGE_OPTIMIZER_VERSION ) {
		if ( wp_doing_ajax() ) {
			return;
		}
		ewww_image_optimizer_enable_background_optimization();
		ewww_image_optimizer_install_table();
		ewww_image_optimizer_set_defaults();
		if ( get_option( 'ewww_image_optimizer_version' ) < 297.5 ) {
			// Cleanup background test mess.
			wp_clear_scheduled_hook( 'wp_ewwwio_test_optimize_cron' );
			global $wpdb;

			if ( is_multisite() ) {
				$wpdb->query( "DELETE FROM $wpdb->sitemeta WHERE meta_key LIKE 'wp_ewwwio_test_optimize_batch_%'" );
			}

			$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'wp_ewwwio_test_optimize_batch_%'" );

		}
		if ( get_option( 'ewww_image_optimizer_version' ) < 280 ) {
			ewww_image_optimizer_migrate_settings_to_levels();
		}
		ewww_image_optimizer_remove_obsolete_settings();
		update_option( 'ewww_image_optimizer_version', EWWW_IMAGE_OPTIMIZER_VERSION );
	}
	ewwwio_memory( __FUNCTION__ );
}

/**
 * Tests background optimization.
 *
 * Send a known packet to admin-ajax.php via the EWWWIO_Test_Async_Handler class.
 *
 * @global object An instance of the test async class.
 */
function ewww_image_optimizer_enable_background_optimization() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( ewww_image_optimizer_detect_wpsf_location_lock() ) {
		return;
	}
	global $ewwwio_test_async;
	if ( ! class_exists( 'WP_Background_Process' ) ) {
		require_once( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'background.php' );
	}
	if ( ! is_object( $ewwwio_test_async ) ) {
		$ewwwio_test_async = new EWWWIO_Test_Async_Handler();
	}
	ewww_image_optimizer_set_option( 'ewww_image_optimizer_background_optimization', false );
	ewwwio_debug_message( 'running test async handler' );
	$ewwwio_test_async->data( array(
		'ewwwio_test_verify' => '949c34123cf2a4e4ce2f985135830df4a1b2adc24905f53d2fd3f5df5b162932',
	) )->dispatch();
	ewww_image_optimizer_debug_log();
}

/**
 * Plugin initialization for admin area.
 *
 * Saves settings when run network-wide, registers all 'common' settings, schedules wp-cron tasks,
 * includes necessary files for bulk operations, runs tool initialization, and ensures
 * compatibility with AJAX calls from other media generation plugins.
 */
function ewww_image_optimizer_admin_init() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	ewwwio_memory( __FUNCTION__ );
	ewww_image_optimizer_cloud_init();
	ewww_image_optimizer_upgrade();
	if ( ! function_exists( 'is_plugin_active_for_network' ) && is_multisite() ) {
		// Need to include the plugin library for the is_plugin_active function.
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	}
	if ( is_multisite() && is_plugin_active_for_network( EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE_REL ) ) {
		// Set the common network settings if they have been POSTed.
		if ( isset( $_POST['ewww_image_optimizer_delay'] ) && current_user_can( 'manage_network_options' ) && ! get_site_option( 'ewww_image_optimizer_allow_multisite_override' ) && wp_verify_nonce( $_REQUEST['_wpnonce'], 'ewww_image_optimizer_options-options' ) ) {
			if ( ewww_image_optimizer_function_exists( 'print_r' ) ) {
				ewwwio_debug_message( print_r( $_POST, true ) );
			}
			$_POST['ewww_image_optimizer_debug'] = ( empty( $_POST['ewww_image_optimizer_debug'] ) ? false : true );
			update_site_option( 'ewww_image_optimizer_debug', $_POST['ewww_image_optimizer_debug'] );
			$_POST['ewww_image_optimizer_jpegtran_copy'] = ( empty( $_POST['ewww_image_optimizer_jpegtran_copy'] ) ? false : true );
			update_site_option( 'ewww_image_optimizer_jpegtran_copy', $_POST['ewww_image_optimizer_jpegtran_copy'] );
			$_POST['ewww_image_optimizer_jpg_level'] = empty( $_POST['ewww_image_optimizer_jpg_level'] ) ? '' : $_POST['ewww_image_optimizer_jpg_level'];
			update_site_option( 'ewww_image_optimizer_jpg_level', (int) $_POST['ewww_image_optimizer_jpg_level'] );
			$_POST['ewww_image_optimizer_png_level'] = empty( $_POST['ewww_image_optimizer_png_level'] ) ? '' : $_POST['ewww_image_optimizer_png_level'];
			update_site_option( 'ewww_image_optimizer_png_level', (int) $_POST['ewww_image_optimizer_png_level'] );
			$_POST['ewww_image_optimizer_gif_level'] = empty( $_POST['ewww_image_optimizer_gif_level'] ) ? '' : $_POST['ewww_image_optimizer_gif_level'];
			update_site_option( 'ewww_image_optimizer_gif_level', (int) $_POST['ewww_image_optimizer_gif_level'] );
			$_POST['ewww_image_optimizer_pdf_level'] = empty( $_POST['ewww_image_optimizer_pdf_level'] ) ? '' : $_POST['ewww_image_optimizer_pdf_level'];
			update_site_option( 'ewww_image_optimizer_pdf_level', (int) $_POST['ewww_image_optimizer_pdf_level'] );
			$_POST['ewww_image_optimizer_lossy_skip_full'] = ( empty( $_POST['ewww_image_optimizer_lossy_skip_full'] ) ? false : true );
			update_site_option( 'ewww_image_optimizer_lossy_skip_full', $_POST['ewww_image_optimizer_lossy_skip_full'] );
			$_POST['ewww_image_optimizer_metadata_skip_full'] = ( empty( $_POST['ewww_image_optimizer_metadata_skip_full'] ) ? false : true );
			update_site_option( 'ewww_image_optimizer_metadata_skip_full', $_POST['ewww_image_optimizer_metadata_skip_full'] );
			$_POST['ewww_image_optimizer_delete_originals'] = ( empty( $_POST['ewww_image_optimizer_delete_originals'] ) ? false : true );
			update_site_option( 'ewww_image_optimizer_delete_originals', $_POST['ewww_image_optimizer_delete_originals'] );
			$_POST['ewww_image_optimizer_jpg_to_png'] = ( empty( $_POST['ewww_image_optimizer_jpg_to_png'] ) ? false : true );
			update_site_option( 'ewww_image_optimizer_jpg_to_png', $_POST['ewww_image_optimizer_jpg_to_png'] );
			$_POST['ewww_image_optimizer_png_to_jpg'] = ( empty( $_POST['ewww_image_optimizer_png_to_jpg'] ) ? false : true );
			update_site_option( 'ewww_image_optimizer_png_to_jpg', $_POST['ewww_image_optimizer_png_to_jpg'] );
			$_POST['ewww_image_optimizer_gif_to_png'] = ( empty( $_POST['ewww_image_optimizer_gif_to_png'] ) ? false : true );
			update_site_option( 'ewww_image_optimizer_gif_to_png', $_POST['ewww_image_optimizer_gif_to_png'] );
			$_POST['ewww_image_optimizer_webp'] = ( empty( $_POST['ewww_image_optimizer_webp'] ) ? false : true );
			update_site_option( 'ewww_image_optimizer_webp', $_POST['ewww_image_optimizer_webp'] );
			$_POST['ewww_image_optimizer_jpg_background'] = empty( $_POST['ewww_image_optimizer_jpg_background'] ) ? '' : $_POST['ewww_image_optimizer_jpg_background'];
			update_site_option( 'ewww_image_optimizer_jpg_background', ewww_image_optimizer_jpg_background( $_POST['ewww_image_optimizer_jpg_background'] ) );
			$_POST['ewww_image_optimizer_jpg_quality'] = empty( $_POST['ewww_image_optimizer_jpg_quality'] ) ? '' : $_POST['ewww_image_optimizer_jpg_quality'];
			update_site_option( 'ewww_image_optimizer_jpg_quality', ewww_image_optimizer_jpg_quality( $_POST['ewww_image_optimizer_jpg_quality'] ) );
			$_POST['ewww_image_optimizer_disable_convert_links'] = ( empty( $_POST['ewww_image_optimizer_disable_convert_links'] ) ? false : true );
			update_site_option( 'ewww_image_optimizer_disable_convert_links', $_POST['ewww_image_optimizer_disable_convert_links'] );
			$_POST['ewww_image_optimizer_backup_files'] = ( empty( $_POST['ewww_image_optimizer_backup_files'] ) ? false : true );
			update_site_option( 'ewww_image_optimizer_backup_files', $_POST['ewww_image_optimizer_backup_files'] );
			$_POST['ewww_image_optimizer_cloud_key'] = empty( $_POST['ewww_image_optimizer_cloud_key'] ) ? '' : $_POST['ewww_image_optimizer_cloud_key'];
			update_site_option( 'ewww_image_optimizer_cloud_key', ewww_image_optimizer_cloud_key_sanitize( $_POST['ewww_image_optimizer_cloud_key'] ) );
			$_POST['ewww_image_optimizer_auto'] = ( empty( $_POST['ewww_image_optimizer_auto'] ) ? false : true );
			update_site_option( 'ewww_image_optimizer_auto', $_POST['ewww_image_optimizer_auto'] );
			$_POST['ewww_image_optimizer_aux_paths'] = empty( $_POST['ewww_image_optimizer_aux_paths'] ) ? '' : $_POST['ewww_image_optimizer_aux_paths'];
			update_site_option( 'ewww_image_optimizer_aux_paths', ewww_image_optimizer_aux_paths_sanitize( $_POST['ewww_image_optimizer_aux_paths'] ) );
			$_POST['ewww_image_optimizer_exclude_paths'] = empty( $_POST['ewww_image_optimizer_exclude_paths'] ) ? '' : $_POST['ewww_image_optimizer_exclude_paths'];
			update_site_option( 'ewww_image_optimizer_exclude_paths', ewww_image_optimizer_exclude_paths_sanitize( $_POST['ewww_image_optimizer_exclude_paths'] ) );
			$_POST['ewww_image_optimizer_enable_cloudinary'] = ( empty( $_POST['ewww_image_optimizer_enable_cloudinary'] ) ? false : true );
			update_site_option( 'ewww_image_optimizer_enable_cloudinary', $_POST['ewww_image_optimizer_enable_cloudinary'] );
			$_POST['ewww_image_optimizer_delay'] = empty( $_POST['ewww_image_optimizer_delay'] ) ? '' : $_POST['ewww_image_optimizer_delay'];
			update_site_option( 'ewww_image_optimizer_delay', (int) $_POST['ewww_image_optimizer_delay'] );
			$_POST['ewww_image_optimizer_maxmediawidth'] = empty( $_POST['ewww_image_optimizer_maxmediawidth'] ) ? 0 : $_POST['ewww_image_optimizer_maxmediawidth'];
			update_site_option( 'ewww_image_optimizer_maxmediawidth', (int) $_POST['ewww_image_optimizer_maxmediawidth'] );
			$_POST['ewww_image_optimizer_maxmediaheight'] = empty( $_POST['ewww_image_optimizer_maxmediaheight'] ) ? 0 : $_POST['ewww_image_optimizer_maxmediaheight'];
			update_site_option( 'ewww_image_optimizer_maxmediaheight', (int) $_POST['ewww_image_optimizer_maxmediaheight'] );
			$_POST['ewww_image_optimizer_maxotherwidth'] = empty( $_POST['ewww_image_optimizer_maxotherwidth'] ) ? 0 : $_POST['ewww_image_optimizer_maxotherwidth'];
			update_site_option( 'ewww_image_optimizer_maxotherwidth', (int) $_POST['ewww_image_optimizer_maxotherwidth'] );
			$_POST['ewww_image_optimizer_maxotherheight'] = empty( $_POST['ewww_image_optimizer_maxotherheight'] ) ? 0 : $_POST['ewww_image_optimizer_maxotherheight'];
			update_site_option( 'ewww_image_optimizer_maxotherheight', (int) $_POST['ewww_image_optimizer_maxotherheight'] );
			$_POST['ewww_image_optimizer_resize_detection'] = ( empty( $_POST['ewww_image_optimizer_resize_detection'] ) ? false : true );
			update_site_option( 'ewww_image_optimizer_resize_detection', $_POST['ewww_image_optimizer_resize_detection'] );
			$_POST['ewww_image_optimizer_resize_existing'] = ( empty( $_POST['ewww_image_optimizer_resize_existing'] ) ? false : true );
			update_site_option( 'ewww_image_optimizer_resize_existing', $_POST['ewww_image_optimizer_resize_existing'] );
			$_POST['ewww_image_optimizer_skip_size'] = empty( $_POST['ewww_image_optimizer_skip_size'] ) ? '' : $_POST['ewww_image_optimizer_skip_size'];
			update_site_option( 'ewww_image_optimizer_skip_size', (int) $_POST['ewww_image_optimizer_skip_size'] );
			$_POST['ewww_image_optimizer_skip_png_size'] = empty( $_POST['ewww_image_optimizer_skip_png_size'] ) ? '' : $_POST['ewww_image_optimizer_skip_png_size'];
			update_site_option( 'ewww_image_optimizer_skip_png_size', (int) $_POST['ewww_image_optimizer_skip_png_size'] );
			$_POST['ewww_image_optimizer_parallel_optimization'] = ( empty( $_POST['ewww_image_optimizer_parallel_optimization'] ) ? false : true );
			update_site_option( 'ewww_image_optimizer_parallel_optimization', $_POST['ewww_image_optimizer_parallel_optimization'] );
			$_POST['ewww_image_optimizer_include_media_paths'] = ( empty( $_POST['ewww_image_optimizer_include_media_paths'] ) ? false : true );
			update_site_option( 'ewww_image_optimizer_include_media_paths', $_POST['ewww_image_optimizer_include_media_paths'] );
			$_POST['ewww_image_optimizer_webp_for_cdn'] = ( empty( $_POST['ewww_image_optimizer_webp_for_cdn'] ) ? false : true );
			update_site_option( 'ewww_image_optimizer_webp_for_cdn', $_POST['ewww_image_optimizer_webp_for_cdn'] );
			$_POST['ewww_image_optimizer_webp_force'] = ( empty( $_POST['ewww_image_optimizer_webp_force'] ) ? false : true );
			update_site_option( 'ewww_image_optimizer_webp_force', $_POST['ewww_image_optimizer_webp_force'] );
			$_POST['ewww_image_optimizer_webp_paths'] = ( empty( $_POST['ewww_image_optimizer_webp_paths'] ) ? '' : $_POST['ewww_image_optimizer_webp_paths'] );
			update_site_option( 'ewww_image_optimizer_webp_paths', ewww_image_optimizer_webp_paths_sanitize( $_POST['ewww_image_optimizer_webp_paths'] ) );
			$_POST['ewww_image_optimizer_allow_multisite_override'] = empty( $_POST['ewww_image_optimizer_allow_multisite_override'] ) ? false : true;
			update_site_option( 'ewww_image_optimizer_allow_multisite_override', $_POST['ewww_image_optimizer_allow_multisite_override'] );
			global $ewwwio_tracking;
			$_POST['ewww_image_optimizer_allow_tracking'] = empty( $_POST['ewww_image_optimizer_allow_tracking'] ) ? false : $ewwwio_tracking->check_for_settings_optin( $_POST['ewww_image_optimizer_allow_tracking'] );
			update_site_option( 'ewww_image_optimizer_allow_tracking', $_POST['ewww_image_optimizer_allow_tracking'] );
			add_action( 'network_admin_notices', 'ewww_image_optimizer_network_settings_saved' );
		} elseif ( isset( $_POST['ewww_image_optimizer_allow_multisite_override_active'] ) && current_user_can( 'manage_network_options' ) && wp_verify_nonce( $_REQUEST['_wpnonce'], 'ewww_image_optimizer_options-options' ) ) {
			$_POST['ewww_image_optimizer_allow_multisite_override'] = empty( $_POST['ewww_image_optimizer_allow_multisite_override'] ) ? false : true;
			update_site_option( 'ewww_image_optimizer_allow_multisite_override', $_POST['ewww_image_optimizer_allow_multisite_override'] );
			global $ewwwio_tracking;
			$_POST['ewww_image_optimizer_allow_tracking'] = empty( $_POST['ewww_image_optimizer_allow_tracking'] ) ? false : $ewwwio_tracking->check_for_settings_optin( $_POST['ewww_image_optimizer_allow_tracking'] );
			update_site_option( 'ewww_image_optimizer_allow_tracking', $_POST['ewww_image_optimizer_allow_tracking'] );
			add_action( 'network_admin_notices', 'ewww_image_optimizer_network_settings_saved' );
		} // End if().
	} // End if().
	if ( is_multisite() && get_site_option( 'ewww_image_optimizer_allow_multisite_override' ) &&
		! ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ) &&
		! ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) &&
		! ewww_image_optimizer_get_option( 'ewww_image_optimizer_gif_level' ) &&
		! ewww_image_optimizer_get_option( 'ewww_image_optimizer_pdf_level' )
	) {
		ewww_image_optimizer_set_defaults();
		update_option( 'ewww_image_optimizer_disable_pngout', true );
		update_option( 'ewww_image_optimizer_optipng_level', 2 );
		update_option( 'ewww_image_optimizer_pngout_level', 2 );
		update_option( 'ewww_image_optimizer_jpegtran_copy', true );
		update_option( 'ewww_image_optimizer_jpg_level', '10' );
		update_option( 'ewww_image_optimizer_png_level', '10' );
		update_option( 'ewww_image_optimizer_gif_level', '10' );
	}
	// Register all the common EWWW IO settings.
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_debug', 'boolval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_jpegtran_copy', 'boolval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_jpg_level', 'intval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_png_level', 'intval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_gif_level', 'intval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_pdf_level', 'intval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_lossy_skip_full', 'boolval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_metadata_skip_full', 'boolval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_delete_originals', 'boolval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_jpg_to_png', 'boolval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_png_to_jpg', 'boolval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_gif_to_png', 'boolval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_webp', 'boolval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_jpg_background', 'ewww_image_optimizer_jpg_background' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_jpg_quality', 'ewww_image_optimizer_jpg_quality' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_disable_convert_links', 'boolval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_backup_files', 'boolval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_cloud_key', 'ewww_image_optimizer_cloud_key_sanitize' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_auto', 'boolval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_aux_paths', 'ewww_image_optimizer_aux_paths_sanitize' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_exclude_paths', 'ewww_image_optimizer_exclude_paths_sanitize' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_enable_cloudinary', 'boolval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_delay', 'intval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_maxmediawidth', 'intval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_maxmediaheight', 'intval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_maxotherwidth', 'intval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_maxotherheight', 'intval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_resize_detection', 'boolval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_resize_existing', 'boolval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_disable_resizes', 'ewww_image_optimizer_disable_resizes_sanitize' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_disable_resizes_opt', 'ewww_image_optimizer_disable_resizes_sanitize' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_skip_size', 'intval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_skip_png_size', 'intval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_parallel_optimization', 'boolval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_include_media_paths', 'boolval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_webp_for_cdn', 'boolval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_webp_force', 'boolval' );
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_webp_paths', 'ewww_image_optimizer_webp_paths_sanitize' );
	global $ewwwio_tracking;
	register_setting( 'ewww_image_optimizer_options', 'ewww_image_optimizer_allow_tracking', array( $ewwwio_tracking, 'check_for_settings_optin' ) );
	ewww_image_optimizer_exec_init();
	ewww_image_optimizer_cron_setup( 'ewww_image_optimizer_auto' );
	/**
	 * Require the file that does the bulk processing.
	 */
	require_once( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'bulk.php' );
	/**
	 * Require the files that contain functions for the images table and bulk processing images outside the library.
	 */
	require_once( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'aux-optimize.php' );
	/**
	 * Require the files that migrate WebP images from extension replacement to extension appending.
	 */
	require_once( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'mwebp.php' );
	// Queue the function that contains custom styling for our progressbars.
	add_action( 'admin_enqueue_scripts', 'ewww_image_optimizer_progressbar_style' );
	// Alert user if multiple re-optimizations detected.
	add_action( 'network_admin_notices', 'ewww_image_optimizer_notice_reoptimization' );
	add_action( 'admin_notices', 'ewww_image_optimizer_notice_reoptimization' );
	ewww_image_optimizer_ajax_compat_check();
	ewwwio_memory( __FUNCTION__ );
}

/**
 * Setup wp_cron tasks for scheduled optimization.
 *
 * @global object $wpdb
 *
 * @param string $event Name of cron hook to schedule.
 */
function ewww_image_optimizer_cron_setup( $event ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	// Setup scheduled optimization if the user has enabled it, and it isn't already scheduled.
	if ( ewww_image_optimizer_get_option( $event ) == true && ! wp_next_scheduled( $event ) ) {
		ewwwio_debug_message( "scheduling $event" );
		wp_schedule_event( time(), apply_filters( 'ewww_image_optimizer_schedule', 'hourly', $event ), $event );
	} elseif ( ewww_image_optimizer_get_option( $event ) == true ) {
		ewwwio_debug_message( "$event already scheduled: " . wp_next_scheduled( $event ) );
	} elseif ( wp_next_scheduled( $event ) ) {
		ewwwio_debug_message( "un-scheduling $event" );
		wp_clear_scheduled_hook( $event );
		if ( ! function_exists( 'is_plugin_active_for_network' ) && is_multisite() ) {
			// Need to include the plugin library for the is_plugin_active function.
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}
		if ( is_multisite() && is_plugin_active_for_network( EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE_REL ) ) {
			global $wpdb;
			$blogs = $wpdb->get_results( $wpdb->prepare( "SELECT blog_id FROM $wpdb->blogs WHERE site_id = %d", $wpdb->siteid ), ARRAY_A );
			if ( ewww_image_optimizer_iterable( $blogs ) ) {
				foreach ( $blogs as $blog ) {
					switch_to_blog( $blog['blog_id'] );
					wp_clear_scheduled_hook( $event );
					restore_current_blog();
				}
			}
		}
	}
}

/**
 * Checks to see if this is an AJAX request, and whether the WP_Image_Editor hooks should be undone.
 *
 * @since 3.3.0
 */
function ewww_image_optimizer_ajax_compat_check() {
	if ( ! wp_doing_ajax() ) {
		return;
	}
	// Check for (Force) Regenerate Thumbnails action (includes MLP regnerate).
	if ( ! empty( $_REQUEST['action'] ) ) {
		if ( 'regeneratethumbnail' == $_REQUEST['action'] ||
			'meauh_save_image' == $_REQUEST['action'] ||
			'hotspot_save' == $_REQUEST['action']
		) {
			ewww_image_optimizer_image_sizes( false );
		}
	}
	// Check for other MLP actions, including multi-regen.
	if ( ! empty( $_REQUEST['action'] ) && class_exists( 'MaxGalleriaMediaLib' ) && ( 'regen_mlp_thumbnails' == $_REQUEST['action'] || 'move_media' == $_REQUEST['action'] || 'copy_media' == $_REQUEST['action'] || 'maxgalleria_rename_image' == $_REQUEST['action'] ) ) {
		ewww_image_optimizer_image_sizes( false );
	}
	// Check for MLP upload.
	if ( ! empty( $_REQUEST['action'] ) && class_exists( 'MaxGalleriaMediaLib' ) && ! empty( $_REQUEST['nonce'] ) && 'upload_attachment' == $_REQUEST['action'] ) {
		ewww_image_optimizer_image_sizes( false );
	}
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	/**
	 * Checks to see if this is an AJAX request.
	 *
	 * For backwards compatiblity with WordPress < 4.7.0.
	 *
	 * @since 3.3.0
	 *
	 * @return bool True if this is an AJAX request.
	 */
	function wp_doing_ajax() {
		return apply_filters( 'wp_doing_ajax', defined( 'DOING_AJAX' ) && DOING_AJAX );
	}
}

/**
 * Disables all the local tools by setting their constants to false.
 */
function ewww_image_optimizer_disable_tools() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( ! defined( 'EWWW_IMAGE_OPTIMIZER_JPEGTRAN' ) ) {
		define( 'EWWW_IMAGE_OPTIMIZER_JPEGTRAN', false );
	}
	if ( ! defined( 'EWWW_IMAGE_OPTIMIZER_OPTIPNG' ) ) {
		define( 'EWWW_IMAGE_OPTIMIZER_OPTIPNG', false );
	}
	if ( ! defined( 'EWWW_IMAGE_OPTIMIZER_PNGOUT' ) ) {
		define( 'EWWW_IMAGE_OPTIMIZER_PNGOUT', false );
	}
	if ( ! defined( 'EWWW_IMAGE_OPTIMIZER_GIFSICLE' ) ) {
		define( 'EWWW_IMAGE_OPTIMIZER_GIFSICLE', false );
	}
	if ( ! defined( 'EWWW_IMAGE_OPTIMIZER_PNGQUANT' ) ) {
		define( 'EWWW_IMAGE_OPTIMIZER_PNGQUANT', false );
	}
	if ( ! defined( 'EWWW_IMAGE_OPTIMIZER_WEBP' ) ) {
		define( 'EWWW_IMAGE_OPTIMIZER_WEBP', false );
	}
	ewwwio_memory( __FUNCTION__ );
}

/**
 * Generates css include for progressbars to match admin style.
 */
function ewww_image_optimizer_progressbar_style() {
	wp_add_inline_style( 'jquery-ui-progressbar', '.ui-widget-header { background-color: ' . ewww_image_optimizer_admin_background() . '; }' );
	ewwwio_memory( __FUNCTION__ );
}

/**
 * Determines the background color to use based on the selected admin theme.
 */
function ewww_image_optimizer_admin_background() {
	if ( function_exists( 'wp_add_inline_style' ) ) {
		$user_info = wp_get_current_user();
		switch ( $user_info->admin_color ) {
			case 'midnight':
				return '#e14d43';
			case 'blue':
				return '#096484';
			case 'light':
				return '#04a4cc';
			case 'ectoplasm':
				return '#a3b745';
			case 'coffee':
				return '#c7a589';
			case 'ocean':
				return '#9ebaa0';
			case 'sunrise':
				return '#dd823b';
			default:
				return '#0073aa';
		}
	}
	ewwwio_memory( __FUNCTION__ );
}

/**
 * If a multisite is over 1000 sites, tells WP this is a 'large network' when querying image stats.
 *
 * @param bool   $large_network Normally only true with 10,000+ users or sites.
 * @param string $criteria The criteria for determining a large network, 'sites' or 'users'.
 * @param int    $count The number of sites/users.
 * @return bool True if this is a 'large network'.
 */
function ewww_image_optimizer_large_network( $large_network, $criteria, $count ) {
	if ( 'sites' == $criteria && $count > 1000 ) {
		return true;
	}
	return false;
}

/**
 * Adds/upgrades table in db for storing status of all images that have been optimized.
 *
 * @global object $wpdb
 */
function ewww_image_optimizer_install_table() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	global $wpdb;
	$wpdb->ewwwio_images = $wpdb->prefix . 'ewwwio_images';

	// Get the current wpdb charset and collation.
	$db_collation = $wpdb->get_charset_collate();
	ewwwio_debug_message( "current collation: $db_collation" );

	// See if the path column exists, and what collation it uses to determine the column index size.
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$wpdb->ewwwio_images'" ) == $wpdb->ewwwio_images ) {
		ewwwio_debug_message( 'upgrading table and checking collation for path, table exists' );
		$wpdb->query( "UPDATE $wpdb->ewwwio_images SET updated = '1971-01-01 00:00:00' WHERE updated < '1001-01-01 00:00:01'" );
		$column_collate = $wpdb->get_col_charset( $wpdb->ewwwio_images, 'path' );
		if ( ! empty( $column_collate ) && 'utf8mb4' !== $column_collate ) {
			$path_index_size = 255;
			ewwwio_debug_message( "current column collation: $column_collate" );
			if ( strpos( $column_collate, 'utf8' ) === false ) {
				ewwwio_debug_message( 'converting path column to utf8' );
				$wpdb->query( "ALTER TABLE $wpdb->ewwwio_images CHANGE path path BLOB" );
				if ( $wpdb->has_cap( 'utf8mb4_520' && strpos( $db_collation, 'utf8mb4' ) ) ) {
					ewwwio_debug_message( 'using mb4 version 5.20' );
					$wpdb->query( "ALTER TABLE $wpdb->ewwwio_images DROP INDEX path_image_size" );
					$wpdb->query( "ALTER TABLE $wpdb->ewwwio_images CONVERT TO CHARACTER SET utf8mb4, CHANGE path path TEXT" );
					unset( $path_index_size );
				} elseif ( $wpdb->has_cap( 'utf8mb4' ) && strpos( $db_collation, 'utf8mb4' ) ) {
					ewwwio_debug_message( 'using mb4 version 4' );
					$wpdb->query( "ALTER TABLE $wpdb->ewwwio_images DROP INDEX path_image_size" );
					$wpdb->query( "ALTER TABLE $wpdb->ewwwio_images CONVERT TO CHARACTER SET utf8mb4, CHANGE path path TEXT" );
					unset( $path_index_size );
				} else {
					ewwwio_debug_message( 'using plain old utf8' );
					$wpdb->query( "ALTER TABLE $wpdb->ewwwio_images CONVERT TO CHARACTER SET utf8, CHANGE path path TEXT" );
				}
			} elseif ( strpos( $column_collate, 'utf8mb4' ) === false && strpos( $db_collation, 'utf8mb4' ) ) {
				if ( $wpdb->has_cap( 'utf8mb4_520' ) ) {
					ewwwio_debug_message( 'using mb4 version 5.20' );
					$wpdb->query( "ALTER TABLE $wpdb->ewwwio_images DROP INDEX path_image_size" );
					$wpdb->query( "ALTER TABLE $wpdb->ewwwio_images CONVERT TO CHARACTER SET utf8mb4, CHANGE path path TEXT" );
					unset( $path_index_size );
				} elseif ( $wpdb->has_cap( 'utf8mb4' ) ) {
					ewwwio_debug_message( 'using mb4 version 4' );
					$wpdb->query( "ALTER TABLE $wpdb->ewwwio_images DROP INDEX path_image_size" );
					$wpdb->query( "ALTER TABLE $wpdb->ewwwio_images CONVERT TO CHARACTER SET utf8mb4, CHANGE path path TEXT" );
					unset( $path_index_size );
				}
			}
		}
	} // End if().

	// If the path column doesn't yet exist, and the default collation is utf8mb4, then we need to lower the column index size.
	if ( empty( $path_index_size ) && strpos( $db_collation, 'utf8mb4' ) ) {
		$path_index_size = 191;
	} else {
		$path_index_size = 255;
	}
	ewwwio_debug_message( "path index size: $path_index_size" );

	/*
	 * Create a table with 15 columns:
	 * id: unique for each record/image,
	 * attachment_id: the unique id within the media library, nextgen, or flag
	 * gallery: 'media', 'nextgen', 'nextcell', or 'flag',
	 * resize: size of the image,
	 * path: filename of the image, potentially replaced with ABSPATH or WP_CONTENT_DIR,
	 * converted: filename of the image before conversion,
	 * results: human-readable savings message,
	 * image_size: optimized size of the image,
	 * orig_size: original size of the image,
	 * backup: hash where the image is stored on the API servers,
	 * level: the optimization level used on the image,
	 * pending: 1 if the image is queued for optimization,
	 * updates: how many times an image has been optimized,
	 * updated: when the image was last optimized,
	 * trace: tracelog from the last optimization if debugging was enabled.
	 */
	$sql = "CREATE TABLE $wpdb->ewwwio_images (
		id int(14) unsigned NOT NULL AUTO_INCREMENT,
		attachment_id bigint(20) unsigned,
		gallery varchar(10),
		resize varchar(75),
		path text NOT NULL,
		converted text NOT NULL,
		results varchar(75) NOT NULL,
		image_size int(10) unsigned,
		orig_size int(10) unsigned,
		backup varchar(100),
		level int(5) unsigned,
		pending tinyint(1) NOT NULL DEFAULT 0,
		updates int(5) unsigned,
		updated timestamp DEFAULT '1971-01-01 00:00:00' ON UPDATE CURRENT_TIMESTAMP,
		trace blob,
		UNIQUE KEY id (id),
		KEY path_image_size (path($path_index_size),image_size),
		KEY attachment_info (gallery(3),attachment_id)
	) $db_collation;";

	// Include the upgrade library to install/upgrade a table.
	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	$updates = dbDelta( $sql );
	if ( ewww_image_optimizer_function_exists( 'print_r' ) ) {
		ewwwio_debug_message( 'db upgrade results: ' . print_r( $updates, true ) );
	}

	// Make sure some of our options are not autoloaded (since they can be huge).
	$bulk_attachments = get_option( 'ewww_image_optimizer_bulk_attachments', '' );
	delete_option( 'ewww_image_optimizer_bulk_attachments' );
	add_option( 'ewww_image_optimizer_bulk_attachments', $bulk_attachments, '', 'no' );
	$bulk_attachments = get_option( 'ewww_image_optimizer_flag_attachments', '' );
	delete_option( 'ewww_image_optimizer_flag_attachments' );
	add_option( 'ewww_image_optimizer_flag_attachments', $bulk_attachments, '', 'no' );
	$bulk_attachments = get_option( 'ewww_image_optimizer_ngg_attachments', '' );
	delete_option( 'ewww_image_optimizer_ngg_attachments' );
	add_option( 'ewww_image_optimizer_ngg_attachments', $bulk_attachments, '', 'no' );
	delete_option( 'ewww_image_optimizer_aux_attachments' );
	delete_option( 'ewww_image_optimizer_defer_attachments' );
}

/**
 * Migrates old cloud/compression settings to compression levels.
 */
function ewww_image_optimizer_migrate_settings_to_levels() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_jpegtran' ) ) {
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_jpg_level', 0 );
	}
	if ( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_jpegtran' ) && ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) ) {
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_jpg_level', 10 );
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_jpg' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) && ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_lossy' ) ) {
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_jpg_level', 20 );
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_jpg' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_lossy' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_lossy_fast' ) ) {
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_jpg_level', 30 );
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_jpg' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_lossy' ) && ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_lossy_fast' ) ) {
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_jpg_level', 40 );
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_pngout' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_optipng' ) ) {
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_png_level', 0 );
	}
	if ( ( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_pngout' ) || ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_optipng' ) ) && ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) ) {
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_png_level', 10 );
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_png' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) && ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_lossy' ) && ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_png_compress' ) ) {
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_png_level', 20 );
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_png' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) && ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_lossy' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_png_compress' ) ) {
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_png_level', 30 );
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_png' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_lossy' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_lossy_fast' ) ) {
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_png_level', 40 );
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_png' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_lossy' ) && ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_lossy_fast' ) ) {
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_png_level', 50 );
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_gifsicle' ) ) {
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_gif_level', 0 );
	}
	if ( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_gifsicle' ) ) {
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_gif_level', 10 );
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_pdf' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) && ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_pdf_lossy' ) ) {
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_pdf_level', 10 );
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_pdf' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_pdf_lossy' ) ) {
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_pdf_level', 20 );
	}
}

/**
 * Removes settings which are no longer used.
 */
function ewww_image_optimizer_remove_obsolete_settings() {
	delete_option( 'ewww_image_optimizer_disable_jpegtran' );
	delete_option( 'ewww_image_optimizer_cloud_jpg' );
	delete_option( 'ewww_image_optimizer_jpg_lossy' );
	delete_option( 'ewww_image_optimizer_lossy_fast' );
	delete_option( 'ewww_image_optimizer_cloud_png' );
	delete_option( 'ewww_image_optimizer_png_lossy' );
	delete_option( 'ewww_image_optimizer_cloud_png_compress' );
	delete_option( 'ewww_image_optimizer_disable_gifsicle' );
	delete_option( 'ewww_image_optimizer_cloud_gif' );
	delete_option( 'ewww_image_optimizer_cloud_pdf' );
	delete_option( 'ewww_image_optimizer_pdf_lossy' );
	delete_option( 'ewww_image_optimizer_skip_check' );
	delete_option( 'ewww_image_optimizer_disable_optipng' );
	delete_option( 'ewww_image_optimizer_interval' );
	delete_option( 'ewww_image_optimizer_jpegtran_path' );
	delete_option( 'ewww_image_optimizer_optipng_path' );
	delete_option( 'ewww_image_optimizer_gifsicle_path' );
	delete_option( 'ewww_image_optimizer_import_status' );
	delete_option( 'ewww_image_optimizer_bulk_image_count' );
	delete_option( 'ewww_image_optimizer_maxwidth' );
	delete_option( 'ewww_image_optimizer_maxheight' );
}

/**
 * Lets the user know their network settings have been saved.
 */
function ewww_image_optimizer_network_settings_saved() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	echo "<div id='ewww-image-optimizer-settings-saved' class='updated fade'><p><strong>" . esc_html__( 'Settings saved', 'ewww-image-optimizer' ) . '.</strong></p></div>';
}

/**
 * Alert the user when 5 images have been re-optimized more than 10 times.
 *
 * @global object $wpdb
 */
function ewww_image_optimizer_notice_reoptimization() {
	// Allows the user to reset all images back to 1 optimization, which clears the alert.
	if ( ! empty( $_GET['ewww_reset_reopt_nonce'] ) && wp_verify_nonce( $_GET['ewww_reset_reopt_nonce'], 'reset_reoptimization_counters' ) ) {
		global $wpdb;
		$debug_images = $wpdb->query( "UPDATE $wpdb->ewwwio_images SET updates=1 WHERE updates > 1" );
		delete_transient( 'ewww_image_optimizer_images_reoptimized' );
	} else {
		$reoptimized = get_transient( 'ewww_image_optimizer_images_reoptimized' );
		if ( empty( $reoptimized ) ) {
			global $wpdb;
			$reoptimized = $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->ewwwio_images WHERE updates > 10 AND path NOT LIKE '%wp-content/themes%' AND path NOT LIKE '%wp-content/plugins%'  LIMIT 10" );
			if ( empty( $reoptimized ) ) {
				set_transient( 'ewww_image_optimizer_images_reoptimized', 'zero', HOUR_IN_SECONDS );
			} else {
				set_transient( 'ewww_image_optimizer_images_reoptimized', $reoptimized, HOUR_IN_SECONDS );
			}
		} elseif ( 'zero' == $reoptimized ) {
			$reoptimized = 0;
		}
		// Do a check for 10+ optimizations on 5+ images.
		if ( ! empty( $reoptimized ) && $reoptimized > 5 ) {
			$debugging_page = admin_url( 'upload.php?page=ewww-image-optimizer-dynamic-debug' );
			$reset_page = wp_nonce_url( $_SERVER['REQUEST_URI'], 'reset_reoptimization_counters', 'ewww_reset_reopt_nonce' );
			// Display an alert, and let the user reset the warning if they wish.
			echo "<div id='ewww-image-optimizer-warning-reoptimizations' class='error'><p>" .
				sprintf(
					/* translators: %s: A link to the Dynamic Image Debugging page */
					esc_html__( 'The EWWW Image Optimizer has detected excessive re-optimization of multiple images. Please turn on the Debugging setting, wait for approximately 12 hours, and then visit the %s page.', 'ewww-image-optimizer' ),
					"<a href='$debugging_page'>" . esc_html__( 'Dynamic Image Debugging', 'ewww-image-optimizer' ) . '</a>'
				) .
				" <a href='$reset_page'>" . esc_html__( 'Reset Counters' ) . '</a></p></div>';
		}
	}
}

/**
 * Loads the class to extend WP_Image_Editor for automatic optimization of generated images.
 *
 * @param array $editors List of image editors available to WordPress.
 * @return array Modified list of editors, with our custom class added at the top.
 */
function ewww_image_optimizer_load_editor( $editors ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( ! class_exists( 'EWWWIO_GD_Editor' ) && ! class_exists( 'EWWWIO_Imagick_Editor' ) ) {
		require_once( plugin_dir_path( __FILE__ ) . '/classes/class-ewwwio-gd-editor.php' );
		require_once( plugin_dir_path( __FILE__ ) . '/classes/class-ewwwio-imagick-editor.php' );
		require_once( plugin_dir_path( __FILE__ ) . '/classes/class-ewwwio-gmagick-editor.php' );
	}
	if ( ! in_array( 'EWWWIO_GD_Editor', $editors ) ) {
		array_unshift( $editors, 'EWWWIO_GD_Editor' );
	}
	if ( ! in_array( 'EWWWIO_Imagick_Editor', $editors ) ) {
		array_unshift( $editors, 'EWWWIO_Imagick_Editor' );
	}
	if ( ! in_array( 'EWWWIO_Gmagick_Editor', $editors ) && class_exists( 'WP_Image_Editor_Gmagick' ) ) {
		array_unshift( $editors, 'EWWWIO_Gmagick_Editor' );
	}
	if ( ewww_image_optimizer_function_exists( 'print_r' ) ) {
		ewwwio_debug_message( 'loading image editors: ' . print_r( $editors, true ) );
	}
	ewwwio_memory( __FUNCTION__ );
	return $editors;
}

/**
 * Registers the filter that will remove the image_editor hooks when an attachment is added.
 */
function ewww_image_optimizer_add_attachment() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	add_filter( 'intermediate_image_sizes_advanced', 'ewww_image_optimizer_image_sizes', 200 );
	add_filter( 'fallback_intermediate_image_sizes', 'ewww_image_optimizer_image_sizes', 200 );
}

/**
 * Removes the image editor filter, and adds a new filter that will restore it later.
 *
 * @param array $sizes A list of sizes to be generated by WordPress.
 * @return array The unaltered list of sizes to be generated.
 */
function ewww_image_optimizer_image_sizes( $sizes ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	remove_filter( 'wp_image_editors', 'ewww_image_optimizer_load_editor', 60 );
	add_filter( 'wp_generate_attachment_metadata', 'ewww_image_optimizer_restore_editor_hooks', 1 );
	add_filter( 'mpp_generate_metadata', 'ewww_image_optimizer_restore_editor_hooks', 1 );
	return $sizes;
}

/**
 * Restores the image editor filter after the resizes have been generated.
 *
 * Also removes the retina filter, and adds our own wrapper around the retina generation function.
 *
 * @param array $metadata The attachment metadata that has been generated.
 * @return array The unaltered attachment metadata.
 */
function ewww_image_optimizer_restore_editor_hooks( $metadata = false ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	add_filter( 'wp_image_editors', 'ewww_image_optimizer_load_editor', 60 );
	if ( function_exists( 'wr2x_wp_generate_attachment_metadata' ) ) {
		remove_filter( 'wp_generate_attachment_metadata', 'wr2x_wp_generate_attachment_metadata' );
		add_filter( 'wp_generate_attachment_metadata', 'ewww_image_optimizer_retina_wrapper' );
	}
	return $metadata;
}

/**
 * Removes image editor filter when an attachment is being saved, and adds a filter to restore it.
 *
 * This prevents resizes from being optimized prematurely when saving the new attachment.
 *
 * @param string $image The filename of the edited image.
 * @return string The unaltered filename.
 */
function ewww_image_optimizer_editor_save_pre( $image ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_noauto' ) ) {
		remove_filter( 'wp_image_editors', 'ewww_image_optimizer_load_editor', 60 );
		add_filter( 'wp_update_attachment_metadata', 'ewww_image_optimizer_restore_editor_hooks', 1 );
		add_filter( 'wp_update_attachment_metadata', 'ewww_image_optimizer_resize_from_meta_data', 15, 2 );
	}
	add_filter( 'intermediate_image_sizes', 'ewww_image_optimizer_image_sizes_advanced' );
	return $image;
}

/**
 * Ensures that images saved with PTE are optimized.
 *
 * Checks for Post Thumbnail Editor's confirm, separate from crop&save, and registers a filter to
 * process any modified resizes.
 *
 * @param array $data The attachment metadata requested by PTE.
 * @return array The unaltered attachment metadata.
 */
function ewww_image_optimizer_pte_check( $data ) {
	if ( ! empty( $_GET['pte-action'] ) ) {
		if ( 'confirm-images' == $_GET['pte-action'] ) {
			add_filter( 'wp_update_attachment_metadata', 'ewww_image_optimizer_resize_from_meta_data', 15, 2 );
		}
	}
	return $data;
}

/**
 * Wraps around the retina generation function to prevent premature optimization.
 *
 * @since 3.3.0
 *
 * @param array $meta The attachment metadata.
 * @return array The unaltered metadata.
 */
function ewww_image_optimizer_retina_wrapper( $meta ) {
	remove_filter( 'wp_image_editors', 'ewww_image_optimizer_load_editor', 60 );
	$meta = wr2x_wp_generate_attachment_metadata( $meta );
	add_filter( 'wp_image_editors', 'ewww_image_optimizer_load_editor', 60 );
	return $meta;
}

/**
 * Filters image sizes generated by Wordpress, themes, and plugins allowing users to disable sizes.
 *
 * @param array $sizes A list of sizes to be generated.
 * @return array A list of sizes, minus any the user wants disabled.
 */
function ewww_image_optimizer_image_sizes_advanced( $sizes ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	$disabled_sizes = get_option( 'ewww_image_optimizer_disable_resizes' );
	$flipped = false;
	if ( ! empty( $disabled_sizes ) ) {
		if ( ! empty( $sizes[0] ) ) {
			$sizes = array_flip( $sizes );
			$flipped = true;
		}
		if ( ewww_image_optimizer_function_exists( 'print_r' ) ) {
			ewwwio_debug_message( print_r( $sizes, true ) );
		}
		if ( ewww_image_optimizer_iterable( $disabled_sizes ) ) {
			foreach ( $disabled_sizes as $size => $disabled ) {
				if ( ! empty( $disabled ) ) {
					ewwwio_debug_message( "size disabled: $size" );
					unset( $sizes[ $size ] );
				}
			}
		}
		if ( $flipped ) {
			$sizes = array_flip( $sizes );
		}
	}
	return $sizes;
}

/**
 * Filter the image previews generated for pdf files and other non-image types.
 *
 * @param array $sizes A list of sizes to be generated.
 * @return array A list of sizes, minus any the user wants disabled.
 */
function ewww_image_optimizer_fallback_sizes( $sizes ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	$disabled_sizes = get_option( 'ewww_image_optimizer_disable_resizes' );
	$flipped = false;
	if ( ! empty( $disabled_sizes ) ) {
		if ( ewww_image_optimizer_function_exists( 'print_r' ) ) {
			ewwwio_debug_message( print_r( $sizes, true ) );
		}
		if ( ewww_image_optimizer_iterable( $sizes ) && ewww_image_optimizer_iterable( $disabled_sizes ) ) {
			if ( ! empty( $disabled_sizes['pdf-full'] ) ) {
				return array();
			}
			foreach ( $sizes as $i => $size ) {
				if ( ! empty( $disabled_sizes[ $size ] ) ) {
					ewwwio_debug_message( "size disabled: $size" );
					unset( $sizes[ $i ] );
				}
			}
		}
	}
	return $sizes;
}

/**
 * Wrapper around the upload handler for MediaPress.
 *
 * @param array $params Parameters related to the file being uploaded.
 * @return array The unaltered parameters, we only need to pass them on.
 */
function ewww_image_optimizer_handle_mpp_upload( $params ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	add_filter( 'mpp_intermediate_image_sizes', 'ewww_image_optimizer_image_sizes', 200 );
	return ewww_image_optimizer_handle_upload( $params );
}

/**
 * During an upload, handles resizing, auto-rotation, and sets the 'new_image' global.
 *
 * @global bool $ewww_new_image True if there is a new image being uploaded.
 *
 * @param array $params Parameters related to the file being uploaded.
 * @return array The unaltered parameters, we only need to read them.
 */
function ewww_image_optimizer_handle_upload( $params ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	global $ewww_new_image;
	$ewww_new_image = true;
	if ( empty( $params['file'] ) ) {
		if ( ! empty( $params['tmp_name'] ) ) {
			$file_path = $params['tmp_name'];
		} else {
			return $params;
		}
	} else {
		$file_path = $params['file'];
	}
	ewww_image_optimizer_autorotate( $file_path );
	// NOTE: if you use the ewww_image_optimizer_defer_resizing filter to defer the resize operation, only the "other" dimensions will apply
	// resize here unless the user chose to defer resizing or imsanity is enabled with a max size.
	if ( ! apply_filters( 'ewww_image_optimizer_defer_resizing', false ) && ! function_exists( 'imsanity_get_max_width_height' ) ) {
		if ( empty( $params['type'] ) ) {
			$mime_type = ewww_image_optimizer_mimetype( $file_path, 'i' );
		} else {
			$mime_type = $params['type'];
		}
		if ( ( ! is_wp_error( $params ) ) && is_file( $file_path ) && in_array( $mime_type, array( 'image/png', 'image/gif', 'image/jpeg' ) ) ) {
			ewww_image_optimizer_resize_upload( $file_path );
		}
	}
	return $params;
}

/**
 * Makes sure W3TC uploads all modified files to any configured CDNs.
 *
 * @global array $ewww_attachment {
 *	Stores the ID and meta for later use with W3TC.
 *
 *	@int int $id The attachment ID number.
 *	@array array $meta The attachment metadata from the postmeta table.
 * }
 *
 * @param array $files Files being updated by W3TC.
 * @return array Original array plus information about full-size image so that it also is updated.
 */
function ewww_image_optimizer_w3tc_update_files( $files ) {
	global $ewww_attachment;
	list( $file, $upload_path ) = ewww_image_optimizer_attachment_path( $ewww_attachment['meta'], $ewww_attachment['id'] );
	$file_info = array();
	if ( function_exists( 'w3_upload_info' ) ) {
		$upload_info = w3_upload_info();
	} else {
		$upload_info = ewww_image_optimizer_upload_info();
	}
	if ( $upload_info ) {
		$remote_file = ltrim( $upload_info['baseurlpath'] . $ewww_attachment['meta']['file'], '/' );
		$home_url = get_site_url();
		$original_url = $home_url . $file;
		$file_info[] = array(
			'local_path' => $file,
			'remote_path' => $remote_file,
			'original_url' => $original_url,
		);
		$files = array_merge( $files, $file_info );
	}
	return $files;
}

/**
 * Wrapper around wp_upload_dir that adds the base url to the uploads folder as 'baseurlpath' key.
 *
 * @return array|bool Information about the uploads dir, or false on failure.
 */
function ewww_image_optimizer_upload_info() {
	$upload_info = @wp_upload_dir( null, false );

	if ( empty( $upload_info['error'] ) ) {
		// Errors silenced because of PHP < 5.3.3, yuck.
		$parse_url = @parse_url( $upload_info['baseurl'] );

		if ( $parse_url ) {
			$baseurlpath = ( ! empty( $parse_url['path'] ) ? trim( $parse_url['path'], '/' ) : '' );
		} else {
			$baseurlpath = 'wp-content/uploads';
		}
		$upload_info['baseurlpath'] = '/' . $baseurlpath . '/';
	} else {
		$upload_info = false;
	}
	return $upload_info;
}

/**
 * Runs scheduled optimization of various images.
 *
 * Regularly compresses any preconfigured folders including Buddypress, the active theme,
 * metaslider, and WP Symposium. Also includes any user-configured folders, along with the last two
 * months of media uploads.
 *
 * @global bool $ewww_defer Gets set to false to make sure optimization happens inline.
 * @global object $wpdb
 * @global object $ewwwdb A clone of $wpdb unless it is lacking utf8 connectivity.
 */
function ewww_image_optimizer_auto() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( get_transient( 'ewww_image_optimizer_no_scheduled_optimization' ) ) {
		ewwwio_debug_message( 'detected bulk operation in progress, bailing' );
		ewww_image_optimizer_debug_log();
		return;
	}
	global $ewww_defer;
	$ewww_defer = false;
	require_once( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'bulk.php' );
	require_once( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'aux-optimize.php' );
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_auto' ) == true ) {
		ewwwio_debug_message( 'running scheduled optimization' );
		ewww_image_optimizer_aux_images_script( 'ewww-image-optimizer-auto' );
		// Generate our own unique nonce value, because wp_create_nonce() will return the same value for 12-24 hours.
		$nonce = wp_hash( time() . '|' . 'ewww-image-optimizer-auto' );
		update_option( 'ewww_image_optimizer_aux_resume', $nonce );
		$delay = ewww_image_optimizer_get_option( 'ewww_image_optimizer_delay' );
		$count = ewww_image_optimizer_aux_images_table_count_pending();
		if ( ! empty( $count ) ) {
			global $wpdb;
			if ( strpos( $wpdb->charset, 'utf8' ) === false ) {
				ewww_image_optimizer_db_init();
				global $ewwwdb;
			} else {
				$ewwwdb = $wpdb;
			}
			$i = 0;
			while ( $i < $count &&
				$attachment = $ewwwdb->get_row( "SELECT id,path FROM $ewwwdb->ewwwio_images WHERE pending=1 LIMIT 1", ARRAY_A )
			 ) {
				// If the nonce has changed since we started, bail out, since that means another aux scan/optimize is running.
				// Do a direct query using $wpdb, because get_option() is cached.
				$current_nonce = $wpdb->get_var( "SELECT option_value FROM $wpdb->options WHERE option_name = 'ewww_image_optimizer_aux_resume'" );
				if ( $nonce !== $current_nonce ) {
					ewwwio_debug_message( 'detected another optimization, nonce changed, bailing' );
					ewww_image_optimizer_debug_log();
					return;
				} else {
					ewwwio_debug_message( "$nonce is fine, compared to $current_nonce" );
				}
				if ( ! empty( $attachment['path'] ) ) {
					$attachment['path'] = ewww_image_optimizer_relative_path_replace( $attachment['path'] );
				}
				ewww_image_optimizer_aux_images_loop( $attachment, true );
				if ( ! empty( $delay ) && ewww_image_optimizer_function_exists( 'sleep' ) ) {
					sleep( $delay );
				}
				ewww_image_optimizer_debug_log();
				$i++;
			}
		}
		ewww_image_optimizer_aux_images_cleanup( true );
	} // End if().
	ewwwio_memory( __FUNCTION__ );
	return;
}

/**
 * Clears scheduled jobs for multisite when the plugin is deactivated.
 *
 * @global object $wpdb
 *
 * @param bool $network_wide True if plugin was network-activated.
 */
function ewww_image_optimizer_network_deactivate( $network_wide ) {
	global $wpdb;
	wp_clear_scheduled_hook( 'ewww_image_optimizer_auto' );
	wp_clear_scheduled_hook( 'ewww_image_optimizer_defer' );
	if ( $network_wide ) {
		$blogs = $wpdb->get_results( $wpdb->prepare( "SELECT blog_id FROM $wpdb->blogs WHERE site_id = %d", $wpdb->siteid ), ARRAY_A );
		if ( ewww_image_optimizer_iterable( $blogs ) ) {
			foreach ( $blogs as $blog ) {
				switch_to_blog( $blog['blog_id'] );
				wp_clear_scheduled_hook( 'ewww_image_optimizer_auto' );
				wp_clear_scheduled_hook( 'ewww_image_optimizer_defer' );
				restore_current_blog();
			}
		}
	}
}

/**
 * Removes rules from .htaccess file added by EWWW for WebP rewriting.
 */
function ewww_image_optimizer_uninstall() {
	insert_with_markers( ewww_image_optimizer_htaccess_path(), 'EWWWIO', '' );
}

/**
 * Adds a global settings page to the network admin settings menu.
 */
function ewww_image_optimizer_network_admin_menu() {
	if ( ! function_exists( 'is_plugin_active_for_network' ) && is_multisite() ) {
		// Need to include the plugin library for the is_plugin_active function.
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	}
	if ( is_multisite() && is_plugin_active_for_network( EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE_REL ) ) {
		// Add options page to the settings menu.
		$permissions = apply_filters( 'ewww_image_optimizer_superadmin_permissions', '' );
		$ewww_network_options_page = add_submenu_page(
			'settings.php',				// Slug of parent
			'EWWW Image Optimizer',			// Page Title
			'EWWW Image Optimizer',			// Menu title
			$permissions,				// Capability
			EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE,	// Slug
			'ewww_image_optimizer_network_options'		// Function to call.
		);
	}
}

/**
 * Simulates regenerating a resize for an attachment.
 */
function ewww_image_optimizer_resize_dup_check() {
	$meta = wp_get_attachment_metadata( 34 );
	list( $file, $upload_path ) = ewww_image_optimizer_attachment_path( $meta, 34 );
	$editor = wp_get_image_editor( $file );
	$resized_image = $editor->resize( 150, 150, true );
	$new_file = $editor->generate_filename();
	echo $new_file;
	if ( is_file( $new_file ) ) {
		echo '<br>file already exists<br>';
	}
	$saved = $editor->save( $new_file );
}

/**
 * Adds various items to the admin menu.
 */
function ewww_image_optimizer_admin_menu() {
	$permissions = apply_filters( 'ewww_image_optimizer_bulk_permissions', '' );
	// Adds bulk optimize to the media library menu.
	$ewww_bulk_page = add_media_page( esc_html__( 'Bulk Optimize', 'ewww-image-optimizer' ), esc_html__( 'Bulk Optimize', 'ewww-image-optimizer' ), $permissions, 'ewww-image-optimizer-bulk', 'ewww_image_optimizer_bulk_preview' );
	// TEST TODO: move this to a unit test or something!
	/* add_media_page( esc_html__( 'Resize checker', 'ewww-image-optimizer' ), esc_html__( 'Duplicate Resize Check', 'ewww-image-optimizer' ), $permissions, 'ewww-image-optimizer-resize-dup-check', 'ewww_image_optimizer_resize_dup_check' ); */
	$ewww_webp_migrate_page = add_submenu_page( null, esc_html__( 'Migrate WebP Images', 'ewww-image-optimizer' ), esc_html__( 'Migrate WebP Images', 'ewww-image-optimizer' ), $permissions, 'ewww-image-optimizer-webp-migrate', 'ewww_image_optimizer_webp_migrate_preview' );
	if ( ! function_exists( 'is_plugin_active' ) ) {
		// Need to include the plugin library for the is_plugin_active function.
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	}
	if ( ! is_plugin_active_for_network( EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE_REL ) ) {
		// Add options page to the settings menu.
		$ewww_options_page = add_options_page(
			'EWWW Image Optimizer',								// Page title
			'EWWW Image Optimizer',								// Menu title
			apply_filters( 'ewww_image_optimizer_admin_permissions', 'manage_options' ),	// Capability
			EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE,						// Slug
			'ewww_image_optimizer_options'							// Function to call.
		);
	} else {
		// Add options page to the single-site settings menu.
		$ewww_options_page = add_options_page(
			'EWWW Image Optimizer',								// Page title
			'EWWW Image Optimizer',								// Menu title
			apply_filters( 'ewww_image_optimizer_admin_permissions', 'manage_options' ),	// Capability
			EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE,						// Slug
			'ewww_image_optimizer_network_singlesite_options'				// Function to call.
		);
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_debug' ) ) {
		// Add Dynamic Image Debugging page for image regeneration issues.
		add_media_page( esc_html__( 'Dynamic Image Debugging', 'ewww-image-optimizer' ), esc_html__( 'Dynamic Image Debugging', 'ewww-image-optimizer' ), $permissions, 'ewww-image-optimizer-dynamic-debug', 'ewww_image_optimizer_dynamic_image_debug' );
		// Add Image Queue Debugging to allow clearing and checking queues.
		add_media_page( esc_html__( 'Image Queue Debugging', 'ewww-image-optimizer' ), esc_html__( 'Image Queue Debugging', 'ewww-image-optimizer' ), $permissions, 'ewww-image-optimizer-queue-debug', 'ewww_image_optimizer_image_queue_debug' );
	}
	if ( is_plugin_active( 'image-store/ImStore.php' ) || is_plugin_active_for_network( 'image-store/ImStore.php' ) ) {
		// Adds an optimize page for Image Store galleries and images.
		$ims_menu = 'edit.php?post_type=ims_gallery';
		$ewww_ims_page = add_submenu_page( $ims_menu, esc_html__( 'Image Store Optimize', 'ewww-image-optimizer' ), esc_html__( 'Optimize', 'ewww-image-optimizer' ), 'ims_change_settings', 'ewww-ims-optimize', 'ewww_image_optimizer_ims' );
	}
}

/**
 * Checks WP Retina images to fix filenames in the database.
 *
 * @param int    $id The attachment ID with which this retina image is associated.
 * @param string $retina_path The filename of the retina image that was generated.
 */
function ewww_image_optimizer_retina( $id, $retina_path ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	$file_info = pathinfo( $retina_path );
	$extension = '.' . $file_info['extension'];
	preg_match( '/-(\d+x\d+)@2x$/', $file_info['filename'], $fileresize );
	$dimensions = explode( 'x', $fileresize[1] );
	$no_ext_path = $file_info['dirname'] . '/' . preg_replace( '/\d+x\d+@2x$/', '', $file_info['filename'] ) . $dimensions[0] * 2 . 'x' . $dimensions[1] * 2 . '-tmp';
	$temp_path = $no_ext_path . $extension;
	ewwwio_debug_message( "temp path: $temp_path" );
	// Check for any orphaned webp retina images, and fix their paths.
	ewwwio_debug_message( "retina path: $retina_path" );
	$webp_path = $temp_path . '.webp';
	ewwwio_debug_message( "retina webp path: $webp_path" );
	if ( is_file( $webp_path ) ) {
		rename( $webp_path, $retina_path . '.webp' );
	}
	$opt_size = ewww_image_optimizer_filesize( $retina_path );
	ewwwio_debug_message( "retina size: $opt_size" );
	$optimized_query = ewww_image_optimizer_find_already_optimized( $temp_path );
	if ( is_array( $optimized_query ) && $optimized_query['image_size'] == $opt_size ) {
		global $wpdb;
		if ( strpos( $wpdb->charset, 'utf8' ) === false ) {
			ewww_image_optimizer_db_init();
			global $ewwwdb;
		} else {
			$ewwwdb = $wpdb;
		}
		// Replace the 'temp' path in the database with the real path.
		$ewwwdb->update( $ewwwdb->ewwwio_images,
			array(
				'path' => ewww_image_optimizer_relative_path_remove( $retina_path ),
				'attachment_id' => $id,
				'gallery' => 'media',
			),
			array(
				'id' => $optimized_query['id'],
			)
		);
	}
	ewwwio_memory( __FUNCTION__ );
}

/**
 * List IMS images and optimization status.
 *
 * @global object $wpdb
 */
function ewww_image_optimizer_ims() {
	global $wpdb;
	$ims_columns = get_column_headers( 'ims_gallery' );
	echo "<div class='wrap'><h1>" . esc_html__( 'Image Store Optimization', 'ewww-image-optimizer' ) . '</h1>';
	if ( empty( $_REQUEST['ewww_gid'] ) ) {
		$galleries = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type = 'ims_gallery' ORDER BY ID" );
		if ( ewww_image_optimizer_iterable( $galleries ) ) {
			$gallery_string = implode( ',', $galleries );
			echo '<p>' . esc_html__( 'Choose a gallery or', 'ewww-image-optimizer' ) . " <a href='upload.php?page=ewww-image-optimizer-bulk&ids=$gallery_string'>" . esc_html__( 'optimize all galleries', 'ewww-image-optimizer' ) . '</a></p>';
			echo '<table class="wp-list-table widefat media" cellspacing="0"><thead><tr><th>' . esc_html__( 'Gallery ID', 'ewww-image-optimizer' ) . '</th><th>' . esc_html__( 'Gallery Name', 'ewww-image-optimizer' ) . '</th><th>' . esc_html__( 'Images', 'ewww-image-optimizer' ) . '</th><th>' . esc_html__( 'Image Optimizer', 'ewww-image-optimizer' ) . '</th></tr></thead>';
			foreach ( $galleries as $gid ) {
				$image_count = $wpdb->get_var( $wpdb->prepare( "SELECT count(ID) FROM $wpdb->posts WHERE post_type = 'ims_image' AND post_mime_type LIKE '%%image%%' AND post_parent = %d", $gid ) );
				$gallery_name = get_the_title( $gid );
				echo "<tr><td>$gid</td>";
				echo "<td><a href='edit.php?post_type=ims_gallery&page=ewww-ims-optimize&ewww_gid=$gid'>$gallery_name</a></td>";
				echo "<td>$image_count</td>";
				echo "<td><a href='upload.php?page=ewww-image-optimizer-bulk&ids=$gid'>" . esc_html__( 'Optimize Gallery', 'ewww-image-optimizer' ) . '</a></td></tr>';
			}
			echo '</table>';
		} else {
			echo '<p>' . esc_html__( 'No galleries found', 'ewww-image-optimizer' ) . '</p>';
		}
	} else {
		$gid = (int) $_REQUEST['ewww_gid'];
		$attachments = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type = 'ims_image' AND post_mime_type LIKE '%%image%%' AND post_parent = %d ORDER BY ID", $gid ) );
		if ( ewww_image_optimizer_iterable( $attachments ) ) {
			echo "<p><a href='upload.php?page=ewww-image-optimizer-bulk&ids=$gid'>" . esc_html__( 'Optimize Gallery', 'ewww-image-optimizer' ) . '</a></p>';
			echo '<table class="wp-list-table widefat media" cellspacing="0"><thead><tr><th>ID</th><th>&nbsp;</th><th>' . esc_html__( 'Title', 'ewww-image-optimizer' ) . '</th><th>' . esc_html__( 'Gallery', 'ewww-image-optimizer' ) . '</th><th>' . esc_html__( 'Image Optimizer', 'ewww-image-optimizer' ) . '</th></tr></thead>';
			$alternate = true;
			foreach ( $attachments as $id ) {
				$meta = get_metadata( 'post', $id );
				if ( empty( $meta['_wp_attachment_metadata'] ) ) {
					continue;
				}
				$meta = maybe_unserialize( $meta['_wp_attachment_metadata'][0] );
				$image_name = get_the_title( $id );
				$gallery_name = get_the_title( $gid );
				$image_url = esc_url( $meta['sizes']['mini']['url'] );
?>				<tr<?php if ( $alternate ) { echo " class='alternate'"; } ?>><td><?php echo $id; ?></td>
<?php				echo "<td style='width:80px' class='column-icon'><img src='$image_url' /></td>";
				echo "<td class='title'>$image_name</td>";
				echo "<td>$gallery_name</td><td>";
				ewww_image_optimizer_custom_column( 'ewww-image-optimizer', $id );
				echo '</td></tr>';
				$alternate = ! $alternate;
			}
			echo '</table>';
		} else {
			echo '<p>' . esc_html__( 'No images found', 'ewww-image-optimizer' ) . '</p>';
		}
	} // End if().
	echo '</div>';
	return;
}

/**
 * Optimize MyArcade screenshots and thumbs.
 *
 * @param string $url The address of the image to be processed.
 * @return string The unaltered url/address.
 */
function ewww_image_optimizer_myarcade_thumbnail( $url ) {
	ewwwio_debug_message( "thumb url passed: $url" );
	if ( ! empty( $url ) ) {
		$thumb_path = str_replace( get_option( 'siteurl' ) . '/', ABSPATH, $url );
		ewwwio_debug_message( "myarcade thumb path generated: $thumb_path" );
		ewww_image_optimizer( $thumb_path );
	}
	return $url;
}

/**
 * Load full webp script when SCRIPT_DEBUG is enabled.
 */
function ewww_image_optimizer_webp_debug_script() {
	if ( ! ewww_image_optimizer_ce_webp_enabled() ) {
		wp_enqueue_script( 'ewww-webp-load-script', plugins_url( '/includes/load_webp.js', __FILE__ ), array( 'jquery' ), EWWW_IMAGE_OPTIMIZER_VERSION );
	}
}

/**
 * Load minified webp script when EWWW_IMAGE_OPTIMIZER_WEBP_EXTERNAL_SCRIPT is set.
 */
function ewww_image_optimizer_webp_min_script() {
	if ( ! ewww_image_optimizer_ce_webp_enabled() ) {
		wp_enqueue_script( 'ewww-webp-load-script', plugins_url( '/includes/load_webp.min.js', __FILE__ ), array( 'jquery' ), EWWW_IMAGE_OPTIMIZER_VERSION );
	}
}

/**
 * Enqueue script dependency for alt webp rewriting when running inline.
 */
function ewww_image_optimizer_webp_load_jquery() {
	if ( ! ewww_image_optimizer_ce_webp_enabled() ) {
		wp_enqueue_script( 'jquery' );
		if ( function_exists( 'wp_add_inline_script' ) ) {
			wp_add_inline_script( 'jquery-migrate',
				'function check_webp_feature(a,b){var c={alpha:"UklGRkoAAABXRUJQVlA4WAoAAAAQAAAAAAAAAAAAQUxQSAwAAAARBxAR/Q9ERP8DAABWUDggGAAAABQBAJ0BKgEAAQAAAP4AAA3AAP7mtQAAAA==",animation:"UklGRlIAAABXRUJQVlA4WAoAAAASAAAAAAAAAAAAQU5JTQYAAAD/////AABBTk1GJgAAAAAAAAAAAAAAAAAAAGQAAABWUDhMDQAAAC8AAAAQBxAREYiI/gcA"},d=!1,e=new Image;e.onload=function(){var a=e.width>0&&e.height>0;d=!0,b(a)},e.onerror=function(){d=!1,b(!1)},e.src="data:image/webp;base64,"+c[a]}function ewww_load_images(a){jQuery(document).arrive(".ewww_webp",function(){ewww_load_images(a)}),function(b){function d(a,d){for(var e=["align","alt","border","crossorigin","height","hspace","ismap","longdesc","usemap","vspace","width","accesskey","class","contenteditable","contextmenu","dir","draggable","dropzone","hidden","id","lang","spellcheck","style","tabindex","title","translate","sizes","data-attachment-id","data-permalink","data-orig-size","data-comments-opened","data-image-meta","data-image-title","data-image-description"],f=0,g=e.length;f<g;f++){var h=b(a).attr(c+e[f]);void 0!==h&&!1!==h&&b(d).attr(e[f],h)}return d}var c="data-";a&&(b(".batch-image img, .image-wrapper a, .ngg-pro-masonry-item a").each(function(){var a=b(this).attr("data-webp");void 0!==a&&!1!==a&&b(this).attr("data-src",a);var a=b(this).attr("data-webp-thumbnail");void 0!==a&&!1!==a&&b(this).attr("data-thumbnail",a)}),b(".image-wrapper a, .ngg-pro-masonry-item a").each(function(){var a=b(this).attr("data-webp");void 0!==a&&!1!==a&&b(this).attr("href",a)}),b(".rev_slider ul li").each(function(){var a=b(this).attr("data-webp-thumb");void 0!==a&&!1!==a&&b(this).attr("data-thumb",a);for(var c=1;c<11;){var a=b(this).attr("data-webp-param"+c);void 0!==a&&!1!==a&&b(this).attr("data-param"+c,a),c++}}),b(".rev_slider img").each(function(){var a=b(this).attr("data-webp-lazyload");void 0!==a&&!1!==a&&b(this).attr("data-lazyload",a)})),b("img.ewww_webp_lazy_retina").each(function(){if(a){var c=b(this).attr("data-srcset-webp");void 0!==c&&!1!==c&&b(this).attr("data-srcset",c)}else{var c=b(this).attr("data-srcset-img");void 0!==c&&!1!==c&&b(this).attr("data-srcset",c)}b(this).removeClass("ewww_webp_lazy_retina")}),b("video").each(function(){if(a){var c=b(this).attr("data-poster-webp");void 0!==c&&!1!==c&&b(this).attr("poster",c)}else{var c=b(this).attr("data-poster-image");void 0!==c&&!1!==c&&b(this).attr("poster",c)}}),b("img.ewww_webp_lazy_load").each(function(){if(a){b(this).attr("data-lazy-src",b(this).attr("data-lazy-webp-src"));var c=b(this).attr("data-srcset-webp");void 0!==c&&!1!==c&&b(this).attr("srcset",c);var c=b(this).attr("data-lazy-srcset-webp");void 0!==c&&!1!==c&&b(this).attr("data-lazy-srcset",c)}else{b(this).attr("data-lazy-src",b(this).attr("data-lazy-img-src"));var c=b(this).attr("data-srcset");void 0!==c&&!1!==c&&b(this).attr("srcset",c);var c=b(this).attr("data-lazy-srcset-img");void 0!==c&&!1!==c&&b(ewww_img).attr("data-lazy-srcset",c)}b(this).removeClass("ewww_webp_lazy_load")}),b(".ewww_webp_lazy_hueman").each(function(){var c=document.createElement("img");if(b(c).attr("src",b(this).attr("data-src")),a){b(c).attr("data-src",b(this).attr("data-webp-src"));var e=b(this).attr("data-srcset-webp");void 0!==e&&!1!==e&&b(c).attr("data-srcset",e)}else{b(c).attr("data-src",b(this).attr("data-img"));var e=b(this).attr("data-srcset-img");void 0!==e&&!1!==e&&b(c).attr("data-srcset",e)}c=d(this,c),b(this).after(c),b(this).removeClass("ewww_webp_lazy_hueman")}),b(".ewww_webp").each(function(){var c=document.createElement("img");if(a){b(c).attr("src",b(this).attr("data-webp"));var e=b(this).attr("data-srcset-webp");void 0!==e&&!1!==e&&b(c).attr("srcset",e);var e=b(this).attr("data-webp-orig-file");void 0!==e&&!1!==e&&b(c).attr("data-orig-file",e);var e=b(this).attr("data-webp-medium-file");void 0!==e&&!1!==e&&b(c).attr("data-medium-file",e);var e=b(this).attr("data-webp-large-file");void 0!==e&&!1!==e&&b(c).attr("data-large-file",e)}else{b(c).attr("src",b(this).attr("data-img"));var e=b(this).attr("data-srcset-img");void 0!==e&&!1!==e&&b(c).attr("srcset",e)}c=d(this,c),b(this).after(c),b(this).removeClass("ewww_webp")})}(jQuery),jQuery.fn.isotope&&jQuery.fn.imagesLoaded&&(jQuery(".fusion-posts-container-infinite").imagesLoaded(function(){jQuery(".fusion-posts-container-infinite").hasClass("isotope")&&jQuery(".fusion-posts-container-infinite").isotope()}),jQuery(".fusion-portfolio:not(.fusion-recent-works) .fusion-portfolio-wrapper").imagesLoaded(function(){jQuery(".fusion-portfolio:not(.fusion-recent-works) .fusion-portfolio-wrapper").isotope()}))}var Arrive=function(a,b,c){"use strict";function l(a,b,c){e.addMethod(b,c,a.unbindEvent),e.addMethod(b,c,a.unbindEventWithSelectorOrCallback),e.addMethod(b,c,a.unbindEventWithSelectorAndCallback)}function m(a){a.arrive=j.bindEvent,l(j,a,"unbindArrive"),a.leave=k.bindEvent,l(k,a,"unbindLeave")}if(a.MutationObserver&&"undefined"!=typeof HTMLElement){var d=0,e=function(){var b=HTMLElement.prototype.matches||HTMLElement.prototype.webkitMatchesSelector||HTMLElement.prototype.mozMatchesSelector||HTMLElement.prototype.msMatchesSelector;return{matchesSelector:function(a,c){return a instanceof HTMLElement&&b.call(a,c)},addMethod:function(a,b,c){var d=a[b];a[b]=function(){return c.length==arguments.length?c.apply(this,arguments):"function"==typeof d?d.apply(this,arguments):void 0}},callCallbacks:function(a){for(var c,b=0;c=a[b];b++)c.callback.call(c.elem)},checkChildNodesRecursively:function(a,b,c,d){for(var g,f=0;g=a[f];f++)c(g,b,d)&&d.push({callback:b.callback,elem:g}),g.childNodes.length>0&&e.checkChildNodesRecursively(g.childNodes,b,c,d)},mergeArrays:function(a,b){var d,c={};for(d in a)c[d]=a[d];for(d in b)c[d]=b[d];return c},toElementsArray:function(b){return void 0===b||"number"==typeof b.length&&b!==a||(b=[b]),b}}}(),f=function(){var a=function(){this._eventsBucket=[],this._beforeAdding=null,this._beforeRemoving=null};return a.prototype.addEvent=function(a,b,c,d){var e={target:a,selector:b,options:c,callback:d,firedElems:[]};return this._beforeAdding&&this._beforeAdding(e),this._eventsBucket.push(e),e},a.prototype.removeEvent=function(a){for(var c,b=this._eventsBucket.length-1;c=this._eventsBucket[b];b--)a(c)&&(this._beforeRemoving&&this._beforeRemoving(c),this._eventsBucket.splice(b,1))},a.prototype.beforeAdding=function(a){this._beforeAdding=a},a.prototype.beforeRemoving=function(a){this._beforeRemoving=a},a}(),g=function(b,d){var g=new f,h=this,i={fireOnAttributesModification:!1};return g.beforeAdding(function(c){var i,e=c.target;c.selector,c.callback;e!==a.document&&e!==a||(e=document.getElementsByTagName("html")[0]),i=new MutationObserver(function(a){d.call(this,a,c)});var j=b(c.options);i.observe(e,j),c.observer=i,c.me=h}),g.beforeRemoving(function(a){a.observer.disconnect()}),this.bindEvent=function(a,b,c){b=e.mergeArrays(i,b);for(var d=e.toElementsArray(this),f=0;f<d.length;f++)g.addEvent(d[f],a,b,c)},this.unbindEvent=function(){var a=e.toElementsArray(this);g.removeEvent(function(b){for(var d=0;d<a.length;d++)if(this===c||b.target===a[d])return!0;return!1})},this.unbindEventWithSelectorOrCallback=function(a){var f,b=e.toElementsArray(this),d=a;f="function"==typeof a?function(a){for(var e=0;e<b.length;e++)if((this===c||a.target===b[e])&&a.callback===d)return!0;return!1}:function(d){for(var e=0;e<b.length;e++)if((this===c||d.target===b[e])&&d.selector===a)return!0;return!1},g.removeEvent(f)},this.unbindEventWithSelectorAndCallback=function(a,b){var d=e.toElementsArray(this);g.removeEvent(function(e){for(var f=0;f<d.length;f++)if((this===c||e.target===d[f])&&e.selector===a&&e.callback===b)return!0;return!1})},this},h=function(){function h(a){var b={attributes:!1,childList:!0,subtree:!0};return a.fireOnAttributesModification&&(b.attributes=!0),b}function i(a,b){a.forEach(function(a){var c=a.addedNodes,d=a.target,f=[];null!==c&&c.length>0?e.checkChildNodesRecursively(c,b,k,f):"attributes"===a.type&&k(d,b,f)&&f.push({callback:b.callback,elem:node}),e.callCallbacks(f)})}function k(a,b,f){if(e.matchesSelector(a,b.selector)&&(a._id===c&&(a._id=d++),-1==b.firedElems.indexOf(a._id))){if(b.options.onceOnly){if(0!==b.firedElems.length)return;b.me.unbindEventWithSelectorAndCallback.call(b.target,b.selector,b.callback)}b.firedElems.push(a._id),f.push({callback:b.callback,elem:a})}}var f={fireOnAttributesModification:!1,onceOnly:!1,existing:!1};j=new g(h,i);var l=j.bindEvent;return j.bindEvent=function(a,b,c){void 0===c?(c=b,b=f):b=e.mergeArrays(f,b);var d=e.toElementsArray(this);if(b.existing){for(var g=[],h=0;h<d.length;h++)for(var i=d[h].querySelectorAll(a),j=0;j<i.length;j++)g.push({callback:c,elem:i[j]});if(b.onceOnly&&g.length)return c.call(g[0].elem);setTimeout(e.callCallbacks,1,g)}l.call(this,a,b,c)},j},i=function(){function d(a){return{childList:!0,subtree:!0}}function f(a,b){a.forEach(function(a){var c=a.removedNodes,f=(a.target,[]);null!==c&&c.length>0&&e.checkChildNodesRecursively(c,b,h,f),e.callCallbacks(f)})}function h(a,b){return e.matchesSelector(a,b.selector)}var c={};k=new g(d,f);var i=k.bindEvent;return k.bindEvent=function(a,b,d){void 0===d?(d=b,b=c):b=e.mergeArrays(c,b),i.call(this,a,b,d)},k},j=new h,k=new i;b&&m(b.fn),m(HTMLElement.prototype),m(NodeList.prototype),m(HTMLCollection.prototype),m(HTMLDocument.prototype),m(Window.prototype);var n={};return l(j,n,"unbindAllArrive"),l(k,n,"unbindAllLeave"),n}}(window,"undefined"==typeof jQuery?null:jQuery,void 0);"undefined"!=typeof jQuery&&check_webp_feature("alpha",ewww_load_images);'
			);
		}
	}
}

/**
 * Load minified inline version of webp script (from jscompress.com).
 */
function ewww_image_optimizer_webp_inline_script() {
	if ( ! ewww_image_optimizer_ce_webp_enabled() ) {
?>
<script>
function check_webp_feature(a,b){var c={alpha:"UklGRkoAAABXRUJQVlA4WAoAAAAQAAAAAAAAAAAAQUxQSAwAAAARBxAR/Q9ERP8DAABWUDggGAAAABQBAJ0BKgEAAQAAAP4AAA3AAP7mtQAAAA==",animation:"UklGRlIAAABXRUJQVlA4WAoAAAASAAAAAAAAAAAAQU5JTQYAAAD/////AABBTk1GJgAAAAAAAAAAAAAAAAAAAGQAAABWUDhMDQAAAC8AAAAQBxAREYiI/gcA"},d=!1,e=new Image;e.onload=function(){var a=e.width>0&&e.height>0;d=!0,b(a)},e.onerror=function(){d=!1,b(!1)},e.src="data:image/webp;base64,"+c[a]}function ewww_load_images(a){jQuery(document).arrive(".ewww_webp",function(){ewww_load_images(a)}),function(b){function d(a,d){for(var e=["align","alt","border","crossorigin","height","hspace","ismap","longdesc","usemap","vspace","width","accesskey","class","contenteditable","contextmenu","dir","draggable","dropzone","hidden","id","lang","spellcheck","style","tabindex","title","translate","sizes","data-attachment-id","data-permalink","data-orig-size","data-comments-opened","data-image-meta","data-image-title","data-image-description"],f=0,g=e.length;f<g;f++){var h=b(a).attr(c+e[f]);void 0!==h&&!1!==h&&b(d).attr(e[f],h)}return d}var c="data-";a&&(b(".batch-image img, .image-wrapper a, .ngg-pro-masonry-item a").each(function(){var a=b(this).attr("data-webp");void 0!==a&&!1!==a&&b(this).attr("data-src",a);var a=b(this).attr("data-webp-thumbnail");void 0!==a&&!1!==a&&b(this).attr("data-thumbnail",a)}),b(".image-wrapper a, .ngg-pro-masonry-item a").each(function(){var a=b(this).attr("data-webp");void 0!==a&&!1!==a&&b(this).attr("href",a)}),b(".rev_slider ul li").each(function(){var a=b(this).attr("data-webp-thumb");void 0!==a&&!1!==a&&b(this).attr("data-thumb",a);for(var c=1;c<11;){var a=b(this).attr("data-webp-param"+c);void 0!==a&&!1!==a&&b(this).attr("data-param"+c,a),c++}}),b(".rev_slider img").each(function(){var a=b(this).attr("data-webp-lazyload");void 0!==a&&!1!==a&&b(this).attr("data-lazyload",a)})),b("img.ewww_webp_lazy_retina").each(function(){if(a){var c=b(this).attr("data-srcset-webp");void 0!==c&&!1!==c&&b(this).attr("data-srcset",c)}else{var c=b(this).attr("data-srcset-img");void 0!==c&&!1!==c&&b(this).attr("data-srcset",c)}b(this).removeClass("ewww_webp_lazy_retina")}),b("video").each(function(){if(a){var c=b(this).attr("data-poster-webp");void 0!==c&&!1!==c&&b(this).attr("poster",c)}else{var c=b(this).attr("data-poster-image");void 0!==c&&!1!==c&&b(this).attr("poster",c)}}),b("img.ewww_webp_lazy_load").each(function(){if(a){b(this).attr("data-lazy-src",b(this).attr("data-lazy-webp-src"));var c=b(this).attr("data-srcset-webp");void 0!==c&&!1!==c&&b(this).attr("srcset",c);var c=b(this).attr("data-lazy-srcset-webp");void 0!==c&&!1!==c&&b(this).attr("data-lazy-srcset",c)}else{b(this).attr("data-lazy-src",b(this).attr("data-lazy-img-src"));var c=b(this).attr("data-srcset");void 0!==c&&!1!==c&&b(this).attr("srcset",c);var c=b(this).attr("data-lazy-srcset-img");void 0!==c&&!1!==c&&b(ewww_img).attr("data-lazy-srcset",c)}b(this).removeClass("ewww_webp_lazy_load")}),b(".ewww_webp_lazy_hueman").each(function(){var c=document.createElement("img");if(b(c).attr("src",b(this).attr("data-src")),a){b(c).attr("data-src",b(this).attr("data-webp-src"));var e=b(this).attr("data-srcset-webp");void 0!==e&&!1!==e&&b(c).attr("data-srcset",e)}else{b(c).attr("data-src",b(this).attr("data-img"));var e=b(this).attr("data-srcset-img");void 0!==e&&!1!==e&&b(c).attr("data-srcset",e)}c=d(this,c),b(this).after(c),b(this).removeClass("ewww_webp_lazy_hueman")}),b(".ewww_webp").each(function(){var c=document.createElement("img");if(a){b(c).attr("src",b(this).attr("data-webp"));var e=b(this).attr("data-srcset-webp");void 0!==e&&!1!==e&&b(c).attr("srcset",e);var e=b(this).attr("data-webp-orig-file");void 0!==e&&!1!==e&&b(c).attr("data-orig-file",e);var e=b(this).attr("data-webp-medium-file");void 0!==e&&!1!==e&&b(c).attr("data-medium-file",e);var e=b(this).attr("data-webp-large-file");void 0!==e&&!1!==e&&b(c).attr("data-large-file",e)}else{b(c).attr("src",b(this).attr("data-img"));var e=b(this).attr("data-srcset-img");void 0!==e&&!1!==e&&b(c).attr("srcset",e)}c=d(this,c),b(this).after(c),b(this).removeClass("ewww_webp")})}(jQuery),jQuery.fn.isotope&&jQuery.fn.imagesLoaded&&(jQuery(".fusion-posts-container-infinite").imagesLoaded(function(){jQuery(".fusion-posts-container-infinite").hasClass("isotope")&&jQuery(".fusion-posts-container-infinite").isotope()}),jQuery(".fusion-portfolio:not(.fusion-recent-works) .fusion-portfolio-wrapper").imagesLoaded(function(){jQuery(".fusion-portfolio:not(.fusion-recent-works) .fusion-portfolio-wrapper").isotope()}))}var Arrive=function(a,b,c){"use strict";function l(a,b,c){e.addMethod(b,c,a.unbindEvent),e.addMethod(b,c,a.unbindEventWithSelectorOrCallback),e.addMethod(b,c,a.unbindEventWithSelectorAndCallback)}function m(a){a.arrive=j.bindEvent,l(j,a,"unbindArrive"),a.leave=k.bindEvent,l(k,a,"unbindLeave")}if(a.MutationObserver&&"undefined"!=typeof HTMLElement){var d=0,e=function(){var b=HTMLElement.prototype.matches||HTMLElement.prototype.webkitMatchesSelector||HTMLElement.prototype.mozMatchesSelector||HTMLElement.prototype.msMatchesSelector;return{matchesSelector:function(a,c){return a instanceof HTMLElement&&b.call(a,c)},addMethod:function(a,b,c){var d=a[b];a[b]=function(){return c.length==arguments.length?c.apply(this,arguments):"function"==typeof d?d.apply(this,arguments):void 0}},callCallbacks:function(a){for(var c,b=0;c=a[b];b++)c.callback.call(c.elem)},checkChildNodesRecursively:function(a,b,c,d){for(var g,f=0;g=a[f];f++)c(g,b,d)&&d.push({callback:b.callback,elem:g}),g.childNodes.length>0&&e.checkChildNodesRecursively(g.childNodes,b,c,d)},mergeArrays:function(a,b){var d,c={};for(d in a)c[d]=a[d];for(d in b)c[d]=b[d];return c},toElementsArray:function(b){return void 0===b||"number"==typeof b.length&&b!==a||(b=[b]),b}}}(),f=function(){var a=function(){this._eventsBucket=[],this._beforeAdding=null,this._beforeRemoving=null};return a.prototype.addEvent=function(a,b,c,d){var e={target:a,selector:b,options:c,callback:d,firedElems:[]};return this._beforeAdding&&this._beforeAdding(e),this._eventsBucket.push(e),e},a.prototype.removeEvent=function(a){for(var c,b=this._eventsBucket.length-1;c=this._eventsBucket[b];b--)a(c)&&(this._beforeRemoving&&this._beforeRemoving(c),this._eventsBucket.splice(b,1))},a.prototype.beforeAdding=function(a){this._beforeAdding=a},a.prototype.beforeRemoving=function(a){this._beforeRemoving=a},a}(),g=function(b,d){var g=new f,h=this,i={fireOnAttributesModification:!1};return g.beforeAdding(function(c){var i,e=c.target;c.selector,c.callback;e!==a.document&&e!==a||(e=document.getElementsByTagName("html")[0]),i=new MutationObserver(function(a){d.call(this,a,c)});var j=b(c.options);i.observe(e,j),c.observer=i,c.me=h}),g.beforeRemoving(function(a){a.observer.disconnect()}),this.bindEvent=function(a,b,c){b=e.mergeArrays(i,b);for(var d=e.toElementsArray(this),f=0;f<d.length;f++)g.addEvent(d[f],a,b,c)},this.unbindEvent=function(){var a=e.toElementsArray(this);g.removeEvent(function(b){for(var d=0;d<a.length;d++)if(this===c||b.target===a[d])return!0;return!1})},this.unbindEventWithSelectorOrCallback=function(a){var f,b=e.toElementsArray(this),d=a;f="function"==typeof a?function(a){for(var e=0;e<b.length;e++)if((this===c||a.target===b[e])&&a.callback===d)return!0;return!1}:function(d){for(var e=0;e<b.length;e++)if((this===c||d.target===b[e])&&d.selector===a)return!0;return!1},g.removeEvent(f)},this.unbindEventWithSelectorAndCallback=function(a,b){var d=e.toElementsArray(this);g.removeEvent(function(e){for(var f=0;f<d.length;f++)if((this===c||e.target===d[f])&&e.selector===a&&e.callback===b)return!0;return!1})},this},h=function(){function h(a){var b={attributes:!1,childList:!0,subtree:!0};return a.fireOnAttributesModification&&(b.attributes=!0),b}function i(a,b){a.forEach(function(a){var c=a.addedNodes,d=a.target,f=[];null!==c&&c.length>0?e.checkChildNodesRecursively(c,b,k,f):"attributes"===a.type&&k(d,b,f)&&f.push({callback:b.callback,elem:node}),e.callCallbacks(f)})}function k(a,b,f){if(e.matchesSelector(a,b.selector)&&(a._id===c&&(a._id=d++),-1==b.firedElems.indexOf(a._id))){if(b.options.onceOnly){if(0!==b.firedElems.length)return;b.me.unbindEventWithSelectorAndCallback.call(b.target,b.selector,b.callback)}b.firedElems.push(a._id),f.push({callback:b.callback,elem:a})}}var f={fireOnAttributesModification:!1,onceOnly:!1,existing:!1};j=new g(h,i);var l=j.bindEvent;return j.bindEvent=function(a,b,c){void 0===c?(c=b,b=f):b=e.mergeArrays(f,b);var d=e.toElementsArray(this);if(b.existing){for(var g=[],h=0;h<d.length;h++)for(var i=d[h].querySelectorAll(a),j=0;j<i.length;j++)g.push({callback:c,elem:i[j]});if(b.onceOnly&&g.length)return c.call(g[0].elem);setTimeout(e.callCallbacks,1,g)}l.call(this,a,b,c)},j},i=function(){function d(a){return{childList:!0,subtree:!0}}function f(a,b){a.forEach(function(a){var c=a.removedNodes,f=(a.target,[]);null!==c&&c.length>0&&e.checkChildNodesRecursively(c,b,h,f),e.callCallbacks(f)})}function h(a,b){return e.matchesSelector(a,b.selector)}var c={};k=new g(d,f);var i=k.bindEvent;return k.bindEvent=function(a,b,d){void 0===d?(d=b,b=c):b=e.mergeArrays(c,b),i.call(this,a,b,d)},k},j=new h,k=new i;b&&m(b.fn),m(HTMLElement.prototype),m(NodeList.prototype),m(HTMLCollection.prototype),m(HTMLDocument.prototype),m(Window.prototype);var n={};return l(j,n,"unbindAllArrive"),l(k,n,"unbindAllLeave"),n}}(window,"undefined"==typeof jQuery?null:jQuery,void 0);"undefined"!=typeof jQuery&&check_webp_feature("alpha",ewww_load_images);
</script>
<?php	} // End if().
	// Current length 9473.
}

/**
 * Enqueue custom jquery stylesheet and scripts for the media library AJAX functions.
 *
 * @param string $hook The unique hook of the page being loaded in the WP admin.
 */
function ewww_image_optimizer_media_scripts( $hook ) {
	if ( 'upload.php' == $hook || 'ims_gallery_page_ewww-ims-optimize' == $hook ) {
		add_thickbox();
		wp_enqueue_script( 'jquery-ui-tooltip' );
		wp_enqueue_script( 'ewwwmediascript', plugins_url( '/includes/media.js', __FILE__ ), array( 'jquery' ), EWWW_IMAGE_OPTIMIZER_VERSION );
		wp_enqueue_style( 'jquery-ui-tooltip-custom', plugins_url( '/includes/jquery-ui-1.10.1.custom.css', __FILE__ ), array(), EWWW_IMAGE_OPTIMIZER_VERSION );
		// Submit a couple variables to the javascript to work with.
		$loading_image = plugins_url( '/images/wpspin.gif', __FILE__ );
		$loading_image = plugins_url( '/images/spinner.gif', __FILE__ );
		wp_localize_script(
			'ewwwmediascript',
			'ewww_vars',
			array(
				'optimizing' => '<p>' . esc_html__( 'Optimizing', 'ewww-image-optimizer' ) . "&nbsp;<img src='$loading_image' /></p>",
				'restoring' => '<p>' . esc_html__( 'Restoring', 'ewww-image-optimizer' ) . "&nbsp;<img src='$loading_image' /></p>",
			)
		);
	}
}

/**
 * Adds a link on the Plugins page for the EWWW IO settings.
 *
 * @param array $links A list of links to display next to the plugin listing.
 * @return array The new list of links to be displayed.
 */
function ewww_image_optimizer_settings_link( $links ) {
	if ( ! function_exists( 'is_plugin_active_for_network' ) && is_multisite() ) {
		// Need to include the plugin library for the is_plugin_active function.
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	}
	// Load the html for the settings link.
	if ( is_multisite() && is_plugin_active_for_network( EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE_REL ) ) {
		$settings_link = '<a href="network/settings.php?page=' . plugin_basename( EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE ) . '">' . esc_html__( 'Settings', 'ewww-image-optimizer' ) . '</a>';
	} else {
		$settings_link = '<a href="options-general.php?page=' . plugin_basename( EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE ) . '">' . esc_html__( 'Settings', 'ewww-image-optimizer' ) . '</a>';
	}
	// Load the settings link into the plugin links array.
	array_unshift( $links, $settings_link );
	// Send back the plugin links array.
	return $links;
}

/**
 * Check for GD support of both PNG and JPG.
 *
 * @return bool True if full GD support is detected.
 */
function ewww_image_optimizer_gd_support() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( function_exists( 'gd_info' ) ) {
		$gd_support = gd_info();
		ewwwio_debug_message( 'GD found, supports:' );
		if ( ewww_image_optimizer_iterable( $gd_support ) ) {
			foreach ( $gd_support as $supports => $supported ) {
				 ewwwio_debug_message( "$supports: $supported" );
			}
			ewwwio_memory( __FUNCTION__ );
			if ( ( ! empty( $gd_support['JPEG Support'] ) || ! empty( $gd_support['JPG Support'] ) ) && ! empty( $gd_support['PNG Support'] ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Check for IMagick support of both PNG and JPG.
 *
 * @return bool True if full Imagick support is detected.
 */
function ewww_image_optimizer_imagick_support() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( extension_loaded( 'imagick' ) ) {
		$imagick = new Imagick();
		$formats = $imagick->queryFormats();
		if ( in_array( 'PNG', $formats ) && in_array( 'JPG', $formats ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Check for GMagick support of both PNG and JPG.
 *
 * @return bool True if full Gmagick support is detected.
 */
function ewww_image_optimizer_gmagick_support() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( extension_loaded( 'gmagick' ) ) {
		$gmagick = new Gmagick();
		$formats = $gmagick->queryFormats();
		if ( in_array( 'PNG', $formats ) && in_array( 'JPG', $formats ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Sanitize the list of disabled resizes.
 *
 * @param array $disabled_resizes A list of sizes, like 'medium_large', 'thumb', etc.
 * @return array|string The sanitized list of sizes, or an empty string.
 */
function ewww_image_optimizer_disable_resizes_sanitize( $disabled_resizes ) {
	if ( is_array( $disabled_resizes ) ) {
		return $disabled_resizes;
	} else {
		return '';
	}
}

/**
 * Sanitize the list of folders to optimize.
 *
 * @param string $input A list of filesystem paths, from a textarea.
 * @return array The list of paths, validated, and converted to an array.
 */
function ewww_image_optimizer_aux_paths_sanitize( $input ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( empty( $input ) ) {
		return '';
	}
	$path_array = array();
	$paths = explode( "\n", $input );
	if ( ewww_image_optimizer_iterable( $paths ) ) {
		$i = 0;
		foreach ( $paths as $path ) {
			$i++;
			$path = sanitize_text_field( $path );
			ewwwio_debug_message( "validating auxiliary path: $path" );
			// Retrieve the location of the wordpress upload folder.
			$upload_dir = apply_filters( 'ewww_image_optimizer_folder_restriction', wp_upload_dir( null, false ) );
			// Retrieve the path of the upload folder from the array.
			$upload_path = trailingslashit( $upload_dir['basedir'] );
			if ( is_dir( $path ) && ( strpos( $path, ABSPATH ) === 0 || strpos( $path, $upload_path ) === 0 ) ) {
				$path_array[] = $path;
				continue;
			}
			// If they put in a relative path.
			if ( is_dir( ABSPATH . ltrim( $path, '/' ) ) ) {
				$path_array[] = ABSPATH . ltrim( $path, '/' );
				continue;
			}
			// Or a path relative to the upload dir?
			if ( is_dir( $upload_path . ltrim( $path, '/' ) ) ) {
				$path_array[] = $upload_path . ltrim( $path, '/' );
				continue;
			}
			// What if they put in a url?
			$pathabsurl = ABSPATH . ltrim( str_replace( get_site_url(), '', $path ), '/' );
			if ( is_dir( $pathabsurl ) ) {
				$path_array[] = $pathabsurl;
				continue;
			}
			// Or a url in the uploads folder?
			$pathupurl = $upload_path . ltrim( str_replace( $upload_dir['baseurl'], '', $path ), '/' );
			if ( is_dir( $pathupurl ) ) {
				$path_array[] = $pathupurl;
				continue;
			}
			if ( ! empty( $path ) ) {
				add_settings_error( 'ewww_image_optimizer_aux_paths', "ewwwio-aux-paths-$i", sprintf(
					/* translators: %s: A file system path */
					esc_html__( 'Could not save Folder to Optimize: %s. Please ensure that it is a valid location on the server.', 'ewww-image-optimizer' ), esc_html( $path )
				) );
			}
		} // End foreach().
	} // End if().
	ewwwio_memory( __FUNCTION__ );
	return $path_array;
}

/**
 * Sanitize the folders/patterns to exclude from optimization.
 *
 * @param string $input A list of filesystem paths, from a textarea.
 * @return array The sanitized list of paths/patterns to exclude.
 */
function ewww_image_optimizer_exclude_paths_sanitize( $input ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( empty( $input ) ) {
		return '';
	}
	$path_array = array();
	$paths = explode( "\n", $input );
	if ( ewww_image_optimizer_iterable( $paths ) ) {
		$i = 0;
		foreach ( $paths as $path ) {
			$i++;
			ewwwio_debug_message( "validating path exclusion: $path" );
			$path = sanitize_text_field( $path );
			if ( ! empty( $path ) ) {
				$path_array[] = $path;
			}
		}
	}
	ewwwio_memory( __FUNCTION__ );
	return $path_array;
}

/**
 * Sanitize the url patterns used for WebP rewriting.
 *
 * @param string $paths A list of urls/patterns, from a textarea.
 * @return array The sanitize list of url patterns for WebP matching.
 */
function ewww_image_optimizer_webp_paths_sanitize( $paths ) {
	if ( empty( $paths ) ) {
		return '';
	}
	$paths_entered = explode( "\n", $paths );
	$paths_saved = array();
	if ( ewww_image_optimizer_iterable( $paths_entered ) ) {
		$i = 0;
		foreach ( $paths_entered as $path ) {
			$i++;
			$original_path = esc_html( $path );
			$path = esc_url( $path, null, 'db' );
			if ( ! empty( $path ) ) {
				if ( ! substr_count( $path, '.' ) ) {
					add_settings_error( 'ewww_image_optimizer_webp_paths', "ewwwio-webp-paths-$i", sprintf(
						/* translators: %s: A url or domain name */
						esc_html__( 'Could not save WebP URL: %s.', 'ewww-image-optimizer' ), esc_html( $original_path )
					) . ' ' . esc_html__( 'Please enter a valid url including the domain name.', 'ewww-image-optimizer' ) );
					continue;
				}
				$paths_saved[] = str_replace( 'http://', '', $path );
			}
		}
	}
	return $paths_saved;
}

/**
 * Replacement for escapeshellarg() that won't kill non-ASCII characters.
 *
 * @param string $arg A value to sanitize/escape for commmand-line usage.
 * @return string The value after being escaped.
 */
function ewww_image_optimizer_escapeshellarg( $arg ) {
	if ( PHP_OS === 'WINNT' ) {
		$safe_arg = '"' . $arg . '"';
	} else {
		$safe_arg = "'" . str_replace( "'", "'\"'\"'", $arg ) . "'";
	}
	ewwwio_memory( __FUNCTION__ );
	return $safe_arg;
}

/**
 * Retrieves/sanitizes jpg background fill setting or returns null for png2jpg conversions.
 *
 * @param string $background The hexadecimal value entered by the user.
 * @return string The background color sanitized.
 */
function ewww_image_optimizer_jpg_background( $background = null ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( null === $background ) {
		// Retrieve the user-supplied value for jpg background color.
		$background = ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_background' );
	}
	// Verify that the supplied value is in hex notation.
	if ( preg_match( '/^\#*([0-9a-fA-F]){6}$/', $background ) ) {
		// We remove a leading # symbol, since we take care of it later.
		$background = ltrim( $background, '#' );
		// Send back the verified, cleaned-up background color.
		ewwwio_debug_message( "background: $background" );
		ewwwio_memory( __FUNCTION__ );
		return $background;
	} else {
		if ( ! empty( $background ) ) {
			add_settings_error( 'ewww_image_optimizer_jpg_background', 'ewwwio-jpg-background', esc_html__( 'Could not save the JPG background color, please enter a six-character, hexadecimal value.', 'ewww-image-optimizer' ) );
		}
		// Send back a blank value.
		ewwwio_memory( __FUNCTION__ );
		return null;
	}
}

/**
 * Retrieves/sanitizes the jpg quality setting for png2jpg conversion or returns null.
 *
 * @param int $quality The JPG quality level as set by the user.
 * @return int The sanitize JPG quality level.
 */
function ewww_image_optimizer_jpg_quality( $quality = null ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( null === $quality ) {
		// Retrieve the user-supplied value for jpg quality.
		$quality = ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_quality' );
	}
	// Verify that the quality level is an integer, 1-100.
	if ( preg_match( '/^(100|[1-9][0-9]?)$/', $quality ) ) {
		ewwwio_debug_message( "quality: $quality" );
		// Send back the valid quality level.
		ewwwio_memory( __FUNCTION__ );
		return $quality;
	} else {
		if ( ! empty( $quality ) ) {
			add_settings_error( 'ewww_image_optimizer_jpg_quality', 'ewwwio-jpg-quality', esc_html__( 'Could not save the JPG quality, please enter an integer between 1 and 100.', 'ewww-image-optimizer' ) );
		}
		// Send back nothing.
		ewwwio_memory( __FUNCTION__ );
		return null;
	}
}

/**
 * Filter the filename past any folders the user chose to ignore.
 *
 * @param bool   $bypass True to skip optimization, defaults to false.
 * @param string $filename The file about to be optimized.
 * @return bool True if the file matches any folders to ignore.
 */
function ewww_image_optimizer_ignore_file( $bypass, $filename ) {
	$ignore_folders = ewww_image_optimizer_get_option( 'ewww_image_optimizer_exclude_paths' );
	if ( ! ewww_image_optimizer_iterable( $ignore_folders ) ) {
		return $bypass;
	}
	foreach ( $ignore_folders as $ignore_folder ) {
		if ( strpos( $filename, $ignore_folder ) !== false ) {
			return true;
		}
	}
	return $bypass;
}

/**
 * Overrides the default JPG quality for WordPress image editing operations.
 *
 * @param int $quality The default JPG quality level.
 * @return int The default quality, or the user configured level.
 */
function ewww_image_optimizer_set_jpg_quality( $quality ) {
	$new_quality = ewww_image_optimizer_jpg_quality();
	if ( ! empty( $new_quality ) ) {
		return $new_quality;
	}
	return $quality;
}

/**
 * Check filesize, and prevent errors by ensuring file exists, and that the cache has been cleared.
 *
 * @param string $file The name of the file.
 * @return int The size of the file or zero.
 */
function ewww_image_optimizer_filesize( $file ) {
	if ( is_file( $file ) ) {
		// Flush the cache for filesize.
		clearstatcache();
		// Find out the size of the new PNG file.
		return filesize( $file );
	} else {
		return 0;
	}
}

/**
 * Make sure an array/object can be parsed by a foreach().
 *
 * @param mixed $var A variable to test for iteration ability.
 * @return bool True if the variable is iterable.
 */
function ewww_image_optimizer_iterable( $var ) {
	return ! empty( $var ) && ( is_array( $var ) || is_object( $var ) );
}

/**
 * Manually process an image from the Media Library
 *
 * @global bool $ewww_defer True if the image optimization should be deferred.
 */
function ewww_image_optimizer_manual() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	global $ewww_defer;
	$ewww_defer = false;
	// Check permissions of current user.
	$permissions = apply_filters( 'ewww_image_optimizer_manual_permissions', '' );
	if ( false === current_user_can( $permissions ) ) {
		// Display error message if insufficient permissions.
		if ( ! wp_doing_ajax() ) {
			wp_die( esc_html__( 'You do not have permission to optimize images.', 'ewww-image-optimizer' ) );
		}
		wp_die( json_encode( array(
			'error' => esc_html__( 'You do not have permission to optimize images.', 'ewww-image-optimizer' ),
		) ) );
	}
	// Make sure we didn't accidentally get to this page without an attachment to work on.
	if ( false === isset( $_REQUEST['ewww_attachment_ID'] ) ) {
		// Display an error message since we don't have anything to work on.
		if ( ! wp_doing_ajax() ) {
			wp_die( esc_html__( 'No attachment ID was provided.', 'ewww-image-optimizer' ) );
		}
		wp_die( json_encode( array(
			'error' => esc_html__( 'No attachment ID was provided.', 'ewww-image-optimizer' ),
		) ) );
	}
	session_write_close();
	// Store the attachment ID value.
	$attachment_id = intval( $_REQUEST['ewww_attachment_ID'] );
	if ( empty( $_REQUEST['ewww_manual_nonce'] ) || ! wp_verify_nonce( $_REQUEST['ewww_manual_nonce'], 'ewww-manual' ) ) {
		if ( ! wp_doing_ajax() ) {
			wp_die( esc_html__( 'Access denied.', 'ewww-image-optimizer' ) );
		}
		wp_die( json_encode( array(
			'error' => esc_html__( 'Access denied.', 'ewww-image-optimizer' ),
		) ) );
	}
	// Retrieve the existing attachment metadata.
	$original_meta = wp_get_attachment_metadata( $attachment_id );
	// If the call was to optimize...
	if ( 'ewww_image_optimizer_manual_optimize' === $_REQUEST['action'] ||  'ewww_manual_optimize' === $_REQUEST['action'] ) {
		// Call the optimize from metadata function and store the resulting new metadata.
		$new_meta = ewww_image_optimizer_resize_from_meta_data( $original_meta, $attachment_id );
	} elseif ( 'ewww_image_optimizer_manual_restore' === $_REQUEST['action'] || 'ewww_manual_restore' === $_REQUEST['action'] ) {
		$new_meta = ewww_image_optimizer_restore_from_meta_data( $original_meta, $attachment_id );
	} elseif ( 'ewww_image_optimizer_manual_cloud_restore' === $_REQUEST['action'] || 'ewww_manual_cloud_restore' === $_REQUEST['action'] ) {
		ewww_image_optimizer_cloud_restore_from_meta_data( $attachment_id, 'media' );
		$new_meta = $original_meta;
	} else {
		if ( ! wp_doing_ajax() ) {
			wp_die( esc_html__( 'Access denied.', 'ewww-image-optimizer' ) );
		}
		wp_die( json_encode( array(
			'error' => esc_html__( 'Access denied.', 'ewww-image-optimizer' ),
		) ) );
	}
	$basename = '';
	if ( is_array( $new_meta ) && ! empty( $new_meta['file'] ) ) {
		$basename = basename( $new_meta['file'] );
	}
	// Update the attachment metadata in the database.
	$meta_saved = wp_update_attachment_metadata( $attachment_id, $new_meta );
	if ( ! $meta_saved ) {
		ewwwio_debug_message( 'failed to save meta' );
	}
	if ( get_transient( 'ewww_image_optimizer_cloud_status' ) == 'exceeded' || ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_exceeded' ) > time() ) {
		if ( ! wp_doing_ajax() ) {
			wp_die( esc_html__( 'License exceeded', 'ewww-image-optimizer' ) );
		}
		wp_die( json_encode( array(
			'error' => esc_html__( 'License exceeded', 'ewww-image-optimizer' ),
		) ) );
	}
	$success = ewww_image_optimizer_custom_column( 'ewww-image-optimizer', $attachment_id, $new_meta, true );
	ewww_image_optimizer_debug_log();
	// Do a redirect, if this was called via GET.
	if ( ! wp_doing_ajax() ) {
		// Store the referring webpage location.
		$sendback = wp_get_referer();
		// Sanitize the referring webpage location.
		$sendback = preg_replace( '|[^a-z0-9-~+_.?#=&;,/:]|i', '', $sendback );
		// Send the user back where they came from.
		wp_redirect( $sendback );
		return;
	}
	ewwwio_memory( __FUNCTION__ );
	die( json_encode( array(
		'success' => $success,
		'basename' => $basename,
	) ) );
}

/**
 * Manually restore a converted image.
 *
 * @global object $wpdb
 * @global object $ewwwdb A clone of $wpdb unless it is lacking utf8 connectivity.
 *
 * @param array $meta The attachment metadata.
 * @param int   $id The attachment id number.
 * @return array The attachment metadata.
 */
function ewww_image_optimizer_restore_from_meta_data( $meta, $id ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	global $wpdb;
	if ( strpos( $wpdb->charset, 'utf8' ) === false ) {
		ewww_image_optimizer_db_init();
		global $ewwwdb;
	} else {
		$ewwwdb = $wpdb;
	}
	$db_image = $ewwwdb->get_results( "SELECT id,path,converted FROM $ewwwdb->ewwwio_images WHERE attachment_id = $id AND gallery = 'media' AND resize = 'full'", ARRAY_A );
	if ( empty( $db_image ) || ! is_array( $db_image ) || empty( $db_image['path'] ) ) {
		// Get the filepath based on the meta and id.
		list( $file_path, $upload_path ) = ewww_image_optimizer_attachment_path( $meta, $id );
		$db_image = ewww_image_optimizer_find_already_optimized( $file_path );
		if ( empty( $db_image ) || ! is_array( $db_image ) || empty( $db_image['path'] ) ) {
			return $meta;
		}
	}
	$ewww_image = new EWWW_Image( $id, 'media', ewww_image_optimizer_relative_path_replace( $db_image['path'] ) );
	return $ewww_image->restore_with_meta( $meta );
}

/**
 * Manually restore an attachment from the API
 *
 * @global object $wpdb
 * @global object $ewwwdb A clone of $wpdb unless it is lacking utf8 connectivity.
 *
 * @param int    $id The attachment id number.
 * @param string $gallery Optional. The gallery from whence we came. Default 'media'.
 */
function ewww_image_optimizer_cloud_restore_from_meta_data( $id, $gallery = 'media' ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	global $wpdb;
	if ( strpos( $wpdb->charset, 'utf8' ) === false ) {
		ewww_image_optimizer_db_init();
		global $ewwwdb;
	} else {
		$ewwwdb = $wpdb;
	}
	$images = $ewwwdb->get_results( "SELECT id,path,backup FROM $ewwwdb->ewwwio_images WHERE attachment_id = $id AND gallery = '$gallery'", ARRAY_A );
	foreach ( $images as $image ) {
		if ( ! empty( $image['path'] ) ) {
			$image['path'] = ewww_image_optimizer_relative_path_replace( $image['path'] );
		}
		ewww_image_optimizer_cloud_restore_single_image( $image );
	}
}

/**
 * Handle the AJAX call for a single image restore.
 */
function ewww_image_optimizer_cloud_restore_single_image_handler() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	// Check permissions of current user.
	$permissions = apply_filters( 'ewww_image_optimizer_manual_permissions', '' );
	if ( false === current_user_can( $permissions ) ) {
		// Display error message if insufficient permissions.
		wp_die( json_encode( array(
			'error' => esc_html__( 'You do not have permission to optimize images.', 'ewww-image-optimizer' ),
		) ) );
	}
	// Make sure we didn't accidentally get to this page without an attachment to work on.
	if ( empty( $_REQUEST['ewww_image_id'] ) ) {
		// Display an error message since we don't have anything to work on.
		wp_die( json_encode( array(
			'error' => esc_html__( 'No image ID was provided.', 'ewww-image-optimizer' ),
		) ) );
	}
	if ( empty( $_REQUEST['ewww_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['ewww_wpnonce'], 'ewww-image-optimizer-bulk' ) ) {
		wp_die( json_encode( array(
			'error' => esc_html__( 'Access token has expired, please reload the page.', 'ewww-image-optimizer' ),
		) ) );
	}
	session_write_close();
	$image = (int) $_REQUEST['ewww_image_id'];
	if ( ewww_image_optimizer_cloud_restore_single_image( $image ) ) {
		wp_die( json_encode( array(
			'success' => 1,
		) ) );
	}
	wp_die( json_encode( array(
		'error' => esc_html__( 'Unable to restore image.', 'ewww-image-optimizer' ),
	) ) );
}

/**
 * Restores a single image from the API.
 *
 * @global object $wpdb
 * @global object $ewwwdb A clone of $wpdb unless it is lacking utf8 connectivity.
 *
 * @param string $image The filename of the image to restore.
 * @return bool True if the image was restored successfully.
 */
function ewww_image_optimizer_cloud_restore_single_image( $image ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	global $wpdb;
	if ( strpos( $wpdb->charset, 'utf8' ) === false ) {
		ewww_image_optimizer_db_init();
		global $ewwwdb;
	} else {
		$ewwwdb = $wpdb;
	}
	if ( ! is_array( $image ) && ! empty( $image ) && is_numeric( $image ) ) {
		$image = $ewwwdb->get_row( "SELECT id,path,backup FROM $ewwwdb->ewwwio_images WHERE id = $image", ARRAY_A );
	}
	if ( ! empty( $image['path'] ) ) {
		$image['path'] = ewww_image_optimizer_relative_path_replace( $image['path'] );
	}
	$ewww_cloud_transport = get_transient( 'ewww_image_optimizer_cloud_transport' );
	if ( empty( $ewww_cloud_transport ) ) {
		if ( ! ewww_image_optimizer_cloud_verify() ) {
			return false;
		} else {
			$ewww_cloud_transport = get_transient( 'ewww_image_optimizer_cloud_transport' );
		}
	}
	if ( empty( $ewww_cloud_transport ) ) {
		$ewww_cloud_transport = 'https';
	}
	$api_key = ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' );
	$domain = parse_url( get_site_url(), PHP_URL_HOST );
	$url = "$ewww_cloud_transport://optimize.exactlywww.com/backup/restore.php";
	$result = wp_remote_post( $url, array(
		'timeout' => 30,
		'sslverify' => false,
		'body' => array(
			'api_key' => $api_key,
			'domain' => $domain,
			'hash' => $image['backup'],
		),
	) );
	if ( is_wp_error( $result ) ) {
		$error_message = $result->get_error_message();
		ewwwio_debug_message( "quota request failed: $error_message" );
		ewwwio_memory( __FUNCTION__ );
		return false;
	} elseif ( ! empty( $result['body'] ) && strpos( $result['body'], 'missing' ) === false ) {
		$enabled_types = array( 'image/jpeg', 'image/png', 'image/gif', 'application/pdf' );
		file_put_contents( $image['path'] . '.tmp', $result['body'] );
		$new_type = ewww_image_optimizer_mimetype( $image['path'] . '.tmp', 'i' );
		$old_type = '';
		if ( is_file( $image['path'] ) ) {
			$old_type = ewww_image_optimizer_mimetype( $image['path'] );
		}
		if ( ! in_array( $new_type, $enabled_types ) ) {
			return false;
		}
		if ( empty( $old_type ) || $old_type == $new_type ) {
			if ( rename( $image['path'] . '.tmp', $image['path'] ) ) {
				// Set the results to nothing.
				$ewwwdb->query( "UPDATE $ewwwdb->ewwwio_images SET results = '', image_size = 0, updates = 0, updated=updated, level = 0 WHERE id = {$image['id']}" );
				return true;
			}
		}
	}
	return false;
}

/**
 * Cleans up when an attachment is being deleted.
 *
 * Removes any .webp images, backups from conversion, and removes related database records.
 *
 * @global object $wpdb
 * @global object $ewwwdb A clone of $wpdb unless it is lacking utf8 connectivity.
 *
 * @param int $id The id number for the attachment being deleted.
 */
function ewww_image_optimizer_delete( $id ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	global $wpdb;
	if ( strpos( $wpdb->charset, 'utf8' ) === false ) {
		ewww_image_optimizer_db_init();
		global $ewwwdb;
	} else {
		$ewwwdb = $wpdb;
	}
	// Finds non-meta images to remove from disk, and from db, as well as converted originals.
	if (
		$optimized_images = $ewwwdb->get_results( "SELECT path,converted FROM $ewwwdb->ewwwio_images WHERE attachment_id = $id AND gallery = 'media'", ARRAY_A )
	) {
		if ( ewww_image_optimizer_iterable( $optimized_images ) ) {
			foreach ( $optimized_images as $image ) {
				if ( ! empty( $image['path'] ) ) {
					$image['path'] = ewww_image_optimizer_relative_path_replace( $image['path'] );
				}
				if ( ! empty( $image['path'] ) && is_file( $image['path'] ) ) {
					unlink( $image['path'] );
					if ( is_file( $image['path'] . '.webp' ) ) {
						unlink( $image['path'] . '.webp' );
					}
				}
				if ( ! empty( $image['converted'] ) ) {
					$image['converted'] = ewww_image_optimizer_relative_path_replace( $image['converted'] );
				}
				if ( ! empty( $image['converted'] ) && is_file( $image['converted'] ) ) {
					unlink( $image['converted'] );
					if ( is_file( $image['converted'] . '.webp' ) ) {
						unlink( $image['converted'] . '.webp' );
					}
				}
			}
		}
		$ewwwdb->delete( $ewwwdb->ewwwio_images, array(
			'attachment_id' => $id,
		) );
	}
	// Retrieve the image metadata.
	$meta = wp_get_attachment_metadata( $id );
	// If the attachment has an original file set.
	if ( ! empty( $meta['orig_file'] ) ) {
		unset( $rows );
		// Get the filepath from the metadata.
		$file_path = $meta['orig_file'];
		// Get the base filename.
		$filename = basename( $file_path );
		// Delete any residual webp versions.
		$webpfile = $filename . '.webp';
		$webpfileold = preg_replace( '/\.\w+$/', '.webp', $filename );
		if ( is_file( $webpfile ) ) {
			unlink( $webpfile );
		}
		if ( is_file( $webpfileold ) ) {
			unlink( $webpfileold );
		}
		// Retrieve any posts that link the original image.
		$esql = "SELECT ID, post_content FROM $ewwwdb->posts WHERE post_content LIKE '%$filename%' LIMIT 1";
		$rows = $ewwwdb->get_row( $esql );
		// If the original file still exists and no posts contain links to the image.
		if ( is_file( $file_path ) && empty( $rows ) ) {
			unlink( $file_path );
			$ewwwdb->delete( $ewwwdb->ewwwio_images, array(
				'path' => ewww_image_optimizer_relative_path_remove( $file_path ),
			) );
		}
	}
	// Remove the regular image from the ewwwio_images tables.
	list( $file_path, $upload_path ) = ewww_image_optimizer_attachment_path( $meta, $id );
	$ewwwdb->delete( $ewwwdb->ewwwio_images, array(
		'path' => ewww_image_optimizer_relative_path_remove( $file_path ),
	) );
	// Resized versions, so we can continue.
	if ( isset( $meta['sizes'] ) && ewww_image_optimizer_iterable( $meta['sizes'] ) ) {
		// One way or another, $file_path is now set, and we can get the base folder name.
		$base_dir = dirname( $file_path ) . '/';
		foreach ( $meta['sizes'] as $size => $data ) {
			// Delete any residual webp versions.
			$webpfile = $base_dir . $data['file'] . '.webp';
			$webpfileold = preg_replace( '/\.\w+$/', '.webp', $base_dir . $data['file'] );
			if ( is_file( $webpfile ) ) {
				unlink( $webpfile );
			}
			if ( is_file( $webpfileold ) ) {
				unlink( $webpfileold );
			}
			$ewwwdb->delete( $ewwwdb->ewwwio_images, array(
				'path' => ewww_image_optimizer_relative_path_remove( $base_dir . $data['file'] ),
			) );
			// If the original resize is set, and still exists.
			if ( ! empty( $data['orig_file'] ) && is_file( $base_dir . $data['orig_file'] ) ) {
				unset( $srows );
				// Retrieve the filename from the metadata.
				$filename = $data['orig_file'];
				// Retrieve any posts that link the image.
				$esql = "SELECT ID, post_content FROM $ewwwdb->posts WHERE post_content LIKE '%$filename%' LIMIT 1";
				$srows = $ewwwdb->get_row( $esql );
				// If there are no posts containing links to the original, delete it.
				if ( empty( $srows ) ) {
					unlink( $base_dir . $data['orig_file'] );
					$ewwwdb->delete( $ewwwdb->ewwwio_images, array(
						'path' => ewww_image_optimizer_relative_path_remove( $base_dir . $data['orig_file'] ),
					) );
				}
			}
		}
	}
	ewwwio_memory( __FUNCTION__ );
	return;
}

/**
 * Sanitizes and verifies an API key for the cloud service.
 *
 * @param string $key An API key entered by the user.
 * @return string A sanitized and validated API key.
 */
function ewww_image_optimizer_cloud_key_sanitize( $key ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	$key = trim( $key );
	if ( ewww_image_optimizer_function_exists( 'print_r' ) ) {
		ewwwio_debug_message( print_r( $_REQUEST, true ) );
	}
	if ( ewww_image_optimizer_cloud_verify( false, $key ) ) {
		add_settings_error( 'ewww_image_optimizer_cloud_key', 'ewwwio-cloud-key', esc_html__( 'Successfully validated API key, happy optimizing!', 'ewww-image-optimizer' ), 'updated' );
		ewwwio_debug_message( 'sanitize (verification) successful' );
		ewwwio_memory( __FUNCTION__ );
		return $key;
	} else {
		if ( ! empty( $key ) ) {
			add_settings_error( 'ewww_image_optimizer_cloud_key', 'ewwwio-cloud-key', esc_html__( 'Could not validate API key, please copy and paste your key to ensure it is correct.', 'ewww-image-optimizer' ) );
		}
		ewwwio_debug_message( 'sanitize (verification) failed' );
		ewwwio_memory( __FUNCTION__ );
		return '';
	}
}

/**
 * Checks to see if all images should be processed via the API.
 *
 * @return bool True if all 'cloud' options are enabled.
 */
function ewww_image_optimizer_full_cloud() {
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ) > 10 && ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) > 10 ) {
		return true;
	} elseif ( 'ewww-image-optimizer' == 'ewww-image-optimizer-cloud' ) {
		return true;
	}
	return false;
}

/**
 * Used to turn on the cloud settings when they are all disabled.
 */
function ewww_image_optimizer_cloud_enable() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	ewww_image_optimizer_set_option( 'ewww_image_optimizer_jpg_level', 30 );
	ewww_image_optimizer_set_option( 'ewww_image_optimizer_png_level', 50 );
	ewww_image_optimizer_set_option( 'ewww_image_optimizer_gif_level', 10 );
	ewww_image_optimizer_set_option( 'ewww_image_optimizer_pdf_level', 10 );
	ewww_image_optimizer_set_option( 'ewww_image_optimizer_backup_files', 1 );
}

/**
 * Adds the EWWW IO version to the useragent for http requests.
 *
 * @param string $useragent The current useragent used in http requests.
 * @return string The useragent with the EWWW IO version appended.
 */
function ewww_image_optimizer_cloud_useragent( $useragent ) {
	$useragent .= ' EWWW/' . EWWW_IMAGE_OPTIMIZER_VERSION . ' ';
	ewwwio_memory( __FUNCTION__ );
	return $useragent;
}

/**
 * Submits the api key for verification. Will retrieve the key option if parameter not provided.
 *
 * @global object $ewwwio_async_key_verification
 *
 * @param bool   $cache Optional. True to return cached verification results. Default true.
 * @param string $api_key Optional. The API key to verify. Default empty string.
 * @return string|bool False if verification fails, status message otherwise: great/exceeded.
 */
function ewww_image_optimizer_cloud_verify( $cache = true, $api_key = '' ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( empty( $api_key ) && ! ( ! empty( $_REQUEST['option_page'] ) && 'ewww_image_optimizer_options' == $_REQUEST['option_page'] ) ) {
		$api_key = ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' );
	} elseif ( empty( $api_key ) && ! empty( $_POST['ewww_image_optimizer_cloud_key'] ) ) {
		$api_key = $_POST['ewww_image_optimizer_cloud_key'];
	}
	if ( empty( $api_key ) ) {
		if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ) > 10 ) {
			update_site_option( 'ewww_image_optimizer_jpg_level', 10 );
			update_option( 'ewww_image_optimizer_jpg_level', 10 );
		}
		if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) > 10 && ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) != 40 ) {
			update_site_option( 'ewww_image_optimizer_png_level', 10 );
			update_option( 'ewww_image_optimizer_png_level', 10 );
		}
		if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_pdf_level' ) > 0 ) {
			update_site_option( 'ewww_image_optimizer_pdf_level', 0 );
			update_option( 'ewww_image_optimizer_pdf_level', 0 );
		}
		update_site_option( 'ewww_image_optimizer_backup_files', '' );
		update_option( 'ewww_image_optimizer_backup_files', '' );
		return false;
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_exceeded' ) > time() ) {
		set_transient( 'ewww_image_optimizer_cloud_status', 'exceeded', 3600 );
		ewwwio_debug_message( 'license exceeded notice has not expired' );
		return 'exceeded';
	}
	add_filter( 'http_headers_useragent', 'ewww_image_optimizer_cloud_useragent', PHP_INT_MAX );
	$ewww_cloud_status = get_transient( 'ewww_image_optimizer_cloud_status' );
	$ewww_cloud_ip = get_transient( 'ewww_image_optimizer_cloud_ip' );
	$ewww_cloud_transport = get_transient( 'ewww_image_optimizer_cloud_transport' );
	if ( ! ewww_image_optimizer_detect_wpsf_location_lock() && $cache && preg_match( '/^(\d{1,3}\.){3}\d{1,3}$/', $ewww_cloud_ip ) && preg_match( '/http/', $ewww_cloud_transport ) && preg_match( '/great/', $ewww_cloud_status ) ) {
		ewwwio_debug_message( 'using cached verification' );
		global $ewwwio_async_key_verification;
		if ( ! class_exists( 'WP_Background_Process' ) ) {
			require_once( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'background.php' );
		}
		if ( ! is_object( $ewwwio_async_key_verification ) ) {
			$ewwwio_async_key_verification = new EWWWIO_Async_Key_Verification();
		}
		$ewwwio_async_key_verification->dispatch();
		return $ewww_cloud_status;
	}
	if ( 'https' !== $ewww_cloud_transport && 'http' !== $ewww_cloud_transport ) {
		$ewww_cloud_transport = 'https';
	}
	if ( preg_match( '/^(\d{1,3}\.){3}\d{1,3}$/', $ewww_cloud_ip ) ) {
		ewwwio_debug_message( 'using cached ip' );
		$result = ewww_image_optimizer_cloud_post_key( $ewww_cloud_ip, $ewww_cloud_transport, $api_key );
		if ( is_wp_error( $result ) ) {
			$ewww_cloud_transport = 'http';
			$error_message = $result->get_error_message();
			ewwwio_debug_message( "verification failed: $error_message" );
			$result = ewww_image_optimizer_cloud_post_key( $ewww_cloud_ip, $ewww_cloud_transport, $api_key );
		}
		if ( is_wp_error( $result ) ) {
			$error_message = $result->get_error_message();
			ewwwio_debug_message( "verification failed: $error_message" );
		} elseif ( ! empty( $result['body'] ) && preg_match( '/(great|exceeded)/', $result['body'] ) ) {
			$verified = $result['body'];
			ewwwio_debug_message( "verification success via: $ewww_cloud_transport://$ewww_cloud_ip" );
		} else {
			ewwwio_debug_message( "verification failed via: $ewww_cloud_ip" );
			if ( ewww_image_optimizer_function_exists( 'print_r' ) ) {
				ewwwio_debug_message( print_r( $result, true ) );
			}
		}
	}
	if ( empty( $verified ) ) {
		$ewww_cloud_transport = 'https';
		$servers = gethostbynamel( 'optimize.exactlywww.com' );
		if ( empty( $servers ) ) {
			ewwwio_debug_message( 'unable to resolve servers' );
			return false;
		}
		if ( ewww_image_optimizer_iterable( $servers ) ) {
			foreach ( $servers as $ip ) {
				$result = ewww_image_optimizer_cloud_post_key( $ip, $ewww_cloud_transport, $api_key );
				if ( is_wp_error( $result ) ) {
					$ewww_cloud_transport = 'http';
					$error_message = $result->get_error_message();
					ewwwio_debug_message( "verification failed via $ewww_cloud_transport://$ip: $error_message" );
				} elseif ( ! empty( $result['body'] ) && preg_match( '/(great|exceeded)/', $result['body'] ) ) {
					$verified = $result['body'];
					if ( preg_match( '/exceeded/', $verified ) ) {
						ewww_image_optimizer_set_option( 'ewww_image_optimizer_cloud_exceeded', time() + 300 );
					}
					$ewww_cloud_ip = $ip;
					ewwwio_debug_message( "verification success via: $ewww_cloud_transport://$ewww_cloud_ip" );
					break;
				} else {
					ewwwio_debug_message( "verification failed via: $ip" );
					if ( ewww_image_optimizer_function_exists( 'print_r' ) ) {
						ewwwio_debug_message( print_r( $result, true ) );
					}
				}
			}
		} else {
			ewwwio_debug_message( 'unable to parse server list' );
		}
	}
	if ( empty( $verified ) ) {
		ewwwio_memory( __FUNCTION__ );
		return false;
	} else {
		set_transient( 'ewww_image_optimizer_cloud_status', $verified, 3600 );
		set_transient( 'ewww_image_optimizer_cloud_ip', $ewww_cloud_ip, 3600 );
		set_transient( 'ewww_image_optimizer_cloud_transport', $ewww_cloud_transport, 3600 );
		if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ) < 20 && ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) < 20 && ewww_image_optimizer_get_option( 'ewww_image_optimizer_gif_level' ) < 20 && ewww_image_optimizer_get_option( 'ewww_image_optimizer_pdf_level' ) == 0 ) {
			ewww_image_optimizer_cloud_enable();
		}
		ewwwio_debug_message( "verification body contents: {$result['body']}" );
		ewwwio_memory( __FUNCTION__ );
		return $verified;
	}
}

/**
 * POSTs the API key to the API for verification.
 *
 * @param string $ip IP address of the server to use.
 * @param string $transport The transport to use: http or https.
 * @param string $key The API key to submit via POST.
 * @return array The results of the http POST request.
 */
function ewww_image_optimizer_cloud_post_key( $ip, $transport, $key ) {
	$result = wp_remote_post( "$transport://$ip/verify/", array(
		'timeout' => 5,
		'sslverify' => false,
		'body' => array(
			'api_key' => $key,
		),
	) );
	return $result;
}

/**
 * Checks the configured API key for quota information.
 *
 * @return string A message with how many credits they have used/left and possibly a renwal date.
 */
function ewww_image_optimizer_cloud_quota() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	// $ewww_cloud_ip = get_transient( 'ewww_image_optimizer_cloud_ip' );
	$ewww_cloud_transport = get_transient( 'ewww_image_optimizer_cloud_transport' );
	if ( empty( $ewww_cloud_transport ) ) {
		if ( ! ewww_image_optimizer_cloud_verify() ) {
			return '';
		} else {
			$ewww_cloud_transport = get_transient( 'ewww_image_optimizer_cloud_transport' );
		}
	}
	if ( empty( $ewww_cloud_transport ) ) {
		$ewww_cloud_transport = 'https';
	}
	$api_key = ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' );
	$url = "$ewww_cloud_transport://optimize.exactlywww.com/quota/";
	$result = wp_remote_post( $url, array(
		'timeout' => 5,
		'sslverify' => false,
		'body' => array(
			'api_key' => $api_key,
		),
	) );
	if ( is_wp_error( $result ) ) {
		$error_message = $result->get_error_message();
		ewwwio_debug_message( "quota request failed: $error_message" );
		ewwwio_memory( __FUNCTION__ );
		return '';
	} elseif ( ! empty( $result['body'] ) ) {
		ewwwio_debug_message( "quota data retrieved: {$result['body']}" );
		$quota = explode( ' ', $result['body'] );
		ewwwio_memory( __FUNCTION__ );
		if ( 0 == $quota[0] && $quota[1] > 0 ) {
			return esc_html( sprintf(
				/* translators: 1: Number of images 2: Number of days until renewal */
				_n( 'optimized %1$d images, usage will reset in %2$d day.', 'optimized %1$d images, usage will reset in %2$d days.', $quota[2], 'ewww-image-optimizer' ), $quota[1], $quota[2]
			) );
		} elseif ( 0 == $quota[0] && $quota[1] < 0 ) {
			return esc_html( sprintf(
				/* translators: 1: Number of images */
				_n( '%1$d image credit remaining.', '%1$d image credits remaining.', abs( $quota[1] ), 'ewww-image-optimizer' ), abs( $quota[1] )
			) );
		} elseif ( $quota[0] > 0 && $quota[1] < 0 ) {
			$real_quota = $quota[0] - $quota[1];
			return esc_html( sprintf(
				/* translators: 1: Number of images */
				_n( '%1$d image credit remaining.', '%1$d image credits remaining.', $real_quota, 'ewww-image-optimizer' ), $real_quota
			) );
		} else {
			return esc_html( sprintf(
				/* translators: 1: Number of image credits used 2: Number of image credits available 3: days until subscription renewal */
				_n( 'used %1$d of %2$d, usage will reset in %3$d day.', 'used %1$d of %2$d, usage will reset in %3$d days.', $quota[2], 'ewww-image-optimizer' ), $quota[1], $quota[0], $quota[2]
			) );
		}
	}
}

/**
 * Submits an image to the cloud optimizer and saves the optimized image to disk.
 *
 * Returns an array of the $file, $converted, possibly a $msg, and the $new_size.
 *
 * @global object $ewww_image Contains more information about the image currently being processed.
 *
 * @param string $file Full absolute path to the image file.
 * @param string $type Mimetype of $file.
 * @param bool   $convert Optional. True if we want to attempt conversion of $file. Default false.
 * @param string $newfile Optional. Filename to be used if image is converted. Default null.
 * @param string $newtype Optional. Mimetype expected if image is converted. Default null.
 * @param bool   $fullsize Optional. True if this is an original upload. Default false.
 * @param array  $jpg_fill Optional. Fill color for PNG to JPG conversion in hex format.
 * @param int    $jpg_quality Optional. JPG quality level. Default null. Accepts 1-100.
 * @return array {
 *	Information about the cloud optimization.
 *
 *	@type string Filename of the optimized version.
 *	@type bool True if the image was converted.
 *	@type string Set to 'exceeded' if the API key is out of credits.
 *	@type int File size of the (new) image.
 * }
 */
function ewww_image_optimizer_cloud_optimizer( $file, $type, $convert = false, $newfile = null, $newtype = null, $fullsize = false, $jpg_fill = '', $jpg_quality = 82 ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	$ewww_cloud_ip = get_transient( 'ewww_image_optimizer_cloud_ip' );
	$ewww_cloud_transport = get_transient( 'ewww_image_optimizer_cloud_transport' );
	$ewww_status = get_transient( 'ewww_image_optimizer_cloud_status' );
	$started = microtime( true );
	if ( empty( $ewww_cloud_ip ) || empty( $ewww_cloud_transport ) || preg_match( '/exceeded/', $ewww_status ) ) {
		if ( ! ewww_image_optimizer_cloud_verify() ) {
			return array( $file, false, 'key verification failed', 0, '' );
		} else {
			$ewww_cloud_ip = get_transient( 'ewww_image_optimizer_cloud_ip' );
			$ewww_cloud_transport = get_transient( 'ewww_image_optimizer_cloud_transport' );
		}
	}
	// Calculate how much time has elapsed since we started.
	$elapsed = microtime( true ) - $started;
	ewwwio_debug_message( "Cloud verify took $elapsed seconds" );
	$ewww_status = get_transient( 'ewww_image_optimizer_cloud_status' );
	if ( ( ! empty( $ewww_status ) && preg_match( '/exceeded/', $ewww_status ) ) || ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_exceeded' ) > time() ) {
		ewwwio_debug_message( 'license exceeded, image not processed' );
		return array( $file, false, 'exceeded', 0, '' );
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_metadata_skip_full' ) && $fullsize ) {
		$metadata = 1;
	} elseif ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpegtran_copy' ) ) {
		// Don't copy metadata.
		$metadata = 0;
	} else {
		// Copy all the metadata.
		$metadata = 1;
	}
	if ( empty( $convert ) ) {
		$convert = 0;
	} else {
		$convert = 1;
	}
	$lossy_fast = 0;
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_lossy_skip_full' ) && $fullsize ) {
		$lossy = 0;
	} elseif ( 'image/png' == $type && ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) >= 40 ) {
		$lossy = 1;
		if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) == 40 ) {
			$lossy_fast = 1;
		}
	} elseif ( 'image/jpeg' == $type && ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ) >= 30 ) {
		$lossy = 1;
		if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ) == 30 ) {
			$lossy_fast = 1;
		}
	} elseif ( 'application/pdf' == $type && ewww_image_optimizer_get_option( 'ewww_image_optimizer_pdf_level' ) == 20 ) {
		$lossy = 1;
	} else {
		$lossy = 0;
	}
	if ( 'image/webp' == $newtype ) {
		$webp = 1;
		$jpg_quality = apply_filters( 'jpeg_quality', $jpg_quality, 'image/webp' );
	} else {
		$webp = 0;
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) == 30 ) {
		$png_compress = 1;
	} else {
		$png_compress = 0;
	}
	if ( ! $webp && ewww_image_optimizer_get_option( 'ewww_image_optimizer_backup_files' )
			&& strpos( $file, '/wp-admin/' ) === false
			&& strpos( $file, '/wp-includes/' ) === false
			&& strpos( $file, '/wp-content/themes/' ) === false
			&& strpos( $file, '/wp-content/plugins/' ) === false
			&& strpos( $file, '/cache/' ) === false
			&& strpos( $file, '/dynamic/' ) === false // Nextgen dynamic images.
	) {
		global $ewww_image;
		if ( is_object( $ewww_image ) && $ewww_image->file == $file && ! empty( $ewww_image->backup ) ) {
			$hash = $ewww_image->backup;
		}
		if ( empty( $hash ) && ! empty( $_REQUEST['ewww_force'] ) ) {
			$image = ewww_image_optimizer_find_already_optimized( $file );
			if ( ! empty( $image ) && is_array( $image ) && ! empty( $image['backup'] ) ) {
				$hash = $image['backup'];
			}
		}
		if ( empty( $hash ) ) {
			$hash = uniqid() . hash( 'sha256', $file );
		}
		$domain = parse_url( get_site_url(), PHP_URL_HOST );
	} else {
		$hash = '';
		$domain = parse_url( get_site_url(), PHP_URL_HOST );
	}
	ewwwio_debug_message( "file: $file " );
	ewwwio_debug_message( "type: $type" );
	ewwwio_debug_message( "convert: $convert" );
	ewwwio_debug_message( "newfile: $newfile" );
	ewwwio_debug_message( "newtype: $newtype" );
	ewwwio_debug_message( "webp: $webp" );
	ewwwio_debug_message( "jpg fill: $jpg_fill" );
	ewwwio_debug_message( "jpg quality: $jpg_quality" );
	$api_key = ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' );
	$url = "$ewww_cloud_transport://$ewww_cloud_ip/v2/";
	$boundary = wp_generate_password( 24, false );

	$headers = array(
		'content-type' => 'multipart/form-data; boundary=' . $boundary,
		'timeout' => 90,
		'httpversion' => '1.0',
		'blocking' => true,
	);
	$post_fields = array(
		'filename' => $file,
		'convert' => $convert,
		'metadata' => $metadata,
		'api_key' => $api_key,
		'jpg_fill' => $jpg_fill,
		'quality' => $jpg_quality,
		'compress' => $png_compress,
		'lossy' => $lossy,
		'lossy_fast' => $lossy_fast,
		'webp' => $webp,
		'backup' => $hash,
		'domain' => $domain,
	);

	$payload = '';
	foreach ( $post_fields as $name => $value ) {
		$payload .= '--' . $boundary;
		$payload .= "\r\n";
		$payload .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
		$payload .= $value;
		$payload .= "\r\n";
	}

	$payload .= '--' . $boundary;
	$payload .= "\r\n";
	$payload .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $file ) . '"' . "\r\n";
	$payload .= 'Content-Type: ' . $type . "\r\n";
	$payload .= "\r\n";
	$payload .= file_get_contents( $file );
	$payload .= "\r\n";
	$payload .= '--' . $boundary;
	$payload .= 'Content-Disposition: form-data; name="submitHandler"' . "\r\n";
	$payload .= "\r\n";
	$payload .= "Upload\r\n";
	$payload .= '--' . $boundary . '--';

	// Retrieve the time when the optimizer starts.
	$response = wp_remote_post( $url, array(
		'timeout' => 90,
		'headers' => $headers,
		'sslverify' => false,
		'body' => $payload,
	) );
	if ( is_wp_error( $response ) ) {
		$error_message = $response->get_error_message();
		ewwwio_debug_message( "optimize failed: $error_message" );
		return array( $file, false, 'cloud optimize failed', 0, '' );
	} else {
		$tempfile = $file . '.tmp';
		file_put_contents( $tempfile, $response['body'] );
		$orig_size = filesize( $file );
		$newsize = $orig_size;
		$converted = false;
		$msg = '';
		if ( preg_match( '/exceeded/', $response['body'] ) ) {
			ewwwio_debug_message( 'License Exceeded' );
			set_transient( 'ewww_image_optimizer_cloud_status', 'exceeded', 3600 );
			$msg = 'exceeded';
			unlink( $tempfile );
		} elseif ( ewww_image_optimizer_mimetype( $tempfile, 'i' ) == $type ) {
			$newsize = filesize( $tempfile );
			ewwwio_debug_message( "cloud results: $newsize (new) vs. $orig_size (original)" );
			rename( $tempfile, $file );
		} elseif ( ewww_image_optimizer_mimetype( $tempfile, 'i' ) == 'image/webp' ) {
			$newsize = filesize( $tempfile );
			ewwwio_debug_message( "cloud results: $newsize (new) vs. $orig_size (original)" );
			rename( $tempfile, $newfile );
		} elseif ( ewww_image_optimizer_mimetype( $tempfile, 'i' ) == $newtype ) {
			$converted = true;
			$newsize = filesize( $tempfile );
			ewwwio_debug_message( "cloud results: $newsize (new) vs. $orig_size (original)" );
			rename( $tempfile, $newfile );
			$file = $newfile;
		} else {
			unlink( $tempfile );
		}
		ewwwio_memory( __FUNCTION__ );
		return array( $file, $converted, $msg, $newsize, $hash );
	} // End if().
}

/**
 * Automatically corrects JPG rotation using API servers.
 *
 * @param string $file Name of the file to fix.
 * @param string $type File type of the file.
 *
 * @return bool True if the rotation was successful.
 */
function ewww_image_optimizer_cloud_autorotate( $file, $type ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	$ewww_cloud_ip = get_transient( 'ewww_image_optimizer_cloud_ip' );
	$ewww_cloud_transport = get_transient( 'ewww_image_optimizer_cloud_transport' );
	$ewww_status = get_transient( 'ewww_image_optimizer_cloud_status' );
	$started = microtime( true );
	if ( empty( $ewww_cloud_ip ) || empty( $ewww_cloud_transport ) || preg_match( '/exceeded/', $ewww_status ) ) {
		if ( ! ewww_image_optimizer_cloud_verify() ) {
			ewwwio_debug_message( 'cloud verify failed, image not rotated' );
			return false;
		} else {
			$ewww_cloud_ip = get_transient( 'ewww_image_optimizer_cloud_ip' );
			$ewww_cloud_transport = get_transient( 'ewww_image_optimizer_cloud_transport' );
		}
	}
	// Calculate how much time has elapsed since we started.
	$elapsed = microtime( true ) - $started;
	ewwwio_debug_message( "Cloud verify took $elapsed seconds" );
	$ewww_status = get_transient( 'ewww_image_optimizer_cloud_status' );
	if ( ( ! empty( $ewww_status ) && preg_match( '/exceeded/', $ewww_status ) ) || ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_exceeded' ) > time() ) {
		ewwwio_debug_message( 'license exceeded, image not rotated' );
		return false;
	}
	ewwwio_debug_message( "file: $file " );
	ewwwio_debug_message( "type: $type" );
	$api_key = ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' );
	$url = "$ewww_cloud_transport://$ewww_cloud_ip/rotate/";
	$boundary = wp_generate_password( 24, false );

	$headers = array(
		'content-type' => 'multipart/form-data; boundary=' . $boundary,
		'timeout' => 60,
		'httpversion' => '1.0',
		'blocking' => true,
	);
	$post_fields = array(
		'filename' => $file,
		'api_key' => $api_key,
	);

	$payload = '';
	foreach ( $post_fields as $name => $value ) {
		$payload .= '--' . $boundary;
		$payload .= "\r\n";
		$payload .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
		$payload .= $value;
		$payload .= "\r\n";
	}

	$payload .= '--' . $boundary;
	$payload .= "\r\n";
	$payload .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $file ) . '"' . "\r\n";
	$payload .= 'Content-Type: ' . $type . "\r\n";
	$payload .= "\r\n";
	$payload .= file_get_contents( $file );
	$payload .= "\r\n";
	$payload .= '--' . $boundary;
	$payload .= 'Content-Disposition: form-data; name="submitHandler"' . "\r\n";
	$payload .= "\r\n";
	$payload .= "Upload\r\n";
	$payload .= '--' . $boundary . '--';

	$response = wp_remote_post( $url, array(
		'timeout' => 60,
		'headers' => $headers,
		'sslverify' => false,
		'body' => $payload,
	) );
	if ( is_wp_error( $response ) ) {
		$error_message = $response->get_error_message();
		ewwwio_debug_message( "rotate failed: $error_message" );
		return false;
	} else {
		$tempfile = $file . '.tmp';
		file_put_contents( $tempfile, $response['body'] );
		$orig_size = filesize( $file );
		$newsize = $orig_size;
		if ( preg_match( '/exceeded/', $response['body'] ) ) {
			ewwwio_debug_message( 'License Exceeded' );
			set_transient( 'ewww_image_optimizer_cloud_status', 'exceeded', 3600 );
			unlink( $tempfile );
		} elseif ( ewww_image_optimizer_mimetype( $tempfile, 'i' ) == $type ) {
			$newsize = filesize( $tempfile );
			ewwwio_debug_message( "cloud rotation success: $newsize (new) vs. $orig_size (original)" );
			rename( $tempfile, $file );
			return true;
		} else {
			unlink( $tempfile );
		}
		ewwwio_memory( __FUNCTION__ );
		return false;
	}
}

/**
 * Setup our own database connection with full utf8 capability.
 *
 * @global object $ewwwdb A new database connection with super powers.
 * @global string $table_prefix The table prefix for the WordPress database.
 */
function ewww_image_optimizer_db_init() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	global $ewwwdb, $table_prefix;
	require_once( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'classes/class-ewwwdb.php' );
	if ( ! isset( $ewwwdb ) ) {
		$ewwwdb = new EwwwDB( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	}

	if ( ! empty( $ewwwdb->error ) ) {
		dead_db();
	}

	$ewwwdb->field_types = array(
		'post_author' => '%d',
		'post_parent' => '%d',
		'menu_order' => '%d',
		'term_id' => '%d',
		'term_group' => '%d',
		'term_taxonomy_id' => '%d',
		'parent' => '%d',
		'count' => '%d',
		'object_id' => '%d',
		'term_order' => '%d',
		'ID' => '%d',
		'comment_ID' => '%d',
		'comment_post_ID' => '%d',
		'comment_parent' => '%d',
		'user_id' => '%d',
		'link_id' => '%d',
		'link_owner' => '%d',
		'link_rating' => '%d',
		'option_id' => '%d',
		'blog_id' => '%d',
		'meta_id' => '%d',
		'post_id' => '%d',
		'user_status' => '%d',
		'umeta_id' => '%d',
		'comment_karma' => '%d',
		'comment_count' => '%d',
		// multisite.
		'active' => '%d',
		'cat_id' => '%d',
		'deleted' => '%d',
		'lang_id' => '%d',
		'mature' => '%d',
		'public' => '%d',
		'site_id' => '%d',
		'spam' => '%d',
	);

	$prefix = $ewwwdb->set_prefix( $table_prefix );

	if ( is_wp_error( $prefix ) ) {
		wp_load_translations_early();
		wp_die(
			/* translators: 1: $table_prefix 2: wp-config.php */
			sprintf( __( '<strong>ERROR</strong>: %1$s in %2$s can only contain numbers, letters, and underscores.' ),
				'<code>$table_prefix</code>',
				'<code>wp-config.php</code>'
			)
		);
	}
	if ( ! isset( $ewwwdb->ewwwio_images ) ) {
		$ewwwdb->ewwwio_images = $ewwwdb->prefix . 'ewwwio_images';
	}
}

/**
 * Inserts multiple records into the table at once.
 *
 * Each sub-array in $images should have the same number of items as $format.
 *
 * @global object $ewwwdb A new database connection with super powers.
 *
 * @param string $table The table to insert records into.
 * @param array  $images Can be any multi-dimensional array with records to insert.
 * @param array  $format A list of formats for the values in each record of $images.
 */
function ewww_image_optimizer_mass_insert( $table, $images, $format ) {
	if ( empty( $table ) || ! ewww_image_optimizer_iterable( $images ) || ! ewww_image_optimizer_iterable( $format ) ) {
		return false;
	}
	ewww_image_optimizer_db_init();
	global $ewwwdb;
	$ewwwdb->insert_multiple( $table, $images, $format );
}

/**
 * Search the database to see if we've done this image before.
 *
 * @global object $wpdb
 *
 * @param string $file The filename of the image.
 * @param int    $orig_size The current filesize of the image.
 * @return array The image record from the table, if found.
 */
function ewww_image_optimizer_check_table( $file, $orig_size ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	ewwwio_debug_message( "checking for $file with size: $orig_size" );
	global $s3_uploads_image;
	if ( class_exists( 'S3_Uploads' ) && ! empty( $s3_uploads_image ) && $s3_uploads_image != $file ) {
		$file = $s3_uploads_image;
		ewwwio_debug_message( "overriding check with: $file" );
	}
	$image = ewww_image_optimizer_find_already_optimized( $file );
	if ( is_array( $image ) && $image['image_size'] == $orig_size ) {
		$prev_string = ' - ' . __( 'Previously Optimized', 'ewww-image-optimizer' );
		if ( preg_match( '/' . __( 'License exceeded', 'ewww-image-optimizer' ) . '/', $image['results'] ) ) {
			return;
		}
		$already_optimized = preg_replace( "/$prev_string/", '', $image['results'] );
		$already_optimized = $already_optimized . $prev_string;
		ewwwio_debug_message( "already optimized: {$image['path']} - $already_optimized" );
		ewwwio_memory( __FUNCTION__ );
		// Make sure the image isn't pending.
		if ( $image['pending'] ) {
			global $wpdb;
			$wpdb->update( $wpdb->ewwwio_images,
				array(
					'pending' => 0,
				),
				array(
					'id' => $image['id'],
				)
			);
		}
		return $already_optimized;
	}
}

/**
 * Inserts or updates an image record in the database.
 *
 * @global object $wpdb
 * @global object $ewwwdb A clone of $wpdb unless it is lacking utf8 connectivity.
 * @global object $ewww_image Contains more information about the image currently being processed.
 *
 * @param string $attachment The filename of the image.
 * @param int    $opt_size The new size of the image.
 * @param int    $orig_size The original size of the image.
 * @param string $original Optional. The name of the file before it was converted. Default ''.
 * @param string $backup_hash Optional. A unique identifier for this file. Default ''.
 */
function ewww_image_optimizer_update_table( $attachment, $opt_size, $orig_size, $original = '', $backup_hash = '' ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	global $wpdb;
	if ( strpos( $wpdb->charset, 'utf8' ) === false ) {
		ewww_image_optimizer_db_init();
		global $ewwwdb;
	} else {
		$ewwwdb = $wpdb;
	}
	global $ewww_image;
	// First check if the image was converted, so we don't orphan records.
	if ( $original && $original != $attachment ) {
		$already_optimized = ewww_image_optimizer_find_already_optimized( $original );
		$converted = $original;
	} else {
		global $s3_uploads_image;
		if ( class_exists( 'S3_Uploads' ) && ! empty( $s3_uploads_image ) && $s3_uploads_image != $attachment ) {
			$attachment = $s3_uploads_image;
			ewwwio_debug_message( "overriding update with: $attachment" );
		}
		$already_optimized = ewww_image_optimizer_find_already_optimized( $attachment );
		if ( is_array( $already_optimized ) && ! empty( $already_optimized['converted'] ) ) {
			$converted = $already_optimized['converted'];
		} else {
			$converted = '';
		}
	}
	if ( is_array( $already_optimized ) && ! empty( $already_optimized['updates'] ) && $opt_size >= $orig_size ) {
		$prev_string = ' - ' . __( 'Previously Optimized', 'ewww-image-optimizer' );
	} else {
		$prev_string = '';
	}
	if ( is_array( $already_optimized ) && ! empty( $already_optimized['orig_size'] ) && $already_optimized['orig_size'] > $orig_size ) {
		$orig_size = $already_optimized['orig_size'];
	}
	ewwwio_debug_message( "savings: $opt_size (new) vs. $orig_size (orig)" );
	// Calculate how much space was saved.
	$results_msg = ewww_image_optimizer_image_results( $orig_size, $opt_size, $prev_string );

	$updates = array(
		'path' => ewww_image_optimizer_relative_path_remove( $attachment ),
		'converted' => $converted,
		'image_size' => $opt_size,
		'results' => $results_msg,
		'updates' => 1,
		'backup' => preg_replace( '/[^\w]/', '', $backup_hash ),
	);
	if ( ! seems_utf8( $updates['path'] ) ) {
		$updates['path'] = utf8_encode( $updates['path'] );
	}
	// Store info on the current image for future reference.
	if ( empty( $already_optimized ) || ! is_array( $already_optimized ) ) {
		ewwwio_debug_message( "creating new record, path: $attachment, size: $opt_size" );
		if ( is_object( $ewww_image ) && $ewww_image instanceof EWWW_Image && $ewww_image->gallery ) {
			$updates['gallery'] = $ewww_image->gallery;
		}
		if ( is_object( $ewww_image ) && $ewww_image instanceof EWWW_Image && $ewww_image->attachment_id ) {
			$updates['attachment_id'] = $ewww_image->attachment_id;
		}
		if ( is_object( $ewww_image ) && $ewww_image instanceof EWWW_Image && $ewww_image->resize ) {
			$updates['resize'] = $ewww_image->resize;
		}
		$updates['orig_size'] = $orig_size;
		$updates['updated'] = date( 'Y-m-d H:i:s' );
		$ewwwdb->insert( $ewwwdb->ewwwio_images, $updates );
	} else {
		if ( is_array( $already_optimized ) && empty( $already_optimized['orig_size'] ) ) {
			$updates['orig_size'] = $orig_size;
		}
		ewwwio_debug_message( "updating existing record ({$already_optimized['id']}), path: $attachment, size: $opt_size" );
		if ( $already_optimized['updates'] ) {
			$updates['updates'] = $already_optimized['updates']++;
		}
		$updates['pending'] = 0;
		if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_debug' ) && $already_optimized['updates'] > 1 ) {
			$updates['trace'] = ewwwio_debug_backtrace();
		}
		if ( empty( $already_optimized['gallery'] ) && is_object( $ewww_image ) && $ewww_image instanceof EWWW_Image && $ewww_image->gallery ) {
			$updates['gallery'] = $ewww_image->gallery;
		}
		if ( empty( $already_optimized['attachment_id'] ) && is_object( $ewww_image ) && $ewww_image instanceof EWWW_Image && $ewww_image->attachment_id ) {
			$updates['attachment_id'] = $ewww_image->attachment_id;
		}
		if ( empty( $already_optimized['resize'] ) && is_object( $ewww_image ) && $ewww_image instanceof EWWW_Image && $ewww_image->resize ) {
			$updates['resize'] = $ewww_image->resize;
		}
		if ( ewww_image_optimizer_function_exists( 'print_r' ) ) {
			ewwwio_debug_message( print_r( $updates, true ) );
		}
		// Update information for the image.
		$record_updated = $ewwwdb->update( $ewwwdb->ewwwio_images,
			$updates,
			array(
				'id' => $already_optimized['id'],
			)
		);
		if ( false === $record_updated ) {
			if ( ewww_image_optimizer_function_exists( 'print_r' ) ) {
				ewwwio_debug_message( 'db error: ' . print_r( $wpdb->last_error, true ) );
			}
		} else {
			ewwwio_debug_message( "updated $record_updated records successfully" );
		}
	} // End if().
	ewwwio_memory( __FUNCTION__ );
	$ewwwdb->flush();
	ewwwio_memory( __FUNCTION__ );
	return $results_msg;
}

/**
 * Creates a human-readable message based on the original and optimized sizes.
 *
 * @param int    $orig_size The original size of the image.
 * @param int    $opt_size The new size of the image.
 * @param string $prev_string Optional. A message to append for previously optimized images.
 * @return string A message with the percentage and size savings.
 */
function ewww_image_optimizer_image_results( $orig_size, $opt_size, $prev_string = '' ) {
	if ( $opt_size >= $orig_size ) {
		ewwwio_debug_message( 'original and new file are same size (or something weird made the new one larger), no savings' );
		$results_msg = __( 'No savings', 'ewww-image-optimizer' );
	} else {
		// Calculate how much space was saved.
		$savings = intval( $orig_size ) - intval( $opt_size );
		$savings_str = ewww_image_optimizer_size_format( $savings );
		// Determine the percentage savings.
		$percent = number_format_i18n( 100 - ( 100 * ( $opt_size / $orig_size ) ), 1 ) . '%';
		// Use the percentage and the savings size to output a nice message to the user.
		$results_msg = sprintf(
			/* translators: 1: Size of savings in bytes, kb, mb 2: Percentage savings */
			__( 'Reduced by %1$s (%2$s)', 'ewww-image-optimizer' ),
			$percent,
			$savings_str
		) . $prev_string;
		ewwwio_debug_message( "original and new file are different size: $results_msg" );
	}
	return $results_msg;
}

/**
 * Wrapper around size_format to remove the decimal from sizes in bytes.
 *
 * @param int $size A filesize in bytes.
 * @param int $precision Number of places after the decimal separator.
 * @return string Human-readable filesize.
 */
function ewww_image_optimizer_size_format( $size, $precision = 1 ) {
		// Convert it to human readable format.
		$size_str = size_format( $size, $precision );
		// Remove spaces and extra decimals when measurement is in bytes.
		return preg_replace( '/\.0+ B ?/', ' B', $size_str );
}

/**
 * Called to process each image during scheduled optimization.
 *
 * @global object $wpdb
 * @global object $ewwwdb A clone of $wpdb unless it is lacking utf8 connectivity.
 * @global bool   $ewww_defer Set to false to avoid deferring image optimization.
 * @global string $ewww_debug Contains in-memory debug log.
 *
 * @param array $attachment {
 *	Optional. The file to optimize. Default null.
 *
 *	@type int $id The id number in the ewwwio_images table.
 *	@type string $path The filename of the image.
 * }
 * @param bool  $auto Optional. True if scheduled optimization is running. Default false.
 * @param bool  $cli Optional. True if WP-CLI is running. Default false.
 * @return string When called from WP-CLI, a message with compression results.
 */
function ewww_image_optimizer_aux_images_loop( $attachment = null, $auto = false, $cli = false ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	global $wpdb;
	if ( strpos( $wpdb->charset, 'utf8' ) === false ) {
		ewww_image_optimizer_db_init();
		global $ewwwdb;
	} else {
		$ewwwdb = $wpdb;
	}
	global $ewww_defer;
	$ewww_defer = false;
	$output = array();
	// Verify that an authorized user has started the optimizer.
	$permissions = apply_filters( 'ewww_image_optimizer_bulk_permissions', '' );
	if ( ! $auto && ( empty( $_REQUEST['ewww_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['ewww_wpnonce'], 'ewww-image-optimizer-bulk' ) || ! current_user_can( $permissions ) ) ) {
		$output['error'] = esc_html__( 'Access token has expired, please reload the page.', 'ewww-image-optimizer' );
		echo json_encode( $output );
		die();
	}
	session_write_close();
	if ( ! empty( $_REQUEST['ewww_wpnonce'] ) ) {
		// Find out if our nonce is on it's last leg/tick.
		$tick = wp_verify_nonce( $_REQUEST['ewww_wpnonce'], 'ewww-image-optimizer-bulk' );
		if ( 2 === $tick ) {
			ewwwio_debug_message( 'nonce on its last leg' );
			$output['new_nonce'] = wp_create_nonce( 'ewww-image-optimizer-bulk' );
		} else {
			ewwwio_debug_message( 'nonce still alive and kicking' );
			$output['new_nonce'] = '';
		}
	}
	// Retrieve the time when the optimizer starts.
	$started = microtime( true );
	if ( ewww_image_optimizer_stl_check() && ini_get( 'max_execution_time' ) < 60 ) {
		set_time_limit( 0 );
	}
	// Get the next image in the queue.
	if ( empty( $attachment ) ) {
		list( $id, $attachment ) = $ewwwdb->get_row( "SELECT id,path FROM $ewwwdb->ewwwio_images WHERE pending=1 LIMIT 1", ARRAY_N );
	} else {
		$id = $attachment['id'];
		$attachment = $attachment['path'];
	}
	if ( $attachment ) {
		$attachment = ewww_image_optimizer_relative_path_replace( $attachment );
	}
	// Do the optimization for the current image.
	$results = ewww_image_optimizer( $attachment );
	if ( ! $results[0] && is_numeric( $id ) ) {
		$ewwwdb->delete(
			$ewwwdb->ewwwio_images,
			array(
				'id' => $id,
			),
			array(
				'%d'
			)
		);
	}
	$ewww_status = get_transient( 'ewww_image_optimizer_cloud_status' );
	if ( ! empty( $ewww_status ) && preg_match( '/exceeded/', $ewww_status ) ) {
		if ( ! $auto ) {
			$output['error'] = esc_html__( 'License Exceeded', 'ewww-image-optimizer' );
			echo json_encode( $output );
		}
		if ( $cli ) {
			WP_CLI::error( __( 'License Exceeded', 'ewww-image-optimizer' ) );
		}
		die();
	}
	if ( ! $auto ) {
		// Output the path.
		$output['results'] = '<p>' . esc_html__( 'Optimized', 'ewww-image-optimizer' ) . ' <strong>' . esc_html( $attachment ) . '</strong><br>';
		// Tell the user what the results were for the original image.
		$output['results'] .= $results[1] . '<br>';
		// Calculate how much time has elapsed since we started.
		$elapsed = microtime( true ) - $started;
		// Output how much time has elapsed since we started.
		$output['results'] .= sprintf( esc_html(
			/* translators: %s: number of seconds */
			_n( 'Elapsed: %s second', 'Elapsed: %s seconds', $elapsed, 'ewww-image-optimizer' )
		) . '</p>', number_format_i18n( $elapsed ) );
		if ( get_site_option( 'ewww_image_optimizer_debug' ) ) {
			global $ewww_debug;
			$output['results'] .= '<div style="background-color:#ffff99;">' . $ewww_debug . '</div>';
		}
		$next_file = ewww_image_optimizer_relative_path_replace( $wpdb->get_var( "SELECT path FROM $wpdb->ewwwio_images WHERE pending=1 LIMIT 1" ) );
		if ( ! empty( $next_file ) ) {
			$loading_image = plugins_url( '/images/wpspin.gif', __FILE__ );
			$output['next_file'] = '<p>' . esc_html__( 'Optimizing', 'ewww-image-optimizer' ) . ' <b>' . esc_html( $next_file ) . "</b>&nbsp;<img src='$loading_image' alt='loading'/></p>";
		}
		echo json_encode( $output );
		ewwwio_memory( __FUNCTION__ );
		die();
	}
	if ( $cli ) {
		return $results[1];
	}
	ewwwio_memory( __FUNCTION__ );
}

/**
 * Looks for a retina version of the original file so that we can optimize that too.
 *
 * @global object $ewww_image Contains more information about the image currently being processed.
 *
 * @param string $orig_path Filename of the 'normal' image.
 * @param bool   $return_path True returns the path of the retina image and skips optimization.
 * @param bool   $validate_file True verifies the file exists.
 * @return string The filename of the retina image.
 */
function ewww_image_optimizer_hidpi_optimize( $orig_path, $return_path = false, $validate_file = true ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	$hidpi_suffix = apply_filters( 'ewww_image_optimizer_hidpi_suffix', '@2x' );
	$pathinfo = pathinfo( $orig_path );
	if ( empty( $pathinfo['dirname'] ) || empty( $pathinfo['filename'] ) || empty( $pathinfo['extension'] ) ) {
		return;
	}
	$hidpi_path = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . $hidpi_suffix . '.' . $pathinfo['extension'];
	if ( $validate_file && ! is_file( $hidpi_path ) ) {
		return;
	}
	if ( $return_path ) {
		ewwwio_debug_message( "found retina at $hidpi_path" );
		return $hidpi_path;
	}
	global $ewww_image;
	if ( is_object( $ewww_image ) && $ewww_image instanceof EWWW_Image ) {
		$id = $ewww_image->attachment_id;
		$gallery = 'media';
		$size = $ewww_image->resize;
	} else {
		$id = 0;
		$gallery = '';
		$size = null;
	}
	$ewww_image = new EWWW_Image( $id, $gallery, $hidpi_path );
	$ewww_image->resize = $size . '-retina';
	ewww_image_optimizer( $hidpi_path );
}

/**
 * Fetches images from S3 or Azure storage so that they can be optimized locally.
 *
 * @global object $as3cf
 *
 * @param int   $id The attachment ID number.
 * @param array $meta The attachment metadata.
 * @return string|bool The filename of the image fetched, false on failure.
 */
function ewww_image_optimizer_remote_fetch( $id, $meta ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( ! function_exists( 'download_url' ) ) {
		require_once( ABSPATH . '/wp-admin/includes/file.php' );
	}
	if ( class_exists( 'Amazon_S3_And_CloudFront' ) ) {
		global $as3cf;
		$full_url = get_attached_file( $id );
		if ( strpos( $full_url, 's3' ) === 0 ) {
			$full_url = $as3cf->get_attachment_url( $id, null, null, $meta );
		}
		$filename = get_attached_file( $id, true );
		ewwwio_debug_message( "amazon s3 fullsize url: $full_url" );
		ewwwio_debug_message( "unfiltered fullsize path: $filename" );
		$temp_file = download_url( $full_url );
		if ( ! is_wp_error( $temp_file ) ) {
			rename( $temp_file, $filename );
		}
		// Resized versions, so we'll grab those too.
		if ( isset( $meta['sizes'] ) && ewww_image_optimizer_iterable( $meta['sizes'] ) ) {
			$disabled_sizes = get_option( 'ewww_image_optimizer_disable_resizes_opt' );
			ewwwio_debug_message( 'retrieving resizes' );
			// Meta sizes don't contain a path, so we calculate one.
			$base_dir = trailingslashit( dirname( $filename ) );
			$processed = array();
			foreach ( $meta['sizes'] as $size => $data ) {
				ewwwio_debug_message( "processing size: $size" );
				if ( preg_match( '/webp/', $size ) ) {
					continue;
				}
				if ( ! empty( $disabled_sizes[ $size ] ) ) {
					continue;
				}
				if ( empty( $data['file'] ) ) {
					continue;
				}
				$dup_size = false;
				// Check through all the sizes we've processed so far.
				foreach ( $processed as $proc => $scan ) {
					// If a previous resize had identical dimensions.
					if ( $scan['height'] == $data['height'] && $scan['width'] == $data['width'] ) {
						// Found a duplicate resize.
						$dup_size = true;
					}
				}
				// If this is a unique size.
				if ( ! $dup_size ) {
					$resize_path = $base_dir . $data['file'];
					$resize_url = $as3cf->get_attachment_url( $id, null, $size, $meta );
					ewwwio_debug_message( "fetching $resize_url to $resize_path" );
					$temp_file = download_url( $resize_url );
					if ( ! is_wp_error( $temp_file ) ) {
						rename( $temp_file, $resize_path );
					}
				}
				// Store info on the sizes we've processed, so we can check the list for duplicate sizes.
				$processed[ $size ]['width'] = $data['width'];
				$processed[ $size ]['height'] = $data['height'];
			}
		} // End if().
	} // End if().
	if ( class_exists( 'WindowsAzureStorageUtil' ) && get_option( 'azure_storage_use_for_default_upload' ) ) {
		$full_url = $meta['url'];
		$filename = $meta['file'];
		ewwwio_debug_message( "azure fullsize url: $full_url" );
		ewwwio_debug_message( "fullsize path: $filename" );
		$temp_file = download_url( $full_url );
		if ( ! is_wp_error( $temp_file ) ) {
			rename( $temp_file, $filename );
		}
		// Resized versions, so we'll grab those too.
		if ( isset( $meta['sizes'] ) && ewww_image_optimizer_iterable( $meta['sizes'] ) ) {
			$disabled_sizes = get_option( 'ewww_image_optimizer_disable_resizes_opt' );
			ewwwio_debug_message( 'retrieving resizes' );
			// Meta sizes don't contain a path, so we calculate one.
			$base_dir = trailingslashit( dirname( $filename ) );
			$base_url = trailingslashit( dirname( $full_url ) );
			// Process each resized version.
			$processed = array();
			foreach ( $meta['sizes'] as $size => $data ) {
				ewwwio_debug_message( "processing size: $size" );
				if ( preg_match( '/webp/', $size ) ) {
					continue;
				}
				if ( ! empty( $disabled_sizes[ $size ] ) ) {
					continue;
				}
				if ( empty( $data['file'] ) ) {
					continue;
				}
				$dup_size = false;
				// Check through all the sizes we've processed so far.
				foreach ( $processed as $proc => $scan ) {
					// If a previous resize had identical dimensions.
					if ( $scan['height'] == $data['height'] && $scan['width'] == $data['width'] ) {
						// Found a duplicate resize.
						$dup_size = true;
					}
				}
				// If this is a unique size.
				if ( ! $dup_size ) {
					$resize_path = $base_dir . $data['file'];
					$resize_url = $base_url . $data['file'];
					ewwwio_debug_message( "fetching $resize_url to $resize_path" );
					$temp_file = download_url( $resize_url );
					if ( ! is_wp_error( $temp_file ) ) {
						rename( $temp_file, $resize_path );
					}
				}
				// Store info on the sizes we've processed, so we can check the list for duplicate sizes.
				$processed[ $size ]['width'] = $data['width'];
				$processed[ $size ]['height'] = $data['height'];
			}
		} // End if().
	} // End if().
	if ( ! empty( $filename ) && is_file( $filename ) ) {
		return $filename;
	} else {
		return false;
	}
}

/**
 * Searches the database for a matching s3 path, and fixes it to use the local path.
 *
 * @global object $wpdb
 *
 * @param array  $meta The attachment metadata.
 * @param int    $id The attachment ID number.
 * @param string $s3_path The potential s3:// path.
 */
function ewww_image_optimizer_check_table_as3cf( $meta, $id, $s3_path ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	$local_path = get_attached_file( $id, true );
	ewwwio_debug_message( "unfiltered local path: $local_path" );
	if ( $local_path !== $s3_path ) {
		ewww_image_optimizer_update_table_as3cf( $local_path, $s3_path );
	}
	if ( isset( $meta['sizes'] ) && ewww_image_optimizer_iterable( $meta['sizes'] ) ) {
		ewwwio_debug_message( 'updating s3 resizes' );
		// Meta sizes don't contain a path, so we calculate one.
		$local_dir = trailingslashit( dirname( $local_path ) );
		$s3_dir = trailingslashit( dirname( $s3_path ) );
		$processed = array();
		foreach ( $meta['sizes'] as $size => $data ) {
			if ( strpos( $size, 'webp' ) === 0 ) {
				continue;
			}
			// Check through all the sizes we've processed so far.
			foreach ( $processed as $proc => $scan ) {
				// If a previous resize had identical dimensions.
				if ( $scan['height'] === $data['height'] && $scan['width'] === $data['width'] ) {
					// Found a duplicate resize.
					continue;
				}
			}
			// If this is a unique size.
			$local_resize_path = $local_dir . $data['file'];
			$s3_resize_path = $s3_dir . $data['file'];
			if ( $local_resize_path !== $s3_resize_path ) {
				ewww_image_optimizer_update_table_as3cf( $local_resize_path, $s3_resize_path );
			}
			// Store info on the sizes we've processed, so we can check the list for duplicate sizes.
			$processed[ $size ]['width'] = $data['width'];
			$processed[ $size ]['height'] = $data['height'];
		}
	}
	global $wpdb;
	$wpdb->flush();
}

/**
 * Given an S3 path, replaces it with the local path.
 *
 * @global object $wpdb
 * @global object $ewwwdb A clone of $wpdb unless it is lacking utf8 connectivity.
 *
 * @param string $local_path The local filesystem path to the image.
 * @param string $s3_path The remote S3 path to the image.
 */
function ewww_image_optimizer_update_table_as3cf( $local_path, $s3_path ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	// First we need to see if anything matches the s3 path.
	$s3_image = ewww_image_optimizer_find_already_optimized( $s3_path );
	ewwwio_debug_message( "looking for $s3_path" );
	if ( is_array( $s3_image ) ) {
		global $wpdb;
		if ( strpos( $wpdb->charset, 'utf8' ) === false ) {
			ewww_image_optimizer_db_init();
			global $ewwwdb;
		} else {
			$ewwwdb = $wpdb;
		}
		ewwwio_debug_message( "found $s3_path in db" );
		// When we find a match by the s3 path, we need to find out if there are already records for the local path.
		$found_local_image = ewww_image_optimizer_find_already_optimized( $local_path );
		ewwwio_debug_message( "looking for $local_path" );
		// If we found records for both local and s3 paths, we delete the s3 record, but store the original size in the local record.
		if ( ! empty( $found_local_image ) && is_array( $found_local_image ) ) {
			ewwwio_debug_message( "found $local_path in db" );
			$ewwwdb->delete( $ewwwdb->ewwwio_images,
				array(
					'id' => $s3_image['id'],
				),
				array(
					'%d'
				)
			);
			if ( $s3_image['orig_size'] > $found_local_image['orig_size'] ) {
				$ewwwdb->update( $ewwwdb->ewwwio_images,
					array(
						'orig_size' => $s3_image['orig_size'],
						'results' => $s3_image['results'],
					),
					array(
						'id' => $found_local_image['id'],
					)
				);
			}
		} else {
			// If we just found an s3 path and no local match, then we just update the path in the table to the local path.
			ewwwio_debug_message( 'just updating s3 to local' );
			$ewwwdb->update( $ewwwdb->ewwwio_images,
				array(
					'path' => ewww_image_optimizer_relative_path_remove( $local_path ),
				),
				array(
					'id' => $s3_image['id'],
				)
			);
		}
	} // End if().
}

/**
 * If a JPG image is using the EXIF orientiation, correct the rotation, and reset the Orientation.
 *
 * @param string $file The file to check for rotation.
 */
function ewww_image_optimizer_autorotate( $file ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( defined( 'EWWW_IMAGE_OPTIMIZER_NO_ROTATE' ) && EWWW_IMAGE_OPTIMIZER_NO_ROTATE ) {
		return;
	}
	$type = ewww_image_optimizer_mimetype( $file, 'i' );
	if ( 'image/jpeg' != $type ) {
		ewwwio_debug_message( 'not a JPG, no rotation needed' );
		return;
	}
	$orientation = ewww_image_optimizer_get_orientation( $file, $type );
	if ( ! $orientation || 1 == $orientation ) {
		return;
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ) < 20 ) {
		// Read the exif, if it fails, we won't autorotate.
		$jpeg = new PelJpeg( $file );
		$exif = $jpeg->getExif();
		if ( null == $exif ) {
			ewwwio_debug_message( 'could not work with PelJpeg object, no rotation happening here' );
		} elseif ( ewww_image_optimizer_jpegtran_autorotate( $file, $type, $orientation ) ) {
			// Use PEL to correct the orientation flag when metadata was preserved.
			$jpeg = new PelJpeg( $file );
			$exif = $jpeg->getExif();
			if ( null != $exif ) {
				$tiff = $exif->getTiff();
				$ifd0 = $tiff->getIfd();
				$orientation = $ifd0->getEntry( PelTag::ORIENTATION );
				if ( null != $orientation ) {
					ewwwio_debug_message( 'orientation being adjusted' );
					$orientation->setValue( 1 );
				}
				$jpeg->saveFile( $file );
			}
		}
		return;
	}
	ewww_image_optimizer_cloud_autorotate( $file, $type );
}

/**
 * Resizes Media Library uploads based on the maximum dimensions specified by the user.
 *
 * @global object $wpdb
 * @global object $ewwwdb A clone of $wpdb unless it is lacking utf8 connectivity.
 *
 * @param string $file The file to check for rotation.
 * @return array|bool The new height and width, or false if no resizing was done.
 */
function ewww_image_optimizer_resize_upload( $file ) {
	// Parts adapted from Imsanity (THANKS Jason!).
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( ! $file ) {
		return false;
	}
	if ( ! empty( $_REQUEST['post_id'] ) || ( ! empty( $_REQUEST['action'] ) && 'upload-attachment' === $_REQUEST['action'] ) || ( ! empty( $_SERVER['HTTP_REFERER'] ) && strpos( $_SERVER['HTTP_REFERER'], 'media-new.php' ) ) ) {
		$maxwidth = ewww_image_optimizer_get_option( 'ewww_image_optimizer_maxmediawidth' );
		$maxheight = ewww_image_optimizer_get_option( 'ewww_image_optimizer_maxmediaheight' );
		ewwwio_debug_message( 'resizing image from media library or attached to post' );
	} else {
		$maxwidth = ewww_image_optimizer_get_option( 'ewww_image_optimizer_maxotherwidth' );
		$maxheight = ewww_image_optimizer_get_option( 'ewww_image_optimizer_maxotherheight' );
		ewwwio_debug_message( 'resizing images from somewhere else' );
	}

	/**
	 * Filters the dimensions to used for resizing uploaded images.
	 *
	 * @param array $args {
	 *	The dimensions to be used in resizing.
	 *
	 *	@type int $maxwidth The maximum width of the image.
	 *	@type int $maxheight The maximum height of the image.
	 * }
	 * @param string $file The name of the file being resized.
	 */
	list( $maxwidth, $maxheight ) = apply_filters( 'ewww_image_optimizer_resize_dimensions', array( $maxwidth, $maxheight ), $file );

	// Check that options are not both set to zero.
	if ( 0 == $maxwidth && 0 == $maxheight ) {
		return false;
	}
	$maxwidth = $maxwidth ? $maxwidth : 999999;
	$maxheight = $maxheight ? $maxheight : 999999;
	// Check the file type.
	$type = ewww_image_optimizer_mimetype( $file, 'i' );
	if ( strpos( $type, 'image' ) === false ) {
		ewwwio_debug_message( 'not an image, cannot resize' );
		return false;
	}
	// Check file size (dimensions).
	list( $oldwidth, $oldheight ) = getimagesize( $file );
	if ( $oldwidth <= $maxwidth && $oldheight <= $maxheight ) {
		ewwwio_debug_message( 'image too small for resizing' );
		if ( $oldwidth && $oldheight ) {
			return array( $oldwidth, $oldheight );
		}
		return false;
	}
	$crop = false;
	if ( $oldwidth > $maxwidth && $maxwidth && $oldheight > $maxheight && $maxheight && apply_filters( 'ewww_image_optimizer_crop_image', false ) ) {
		$crop = true;
		$newwidth = $maxwidth;
		$newheight = $maxheight;
	} else {
		list( $newwidth, $newheight ) = wp_constrain_dimensions( $oldwidth, $oldheight, $maxwidth, $maxheight );
	}
	if ( ! function_exists( 'wp_get_image_editor' ) ) {
		ewwwio_debug_message( 'no image editor function' );
		return false;
	}
	remove_filter( 'wp_image_editors', 'ewww_image_optimizer_load_editor', 60 );
	$editor = wp_get_image_editor( $file );
	if ( is_wp_error( $editor ) ) {
		ewwwio_debug_message( 'could not get image editor' );
		return false;
	}
	// Rotation should not happen here anymore, so don't worry about it breaking the dimensions.
	$orientation = ewww_image_optimizer_get_orientation( $file, $type );
	$rotated = false;
	switch ( $orientation ) {
		case 3:
			$editor->rotate( 180 );
			$rotated = true;
			break;
		case 6:
			$editor->rotate( -90 );
			$new_newwidth = $newwidth;
			$newwidth = $newheight;
			$newheight = $new_newwidth;
			$rotated = true;
			break;
		case 8:
			$editor->rotate( 90 );
			$new_newwidth = $newwidth;
			$newwidth = $newheight;
			$newheight = $new_newwidth;
			$rotated = true;
			break;
	}
	$resized_image = $editor->resize( $newwidth, $newheight, $crop );
	if ( is_wp_error( $resized_image ) ) {
		ewwwio_debug_message( 'error during resizing' );
		return false;
	}
	$new_file = $editor->generate_filename( 'tmp' );
	$orig_size = filesize( $file );
	ewwwio_debug_message( "before resizing: $orig_size" );
	$saved = $editor->save( $new_file );
	if ( is_wp_error( $saved ) ) {
		ewwwio_debug_message( 'error saving resized image' );
	}
	add_filter( 'wp_image_editors', 'ewww_image_optimizer_load_editor', 60 );
	$new_size = ewww_image_optimizer_filesize( $new_file );
	if ( $new_size && $new_size < $orig_size ) {
		// Use this action to perform any operations on the original file before it is overwritten with the new, smaller file.
		do_action( 'ewww_image_optimizer_image_resized', $file, $new_file );
		ewwwio_debug_message( "after resizing: $new_size" );
		// TODO: see if there is a way to just check that meta exists on the new image.
		// Use PEL to get the exif (if Remove Meta is unchecked) and GD is in use, so we can save it to the new image.
		if ( 'image/jpeg' === $type && ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_metadata_skip_full' ) || ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpegtran_copy' ) ) && ! ewww_image_optimizer_imagick_support() ) {
			$old_jpeg = new PelJpeg( $file );
			$old_exif = $old_jpeg->getExif();
			$new_jpeg = new PelJpeg( $new_file );
			if ( null != $old_exif ) {
				if ( $rotated ) {
					$tiff = $old_exif->getTiff();
					$ifd0 = $tiff->getIfd();
					$orientation = $ifd0->getEntry( PelTag::ORIENTATION );
					if ( null != $orientation ) {
						$orientation->setValue( 1 );
					}
				}
				$new_jpeg->setExif( $old_exif );
			}
			$new_jpeg->saveFile( $new_file );
		}
		rename( $new_file, $file );
		// Store info on the current image for future reference.
		global $wpdb;
		if ( strpos( $wpdb->charset, 'utf8' ) === false ) {
			ewww_image_optimizer_db_init();
			global $ewwwdb;
		} else {
			$ewwwdb = $wpdb;
		}
		$already_optimized = ewww_image_optimizer_find_already_optimized( $file );
		// If the original file has never been optimized, then just update the record that was created with the proper filename (because the resized file has usually been optimized).
		if ( empty( $already_optimized ) ) {
			$tmp_exists = $ewwwdb->update( $ewwwdb->ewwwio_images,
				array(
					'path' => ewww_image_optimizer_relative_path_remove( $file ),
					'orig_size' => $orig_size,
				),
				array(
					'path' => ewww_image_optimizer_relative_path_remove( $new_file ),
				)
			);
			// If the tmp file didn't get optimized (and it shouldn't), then just insert a dummy record to be updated shortly.
			if ( ! $tmp_exists ) {
				$ewwwdb->insert( $ewwwdb->ewwwio_images, array(
					'path' => ewww_image_optimizer_relative_path_remove( $file ),
					'orig_size' => $orig_size,
				) );
			}
		} else {
			// Otherwise, we delete the record created from optimizing the resized file.
			$temp_optimized = ewww_image_optimizer_find_already_optimized( $new_file );
			if ( is_array( $temp_optimized ) && ! empty( $temp_optimized['id'] ) ) {
				$ewwwdb->delete( $ewwwdb->ewwwio_images,
					array(
						'id' => $temp_optimized['id'],
					),
					array(
						'%d',
					)
				);
			}
		}
		return array( $newwidth, $newheight );
	} // End if().
	if ( is_file( $new_file ) ) {
		ewwwio_debug_message( "resizing did not create a smaller image: $new_size" );
		unlink( $new_file );
	}
	return false;
}

/**
 * Gets the orientation/rotation of a JPG image using the EXIF data.
 *
 * @param string $file Name of the file.
 * @param string $type Mime type of the file.
 * @return int|bool The orientation value or false.
 */
function ewww_image_optimizer_get_orientation( $file, $type ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( function_exists( 'exif_read_data' ) && 'image/jpeg' === $type ) {
		$exif = @exif_read_data( $file );
		if ( ewww_image_optimizer_function_exists( 'print_r' ) ) {
			ewwwio_debug_message( print_r( $exif, true ) );
		}
		if ( is_array( $exif ) && array_key_exists( 'Orientation', $exif ) ) {
			return $exif['Orientation'];
		}
	}
	return false;
}

/**
 * Searches the images table for a file.
 *
 * If more than one record is found, verifies case and calls duplicate removal if needed.
 *
 * @global object $wpdb
 * @global object $ewwwdb A clone of $wpdb unless it is lacking utf8 connectivity.
 *
 * @param string $attachment The name of the file.
 * @return array|bool If found, information about the image, false otherwise.
 */
function ewww_image_optimizer_find_already_optimized( $attachment ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	global $wpdb;
	if ( strpos( $wpdb->charset, 'utf8' ) === false ) {
		ewww_image_optimizer_db_init();
		global $ewwwdb;
	} else {
		$ewwwdb = $wpdb;
	}
	$maybe_return_image = false;
	$query = $ewwwdb->prepare( "SELECT * FROM $ewwwdb->ewwwio_images WHERE path = %s", ewww_image_optimizer_relative_path_remove( $attachment ) );
	$optimized_query = $ewwwdb->get_results( $query, ARRAY_A );
	if ( ewww_image_optimizer_iterable( $optimized_query ) ) {
		foreach ( $optimized_query as $image ) {
			$image['path'] = ewww_image_optimizer_relative_path_replace( $image['path'] );
			if ( $image['path'] != $attachment ) {
				ewwwio_debug_message( "{$image['path']} does not match $attachment, continuing our search" );
			} elseif ( ! $maybe_return_image ) {
				ewwwio_debug_message( "found a match for $attachment" );
				$maybe_return_image = $image;
			} else {
				if ( empty( $duplicates ) ) {
					$duplicates = array( $maybe_return_image, $image );
				} else {
					$duplicates[] = $image;
				}
			}
		}
	}
	// Do something with duplicates.
	if ( ! empty( $duplicates ) && is_array( $duplicates ) ) {
		$keeper = ewww_image_optimizer_remove_duplicate_records( $duplicates );
		if ( ! empty( $keeper ) && is_array( $keeper ) ) {
			$maybe_return_image = $keeper;
		}
	}
	return $maybe_return_image;
}

/**
 * Merge duplicate records from the images table and remove any extras.
 *
 * @global object $wpdb
 * @global object $ewwwdb A clone of $wpdb unless it is lacking utf8 connectivity.
 *
 * @param array $duplicates An array of records referencing the same image.
 * @return array|bool A single image record or false if something unexpected happens.
 */
function ewww_image_optimizer_remove_duplicate_records( $duplicates ) {
	if ( empty( $duplicates ) ) {
		return false;
	}
	global $wpdb;
	if ( strpos( $wpdb->charset, 'utf8' ) === false ) {
		ewww_image_optimizer_db_init();
		global $ewwwdb;
	} else {
		$ewwwdb = $wpdb;
	}
	if ( ! is_array( $duplicates[0] ) ) {
		// Retrieve records for the ID #s passed.
		$duplicate_ids = implode( ',', array_map( 'intval', $duplicates ) );
		$duplicates = $ewwwdb->get_results( "SELECT * FROM $ewwwdb->ewwwio_images WHERE id IN ($duplicate_ids)" ); // WPCS: unprepared SQL ok.
	}
	if ( ! is_array( $duplicates ) || ! is_array( $duplicates[0] ) ) {
		return false;
	}
	$image_size = ewww_image_optimizer_filesize( $duplicates[0]['path'] );
	$discard = array();
	// First look for an image size match.
	foreach ( $duplicates as $duplicate ) {
		if ( empty( $keeper ) && ! empty( $duplicate['image_size'] ) && $image_size == $duplicate['image_size'] ) {
			$keeper = $duplicate;
		} else {
			$discard[] = $duplicate;
		}
	}
	// Then look for the first record with an image_size (that means it has been optimized).
	if ( empty( $keeper ) ) {
		$discard = array();
		foreach ( $duplicates as $duplicate ) {
			if ( empty( $keeper ) && ! empty( $duplicate['image_size'] ) ) {
				$keeper = $duplicate;
			} else {
				$discard[] = $duplicate;
			}
		}
	}
	// If we still have nothing, mark the 0 record as the primary and pull it off the stack.
	if ( empty( $keeper ) ) {
		$keeper = array_shift( $duplicates );
		$discard = $duplicates;
	}
	if ( is_array( $keeper ) && is_array( $discard ) ) {
		$delete_ids = array();
		foreach ( $discard as $record ) {
			foreach ( $record as $key => $value ) {
				if ( empty( $keeper[ $key ] ) && ! empty( $value ) ) {
					$keeper[ $key ] = $value;
				}
			}
			$delete_ids[] = (int) $record['id'];
		}
		if ( ! empty( $delete_ids ) && is_array( $delete_ids ) ) {
			$ewwwdb->query( "DELETE FROM $ewwwdb->ewwwio_images WHERE id IN (" . implode( ',', $delete_ids ) . ')' ); // WPCS: unprepared SQL ok.
		}
		return $keeper;
	}
	return false;
}

/**
 * Checks to see if we should use background optimization for an image.
 *
 * Uses the mimetype and current configuration to determine if background mode should be used.
 *
 * @global bool $ewww_defer True to defer optimization.
 *
 * @param string $type Optional. Mime type of image being processed. Default ''.
 * @return bool True if background mode should be used.
 */
function ewww_image_optimizer_test_background_opt( $type = '' ) {
	if ( defined( 'EWWW_DISABLE_ASYNC' ) && EWWW_DISABLE_ASYNC ) {
		return false;
	}
	if ( ! ewww_image_optimizer_function_exists( 'sleep' ) ) {
		return false;
	}
	if ( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_background_optimization' ) ) {
		return false;
	}
	if ( ewww_image_optimizer_detect_wpsf_location_lock() ) {
		return false;
	}
	if ( 'image/type' == $type && ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_to_png' ) ) {
		return apply_filters( 'ewww_image_optimizer_defer_conversion', false );
	}
	if ( 'image/png' == $type && ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_to_jpg' ) ) {
		return apply_filters( 'ewww_image_optimizer_defer_conversion', false );
	}
	if ( 'image/gif' == $type && ewww_image_optimizer_get_option( 'ewww_image_optimizer_gif_to_png' ) ) {
		return apply_filters( 'ewww_image_optimizer_defer_conversion', false );
	}
	global $ewww_defer;
	return (bool) apply_filters( 'ewww_image_optimizer_background_optimization', $ewww_defer );
}

/**
 * Checks to see if we should use parallel optimization for an image.
 *
 * Uses the mimetype and current configuration to determine if parallel mode should be used.
 *
 * @param string $type Optional. Mime type of image being processed. Default ''.
 * @param int    $id Optional. Attachment ID number. Default 0.
 * @return bool True if parallel mode should be used.
 */
function ewww_image_optimizer_test_parallel_opt( $type = '', $id = 0 ) {
	if ( defined( 'EWWW_DISABLE_ASYNC' ) && EWWW_DISABLE_ASYNC ) {
		return false;
	}
	if ( ! ewww_image_optimizer_function_exists( 'sleep' ) ) {
		return false;
	}
	if ( ewww_image_optimizer_detect_wpsf_location_lock() ) {
		return false;
	}
	if ( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_parallel_optimization' ) ) {
		return false;
	}
	if ( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_background_optimization' ) ) {
		return false;
	}
	if ( empty( $id ) ) {
		return true;
	}
	if ( ! empty( $_REQUEST['ewww_convert'] ) ) {
		return false;
	}
	if ( empty( $type ) ) {
		$type = get_post_mime_type( $id );
	}
	if ( 'image/jpeg' == $type && ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_to_png' ) ) {
		return false;
	}
	if ( 'image/png' == $type && ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_to_jpg' ) ) {
		return false;
	}
	if ( 'image/gif' == $type && ewww_image_optimizer_get_option( 'ewww_image_optimizer_gif_to_png' ) ) {
		return false;
	}
	if ( 'application/pdf' == $type ) {
		return false;
	}
	return true;
}

/**
 * Rebuilds metadata and regenerates thumbs for an attachment.
 *
 * @param int $attachment_id The ID number of the attachment.
 * @return array Attachment metadata, if the rebuild was successful.
 */
function ewww_image_optimizer_rebuild_meta( $attachment_id ) {
	$file = get_attached_file( $attachment_id );
	if ( is_file( $file ) ) {
		remove_filter( 'wp_image_editors', 'ewww_image_optimizer_load_editor', 60 );
		remove_all_filters( 'wp_generate_attachment_metadata' );
		ewwwio_debug_message( "generating new meta for $attachment_id" );
		$meta = wp_generate_attachment_metadata( $attachment_id, $file );
		ewwwio_debug_message( "generated new meta for $attachment_id" );
		$updated = update_post_meta( $attachment_id, '_wp_attachment_metadata', $meta );
		if ( $updated ) {
			ewwwio_debug_message( "updated meta for $attachment_id" );
		} else {
			ewwwio_debug_message( "failed meta update for $attachment_id" );
		}
		return $meta;
	}
}

/**
 * Find image paths from an attachment's meta data and process each image.
 *
 * Called after `wp_generate_attachment_metadata` is completed, it also searches for retina images,
 * and a few custom theme resizes. When a new image is uploaded, it is added to the queue, if
 * possible, and then this same function is run in the background. Optionally, it will use parallel
 * (async) requests to speed up the process.
 *
 * @global object $wpdb
 * @global object $ewwwdb A clone of $wpdb unless it is lacking utf8 connectivity.
 * @global bool $ewww_defer True if optimization should be deferred until later.
 * @global bool $ewww_new_image True if this is a newly uploaded image.
 * @global object $ewww_image Contains more information about the image currently being processed.
 * @global object $ewwwio_media_background;
 * @global object $ewwwio_async_optimize_media;
 * @global array $ewww_attachment {
 *	Stores the ID and meta for later use with W3TC.
 *
 *	@int int $id The attachment ID number.
 *	@array array $meta The attachment metadata from the postmeta table.
 * }
 * @global object $as3cf For working with the WP Offload S3 plugin.
 * @global object $dreamspeed For working with the Dreamspeed CDN plugin.
 *
 * @param array $meta The attachment metadata generated by WordPress.
 * @param int   $id Optional. The attachment ID number. Default null. Accepts any non-negative integer.
 * @param bool  $log Optional. True to flush debug info to the log at the end of the function.
 * @param bool  $background_new Optional. True indicates this is a new image processed in the background.
 * @return array $meta Send the metadata back from whence it came.
 */
function ewww_image_optimizer_resize_from_meta_data( $meta, $id = null, $log = true, $background_new = false ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( ! is_array( $meta ) && empty( $meta ) ) {
		$meta = array();
	} elseif ( ! is_array( $meta ) ) {
		if ( is_string( $meta )  && is_numeric( $id ) && 'processing' == $meta ) {
			ewwwio_debug_message( "attempting to rebuild attachment meta for $id" );
			$new_meta = ewww_image_optimizer_rebuild_meta( $id );
			if ( ! is_array( $new_meta ) ) {
				ewwwio_debug_message( 'attempt to rebuild attachment meta failed' );
				return $meta;
			} else {
				$meta = $new_meta;
			}
		} else {
			ewwwio_debug_message( 'attachment meta is not a usable array' );
			return $meta;
		}
	} elseif ( is_array( $meta ) && ! empty( $meta[0] ) && 'processing' == $meta[0] ) {
		ewwwio_debug_message( "attempting to rebuild attachment meta for $id" );
		$new_meta = ewww_image_optimizer_rebuild_meta( $id );
		if ( ! is_array( $new_meta ) ) {
			ewwwio_debug_message( 'attempt to rebuild attachment meta failed' );
			return $meta;
		} else {
			$meta = $new_meta;
		}
	}
	global $wpdb;
	if ( strpos( $wpdb->charset, 'utf8' ) === false ) {
		ewww_image_optimizer_db_init();
		global $ewwwdb;
	} else {
		$ewwwdb = $wpdb;
	}
	global $ewww_defer;
	global $ewww_new_image;
	global $ewww_image;
	$gallery_type = 1;
	ewwwio_debug_message( "attachment id: $id" );

	session_write_close();
	if ( ! empty( $ewww_new_image ) ) {
		ewwwio_debug_message( 'this is a newly uploaded image with no metadata yet' );
		$new_image = true;
	} else {
		ewwwio_debug_message( 'this image already has metadata, so it is not new' );
		$new_image = false;
	}
	list( $file_path, $upload_path ) = ewww_image_optimizer_attachment_path( $meta, $id );
	// If the attachment has been uploaded via the image store plugin.
	if ( 'ims_image' == get_post_type( $id ) ) {
		$gallery_type = 6;
	}
	if ( ! $new_image && class_exists( 'Amazon_S3_And_CloudFront' ) && strpos( $file_path, 's3' ) === 0 ) {
		ewww_image_optimizer_check_table_as3cf( $meta, $id, $file_path );
	}
	// If the local file is missing and we have valid metadata, see if we can fetch via CDN.
	if ( ! is_file( $file_path ) || ( strpos( $file_path, 's3' ) === 0 && ! class_exists( 'S3_Uploads' ) ) ) {
		$file_path = ewww_image_optimizer_remote_fetch( $id, $meta );
		if ( ! $file_path ) {
			ewwwio_debug_message( 'could not retrieve path' );
			return $meta;
		}
	}
	ewwwio_debug_message( "retrieved file path: $file_path" );
	$type = ewww_image_optimizer_mimetype( $file_path, 'i' );
	$supported_types = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'application/pdf',
	);
	if ( ! in_array( $type, $supported_types ) ) {
		ewwwio_debug_message( "mimetype not supported: $id" );
		return $meta;
	}
	$fullsize_size = ewww_image_optimizer_filesize( $file_path );
	// See if this is a new image and Imsanity resized it (which means it could be already optimized).
	if ( ! empty( $new_image ) && function_exists( 'imsanity_get_max_width_height' ) && strpos( $type, 'image' ) !== false ) {
		list( $maxw, $maxh ) = imsanity_get_max_width_height( IMSANITY_SOURCE_LIBRARY );
		list( $oldw, $oldh ) = getimagesize( $file_path );
		list( $neww, $newh ) = wp_constrain_dimensions( $oldw, $oldh, $maxw, $maxh );
		$path_parts = pathinfo( $file_path );
		$imsanity_path = trailingslashit( $path_parts['dirname'] ) . $path_parts['filename'] . '-' . $neww . 'x' . $newh . '.' . $path_parts['extension'];
		ewwwio_debug_message( "imsanity path: $imsanity_path" );
		$image_size = ewww_image_optimizer_filesize( $file_path );
		$already_optimized = ewww_image_optimizer_find_already_optimized( $imsanity_path );
		if ( is_array( $already_optimized ) ) {
			ewwwio_debug_message( "updating existing record, path: $file_path, size: " . $image_size );
			// Store info on the current image for future reference.
			$ewwwdb->update( $ewwwdb->ewwwio_images,
				array(
					'path' => ewww_image_optimizer_relative_path_remove( $file_path ),
					'attachment_id' => $id,
					'resize' => 'full',
					'gallery' => 'media',
				),
				array(
					'id' => $already_optimized['id'],
			));
		}
	}
	// Resize here so long as this is not a new image AND resize existing is enabled, and imsanity isn't enabled with a max size.
	if ( ( empty( $new_image ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_resize_existing' ) ) && ! function_exists( 'imsanity_get_max_width_height' ) ) {
		$new_dimensions = ewww_image_optimizer_resize_upload( $file_path );
		if ( is_array( $new_dimensions ) ) {
			$meta['width'] = $new_dimensions[0];
			$meta['height'] = $new_dimensions[1];
		}
	}
	if ( ewww_image_optimizer_test_background_opt( $type ) ) {
		add_filter( 'http_headers_useragent', 'ewww_image_optimizer_cloud_useragent', PHP_INT_MAX );
		add_filter( 'as3cf_pre_update_attachment_metadata', '__return_true' );
		global $ewwwio_media_background;
		if ( ! class_exists( 'WP_Background_Process' ) ) {
			require_once( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'background.php' );
		}
		if ( ! is_object( $ewwwio_media_background ) ) {
			$ewwwio_media_background = new EWWWIO_Media_Background_Process();
		}
		ewwwio_debug_message( "backgrounding optimization for $id" );
		$ewwwio_media_background->push_to_queue( array(
			'id' => $id,
			'new' => $new_image,
			'type' => $type,
		) );
		$ewwwio_media_background->save()->dispatch();
		set_transient( 'ewwwio-background-in-progress-' . $id, true, 24 * HOUR_IN_SECONDS );
		if ( $log ) {
			ewww_image_optimizer_debug_log();
		}
		return $meta;
	}
	if ( $background_new ) {
		$new_image = true;
	}
	// Resize here if the user has used the filter to defer resizing, we have a new image OR resize existing is enabled, and imsanity isn't enabled with a max size.
	if ( apply_filters( 'ewww_image_optimizer_defer_resizing', false ) && ( ! empty( $new_image ) || ewww_image_optimizer_get_option( 'ewww_image_optimizer_resize_existing' ) ) && ! function_exists( 'imsanity_get_max_width_height' ) ) {
		$new_dimensions = ewww_image_optimizer_resize_upload( $file_path );
		if ( is_array( $new_dimensions ) ) {
			$meta['width'] = $new_dimensions[0];
			$meta['height'] = $new_dimensions[1];
		}
	}
	// This gets a bit long, so here goes:
	// we run in parallel if we didn't detect breakage (test_parallel_opt), and there are enough resizes to make it worthwhile (or if the API is enabled).
	if ( ewww_image_optimizer_test_parallel_opt( $type, $id ) && isset( $meta['sizes'] ) && ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) || count( $meta['sizes'] ) > 5 ) ) {
		ewwwio_debug_message( 'running in parallel' );
		$parallel_opt = true;
	} else {
		ewwwio_debug_message( 'running in sequence' );
		$parallel_opt = false;
	}
	$parallel_sizes = array();
	if ( $parallel_opt ) {
		add_filter( 'http_headers_useragent', 'ewww_image_optimizer_cloud_useragent', PHP_INT_MAX );
		$parallel_sizes['full'] = $file_path;
		global $ewwwio_async_optimize_media;
		if ( ! class_exists( 'WP_Background_Process' ) ) {
			require_once( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'background.php' );
		}
		if ( ! is_object( $ewwwio_async_optimize_media ) ) {
			$ewwwio_async_optimize_media = new EWWWIO_Async_Request();
		}
	} else {
		$ewww_image = new EWWW_Image( $id, 'media', $file_path );
		$ewww_image->resize = 'full';
		list( $file, $msg, $conv, $original ) = ewww_image_optimizer( $file_path, $gallery_type, false, $new_image, true );

		if ( false === $file ) {
			return $meta;
		}
		// If the file was converted.
		if ( false !== $conv ) {
			if ( $conv ) {
				$ewww_image->increment = $conv;
			}
			$ewww_image->file = $file;
			$ewww_image->converted = $original;
			$meta['file'] = trailingslashit( dirname( $meta['file'] ) ) . basename( $file );
			$ewww_image->update_converted_attachment( $meta );
			$meta = $ewww_image->convert_sizes( $meta );
			ewwwio_debug_message( 'image was converted' );
		} else {
			remove_filter( 'wp_update_attachment_metadata', 'ewww_image_optimizer_update_attachment', 10 );
		}
		ewww_image_optimizer_hidpi_optimize( $file );
	}
	// Resized versions, so we can continue.
	if ( isset( $meta['sizes'] ) && ewww_image_optimizer_iterable( $meta['sizes'] ) ) {
		$disabled_sizes = get_option( 'ewww_image_optimizer_disable_resizes_opt' );
		ewwwio_debug_message( 'processing resizes' );
		// Meta sizes don't contain a path, so we calculate one.
		if ( 6 === $gallery_type ) {
			$base_ims_dir = trailingslashit( dirname( $file_path ) ) . '_resized/';
		}
		$base_dir = trailingslashit( dirname( $file_path ) );
		// Process each resized version.
		$processed = array();
		foreach ( $meta['sizes'] as $size => $data ) {
			ewwwio_debug_message( "processing size: $size" );
			if ( strpos( $size, 'webp' ) === 0 ) {
				continue;
			}
			if ( ! empty( $disabled_sizes[ $size ] ) ) {
				continue;
			}
			if ( ! empty( $disabled_sizes['pdf-full'] ) && 'full' == $size ) {
				continue;
			}
			if ( empty( $data['file'] ) ) {
				continue;
			}
			if ( 6 === $gallery_type ) {
				// We reset base_dir, because base_dir potentially gets overwritten with base_ims_dir.
				$base_dir = trailingslashit( dirname( $file_path ) );
				$image_path = $base_dir . $data['file'];
				$ims_path = $base_ims_dir . $data['file'];
				if ( is_file( $ims_path ) ) {
					ewwwio_debug_message( 'ims resize already exists, wahoo' );
					ewwwio_debug_message( "ims path: $ims_path" );
					$image_size = ewww_image_optimizer_filesize( $ims_path );
					$already_optimized = ewww_image_optimizer_find_already_optimized( $image_path );
					if ( is_array( $already_optimized ) ) {
						ewwwio_debug_message( "updating existing record, path: $ims_path, size: " . $image_size );
						// Store info on the current image for future reference.
						$ewwwdb->update( $ewwwdb->ewwwio_images,
							array(
								'path' => ewww_image_optimizer_relative_path_remove( $ims_path ),
							),
							array(
								'id' => $already_optimized['id'],
						));
					}
					$base_dir = $base_ims_dir;
				}
			}
			// Check through all the sizes we've processed so far.
			foreach ( $processed as $proc => $scan ) {
				// If a previous resize had identical dimensions.
				if ( $scan['height'] == $data['height'] && $scan['width'] == $data['width'] ) {
					// We found a duplicate resize, so...
					// Point this resize at the same image as the previous one.
					$meta['sizes'][ $size ]['file'] = $meta['sizes'][ $proc ]['file'];
					continue( 2 );
				}
			}
			// If this is a unique size.
			$resize_path = $base_dir . $data['file'];
			if ( 'application/pdf' == $type && 'full' == $size ) {
				$size = 'pdf-full';
				ewwwio_debug_message( 'processing full size pdf preview' );
			}
			// Run the optimization and store the results.
			if ( $parallel_opt && is_file( $resize_path ) ) {
				$parallel_sizes[ $size ] = $resize_path;
			} else {
				$ewww_image = new EWWW_Image( $id, 'media', $resize_path );
				$ewww_image->resize = $size;
				list( $optimized_file, $results, $resize_conv, $original ) = ewww_image_optimizer( $resize_path );
			}
			// Optimize retina images, if they exist.
			if ( function_exists( 'wr2x_get_retina' ) ) {
				$retina_path = wr2x_get_retina( $resize_path );
			} else {
				$retina_path = false;
			}
			if ( $retina_path && is_file( $retina_path ) ) {
				if ( $parallel_opt ) {
					$async_path = str_replace( $upload_path, '', $retina_path );
					$ewwwio_async_optimize_media->data(
						array(
							'ewwwio_id' => $id,
							'ewwwio_path' => $async_path,
							'ewwwio_size' => '',
							'ewww_force' => $force,
						)
					)->dispatch();
				} else {
					$ewww_image = new EWWW_Image( $id, 'media', $retina_path );
					$ewww_image->resize = $size . '-retina';
					ewww_image_optimizer( $retina_path );
				}
			} elseif ( ! $parallel_opt ) {
				ewww_image_optimizer_hidpi_optimize( $resize_path );
			}
			// Store info on the sizes we've processed, so we can check the list for duplicate sizes.
			$processed[ $size ]['width'] = $data['width'];
			$processed[ $size ]['height'] = $data['height'];
		} // End foreach().
	} // End if().

	// Process size from a custom theme.
	if ( isset( $meta['image_meta']['resized_images'] ) && ewww_image_optimizer_iterable( $meta['image_meta']['resized_images'] ) ) {
		$imagemeta_resize_pathinfo = pathinfo( $file_path );
		$imagemeta_resize_path = '';
		foreach ( $meta['image_meta']['resized_images'] as $imagemeta_resize ) {
			$imagemeta_resize_path = $imagemeta_resize_pathinfo['dirname'] . '/' . $imagemeta_resize_pathinfo['filename'] . '-' . $imagemeta_resize . '.' . $imagemeta_resize_pathinfo['extension'];
			if ( $parallel_opt && is_file( $imagemeta_resize_path ) ) {
				$async_path = str_replace( $upload_path, '', $imagemeta_resize_path );
				$ewwwio_async_optimize_media->data(
					array(
						'ewwwio_id' => $id,
						'ewwwio_path' => $async_path,
						'ewwwio_size' => '',
						'ewww_force' => $force,
					)
				)->dispatch();
			} else {
				$ewww_image = new EWWW_Image( $id, 'media', $imagemeta_resize_path );
				ewww_image_optimizer( $imagemeta_resize_path );
			}
		}
	}

	// And another custom theme.
	if ( isset( $meta['custom_sizes'] ) && ewww_image_optimizer_iterable( $meta['custom_sizes'] ) ) {
		$custom_sizes_pathinfo = pathinfo( $file_path );
		$custom_size_path = '';
		foreach ( $meta['custom_sizes'] as $custom_size ) {
			$custom_size_path = $custom_sizes_pathinfo['dirname'] . '/' . $custom_size['file'];
			if ( $parallel_opt && file_exists( $custom_size_path ) ) {
				$async_path = str_replace( $upload_path, '', $custom_size_path );
				$ewwwio_async_optimize_media->data(
					array(
						'ewwwio_id' => $id,
						'ewwwio_path' => $async_path,
						'ewwwio_size' => '',
						'ewww_force' => $force,
					)
				)->dispatch();
			} else {
				$ewww_image = new EWWW_Image( $id, 'media', $custom_size_path );
				ewww_image_optimizer( $custom_size_path );
			}
		}
	}

	if ( $parallel_opt && count( $parallel_sizes ) > 0 ) {
		$max_threads = (int) apply_filters( 'ewww_image_optimizer_max_parallel_threads', 5 );
		$processing = true;
		$timer = (int) apply_filters( 'ewww_image_optimizer_background_timer_init', 1 );
		$increment = (int) apply_filters( 'ewww_image_optimizer_background_timer_increment', 1 );
		$timer_max = (int) apply_filters( 'ewww_image_optimizer_background_timer_max', 20 );
		$processing_sizes = array();
		if ( ! empty( $_REQUEST['ewww_force'] ) ) {
			$force = true;
		} else {
			$force = false;
		}
		global $ewwwio_async_optimize_media;
		if ( ! class_exists( 'WP_Background_Process' ) ) {
			require_once( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'background.php' );
		}
		if ( ! is_object( $ewwwio_async_optimize_media ) ) {
			$ewwwio_async_optimize_media = new EWWWIO_Async_Request();
		}
		while ( $parallel_opt && count( $parallel_sizes ) > 0 ) {
			$threads = $max_threads;
			ewwwio_debug_message( 'sizes left to queue: ' . count( $parallel_sizes ) );
			// Phase 1, add $max_threads items to the queue and dispatch.
			foreach ( $parallel_sizes as $size => $filename ) {
				if ( $threads < 1 ) {
					continue;
				}
				if ( ! file_exists( $filename ) ) {
					unset( $parallel_sizes[ $size ] );
					continue;
				}
				ewwwio_debug_message( "queueing size $size - $filename" );
				$processing_sizes[ $size ] = $filename;
				unset( $parallel_sizes[ $size ] );
				touch( $filename . '.processing' );
				$async_path = str_replace( $upload_path, '', $filename );
				ewwwio_debug_message( "sending off $async_path in folder $upload_path" );
				$ewwwio_async_optimize_media->data(
					array(
						'ewwwio_id' => $id,
						'ewwwio_path' => $async_path,
						'ewwwio_size' => $size,
						'ewww_force' => $force,
					)
				)->dispatch();
				$threads--;
				ewwwio_debug_message( 'sizes left to queue: ' . count( $parallel_sizes ) );
				$processing = true;
			}
			// In phase 2, we start checking to see what sizes are done, until they all finish.
			while ( $parallel_opt && $processing ) {
				$processing = false;
				foreach ( $processing_sizes as $size => $filename ) {
					if ( is_file( $filename . '.processing' ) ) {
						ewwwio_debug_message( "still processing $size" );
						$processing = true;
						continue;
					}
					$image = ewww_image_optimizer_find_already_optimized( $filename );
					unset( $processing_sizes[ $size ] );
					ewwwio_debug_message( "got results for $size size" );
				}
				if ( $processing ) {
					ewwwio_debug_message( "sleeping for $timer seconds" );
					sleep( $timer );
					$timer += $increment;
					clearstatcache();
				}
				if ( $timer > $timer_max ) {
					break;
				}
				if ( $log ) {
					ewww_image_optimizer_debug_log();
				}
			}
			if ( $timer > $timer_max ) {
				foreach ( $processing_sizes as $filename ) {
					if ( is_file( $filename . '.processing' ) ) {
						unlink( $filename . '.processing' );
					}
				}
				$meta['processing'] = 1;
				if ( $log ) {
					ewww_image_optimizer_debug_log();
				}
				return $meta;
			}
			if ( $log ) {
				ewww_image_optimizer_debug_log();
			}
		} // End while().
	} // End if().
	unset( $meta['processing'] );

	global $ewww_attachment;
	$ewww_attachment['id'] = $id;
	$ewww_attachment['meta'] = $meta;
	add_filter( 'w3tc_cdn_update_attachment_metadata', 'ewww_image_optimizer_w3tc_update_files' );

	// In case we used parallel opt, $file might not be set.
	if ( empty( $file ) ) {
		$file = $file_path;
	}
	$fullsize_opt_size = ewww_image_optimizer_filesize( $file );
	if ( $fullsize_opt_size && $fullsize_opt_size < $fullsize_size && class_exists( 'Amazon_S3_And_CloudFront' ) ) {
		global $as3cf;
		if ( method_exists( $as3cf, 'wp_update_attachment_metadata' ) ) {
			ewwwio_debug_message( 'deferring to normal S3 hook' );
		} elseif ( method_exists( $as3cf, 'wp_generate_attachment_metadata' ) ) {
			$as3cf->wp_generate_attachment_metadata( $meta, $id );
			ewwwio_debug_message( 'uploading to Amazon S3' );
		}
	}
	if ( $fullsize_opt_size && $fullsize_opt_size < $fullsize_size && class_exists( 'DreamSpeed_Services' ) ) {
		global $dreamspeed;
		$dreamspeed->wp_generate_attachment_metadata( $meta, $id );
		ewwwio_debug_message( 'uploading to Dreamspeed' );
	}
	if ( class_exists( 'Cloudinary' ) && Cloudinary::config_get( 'api_secret' ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_enable_cloudinary' ) && ! empty( $new_image ) ) {
		try {
			$result = CloudinaryUploader::upload( $file, array(
				'use_filename' => true,
			) );
		} catch ( Exception $e ) {
			$error = $e->getMessage();
		}
		if ( ! empty( $error ) ) {
			ewwwio_debug_message( "Cloudinary error: $error" );
		} else {
			ewwwio_debug_message( 'successfully uploaded to Cloudinary' );
			// Register the attachment in the database as a cloudinary attachment.
			$old_url = wp_get_attachment_url( $id );
			wp_update_post( array(
				'ID' => $id,
				'guid' => $result['url'],
			) );
			update_attached_file( $id, $result['url'] );
			$meta['cloudinary'] = true;
			$errors = array();
			// Update the image location for the attachment.
			CloudinaryPlugin::update_image_src_all( $id, $result, $old_url, $result['url'], true, $errors );
			if ( count( $errors ) > 0 ) {
				ewwwio_debug_message( 'Cannot migrate the following posts:' );
				foreach ( $errors as $error ) {
					ewwwio_debug_message( $error );
				}
			}
		}
	}
	if ( $log ) {
		ewww_image_optimizer_debug_log();
	}
	ewwwio_memory( __FUNCTION__ );
	// Send back the updated metadata.
	return $meta;
}

/**
 * Check to see if Shield's location lock option is enabled.
 *
 * @return bool True if the IP location lock is enabled.
 */
function ewww_image_optimizer_detect_wpsf_location_lock() {
	if ( class_exists( 'ICWP_Wordpress_Simple_Firewall' ) ) {
		$shield_user_man = ewww_image_optimizer_get_option( 'icwp_wpsf_user_management_options' );
		if ( ewww_image_optimizer_function_exists( 'print_r' ) ) {
			ewwwio_debug_message( print_r( $shield_user_man, true ) );
		}
		if ( 'Y' == $shield_user_man['session_lock_location'] ) {
			return true;
		}
	}
	return false;
}

/**
 * Update the attachment's meta data after being converted.
 *
 * @global object $wpdb
 *
 * @param array $meta Attachment metadata.
 * @param int   $id Attachment ID number.
 */
function ewww_image_optimizer_update_attachment( $meta, $id ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	global $wpdb;
	// Update the file location in the post metadata based on the new path stored in the attachment metadata.
	update_attached_file( $id, $meta['file'] );
	$guid = wp_get_attachment_url( $id );
	if ( empty( $meta['real_orig_file'] ) ) {
		$old_guid = dirname( $guid ) . '/' . basename( $meta['orig_file'] );
	} else {
		$old_guid = dirname( $guid ) . '/' . basename( $meta['real_orig_file'] );
		unset( $meta['real_orig_file'] );
	}
	// Construct the new guid based on the filename from the attachment metadata.
	ewwwio_debug_message( "old guid: $old_guid" );
	ewwwio_debug_message( "new guid: $guid" );
	if ( substr( $old_guid, -1 ) == '/' || substr( $guid, -1 ) == '/' ) {
		ewwwio_debug_message( 'could not obtain full url for current and previous image, bailing' );
		return $meta;
	}
	// Retrieve any posts that link the image.
	$esql = $wpdb->prepare( "SELECT ID, post_content FROM $wpdb->posts WHERE post_content LIKE '%%%s%%'", $old_guid );
	ewwwio_debug_message( "using query: $esql" );
	// While there are posts to process.
	$rows = $wpdb->get_results( $esql, ARRAY_A ); // WPCS: unprepared SQL ok.
	if ( ewww_image_optimizer_iterable( $rows ) ) {
		foreach ( $rows as $row ) {
			// Replace all occurences of the old guid with the new guid.
			$post_content = str_replace( $old_guid, $guid, $row['post_content'] );
			ewwwio_debug_message( "replacing $old_guid with $guid in post " . $row['ID'] );
			// Send the updated content back to the database.
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_content' => $post_content,
				),
				array(
					'ID' => $row['ID'],
				)
			);
		}
	}
	if ( isset( $meta['sizes'] ) && ewww_image_optimizer_iterable( $meta['sizes'] ) ) {
		// For each resized version.
		foreach ( $meta['sizes'] as $size => $data ) {
			// If the resize was converted.
			if ( isset( $data['converted'] ) ) {
				// Generate the url for the old image.
				if ( empty( $data['real_orig_file'] ) ) {
					$old_sguid = dirname( $old_guid ) . '/' . basename( $data['orig_file'] );
				} else {
					$old_sguid = dirname( $old_guid ) . '/' . basename( $data['real_orig_file'] );
					unset( $meta['sizes'][ $size ]['real_orig_file'] );
				}
				ewwwio_debug_message( "processing: $size" );
				ewwwio_debug_message( "old sguid: $old_sguid" );
				// Generate the url for the new image.
				$sguid = dirname( $old_guid ) . '/' . basename( $data['file'] );
				ewwwio_debug_message( "new sguid: $sguid" );
				if ( substr( $old_sguid, -1 ) == '/' || substr( $sguid, -1 ) == '/' ) {
					ewwwio_debug_message( 'could not obtain full url for current and previous resized image, bailing' );
					continue;
				}
				// Retrieve any posts that link the resize.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM $wpdb->posts WHERE post_content LIKE '%%%s%%'", $old_sguid ), ARRAY_A );
				// While there are posts to process.
				if ( ewww_image_optimizer_iterable( $rows ) ) {
					foreach ( $rows as $row ) {
						// Replace all occurences of the old guid with the new guid.
						$post_content = str_replace( $old_sguid, $sguid, $row['post_content'] );
						ewwwio_debug_message( "replacing $old_sguid with $sguid in post " . $row['ID'] );
						// Send the updated content back to the database.
						$wpdb->update(
							$wpdb->posts,
							array(
								'post_content' => $post_content,
							),
							array(
								'ID' => $row['ID'],
							)
						);
					}
				}
			} // End if().
		} // End foreach().
	} // End if().
	if ( preg_match( '/.jpg$/i', basename( $meta['file'] ) ) ) {
		$mime = 'image/jpeg';
	}
	if ( preg_match( '/.png$/i', basename( $meta['file'] ) ) ) {
		$mime = 'image/png';
	}
	if ( preg_match( '/.gif$/i', basename( $meta['file'] ) ) ) {
		$mime = 'image/gif';
	}
	// Update the attachment post with the new mimetype and id.
	wp_update_post( array(
		'ID' => $id,
		'post_mime_type' => $mime,
		)
	);
	ewww_image_optimizer_debug_log();
	ewwwio_memory( __FUNCTION__ );
	return $meta;
}

/**
 * Retrieves the path of an attachment via the $id and the $meta.
 *
 * @param array  $meta The attachment metadata.
 * @param int    $id The attachment ID number.
 * @param string $file Optional. Path relative to the uploads folder. Default ''.
 * @param bool   $refresh_cache Optional. True to flush cache prior to fetching path. Default true.
 * @return array {
 *	Information about the file.
 *
 *	@type string The full path to the image.
 *	@type string The path to the uploads folder.
 * }
 */
function ewww_image_optimizer_attachment_path( $meta, $id, $file = '', $refresh_cache = true ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );

	// Retrieve the location of the wordpress upload folder.
	$upload_dir = wp_upload_dir( null, false, $refresh_cache );
	$upload_path = trailingslashit( $upload_dir['basedir'] );
	if ( ! $file ) {
		$file = get_post_meta( $id, '_wp_attached_file', true );
	} else {
		ewwwio_debug_message( 'using prefetched _wp_attached_file' );
	}
	$file_path = ( 0 !== strpos( $file, '/' ) && ! preg_match( '|^.:\\\|', $file ) ? $upload_path . $file : $file );
	$filtered_file_path = apply_filters( 'get_attached_file', $file_path, $id );
	ewwwio_debug_message( "WP (filtered) thinks the file is at: $filtered_file_path" );
	if ( ( strpos( $filtered_file_path, 's3' ) === false || in_array( 's3', stream_get_wrappers() ) ) && is_file( $filtered_file_path ) ) {
		return array( str_replace( '//_imsgalleries/', '/_imsgalleries/', $filtered_file_path ), $upload_path );
	}
	ewwwio_debug_message( "WP (unfiltered) thinks the file is at: $file_path" );
	if ( ( strpos( $file_path, 's3' ) === false || in_array( 's3', stream_get_wrappers() ) ) && is_file( $file_path ) ) {
		return array( str_replace( '//_imsgalleries/', '/_imsgalleries/', $file_path ), $upload_path );
	}
	if ( 'ims_image' == get_post_type( $id ) && is_array( $meta ) && ! empty( $meta['file'] ) ) {
		ewwwio_debug_message( "finding path for IMS image: $id " );
		if ( is_dir( $file_path ) && is_file( $file_path . $meta['file'] ) ) {
			// Generate the absolute path.
			$file_path = $file_path . $meta['file'];
			$upload_path = ewww_image_optimizer_upload_path( $file_path, $upload_path );
			ewwwio_debug_message( "found path for IMS image: $file_path" );
		} elseif ( is_file( $meta['file'] ) ) {
			$file_path = $meta['file'];
			$upload_path = ewww_image_optimizer_upload_path( $file_path, $upload_path );
			ewwwio_debug_message( "found path for IMS image: $file_path" );
		} else {
			$upload_path = trailingslashit( WP_CONTENT_DIR );
			$file_path = $upload_path . ltrim( $meta['file'], '/' );
			ewwwio_debug_message( "checking path for IMS image: $file_path" );
			if ( ! file_exists( $file_path ) ) {
				$file_path = '';
			}
		}
		return array( $file_path, $upload_path );
	}
	if ( is_array( $meta ) && ! empty( $meta['file'] ) ) {
		$file_path = $meta['file'];
		if ( strpos( $file_path, 's3' ) === 0 && ! in_array( 's3', stream_get_wrappers() ) ) {
			return array( '', $upload_path );
		}
		ewwwio_debug_message( "looking for file at $file_path" );
		if ( is_file( $file_path ) ) {
			return array( $file_path, $upload_path );
		}
		$file_path = trailingslashit( $upload_path ) . $file_path;
		ewwwio_debug_message( "that did not work, try it with the upload_dir: $file_path" );
		if ( is_file( $file_path ) ) {
			return array( $file_path, $upload_path );
		}
		$upload_path = trailingslashit( WP_CONTENT_DIR ) . 'uploads/';
		$file_path = $upload_path . $meta['file'];
		ewwwio_debug_message( "one last shot, using the wp-content/ constant: $file_path" );
		if ( is_file( $file_path ) ) {
			return array( $file_path, $upload_path );
		}
	}
	ewwwio_memory( __FUNCTION__ );
	return array( '', $upload_path );
}

/**
 * Removes parent folders to create a relative path.
 *
 * Replaces either ABSPATH, WP_CONTENT_DIR, or EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER with the literal
 * string name of the applicable constant. For example: /var/www/wp-content/uploads/test.jpg becomes
 * ABSPATHwp-content/uploads/test.jpg.
 *
 * @param string $file The filename to mangle.
 * @return string The filename with parent folders replaced by a constant name.
 */
function ewww_image_optimizer_relative_path_remove( $file ) {
	if ( ! defined( 'EWWW_IMAGE_OPTIMIZER_RELATIVE' ) || ! EWWW_IMAGE_OPTIMIZER_RELATIVE ) {
		return $file;
	}
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( defined( 'EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER' ) && EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER && strpos( $file, EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER ) === 0 ) {
		ewwwio_debug_message( "removing custom relative folder from $file" );
		return str_replace( EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER, 'EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER', $file );
	}
	if ( strpos( $file, ABSPATH ) === 0 ) {
		ewwwio_debug_message( "removing ABSPATH from $file" );
		return str_replace( ABSPATH, 'ABSPATH', $file );
	}
	if ( defined( 'WP_CONTENT_DIR' ) && WP_CONTENT_DIR && strpos( $file, WP_CONTENT_DIR ) === 0 ) {
		ewwwio_debug_message( "removing WP_CONTENT_DIR from $file" );
		return str_replace( WP_CONTENT_DIR, 'WP_CONTENT_DIR', $file );
	}
	return $file;
}

/**
 * Replaces constant names with their actual values to recreate an absolute path.
 *
 * Replaces the literal strings 'ABSPATH', 'WP_CONTENT_DIR', or
 * 'EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER' with the actual value of the constant contained within
 * the file path.string name of the applicable constant.
 *
 * @param string $file The filename to parse.
 * @return string The full filename with parent folders reinserted.
 */
function ewww_image_optimizer_relative_path_replace( $file ) {
	if ( ! defined( 'EWWW_IMAGE_OPTIMIZER_RELATIVE' ) ) {
		return $file;
	}
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( defined( 'EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER' ) && EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER && strpos( $file, 'EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER' ) === 0 ) {
		ewwwio_debug_message( "replacing custom relative folder in $file" );
		return str_replace( 'EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER', EWWW_IMAGE_OPTIMIZER_RELATIVE_FOLDER, $file );
	}
	if ( strpos( $file, 'ABSPATH' ) === 0 ) {
		ewwwio_debug_message( "replacing ABSPATH in $file" );
		return str_replace( 'ABSPATH', ABSPATH, $file );
	}
	if ( defined( 'WP_CONTENT_DIR' ) && WP_CONTENT_DIR && strpos( $file, 'WP_CONTENT_DIR' ) === 0 ) {
		ewwwio_debug_message( "replacing WP_CONTENT_DIR in $file" );
		return str_replace( 'WP_CONTENT_DIR', WP_CONTENT_DIR, $file );
	}
	return $file;
}

/**
 * Takes a file and upload folder, and makes sure that the file is within the folder.
 *
 * Used for path replacement with async/parallel processing, since security plugins can block
 * POSTing of full paths.
 *
 * @param string $file Name of the file.
 * @param string $upload_path Location of the upload directory.
 * @return string The upload path or an empty string if the file is outside the uploads folder.
 */
function ewww_image_optimizer_upload_path( $file, $upload_path ) {
	if ( strpos( $file, $upload_path ) === 0 ) {
		return $upload_path;
	} else {
		return '';
	}
}

/**
 * Takes a human-readable size, and generates an approximate byte-size.
 *
 * @param string $formatted A human-readable file size.
 * @return int The approximated filesize.
 */
function ewww_image_optimizer_size_unformat( $formatted ) {
	$size_parts = explode( '&nbsp;', $formatted );
	switch ( $size_parts[1] ) {
		case 'B':
			return intval( $size_parts[0] );
		case 'kB':
			return intval( $size_parts[0] * 1024 );
		case 'MB':
			return intval( $size_parts[0] * 1048576 );
		case 'GB':
			return intval( $size_parts[0] * 1073741824 );
		case 'TB':
			return intval( $size_parts[0] * 1099511627776 );
		default:
			return 0;
	}
}

/**
 * Generate a unique filename for a converted image.
 *
 * @param string $file The filename to test for uniqueness.
 * @param string $fileext An iterator to append to the base filename, starts empty usually.
 * @return array {
 *	Filename information.
 *
 *	@type string A unique filename for converting an image.
 *	@type int|string The iterator used for uniqueness.
 * }
 */
function ewww_image_optimizer_unique_filename( $file, $fileext ) {
	// Strip the file extension.
	$filename = preg_replace( '/\.\w+$/', '', $file );
	if ( ! is_file( $filename . $fileext ) ) {
		return array( $filename . $fileext, '' );
	}
	// Set the increment to 1 (but allow the user to override it).
	$filenum = apply_filters( 'ewww_image_optimizer_converted_filename_suffix', 1 );
	// But it must be only letters, numbers, or underscores.
	$filenum = ( preg_match( '/^[\w\d]*$/', $filenum ) ? $filenum : 1 );
	$suffix = ( ! empty( $filenum ) ? '-' . $filenum : '' );
	// While a file exists with the current increment.
	while ( file_exists( $filename . $suffix . $fileext ) ) {
		// Increment the increment...
		$filenum++;
		$suffix = '-' . $filenum;
	}
	// All done, let's reconstruct the filename.
	ewwwio_memory( __FUNCTION__ );
	return array( $filename . $suffix . $fileext, $filenum );
}

/**
 * Get mimetype based on file extension instead of file contents when speed outweighs accuracy.
 *
 * @param string $path The name of the file.
 * @return string|bool The mime type based on the extension or false.
 */
function ewww_image_optimizer_quick_mimetype( $path ) {
	$pathextension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	switch ( $pathextension ) {
		case 'jpg':
		case 'jpeg':
		case 'jpe':
			return 'image/jpeg';
		case 'png':
			return 'image/png';
		case 'gif':
			return 'image/gif';
		case 'pdf':
			return 'application/pdf';
		default:
			return false;
	}
}

/**
 * Check a PNG to see if it has transparency.
 *
 * @param string $filename The name of the PNG file.
 * @return bool True if transparency is found.
 */
function ewww_image_optimizer_png_alpha( $filename ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( ! is_file( $filename ) ) {
		return false;
	}
	// Determine what color type is stored in the file.
	$color_type = ord( file_get_contents( $filename, null, null, 25, 1 ) );
	ewwwio_debug_message( "color type: $color_type" );
	// If it is set to RGB alpha or Grayscale alpha.
	if ( 4 == $color_type || 6 == $color_type ) {
		ewwwio_debug_message( 'transparency found' );
		return true;
	} elseif ( 3 == $color_type && ewww_image_optimizer_gd_support() ) {
		$image = imagecreatefrompng( $filename );
		if ( imagecolortransparent( $image ) >= 0 ) {
			ewwwio_debug_message( 'transparency found' );
			return true;
		}
		list( $width, $height ) = getimagesize( $filename );
		ewwwio_debug_message( "image dimensions: $width x $height" );
		ewwwio_debug_message( 'preparing to scan image' );
		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				$color = imagecolorat( $image, $x, $y );
				$rgb = imagecolorsforindex( $image, $color );
				if ( $rgb['alpha'] > 0 ) {
					ewwwio_debug_message( 'transparency found' );
					return true;
				}
			}
		}
	}
	ewwwio_debug_message( 'no transparency' );
	ewwwio_memory( __FUNCTION__ );
	return false;
}

/**
 * Check the submitted GIF to see if it is animated
 *
 * @param string $filename Name of the GIF to test for animation.
 * @return bool True if animation found.
 */
function ewww_image_optimizer_is_animated( $filename ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( ! is_file( $filename ) ) {
		return false;
	}
	// If we can't open the file in read-only buffered mode.
	$fh = fopen( $filename, 'rb' );
	if ( ! $fh ) {
		return false;
	}
	$count = 0;
	// We read through the file til we reach the end of the file, or we've found at least 2 frame headers.
	while ( ! feof( $fh ) && $count < 2 ) {
		$chunk = fread( $fh, 1024 * 100 ); // Read 100kb at a time.
		$count += preg_match_all( '#\x00\x21\xF9\x04.{4}\x00(\x2C|\x21)#s', $chunk, $matches );
	}
	fclose( $fh );
	ewwwio_debug_message( "scanned GIF and found $count frames" );
	ewwwio_memory( __FUNCTION__ );
	return $count > 1;
}

/**
 * Count how many sizes are in the metadata, accounting for those with duplicate dimensions.
 *
 * @param array $sizes A list of resize information from an attachment.
 * @return int The number of sizes found.
 */
function ewww_image_optimizer_resize_count( $sizes ) {
	if ( empty( $sizes ) || ! is_array( $sizes ) ) {
		return 0;
	}
	$size_count = 0;
	$processed = array();
	foreach ( $sizes as $size => $data ) {
		if ( strpos( $size, 'webp' ) === 0 ) {
			continue;
		}
		if ( empty( $data['file'] ) ) {
			continue;
		}
		// Check through all the sizes we've processed so far.
		foreach ( $processed as $proc => $scan ) {
			// If a previous resize had identical dimensions.
			if ( $scan['height'] == $data['height'] && $scan['width'] == $data['width'] ) {
				continue( 2 );
			}
		}
		// If this is a unique size.
		$size_count++;
		// Sore info on the sizes we've processed, so we can check the list for duplicate sizes.
		$processed[ $size ]['width'] = $data['width'];
		$processed[ $size ]['height'] = $data['height'];
	}
	return $size_count;
}

/**
 * Add column header for optimizer results in the media library listing.
 *
 * @param array $defaults A list of columns in the media library.
 * @return array The new list of columns.
 */
function ewww_image_optimizer_columns( $defaults ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	$defaults['ewww-image-optimizer'] = esc_html__( 'Image Optimizer', 'ewww-image-optimizer' );
	ewwwio_memory( __FUNCTION__ );
	return $defaults;
}

/**
 * Print column data for optimizer results in the media library.
 *
 * @global object $wpdb
 *
 * @param string $column_name The name of the column being displayed.
 * @param int    $id The attachment ID number.
 * @param array  $meta Optional. The attachment metadata. Default null.
 * @param bool   $return_output Optional. True if output should be returned instead of output.
 * @return string If $return_output, the data that would normally be output directly.
 */
function ewww_image_optimizer_custom_column( $column_name, $id, $meta = null, $return_output = false ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	// Once we get to the EWWW IO custom column.
	if ( 'ewww-image-optimizer' == $column_name ) {
		$output = '';
		if ( null == $meta ) {
			// Retrieve the metadata.
			$meta = wp_get_attachment_metadata( $id );
		}
		if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_debug' ) && ! $return_output && ewww_image_optimizer_function_exists( 'print_r' ) ) {
			$print_meta = print_r( $meta, true );
			$print_meta = preg_replace( array( '/ /', '/\n+/' ), array( '&nbsp;', '<br />' ), $print_meta );
			$output .= '<div style="background-color:#ffff99;font-size: 10px;padding: 10px;margin:-10px -10px 10px;line-height: 1.1em">' . $print_meta . '</div>';
		}
		$output .= "<div id='ewww-media-status-$id'>";
		$ewww_cdn = false;
		if ( is_array( $meta ) && ! empty( $meta['cloudinary'] ) ) {
			$output .= esc_html__( 'Cloudinary image', 'ewww-image-optimizer' ) . '</div>';
			if ( $return_output ) {
				return $output;
			}
			echo $output;
			return;
		}
		if ( is_array( $meta ) & class_exists( 'WindowsAzureStorageUtil' ) && ! empty( $meta['url'] ) ) {
			$output .= '<div>' . esc_html__( 'Azure Storage image', 'ewww-image-optimizer' ) . '</div>';
			$ewww_cdn = true;
		}
		if ( is_array( $meta ) && class_exists( 'Amazon_S3_And_CloudFront' ) && preg_match( '/^(http|s3)\w*:/', get_attached_file( $id ) ) ) {
			$output .= '<div>' . esc_html__( 'Amazon S3 image', 'ewww-image-optimizer' ) . '</div>';
			$ewww_cdn = true;
		}
		if ( is_array( $meta ) && class_exists( 'S3_Uploads' ) && preg_match( '/^(http|s3)\w*:/', get_attached_file( $id ) ) ) {
			$output .= '<div>' . esc_html__( 'Amazon S3 image', 'ewww-image-optimizer' ) . '</div>';
			$ewww_cdn = true;
		}
		list( $file_path, $upload_path ) = ewww_image_optimizer_attachment_path( $meta, $id );
		// If the file does not exist.
		if ( empty( $file_path ) && ! $ewww_cdn ) {
			$output .= esc_html__( 'Could not retrieve file path.', 'ewww-image-optimizer' ) . '</div>';
			ewww_image_optimizer_debug_log();
			if ( $return_output ) {
				return $output;
			}
			echo $output;
			return;
		}
		if ( is_array( $meta ) && ( ! empty( $meta['ewww_image_optimizer'] ) || ! empty( $meta['converted'] ) ) ) {
			$meta = ewww_image_optimizer_migrate_meta_to_db( $id, $meta );
		}
		$msg = '';
		$convert_desc = '';
		$convert_link = '';
		if ( $ewww_cdn ) {
			$type = get_post_mime_type( $id );
		} else {
			// Retrieve the mimetype of the attachment.
			$type = ewww_image_optimizer_mimetype( $file_path, 'i' );
			// Get a human readable filesize.
			$file_size = ewww_image_optimizer_size_format( filesize( $file_path ) );
		}
		if ( ! defined( 'EWWW_IMAGE_OPTIMIZER_JPEGTRAN' ) ) {
			ewww_image_optimizer_tool_init();
			ewww_image_optimizer_notice_utils( 'quiet' );
		}
		$skip = ewww_image_optimizer_skip_tools();
		// Run the appropriate code based on the mimetype.
		switch ( $type ) {
			case 'image/jpeg':
				// If jpegtran is missing and should not be skipped.
				if ( ! EWWW_IMAGE_OPTIMIZER_JPEGTRAN && ! $skip['jpegtran'] ) {
					$msg = '<div>' . sprintf(
						/* translators: %s: name of a tool like jpegtran */
						esc_html__( '%s is missing', 'ewww-image-optimizer' ),
						'<em>jpegtran</em>'
					) . '</div>';
				} elseif ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ) == 0 ) {
					$msg = '<div>' . sprintf(
						/* translators: %s: JPG, PNG, GIF, or PDF */
						esc_html__( '%s compression disabled', 'ewww-image-optimizer' ),
						'JPG'
					) . '</div>';
				} else {
					$convert_link = esc_html__( 'JPG to PNG', 'ewww-image-optimizer' );
					$convert_desc = esc_attr__( 'WARNING: Removes metadata. Requires GD or ImageMagick. PNG is generally much better than JPG for logos and other images with a limited range of colors.', 'ewww-image-optimizer' );
				}
				break;
			case 'image/png':
				// If pngout and optipng are missing and should not be skipped.
				if ( ! EWWW_IMAGE_OPTIMIZER_PNGOUT && ! EWWW_IMAGE_OPTIMIZER_OPTIPNG && ! $skip['optipng'] && ! $skip['pngout'] ) {
					$msg = '<div>' . sprintf(
						/* translators: %s: name of a tool like jpegtran */
						esc_html__( '%s is missing', 'ewww-image-optimizer' ),
						'<em>optipng/pngout</em>'
					) . '</div>';
				} else {
					$convert_link = esc_html__( 'PNG to JPG', 'ewww-image-optimizer' );
					$convert_desc = esc_attr__( 'WARNING: This is not a lossless conversion and requires GD or ImageMagick. JPG is much better than PNG for photographic use because it compresses the image and discards data. Transparent images will only be converted if a background color has been set.', 'ewww-image-optimizer' );
				}
				break;
			case 'image/gif':
				// If gifsicle is missing and should not be skipped.
				if ( ! EWWW_IMAGE_OPTIMIZER_GIFSICLE && ! $skip['gifsicle'] ) {
					$msg = '<div>' . sprintf(
						/* translators: %s: name of a tool like jpegtran */
						esc_html__( '%s is missing', 'ewww-image-optimizer' ),
						'<em>gifsicle</em>'
					) . '</div>';
				} else {
					$convert_link = esc_html__( 'GIF to PNG', 'ewww-image-optimizer' );
					$convert_desc = esc_attr__( 'PNG is generally better than GIF, but does not support animation. Animated images will not be converted.', 'ewww-image-optimizer' );
				}
				break;
			case 'application/pdf':
				if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_pdf_level' ) == 0 ) {
					$msg = '<div>' . sprintf(
						/* translators: %s: JPG, PNG, GIF, or PDF */
						esc_html__( '%s compression disabled', 'ewww-image-optimizer' ),
						'PDF'
					) . '</div>';
				} else {
					$convert_desc = '';
				}
				break;
			default:
				// Not a supported mimetype.
				$msg = '<div>' . esc_html__( 'Unsupported file type', 'ewww-image-optimizer' ) . '</div>';
				ewww_image_optimizer_debug_log();
		} // End switch().
		if ( ! empty( $msg ) ) {
			if ( $return_output ) {
				return $msg;
			}
			echo $msg;
			return;
		}
		$ewww_manual_nonce = wp_create_nonce( 'ewww-manual' );
		global $wpdb;
		$in_progress = false;
		$migrated = false;
		$optimized_images = false;
		$backup_available = false;
		$file_parts = pathinfo( $file_path );
		$basename = $file_parts['filename'];
		// If necessary, use get_post_meta( $post_id, '_wp_attachment_backup_sizes', true ); to only use basename for edited attachments, but that requires extra queries, so kiss for now.
		if ( $ewww_cdn ) {
			if ( get_transient( 'ewwwio-background-in-progress-' . $id ) ) {
				$output .= '<div>' . esc_html__( 'In Progress', 'ewww-image-optimizer' ) . '</div>';
				$in_progress = true;
			}
			if ( ! $in_progress ) {
				$optimized_images = $wpdb->get_results( $wpdb->prepare( "SELECT image_size,orig_size,resize,converted,level,backup,updated FROM $wpdb->ewwwio_images WHERE attachment_id = %d AND gallery = 'media' AND image_size <> 0 AND path LIKE '%%%s%%' ORDER BY orig_size DESC", $id, $basename ), ARRAY_A );
				if ( ! $optimized_images ) {
					// Attempt migration, but only if the original image is in the db, $migrated will be metadata on success, false on failure.
					$migrated = ewww_image_optimizer_migrate_meta_to_db( $id, $meta, true );
				}
				if ( $migrated ) {
					$optimized_images = $wpdb->get_results( $wpdb->prepare( "SELECT image_size,orig_size,resize,converted,level,backup,updated FROM $wpdb->ewwwio_images WHERE attachment_id = %d AND gallery = 'media' AND image_size <> 0 AND path LIKE '%%%s%%' ORDER BY orig_size DESC", $id, $basename ), ARRAY_A );
				}
			}
			// If optimizer data exists in the db.
			if ( ! empty( $optimized_images ) ) {
				list( $detail_output, $converted, $backup_available ) = ewww_image_optimizer_custom_column_results( $id, $optimized_images );
				$output .= $detail_output;
				// Output the optimizer actions.
				if ( current_user_can( apply_filters( 'ewww_image_optimizer_manual_permissions', '' ) ) ) {
					// Display a link to re-optimize manually.
					$output .= '<div>' . sprintf( "<a class='ewww-manual-optimize' data-id='$id' data-nonce='$ewww_manual_nonce' href=\"admin.php?action=ewww_image_optimizer_manual_optimize&amp;ewww_manual_nonce=$ewww_manual_nonce&amp;ewww_force=1&amp;ewww_attachment_ID=%d\">%s</a>",
						$id,
						esc_html__( 'Re-optimize', 'ewww-image-optimizer' )
					) . '</div>';
				}
				if ( $backup_available && current_user_can( apply_filters( 'ewww_image_optimizer_manual_permissions', '' ) ) ) {
					$output .= '<div>' . sprintf( "<a class='ewww-manual-cloud-restore' data-id='$id' data-nonce='$ewww_manual_nonce' href=\"admin.php?action=ewww_image_optimizer_manual_cloud_restore&amp;ewww_manual_nonce=$ewww_manual_nonce&amp;ewww_attachment_ID=%d\">%s</a>",
						$id,
						esc_html__( 'Restore original', 'ewww-image-optimizer' )
					) . '</div>';
				}
			} elseif ( ! $in_progress && current_user_can( apply_filters( 'ewww_image_optimizer_manual_permissions', '' ) ) ) {
				ewww_image_optimizer_migrate_meta_to_db( $id, $meta );
				// Give the user the option to optimize the image right now.
				if ( isset( $meta['sizes'] ) && ewww_image_optimizer_iterable( $meta['sizes'] ) ) {
					$sizes_to_opt = ewww_image_optimizer_count_unoptimized_sizes( $meta['sizes'] ) + 1;
					$output .= '<div>' . sprintf( esc_html(
						/* translators: %d: The number of resize/thumbnail images */
						_n( '%d size to compress', '%d sizes to compress', $sizes_to_opt, 'ewww-image-optimizer' )
					), $sizes_to_opt ) . '</div>';
				}
				$output .= '<div>' . sprintf( "<a class='ewww-manual-optimize' data-id='$id' data-nonce='$ewww_manual_nonce' href=\"admin.php?action=ewww_image_optimizer_manual_optimize&amp;ewww_manual_nonce=$ewww_manual_nonce&amp;ewww_attachment_ID=%d\">%s</a>", $id, esc_html__( 'Optimize now!', 'ewww-image-optimizer' ) ) . '</div>';
			}
			$output .= '</div>';
			if ( $return_output ) {
				return $output;
			}
			echo $output;
			return;
		} // End if().
		// End of output for CDN images.
		if ( get_transient( 'ewwwio-background-in-progress-' . $id ) ) {
			$output .= esc_html__( 'In Progress', 'ewww-image-optimizer' );
			$in_progress = true;
		}
		if ( ! $in_progress ) {
			$optimized_images = $wpdb->get_results( $wpdb->prepare( "SELECT image_size,orig_size,resize,converted,level,backup,updated FROM $wpdb->ewwwio_images WHERE attachment_id = %d AND gallery = 'media' AND image_size <> 0 AND path LIKE '%%%s%%' ORDER BY orig_size DESC", $id, $basename ), ARRAY_A );
			if ( ! $optimized_images ) {
				// Attempt migration, but only if the original image is in the db, $migrated will be metadata on success, false on failure.
				$migrated = ewww_image_optimizer_migrate_meta_to_db( $id, $meta, true );
			}
			if ( $migrated ) {
				$optimized_images = $wpdb->get_results( $wpdb->prepare( "SELECT image_size,orig_size,resize,converted,level,backup,updated FROM $wpdb->ewwwio_images WHERE attachment_id = %d AND gallery = 'media' AND image_size <> 0 AND path LIKE '%%%s%%' ORDER BY orig_size DESC", $id, $basename ), ARRAY_A );
			}
		}
		// If optimizer data exists.
		if ( ! empty( $optimized_images ) ) {
			list( $detail_output, $converted, $backup_available ) = ewww_image_optimizer_custom_column_results( $id, $optimized_images );
			$output .= $detail_output;

			// Link to webp upgrade script.
			$oldwebpfile = preg_replace( '/\.\w+$/', '.webp', $file_path );
			if ( file_exists( $oldwebpfile ) && current_user_can( apply_filters( 'ewww_image_optimizer_admin_permissions', '' ) ) ) {
				$output .= "<div><a href='options.php?page=ewww-image-optimizer-webp-migrate'>" . esc_html__( 'Run WebP upgrade', 'ewww-image-optimizer' ) . '</a></div>';
			}

			// Determine filepath for webp.
			$webpfile = $file_path . '.webp';
			$webp_size = ewww_image_optimizer_filesize( $webpfile );
			if ( $webp_size ) {
				// Get a human readable filesize.
				$webp_size = ewww_image_optimizer_size_format( $webp_size );
				$webpurl = esc_url( wp_get_attachment_url( $id ) . '.webp' );
				$output .= "<div>WebP: <a href='$webpurl'>$webp_size</a></div>";
			}

			if ( empty( $msg ) && current_user_can( apply_filters( 'ewww_image_optimizer_manual_permissions', '' ) ) ) {
				// Output a link to re-optimize manually.
				$output .= '<div>' . sprintf("<a class='ewww-manual-optimize' data-id='$id' data-nonce='$ewww_manual_nonce' href=\"admin.php?action=ewww_image_optimizer_manual_optimize&amp;ewww_manual_nonce=$ewww_manual_nonce&amp;ewww_force=1&amp;ewww_attachment_ID=%d\">%s</a>",
					$id,
					esc_html__( 'Re-optimize', 'ewww-image-optimizer' )
				);
				if ( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_convert_links' ) && 'ims_image' != get_post_type( $id ) && ! empty( $convert_desc ) ) {
					$output .= " | <a class='ewww-manual-convert' data-id='$id' data-nonce='$ewww_manual_nonce' title='$convert_desc' href='admin.php?action=ewww_image_optimizer_manual_optimize&amp;ewww_manual_nonce=$ewww_manual_nonce&amp;ewww_attachment_ID=$id&amp;ewww_convert=1&amp;ewww_force=1'>$convert_link</a>";
				}
				$output .= '</div>';
			} else {
				$output .= $msg;
			}
			$restorable = false;
			if ( $converted && is_file( $converted ) ) {
				$restorable = true;
			}
			if ( $restorable && current_user_can( apply_filters( 'ewww_image_optimizer_manual_permissions', '' ) ) ) {
				$output .= '<div>' . sprintf( "<a class='ewww-manual-restore' data-id='$id' data-nonce='$ewww_manual_nonce' href=\"admin.php?action=ewww_image_optimizer_manual_restore&amp;ewww_manual_nonce=$ewww_manual_nonce&amp;ewww_attachment_ID=%d\">%s</a>",
					$id,
					esc_html__( 'Restore original', 'ewww-image-optimizer' )
				) . '</div>';
			} elseif ( $backup_available && current_user_can( apply_filters( 'ewww_image_optimizer_manual_permissions', '' ) ) ) {
				$output .= '<div>' . sprintf( "<a class='ewww-manual-cloud-restore' data-id='$id' data-nonce='$ewww_manual_nonce' href=\"admin.php?action=ewww_image_optimizer_manual_cloud_restore&amp;ewww_manual_nonce=$ewww_manual_nonce&amp;ewww_attachment_ID=%d\">%s</a>",
					$id,
					esc_html__( 'Restore original', 'ewww-image-optimizer' )
				) . '</div>';
			}
		} elseif ( ! $in_progress ) {
			// Otherwise, this must be an image we haven't processed.
			if ( isset( $meta['sizes'] ) && ewww_image_optimizer_iterable( $meta['sizes'] ) ) {
				$sizes_to_opt = ewww_image_optimizer_count_unoptimized_sizes( $meta['sizes'] ) + 1;
				$output .= '<div>' . sprintf( esc_html(
					/* translators: %d: The number of resize/thumbnail images */
					_n( '%d size to compress', '%d sizes to compress', $sizes_to_opt, 'ewww-image-optimizer' )
				), $sizes_to_opt ) . '</div>';
			} else {
				$output .= '<div>' . esc_html__( 'Not processed', 'ewww-image-optimizer' ) . '</div>';
			}
			// Tell them the filesize.
			$output .= '<div>' . sprintf(
				/* translators: %s: size of the image */
				esc_html__( 'Image Size: %s', 'ewww-image-optimizer' ),
				$file_size
			) . '</div>';
			if ( empty( $msg ) && current_user_can( apply_filters( 'ewww_image_optimizer_manual_permissions', '' ) ) ) {
				// Give the user the option to optimize the image right now.
				$output .= sprintf( "<div><a class='ewww-manual-optimize' data-id='$id' data-nonce='$ewww_manual_nonce' href=\"admin.php?action=ewww_image_optimizer_manual_optimize&amp;ewww_manual_nonce=$ewww_manual_nonce&amp;ewww_attachment_ID=%d\">%s</a>", $id, esc_html__( 'Optimize now!', 'ewww-image-optimizer' ) );
				if ( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_convert_links' ) && 'ims_image' != get_post_type( $id ) && ! empty( $convert_desc ) ) {
					$output .= " | <a class='ewww-manual-convert' data-id='$id' data-nonce='$ewww_manual_nonce' title='$convert_desc' href='admin.php?action=ewww_image_optimizer_manual_optimize&amp;ewww_manual_nonce=$ewww_manual_nonce&amp;ewww_attachment_ID=$id&amp;ewww_convert=1&amp;ewww_force=1'>$convert_link</a>";
				}
				$output .= '</div>';
			} else {
				$output .= $msg;
			}
		} // End if().
		$output .= '</div>';
		if ( $return_output ) {
			ewww_image_optimizer_debug_log();
			return $output;
		}
		echo $output;
	} // End if().
	ewwwio_memory( __FUNCTION__ );
	ewww_image_optimizer_debug_log();
}

/**
 * Determine how many sizes need optimization.
 *
 * @param array $sizes The 'sizes' portion of the attachment metadata.
 * @return int The number of unoptimized sizes.
 */
function ewww_image_optimizer_count_unoptimized_sizes( $sizes ) {
	if ( ! ewww_image_optimizer_iterable( $sizes ) ) {
		ewwwio_debug_message( 'unoptimized sizes cannot be counted' );
		return 0;
	}
	$sizes_to_opt = 0;
	$disabled_sizes = get_option( 'ewww_image_optimizer_disable_resizes_opt' );

	// To keep track of the ones we have already processed.
	$processed = array();
	foreach ( $sizes as $size => $data ) {
		ewwwio_debug_message( "checking for size: $size" );
		ewww_image_optimizer_debug_log();
		if ( strpos( $size, 'webp' ) === 0 ) {
			continue;
		}
		if ( ! empty( $disabled_sizes[ $size ] ) ) {
			continue;
		}
		if ( ! empty( $disabled_sizes['pdf-full'] ) && 'full' == $size ) {
			continue;
		}
		if ( empty( $data['file'] ) ) {
			continue;
		}

		// Check through all the sizes we've processed so far.
		foreach ( $processed as $proc => $scan ) {
			// If a previous resize had identical dimensions...
			if ( $scan['height'] == $data['height'] && $scan['width'] == $data['width'] ) {
				// Found a duplicate size, get outta here!
				continue( 2 );
			}
		}
		$sizes_to_opt++;
		// Store info on the sizes we've processed, so we can check the list for duplicate sizes.
		$processed[ $size ]['width'] = $data['width'];
		$processed[ $size ]['height'] = $data['height'];
	} // End foreach().
	return $sizes_to_opt;
}

/**
 * Display cumulative image compression results with individual images displayed in a modal.
 *
 * @param int   $id The ID number of the attachment.
 * @param array $optimized_images A list of image records related to $id.
 * @return array {
 *	Information compiled from the database records.
 *
 *	@type string $output The image results plus a table of individual image results in a modal.
 *	@type string|bool $converted The original image if the attachment was converted or false.
 *	@type string|bool $backup_available The backup hash if available or false.
 * }
 */
function ewww_image_optimizer_custom_column_results( $id, $optimized_images ) {
	if ( empty( $id ) || empty( $optimized_images ) || ! is_array( $optimized_images ) ) {
		return array( '', false, false );
	}
	$orig_size = 0;
	$opt_size = 0;
	$level = 0;
	$converted = false;
	$backup_available = false;
	$sizes_to_opt = 0;
	$output = '';
	$detail_output = '<table class="striped"><tr><th>&nbsp;</th><th>' . esc_html__( 'Image Size', 'ewww-image-optimizer' ) . '</th><th>' . esc_html__( 'Savings', 'ewww-image-optimizer' ) . '</th></tr>';
	foreach ( $optimized_images as $optimized_image ) {
		if ( ! empty( $optimized_image['attachment_id'] ) ) {
			$id = $optimized_image['attachment_id'];
		}
		$orig_size += $optimized_image['orig_size'];
		$opt_size += $optimized_image['image_size'];
		if ( 'full' == $optimized_image['resize'] ) {
			$level = $optimized_image['level'];
			$updated_time = strtotime( $optimized_image['updated'] );
			if ( DAY_IN_SECONDS * 30 + $updated_time > time() ) {
				$backup_available = $optimized_image['backup'];
			} else {
				$backup_available = '';
			}
		}
		if ( ! empty( $optimized_image['converted'] ) ) {
			$converted = $optimized_image['converted'];
		}
		$sizes_to_opt++;
		if ( ! empty( $optimized_image['resize'] ) ) {
			$display_size = ewww_image_optimizer_size_format( $optimized_image['image_size'] );
			$detail_output .= '<tr><td><strong>' . ucfirst( $optimized_image['resize'] ) . "</strong></td><td>$display_size</td><td>" . esc_html( ewww_image_optimizer_image_results( $optimized_image['orig_size'], $optimized_image['image_size'] ) ) . '</td></tr>';
		}
	}
	$detail_output .= '</table>';

	$output .= '<div>' . sprintf( esc_html(
		/* translators: %d: number of resizes/thumbnails compressed */
		_n( '%d size compressed', '%d sizes compressed', $sizes_to_opt, 'ewww-image-optimizer' )
	), $sizes_to_opt );
	$output .= " <a href='#TB_inline?width=550&height=450&inlineId=ewww-attachment-detail-$id' class='thickbox'>(+)</a></div>";
	$results_msg = ewww_image_optimizer_image_results( $orig_size, $opt_size );
	// Output the optimizer results.
	$output .= '<div>' . esc_html( $results_msg ) . '</div>';
	$display_size = ewww_image_optimizer_size_format( $opt_size );
	// Output the total filesize.
	$detail_output .= '<div><strong>' . sprintf(
		/* translators: %s: human-readable file size */
		esc_html__( 'Total Size: %s', 'ewww-image-optimizer' ),
		$display_size
	) . '</strong></div>';
	$output .= "<div id='ewww-attachment-detail-$id' class='ewww-attachment-detail-container'><div class='ewww-attachment-detail'>$detail_output</div></div>";
	return array( $output, $converted, $backup_available );
}

/**
 * Removes optimization from metadata, because we store it all in the images table now.
 *
 * @param array $meta The attachment metadata.
 * @return array The attachment metadata after being cleaned.
 */
function ewww_image_optimizer_clean_meta( $meta ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( is_array( $meta ) && ! empty( $meta['ewww_image_optimizer'] ) ) {
		unset( $meta['ewww_image_optimizer'] );
	}
	if ( is_array( $meta ) && ! empty( $meta['converted'] ) ) {
		unset( $meta['converted'] );
	}
	if ( is_array( $meta ) && ! empty( $meta['orig_file'] ) ) {
		unset( $meta['orig_file'] );
	}
	if ( isset( $meta['sizes'] ) && ewww_image_optimizer_iterable( $meta['sizes'] ) ) {
		foreach ( $meta['sizes'] as $size => $data ) {
			if ( is_array( $data ) && ! empty( $data['ewww_image_optimizer'] ) ) {
				unset( $meta['sizes'][ $size ]['ewww_image_optimizer'] );
			}
			if ( is_array( $data ) && ! empty( $data['converted'] ) ) {
				unset( $meta['sizes'][ $size ]['converted'] );
			}
			if ( is_array( $data ) && ! empty( $data['orig_file'] ) ) {
				unset( $meta['sizes'][ $size ]['orig_file'] );
			}
		}
	}
	return $meta;
}

/**
 * Updates a record in the images table with information from the attachment metadata.
 *
 * @global object $wpdb
 *
 * @param string $file The name of the file to update.
 * @param string $gallery Optional. Location of the image, like 'media' or 'nextgen'. Default ''.
 * @param int    $attachment_id Optional. The ID number of the image. Default 0.
 * @param string $size Optional. The name of the image size like 'medium' or 'large'. Default ''.
 * @param string $converted Optional. The name of the original file. Default ''.
 */
function ewww_image_optimizer_update_file_from_meta( $file, $gallery = '', $attachment_id = 0, $size = '', $converted = '' ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	$already_optimized = ewww_image_optimizer_find_already_optimized( $file );
	if ( is_array( $already_optimized ) && ! empty( $already_optimized['id'] ) ) {
		$updates = array();
		if ( $gallery && empty( $already_optimized['gallery'] ) ) {
			$updates['gallery'] = $gallery;
		}
		if ( $attachment_id && empty( $already_optimized['attachment_id'] ) ) {
			$updates['attachment_id'] = $attachment_id;
		}
		if ( $size && empty( $already_optimized['resize'] ) ) {
			$updates['resize'] = $size;
		}
		if ( $converted && empty( $already_optimized['converted'] ) ) {
			$updates['converted'] = $converted;
		}
		if ( $updates ) {
			ewwwio_debug_message( "running update for $file" );
			$updates['updated'] = $already_optimized['updated'];
			global $wpdb;
			// Update the values given for the record we found.
			$updated = $wpdb->update(
				$wpdb->ewwwio_images,
				$updates,
				array(
					'id' => $already_optimized['id'],
				)
			);
			if ( false === $updated ) {
				ewwwio_debug_message( "failed to update record for $file" );
			}
			if ( ! $updated ) {
				ewwwio_debug_message( "no records updated for $file and {$already_optimized['id']}" );
			}
			return $updated;
		}
	} // End if().
	return false;
}

/**
 * Compiles information from the metadata to be inserted into the images table.
 *
 * @param int   $id The attachment ID number.
 * @param array $meta The attachment metadata.
 * @param bool  $bail_early Optional. True to stop execution if full size didn't need migration.
 * @return array The attachment metadata, potentially cleaned after migration.
 */
function ewww_image_optimizer_migrate_meta_to_db( $id, $meta, $bail_early = false ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( empty( $meta ) ) {
		ewwwio_debug_message( "empty meta for $id" );
		return $meta;
	}
	list( $file_path, $upload_path ) = ewww_image_optimizer_attachment_path( $meta, $id );
	if ( ! is_file( $file_path ) && ( class_exists( 'WindowsAzureStorageUtil' ) || class_exists( 'Amazon_S3_And_CloudFront' ) ) ) {
		// Construct a $file_path and proceed IF a supported CDN plugin is installed.
		$file_path = get_attached_file( $selected_id );
		if ( ! $file_path ) {
			ewwwio_debug_message( 'no file found for remote attachment' );
			// $meta = ewww_image_optimizer_clean_meta( $meta );
			// TODO: once we've kicked the tires about a million times, and we're convinced this can't happen in error, then let's clean the meta
			return $meta;
		}
	} elseif ( ! $file_path ) {
		ewwwio_debug_message( 'no file found for attachment' );
		// $meta = ewww_image_optimizer_clean_meta( $meta );
		// TODO: ditto.
		return $meta;
	}
	$converted = ( is_array( $meta ) && ! empty( $meta['converted'] ) && ! empty( $meta['orig_file'] ) ? trailingslashit( dirname( $file_path ) ) . basename( $meta['orig_file'] ) : false );
	$full_size_update = ewww_image_optimizer_update_file_from_meta( $file_path, 'media', $id, 'full', $converted );
	if ( ! $full_size_update && $bail_early ) {
		ewwwio_debug_message( "bailing early for migration of $id" );
		return false;
	}
	$retina_path = ewww_image_optimizer_hidpi_optimize( $file_path, true, false );
	if ( $retina_path ) {
		ewww_image_optimizer_update_file_from_meta( $retina_path, 'media', $id, 'full-retina' );
	}
	$type = ewww_image_optimizer_quick_mimetype( $file_path );
	// Resized versions, so we can continue.
	if ( isset( $meta['sizes'] ) && ewww_image_optimizer_iterable( $meta['sizes'] ) ) {
		// Meta sizes don't contain a path, so we calculate one.
		if ( 'ims_image' == get_post_type( $id ) ) {
			$base_dir = trailingslashit( dirname( $file_path ) ) . '_resized/';
		} else {
			$base_dir = trailingslashit( dirname( $file_path ) );
		}
		foreach ( $meta['sizes'] as $size => $data ) {
			ewwwio_debug_message( "checking for size: $size" );
			if ( strpos( $size, 'webp' ) === 0 ) {
				continue;
			}
			if ( empty( $data ) || ! is_array( $data ) || empty( $data['file'] ) ) {
				continue;
			}
			if ( 'full' == $size && 'application/pdf' == $type ) {
				$size = 'pdf-full';
			} elseif ( 'full' == $size ) {
				continue;
			}

			$resize_path = $base_dir . $data['file'];
			$converted = ( is_array( $data ) && ! empty( $data['converted'] ) && ! empty( $data['orig_file'] ) ? trailingslashit( dirname( $resize_path ) ) . basename( $data['orig_file'] ) : false );
			ewww_image_optimizer_update_file_from_meta( $resize_path, 'media', $id, $size, $converted );
			// Search for retina images.
			if ( function_exists( 'wr2x_get_retina' ) ) {
				$retina_path = wr2x_get_retina( $resize_path );
			} else {
				$retina_path = ewww_image_optimizer_hidpi_optimize( $resize_path, true, false );
			}
			if ( $retina_path ) {
				ewww_image_optimizer_update_file_from_meta( $retina_path, 'media', $id, $size . '-retina' );
			}
		}
	} // End if().

	// Search sizes from a custom theme...
	if ( isset( $meta['image_meta']['resized_images'] ) && ewww_image_optimizer_iterable( $meta['image_meta']['resized_images'] ) ) {
		$imagemeta_resize_pathinfo = pathinfo( $file_path );
		$imagemeta_resize_path = '';
		foreach ( $meta['image_meta']['resized_images'] as $index => $imagemeta_resize ) {
			$imagemeta_resize_path = $imagemeta_resize_pathinfo['dirname'] . '/' . $imagemeta_resize_pathinfo['filename'] . '-' . $imagemeta_resize . '.' . $imagemeta_resize_pathinfo['extension'];
			ewww_image_optimizer_update_file_from_meta( $imagemeta_resize_path, 'media', $id, 'resized-images-' . $index );
		}
	}

	// and another custom theme.
	if ( isset( $meta['custom_sizes'] ) && ewww_image_optimizer_iterable( $meta['custom_sizes'] ) ) {
		$custom_sizes_pathinfo = pathinfo( $file_path );
		$custom_size_path = '';
		foreach ( $meta['custom_sizes'] as $dimensions => $custom_size ) {
			$custom_size_path = $custom_sizes_pathinfo['dirname'] . '/' . $custom_size['file'];
			ewww_image_optimizer_update_file_from_meta( $custom_size_path, 'media', $id, 'custom-size-' . $dimensions );
		}
	}
	$meta = ewww_image_optimizer_clean_meta( $meta );
	if ( ewww_image_optimizer_function_exists( 'print_r' ) ) {
		ewwwio_debug_message( print_r( $meta, true ) );
	}
	update_post_meta( $id, '_wp_attachment_metadata', $meta );
	return $meta;
}

/**
 * Load JS in media library footer for bulk actions.
 */
function ewww_image_optimizer_load_admin_js() {
	add_action( 'admin_print_footer_scripts', 'ewww_image_optimizer_add_bulk_actions_via_javascript' );
}

/**
 * Adds a bulk optimize action to the drop-down on the media library page.
 */
function ewww_image_optimizer_add_bulk_actions_via_javascript() {
	// Borrowed from http://www.viper007bond.com/wordpress-plugins/regenerate-thumbnails/ .
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	if ( ! current_user_can( apply_filters( 'ewww_image_optimizer_bulk_permissions', '' ) ) ) {
		return;
	}
?>
	<script type="text/javascript">
		jQuery(document).ready(function($){
			$('select[name^="action"] option:last-child').before('<option value="bulk_optimize"><?php esc_html_e( 'Bulk Optimize', 'ewww-image-optimizer' ); ?></option>');
			$('.ewww-manual-convert').tooltip();
		});
	</script>
<?php
}

/**
 * Handles the bulk actions POST.
 */
function ewww_image_optimizer_bulk_action_handler() {
	// Borrowed from http://www.viper007bond.com/wordpress-plugins/regenerate-thumbnails/ .
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	// If the requested action is blank, or not a bulk_optimize, do nothing.
	if ( ( empty( $_REQUEST['action'] ) || 'bulk_optimize' != $_REQUEST['action'] ) && ( empty( $_REQUEST['action2'] ) || 'bulk_optimize' != $_REQUEST['action2'] ) ) {
		return;
	}
	// If there is no media to optimize, do nothing.
	if ( empty( $_REQUEST['media'] ) || ! is_array( $_REQUEST['media'] ) ) {
		return;
	}
	// Check the referring page.
	check_admin_referer( 'bulk-media' );
	// Prep the attachment IDs for optimization.
	$ids = implode( ',', array_map( 'intval', $_REQUEST['media'] ) );
	wp_redirect( add_query_arg(
		array(
			'page' => 'ewww-image-optimizer-bulk',
			'_wpnonce' => wp_create_nonce( 'ewww-image-optimizer-bulk' ),
			'goback' => 1,
			'ids' => $ids,
		),
		admin_url( 'upload.php' )
	) );
	ewwwio_memory( __FUNCTION__ );
	exit();
}

/**
 * Retrieve option: use 'site' setting if plugin is network activated, otherwise use 'blog' setting.
 *
 * @param string $option_name The name of the option to retrieve.
 * @return mixed The value of the option.
 */
function ewww_image_optimizer_get_option( $option_name ) {
	if ( ! function_exists( 'is_plugin_active_for_network' ) && is_multisite() ) {
		// Need to include the plugin library for the is_plugin_active function.
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	}
	if ( is_multisite() && is_plugin_active_for_network( EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE_REL ) && ! get_site_option( 'ewww_image_optimizer_allow_multisite_override' ) ) {
		$option_value = get_site_option( $option_name );
	} else {
		$option_value = get_option( $option_name );
	}
	return $option_value;
}

/**
 * Set an option: use 'site' setting if plugin is network activated, otherwise use 'blog' setting.
 *
 * @param string $option_name The name of the option to save.
 * @param mixed  $option_value The value to save for the option.
 * @return bool True if the operation was successful.
 */
function ewww_image_optimizer_set_option( $option_name, $option_value ) {
	if ( ! function_exists( 'is_plugin_active_for_network' ) && is_multisite() ) {
		// Need to include the plugin library for the is_plugin_active function.
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	}
	if ( is_multisite() && is_plugin_active_for_network( EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE_REL ) && ! get_site_option( 'ewww_image_optimizer_allow_multisite_override' ) ) {
		$success = update_site_option( $option_name, $option_value );
	} else {
		$success = update_option( $option_name, $option_value );
	}
	return $success;
}

/**
 * Check for a list of attachments for which we do not rebuild meta.
 *
 * @return array {
 *	Information regarding attachments with broken metadata that could not be rebuilt.
 *
 *	@type array A list of all know 'bad' attachments.
 *	@type string The most recent 'bad' attachment.
 * }
 */
function ewww_image_optimizer_get_bad_attachments() {
	$bad_attachment = get_transient( 'ewww_image_optimizer_rebuilding_attachment' );
	if ( $bad_attachment ) {
		$bad_attachments = (array) ewww_image_optimizer_get_option( 'ewww_image_optimizer_bad_attachments' );
		$bad_attachments[] = $bad_attachment;
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_bad_attachments', $bad_attachments, false );
	} else {
		$bad_attachments = (array) ewww_image_optimizer_get_option( 'ewww_image_optimizer_bad_attachments' );
	}
	delete_transient( 'ewww_image_optimizer_rebuilding_attachment' );
	return array( $bad_attachments, $bad_attachment );
}

/**
 * JS needed for the settings page.
 *
 * @param string $hook The hook name of the page being loaded.
 */
function ewww_image_optimizer_settings_script( $hook ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	// Make sure we are being called from the settings page.
	if ( strpos( $hook,'settings_page_ewww-image-optimizer' ) !== 0 ) {
		return;
	}
	wp_enqueue_script( 'ewwwbulkscript', plugins_url( '/includes/eio.js', __FILE__ ), array( 'jquery' ), EWWW_IMAGE_OPTIMIZER_VERSION );
	wp_enqueue_script( 'postbox' );
	wp_enqueue_script( 'dashboard' );
	wp_localize_script( 'ewwwbulkscript', 'ewww_vars', array(
			'_wpnonce' => wp_create_nonce( 'ewww-image-optimizer-settings' ),
		)
	);
	ewwwio_memory( __FUNCTION__ );
	return;
}

/**
 * Get a total of how much space we have saved so far.
 *
 * @global object $wpdb
 *
 * @return int The total savings found, in bytes.
 */
function ewww_image_optimizer_savings() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	global $wpdb;
	if ( ! function_exists( 'is_plugin_active_for_network' ) && is_multisite() ) {
		// Need to include the plugin library for the is_plugin_active function.
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	}
	if ( is_multisite() && is_plugin_active_for_network( EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE_REL ) ) {
		ewwwio_debug_message( 'querying savings for multi-site' );

		if ( get_blog_count() > 1000 ) {
			// TODO: someday do something more clever than this, maybe.
			return 0;
		}
		if ( function_exists( 'get_sites' ) ) {
			ewwwio_debug_message( 'retrieving list of sites the easy way (4.6+)' );
			$blogs = get_sites( array(
				'fields' => 'ids',
				'number' => 1000,
			) );

		} elseif ( function_exists( 'wp_get_sites' ) ) {
			ewwwio_debug_message( 'retrieving list of sites the easy way (pre 4.6)' );
			$blogs = wp_get_sites( array(
				'network_id' => $wpdb->siteid,
				'limit' => 1000,
			) );
		}
		$total_savings = 0;
		if ( ewww_image_optimizer_iterable( $blogs ) ) {
			foreach ( $blogs as $blog ) {
				if ( is_array( $blog ) ) {
					$blog_id = $blog['blog_id'];
				} else {
					$blog_id = $blog;
				}
				switch_to_blog( $blog_id );
				ewwwio_debug_message( "getting savings for site: $blog_id" );
				$table_name = $wpdb->prefix . 'ewwwio_images';
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) != $table_name ) {
					ewww_image_optimizer_install_table();
				}
				if ( $wpdb->ewwwio_images ) {
					$wpdb->query( "DELETE FROM $wpdb->ewwwio_images WHERE image_size > orig_size" );
					$savings = $wpdb->get_var( "SELECT SUM(orig_size-image_size) FROM $wpdb->ewwwio_images" );
					ewwwio_debug_message( "savings found: $savings" );
					$total_savings += $savings;
				}
				restore_current_blog();
			}
		}
	} else {
		ewwwio_debug_message( 'querying savings for single site' );
		$total_savings = 0;
		$table_name = $wpdb->ewwwio_images;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) != $table_name ) {
			ewww_image_optimizer_install_table();
		}
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'ewwwio_images WHERE image_size > orig_size' );
		$total_savings = $wpdb->get_var( "SELECT SUM(orig_size-image_size) FROM $wpdb->ewwwio_images" );
		ewwwio_debug_message( "savings found: $total_savings" );
	} // End if().
	return $total_savings;
}

/**
 * Figure out where the .htaccess file should live.
 *
 * @return string The path to the .htaccess file.
 */
function ewww_image_optimizer_htaccess_path() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	$htpath = get_home_path();
	if ( get_option( 'siteurl' ) !== get_option( 'home' ) ) {
		ewwwio_debug_message( 'WordPress Address and Site Address are different, possible subdir install' );
		$path_diff = str_replace( get_option( 'home' ), '', get_option( 'siteurl' ) );
		$newhtpath = trailingslashit( $htpath . $path_diff ) . '.htaccess';
		if ( is_file( $newhtpath ) ) {
			ewwwio_debug_message( 'subdir install confirmed' );
			return $newhtpath;
		}
	}
	return $htpath . '.htaccess';
}

/**
 * Called via AJAX, adds WebP rewrite rules to the .htaccess file.
 */
function ewww_image_optimizer_webp_rewrite() {
	// Verify that the user is properly authorized.
	if ( ! wp_verify_nonce( $_REQUEST['ewww_wpnonce'], 'ewww-image-optimizer-settings' ) ) {
		wp_die( esc_html__( 'Access denied.', 'ewww-image-optimizer' ) );
	}
	$ewww_rules = ewww_image_optimizer_webp_rewrite_verify();
	if ( $ewww_rules ) {
		if ( insert_with_markers( ewww_image_optimizer_htaccess_path(), 'EWWWIO', $ewww_rules ) && ! ewww_image_optimizer_webp_rewrite_verify() ) {
			esc_html_e( 'Insertion successful', 'ewww-image-optimizer' );
		} else {
			esc_html_e( 'Insertion failed', 'ewww-image-optimizer' );
		}
	}
	die();
}

/**
 * If rules are present, stay silent, otherwise, gives us some rules to insert!
 */
function ewww_image_optimizer_webp_rewrite_verify() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	$current_rules = extract_from_markers( ewww_image_optimizer_htaccess_path(), 'EWWWIO' );
	$ewww_rules = array(
		'<IfModule mod_rewrite.c>',
		'RewriteEngine On',
		'RewriteCond %{HTTP_ACCEPT} image/webp',
		'RewriteCond %{REQUEST_FILENAME} (.*)\.(jpe?g|png)$',
		'RewriteCond %{REQUEST_FILENAME}.webp -f',
		'RewriteCond %{QUERY_STRING} !type=original',
		'RewriteRule (.+)\.(jpe?g|png)$ %{REQUEST_FILENAME}.webp [T=image/webp,E=accept:1,L]',
		'</IfModule>',
		'<IfModule mod_headers.c>',
		'Header append Vary Accept env=REDIRECT_accept',
		'</IfModule>',
		'AddType image/webp .webp',
	);
	ewwwio_debug_message( print_r( $current_rules, true ) );
	if ( empty( $current_rules ) ||
		! ewww_image_optimizer_array_search( '{HTTP_ACCEPT} image/webp', $current_rules ) ||
		! ewww_image_optimizer_array_search( '{REQUEST_FILENAME}.webp', $current_rules ) ||
		! ewww_image_optimizer_array_search( 'Header append Vary Accept', $current_rules ) ||
		! ewww_image_optimizer_array_search( 'AddType image/webp', $current_rules )
	) {
		ewwwio_memory( __FUNCTION__ );
		return $ewww_rules;
	} else {
		ewwwio_memory( __FUNCTION__ );
		return;
	}
}

/**
 * Looks for a certain string within all array elements.
 *
 * @param string $needle The searched value.
 * @param array  $haystack The array to search.
 * @return bool True if the needle is found, false otherwise.
 */
function ewww_image_optimizer_array_search( $needle, $haystack ) {
	if ( ! is_array( $haystack ) ) {
		return false;
	}
	foreach ( $haystack as $straw ) {
		if ( ! is_string( $straw ) ) {
			continue;
		}
		if ( strpos( $straw, $needle ) !== false ) {
			return true;
		}
	}
	return false;
}

/**
 * Retrieves a list of registered image sizes.
 *
 * @global array $_wp_additional_image_sizes
 *
 * @return array A list if image sizes.
 */
function ewww_image_optimizer_get_image_sizes() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	global $_wp_additional_image_sizes;
	$sizes = array();
	$image_sizes = get_intermediate_image_sizes();
	if ( ewww_image_optimizer_function_exists( 'print_r' ) ) {
		ewwwio_debug_message( print_r( $image_sizes, true ) );
	}
	if ( false ) { // set to true to enable more debugging.
		ewwwio_debug_message( print_r( $_wp_additional_image_sizes, true ) );
	}
	if ( ewww_image_optimizer_iterable( $image_sizes ) ) {
		foreach ( $image_sizes as $_size ) {
			if ( in_array( $_size, array( 'thumbnail', 'medium', 'medium_large', 'large' ) ) ) {
				$sizes[ $_size ]['width'] = get_option( $_size . '_size_w' );
				$sizes[ $_size ]['height'] = get_option( $_size . '_size_h' );
				if ( 'medium_large' === $_size && 0 == $sizes[ $_size ]['width'] ) {
					$sizes[ $_size ]['width'] = '768';
				}
				if ( 'medium_large' === $_size && 0 == $sizes[ $_size ]['height'] ) {
					$sizes[ $_size ]['height'] = '9999';
				}
			} elseif ( isset( $_wp_additional_image_sizes[ $_size ] ) ) {
				$sizes[ $_size ] = array(
					'width' => $_wp_additional_image_sizes[ $_size ]['width'],
					'height' => $_wp_additional_image_sizes[ $_size ]['height'],
				);
			}
		}
	}
	$sizes['pdf-full'] = array(
		'width' => 99999,
		'height' => 99999,
	);

	if ( ewww_image_optimizer_function_exists( 'print_r' ) ) {
		ewwwio_debug_message( print_r( $sizes, true ) );
	}
	return $sizes;
}

/**
 * Wrapper that displays the EWWW IO options in the multisite network admin.
 *
 * @global string $ewww_debug In memory debug log.
 */
function ewww_image_optimizer_network_options() {
	add_filter( 'ewww_image_optimizer_settings', 'ewww_image_optimizer_filter_network_settings_page', 9 );
	ewww_image_optimizer_options( 'network-multisite' );
}

/**
 * Wrapper that displays the EWWW IO options for multisite mode on a single site.
 *
 * By default, the only options displayed are the per-site resizes list, but a network admin can
 * permit site admins to configure their own blog settings.
 *
 * @global string $ewww_debug In memory debug log.
 */
function ewww_image_optimizer_network_singlesite_options() {
	add_filter( 'ewww_image_optimizer_settings', 'ewww_image_optimizer_filter_network_singlesite_settings_page', 9 );
	ewww_image_optimizer_options( 'network-singlesite' );
}

/**
 * Displays the EWWW IO options along with status information, and debugging information.
 *
 * @global string $ewww_debug In memory debug log.
 *
 * @param string $network Indicates which options should be shown in multisite installations.
 */
function ewww_image_optimizer_options( $network = 'singlesite' ) {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	ewwwio_debug_message( 'ABSPATH: ' . ABSPATH );
	ewwwio_debug_message( 'WP_CONTENT_DIR: ' . WP_CONTENT_DIR );
	ewwwio_debug_message( 'home url: ' . get_home_url() );
	ewwwio_debug_message( 'site url: ' . get_site_url() );
	$network_class = $network;
	if ( empty( $network ) ) {
		$network_class = 'singlesite';
	}
	if ( ! function_exists( 'is_plugin_active_for_network' ) && is_multisite() ) {
		// Need to include the plugin library for the is_plugin_active function.
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	}
	if ( is_multisite() && is_plugin_active_for_network( EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE_REL ) && ! get_site_option( 'ewww_image_optimizer_allow_multisite_override' ) ) {
		$network_class = 'network-multisite';
	}
	$output = array();
	if ( isset( $_REQUEST['ewww_pngout'] ) ) {
		if ( 'success' == $_REQUEST['ewww_pngout'] ) {
			$output[] = "<div id='ewww-image-optimizer-pngout-success' class='updated fade'>\n";
			$output[] = '<p>' . esc_html__( 'Pngout was successfully installed, check the Plugin Status area for version information.', 'ewww-image-optimizer' ) . "</p>\n";
			$output[] = "</div>\n";
		}
		if ( 'failed' == $_REQUEST['ewww_pngout'] ) {
			$output[] = "<div id='ewww-image-optimizer-pngout-failure' class='error'>\n";
			$output[] = '<p>' . sprintf(
				/* translators: 1: An error message 2: The folder where pngout should be installed */
				esc_html__( 'Pngout was not installed: %1$s. Make sure this folder is writable: %2$s', 'ewww-image-optimizer' ),
				sanitize_text_field( $_REQUEST['ewww_error'] ), EWWW_IMAGE_OPTIMIZER_TOOL_PATH
			) . "</p>\n";
			$output[] = "</div>\n";
		}
	}
	$output[] = "<script type='text/javascript'>\n" .
		'jQuery(document).ready(function($) {$(".fade").fadeTo(5000,1).fadeOut(3000);});' . "\n" .
		"</script>\n";
	$output[] = "<style>\n" .
		".ewww-tab span { font-size: 15px; font-weight: 700; color: #555; text-decoration: none; line-height: 36px; padding: 0 10px; }\n" .
		".ewww-tab span:hover { color: #464646; }\n" .
		".ewww-tab { margin: 0 0 0 5px; padding: 0px; border-width: 1px 1px 1px; border-style: solid solid none; border-image: none; border-color: #ccc; display: inline-block; background-color: #e4e4e4; cursor: pointer }\n" .
		".ewww-tab:hover { background-color: #fff }\n" .
		".ewww-selected { background-color: #f1f1f1; margin-bottom: -1px; border-bottom: 1px solid #f1f1f1 }\n" .
		".ewww-selected span { color: #000; }\n" .
		".ewww-selected:hover { background-color: #f1f1f1; }\n" .
		".ewww-tab-nav { list-style: none; margin: 10px 0 0; padding-left: 5px; border-bottom: 1px solid #ccc; }\n" .
	"</style>\n";
	$output[] = "<div class='wrap'>\n";
	$output[] = "<h1>EWWW Image Optimizer</h1>\n";
	$output[] = "<div id='ewww-container-left' style='float: left; margin-right: 225px;'>\n";
	$output[] = "<p><a href='https://ewww.io/'>" . esc_html__( 'Plugin Home Page', 'ewww-image-optimizer' ) . '</a> | ' .
		"<a href='http://docs.ewww.io/'>" . esc_html__( 'Documentation', 'ewww-image-optimizer' ) . '</a> | ' .
		"<a href='https://ewww.io/contact-us/'>" . esc_html__( 'Plugin Support', 'ewww-image-optimizer' ) . '</a> | ' .
		"<a href='https://ewww.io/status/'>" . esc_html__( 'Cloud Status', 'ewww-image-optimizer' ) . '</a> | ' .
		"<a href='https://ewww.io/downloads/s3-image-optimizer/'>" . esc_html__( 'S3 Image Optimizer', 'ewww-image-optimizer' ) . "</a></p>\n";
	if ( 'network-multisite' == $network ) {
		$bulk_link = esc_html__( 'Media Library', 'ewww-image-optimizer' ) . ' -> ' . esc_html__( 'Bulk Optimize', 'ewww-image-optimizer' );
	} else {
		$bulk_link = '<a href="upload.php?page=ewww-image-optimizer-bulk">' . esc_html__( 'Bulk Optimize', 'ewww-image-optimizer' ) . '</a>';
	}
	$s3_link = '<a href="https://ewww.io/downloads/s3-image-optimizer/">' . esc_html__( 'S3 Image Optimizer', 'ewww-image-optimizer' ) . '</a>';
	$output[] = '<p>' .
		sprintf(
			/* translators: %s: Bulk Optimize (link) */
			esc_html__( 'New images uploaded to the Media Library will be optimized automatically. If you have existing images you would like to optimize, you can use the %s tool.', 'ewww-image-optimizer' ),
			$bulk_link
		) . ' ' .
		sprintf(
			/* translators: %s: S3 Image Optimizer (link) */
			esc_html__( 'Images stored in an Amazon S3 bucket can be optimized using our %s.' ),
			$s3_link
		) .
		"</p>\n";
	$status_output = "<div id='ewww-widgets' class='metabox-holder'><div class='meta-box-sortables'><div id='ewww-status' class='postbox'>\n" .
		"<h2 class='hndle'>" . esc_html__( 'Plugin Status', 'ewww-image-optimizer' ) . "&emsp;STATUS_PLACEHOLDER</h2>\n<div class='inside'>";
	$total_savings = ewww_image_optimizer_savings();
	if ( $total_savings ) {
		$status_output .= '<b>' . esc_html__( 'Total Savings:', 'ewww-image-optimizer' ) . "</b> <span id='ewww-total-savings'>" . ewww_image_optimizer_size_format( $total_savings, 2 ) . '</span><br>';
	}
	ewwwio_debug_message( ewww_image_optimizer_aux_images_table_count() . ' images have been optimized' );
	// If this remains true, then we display an all clear, otherwise something needs fixing. TODO: rename this someday.
	$collapsible = true;
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) ) {
		$status_output .= '<p><b>' . esc_html__( 'Cloud optimization API Key', 'ewww-image-optimizer' ) . ':</b> ';
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_cloud_exceeded', 0 );
		$verify_cloud = ewww_image_optimizer_cloud_verify( false );
		if ( preg_match( '/great/', $verify_cloud ) ) {
			$status_output .= '<span style="color: green">' . esc_html__( 'Verified,', 'ewww-image-optimizer' ) . ' </span>' . ewww_image_optimizer_cloud_quota();
		} elseif ( preg_match( '/exceeded/', $verify_cloud ) ) {
			$status_output .= '<span style="color: orange">' . esc_html__( 'Verified,', 'ewww-image-optimizer' ) . ' </span>' . ewww_image_optimizer_cloud_quota();
			$collapsible = false;
		} else {
			$status_output .= '<span style="color: red">' . esc_html__( 'Not Verified', 'ewww-image-optimizer' ) . '</span>';
			$collapsible = false;
		}
		$status_output .= "</p>\n";
		$disable_level = '';
	} else {
		$disable_level = "disabled='disabled'";
	}
	if ( ! ewww_image_optimizer_full_cloud() && ! EWWW_IMAGE_OPTIMIZER_NOEXEC ) {
		list ( $jpegtran_src, $optipng_src, $gifsicle_src, $jpegtran_dst, $optipng_dst, $gifsicle_dst ) = ewww_image_optimizer_install_paths();
	}
	$skip = ewww_image_optimizer_skip_tools();
	$status_output .= "<p>\n";
	if ( ! $skip['jpegtran']  && ! EWWW_IMAGE_OPTIMIZER_NOEXEC ) {
		$status_output .= '<b>jpegtran:</b> ';
		if ( EWWW_IMAGE_OPTIMIZER_JPEGTRAN ) {
			$jpegtran_installed = ewww_image_optimizer_tool_found( EWWW_IMAGE_OPTIMIZER_JPEGTRAN, 'j' );
			if ( ! $jpegtran_installed ) {
				$jpegtran_installed = ewww_image_optimizer_tool_found( EWWW_IMAGE_OPTIMIZER_JPEGTRAN, 'jb' );
			}
		}
		if ( ! empty( $jpegtran_installed ) ) {
			$status_output .= '<span style="color: green; font-weight: bolder">' . esc_html__( 'Installed', 'ewww-image-optimizer' ) . '</span>&emsp;' . esc_html__( 'version', 'ewww-image-optimizer' ) . ': ' . $jpegtran_installed . "<br />\n";
		} else {
			$status_output .= '<span style="color: red; font-weight: bolder">' . esc_html__( 'Missing', 'ewww-image-optimizer' ) . '</span><br />' . "\n";
			$collapsible = false;
		}
	}
	if ( ! $skip['optipng'] && ! EWWW_IMAGE_OPTIMIZER_NOEXEC ) {
		$status_output .= '<b>optipng:</b> ';
		if ( EWWW_IMAGE_OPTIMIZER_OPTIPNG ) {
			$optipng_version = ewww_image_optimizer_tool_found( EWWW_IMAGE_OPTIMIZER_OPTIPNG, 'o' );
			if ( ! $optipng_version ) {
				$optipng_version = ewww_image_optimizer_tool_found( EWWW_IMAGE_OPTIMIZER_OPTIPNG, 'ob' );
			}
		}
		if ( ! empty( $optipng_version ) ) {
			$status_output .= '<span style="color: green; font-weight: bolder">' . esc_html__( 'Installed', 'ewww-image-optimizer' ) . '</span>&emsp;' . esc_html__( 'version', 'ewww-image-optimizer' ) . ': ' . $optipng_version . "<br />\n";
		} else {
			$status_output .= '<span style="color: red; font-weight: bolder">' . esc_html__( 'Missing', 'ewww-image-optimizer' ) . '</span><br />' . "\n";
			$collapsible = false;
		}
	}
	if ( ! $skip['pngout'] && ! EWWW_IMAGE_OPTIMIZER_NOEXEC ) {
		$status_output .= '<b>pngout:</b> ';
		if ( EWWW_IMAGE_OPTIMIZER_PNGOUT ) {
			$pngout_version = ewww_image_optimizer_tool_found( EWWW_IMAGE_OPTIMIZER_PNGOUT, 'p' );
			if ( ! $pngout_version ) {
				$pngout_version = ewww_image_optimizer_tool_found( EWWW_IMAGE_OPTIMIZER_PNGOUT, 'pb' );
			}
		}
		if ( ! empty( $pngout_version ) ) {
			$status_output .= '<span style="color: green; font-weight: bolder">' . esc_html__( 'Installed', 'ewww-image-optimizer' ) . '</span>&emsp;' . esc_html__( 'version', 'ewww-image-optimizer' ) . ': ' . preg_replace( '/PNGOUT \[.*\)\s*?/', '', $pngout_version ) . "<br />\n";
		} else {
			$status_output .= '<span style="color: red; font-weight: bolder">' . esc_html__( 'Missing', 'ewww-image-optimizer' ) . '</span>&emsp;' . esc_html__( 'Install', 'ewww-image-optimizer' ) . ' <a href="admin.php?action=ewww_image_optimizer_install_pngout">' . esc_html__( 'automatically', 'ewww-image-optimizer' ) . '</a> | <a href="http://advsys.net/ken/utils.htm">' . esc_html__( 'manually', 'ewww-image-optimizer' ) . '</a> - ' . esc_html__( 'Pngout is free closed-source software that can produce drastically reduced filesizes for PNGs, but can be very time consuming to process images', 'ewww-image-optimizer' ) . "<br />\n";
			$collapsible = false;
		}
	}
	if ( $skip['pngout'] && ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) == 10 ) {
		$status_output .= '<b>pngout:</b> ' . esc_html__( 'Not installed, enable in Advanced Settings', 'ewww-image-optimizer' ) . "<br />\n";
	}
	if ( ! $skip['gifsicle'] && ! EWWW_IMAGE_OPTIMIZER_NOEXEC ) {
		$status_output .= '<b>gifsicle:</b> ';
		if ( EWWW_IMAGE_OPTIMIZER_GIFSICLE ) {
			$gifsicle_version = ewww_image_optimizer_tool_found( EWWW_IMAGE_OPTIMIZER_GIFSICLE, 'g' );
			if ( ! $gifsicle_version ) {
				$gifsicle_version = ewww_image_optimizer_tool_found( EWWW_IMAGE_OPTIMIZER_GIFSICLE, 'gb' );
			}
		}
		if ( ! empty( $gifsicle_version ) ) {
			$status_output .= '<span style="color: green; font-weight: bolder">' . esc_html__( 'Installed', 'ewww-image-optimizer' ) . '</span>&emsp;' . esc_html__( 'version', 'ewww-image-optimizer' ) . ': ' . $gifsicle_version . "<br />\n";
		} else {
			$status_output .= '<span style="color: red; font-weight: bolder">' . esc_html__( 'Missing', 'ewww-image-optimizer' ) . '</span><br />' . "\n";
			$collapsible = false;
		}
	}
	if ( ! $skip['pngquant'] && ! EWWW_IMAGE_OPTIMIZER_NOEXEC ) {
		$status_output .= '<b>pngquant:</b> ';
		if ( EWWW_IMAGE_OPTIMIZER_PNGQUANT ) {
			$pngquant_version = ewww_image_optimizer_tool_found( EWWW_IMAGE_OPTIMIZER_PNGQUANT, 'q' );
			if ( ! $pngquant_version ) {
				$pngquant_version = ewww_image_optimizer_tool_found( EWWW_IMAGE_OPTIMIZER_PNGQUANT, 'qb' );
			}
		}
		if ( ! empty( $pngquant_version ) ) {
			$status_output .= '<span style="color: green; font-weight: bolder">' . esc_html__( 'Installed', 'ewww-image-optimizer' ) . '</span>&emsp;' . esc_html__( 'version', 'ewww-image-optimizer' ) . ': ' . $pngquant_version . "<br />\n";
		} else {
			$status_output .= '<span style="color: red; font-weight: bolder">' . esc_html__( 'Missing', 'ewww-image-optimizer' ) . '</span><br />' . "\n";
			$collapsible = false;
		}
	}
	if ( EWWW_IMAGE_OPTIMIZER_WEBP && ! $skip['webp'] && ! EWWW_IMAGE_OPTIMIZER_NOEXEC ) {
		$status_output .= '<b>webp:</b> ';
		if ( EWWW_IMAGE_OPTIMIZER_WEBP ) {
			$webp_version = ewww_image_optimizer_tool_found( EWWW_IMAGE_OPTIMIZER_WEBP, 'w' );
			if ( ! $webp_version ) {
				$webp_version = ewww_image_optimizer_tool_found( EWWW_IMAGE_OPTIMIZER_WEBP, 'wb' );
			}
		}
		if ( ! empty( $webp_version ) ) {
			$status_output .= '<span style="color: green; font-weight: bolder">' . esc_html__( 'Installed', 'ewww-image-optimizer' ) . '</span>&emsp;' . esc_html__( 'version', 'ewww-image-optimizer' ) . ': ' . $webp_version . "<br />\n";
		} else {
			$status_output .= '<span style="color: red; font-weight: bolder">' . esc_html__( 'Missing', 'ewww-image-optimizer' ) . '</span><br />' . "\n";
			$collapsible = false;
		}
	}
	if ( ! ewww_image_optimizer_full_cloud() ) {
		if ( ewww_image_optimizer_safemode_check() ) {
			$status_output .= 'safe mode: <span style="color: red; font-weight: bolder">' . esc_html__( 'On', 'ewww-image-optimizer' ) . '</span>&emsp;&emsp;';
			$collapsible = false;
		} else {
			$status_output .= 'safe mode: <span style="color: green; font-weight: bolder">' . esc_html__( 'Off', 'ewww-image-optimizer' ) . '</span>&emsp;&emsp;';
		}
		if ( ewww_image_optimizer_exec_check() ) {
			$status_output .= 'exec(): <span style="color: red; font-weight: bolder">' . esc_html__( 'Disabled', 'ewww-image-optimizer' ) . '</span>&emsp;&emsp;';
			$collapsible = false;
		} else {
			$status_output .= 'exec(): <span style="color: green; font-weight: bolder">' . esc_html__( 'Enabled', 'ewww-image-optimizer' ) . '</span>&emsp;&emsp;';
		}
		$status_output .= "<br />\n";
	}
	if ( ! ewww_image_optimizer_full_cloud() && ! EWWW_IMAGE_OPTIMIZER_NOEXEC ) {
		$toolkit_output = sprintf(
			/* translators: %s: Graphics libraries - */
			esc_html__( '%s only need one, used for conversion, not optimization', 'ewww-image-optimizer' ),
			'<b>' . __( 'Graphics libraries', 'ewww-image-optimizer' ) . '</b> - '
		);
		$toolkit_output .= '<br>';
		$toolkit_found = false;
		if ( ewww_image_optimizer_gd_support() ) {
			$toolkit_output .= 'GD: <span style="color: green; font-weight: bolder">' . esc_html__( 'Installed', 'ewww-image-optimizer' );
			$toolkit_found = true;
		} else {
			$toolkit_output .= 'GD: <span style="color: red; font-weight: bolder">' . esc_html__( 'Missing', 'ewww-image-optimizer' );
		}
		$toolkit_output .= '</span>&emsp;&emsp;' .
		'Gmagick: ';
		if ( ewww_image_optimizer_gmagick_support() ) {
			$toolkit_output .= '<span style="color: green; font-weight: bolder">' . esc_html__( 'Installed', 'ewww-image-optimizer' );
			$toolkit_found = true;
		} else {
			$toolkit_output .= '<span style="color: red; font-weight: bolder">' . esc_html__( 'Missing', 'ewww-image-optimizer' );
		}
		$toolkit_output .= '</span>&emsp;&emsp;' .
		'Imagick: ';
		if ( ewww_image_optimizer_imagick_support() ) {
			$toolkit_output .= '<span style="color: green; font-weight: bolder">' . esc_html__( 'Installed', 'ewww-image-optimizer' );
			$toolkit_found = true;
		} else {
			$toolkit_output .= '<span style="color: red; font-weight: bolder">' . esc_html__( 'Missing', 'ewww-image-optimizer' );
		}
		$toolkit_output .= "</span><br />\n";
		if ( ! $toolkit_found && ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_to_jpg' ) || ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_to_png' ) ) ) {
			$collapsible = false;
		} else {
			$toolkit_output = str_replace( 'color: red', 'color: gray', $toolkit_output );
		}
		$status_output .= $toolkit_output;
	} // End if().
	$mimetype_output = '<b>' . esc_html__( 'Only need one of these:', 'ewww-image-optimizer' ) . ' </b><br>';
	// Initialize this variable to check for the 'file' command if we don't have any php libraries we can use.
	$file_command_check = true;
	$mimetype_tool = '';
	if ( function_exists( 'finfo_file' ) ) {
		$mimetype_output .= 'fileinfo: <span style="color: green; font-weight: bolder">' . esc_html__( 'Installed', 'ewww-image-optimizer' ) . '</span>&emsp;&emsp;';
		$file_command_check = false;
		$mimetype_tool = 'fileinfo';
	} else {
		$mimetype_output .= 'fileinfo: <span style="color: red; font-weight: bolder">' . esc_html__( 'Missing', 'ewww-image-optimizer' ) . '</span>&emsp;&emsp;';
	}
	if ( function_exists( 'getimagesize' ) ) {
		$mimetype_output .= 'getimagesize(): <span style="color: green; font-weight: bolder">' . esc_html__( 'Installed', 'ewww-image-optimizer' ) . '</span>&emsp;&emsp;';
		if ( ewww_image_optimizer_full_cloud() || EWWW_IMAGE_OPTIMIZER_NOEXEC || PHP_OS == 'WINNT' ) {
			$file_command_check = false;
		}
		$mimetype_tool = empty( $mimetype_tool ) ? 'getimagesize' : $mimetype_tool;
	} else {
		$mimetype_output .= 'getimagesize(): <span style="color: red; font-weight: bolder">' . esc_html__( 'Missing', 'ewww-image-optimizer' ) . '</span>&emsp;&emsp;';
	}
	if ( function_exists( 'mime_content_type' ) ) {
		$mimetype_output .= 'mime_content_type(): <span style="color: green; font-weight: bolder">' . esc_html__( 'Installed', 'ewww-image-optimizer' ) . '</span><br />' . "\n";
		$file_command_check = false;
		$mimetype_tool = empty( $mimetype_tool ) ? 'mime_content_type' : $mimetype_tool;
	} else {
		$mimetype_output .= 'mime_content_type(): <span style="color: red; font-weight: bolder">' . esc_html__( 'Missing', 'ewww-image-optimizer' ) . '</span><br />' . "\n";
	}
	$extra_tool_output = '';
	if ( PHP_OS != 'WINNT' && ! ewww_image_optimizer_full_cloud() && ! EWWW_IMAGE_OPTIMIZER_NOEXEC ) {
		if ( $file_command_check && ! ewww_image_optimizer_find_nix_binary( 'file', 'f' ) ) {
			$extra_tool_output .= '<span style="color: red; font-weight: bolder">file: ' . esc_html__( 'command not found on your system', 'ewww-image-optimizer' ) . '</span><br />';
			$collapsible = false;
		} elseif ( empty( $mimetype_tool ) ) {
			$mimetype_tool = 'file'; // So that we know a mimetype library was found.
		}
		if ( ! ewww_image_optimizer_find_nix_binary( 'nice', 'n' ) ) {
			$extra_tool_output .= '<span style="color: orange; font-weight: bolder">nice: ' . esc_html__( 'command not found on your system', 'ewww-image-optimizer' ) . ' (' . esc_html__( 'not required', 'ewww-image-optimizer' ) . ')</span><br />';
		}
		if ( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_pngout' ) && ! $skip['pngout'] && PHP_OS != 'SunOS' && ! ewww_image_optimizer_find_nix_binary( 'tar', 't' ) ) {
			$extra_tool_output .= '<span style="color: red; font-weight: bolder">tar: ' . esc_html__( 'command not found on your system', 'ewww-image-optimizer' ) . ' (' . esc_html__( 'required for automatic pngout installer', 'ewww-image-optimizer' ) . ')</span><br />';
			$collapsible = false;
		}
	} elseif ( $file_command_check ) {
		$collapsible = false;
	}
	if ( 'getimagesize' == $mimetype_tool && ewww_image_optimizer_get_option( 'ewww_image_optimizer_pdf_level' ) ) {
		$collapsible = false;
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_pdf_level', 0 );
	} elseif ( $mimetype_tool ) {
		$mimetype_output = str_replace( 'color: red', 'color: gray', $mimetype_output );
	}
	ewwwio_debug_message( "mimetype function in use: $mimetype_tool" );
	$status_output .= $mimetype_output;
	$status_output .= $extra_tool_output;
	$status_output .= '<strong>' . esc_html( 'Background and Parallel optimization (faster uploads):', 'ewww-image-optimizer' ) . '</strong><br>';
	if ( defined( 'EWWW_DISABLE_ASYNC' ) && EWWW_DISABLE_ASYNC ) {
		$status_output .= '<span>' . esc_html__( 'Disabled by administrator', 'ewww-image-optimizer' ) . '</span>';
	} elseif ( ! ewww_image_optimizer_function_exists( 'sleep' ) ) {
		$status_output .= '<span style="color: orange; font-weight: bolder">' . esc_html__( 'Disabled, sleep function missing', 'ewww-image-optimizer' ) . '</span>';
	} elseif ( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_background_optimization' ) ) {
		$status_output .= '<span style="color: orange; font-weight: bolder">' . esc_html__( 'Disabled automatically, async requests blocked', 'ewww-image-optimizer' ) . '</span>';
	} elseif ( ewww_image_optimizer_detect_wpsf_location_lock() ) {
		$status_output .= '<span style="color: orange; font-weight: bolder">' . esc_html__( "Disabled by Shield's Lock to Location feature", 'ewww-image-optimizer' ) . '</p>';
	} elseif ( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_parallel_optimization' ) ) {
		$status_output .= '<span>' . esc_html__( 'Enable Parallel Optimization in Advanced Settings', 'ewww-image-optimizer' ) . '</span>';
	} else {
		$status_output .= '<span style="color: green; font-weight: bolder">' . esc_html__( 'Fully Enabled', 'ewww-image-optimizer' ) . '</span>';
	}
	$status_output .= '</div><!-- end .inside -->';
	$status_output .= "</div></div></div>\n";
	if ( $collapsible ) {
		$output[] = str_replace( 'STATUS_PLACEHOLDER', "<span id='ewww-status-ok' style='color: green;'>" . esc_html__( 'All Clear', 'ewww-image-optimizer' ) . '</span>', $status_output );
	} else {
		$output[] = str_replace( 'STATUS_PLACEHOLDER', "<span id='ewww-status-attention' style='color: red;'>" . esc_html__( 'Requires Attention', 'ewww-image-optimizer' ) . '</span>', $status_output );
	}
	if ( ( 'network-multisite' != $network || ! get_site_option( 'ewww_image_optimizer_allow_multisite_override' ) ) && // Display tabs so long as this isn't the network admin OR single-site override is disabled.
		! ( 'network-singlesite' == $network && ! get_site_option( 'ewww_image_optimizer_allow_multisite_override' ) ) ) { // Also make sure that this isn't single site without override mode.
		$output[] = "<ul class='ewww-tab-nav'>\n" .
			"<li class='ewww-tab ewww-general-nav'><span class='ewww-tab-hidden'>" . esc_html__( 'Basic Settings', 'ewww-image-optimizer' ) . "</span></li>\n" .
			"<li class='ewww-tab ewww-optimization-nav'><span class='ewww-tab-hidden'>" . esc_html__( 'Advanced Settings', 'ewww-image-optimizer' ) . "</span></li>\n" .
			"<li class='ewww-tab ewww-conversion-nav'><span class='ewww-tab-hidden'>" . esc_html__( 'Conversion Settings', 'ewww-image-optimizer' ) . "</span></li>\n" .
			"<li class='ewww-tab ewww-webp-nav'><span class='ewww-tab-hidden'>" . esc_html__( 'WebP Settings', 'ewww-image-optimizer' ) . "</span></li>\n" .
		"</ul>\n";
	}
	if ( 'network-multisite' == $network ) {
		$output[] = "<form method='post' action=''>\n";
	} else {
		$output[] = "<form method='post' action='options.php'>\n";
	}
	$output[] = "<input type='hidden' name='option_page' value='ewww_image_optimizer_options' />\n";
	$output[] = "<input type='hidden' name='action' value='update' />\n";
	$output[] = wp_nonce_field( 'ewww_image_optimizer_options-options', '_wpnonce', true, false ) . "\n";
	$output[] = "<div id='ewww-general-settings'>\n";
	$output[] = "<table class='form-table'>\n";
	if ( is_multisite() ) {
		$output[] = "<tr class='network-only'><th><label for='ewww_image_optimizer_allow_multisite_override'>" . esc_html__( 'Allow Single-site Override', 'ewww-image-optimizer' ) . "</label></th><td><input type='checkbox' id='ewww_image_optimizer_allow_multisite_override' name='ewww_image_optimizer_allow_multisite_override' value='true' " . ( get_site_option( 'ewww_image_optimizer_allow_multisite_override' ) == true ? "checked='true'" : '' ) . ' /> ' . esc_html__( 'Allow individual sites to configure their own settings and override all network options.', 'ewww-image-optimizer' ) . "</td></tr>\n";
		if ( 'network-multisite' == $network && get_site_option( 'ewww_image_optimizer_allow_multisite_override' ) ) {
			$output[] = "<tr><th><label for='ewww_image_optimizer_allow_tracking'>" . esc_html__( 'Allow Usage Tracking?', 'ewww-image-optimizer' ) . "</label></th><td><input type='checkbox' id='ewww_image_optimizer_allow_tracking' name='ewww_image_optimizer_allow_tracking' value='true' " . ( get_site_option( 'ewww_image_optimizer_allow_tracking' ) == true ? "checked='true'" : '' ) . ' /> ' .
				esc_html__( 'Allow EWWW Image Optimizer to anonymously track how this plugin is used and help us make the plugin better. Opt-in to tracking and receive 500 API image credits free. No sensitive data is tracked.', 'ewww-image-optimizer' ) . "</td></tr>\n";
			$output[] = "<input type='hidden' id='ewww_image_optimizer_allow_multisite_override_active' name='ewww_image_optimizer_allow_multisite_override_active' value='0'>";
			foreach ( $output as $line ) {
				echo $line;
			}
			echo '</table></div><!-- end container general settings -->';
			echo "<p class='submit'><input type='submit' class='button-primary' value='" . esc_attr__( 'Save Changes', 'ewww-image-optimizer' ) . "' /></p>\n";
			echo '</form></div><!-- end container left --></div><!-- end container wrap -->';
			return;
		}
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) ) {
		$blog_id = get_current_blog_id();
		$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_cloud_notkey'>" . esc_html__( 'Cloud optimization API Key', 'ewww-image-optimizer' ) . "</label></th><td><input type='text' id='ewww_image_optimizer_cloud_notkey' name='ewww_image_optimizer_cloud_notkey' readonly='readonly' value='****************************" . substr( ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ), 28 ) . "' size='32' /> <a href='admin.php?action=ewww_image_optimizer_remove_cloud_key&site=$blog_id'>" . esc_html__( 'Remove API key.', 'ewww-image-optimizer' ) . "</a></td></tr>\n";
		$output[] = "<input type='hidden' id='ewww_image_optimizer_cloud_key' name='ewww_image_optimizer_cloud_key' value='" . ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) . "' />\n";
	} else {
		$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_cloud_key'>" . esc_html__( 'Cloud optimization API Key', 'ewww-image-optimizer' ) . "</label></th><td><input type='text' id='ewww_image_optimizer_cloud_key' name='ewww_image_optimizer_cloud_key' value='' size='32' /> " . esc_html__( 'API Key will be validated when you save your settings.', 'ewww-image-optimizer' ) . " <a href='https://ewww.io/plans/'>" . esc_html__( 'Purchase an API key.', 'ewww-image-optimizer' ) . "</a></td></tr>\n";
	}
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_debug'>" . esc_html__( 'Debugging', 'ewww-image-optimizer' ) . "</label></th><td><input type='checkbox' id='ewww_image_optimizer_debug' name='ewww_image_optimizer_debug' value='true' " . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_debug' ) == true ? "checked='true'" : '' ) . ' /> ' . esc_html__( 'Use this to provide information for support purposes, or if you feel comfortable digging around in the code to fix a problem you are experiencing.', 'ewww-image-optimizer' ) . "</td></tr>\n";
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_jpegtran_copy'>" . esc_html__( 'Remove metadata', 'ewww-image-optimizer' ) . "</label></th>\n" .
		"<td><input type='checkbox' id='ewww_image_optimizer_jpegtran_copy' name='ewww_image_optimizer_jpegtran_copy' value='true' " . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpegtran_copy' ) == true ? "checked='true'" : '' ) . ' /> ' . esc_html__( 'This will remove ALL metadata: EXIF, comments, color profiles, and anything else that is not pixel data.', 'ewww-image-optimizer' ) . "</td></tr>\n";
	ewwwio_debug_message( 'remove metadata: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpegtran_copy' ) == true ? 'on' : 'off' ) );
	$output[] = "<tr class='$network_class'><th>&nbsp;</th><td>" .
		"<p class='$network_class description'>" . esc_html__( 'All methods used by the EWWW Image Optimizer are intended to produce visually identical images.', 'ewww-image-optimizer' ) .
		' ' . esc_html__( 'Lossless compression is actually identical to the original, while lossy reduces the quality a small amount.', 'ewww-image-optimizer' ) . "</p>\n" .
		( ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) ? "<p class='$network_class nocloud'><strong>* <a href='https://ewww.io/plans/' target='_blank'>" . esc_html__( 'Get an API key to achieve up to 80% compression and see the quality for yourself.', 'ewww-image-optimizer' ) . "</a></strong></p>\n" : '' );
	$output[] = "</td></tr>\n";
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_jpg_level'>" . esc_html__( 'JPG Optimization Level', 'ewww-image-optimizer' ) . "</label></th>\n" .
		"<td><span><select id='ewww_image_optimizer_jpg_level' name='ewww_image_optimizer_jpg_level'>\n" .
		"<option value='0'" . selected( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ), 0, false ) . '>' . esc_html__( 'No Compression', 'ewww-image-optimizer' ) . "</option>\n";
	if ( 'ewww-image-optimizer' !== 'ewww-image-optimizer-cloud' ) {
		$output[] = "<option class='$network_class' value='10'" . selected( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ), 10, false ) . '>' . esc_html__( 'Lossless Compression', 'ewww-image-optimizer' ) . "</option>\n";
	}
	$output[] = "<option class='$network_class' $disable_level value='20'" . selected( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ), 20, false ) . '>' . esc_html__( 'Maximum Lossless Compression', 'ewww-image-optimizer' ) . " *</option>\n" .
		"<option $disable_level value='30'" . selected( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ), 30, false ) . '>' . esc_html__( 'Lossy Compression', 'ewww-image-optimizer' ) . " *</option>\n" .
		"<option $disable_level value='40'" . selected( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ), 40, false ) . '>' . esc_html__( 'Maximum Lossy Compression', 'ewww-image-optimizer' ) . " *</option>\n" .
		"</select></td></tr>\n";
	ewwwio_debug_message( 'jpg level: ' . ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ) );
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_png_level'>" . esc_html__( 'PNG Optimization Level', 'ewww-image-optimizer' ) . "</label></th>\n" .
		"<td><span><select id='ewww_image_optimizer_png_level' name='ewww_image_optimizer_png_level'>\n" .
		"<option value='0'" . selected( ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ), 0, false ) . '>' . esc_html__( 'No Compression', 'ewww-image-optimizer' ) . "</option>\n";
	if ( 'ewww-image-optimizer' !== 'ewww-image-optimizer-cloud' ) {
		$output[] = "<option class='$network_class' value='10'" . selected( ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ), 10, false ) . '>' . esc_html__( 'Lossless Compression', 'ewww-image-optimizer' ) . "</option>\n";
	}
	$output[] = "<option class='$network_class' $disable_level value='20'" . selected( ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ), 20, false ) . '>' . esc_html__( 'Better Lossless Compression', 'ewww-image-optimizer' ) . " *</option>\n" .
		"<option $disable_level value='30'" . selected( ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ), 30, false ) . '>' . esc_html__( 'Maximum Lossless Compression', 'ewww-image-optimizer' ) . " *</option>\n" .
		"<option value='40'" . selected( ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ), 40, false ) . '>' . esc_html__( 'Lossy Compression', 'ewww-image-optimizer' ) . "</option>\n" .
		"<option $disable_level value='50'" . selected( ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ), 50, false ) . '>' . esc_html__( 'Maximum Lossy Compression', 'ewww-image-optimizer' ) . " *</option>\n" .
		"</select></td></tr>\n";
	ewwwio_debug_message( 'png level: ' . ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) );
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_gif_level'>" . esc_html__( 'GIF Optimization Level', 'ewww-image-optimizer' ) . "</label></th>\n" .
		"<td><span><select id='ewww_image_optimizer_gif_level' name='ewww_image_optimizer_gif_level'>\n" .
		"<option value='0'" . selected( ewww_image_optimizer_get_option( 'ewww_image_optimizer_gif_level' ), 0, false ) . '>' . esc_html__( 'No Compression', 'ewww-image-optimizer' ) . "</option>\n" .
		"<option value='10'" . selected( ewww_image_optimizer_get_option( 'ewww_image_optimizer_gif_level' ), 10, false ) . '>' . esc_html__( 'Lossless Compression', 'ewww-image-optimizer' ) . "</option>\n" .
		"</select></td></tr>\n";
	ewwwio_debug_message( 'gif level: ' . ewww_image_optimizer_get_option( 'ewww_image_optimizer_gif_level' ) );
	$disable_pdf_level = $disable_level;
	if ( 'getimagesize' == $mimetype_tool ) {
		$disable_pdf_level = "disabled='disabled'";
		$output[] = "<tr class='$network_class'><th>&nbsp;</th><td>";
		$output[] = "<p class='$network_class description'>" . esc_html__( '*PDF optimization cannot be enabled because the fileinfo extension is missing.', 'ewww-image-optimizer' ) . "</p></td></tr>\n";
	}
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_pdf_level'>" . esc_html__( 'PDF Optimization Level', 'ewww-image-optimizer' ) . "</label></th>\n" .
		"<td><span><select id='ewww_image_optimizer_pdf_level' name='ewww_image_optimizer_pdf_level'>\n" .
		"<option value='0'" . selected( ewww_image_optimizer_get_option( 'ewww_image_optimizer_pdf_level' ), 0, false ) . '>' . esc_html__( 'No Compression', 'ewww-image-optimizer' ) . "</option>\n" .
		"<option $disable_pdf_level value='10'" . selected( ewww_image_optimizer_get_option( 'ewww_image_optimizer_pdf_level' ), 10, false ) . '>' . esc_html__( 'Lossless Compression', 'ewww-image-optimizer' ) . " *</option>\n" .
		"<option $disable_pdf_level value='20'" . selected( ewww_image_optimizer_get_option( 'ewww_image_optimizer_pdf_level' ), 20, false ) . '>' . esc_html__( 'Lossy Compression', 'ewww-image-optimizer' ) . " *</option>\n" .
		"</select></td></tr>\n";
	ewwwio_debug_message( 'pdf level: ' . ewww_image_optimizer_get_option( 'ewww_image_optimizer_pdf_level' ) );
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_delay'>" . esc_html__( 'Bulk Delay', 'ewww-image-optimizer' ) . "</label></th><td><input type='text' id='ewww_image_optimizer_delay' name='ewww_image_optimizer_delay' size='5' value='" . ewww_image_optimizer_get_option( 'ewww_image_optimizer_delay' ) . "'> " . esc_html__( 'Choose how long to pause between images (in seconds, 0 = disabled)', 'ewww-image-optimizer' ) . "</td></tr>\n";
	ewwwio_debug_message( 'bulk delay: ' . ewww_image_optimizer_get_option( 'ewww_image_optimizer_delay' ) );
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_backup_files'>" . esc_html__( 'Backup Originals', 'ewww-image-optimizer' ) . "</label></th><td><input type='checkbox' id='ewww_image_optimizer_backup_files' name='ewww_image_optimizer_backup_files' value='true' " .
		( ewww_image_optimizer_get_option( 'ewww_image_optimizer_backup_files' ) ? "checked='true'" : '' ) . " $disable_level > " . esc_html__( 'Store a copy of your original images on our secure server for 30 days. *Requires an active API key.', 'ewww-image-optimizer' ) . "</td></tr>\n";
	ewwwio_debug_message( 'backup mode: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_backup_files' ) ? 'on' : 'off' ) );
	if ( class_exists( 'Cloudinary' ) && Cloudinary::config_get( 'api_secret' ) ) {
		$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_enable_cloudinary'>" . esc_html__( 'Automatic Cloudinary upload', 'ewww-image-optimizer' ) . "</label></th><td><input type='checkbox' id='ewww_image_optimizer_enable_cloudinary' name='ewww_image_optimizer_enable_cloudinary' value='true' " . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_enable_cloudinary' ) == true ? "checked='true'" : '' ) . ' /> ' . esc_html__( 'When enabled, uploads to the Media Library will be transferred to Cloudinary after optimization. Cloudinary generates resizes, so only the full-size image is uploaded.', 'ewww-image-optimizer' ) . "</td></tr>\n";
		ewwwio_debug_message( 'cloudinary upload: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_enable_cloudinary' ) == true ? 'on' : 'off' ) );
	}
	$output[] = "</table>\n</div>\n";
	$output[] = "<div id='ewww-optimization-settings'>\n";
	$output[] = "<table class='form-table'>\n";
	if ( ewww_image_optimizer_full_cloud() ) {
		$output[] = "<input class='$network_class' id='ewww_image_optimizer_optipng_level' name='ewww_image_optimizer_optipng_level' type='hidden' value='2'>\n" .
			"<input id='ewww_image_optimizer_pngout_level' name='ewww_image_optimizer_pngout_level' type='hidden' value='2'>\n" .
			"<input id='ewww_image_optimizer_disable_pngout' name='ewww_image_optimizer_disable_pngout' type='hidden' value='true'/>\n";
	} else {
		$optipng_levels = array( 0, 1, 8, 16, 24, 48 );
		$optipng_levels_output = '';
		foreach ( $optipng_levels as $level => $trials ) {
			if ( empty( $level ) ) {
				continue;
			}
			/* translators: %d: a number 0-5 */
			$optipng_levels_output .= "<option value='$level'" . selected( ewww_image_optimizer_get_option( 'ewww_image_optimizer_optipng_level' ), $level, false ) . '>' . sprintf( esc_html__( 'Level %d', 'ewww-image-optimizer' ), $level ) . ': ' .
				/* translators: %d: a number 1-48 */
				sprintf( esc_html( _n( '%d trial', '%d trials', $trials, 'ewww-image-optimizer' ) ), $trials ) . "</option>\n";
		}
		$output[] = "<tr class='$network_class nocloud'><th><label for='ewww_image_optimizer_optipng_level'>" . esc_html__( 'optipng optimization level', 'ewww-image-optimizer' ) . "</label></th>\n" .
			"<td><span><select id='ewww_image_optimizer_optipng_level' name='ewww_image_optimizer_optipng_level'>\n" .
			$optipng_levels_output .
			'</select> (' . esc_html__( 'default', 'ewww-image-optimizer' ) . "=2)</span>\n" .
			"<p class='description'>" . esc_html__( 'Levels 4 and above are unlikely to yield any additional savings.', 'ewww-image-optimizer' ) . "</p></td></tr>\n";
		ewwwio_debug_message( 'optipng level: ' . ewww_image_optimizer_get_option( 'ewww_image_optimizer_optipng_level' ) );
		$output[] = "<tr class='$network_class nocloud'><th><label for='ewww_image_optimizer_disable_pngout'>" . esc_html__( 'disable', 'ewww-image-optimizer' ) . " pngout</label></th><td><input type='checkbox' id='ewww_image_optimizer_disable_pngout' name='ewww_image_optimizer_disable_pngout' " . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_pngout' ) == true  ? "checked='true'" : '' ) . " /></td><tr>\n";
		ewwwio_debug_message( 'pngout disabled: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_pngout' ) == true ? 'yes' : 'no' ) );
		$pngout_levels = array(
			esc_html__( 'Xtreme! (Slowest)', 'ewww-image-optimizer' ),
			esc_html__( 'Intense (Slow)', 'ewww-image-optimizer' ),
			esc_html__( 'Longest Match (Fast)', 'ewww-image-optimizer' ),
			esc_html__( 'Huffman Only (Faster)', 'ewww-image-optimizer' ),
		);
		$pngout_levels_output = '';
		foreach ( $pngout_levels as $level => $description ) {
			/* translators: %d: a number 0-5 */
			$pngout_levels_output .= "<option value='$level'" . selected( ewww_image_optimizer_get_option( 'ewww_image_optimizer_pngout_level' ), $level, false ) . '>' . sprintf( esc_html__( 'Level %d', 'ewww-image-optimizer' ), $level ) . ": $description</option>\n";
		}
		$output[] = "<tr class='$network_class nocloud'><th><label for='ewww_image_optimizer_pngout_level'>" . esc_html__( 'pngout optimization level', 'ewww-image-optimizer' ) . "</label></th>\n" .
			"<td><span><select id='ewww_image_optimizer_pngout_level' name='ewww_image_optimizer_pngout_level'>\n" .
			$pngout_levels_output .
			'</select> (' . esc_html__( 'default', 'ewww-image-optimizer' ) . "=2)</span>\n" .
			"<p class='description'>" . esc_html__( 'If you have CPU cycles to spare, go with level 0', 'ewww-image-optimizer' ) . "</p></td></tr>\n";
		ewwwio_debug_message( 'pngout level: ' . ewww_image_optimizer_get_option( 'ewww_image_optimizer_pngout_level' ) );
	} // End if().
	$output[] = "<tr class='$network_class'><th><span><label for='ewww_image_optimizer_jpg_quality'>" . esc_html__( 'JPG quality level:', 'ewww-image-optimizer' ) . "</label></th><td><input type='text' id='ewww_image_optimizer_jpg_quality' name='ewww_image_optimizer_jpg_quality' class='small-text' value='" . ewww_image_optimizer_jpg_quality() . "' /> " . esc_html__( 'Valid values are 1-100.', 'ewww-image-optimizer' ) . "\n<p class='description'>" . esc_html__( 'Use this to override the default WordPress quality level of 82. Applies to image editing, resizing, PNG to JPG conversion, and JPG to WebP conversion. Does not affect the original uploaded image unless maximum dimensions are set and resizing occurs.', 'ewww-image-optimizer' ) . "</p></td></tr>\n";
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_parallel_optimization'>" . esc_html__( 'Parallel optimization', 'ewww-image-optimizer' ) . "</label></th><td><input type='checkbox' id='ewww_image_optimizer_parallel_optimization' name='ewww_image_optimizer_parallel_optimization' value='true' " . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_parallel_optimization' ) == true ? "checked='true'" : '' ) . ' /> ' . esc_html__( 'All resizes generated from a single upload are optimized in parallel for faster optimization. If this is causing performance issues, disable parallel optimization to reduce the load on your server.', 'ewww-image-optimizer' ) . "</td></tr>\n";
	ewwwio_debug_message( 'parallel optimization: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_parallel_optimization' ) == true ? 'on' : 'off' ) );
	ewwwio_debug_message( 'background optimization: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_background_optimization' ) == true ? 'on' : 'off' ) );
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_auto'>" . esc_html__( 'Scheduled optimization', 'ewww-image-optimizer' ) . "</label></th><td><input type='checkbox' id='ewww_image_optimizer_auto' name='ewww_image_optimizer_auto' value='true' " . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_auto' ) == true ? "checked='true'" : '' ) . ' /> ' . esc_html__( 'This will enable scheduled optimization of unoptimized images for your theme, buddypress, and any additional folders you have configured below. Runs hourly: wp_cron only runs when your site is visited, so it may be even longer between optimizations.', 'ewww-image-optimizer' ) . "</td></tr>\n";
	ewwwio_debug_message( 'scheduled optimization: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_auto' ) == true ? 'on' : 'off' ) );
	$media_include_disable = '';
	if ( get_option( 'ewww_image_optimizer_disable_resizes_opt' ) ) {
		$media_include_disable = 'disabled="disabled"';
		$output[] = "<tr class='$network_class'><th>&nbsp;</th><td>" .
			'<p><span style="color: green">' . esc_html__( '*Include Media Library Folders has been disabled because it will cause the scanner to ignore the disabled resizes.', 'ewww-image-optimizer' ) . "</span></p></td></tr>\n";
	}
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_include_media_paths'>" . esc_html__( 'Include Media Library Folders', 'ewww-image-optimizer' ) . "</label></th><td><input type='checkbox' id='ewww_image_optimizer_include_media_paths' name='ewww_image_optimizer_include_media_paths' $media_include_disable value='true' " . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_include_media_paths' ) == true && ! get_option( 'ewww_image_optimizer_disable_resizes_opt' ) ? "checked='true'" : '' ) . ' /> ' . esc_html__( 'Scan all images from the latest two folders of the Media Library during the Bulk Optimizer and Scheduled Optimization.', 'ewww-image-optimizer' ) . "</td></tr>\n";
	ewwwio_debug_message( 'include media library: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_include_media_paths' ) == true ? 'on' : 'off' ) );
	$aux_paths = ewww_image_optimizer_get_option( 'ewww_image_optimizer_aux_paths' ) ? esc_html( implode( "\n", ewww_image_optimizer_get_option( 'ewww_image_optimizer_aux_paths' ) ) ) : '';
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_aux_paths'>" . esc_html__( 'Folders to optimize', 'ewww-image-optimizer' ) . '</label></th><td>' .
		/* translators: %s: the folder where WordPress is installed */
		sprintf( esc_html__( 'One path per line, must be within %s. Use full paths, not relative paths.', 'ewww-image-optimizer' ), ABSPATH ) . "<br>\n" .
		"<textarea id='ewww_image_optimizer_aux_paths' name='ewww_image_optimizer_aux_paths' rows='3' cols='60'>$aux_paths</textarea>\n" .
		"<p class='description'>" . esc_html__( 'Provide paths containing images to be optimized using the Bulk Optimizer and Scheduled Optimization.', 'ewww-image-optimizer' ) . "</p></td></tr>\n";
	ewwwio_debug_message( 'folders to optimize:' );
	ewwwio_debug_message( $aux_paths );

	$exclude_paths = ewww_image_optimizer_get_option( 'ewww_image_optimizer_exclude_paths' ) ? esc_html( implode( "\n", ewww_image_optimizer_get_option( 'ewww_image_optimizer_exclude_paths' ) ) ) : '';
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_exclude_paths'>" . esc_html__( 'Folders to ignore', 'ewww-image-optimizer' ) . '</label></th><td>' . esc_html__( 'One path per line, partial paths allowed, but no urls.', 'ewww-image-optimizer' ) . "<br>\n" .
		"<textarea id='ewww_image_optimizer_exclude_paths' name='ewww_image_optimizer_exclude_paths' rows='3' cols='60'>$exclude_paths</textarea>\n" .
		"<p class='description'>" . esc_html__( 'A file that matches any pattern or path provided will not be optimized.', 'ewww-image-optimizer' ) . "</p></td></tr>\n";
	ewwwio_debug_message( 'folders to ignore:' );
	ewwwio_debug_message( $exclude_paths );

	// $output[] = "<tr><th><label for='ewww_image_optimizer_resize_detection'>" . esc_html__( 'Resize Detection', 'ewww-image-optimizer' ) . "</label></th><td><input type='checkbox' id='ewww_image_optimizer_resize_detection' name='ewww_image_optimizer_resize_detection' value='true' " . ( ewww_image_optimizer_get_option('ewww_image_optimizer_resize_detection') == TRUE ? "checked='true'" : "" ) . " /> " . esc_html__( 'Will highlight images that need to be resized because the browser is scaling them down. Only visible for Admin users and adds a button to the admin bar to detect scaled images that have been lazy loaded.', 'ewww-image-optimizer' ) . "</td></tr>\n";
	// ewwwio_debug_message( 'resize detection: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_resize_detection' ) == TRUE ? 'on' : 'off' ) );
	if ( function_exists( 'imsanity_get_max_width_height' ) ) {
		$output[] = "<tr class='$network_class'><th>&nbsp;</th><td>" .
			'<p><span style="color: green">' . esc_html__( '*Imsanity settings override the EWWW resize dimensions.', 'ewww-image-optimizer' ) . "</span></p></td></tr>\n";
	}
	$output[] = "<tr class='$network_class'><th>" . esc_html__( 'Resize Media Images', 'ewww-image-optimizer' ) . "</th><td><label for='ewww_image_optimizer_maxmediawidth'>" . esc_html__( 'Max Width', 'ewww-image-optimizer' ) . "</label> <input type='number' step='1' min='0' class='small-text' id='ewww_image_optimizer_maxmediawidth' name='ewww_image_optimizer_maxmediawidth' value='" . ewww_image_optimizer_get_option( 'ewww_image_optimizer_maxmediawidth' ) . ( function_exists( 'imsanity_get_max_width_height' ) ? "' disabled='disabled" : '' ) . "' /> <label for='ewww_image_optimizer_maxmediaheight'>" . esc_html__( 'Max Height', 'ewww-image-optimizer' ) . "</label> <input type='number' step='1' min='0' class='small-text' id='ewww_image_optimizer_maxmediaheight' name='ewww_image_optimizer_maxmediaheight' value='" . ewww_image_optimizer_get_option( 'ewww_image_optimizer_maxmediaheight' ) . ( function_exists( 'imsanity_get_max_width_height' ) ? "' disabled='disabled" : '' ) . "' /> " . esc_html__( 'in pixels', 'ewww-image-optimizer' ) . "\n" .
		"<p class='description'>" . esc_html__( 'Resizes images uploaded directly to the Media Library and those uploaded within a post or page.', 'ewww-image-optimizer' ) .
		"</td></tr>\n";
	ewwwio_debug_message( 'max media dimensions: ' . ewww_image_optimizer_get_option( 'ewww_image_optimizer_maxmediawidth' ) . ' x ' . ewww_image_optimizer_get_option( 'ewww_image_optimizer_maxmediaheight' ) );
	$output[] = "<tr class='$network_class'><th>" . esc_html__( 'Resize Other Images', 'ewww-image-optimizer' ) . "</th><td><label for='ewww_image_optimizer_maxotherwidth'>" . esc_html__( 'Max Width', 'ewww-image-optimizer' ) . "</label> <input type='number' step='1' min='0' class='small-text' id='ewww_image_optimizer_maxotherwidth' name='ewww_image_optimizer_maxotherwidth' value='" . ewww_image_optimizer_get_option( 'ewww_image_optimizer_maxotherwidth' ) . ( function_exists( 'imsanity_get_max_width_height' ) ? "' disabled='disabled" : '' ) . "' /> <label for='ewww_image_optimizer_maxotherheight'>" . esc_html__( 'Max Height', 'ewww-image-optimizer' ) . "</label> <input type='number' step='1' min='0' class='small-text' id='ewww_image_optimizer_maxotherheight' name='ewww_image_optimizer_maxotherheight' value='" . ewww_image_optimizer_get_option( 'ewww_image_optimizer_maxotherheight' ) . ( function_exists( 'imsanity_get_max_width_height' ) ? "' disabled='disabled" : '' ) . "' /> " . esc_html__( 'in pixels', 'ewww-image-optimizer' ) . "\n" .
		"<p class='description'>" . esc_html__( 'Resizes images uploaded indirectly to the Media Library, like theme images or front-end uploads.', 'ewww-image-optimizer' ) .
		"</td></tr>\n";
	ewwwio_debug_message( 'max other dimensions: ' . ewww_image_optimizer_get_option( 'ewww_image_optimizer_maxotherwidth' ) . ' x ' . ewww_image_optimizer_get_option( 'ewww_image_optimizer_maxotherheight' ) );
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_resize_existing'>" . esc_html__( 'Resize Existing Images', 'ewww-image-optimizer' ) . "</label></th><td><input type='checkbox' id='ewww_image_optimizer_resize_existing' name='ewww_image_optimizer_resize_existing' value='true' " . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_resize_existing' ) == true ? "checked='true'" : '' ) . ' /> ' . esc_html__( 'Allow resizing of existing Media Library images.', 'ewww-image-optimizer' ) . "</td></tr>\n";
	ewwwio_debug_message( 'resize existing images: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_resize_existing' ) == true ? 'on' : 'off' ) );

	$output[] = '<tr class="network-singlesite"><th>' . esc_html__( 'Disable Resizes', 'ewww-image-optimizer' ) . '</th><td><p>' . esc_html__( 'Wordpress, your theme, and other plugins generate various image sizes. You may disable optimization for certain sizes, or completely prevent those sizes from being created.', 'ewww-image-optimizer' ) . "</p>\n";
	$image_sizes = ewww_image_optimizer_get_image_sizes();
	$disabled_sizes = get_option( 'ewww_image_optimizer_disable_resizes' );
	$disabled_sizes_opt = get_option( 'ewww_image_optimizer_disable_resizes_opt' );
	$output[] = '<table><tr class="network-singlesite"><th>' . esc_html__( 'Disable Optimization', 'ewww-image-optimizer' ) . '</th><th>' . esc_html__( 'Disable Creation', 'ewww-image-optimizer' ) . "</th></tr>\n";
	ewwwio_debug_message( 'disabled resizes:' );
	foreach ( $image_sizes as $size => $dimensions ) {
		if ( 'thumbnail' == $size ) {
			$output [] = "<tr class='network-singlesite'><td><input type='checkbox' id='ewww_image_optimizer_disable_resizes_opt_$size' name='ewww_image_optimizer_disable_resizes_opt[$size]' value='true' " . ( ! empty( $disabled_sizes_opt[ $size ] ) ? "checked='true'" : '' ) . " /></td><td><input type='checkbox' id='ewww_image_optimizer_disable_resizes_$size' name='ewww_image_optimizer_disable_resizes[$size]' value='true' disabled /></td><td><label for='ewww_image_optimizer_disable_resizes_$size'>$size - {$dimensions['width']}x{$dimensions['height']}</label></td></tr>\n";
		} elseif ( 'pdf-full' == $size ) {
			$output [] = "<tr class='network-singlesite'><td><input type='checkbox' id='ewww_image_optimizer_disable_resizes_opt_$size' name='ewww_image_optimizer_disable_resizes_opt[$size]' value='true' " . ( ! empty( $disabled_sizes_opt[ $size ] ) ? "checked='true'" : '' ) . " /></td><td><input type='checkbox' id='ewww_image_optimizer_disable_resizes_$size' name='ewww_image_optimizer_disable_resizes[$size]' value='true' " . ( ! empty( $disabled_sizes[ $size ] ) ? "checked='true'" : '' ) . " /></td><td><label for='ewww_image_optimizer_disable_resizes_$size'>$size - <span class='description'>" . esc_html__( 'Disabling creation of the full-size preview for PDF files will disable all PDF preview sizes', 'ewww-image-optimizer' ) . "</span></label></td></tr>\n";
		} else {
			$output [] = "<tr class='network-singlesite'><td><input type='checkbox' id='ewww_image_optimizer_disable_resizes_opt_$size' name='ewww_image_optimizer_disable_resizes_opt[$size]' value='true' " . ( ! empty( $disabled_sizes_opt[ $size ] ) ? "checked='true'" : '' ) . " /></td><td><input type='checkbox' id='ewww_image_optimizer_disable_resizes_$size' name='ewww_image_optimizer_disable_resizes[$size]' value='true' " . ( ! empty( $disabled_sizes[ $size ] ) ? "checked='true'" : '' ) . " /></td><td><label for='ewww_image_optimizer_disable_resizes_$size'>$size - {$dimensions['width']}x{$dimensions['height']}</label></td></tr>\n";
		}
		ewwwio_debug_message( $size . ': ' . ( ! empty( $disabled_sizes_opt[ $size ] ) ? 'optimization=disabled ' : 'optimization=enabled ' ) . ( ! empty( $disabled_sizes[ $size ] ) ? 'creation=disabled' : 'creation=enabled' ) );
	}
	if ( 'network-multisite' != $network ) {
		$output[] = "</table>\n";
		$output[] = "</td></tr>\n";
	} else {
		$output[] = '<tr><th>' . esc_html__( 'Disable Resizes', 'ewww-image-optimizer' ) . '</th><td>';
		$output[] = '<p><span style="color: green">' . esc_html__( '*Settings to disable creation and optimization of individual sizes must be configured for each individual site.', 'ewww-image-optimizer' ) . "</span></p></td></tr>\n";
	}
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_skip_size'>" . esc_html__( 'Skip Small Images', 'ewww-image-optimizer' ) . "</label></th><td><input type='text' id='ewww_image_optimizer_skip_size' name='ewww_image_optimizer_skip_size' size='8' value='" . ewww_image_optimizer_get_option( 'ewww_image_optimizer_skip_size' ) . "'> " . esc_html__( 'Do not optimize images smaller than this (in bytes)', 'ewww-image-optimizer' ) . "</td></tr>\n";
	ewwwio_debug_message( 'skip images smaller than: ' . ewww_image_optimizer_get_option( 'ewww_image_optimizer_skip_size' ) . ' bytes' );
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_skip_png_size'>" . esc_html__( 'Skip Large PNG Images', 'ewww-image-optimizer' ) . "</label></th><td><input type='text' id='ewww_image_optimizer_skip_png_size' name='ewww_image_optimizer_skip_png_size' size='8' value='" . ewww_image_optimizer_get_option( 'ewww_image_optimizer_skip_png_size' ) . "'> " . esc_html__( 'Do not optimize PNG images larger than this (in bytes)', 'ewww-image-optimizer' ) . "</td></tr>\n";
	ewwwio_debug_message( 'skip PNG images larger than: ' . ewww_image_optimizer_get_option( 'ewww_image_optimizer_skip_png_size' ) . ' bytes' );
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_lossy_skip_full'>" . esc_html__( 'Exclude full-size images from lossy optimization', 'ewww-image-optimizer' ) . "</label></th><td><input type='checkbox' id='ewww_image_optimizer_lossy_skip_full' name='ewww_image_optimizer_lossy_skip_full' value='true' " . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_lossy_skip_full' ) == true ? "checked='true'" : '' ) . " /></td></tr>\n";
	ewwwio_debug_message( 'exclude originals from lossy: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_lossy_skip_full' ) == true ? 'on' : 'off' ) );
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_metadata_skip_full'>" . esc_html__( 'Exclude full-size images from metadata removal', 'ewww-image-optimizer' ) . "</label></th><td><input type='checkbox' id='ewww_image_optimizer_metadata_skip_full' name='ewww_image_optimizer_metadata_skip_full' value='true' " . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_metadata_skip_full' ) == true ? "checked='true'" : '' ) . " /></td></tr>\n";
	ewwwio_debug_message( 'exclude originals from metadata removal: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_metadata_skip_full' ) == true ? 'on' : 'off' ) );
	$output[] = "<tr class='$network_class nocloud'><th><label for='ewww_image_optimizer_skip_bundle'>" . esc_html__( 'Use System Paths', 'ewww-image-optimizer' ) . "</label></th><td><input type='checkbox' id='ewww_image_optimizer_skip_bundle' name='ewww_image_optimizer_skip_bundle' value='true' " . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_skip_bundle' ) == true ? "checked='true'" : '' ) . ' /> ' .
		/* translators: 1: an example folder where system binaries/executables are installed 2: another example folder */
		sprintf( esc_html__( 'If you have already installed the utilities in a system location, such as %1$s or %2$s, use this to force the plugin to use those versions and skip the auto-installers.', 'ewww-image-optimizer' ), '/usr/local/bin', '/usr/bin' ) . "</td></tr>\n";
	ewwwio_debug_message( 'use system binaries: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_skip_bundle' ) == true ? 'yes' : 'no' ) );
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_allow_tracking'>" . esc_html__( 'Allow Usage Tracking?', 'ewww-image-optimizer' ) . "</label></th><td><input type='checkbox' id='ewww_image_optimizer_allow_tracking' name='ewww_image_optimizer_allow_tracking' value='true' " . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_allow_tracking' ) == true ? "checked='true'" : '' ) . ' /> ' .
		esc_html__( 'Allow EWWW Image Optimizer to anonymously track how this plugin is used and help us make the plugin better. Opt-in to tracking and receive 500 API image credits free. No sensitive data is tracked.', 'ewww-image-optimizer' ) . "</td></tr>\n";
	$output[] = "</table>\n</div>\n";
	$output[] = "<div id='ewww-conversion-settings'>\n";
	$output[] = '<p>' . esc_html__( 'Conversion is only available for images in the Media Library (except WebP). By default, all images have a link available in the Media Library for one-time conversion. Turning on individual conversion operations below will enable conversion filters any time an image is uploaded or modified.', 'ewww-image-optimizer' ) . "<br />\n" .
		'<b>' . esc_html__( 'NOTE:', 'ewww-image-optimizer' ) . '</b> ' . esc_html__( 'The plugin will attempt to update image locations for any posts that contain the images. You may still need to manually update locations/urls for converted images.', 'ewww-image-optimizer' ) . "\n" .
		"</p>\n";
	$output[] = "<table class='form-table'>\n";
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_disable_convert_links'>" . esc_html__( 'Hide Conversion Links', 'ewww-image-optimizer' ) . "</label</th><td><input type='checkbox' id='ewww_image_optimizer_disable_convert_links' name='ewww_image_optimizer_disable_convert_links' " . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_disable_convert_links' ) == true ? "checked='true'" : '' ) . ' /> ' . esc_html__( 'Site or Network admins can use this to prevent other users from using the conversion links in the Media Library which bypass the settings below.', 'ewww-image-optimizer' ) . "</td></tr>\n";
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_delete_originals'>" . esc_html__( 'Delete originals', 'ewww-image-optimizer' ) . "</label></th><td><input type='checkbox' id='ewww_image_optimizer_delete_originals' name='ewww_image_optimizer_delete_originals' " . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_delete_originals' ) == true ? "checked='true'" : '' ) . ' /> ' . esc_html__( 'This will remove the original image from the server after a successful conversion.', 'ewww-image-optimizer' ) . "</td></tr>\n";
	ewwwio_debug_message( 'delete originals: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_delete_originals' ) == true ? 'on' : 'off' ) );
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_jpg_to_png'>" .
		/* translators: 1: JPG, GIF or PNG 2: JPG or PNG */
		sprintf( esc_html__( 'enable %1$s to %2$s conversion', 'ewww-image-optimizer' ), 'JPG', 'PNG' ) .
		"</label></th><td><span><input type='checkbox' id='ewww_image_optimizer_jpg_to_png' name='ewww_image_optimizer_jpg_to_png' " . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_to_png' ) == true ? "checked='true'" : '' ) . ' /> <b>' . esc_html__( 'WARNING:', 'ewww-image-optimizer' ) . '</b> ' . esc_html__( 'Removes metadata and increases cpu usage dramatically.', 'ewww-image-optimizer' ) . "</span>\n" .
		"<p class='description'>" . esc_html__( 'PNG is generally much better than JPG for logos and other images with a limited range of colors. Checking this option will slow down JPG processing significantly, and you may want to enable it only temporarily.', 'ewww-image-optimizer' ) . "</p></td></tr>\n";
	ewwwio_debug_message( 'jpg2png: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_to_png' ) == true ? 'on' : 'off' ) );
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_png_to_jpg'>" .
		/* translators: 1: JPG, GIF or PNG 2: JPG or PNG */
		sprintf( esc_html__( 'enable %1$s to %2$s conversion', 'ewww-image-optimizer' ), 'PNG', 'JPG' ) .
		"</label></th><td><span><input type='checkbox' id='ewww_image_optimizer_png_to_jpg' name='ewww_image_optimizer_png_to_jpg' " . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_to_jpg' ) == true ? "checked='true'" : '' ) . ' /> <b>' . esc_html__( 'WARNING:', 'ewww-image-optimizer' ) . '</b> ' . esc_html__( 'This is not a lossless conversion.', 'ewww-image-optimizer' ) . "</span>\n" .
		"<p class='description'>" . esc_html__( 'JPG is generally much better than PNG for photographic use because it compresses the image and discards data. PNGs with transparency are not converted by default.', 'ewww-image-optimizer' ) . "</p>\n" .
		"<span><label for='ewww_image_optimizer_jpg_background'> " . esc_html__( 'JPG background color:', 'ewww-image-optimizer' ) . "</label> #<input type='text' id='ewww_image_optimizer_jpg_background' name='ewww_image_optimizer_jpg_background' size='6' value='" . ewww_image_optimizer_jpg_background() . "' /> <span style='padding-left: 12px; font-size: 12px; border: solid 1px #555555; background-color: #" . ewww_image_optimizer_jpg_background() . "'>&nbsp;</span> " . esc_html__( 'HEX format (#123def)', 'ewww-image-optimizer' ) . ".</span>\n" .
		"<p class='description'>" . esc_html__( 'Background color is used only if the PNG has transparency. Leave this value blank to skip PNGs with transparency.', 'ewww-image-optimizer' ) . "</p></td></tr>\n";
	ewwwio_debug_message( 'png2jpg: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_to_jpg' ) == true ? 'on' : 'off' ) );
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_gif_to_png'>" .
		/* translators: 1: JPG, GIF or PNG 2: JPG or PNG */
		sprintf( esc_html__( 'enable %1$s to %2$s conversion', 'ewww-image-optimizer' ), 'GIF', 'PNG' ) .
		"</label></th><td><span><input type='checkbox' id='ewww_image_optimizer_gif_to_png' name='ewww_image_optimizer_gif_to_png' " . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_gif_to_png' ) == true ? "checked='true'" : '' ) . ' /> ' . esc_html__( 'No warnings here, just do it.', 'ewww-image-optimizer' ) . "</span>\n" .
		"<p class='description'> " . esc_html__( 'PNG is generally better than GIF, but animated images cannot be converted.', 'ewww-image-optimizer' ) . "</p></td></tr>\n";
	ewwwio_debug_message( 'gif2png: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_gif_to_png' ) == true ? 'on' : 'off' ) );
	$output[] = "</table>\n</div>\n";
	$output[] = "<div id='ewww-webp-settings'>\n";
	$output[] = "<table class='form-table'>\n";
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_webp'>" . esc_html__( 'JPG/PNG to WebP', 'ewww-image-optimizer' ) . "</label></th><td><span><input type='checkbox' id='ewww_image_optimizer_webp' name='ewww_image_optimizer_webp' value='true' " . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp' ) == true ? "checked='true'" : '' ) . ' /> <b>' . esc_html__( 'WARNING:', 'ewww-image-optimizer' ) . '</b> ' . esc_html__( 'JPG to WebP conversion is lossy, but quality loss is minimal. PNG to WebP conversion is lossless.', 'ewww-image-optimizer' ) . "</span>\n" .
		"<p class='description'>" . esc_html__( 'Originals are never deleted, and WebP images should only be served to supported browsers.', 'ewww-image-optimizer' ) . " <a href='#webp-rewrite'>" . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp' ) && ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_for_cdn' ) ? esc_html__( 'You can use the rewrite rules below to serve WebP images with Apache.', 'ewww-image-optimizer' ) : '' ) . "</a></td></tr>\n";
	ewwwio_debug_message( 'webp conversion: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp' ) == true ? 'on' : 'off' ) );
	$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_webp_force'>" . esc_html__( 'Force WebP', 'ewww-image-optimizer' ) . "</label></th><td><span><input type='checkbox' id='ewww_image_optimizer_webp_force' name='ewww_image_optimizer_webp_force' value='true' " . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_force' ) == true ? "checked='true'" : '' ) . ' /> ' . esc_html__( 'WebP images will be generated and saved for all JPG/PNG images regardless of their size. The Alternative WebP Rewriting will not check if a file exists, only that the domain matches the home url.', 'ewww-image-optimizer' ) . "</span></td></tr>\n";
	ewwwio_debug_message( 'forced webp: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_force' ) == true ? 'on' : 'off' ) );
	if ( ! ewww_image_optimizer_ce_webp_enabled() ) {
		$webp_paths = ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_paths' ) ? esc_html( implode( "\n", ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_paths' ) ) ) : '';
		$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_webp_paths'>" . esc_html__( 'WebP URLs', 'ewww-image-optimizer' ) . '</label></th><td>' . esc_html__( 'If Force WebP is enabled, enter URL patterns that should be permitted for Alternative WebP Rewriting. One pattern per line, may be partial URLs, but must include the domain name.', 'ewww-image-optimizer' ) . '<br>' .
			"<textarea id='ewww_image_optimizer_webp_paths' name='ewww_image_optimizer_webp_paths' rows='3' cols='60'>$webp_paths</textarea></td></tr>\n";
		ewwwio_debug_message( 'webp paths:' );
		ewwwio_debug_message( $webp_paths );
		$output[] = "<tr class='$network_class'><th><label for='ewww_image_optimizer_webp_for_cdn'>" .
			esc_html__( 'Alternative WebP Rewriting', 'ewww-image-optimizer' ) .
			"</label></th><td><span><input type='checkbox' id='ewww_image_optimizer_webp_for_cdn' name='ewww_image_optimizer_webp_for_cdn' value='true' " .
			( ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_for_cdn' ) == true ? "checked='true'" : '' ) . ' /> ' .
			esc_html__( 'Uses output buffering and libxml functionality from PHP. Use this if the Apache rewrite rules do not work, or if your images are served from a CDN.', 'ewww-image-optimizer' ) . ' ' .
			/* translators: %s: Cache Enabler (link) */
			sprintf( esc_html__( 'Sites using a CDN may also use the WebP option in the %s plugin.', 'ewww-image-optimizer' ), '<a href="https://wordpress.org/plugins/cache-enabler/">Cache Enabler</a>' ) . '</span></td></tr>';
	}
	ewwwio_debug_message( 'alt webp rewriting: ' . ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_for_cdn' ) == true ? 'on' : 'off' ) );
	$output[] = "</table>\n</div>\n";
	$output[] = "<p class='submit'><input type='submit' class='button-primary' value='" . esc_attr__( 'Save Changes', 'ewww-image-optimizer' ) . "' /></p>\n";
	$output[] = "</form>\n";
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp' ) && ! ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_for_cdn' ) && ! ewww_image_optimizer_ce_webp_enabled() ) {
		$output[] = "<form id='ewww-webp-rewrite'>\n";
		$output[] = '<p>' . esc_html__( 'There are many ways to serve WebP images to visitors with supported browsers. You may choose any you wish, but it is recommended to serve them with an .htaccess file using mod_rewrite and mod_headers. The plugin can insert the rules for you if the file is writable, or you can edit .htaccess yourself.', 'ewww-image-optimizer' ) . "</p>\n";
		if ( ! ewww_image_optimizer_webp_rewrite_verify() ) {
			$output[] = "<img id='webp-image' src='" . plugins_url( '/images/test.png', __FILE__ ) . "' style='float: right; padding: 0 0 10px 10px;'>\n" .
				"<p id='ewww-webp-rewrite-status'><b>" . esc_html__( 'Rules verified successfully', 'ewww-image-optimizer' ) . "</b></p>\n";
			ewwwio_debug_message( 'webp .htaccess rewriting enabled' );
		} else {
			$output[] = "<pre id='webp-rewrite-rules' style='background: white; font-color: black; border: 1px solid black; clear: both; padding: 10px;'>\n" .
				"&lt;IfModule mod_rewrite.c&gt;\n" .
				"RewriteEngine On\n" .
				"RewriteCond %{HTTP_ACCEPT} image/webp\n" .
				"RewriteCond %{REQUEST_FILENAME} (.*)\.(jpe?g|png)$\n" .
				"RewriteCond %{REQUEST_FILENAME}\.webp -f\n" .
				"RewriteCond %{QUERY_STRING} !type=original\n" .
				"RewriteRule (.+)\.(jpe?g|png)$ %{REQUEST_FILENAME}.webp [T=image/webp,E=accept:1,L]\n" .
				"&lt;/IfModule&gt;\n" .
				"&lt;IfModule mod_headers.c&gt;\n" .
				"Header append Vary Accept env=REDIRECT_accept\n" .
				"&lt;/IfModule&gt;\n" .
				"AddType image/webp .webp</pre>\n" .
				"<img id='webp-image' src='" . plugins_url( '/images/test.png', __FILE__ ) . "' style='float: right; padding-left: 10px;'>\n" .
				"<p id='ewww-webp-rewrite-status'>" . esc_html__( 'The image to the right will display a WebP image with WEBP in white text, if your site is serving WebP images and your browser supports WebP.', 'ewww-image-optimizer' ) . "</p>\n" .
				"<button type='submit' class='button-secondary action'>" . esc_html__( 'Insert Rewrite Rules', 'ewww-image-optimizer' ) . "</button>\n";
			ewwwio_debug_message( 'webp .htaccess rules not detected' );
		}
		$output[] = "</form>\n";
	} elseif ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_for_cdn' ) && ! ewww_image_optimizer_ce_webp_enabled() ) {
		$test_webp_image = plugins_url( '/images/test.png.webp', __FILE__ );
		$test_png_image = plugins_url( '/images/test.png', __FILE__ );
		$output[] = "<noscript  data-img='$test_png_image' data-webp='$test_webp_image' data-style='float: right; padding: 0 0 10px 10px;' class='ewww_webp'><img src='$test_png_image' style='float: right; padding: 0 0 10px 10px;'></noscript>\n";
	}
	$output[] = "</div><!-- end container left -->\n";
	$output[] = "<div id='ewww-container-right' style='border: 1px solid #e5e5e5; float: right; margin-left: -215px; padding: 0em 1.5em 1em; background-color: #fff; box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04); display: inline-block; width: 174px;'>\n" .
		'<h2>' . esc_html__( 'Support EWWW I.O.', 'ewww-image-optimizer' ) . "</h2>\n" .
		'<p>' . esc_html__( 'Would you like to help support development of this plugin?', 'ewww-image-optimizer' ) . "</p>\n" .
		"<p><a href='https://translate.wordpress.org/projects/wp-plugins/ewww-image-optimizer/'>" . esc_html__( 'Help translate EWWW I.O.', 'ewww-image-optimizer' ) . "</a></p>\n" .
		"<p><a href='https://wordpress.org/support/view/plugin-reviews/ewww-image-optimizer#postform'>" . esc_html__( 'Write a review.', 'ewww-image-optimizer' ) . "</a></p>\n" .
		/* translators: %s: Paypal (link) */
		'<p>' . sprintf( esc_html__( 'Contribute directly via %s.',  'ewww-image-optimizer' ), "<a href='https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&amp;hosted_button_id=MKMQKCBFFG3WW'>Paypal</a>" ) . "</p>\n" .
		'<p>' . esc_html__( 'Use any of these referral links to show your appreciation:', 'ewww-image-optimizer' ) . "</p>\n" .
		'<p><b>' . esc_html__( 'Web Hosting:', 'ewww-image-optimizer' ) . "</b><br>\n" .
		"<a href='http://www.a2hosting.com/?aid=b6322137'>A2 Hosting:</a> " . esc_html_x( 'with automatic EWWW IO setup', 'A2 Hosting:', 'ewww-image-optimizer' ) . "<br>\n" .
		"<a href='http://www.shareasale.com/r.cfm?b=394686&u=1481701&m=41388&urllink='>WP Engine</a><br>\n" .
		"</p>\n" .
		'<p><b>' . esc_html_x( 'VPS:', 'abbreviation for Virtual Private Server', 'ewww-image-optimizer' ) . "</b><br>\n" .
		"<a href='https://www.digitalocean.com/?refcode=89ef0197ec7e'>DigitalOcean</a><br>\n" .
		"</p>\n" .
		'<p><b>' . esc_html_x( 'CDN:', 'abbreviation for Content Delivery Network', 'ewww-image-optimizer' ) . "</b><br><a target='_blank' href='http://tracking.maxcdn.com/c/91625/36539/378'>" . esc_html__( 'Add MaxCDN to increase website speeds dramatically! Sign Up Now and Save 25%.', 'ewww-image-optimizer' ) . "</a></p>\n" .
		"</div>\n" .
		"</div>\n";
	ewwwio_debug_message( 'max_execution_time: ' . ini_get( 'max_execution_time' ) );
	ewww_image_optimizer_stl_check();
	if ( ! ewww_image_optimizer_function_exists( 'sleep' ) ) {
		ewwwio_debug_message( 'sleep disabled' );
	}
	if ( ! ewww_image_optimizer_function_exists( 'print_r' ) ) {
		ewwwio_debug_message( 'print_r disabled' );
	}
	ewwwio_check_memory_available();
	echo apply_filters( 'ewww_image_optimizer_settings', $output );
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_webp_for_cdn' ) && ! ewww_image_optimizer_ce_webp_enabled() ) {
		ewww_image_optimizer_webp_inline_script();
	}

	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_debug' ) ) {
	?>
<script type="text/javascript">
	function selectText(containerid) {
		if (document.selection) {
			var range = document.body.createTextRange();
			range.moveToElementText(document.getElementById(containerid));
			range.select();
		} else if (window.getSelection) {
			var range = document.createRange();
			range.selectNode(document.getElementById(containerid));
			window.getSelection().addRange(range);
		}
	}
</script>
		<?php
		global $ewww_debug;
		$debug_log_url = plugins_url( '/debug.log', __FILE__ );
		echo '<p style="clear:both"><b>' . esc_html__( 'Debugging Information', 'ewww-image-optimizer' ) . ':</b> <button onclick="selectText(' . "'ewww-debug-info'" . ')">' . esc_html__( 'Select All', 'ewww-image-optimizer' ) . '</button>';
		if ( is_file( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'debug.log' ) ) {
			echo "&emsp;<a href='$debug_log_url'>" . esc_html( 'View Debug Log', 'ewww-image-optimizer' ) . "</a> - <a href='admin.php?action=ewww_image_optimizer_delete_debug_log'>" . esc_html( 'Remove Debug Log', 'ewww-image-optimizer' ) . '</a>';
		}
		echo '</p>';
		echo '<div id="ewww-debug-info" style="background:#ffff99;margin-left:-20px;padding:10px" contenteditable="true">' . $ewww_debug . '</div>';
	}
	ewwwio_memory( __FUNCTION__ );
	ewww_image_optimizer_debug_log();
}

/**
 * Filters through the settings page to remove unneeded settings for the -cloud plugin.
 *
 * @param array $input The output of the settings page, broken up into an array.
 * @return string The filtered output for the settings page.
 */
function ewww_image_optimizer_filter_settings_page( $input ) {
	$output = '';
	foreach ( $input as $line ) {
		if ( ewww_image_optimizer_full_cloud() && preg_match( "/class='nocloud'/", $line ) ) {
			continue;
		} else {
			$output .= $line;
		}
	}
	ewwwio_memory( __FUNCTION__ );
	return $output;
}

/**
 * Filters through the multisite settings page to remove unneeded settings in multisite mode.
 *
 * @param array $input The output of the settings page, broken up into an array.
 * @return string The filtered output for the settings page.
 */
function ewww_image_optimizer_filter_network_settings_page( $input ) {
	$output = array();
	foreach ( $input as $line ) {
		if ( strpos( $line, 'network-singlesite' ) ) {
			continue;
		} else {
			$output[] = $line;
		}
	}
	ewwwio_memory( __FUNCTION__ );
	return $output;
}

/**
 * Filters through the single-site settings page to remove unneeded settings in multisite mode.
 *
 * @param array $input The output of the settings page, broken up into an array.
 * @return string The filtered output for the settings page.
 */
function ewww_image_optimizer_filter_network_singlesite_settings_page( $input ) {
	$output = array();
	foreach ( $input as $line ) {
		if ( ! get_site_option( 'ewww_image_optimizer_allow_multisite_override' ) && strpos( $line, 'network-multisite' ) ) {
			continue;
		} elseif ( strpos( $line, 'network-only' ) ) {
			continue;
		} else {
			$output[] = $line;
		}
	}
	ewwwio_memory( __FUNCTION__ );
	return $output;
}

/**
 * Removes the API key currently installed.
 */
function ewww_image_optimizer_remove_cloud_key() {
	$permissions = apply_filters( 'ewww_image_optimizer_admin_permissions', 'manage_options' );
	if ( false === current_user_can( $permissions ) ) {
		wp_die( esc_html__( 'Access denied.', 'ewww-image-optimizer' ) );
	}
	ewww_image_optimizer_set_option( 'ewww_image_optimizer_cloud_key', '' );
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_jpg_level' ) > 10 ) {
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_jpg_level', 10 );
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) > 10 && ewww_image_optimizer_get_option( 'ewww_image_optimizer_png_level' ) != 40 ) {
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_png_level', 10 );
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_pdf_level' ) > 0 ) {
		ewww_image_optimizer_set_option( 'ewww_image_optimizer_pdf_level', 0 );
	}
	ewww_image_optimizer_set_option( 'ewww_image_optimizer_backup_files', '' );
	$sendback = wp_get_referer();
	wp_redirect( esc_url_raw( $sendback ) );
	exit;
}

/**
 * Loads script to detect scaled images within the page, only enabled for admins.
 */
function ewww_image_optimizer_resize_detection_script() {
	if ( ! current_user_can( apply_filters( 'ewww_image_optimizer_admin_permissions', 'edit_others_posts' ) ) || 'wp-login.php' == basename( $_SERVER['SCRIPT_NAME'] ) ) {
		return;
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_resize_detection' ) ) {
		wp_enqueue_script( 'ewww-resize-detection', plugins_url( '/includes/resize_detection.js', __FILE__ ), array(), EWWW_IMAGE_OPTIMIZER_VERSION );
	}
}

/**
 * Checks if admin bar is visible, and then adds the admin_bar_menu action.
 */
function ewww_image_optimizer_admin_bar_init() {
	if ( ! current_user_can( apply_filters( 'ewww_image_optimizer_admin_permissions', 'edit_others_posts' ) ) || ! is_admin_bar_showing() || 'wp-login.php' == basename( $_SERVER['SCRIPT_NAME'] ) || is_admin() ) {
		return;
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_resize_detection' ) ) {
		add_action( 'admin_bar_menu', 'ewww_image_optimizer_admin_bar_menu', 99 );
	}
}

/**
 * Adds a resize detection button to the wp admin bar.
 */
function ewww_image_optimizer_admin_bar_menu() {
	global $wp_admin_bar;
	$wp_admin_bar->add_menu( array(
		'id'     => 'resize-detection',
		'parent' => 'top-secondary',
		'title'  => __( 'Detect Scaled Images', 'ewww-image-optimizer' ),
	) );
}

/**
 * Adds information to the in-memory debug log.
 *
 * @global string $ewww_debug The in-memory debug log.
 *
 * @param string $message Debug information to add to the log.
 */
function ewwwio_debug_message( $message ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::debug( $message );
		return;
	}
	if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_debug' ) ) {
		$memory_limit = ewwwio_memory_limit();
		if ( strlen( $message ) + 4000000 + memory_get_usage( true ) <= $memory_limit ) {
			global $ewww_debug;
			global $ewww_version_dumped;
			if ( empty( $ewww_debug ) && empty( $ewww_version_dumped ) ) {
				ewwwio_debug_version_info();
				$ewww_version_dumped = true;
			}
			$message = str_replace( "\n\n\n", '<br>', $message );
			$message = str_replace( "\n\n", '<br>', $message );
			$message = str_replace( "\n", '<br>', $message );
			$ewww_debug .= "$message<br>";
		} else {
			global $ewww_debug;
			$ewww_debug = "not logging message, memory limit is $memory_limit";
		}
	}
}

/**
 * Saves the in-memory debug log to a logfile in the plugin folder.
 *
 * @global string $ewww_debug The in-memory debug log.
 */
function ewww_image_optimizer_debug_log() {
	global $ewww_debug;
	if ( ! empty( $ewww_debug ) && ewww_image_optimizer_get_option( 'ewww_image_optimizer_debug' ) ) {
		$memory_limit = ewwwio_memory_limit();
		clearstatcache();
		$timestamp = date( 'y-m-d h:i:s.u' ) . "\n";
		if ( ! file_exists( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'debug.log' ) ) {
			touch( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'debug.log' );
		} else {
			if ( filesize( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'debug.log' ) + 4000000 + memory_get_usage( true ) > $memory_limit ) {
				unlink( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'debug.log' );
				touch( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'debug.log' );
			}
		}
		if ( filesize( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'debug.log' ) + strlen( $ewww_debug ) + 4000000 + memory_get_usage( true ) <= $memory_limit ) {
			$ewww_debug_log = str_replace( '<br>', "\n", $ewww_debug );
			file_put_contents( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'debug.log', $timestamp . $ewww_debug_log, FILE_APPEND );
		}
	}
	$ewww_debug = '';
	ewwwio_memory( __FUNCTION__ );
}

/**
 * Removes the debug.log file from the plugin folder.
 */
function ewww_image_optimizer_delete_debug_log() {
	$permissions = apply_filters( 'ewww_image_optimizer_admin_permissions', 'manage_options' );
	if ( false === current_user_can( $permissions ) ) {
		wp_die( esc_html__( 'Access denied.', 'ewww-image-optimizer' ) );
	}
	if ( is_file( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'debug.log' ) ) {
		unlink( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'debug.log' );
	}
	$sendback = wp_get_referer();
	wp_redirect( esc_url_raw( $sendback ) );
	exit;
}

/**
 * Adds version information to the in-memory debug log.
 *
 * @global string $ewww_debug The in-memory debug log.
 * @global int $wp_version
 */
function ewwwio_debug_version_info() {
	global $ewww_debug;
	if ( ! extension_loaded( 'suhosin' ) && function_exists( 'get_current_user' ) ) {
		$ewww_debug .= get_current_user() . '<br>';
	}

	$ewww_debug .= 'EWWW IO version: ' . EWWW_IMAGE_OPTIMIZER_VERSION . '<br>';

	// Check the WP version.
	global $wp_version;
	$my_version = substr( $wp_version, 0, 3 );
	$ewww_debug .= "WP version: $wp_version<br>" ;

	if ( defined( 'PHP_VERSION_ID' ) ) {
		$ewww_debug .= 'PHP version: ' . PHP_VERSION_ID . '<br>';
	}
	if ( defined( 'LIBXML_VERSION' ) ) {
		$ewww_debug .= 'libxml version: ' . LIBXML_VERSION . '<br>';
	}
	if ( ! empty( $_ENV['PANTHEON_ENVIRONMENT'] ) && in_array( $_ENV['PANTHEON_ENVIRONMENT'], array( 'test', 'live', 'dev' ) ) ) {
		$ewww_debug .= "detected pantheon env: {$_ENV['PANTHEON_ENVIRONMENT']}<br>";
	}
}

/**
 * Generate and cleanup a PHP backtrace.
 *
 * @return string A serialized backtrace, suitable for database storage.
 */
function ewwwio_debug_backtrace() {
	if ( defined( 'DEBUG_BACKTRACE_IGNORE_ARGS' ) ) {
		$backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
	} else {
		$backtrace = debug_backtrace( false );
	}
	array_shift( $backtrace );
	array_shift( $backtrace );
	return maybe_serialize( $backtrace );
}

/**
 * Displays backtraces and information on images that have been optimized multiple times.
 *
 * @global object $wpdb
 * @global object $ewwwdb A clone of $wpdb unless it is lacking utf8 connectivity.
 */
function ewww_image_optimizer_dynamic_image_debug() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	echo '<div class="wrap"><h1>' . esc_html__( 'Dynamic Image Debugging', 'ewww-image-optimizer' ) . '</h1>';
	global $wpdb;
	if ( strpos( $wpdb->charset, 'utf8' ) === false ) {
		ewww_image_optimizer_db_init();
		global $ewwwdb;
	} else {
		$ewwwdb = $wpdb;
	}
	$debug_images = $ewwwdb->get_results( "SELECT path,updates,updated,trace FROM $ewwwdb->ewwwio_images WHERE trace IS NOT NULL ORDER BY updates DESC LIMIT 100" );
	if ( count( $debug_images ) != 0 ) {
		foreach ( $debug_images as $image ) {
			$trace = unserialize( $image->trace );
			echo '<p><b>' . esc_html__( 'File path', 'ewww-image-optimizer' ) . ': ' . $image->path . '</b><br>';
			echo esc_html__( 'Number of attempted optimizations', 'ewww-image-optimizer' ) . ': ' . $image->updates . '<br>';
			echo esc_html__( 'Last attempted', 'ewww-image-optimizer' ) . ': ' . $image->updated . '<br>';
			echo esc_html__( 'PHP trace', 'ewww-image-optimizer' ) . ':<br>';
			$i = 0;
			if ( is_array( $trace ) ) {
				foreach ( $trace as $function ) {
					if ( ! empty( $function['file'] ) && ! empty( $function['line'] ) ) {
						echo "#$i {$function['function']}() called at {$function['file']}:{$function['line']}<br>";
					} else {
						echo "#$i {$function['function']}() called<br>";
					}
					$i++;
				}
			} else {
				esc_html_e( 'Cannot display trace',  'ewww-image-optimizer' );
			}
			echo '</p>';
		}
	}
	echo '</div>';
}

/**
 * Displays images that are in the optimization queue.
 *
 * Allows viewing the image queues for debugging, and lets the user clear all queues.
 *
 * @global object $wpdb
 * @global object $ewwwio_media_background Background optimization class object.
 */
function ewww_image_optimizer_image_queue_debug() {
	ewwwio_debug_message( '<b>' . __FUNCTION__ . '()</b>' );
	// Let user clear a queue, or all queues.
	if ( isset( $_POST['ewww_image_optimizer_clear_queue'] ) && current_user_can( 'manage_options' ) && wp_verify_nonce( $_POST['ewww_nonce'], 'ewww_image_optimizer_clear_queue' ) ) {
		if ( is_numeric( $_POST['ewww_image_optimizer_clear_queue'] ) ) {
			global $ewwwio_media_background;
			if ( ! class_exists( 'WP_Background_Process' ) ) {
				require_once( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'background.php' );
			}
			if ( ! is_object( $ewwwio_media_background ) ) {
				$ewwwio_media_background = new EWWWIO_Media_Background_Process();
			}
			$queues = (int) $_POST['ewww_image_optimizer_clear_queue'];
			while ( $queues ) {
				$ewwwio_media_background->cancel_process();
				$queues--;
			}
			if ( ! empty( $_POST['ids'] ) && preg_match( '/^[\d,]+$/', $_POST['ids'], $request_ids ) ) {
				$ids = explode( ',', $request_ids[0] );
				foreach ( $ids as $id ) {
					delete_transient( 'ewwwio-background-in-progress-' . $id );
				}
			}
		} else {
			delete_site_option( sanitize_text_field( $_POST['ewww_image_optimizer_clear_queue'] ) );
			if ( ! empty( $_POST['ids'] ) && preg_match( '/^[\d,]+$/', $_POST['ids'], $request_ids ) ) {
				$ids = explode( ',', $request_ids[0] );
				foreach ( $ids as $id ) {
					delete_transient( 'ewwwio-background-in-progress-' . $id );
				}
			}
		}
	}
	echo "<div class='wrap'><h1>" . esc_html__( 'Image Queue Debugging', 'ewww-image-optimizer' ) . '</h1>';
	global $wpdb;

	$table        = $wpdb->options;

	$key = 'wp_ewwwio_media_optimize_batch_%';
	$queues = $wpdb->get_results(
		$wpdb->prepare( "
			SELECT *
			FROM $wpdb->options
			WHERE option_name LIKE %s AND option_value != ''
			ORDER BY option_id ASC
			", $key ),
		ARRAY_A
	);

	$nonce = wp_create_nonce( 'ewww_image_optimizer_clear_queue' );
	if ( empty( $queues ) ) {
		esc_html_e( 'Nothing to see here, go upload some images!', 'ewww-image-optimizer' );
	} else {
		$all_ids = array();
		foreach ( $queues as $queue ) {
			$ids = array();
			echo "<strong>{$queue['option_id']}</strong> - {$queue['option_name']}<br>";
			$items = maybe_unserialize( $queue['option_value'] );
			foreach ( $items as $item ) {
				echo "{$item['id']} - {$item['type']}<br>";
				$all_ids[] = $item['id'];
				$ids[] = $item['id'];
			}
			$ids = implode( ',', $ids );
		?>	<form id="ewww-queue-clear-<?php echo $queue['option_id']; ?>" method="post" style="margin-bottom: 1.5em;" action="">
			<input type="hidden" id="ewww_nonce" name="ewww_nonce" value="<?php echo $nonce; ?>">
			<input type="hidden" name="ewww_image_optimizer_clear_queue" value="<?php echo $queue['option_name']; ?>">
			<input type="hidden" name="ids" value="<?php echo $ids; ?>">
			<button type="submit" class="button-secondary action"><?php esc_html_e( 'Clear this queue', 'ewww-image-optimizer' ); ?></button>
		</form>
<?php		}
		$all_ids = implode( ',', $all_ids );
?>		<form id="ewww-queue-clear-all" method="post" style="margin: 2em 0;" action="">
			<input type="hidden" id="ewww_nonce" name="ewww_nonce" value="<?php echo $nonce; ?>">
			<input type="hidden" name="ewww_image_optimizer_clear_queue" value="<?php echo count( $queues ); ?>">
			<input type="hidden" name="ids" value="<?php echo $all_ids; ?>">
			<button type="submit" class="button-secondary action"><?php esc_html_e( 'Clear all queues', 'ewww-image-optimizer' ); ?></button>
		</form>
<?php	}
}

/**
 * Finds the current PHP memory limit or a reasonable default.
 *
 * @return int The memory limit in bytes.
 */
function ewwwio_memory_limit() {
	if ( defined( 'EWWW_MEMORY_LIMIT' ) && EWWW_MEMORY_LIMIT ) {
		$memory_limit = EWWW_MEMORY_LIMIT;
	} elseif ( function_exists( 'ini_get' ) ) {
		$memory_limit = ini_get( 'memory_limit' );
	} else {
		if ( ! defined( 'EWWW_MEMORY_LIMIT' ) ) {
			// Conservative default, current usage + 16M.
			$current_memory = memory_get_usage( true );
			$memory_limit = round( $current_memory / ( 1024 * 1024 ) ) + 16;
			define( 'EWWW_MEMORY_LIMIT', $memory_limit );
		}
	}
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::debug( "memory limit is set at $memory_limit" );
	}
	if ( ! $memory_limit || -1 == $memory_limit ) {
		// Unlimited, set to 32GB.
		$memory_limit = '32000M';
	}
	if ( strpos( $memory_limit, 'G' ) ) {
		$memory_limit = intval( $memory_limit ) * 1024 * 1024 * 1024;
	} else {
		$memory_limit = intval( $memory_limit ) * 1024 * 1024;
	}
	return $memory_limit;
}

/**
 * Checks if there is enough memory still available.
 *
 * Looks to see if the current usage + padding will fit within the memory_limit defined by PHP.
 *
 * @param int $padding Optional. The amount of memory needed to continue. Default 1050000.
 * @return True to proceed, false if there is not enough memory.
 */
function ewwwio_check_memory_available( $padding = 1050000 ) {
	$memory_limit = ewwwio_memory_limit();

	$current_memory = memory_get_usage( true ) + $padding;
	if ( $current_memory >= $memory_limit ) {
		ewwwio_debug_message( "detected memory limit is not enough: $memory_limit" );
		return false;
	}
	ewwwio_debug_message( "detected memory limit is: $memory_limit" );
	return true;
}

/**
 * Logs memory usage stats. Disabled normally.
 *
 * @global string $ewww_memory An buffer of memory stat messages.
 *
 * @param string $function The name of the function or descriptive label.
 */
function ewwwio_memory( $function ) {
	return;
	if ( WP_DEBUG ) {
		global $ewww_memory;
		$ewww_memory .= $function . ': ' . memory_get_usage( true ) . "\n";
		ewwwio_memory_output();
	}
}

/**
 * Saves the memory stats to a log file.
 *
 * @global string $ewww_memory An buffer of memory stat messages.
 */
function ewwwio_memory_output() {
	if ( WP_DEBUG ) {
		global $ewww_memory;
		$timestamp = date( 'y-m-d h:i:s.u' ) . '  ';
		if ( ! file_exists( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'memory.log' ) ) {
			touch( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'memory.log' );
		}
		file_put_contents( EWWW_IMAGE_OPTIMIZER_PLUGIN_PATH . 'memory.log', $timestamp . $ewww_memory, FILE_APPEND );
		$ewww_memory = '';
	}
}
?>
