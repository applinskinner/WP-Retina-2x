<?php
/*
Plugin Name: WP Retina 2x
Plugin URI: http://meowapps.com
Description: Make your website look beautiful and crisp on modern displays by creating + displaying retina images. WP 4.4 is also supported and enhanced.
Version: 4.7.7
Author: Jordy Meow
Author URI: http://meowapps.com
Text Domain: wp-retina-2x
Domain Path: /languages

Dual licensed under the MIT and GPL licenses:
http://www.opensource.org/licenses/mit-license.php
http://www.gnu.org/licenses/gpl.html

Originally developed for two of my websites:
- Jordy Meow (http://jordymeow.com)
- Haikyo (http://www.haikyo.org)
*/

/**
 *
 * @author      Jordy Meow  <http://meowapps.com>
 * @package     Wordpress
 * @subpackage	Administration
 *
 */

$wr2x_version = '4.7.7';
$wr2x_retinajs = '2.0.0';
$wr2x_picturefill = '3.0.2';
$wr2x_lazysizes = '2.0.3';
$wr2x_retina_image = '1.7.2';
$wr2x_extra_debug = false;

//add_action( 'admin_menu', 'wr2x_admin_menu' );
add_action( 'wp_enqueue_scripts', 'wr2x_wp_enqueue_scripts' );
add_action( 'admin_enqueue_scripts', 'wr2x_wp_enqueue_scripts' );
add_filter( 'wp_generate_attachment_metadata', 'wr2x_wp_generate_attachment_metadata' );
add_action( 'delete_attachment', 'wr2x_delete_attachment' );
add_filter( 'generate_rewrite_rules', array( 'WR2X_Admin', 'generate_rewrite_rules' ) );
add_filter( 'wr2x_validate_src', 'wr2x_validate_src' );
add_action( 'init', 'wr2x_init' );

register_deactivation_hook( __FILE__, 'wr2x_deactivate' );
register_activation_hook( __FILE__, 'wr2x_activate' );

global $wr2x_admin;
require( 'wr2x_admin.php');
$wr2x_admin = new WR2X_Admin();

if ( is_admin() ) {
	require( 'wr2x_ajax.php' );
	if ( !get_option( "wr2x_hide_retina_dashboard" ) )
		require( 'wr2x_retina-dashboard.php' );
	if ( !get_option( "wr2x_hide_retina_column" ) )
		require( 'wr2x_media-library.php' );
}

require( 'wr2x_responsive.php' );
require( 'vendor/autoload.php' );

use DiDom\Document;

// if ( get_option( "ignore_mobile" ) && !class_exists( 'Mobile_Detect' ) )
// 	require( 'inc/Mobile_Detect.php');

//if ( !get_option( "wr2x_hide_retina_column" ) )
//require( 'wr2x_retina_uploader.php' );

