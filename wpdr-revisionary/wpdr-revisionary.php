<?php
/**
 * Plugin Name: WP Document Revisions - PublishPress Revisions Integration
 * Plugin URI: https://github.com/wp-document-revisions/WP-Document-Revisions-Code-Cookbook
 * Description: Integrates the PublishPress Revisions functionality into WP Document Revisions to provide revisions to Published documents.
 * Version: 0.5
 * Author: Neil James based on work by Earthling Davey
 * License: GPL3
 *
 *  WP Document Revisions - PublishPress Revisions Integration
 *
 *  @copyright 2026
 *  @license GPL v3
 *  @version 0.5
 *  @package WPDR_Revisionary
 *  @author Neil James based mainly on work by Earthling Davey
 */

if ( ! defined( 'ABSPATH' ) ) {
		exit;
}

/**
 * Add compatibility with PublishPress Revisions, formerly known as Revisionary.
 *
 * @see https://wp-document-revisions.github.io/wp-document-revisions/
 * @see https://github.com/wp-document-revisions/wp-document-revisions
 * @see https://publishpress.com/revisions/
 * @see https://github.com/publishpress/PublishPress-Revisions
 */

require_once __DIR__ . '/includes/class-wpdr-revisionary.php';

global $wpdr_r;
if ( ! $wpdr_r ) {
	$wpdr_r = new WPDR_Revisionary();
}
