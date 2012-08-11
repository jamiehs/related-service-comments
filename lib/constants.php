<?php
/**
 * Constants used by this plugin

 * @author jamie3d
 * @version 1.0.0
 * @since 1.0.0
 */

// The current version of this plugin
if( !defined( 'RELATED_SERVICE_COMMENTS_VERSION' ) ) define( 'RELATED_SERVICE_COMMENTS_VERSION', '1.0.0' );

// The cache prefix
if( !defined( 'RELATED_SERVICE_COMMENTS_CACHE_PREFIX' ) ) define( 'RELATED_SERVICE_COMMENTS_CACHE_PREFIX', 'rsc' );

// The 500px Key
if( !defined( 'RELATED_SERVICE_COMMENTS_500PX_KEY' ) ) define( 'RELATED_SERVICE_COMMENTS_500PX_KEY', 'iPgKbqCRAOPYogfQrcNMzufvOLxzxa11PjTH38JE' );

// The directory the plugin resides in
if( !defined( 'RELATED_SERVICE_COMMENTS_DIRNAME' ) ) define( 'RELATED_SERVICE_COMMENTS_DIRNAME', dirname( dirname( __FILE__ ) ) );

// The URL path of this plugin
if( !defined( 'RELATED_SERVICE_COMMENTS_URLPATH' ) ) define( 'RELATED_SERVICE_COMMENTS_URLPATH', WP_PLUGIN_URL . "/" . plugin_basename( RELATED_SERVICE_COMMENTS_DIRNAME ) );

if( !defined( 'IS_AJAX_REQUEST' ) ) define( 'IS_AJAX_REQUEST', ( !empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && strtolower( $_SERVER['HTTP_X_REQUESTED_WITH'] ) == 'xmlhttprequest' ) );