function wr2x_init() {
	load_plugin_textdomain( 'wp-retina-2x', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

	if ( get_option( 'wr2x_disable_medium_large' ) ) {
		remove_image_size( 'medium_large' );
		add_filter( 'image_size_names_choose', 'wr2x_unset_medium_large' );
		add_filter( 'intermediate_image_sizes_advanced', 'wr2x_unset_medium_large' );
	}

	if ( is_admin() ) {
		wp_register_style( 'wr2x-admin-css', plugins_url( '/wr2x_admin.css', __FILE__ ) );
		wp_enqueue_style( 'wr2x-admin-css' );
		if ( !get_option( "wr2x_retina_admin" ) )
			return;
	}

	$method = get_option( "wr2x_method" );
	if ( $method == "Picturefill" ) {
		add_action( 'wp_head', 'wr2x_picture_buffer_start' );
		add_action( 'wp_footer', 'wr2x_picture_buffer_end' );
	}
	else if ( $method == 'HTML Rewrite' ) {
		$is_retina = false;
		if ( isset( $_COOKIE['devicePixelRatio'] ) ) {
			$is_retina = ceil( floatval( $_COOKIE['devicePixelRatio'] ) ) > 1;
			// if ( get_option( "wr2x_ignore_mobile" ) ) {
			// 	$mobileDetect = new Mobile_Detect();
			// 	$is_retina = !$mobileDetect->isMobile();
			// }
		}
		if ( $is_retina || wr2x_is_debug() ) {
			add_action( 'wp_head', 'wr2x_buffer_start' );
			add_action( 'wp_footer', 'wr2x_buffer_end' );
		}
	}

}

function wr2x_unset_medium_large( $sizes ) {
	unset( $sizes['medium_large'] );
	return $sizes;
}

/**
 *
 * PICTURE METHOD
 *
 */



function wr2x_is_supported_image( $url ) {
	$wr2x_supported_image = array( 'jpg', 'jpeg', 'png', 'gif' );
	$ext = strtolower( pathinfo( $url, PATHINFO_EXTENSION ) );
	if ( !in_array( $ext, $wr2x_supported_image ) ) {
		wr2x_log( "Extension (" . $ext . ") is not " . implode( ', ', $wr2x_supported_image ) . "." );
		return false;
	}
	return true;
}

function wr2x_picture_buffer_start () {
	ob_start( "wr2x_picture_rewrite" );
	wr2x_log( "* HTML REWRITE FOR PICTUREFILL" );
}

function wr2x_picture_buffer_end () {
	ob_end_flush();
}

// Replace the IMG tags by PICTURE tags with SRCSET
function wr2x_picture_rewrite( $buffer ) {
	global $wr2x_admin;
	if ( !isset( $buffer ) || trim( $buffer ) === '' )
		return $buffer;

	$lazysize = get_option( "wr2x_picturefill_lazysizes" ) && $wr2x_admin->is_pro();
	$killsrc = !get_option( "wr2x_picturefill_keep_src" );
	$nodes_count = 0;
	$nodes_replaced = 0;
	$html = new Document( $buffer );

	// IMG TAGS
	foreach( $html->find( 'img' ) as $element ) {
		$nodes_count++;
		$parent = $element->parent();
		if ( $parent->tag == "picture" ) {
			wr2x_log("The img tag is inside a picture tag. Tag ignored.");
			continue;
		}
		else {
			$valid = apply_filters( "wr2x_validate_src", $element->src );
			if ( empty( $valid ) ) {
				$nodes_count--;
				continue;
			}

			// Original HTML
			$from = substr( $element, 0 );

			// SRC-SET already exists, let's check if LazySize is used
			if ( !empty( $element->srcset ) ) {
				if ( $lazysize ) {
					wr2x_log( "The src-set has already been created but it will be modifid to data-srcset for lazyload." );
					$element->class = $element->class . ' lazyload';
					$element->{'data-srcset'} =  $element->srcset;
					$element->srcset = null;
					if ( $killsrc )
						$element->src = null;
					$to = $element;
					wr2x_log( "The img tag '$from' was rewritten to '$to'" );
					$nodes_replaced++;
				}
				else {
					wr2x_log( "The src-set has already been created. Tag ignored." );
				}
				continue;
			}

			// Process of SRC-SET creation
			if ( !wr2x_is_supported_image( $element->src ) ) {
				$nodes_count--;
				continue;
			}
			$retina_url = wr2x_get_retina_from_url( $element->src );
			$retina_url = apply_filters( 'wr2x_img_retina_url', $retina_url );
			if ( $retina_url != null ) {
				$retina_url = wr2x_cdn_this( $retina_url );
				$img_url = wr2x_cdn_this( $element->src );
				$img_url  = apply_filters( 'wr2x_img_url', $img_url  );
				if ( $lazysize ) {
					$element->class = $element->class . ' lazyload';
					$element->{'data-srcset'} =  "$img_url, $retina_url 2x";
				}
				else
					$element->srcset = "$img_url, $retina_url 2x";
				if ( $killsrc )
					$element->src = null;
				else {
					$img_src = apply_filters( 'wr2x_img_src', $element->src  );
					$element->src = wr2x_cdn_this( $img_src );
				}
				$to = $element;
				wr2x_log( "The img tag '$from' was rewritten to '$to'" );
				$nodes_replaced++;
			}
			else {
				wr2x_log( "The img tag was not rewritten. No retina for '" . $element->src . "'." );
			}
		}
	}
	wr2x_log( "$nodes_replaced/$nodes_count img tags were replaced." );

	$buffer = $html->getDocument()->saveHTML();

	// INLINE CSS BACKGROUND
	if ( get_option( 'wr2x_picturefill_css_background', false ) && $wr2x_admin->is_pro() ) {
		preg_match_all( "/url(?:\(['\"]?)(.*?)(?:['\"]?\))/", $buffer, $matches );
		$match_css = $matches[0];
		$match_url = $matches[1];
		if ( count( $matches ) != 2 )
			return $buffer;
		$nodes_count = 0;
		$nodes_replaced = 0;
		for ( $c = 0; $c < count( $matches[0] ); $c++ ) {
			$css = $match_css[$c];
			$url = $match_url[$c];
			if ( !wr2x_is_supported_image( $url ) )
				continue;
			$nodes_count++;
			$retina_url = wr2x_get_retina_from_url( $url );
			$retina_url = apply_filters( 'wr2x_img_retina_url', $retina_url );
			if ( $retina_url != null ) {
				$retina_url = wr2x_cdn_this( $retina_url );
				$to = $element;
				$minibuffer = str_replace( $url, $retina_url, $css );
				$buffer = str_replace( $css, $minibuffer, $buffer );
				wr2x_log( "The background src '$css' was rewritten to '$minibuffer'" );
				$nodes_replaced++;
			}
			else {
				wr2x_log( "The background src was not rewritten. No retina for '" . $url . "'." );
			}
		}
		wr2x_log( "$nodes_replaced/$nodes_count background src were replaced." );
	}

	return $buffer;
}

/**
 *
 * HTML REWRITE METHOD
 *
 */

function wr2x_buffer_start () {
	ob_start( "wr2x_html_rewrite" );
	wr2x_log( "* HTML REWRITE" );
}

function wr2x_buffer_end () {
	ob_end_flush();
}

// Replace the images by retina images (if available)
function wr2x_html_rewrite( $buffer ) {
	if ( !isset( $buffer ) || trim( $buffer ) === '' )
		return $buffer;
	$nodes_count = 0;
	$nodes_replaced = 0;
	$doc = new DOMDocument();
	@$doc->loadHTML( $buffer ); // = ($doc->strictErrorChecking = false;)
	$imageTags = $doc->getElementsByTagName('img');
	foreach ( $imageTags as $tag ) {
		$nodes_count++;
		$img_pathinfo = wr2x_get_pathinfo_from_image_src( $tag->getAttribute('src') );
		$filepath = trailingslashit( wr2x_get_upload_root() ) . $img_pathinfo;
		$system_retina = wr2x_get_retina( $filepath );
		if ( $system_retina != null ) {
			$retina_pathinfo = wr2x_cdn_this( ltrim( str_replace( wr2x_get_upload_root(), "", $system_retina ), '/' ) );
			$buffer = str_replace( $img_pathinfo, $retina_pathinfo, $buffer );
			wr2x_log( "The img src '$img_pathinfo' was replaced by '$retina_pathinfo'" );
			$nodes_replaced++;
		}
		else {
			wr2x_log( "The file '$system_retina' was not found. Tag not modified." );
		}
	}
	wr2x_log( "$nodes_replaced/$nodes_count were replaced." );
	return $buffer;
}


// Converts PHP INI size type (e.g. 24M) to int
function wr2x_parse_ini_size( $size ) {
	$unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
	$size = preg_replace('/[^0-9\.]/', '', $size);
	if ( $unit )
		return round( $size * pow( 1024, stripos( 'bkmgtpezy', $unit[0] ) ) );
	else
		round( $size );
}

function wr2x_get_max_filesize() {
	if ( defined ('HHVM_VERSION' ) ) {
		return ini_get( 'upload_max_filesize' ) ? (int)wr2x_parse_ini_size( ini_get( 'upload_max_filesize' ) ) :
			(int)ini_get( 'hhvm.server.upload.upload_max_file_size' );
	}
	else {
		return (int)wr2x_parse_ini_size( ini_get( 'upload_max_filesize' ) );
	}
}

/**
 *
 * ISSUES CALCULATION AND FUNCTIONS
 *
 */

// Compares two images dimensions (resolutions) against each while accepting an margin error
function wr2x_are_dimensions_ok( $width, $height, $retina_width, $retina_height ) {
	$w_margin = $width - $retina_width;
	$h_margin = $height - $retina_height;
	return ( $w_margin >= -2 && $h_margin >= -2 );
}

// UPDATE THE ISSUE STATUS OF THIS ATTACHMENT
function wr2x_update_issue_status( $attachmentId, $issues = null, $info = null ) {
	if ( wr2x_is_ignore( $attachmentId ) )
		return;
	if ( $issues == null )
		$issues = wr2x_get_issues();
	if ( $info == null )
		$info = wr2x_retina_info( $attachmentId );
	$consideredIssue = in_array( $attachmentId, $issues );
	$realIssue = wr2x_info_has_issues( $info );
	if ( $consideredIssue && !$realIssue )
		wr2x_remove_issue( $attachmentId );
	else if ( !$consideredIssue && $realIssue )
		wr2x_add_issue( $attachmentId );
	return $realIssue;
}

function wr2x_get_issues() {
	$issues = get_transient( 'wr2x_issues' );
	if ( !$issues || !is_array( $issues ) ) {
		$issues = array();
		set_transient( 'wr2x_issues', $issues );
	}
	return $issues;
}

// CHECK IF THE 'INFO' OBJECT CONTAINS ISSUE (RETURN TRUE OR FALSE)
function wr2x_info_has_issues( $info ) {
	foreach ( $info as $aindex => $aval ) {
		if ( is_array( $aval ) || $aval == 'PENDING' )
			return true;
	}
	return false;
}

function wr2x_calculate_issues() {
	global $wpdb;
	$postids = $wpdb->get_col( "
		SELECT p.ID FROM $wpdb->posts p
		WHERE post_status = 'inherit'
		AND post_type = 'attachment'" . wr2x_create_sql_if_wpml_original() . "
		AND ( post_mime_type = 'image/jpeg' OR
			post_mime_type = 'image/jpg' OR
			post_mime_type = 'image/png' OR
			post_mime_type = 'image/gif' )
	" );
	$issues = array();
	foreach ( $postids as $id ) {
		$info = wr2x_retina_info( $id );
		if ( wr2x_info_has_issues( $info ) )
			array_push( $issues, $id );

	}
	set_transient( 'wr2x_ignores', array() );
	set_transient( 'wr2x_issues', $issues );
}

function wr2x_add_issue( $attachmentId ) {
	if ( wr2x_is_ignore( $attachmentId ) )
		return;
	$issues = wr2x_get_issues();
	if ( !in_array( $attachmentId, $issues ) ) {
		array_push( $issues, $attachmentId );
		set_transient( 'wr2x_issues', $issues );
	}
	return $issues;
}

function wr2x_remove_issue( $attachmentId, $onlyIgnore = false ) {
	$issues = array_diff( wr2x_get_issues(), array( $attachmentId ) );
	set_transient( 'wr2x_issues', $issues );
	if ( !$onlyIgnore )
		wr2x_remove_ignore( $attachmentId );
	return $issues;
}

// IGNORE

function wr2x_get_ignores( $force = false ) {
	$ignores = get_transient( 'wr2x_ignores' );
	if ( !$ignores || !is_array( $ignores ) ) {
		$ignores = array();
		set_transient( 'wr2x_ignores', $ignores );
	}
	return $ignores;
}

function wr2x_is_ignore( $attachmentId ) {
	$ignores = wr2x_get_ignores();
	return in_array( $attachmentId, wr2x_get_ignores() );
}

function wr2x_remove_ignore( $attachmentId ) {
	$ignores = wr2x_get_ignores();
	$ignores = array_diff( $ignores, array( $attachmentId ) );
	set_transient( 'wr2x_ignores', $ignores );
	return $ignores;
}

function wr2x_add_ignore( $attachmentId ) {
	$ignores = wr2x_get_ignores();
	if ( !in_array( $attachmentId, $ignores ) ) {
		array_push( $ignores, $attachmentId );
		set_transient( 'wr2x_ignores', $ignores );
	}
	wr2x_remove_issue( $attachmentId, true );
	return $ignores;
}

/**
 *
 * INFORMATION ABOUT THE RETINA IMAGE IN HTML
 *
 */

function wpr2x_html_get_basic_retina_info_full( $attachmentId, $retina_info ) {
	$status = ( isset( $retina_info ) && isset( $retina_info['full-size'] ) ) ? $retina_info['full-size'] : 'IGNORED';
	if ( $status == 'EXISTS' ) {
		return '<ul class="meow-sized-images"><li class="meow-bk-blue" title="full-size"></li></ul>';
	}
	else if ( is_array( $status ) ) {
		return '<ul class="meow-sized-images"><li class="meow-bk-orange" title="full-size"></li></ul>';
	}
	else if ( $status == 'IGNORED' ) {
		return __( "N/A", "wp-retina-2x" );
	}
	return $status;
}

function wr2x_format_title( $i, $size ) {
	return $i . ' (' . ( $size['width'] * 2 ) . 'x' . ( $size['height'] * 2 ) . ')';
}

// Information for the 'Media Sizes Retina-ized' Column in the Retina Dashboard
function wpr2x_html_get_basic_retina_info( $attachmentId, $retina_info ) {
	$sizes = wr2x_get_active_image_sizes();
	$result = '<ul class="meow-sized-images" postid="' . ( is_integer( $attachmentId ) ? $attachmentId : $attachmentId->ID ) . '">';
	foreach ( $sizes as $i => $size ) {
		$status = ( isset( $retina_info ) && isset( $retina_info[$i] ) ) ? $retina_info[$i] : null;
		if ( is_array( $status ) )
			$result .= '<li class="meow-bk-red" title="' . wr2x_format_title( $i, $size ) . '">'
				. Meow_Admin::size_shortname( $i ) . '</li>';
		else if ( $status == 'EXISTS' )
			$result .= '<li class="meow-bk-blue" title="' . wr2x_format_title( $i, $size ) . '">'
				. Meow_Admin::size_shortname( $i ) . '</li>';
		else if ( $status == 'PENDING' )
			$result .= '<li class="meow-bk-orange" title="' . wr2x_format_title( $i, $size ) . '">'
				. Meow_Admin::size_shortname( $i ) . '</li>';
		else if ( $status == 'MISSING' )
			$result .= '<li class="meow-bk-red" title="' . wr2x_format_title( $i, $size ) . '">'
				. Meow_Admin::size_shortname( $i ) . '</li>';
		else if ( $status == 'IGNORED' )
			$result .= '<li class="meow-bk-gray" title="' . wr2x_format_title( $i, $size ) . '">'
				. Meow_Admin::size_shortname( $i ) . '</li>';
		else {
			error_log( "Retina: This status is not recognized: " . $status );
		}
	}
	$result .= '</ul>';
	return $result;
}

// Information for Details in the Retina Dashboard
function wpr2x_html_get_details_retina_info( $post, $retina_info ) {
	global $wr2x_admin;
	if ( !$wr2x_admin->is_pro() ) {
		return __( "PRO VERSION ONLY", 'wp-retina-2x' );
	}

	$sizes = wr2x_get_image_sizes();
	$total = 0; $possible = 0; $issue = 0; $ignored = 0; $retina = 0;

	$postinfo = get_post( $post, OBJECT );
	$meta = wp_get_attachment_metadata( $post );
	$fullsize_file = get_attached_file( $post );
	$pathinfo_system = pathinfo( $fullsize_file );
	$pathinfo = pathinfo( $meta['file'] );
	$uploads = wp_upload_dir();
	$basepath_url = trailingslashit( $uploads['baseurl'] ) . $pathinfo['dirname'];
	if ( get_option( "wr2x_full_size" ) ) {
		$sizes['full-size']['file'] = $pathinfo['basename'];
		$sizes['full-size']['width'] = $meta['width'];
		$sizes['full-size']['height'] = $meta['height'];
		$meta['sizes']['full-size']['file'] = $pathinfo['basename'];
		$meta['sizes']['full-size']['width'] = $meta['width'];
		$meta['sizes']['full-size']['height'] = $meta['height'];
	}
	$result = "<p>This screen displays all the image sizes set-up by your WordPress configuration with the Retina details.</p>";
	$result .= "<br /><a target='_blank' href='" . trailingslashit( $uploads['baseurl'] ) . $meta['file'] . "'><img src='" . trailingslashit( $uploads['baseurl'] ) . $meta['file'] . "' height='100px' style='float: left; margin-right: 10px;' /></a><div class='base-info'>";
	$result .= "Title: <b>" . ( $postinfo->post_title ? $postinfo->post_title : '<i>Untitled</i>' ) . "</b><br />";
	$result .= "Full-size: <b>" . $meta['width'] . "×" . $meta['height'] . "</b><br />";
	$result .= "Image URL: <a target='_blank' href='" . trailingslashit( $uploads['baseurl'] ) . $meta['file'] . "'>" . trailingslashit( $uploads['baseurl'] ) . $meta['file'] . "</a><br />";
	$result .= "Image Path: " . $fullsize_file . "<br />";
	$result .= "</div><div style='clear: both;'></div><br />";
	$result .= "<div class='scrollable-info'>";

	foreach ( $sizes as $i => $sizemeta ) {
		$total++;
		$normal_file_system = ""; $retina_file_system = "";
		$normal_file = ""; $retina_file = ""; $width = ""; $height = "";

		if ( isset( $retina_info[$i] ) && $retina_info[$i] == 'IGNORED' ) {
			$status = "IGNORED";
		}
		else if ( !isset( $meta['sizes'] ) ) {
			$statusText  = __( "The metadata is broken! This is not related to the retina plugin. You should probably use a plugin to re-generate the missing metadata and images.", 'wp-retina-2x' );
			$status = "MISSING";
		}
		else if ( !isset( $meta['sizes'][$i] ) ) {
			$statusText  = sprintf( __( "The image size '%s' could not be found. You probably changed your image sizes but this specific image was not re-build. This is not related to the retina plugin. You should probably use a plugin to re-generate the missing metadata and images.", 'wp-retina-2x' ), $i );
			$status = "MISSING";
		}
		else {
			$normal_file_system = trailingslashit( $pathinfo_system['dirname'] ) . $meta['sizes'][$i]['file'];
			$retina_file_system = wr2x_get_retina( $normal_file_system );
			$normal_file = trailingslashit( $basepath_url ) . $meta['sizes'][$i]['file'];
			$retina_file = wr2x_get_retina_from_url( $normal_file );
			$status = ( isset( $retina_info ) && isset( $retina_info[$i] ) ) ? $retina_info[$i] : null;
			$width = $meta['sizes'][$i]['width'];
			$height = $meta['sizes'][$i]['height'];
		}

		$result .= "<h3>";

		// Status Icon
		if ( is_array( $status ) && $i == 'full-size' ) {
			$result .= '<div class="meow-sized-image meow-bk-red"></div>';
			$statusText = sprintf( __( "The retina version of the Full-Size image is missing.<br />Full Size Retina has been checked in the Settings and this image is therefore required.<br />Please drag & drop an image of at least <b>%dx%d</b> in the <b>Full-Size Retina Upload</b> column.", 'wp-retina-2x' ), $status['width'], $status['height'] );
		}
		else if ( is_array( $status ) ) {
			$result .= '<div class="meow-sized-image meow-bk-red"></div>';
			$statusText = sprintf( __( "The Full-Size image is too small (<b>%dx%d</b>) and this size cannot be generated.<br />Please upload an image of at least <b>%dx%d</b>.", 'wp-retina-2x' ), $meta['width'], $meta['height'], $status['width'], $status['height'] );
			$issue++;
		}
		else if ( $status == 'EXISTS' ) {
			$result .= '<div class="meow-sized-image meow-bk-blue"></div>';
			$statusText = "";
			$retina++;
		}
		else if ( $status == 'PENDING' ) {
			$result .= '<div class="meow-sized-image meow-bk-orange"></div>';
			$statusText = __( "The retina image can be created. Please use the 'GENERATE' button.", 'wp-retina-2x' );
			$possible++;
		}
		else if ( $status == 'MISSING' ) {
			$result .= '<div class="meow-sized-image meow-bk-gray"></div>';
			$statusText = __( "The standard image normally created by WordPress is missing.", 'wp-retina-2x' );
			$total--;
		}
		else if ( $status == 'IGNORED' ) {
			$result .= '<div class="meow-sized-image meow-bk-gray"></div>';
			$statusText = __( "This size is ignored by your retina settings.", 'wp-retina-2x' );
			$ignored++;
			$total--;
		}

		$result .= "&nbsp;Size: $i</h3><p>$statusText</p>";

		if ( !is_array( $status ) && $status !== 'IGNORED' && $status !== 'MISSING'  ) {
			$result .= "<table><tr><th>Normal (" . $width . "×" . $height. ")</th><th>Retina 2x (" . $width * 2 . "×" . $height * 2 . ")</th></tr><tr><td><a target='_blank' href='$normal_file'><img src='$normal_file' width='100'></a></td><td><a target='_blank' href='$retina_file'><img src='$retina_file' width='100'></a></td></tr></table>";
			$result .= "<p><small>";
			$result .= "Image URL: <a target='_blank' href='$normal_file'>$normal_file</a><br />";
			$result .= "Retina URL: <a target='_blank' href='$retina_file'>$retina_file</a><br />";
			$result .= "Image Path: $normal_file_system<br />";
			$result .= "Retina Path: $retina_file_system<br />";
			$result .= "</small></p>";
		}
	}
	$result .= "</table>";
	$result .= "</div>";
	return $result;
}

/**
 *
 * WP RETINA 2X CORE
 *
 */

// Get WordPress upload directory
function wr2x_get_upload_root() {
	$uploads = wp_upload_dir();
	return $uploads['basedir'];
}

function wr2x_get_upload_root_url() {
	$uploads = wp_upload_dir();
	return $uploads['baseurl'];
}

// Get WordPress directory
function wr2x_get_wordpress_root() {
	return ABSPATH;
}

// Return the retina file if there is any (system path)
function wr2x_get_retina( $file ) {
	$pathinfo = pathinfo( $file ) ;
	if ( empty( $pathinfo ) || !isset( $pathinfo['dirname'] ) ) {
		if ( empty( $file ) ) {
			wr2x_log( "An empty filename was given to wr2x_get_retina()." );
			error_log( "An empty filename was given to wr2x_get_retina()." );
		}
		else {
			wr2x_log( "Pathinfo is null for " . $file . "." );
			error_log( "Pathinfo is null for " . $file . "." );
		}
		return null;
	}
	$retina_file = trailingslashit( $pathinfo['dirname'] ) . $pathinfo['filename'] .
		wr2x_retina_extension() . ( isset( $pathinfo['extension'] ) ? $pathinfo['extension'] : "" );
	if ( file_exists( $retina_file ) )
		return $retina_file;
	wr2x_log( "Retina file at '{$retina_file}' does not exist." );
	return null;
}

function wr2x_get_retina_from_remote_url( $url ) {
	global $wr2x_admin;
	$over_http = get_option( 'wr2x_over_http_check', false ) && $wr2x_admin->is_pro();
	if ( !$over_http )
		return null;
	$potential_retina_url = wr2x_rewrite_url_to_retina( $url );
	$response = wp_remote_head( $potential_retina_url, array(
		'user-agent' => "MeowApps-Retina",
		'sslverify' => false,
		'timeout' => 10
	));
	if ( is_array( $response ) && is_array( $response['response'] ) && isset( $response['response']['code'] ) ) {
		if ( $response['response']['code'] == 200 ) {
			wr2x_log( "Retina URL: " . $potential_retina_url, true);
			return $potential_retina_url;
		}
		else
			wr2x_log( "Remote head failed with code " . $response['response']['code'] . "." );
	}
	wr2x_log( "Retina URL couldn't be found (URL -> Retina URL).", true);
}

// Return retina URL from the image URL
function wr2x_get_retina_from_url( $url ) {
	wr2x_log( "Standard URL: " . $url, true);
	global $wr2x_admin;
	$over_http = get_option( 'wr2x_over_http_check', false ) && $wr2x_admin->is_pro();
	$filepath = wr2x_from_url_to_system( $url );
	if ( empty ( $filepath ) )
		return wr2x_get_retina_from_remote_url( $url );
	wr2x_log( "Standard PATH: " . $filepath, true);
	$system_retina = wr2x_get_retina( $filepath );
	if ( empty ( $system_retina ) )
		return wr2x_get_retina_from_remote_url( $url );
	wr2x_log( "Retina PATH: " . $system_retina, true);
	$retina_url = wr2x_rewrite_url_to_retina( $url );
	wr2x_log( "Retina URL: " . $retina_url, true);
	return $retina_url;
}

// Get the filepath from the URL
function wr2x_from_url_to_system( $url ) {
	$img_pathinfo = wr2x_get_pathinfo_from_image_src( $url );
	$filepath = trailingslashit( wr2x_get_wordpress_root() ) . $img_pathinfo;
	if ( file_exists( $filepath ) )
		return $filepath;
	$filepath = trailingslashit( wr2x_get_upload_root() ) . $img_pathinfo;
	if ( file_exists( $filepath ) )
		return $filepath;
	wr2x_log( "Standard PATH couldn't be found (URL -> System).", true);
	return null;
}

function wr2x_rewrite_url_to_retina( $url ) {
	$whereisdot = strrpos( $url, '.' );
	$url = substr( $url, 0, $whereisdot ) . wr2x_retina_extension() . substr( $url, $whereisdot + 1 );
	return $url;
}

// Clean the PathInfo of the IMG SRC.
// IMPORTANT: This function STRIPS THE UPLOAD FOLDER if it's found
// REASON: The reason is that on some installs the uploads folder is linked to a different "unlogical" physical folder
// http://wordpress.org/support/topic/cant-find-retina-file-with-custom-uploads-constant?replies=3#post-5078892
function wr2x_get_pathinfo_from_image_src( $image_src ) {
	$uploads_url = trailingslashit( wr2x_get_upload_root_url() );
	if ( strpos( $image_src, $uploads_url ) === 0 )
		return ltrim( substr( $image_src, strlen( $uploads_url ) ), '/');
	else if ( strpos( $image_src, wp_make_link_relative( $uploads_url ) ) === 0 )
		return ltrim( substr( $image_src, strlen( wp_make_link_relative( $uploads_url ) ) ), '/');
	$img_info = parse_url( $image_src );
	return ltrim( $img_info['path'], '/' );
}

// Rename this filename with CDN
function wr2x_cdn_this( $url ) {
	global $wr2x_admin;
	$cdn_domain = "";
	if ( $wr2x_admin->is_pro() )
		$cdn_domain = get_option( "wr2x_cdn_domain" );
	if ( empty( $cdn_domain ) )
		return $url;

	$home_url = parse_url( home_url() );
	$uploads_url = trailingslashit( wr2x_get_upload_root_url() );
  $uploads_url_cdn = str_replace( $home_url['host'], $cdn_domain, $uploads_url );
	// Perform additional CDN check (Issue #1631 by Martin)
	if ( strpos( $url, $uploads_url_cdn ) === 0 ) {
		wr2x_log( "URL already has CDN: $url" );
		return $url;
	}
	wr2x_log( "URL before CDN: $url" );
	$site_url = preg_replace( '#^https?://#', '', rtrim( get_site_url(), '/' ) );
	$new_url = str_replace( $site_url, $cdn_domain, $url );
	wr2x_log( "URL with CDN: $new_url" );
	return $new_url;
}

// function wr2x_admin_menu() {
// 	add_options_page( 'Retina', 'Retina', 'manage_options', 'wr2x_settings', 'wr2x_settings_page' );
// }

function wr2x_get_image_sizes() {
	$sizes = array();
	global $_wp_additional_image_sizes;
	foreach ( get_intermediate_image_sizes() as $s ) {
		$crop = false;
		if ( isset( $_wp_additional_image_sizes[$s] ) ) {
			$width = intval($_wp_additional_image_sizes[$s]['width']);
			$height = intval($_wp_additional_image_sizes[$s]['height']);
			$crop = $_wp_additional_image_sizes[$s]['crop'];
		} else {
			$width = get_option( $s . '_size_w' );
			$height = get_option( $s . '_size_h' );
			$crop = get_option( $s . '_crop' );
		}
		$sizes[$s] = array( 'width' => $width, 'height' => $height, 'crop' => $crop );
	}
	if ( get_option( 'wr2x_disable_medium_large' ) )
		unset( $sizes['medium_large'] );
	return $sizes;
}

function wr2x_get_active_image_sizes() {
	$sizes = wr2x_get_image_sizes();
	$active_sizes = array();
	$ignore = get_option( "wr2x_ignore_sizes", array() );
	if ( empty( $ignore ) )
		$ignore = array();
	foreach ( $sizes as $name => $attr ) {
		$validSize = !empty( $attr['width'] ) || !empty( $attr['height'] );
		if ( $validSize && !in_array( $name, $ignore ) ) {
			$active_sizes[$name] = $attr;
		}
	}
	return $active_sizes;
}

function wr2x_is_wpml_installed() {
	return function_exists( 'icl_object_id' ) && !class_exists( 'Polylang' );
}

// SQL Query if WPML with an AND to check if the p.ID (p is attachment) is indeed an original
// That is to limit the SQL that queries all the attachments
function wr2x_create_sql_if_wpml_original() {
	$whereIsOriginal = "";
	if ( wr2x_is_wpml_installed() ) {
		global $wpdb;
		global $sitepress;
		$tbl_wpml = $wpdb->prefix . "icl_translations";
		$language = $sitepress->get_default_language();
		$whereIsOriginal = " AND p.ID IN (SELECT element_id FROM $tbl_wpml WHERE element_type = 'post_attachment' AND language_code = '$language') ";
	}
	return $whereIsOriginal;
}

function wr2x_is_debug() {
	static $debug = -1;
	if ( $debug == -1 ) {
		$debug = get_option( "wr2x_debug" );
	}
	return !!$debug;
}

function wr2x_log( $data, $isExtra = false ) {
	global $wr2x_extra_debug;
	if ( !wr2x_is_debug() )
		return;
	$fh = fopen( trailingslashit( WP_PLUGIN_DIR ) . 'wp-retina-2x/wp-retina-2x.log', 'a' );
	$date = date( "Y-m-d H:i:s" );
	fwrite( $fh, "$date: {$data}\n" );
	fclose( $fh );
}

// Based on http://wordpress.stackexchange.com/questions/6645/turn-a-url-into-an-attachment-post-id
function wr2x_get_attachment_id( $file ) {
	$query = array(
		'post_type' => 'attachment',
		'meta_query' => array(
			array(
				'key'		=> '_wp_attached_file',
				'value'		=> ltrim( $file, '/' )
			)
		)
	);
	$posts = get_posts( $query );
	foreach( $posts as $post )
		return $post->ID;
	return false;
}

// Return the retina extension followed by a dot
function wr2x_retina_extension() {
	return '@2x.';
}

function wr2x_is_image_meta( $meta ) {
	if ( !isset( $meta ) )
		return false;
	if ( !isset( $meta['sizes'] ) )
		return false;
	if ( !isset( $meta['width'], $meta['height'] ) )
		return false;
	return true;
}

function wr2x_retina_info( $id ) {
	global $wr2x_admin;
	$result = array();
	$meta = wp_get_attachment_metadata( $id );
	if ( !wr2x_is_image_meta( $meta ) )
		return $result;
	$original_width = $meta['width'];
	$original_height = $meta['height'];
	$sizes = wr2x_get_image_sizes();
	$required_files = true;
	$originalfile = get_attached_file( $id );
	$pathinfo = pathinfo( $originalfile );
	$basepath = $pathinfo['dirname'];
	$ignore = get_option( "wr2x_ignore_sizes", array() );
	if ( empty( $ignore ) )
		$ignore = array();

	// Full-Size (if required in the settings)
	$fullsize_required = get_option( "wr2x_full_size" ) && $wr2x_admin->is_pro();
	$retina_file = trailingslashit( $pathinfo['dirname'] ) . $pathinfo['filename'] . wr2x_retina_extension() . $pathinfo['extension'];
	if ( $retina_file && file_exists( $retina_file ) )
		$result['full-size'] = 'EXISTS';
	else if ( $fullsize_required && $retina_file )
		$result['full-size'] = array( 'width' => $original_width * 2, 'height' => $original_height * 2 );
	//}

	if ( $sizes ) {
		foreach ( $sizes as $name => $attr ) {
			$validSize = !empty( $attr['width'] ) || !empty( $attr['height'] );
			if ( !$validSize || in_array( $name, $ignore ) ) {
				$result[$name] = 'IGNORED';
				continue;
			}
			// Check if the file related to this size is present
			$pathinfo = null;
			$retina_file = null;

			if ( isset( $meta['sizes'][$name]['width'] ) && isset( $meta['sizes'][$name]['height']) && isset($meta['sizes'][$name]) && isset($meta['sizes'][$name]['file']) && file_exists( trailingslashit( $basepath ) . $meta['sizes'][$name]['file'] ) ) {
				$normal_file = trailingslashit( $basepath ) . $meta['sizes'][$name]['file'];
				$pathinfo = pathinfo( $normal_file ) ;
				$retina_file = trailingslashit( $pathinfo['dirname'] ) . $pathinfo['filename'] . wr2x_retina_extension() . $pathinfo['extension'];
			}
			// None of the file exist
			else {
				$result[$name] = 'MISSING';
				$required_files = false;
				continue;
			}

			// The retina file exists
			if ( $retina_file && file_exists( $retina_file ) ) {
				$result[$name] = 'EXISTS';
				continue;
			}
			// The size file exists
			else if ( $retina_file )
				$result[$name] = 'PENDING';

			// The retina file exists
			$required_width = $meta['sizes'][$name]['width'] * 2;
			$required_height = $meta['sizes'][$name]['height'] * 2;
			if ( !wr2x_are_dimensions_ok( $original_width, $original_height, $required_width, $required_height ) ) {
				$result[$name] = array( 'width' => $required_width, 'height' => $required_height );
			}
		}
	}
	return $result;
}

function wr2x_delete_attachment( $attach_id, $deleteFullSize = true ) {
	$meta = wp_get_attachment_metadata( $attach_id );
	wr2x_delete_images( $meta, $deleteFullSize );
	wr2x_remove_issue( $attach_id );
}

function wr2x_wp_generate_attachment_metadata( $meta ) {
	if ( get_option( "wr2x_auto_generate" ) == true )
		if ( wr2x_is_image_meta( $meta ) )
			wr2x_generate_images( $meta );
    return $meta;
}

function wr2x_generate_images( $meta ) {
	require( 'wr2x_vt_resize.php' );
	global $_wp_additional_image_sizes;
	$sizes = wr2x_get_image_sizes();
	if ( !isset( $meta['file'] ) )
		return;
	$originalfile = $meta['file'];
	$uploads = wp_upload_dir();
	$pathinfo = pathinfo( $originalfile );
	$original_basename = $pathinfo['basename'];
	$basepath = trailingslashit( $uploads['basedir'] ) . $pathinfo['dirname'];
	$ignore = get_option( "wr2x_ignore_sizes", array() );
	if ( empty( $ignore ) )
		$ignore = array();
	$issue = false;
	$id = wr2x_get_attachment_id( $meta['file'] );

	wr2x_log("* GENERATE RETINA FOR ATTACHMENT '{$meta['file']}'");
	wr2x_log( "Full-Size is {$original_basename}." );

	foreach ( $sizes as $name => $attr ) {
		$normal_file = "";
		if ( in_array( $name, $ignore ) ) {
			wr2x_log( "Retina for {$name} ignored (settings)." );
			continue;
		}
		// Is the file related to this size there?
		$pathinfo = null;
		$retina_file = null;

		if ( isset( $meta['sizes'][$name] ) && isset( $meta['sizes'][$name]['file'] ) ) {
			$normal_file = trailingslashit( $basepath ) . $meta['sizes'][$name]['file'];
			$pathinfo = pathinfo( $normal_file ) ;
			$retina_file = trailingslashit( $pathinfo['dirname'] ) . $pathinfo['filename'] . wr2x_retina_extension() . $pathinfo['extension'];
		}

		if ( $retina_file && file_exists( $retina_file ) ) {
			wr2x_log( "Base for {$name} is '{$normal_file }'." );
			wr2x_log( "Retina for {$name} already exists: '$retina_file'." );
			continue;
		}
		if ( $retina_file ) {
			$originalfile = trailingslashit( $pathinfo['dirname'] ) . $original_basename;

			if ( !file_exists( $originalfile ) ) {
				wr2x_log( "[ERROR] Original file '{$originalfile}' cannot be found." );
				return $meta;
			}

			// Maybe that new image is exactly the size of the original image.
			// In that case, let's make a copy of it.
			if ( $meta['sizes'][$name]['width'] * 2 == $meta['width'] && $meta['sizes'][$name]['height'] * 2 == $meta['height'] ) {
				copy ( $originalfile, $retina_file );
				wr2x_log( "Retina for {$name} created: '{$retina_file}' (as a copy of the full-size)." );
			}
			// Otherwise let's resize (if the original size is big enough).
			else if ( wr2x_are_dimensions_ok( $meta['width'], $meta['height'], $meta['sizes'][$name]['width'] * 2, $meta['sizes'][$name]['height'] * 2 ) ) {
				// Change proposed by Nicscott01, slighlty modified by Jordy (+isset)
				// (https://wordpress.org/support/topic/issue-with-crop-position?replies=4#post-6200271)
				$crop = isset( $_wp_additional_image_sizes[$name] ) ? $_wp_additional_image_sizes[$name]['crop'] : true;
				$customCrop = null;

				// Support for Manual Image Crop
				// If the size of the image was manually cropped, let's keep it.
				if ( class_exists( 'ManualImageCrop' ) && isset( $meta['micSelectedArea'] ) && isset( $meta['micSelectedArea'][$name] ) && isset( $meta['micSelectedArea'][$name]['scale'] ) ) {
					$customCrop = $meta['micSelectedArea'][$name];
				}
				$image = wr2x_vt_resize( $originalfile, $meta['sizes'][$name]['width'] * 2,
					$meta['sizes'][$name]['height'] * 2, $crop, $retina_file, $customCrop );
			}
			if ( !file_exists( $retina_file ) ) {
				wr2x_log( "[ERROR] Retina for {$name} could not be created. Full-Size is " . $meta['width'] . "x" . $meta['height'] . " but Retina requires a file of at least " . $meta['sizes'][$name]['width'] * 2 . "x" . $meta['sizes'][$name]['height'] * 2 . "." );
				$issue = true;
			}
			else {
				do_action( 'wr2x_retina_file_added', $id, $retina_file, $name );
				wr2x_log( "Retina for {$name} created: '{$retina_file}'." );
			}
		} else {
			if ( empty( $normal_file ) )
				wr2x_log( "[ERROR] Base file for '{$name}' does not exist." );
			else
				wr2x_log( "[ERROR] Base file for '{$name}' cannot be found here: '{$normal_file}'." );
		}
	}

	// Checks attachment ID + issues
	if ( !$id )
		return $meta;
	if ( $issue )
		wr2x_add_issue( $id );
	else
		wr2x_remove_issue( $id );
   return $meta;
}

function wr2x_delete_images( $meta, $deleteFullSize = false ) {
	if ( !wr2x_is_image_meta( $meta ) )
		return $meta;
	$sizes = $meta['sizes'];
	if ( !$sizes || !is_array( $sizes ) )
		return $meta;
	wr2x_log("* DELETE RETINA FOR ATTACHMENT '{$meta['file']}'");
	$originalfile = $meta['file'];
	$id = wr2x_get_attachment_id( $originalfile );
	$pathinfo = pathinfo( $originalfile );
	$uploads = wp_upload_dir();
	$basepath = trailingslashit( $uploads['basedir'] ) . $pathinfo['dirname'];
	foreach ( $sizes as $name => $attr ) {
		$pathinfo = pathinfo( $attr['file'] );
		$retina_file = $pathinfo['filename'] . wr2x_retina_extension() . $pathinfo['extension'];
		if ( file_exists( trailingslashit( $basepath ) . $retina_file ) ) {
			$fullpath = trailingslashit( $basepath ) . $retina_file;
			unlink( $fullpath );
			do_action( 'wr2x_retina_file_removed', $id, $retina_file );
			wr2x_log("Deleted '$fullpath'.");
		}
	}
	// Remove full-size if there is any
	if ( $deleteFullSize ) {
		$pathinfo = pathinfo( $originalfile );
		$retina_file = $pathinfo[ 'filename' ] . wr2x_retina_extension() . $pathinfo[ 'extension' ];
		if ( file_exists( trailingslashit( $basepath ) . $retina_file ) ) {
			$fullpath = trailingslashit( $basepath ) . $retina_file;
			unlink( $fullpath );
			do_action( 'wr2x_retina_file_removed', $id, $retina_file );
			wr2x_log( "Deleted '$fullpath'." );
		}
	}
	return $meta;
}

function wr2x_activate() {
	global $wp_rewrite;
	$wp_rewrite->flush_rules();
}

function wr2x_deactivate() {
	remove_filter( 'generate_rewrite_rules', array( 'WR2X_Admin', 'generate_rewrite_rules' ) );
	global $wp_rewrite;
	$wp_rewrite->flush_rules();
}

/**
 *
 * FILTERS
 *
 */

function wr2x_validate_src( $src ) {
	if ( preg_match( "/^data:/i", $src ) )
		return null;
	return $src;
}

/**
 *
 * LOAD SCRIPTS IF REQUIRED
 *
 */

function wr2x_wp_enqueue_scripts () {
	global $wr2x_version, $wr2x_retinajs, $wr2x_retina_image, $wr2x_picturefill, $wr2x_lazysizes;
	global $wr2x_admin;
	$method = get_option( "wr2x_method" );

	if ( is_admin() && !get_option( "wr2x_retina_admin" ) )
			return;

	// Picturefill
	if ( $method == "Picturefill" ) {
		if ( wr2x_is_debug() )
			wp_enqueue_script( 'wr2x-debug', plugins_url( '/js/debug.js', __FILE__ ), array(), $wr2x_version, false );
		// Picturefill
		if ( !get_option( "wr2x_picturefill_noscript" ) )
			wp_enqueue_script( 'picturefill', plugins_url( '/js/picturefill.min.js', __FILE__ ), array(), $wr2x_picturefill, false );
		// Lazysizes
		if ( get_option( "wr2x_picturefill_lazysizes" ) && $wr2x_admin->is_pro() )
			wp_enqueue_script( 'lazysizes', plugins_url( '/js/lazysizes.min.js', __FILE__ ), array(), $wr2x_lazysizes, false );
		return;
	}

	// Debug + HTML Rewrite = No JS!
	if ( wr2x_is_debug() && $method == "HTML Rewrite" ) {
		return;
	}

	// Debug mode, we force the devicePixelRatio to be Retina
	if ( wr2x_is_debug() )
		wp_enqueue_script( 'wr2x-debug', plugins_url( '/js/debug.js', __FILE__ ), array(), $wr2x_version, false );
	// Not Debug Mode + Ignore Mobile
	// else if ( get_option( "wr2x_ignore_mobile"  ) ) {
	// 	$mobileDetect = new Mobile_Detect();
	// 	if ( $mobileDetect->isMobile() )
	// 		return;
	// }

	// Retina-Images and HTML Rewrite both need the devicePixelRatio cookie on the server-side
	if ( $method == "Retina-Images" || $method == "HTML Rewrite" )
		wp_enqueue_script( 'retina-images', plugins_url( '/js/retina-cookie.js', __FILE__ ), array(), $wr2x_retina_image, false );

	// Retina.js only needs itself
	if ($method == "retina.js")
		wp_enqueue_script( 'retinajs', plugins_url( '/js/retina.min.js', __FILE__ ), array(), $wr2x_retinajs, true );
}

// Function still use by WP Rocket (and maybe another plugin as well)
function wr2x_is_pro() {
	global $wr2x_admin;
	return $wr2x_admin->is_pro();
}

?>
