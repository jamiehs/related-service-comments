<?php
/*
Plugin Name: Related Service Comments
Plugin URI: http://jamie3d.com/
Description: Grabs Related Comments from 500px, YouTube
Version: 1.0.0
Author: jamie3d
Author URI: http://jamie3d.com
License: GPL3

Copyright 2012 Jamie Hamel-Smith

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

// Include constants file
require_once( dirname( __FILE__ ) . '/lib/constants.php' );

class RelatedServiceComments {
    static $html_newline = "\n";
    var $namespace = "related_service_comments";
    var $version = "1.0.0";
    var $process_log = '';
    var $other_logs = array();
    var $update_existing_comment_content = false; // Should we update the comment data (for edits, etc)?
    var $first_cron_offset = 60; // Number of seconds the scheduler waits before running the first event.
    
    /**
     * Instantiation construction
     * 
     * @uses add_action()
     * @uses RelatedServiceComments::wp_register_scripts()
     * @uses RelatedServiceComments::wp_register_styles()
     */
    function __construct() {
        // Set the admin email address...
        $this->admin_email_address = get_option('admin_email');
        $this->admin_name = get_option('blogname') . ' ' . __( 'Administrator', $this->namespace );
        
        // Name of the option_value to store plugin options in
        $this->option_name = '_' . $this->namespace . '--options';

        // Set and Translate the friendly name
        $this->friendly_name = __( "Related Service Comments", $this->namespace );
        
        // Update frequencies
        $this->update_schedules = array(
            'never' => array(
                'time' => 0, 
                'name' => __('Never (Manual updates)', $this->namespace )
            ),
            'hourly' => array(
                'time' => 3600, 
                'name' => __('Every hour', $this->namespace )
            ),
            'every_2_hours' => array(
                'time' => 7200, 
                'name' => sprintf( __('Every %d hours', $this->namespace ), 2 )
            ),
            'every_4_hours' => array(
                'time' => 14400, 
                'name' => sprintf( __('Every %d hours', $this->namespace ), 4 )
            ),
            'every_6_hours' => array(
                'time' => 21600, 
                'name' => sprintf( __('Every %d hours', $this->namespace ), 6 )
            ),
            'every_12_hours' => array(
                'time' => 43200, 
                'name' => sprintf( __('Every %d hours', $this->namespace ), 12 )
            ),
            'daily' => array(
                'time' => 86400, 
                'name' => __('Once a day', $this->namespace ) ),
            'every_2_days' => array(
                'time' => 172800, 
                'name' => sprintf( __('Every %d days', $this->namespace ), 2 )
            ),
            'every_week' => array(
                'time' => 604800, 
                'name' => __('Once a week', $this->namespace ) ),
            'every_2_weeks' => array(
                'time' => 1209600, 
                'name' => sprintf( __('Once every %d weeks', $this->namespace ), 2 )
            )
        );
        
        // Set and Translate defaults
        $this->defaults = array(
            'email_report_to_admin' => 'no',
            'email_type' => 'summary',
            'update_existing_comment_content' => 'yes',
            'youtube_comment_prefix' => __('From YouTube: ', $this->namespace ),
            'fivehundred_pixels_comment_prefix' => __('From 500px: ', $this->namespace ),
            'dribbble_comment_prefix' => __('From Dribbble: ', $this->namespace ),
            'update_schedule' => 'daily'
        );
        
        /**
         * By default, the cache duration is set to 1/2 of the schedule.
         * This ensures that the data is cached during requests, but that it
         * will be stale by the time the next schedule is run.
         * 
         * It is also set to a minimum of 180 seconds
         */
        $this->cache_duration = max( 180, round( $this->get_option( 'update_schedule' ) / 2 ) );
        
        // Should we update the existing comment content?
        $this->update_existing_comment_content = (boolean) ( $this->get_option( 'update_existing_comment_content' ) == 'yes' );
        
        // Load all library files used by this plugin
        $libs = glob( RELATED_SERVICE_COMMENTS_DIRNAME . '/lib/*.php' );
        foreach( $libs as $lib ) {
            include_once( $lib );
        }
        
        // Add all action, filter and shortcode hooks
        $this->_add_hooks();
    }
    
    /**
     * Add in various hooks
     * 
     * Place all add_action, add_filter, add_shortcode hook-ins here
     */
    private function _add_hooks() {
        // Options page for configuration
        add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
        
        // Output the styles for the admin area.
        add_action( 'admin_menu', array( &$this, 'admin_print_styles' ) );
        
        // Route requests for form processing
        add_action( 'init', array( &$this, 'route' ) );
        
        // Add the meta boxes
        add_action( 'add_meta_boxes', array( &$this, 'add_autodetect_meta_box' ) );
        
        // Add a settings link next to the "Deactivate" link on the plugin listing page.
        add_filter( 'plugin_action_links', array( &$this, 'plugin_action_links' ), 10, 2 );
        
        // Add Avatar Filter
        add_filter( 'get_avatar', array( $this, 'filter_avatar' ), 20, 5 );
        
        // Fetch a preview of the content found that we can get related comments for.
        add_action( "wp_ajax_{$this->namespace}_fetch_content_preview", array( &$this, "ajax_fetch_content_preview" ) );
        
        // Register all JavaScripts for this plugin
        add_action( 'init', array( &$this, 'wp_register_scripts' ), 1 );
        
        // Register all Stylesheets for this plugin
        add_action( 'init', array( &$this, 'wp_register_styles' ), 1 );
        
        // Filter the default array of cron schedules and add some more...
        add_filter( 'cron_schedules', array( &$this, 'cron_add_custom_schedules' ) );
        
        // Add the cron task for updating the comments...
        add_action( RELATED_SERVICE_COMMENTS_CRON_PREFIX, array( &$this, 'cron_import_comments' ) );
    }
    
    /**
     * Process update page form submissions
     * 
     * @uses RelatedServiceComments::sanitize()
     * @uses wp_redirect()
     * @uses wp_verify_nonce()
     */
    private function _admin_options_update() {
        // Verify submission for processing using wp_nonce
        if( wp_verify_nonce( $_REQUEST['_wpnonce'], "{$this->namespace}-update-options" ) ) {
            $data = array();
            /**
             * Loop through each POSTed value and sanitize it to protect against malicious code. Please
             * note that rich text (or full HTML fields) should not be processed by this function and 
             * dealt with directly.
             */
            foreach( $_POST['data'] as $key => $val ) {
                $data[$key] = $this->_sanitize( $val );
            }
            
            /**
             * Place your options processing and storage code here
             */
             
            // Because these are checkboxes, not set is actually 'no' 
            if( !isset( $data['email_report_to_admin'] ) )
                $data['email_report_to_admin'] = 'no';

            if( !isset( $data['update_existing_comment_content'] ) )
                $data['update_existing_comment_content'] = 'no';

            // Update the options value with the data submitted
            update_option( $this->option_name, $data );
            
            // Setup the cron tasks
            $this->schedule_cron();
            
            // Redirect back to the options page with the message flag to show the saved message
            wp_safe_redirect( $_REQUEST['_wp_http_referer'] );
            exit;
        }
    }
    
    /**
     * Sanitize data
     * 
     * @param mixed $str The data to be sanitized
     * 
     * @uses wp_kses()
     * 
     * @return mixed The sanitized version of the data
     */
    private function _sanitize( $str ) {
        if ( !function_exists( 'wp_kses' ) ) {
            require_once( ABSPATH . 'wp-includes/kses.php' );
        }
        global $allowedposttags;
        global $allowedprotocols;
        
        if ( is_string( $str ) ) {
            $str = wp_kses( $str, $allowedposttags, $allowedprotocols );
        } elseif( is_array( $str ) ) {
            $arr = array();
            foreach( (array) $str as $key => $val ) {
                $arr[$key] = $this->_sanitize( $val );
            }
            $str = $arr;
        }
        
        return $str;
    }

    /**
     * Hook into register_activation_hook action
     * 
     * Put code here that needs to happen when your plugin is first activated (database
     * creation, permalink additions, etc.)
     */
    static function activate() {
        // Do activation actions
    }
    
    function add_autodetect_meta_box() {
        add_meta_box( 
            $this->namespace . '-autodetect_meta_box',
            __( 'Adutodetected Services with Comment Support', $this->namespace ),
            array( &$this, 'autodetect_meta_box' ),
            'post',
            'normal',
            'high'
        );
        add_meta_box( 
            $this->namespace . '-autodetect_meta_box',
            __( 'Adutodetected Services with Comment Support', $this->namespace ),
            array( &$this, 'autodetect_meta_box' ),
            'page',
            'normal',
            'high'
        );
    }
    
    function add_checked_items_status_message() {
        return $this->friendly_name . " will try to fetch comments for checked items above.";
    }
    
    /**
     * Define the admin menu options for this plugin
     * 
     * @uses add_action()
     * @uses add_options_page()
     */
    function admin_menu() {
        $page_hook = add_options_page( $this->friendly_name, $this->friendly_name, 'administrator', $this->namespace, array( &$this, 'admin_options_page' ) );
        
        // Add print scripts and styles action based off the option page hook
        add_action( 'admin_print_scripts-' . $page_hook, array( &$this, 'admin_print_scripts' ) );
    }
    
    
    /**
     * The admin section options page rendering method
     * 
     * @uses current_user_can()
     * @uses wp_die()
     */
    function admin_options_page() {
        if( !current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have sufficient permissions to access this page' );
        }
        
        $namespace = $this->namespace;
        $page_title = $this->friendly_name . ' ' . __( 'Settings', $namespace );
        $update_schedule = $this->get_option( 'update_schedule' );
        $email_type = $this->get_option( 'email_type' );
        $gmt_seconds_offset = get_option( 'gmt_offset' ) * 3600;
        $next_scheduled_cron = wp_next_scheduled( RELATED_SERVICE_COMMENTS_CRON_PREFIX ) + $gmt_seconds_offset;
        
        // Create a bit of text for the next scheduled status
        $next_scheduled_cron_time = ' (';
        if( !empty( $next_scheduled_cron ) ){
            $next_scheduled_cron_time .= sprintf(
                __( 'next automatic update will be at: %s on %s', $namespace ),
                date( "H:i:s", $next_scheduled_cron ),
                date( "Y-m-d", $next_scheduled_cron )
            );
        }else{
            $next_scheduled_cron_time .= __( 'there is no automatic updating scheduled', $namespace );
        }
        $next_scheduled_cron_time .= ')';
        
        include( RELATED_SERVICE_COMMENTS_DIRNAME . "/views/options.php" );
    }

    /**
     * Load JavaScript for the admin options page
     * 
     * @uses wp_enqueue_script()
     */
    function admin_print_scripts() {
        wp_enqueue_script( "{$this->namespace}-admin" );
    }
    
    /**
     * Load Stylesheet for the admin options page
     * 
     * @uses wp_enqueue_style()
     */
    function admin_print_styles() {
        wp_enqueue_style( "{$this->namespace}-admin" );
    }
    
    /**
     * Auto Detect Meta Box
     * 
     * Adds the meta box to posts/pages that checks the post
     * content to see if there are any commmentable pieces of media
     * in the post body.
     */
    function autodetect_meta_box() {
        $this->admin_print_scripts();
        
        include( RELATED_SERVICE_COMMENTS_DIRNAME . '/views/_autodetect_meta_box.php' );
    }
    
    /**
     * Sets a WordPress Transient. Returns a boolean value of the success of the write.
     * 
     * @param string $name The name (key) for the file cache
     * @param mixed $content The content to store for the file cache
     * @param string $time_from_now time in seconds from now when the cache should expire default 5 minutes (300 seconds)
     * 
     * @uses set_transient()
     * 
     * @return boolean
     */
    function cache_write( $name = "", $content = "", $time_from_now = 300 ) {
        $name = RELATED_SERVICE_COMMENTS_CACHE_PREFIX . '_' . md5( $name );
        return set_transient( $name, $content, $time_from_now );
    }
    
    /**
     * Reads a file cache value and returns the content stored, 
     * or returns boolean(false)
     * 
     * @param string $name The name (key) for the transient
     * 
     * @uses get_transient()
     * 
     * @return mixed
     */
    function cache_read( $name = "" ) {
        $name = RELATED_SERVICE_COMMENTS_CACHE_PREFIX . '_' . md5( $name );
        return get_transient( $name );
    }
    
    /**
     * Deletes a WordPress Transient Cache
     * 
     * @param string $name The name (key) for the file cache
     * 
     * @uses delete_transient()
     */
    function cache_clear( $name = "" ) {
        delete_transient( RELATED_SERVICE_COMMENTS_CACHE_PREFIX . '_' . $name );
    }
    
    function cleanup_orphaned_data() {
        global $wpdb;
        $cleaned_rows = 0;
        
        /**
         * Delete all the commentmeta that are from this plugin and have a 
         * missing comment association.
         */
        $sql = "
            DELETE cm FROM $wpdb->commentmeta as cm
            LEFT JOIN $wpdb->comments as c ON cm.comment_id = c.comment_ID
            AND c.comment_agent LIKE %s
                
            WHERE cm.meta_key LIKE %s
            AND c.comment_ID IS NULL
        ";
        $cleaned_rows += $wpdb->query( $wpdb->prepare( $sql, 'Created by ' . $this->namespace . '%', $this->namespace . '%' ) );
        
        
        /**
         * Delete all the commentmeta (parent associations) that are from this plugin and have a 
         * missing comment meta association.
         * 
         * Notice the extra subquery selected as x. see this post
         * for more info about it: http://www.xaprb.com/blog/2006/04/30/how-to-optimize-subqueries-and-joins-in-mysql/
         */
        $sql = "
            DELETE FROM $wpdb->commentmeta 
            WHERE $wpdb->commentmeta.meta_id IN ( 
                SELECT id FROM (
                    SELECT cm.meta_id as id FROM $wpdb->commentmeta as cm
                    LEFT JOIN $wpdb->comments as c ON cm.comment_id = c.comment_ID
                    AND c.comment_agent LIKE %s
                                
                    WHERE cm.meta_key = %s
                    AND (
                        SELECT COUNT( comment_id ) 
                        FROM $wpdb->commentmeta as cm1 
                        WHERE cm1.comment_id = cm.comment_id
                        AND cm1.meta_key = %s
                    ) = 0 
                ) as x
            )
        ";
        $cleaned_rows += $wpdb->query( $wpdb->prepare( 
             $sql, 'Created by ' . $this->namespace . '%', 
             $this->namespace . '_parent_id',
             $this->namespace . '_id'
         ) );
                 
        
        /**
         * Delete all the comments that are from this plugin and have a 
         * missing commentmeta association.
         */
         $sql = "
            DELETE c FROM $wpdb->comments as c
            LEFT JOIN $wpdb->commentmeta as cm ON cm.comment_id = c.comment_ID
            AND cm.meta_key LIKE %s
            
            WHERE c.comment_agent LIKE %s
            AND cm.meta_id IS NULL
         ";
         
         $cleaned_rows += $wpdb->query( $wpdb->prepare( $sql, $this->namespace . '%', 'Created by ' . $this->namespace . '%' ) );
         
         
         /**
          * Delete all comments where the parent_id IS NOT 0. And there is no related_service_comments_parent_id meta row.
          * In other words, if commentmeta that links a child to a parent post is missing, but the parent
          * post is still present, then we should remove the post in question. It will be re-generated
          * on the next run of the plugin.
          */
         $sql = "
            DELETE c FROM $wpdb->comments as c
            LEFT JOIN $wpdb->commentmeta as cm ON cm.comment_id = c.comment_ID
            AND cm.meta_key = %s
            
            WHERE c.comment_agent LIKE %s
            AND cm.meta_id IS NULL
            AND c.comment_parent != 0
         ";
         $cleaned_rows += $wpdb->query( $wpdb->prepare( $sql, $this->namespace . '_parent_id', 'Created by ' . $this->namespace . '%' ) );
          
         
         // Log a summary of the cleanup actions.
         if( $cleaned_rows > 0 ) {
             // If something was indeed cleaned...
             $this->log_entry( sprintf( _n( '%d thing was cleaned up...', '%d things were cleaned up...', $cleaned_rows, $this->namespace ), $cleaned_rows ), array( 'summary' ) );
         } else{
             // If nothing needed to be cleaned
             $this->log_entry( __( 'Nothing needed to be cleaned up...', $this->namespace ), array( 'summary' ) );
         }
    }

    /**
     * Comment Prefix
     * 
     * @param string $service eg: "From YouTube"
     * @param string $slug
     * @param string $url
     * @param string $title
     * 
     * @return string
     */
    function comment_prefix( $service, $slug, $url, $title ) {
        $comment_prefix = '<div class="' . $this->namespace . '-prefix ' . $slug . '">';
            $comment_prefix .= '<span class="' . $this->namespace . '-prefix-service">' . $service . '</span>';
            $comment_prefix .= '<span class="' . $this->namespace . '-prefix-link">';
                $comment_prefix .= '<a href="' . $url . '" target="_blank" rel="nofollow">' . $title . '</a>';
            $comment_prefix .= '</span>';
        $comment_prefix .= '</div>';
        
        return $comment_prefix;
    }
    
    /**
     * Append some custom (namespaced) cron schedules to
     * the default list of 3 or so.
     * This loops through our list of custom 
     * cron intervals and adds each one.
     * 
     * @uses $this->update_schedules
     * @uses RELATED_SERVICE_COMMENTS_CRON_PREFIX
     * 
     * @param array $schedules
     * 
     * @return array $schedules
     */
    function cron_add_custom_schedules( $schedules ) {
        foreach( (array) $this->update_schedules as $slug => $time_name ){
            if( $slug != 'never' ) {
                $schedules[ RELATED_SERVICE_COMMENTS_CRON_PREFIX . '_' . $slug ] = array(
                    'interval' => $time_name['time'],
                    'display' => $time_name['name']
                );
            }
        }
        return $schedules;
    }
    
    function cron_import_comments() {
        $this->log_entry( __( "Updating the comments..." ) );
        $this->save_comments_for_services();
        $this->log_entry( __( "Done updating." ) );
    }
    
    /**
     * Hook into register_deactivation_hook action
     * 
     * Put code here that needs to happen when your plugin is deactivated
     */
    static function deactivate() {
        // Do deactivation actions
    }
    
    /**
     * Delete All Plugin Comments
     * 
     * Deletes all the associated comment meta and comments.
     * Does not trash or change status, this function is a 
     * complete purge of the MySQL data that the plugin creates 
     * in the comment tables.
     * 
     * @global $wpdb
     * 
     * @uses $this->log_entry()
     * @uses $wpdb->prepare()
     * @uses $wpdb->query()
     * @uses _n()
     * 
     * @return integer The number of records deleted.
     */
    function delete_all_plugin_comments() {
        global $wpdb;
        $deleted_rows = 0;
        $data = (array) $this->_sanitize( $_REQUEST );
        
        if( isset( $data['delete_cache'] ) && ( $data['delete_cache'] == 'yes' ) ) {
            /**
             * Delete all the transients from this plugin
             */
            $delete_transients_sql = "
                DELETE options FROM $wpdb->options as options
                WHERE options.option_name LIKE %s
                OR options.option_name LIKE %s
            ";
            $transients_deleted = (int) $wpdb->query( $wpdb->prepare(
                $delete_transients_sql,
                '_transient_' . RELATED_SERVICE_COMMENTS_CACHE_PREFIX . '_%',
                '_transient_timeout_' . RELATED_SERVICE_COMMENTS_CACHE_PREFIX . '_%'
            ) );
            $deleted_rows += $transients_deleted;
            $this->log_entry( sprintf( _n( 'Deleted %d cache (transient) entry...', 'Deleted %d cache (transient) entries...', $transients_deleted ), $transients_deleted ) );
        }
        
        if( isset( $data['delete_postmeta'] ) && ( $data['delete_postmeta'] == 'yes' ) ) {
            /**
             * Delete all the postmeta that is from this plugin
             */
            $delete_postmeta_sql = "
                DELETE pm FROM $wpdb->postmeta as pm
                WHERE pm.meta_key LIKE %s
            ";
            $postmeta_deleted = (int) $wpdb->query( $wpdb->prepare( $delete_postmeta_sql, $this->namespace . '%' ) );
            $deleted_rows += $postmeta_deleted;
            $this->log_entry( sprintf( _n( 'Deleted %d postmeta entry...', 'Deleted %d postmeta entries...', $postmeta_deleted ), $postmeta_deleted ) );
        }
        
        if( isset( $data['delete_comments'] ) && ( $data['delete_comments'] == 'yes' ) ) {
            /**
             * Delete all the comments that are from this plugin
             */
            $delete_comments_sql = "
                DELETE c FROM $wpdb->comments as c
                WHERE c.comment_agent LIKE %s
            ";
            $comments_deleted = (int) $wpdb->query( $wpdb->prepare( $delete_comments_sql, 'Created by ' . $this->namespace . '%' ) );
            $deleted_rows += $comments_deleted;
            $this->log_entry( sprintf( _n( 'Deleted %d comment...', 'Deleted %d comments...', $comments_deleted ), $comments_deleted ) );
            
            /**
             * Delete all the comment meta that is from this plugin
             */
            $delete_commentmeta_sql = "
                DELETE cm FROM $wpdb->commentmeta as cm
                WHERE cm.meta_key LIKE %s
            ";
            $commentmeta_deleted = (int) $wpdb->query( $wpdb->prepare( $delete_commentmeta_sql, $this->namespace . '%' ) );
            $deleted_rows += $commentmeta_deleted;
            $this->log_entry( sprintf( _n( 'Deleted %d comment meta entry...', 'Deleted %d rows of comment meta entries...', $commentmeta_deleted ), $commentmeta_deleted ) );
        }
        
        if( $deleted_rows > 0 ) {
            $this->log_entry( sprintf( _n( '%d database record was deleted.', '%d total database records were deleted.', $deleted_rows ), $deleted_rows ) );
        } else {
            $this->log_entry( __( 'Nothing was deleted.', $this->namespace ) );
        }
        
        
        return $deleted_rows;
    }
    
    
    function email_admin( $subject, $message, $html = true ) {
        add_filter( 'wp_mail_content_type', create_function( '' , 'return "text/html";' ) );
        $headers = 'From: ' . get_option('blogname') . ' <server@' . $_SERVER['HTTP_HOST'] . '>' . "\r\n";
        return wp_mail( $this->admin_name . ' <' . $this->admin_email_address . '>', $subject, $message, $headers );
    }

    function ajax_fetch_content_preview() {
        // Verify the nonce!
        if( !wp_verify_nonce( $_REQUEST['fetch_content_preview_nonce'], "{$this->namespace}_fetch_content_preview" ) )
            wp_die( __( "Unauthorized request!", $this->namespace ) );
        
        $post_id = (int) $_REQUEST['post_id'];
        $first_load = (boolean) $_REQUEST['first_load'];
        
        // Checked matches for the services
        $youtube_matches = array();
        $checked_youtube_matches = array();
        if( isset( $_REQUEST['youtube_matches'] ) )
            $youtube_matches = (array) $_REQUEST['youtube_matches'];
        if( isset( $_REQUEST['checked_youtube_matches'] ) )
            $checked_youtube_matches = (array) $_REQUEST['checked_youtube_matches'];
        
        $fivehundred_pixels_matches = array();
        $checked_fivehundred_pixels_matches = array();
        if( isset( $_REQUEST['fivehundred_pixels_matches'] ) )
            $fivehundred_pixels_matches = (array) $_REQUEST['fivehundred_pixels_matches'];
        if( isset( $_REQUEST['checked_fivehundred_pixels_matches'] ) )
            $checked_fivehundred_pixels_matches = (array) $_REQUEST['checked_fivehundred_pixels_matches'];
        
        $dribbble_matches = array();
        $checked_dribbble_matches = array();
        if( isset( $_REQUEST['dribbble_matches'] ) )
            $dribbble_matches = (array) $_REQUEST['dribbble_matches'];
        if( isset( $_REQUEST['checked_dribbble_matches'] ) )
            $checked_dribbble_matches = (array) $_REQUEST['checked_dribbble_matches'];
        
        // Delete the post meta if nothing is checked 
        if( empty( $checked_dribbble_matches ) && !$first_load )
            delete_post_meta( $post_id, "{$this->namespace}_dribbble_ids" );
        
        // Check for matches...
        $dribbble_matches = $this->output_dribbble_matches( $post_id, $dribbble_matches, $checked_dribbble_matches );
        // If matches are found, output them.
        if( !empty( $dribbble_matches ) ) {
            echo '<h4>' . __( "Dribbble", $this->namespace ) . '</h4>';
            echo '<ul>' . self::$html_newline;
            echo $dribbble_matches;
            echo '</ul>' . self::$html_newline;
        }
        
        
        
        // Delete the post meta if nothing is checked 
        if( empty( $checked_youtube_matches ) && !$first_load )
            delete_post_meta( $post_id, "{$this->namespace}_youtube_ids" );
        
        // Check for matches...
        $youtube_matches = $this->output_youtube_matches( $post_id, $youtube_matches, $checked_youtube_matches );
        // If matches are found, output them.
        if( !empty( $youtube_matches ) ) {
            echo '<h4>' . __( "YouTube", $this->namespace ) . '</h4>';
            echo '<ul>' . self::$html_newline;
            echo $youtube_matches;
            echo '</ul>' . self::$html_newline;
        }
        
        
        
        // Delete the post meta if nothing is checked 
        if( empty( $checked_fivehundred_pixels_matches ) && !$first_load )
            delete_post_meta( $post_id, "{$this->namespace}_fivehundred_pixels_ids" );
        
        // Check for matches...
        $fivehundred_pixels_matches = $this->output_fivehundred_pixels_matches( $post_id, $fivehundred_pixels_matches, $checked_fivehundred_pixels_matches );
        // If matches are found, output them.
        if( !empty( $fivehundred_pixels_matches ) ) {
            echo '<h4>' . __( "500px", $this->namespace ) . '</h4>';
            echo '<ul>' . self::$html_newline;
            echo $fivehundred_pixels_matches;
            echo '</ul>' . self::$html_newline;
        }
        
        // As long as we found some matches, display a notice explaining the checks.
        if( !empty( $youtube_matches ) || !empty( $fivehundred_pixels_matches ) || !empty( $dribbble_matches ))
            echo $this->add_checked_items_status_message();
        
        exit;
    }
    
    /**
     * Filter Avatar
     * 
     * Filters the WordPress get_avatar() function.
     * 
     * @param string $avatar (<img /> tag) from WordPress
     * @param object $comment from WordPress
     * @param integer $size of the avatar in pixels
     * @param string $default url of the avatar as defined by WordPress
     * @param string $alt text for the image
     * 
     * @return string image tag for the user's avatar.
     */
    public function filter_avatar( $avatar, $comment, $size, $default, $alt ){
        // TODO: Figure out a better way to query this... Perhaps with request level caching and pre-fetching
        
        /**
         * Only fetch the comment meta if the comment object is an object.
         * There are some cases where it isn't, and we don't want to invoke
         * anything if it is not.
         */
        if( is_object( $comment ) )
            $new_avatar = get_comment_meta( $comment->comment_ID, $this->namespace . '_avatar', true );
        
        // Only modify the avatar if this plugin found one of its own.
        if( !empty( $new_avatar ) ) {
            // Extract the default argument from the Gravatar and replace it. 
            preg_match('/src=[\'"]([^"\']+)/', $avatar, $matches);
            $gravatar_url = $matches[1];
            $new_gravatar_url = preg_replace( '/d=([^&]+)/', 'd=' . urlencode( $new_avatar ), $gravatar_url );
            
            return preg_replace('/src=([\'"])([^"\']+)/', "src=$1{$new_gravatar_url}", $avatar );
        }
        
        return $avatar;
    }
    
    /**
     * Fetches the Dribbble comments, then caches them.
     * 
     * @param string $shot_id
     * 
     * @return array The comments
     */
    function get_dribbble_comments( $shot_id ) {
        $formatted_comments = array();
        
        $comment_prefix_service = $this->get_option( 'dribbble_comment_prefix' );
        
        // Create a cache key
        $cache_key = "dribbble-comments-{$shot_id}";
        
        // Attempt to read the cache for the response
        $response = $this->cache_read( $cache_key );
        
        if( !$response ) {
            $url = "http://api.dribbble.com/shots/{$shot_id}/comments";
            $response = wp_remote_get( $url, array( 'sslverify' => false ) );
            
            // Only update the cache if this is not an error
            if( !is_wp_error( $response ) ) {
                $this->cache_write( $cache_key, $response, $this->cache_duration );
            }
        }
        
        $comments_object = json_decode( $response['body'] );
        
        if( isset( $comments_object->comments ) && !empty( $comments_object->comments ) ){
                
            $shot_meta = $this->get_dribbble_shot_meta( $shot_id );
            
            $comment_prefix = $this->comment_prefix( $comment_prefix_service, 'dribbble', 'http://dribbble.com/shots/' . $shot_id, $shot_meta['title'] );
            
            foreach( (array) $comments_object->comments as $key => $comment ) {
                
                $avatar = ( preg_match( '/^http/', $comment->player->avatar_url ) ) ? $comment->player->avatar_url : false ;
                
                $formatted_comments[$key]['comment_id'] = $comment->id;
                $formatted_comments[$key]['comment_hash'] = md5( 'dribbble' . $comment->id ); // Salt it a bit... Mmmmmm... salt!
                $formatted_comments[$key]['author_name'] = $comment->player->name;
                $formatted_comments[$key]['author_username'] = $comment->player->username;
                $formatted_comments[$key]['author_uri'] = $comment->player->url;
                $formatted_comments[$key]['author_avatar'] = $avatar;
                $formatted_comments[$key]['user_id'] = $comment->player->id;
                $formatted_comments[$key]['user_fake_email'] = md5( $comment->player->username ) . '.fake@dribbble.com';
                $formatted_comments[$key]['comment'] = $comment_prefix . ' ' . $comment->body;
                $formatted_comments[$key]['created'] = $comment->created_at;
                $formatted_comments[$key]['updated'] = $comment->created_at;
            }
        }
        return $formatted_comments;
        
        return false;
    }
    
    
    /**
     * Fetches the 500px comments, then caches them.
     * 
     * @param string $photo_id
     * 
     * @return array The comments
     */
    function get_fivehundred_pixels_comments( $photo_id ) {
        $formatted_comments = array();
        
        $comment_prefix_service = $this->get_option( 'fivehundred_pixels_comment_prefix' );
        
        // Create a cache key
        $cache_key = "fivehundred_pixels-comments-{$photo_id}";
        
        // Attempt to read the cache for the response
        $response = $this->cache_read( $cache_key );
        
        if( !$response ) {
            $url = "https://api.500px.com/v1/photos/{$photo_id}?comments=1&consumer_key=" . RELATED_SERVICE_COMMENTS_500PX_KEY;
            $response = wp_remote_get( $url, array( 'sslverify' => false ) );
            
            // Only update the cache if this is not an error
            if( !is_wp_error( $response ) ) {
                $this->cache_write( $cache_key, $response, $this->cache_duration );
            }
        }
        
        $comments_object = json_decode( $response['body'] );
        
        if( isset( $comments_object->comments ) && !empty( $comments_object->comments ) ){
            // Add the remaining comment prefix info (there's no need for a meta query in this case)
            $comment_prefix = $this->comment_prefix( $comment_prefix_service, 'fivehundred_pixels', 'http://500px.com/photo/' . $comments_object->photo->id, $comments_object->photo->name );
            
            foreach( (array) $comments_object->comments as $key => $comment ) {
                $reply_to = false;
                if( $comment->parent_id )
                    $reply_to = $comment->parent_id;
                
                if( $reply_to ){
                    $formatted_comments[$key]['reply_to_comment_id'] = $reply_to;
                    $formatted_comments[$key]['reply_to_comment_hash'] = md5( '500px' . $reply_to ); // Salt it a bit... Mmmmmm... salt!
                }
                
                $avatar = ( preg_match( '/^http/', $comment->user->userpic_url ) ) ? $comment->user->userpic_url : false ;
                
                $formatted_comments[$key]['comment_id'] = $comment->id;
                $formatted_comments[$key]['comment_hash'] = md5( '500px' . $comment->id ); // Salt it a bit... Mmmmmm... salt!
                $formatted_comments[$key]['author_name'] = $comment->user->fullname;
                $formatted_comments[$key]['author_username'] = $comment->user->username;
                $formatted_comments[$key]['author_uri'] = 'http://500px.com/' . $comment->user->username;
                $formatted_comments[$key]['author_avatar'] = $avatar;
                $formatted_comments[$key]['user_id'] = $author->{'yt$userId'}->{'$t'};
                $formatted_comments[$key]['user_fake_email'] = md5( $comment->user->username ) . '.fake@500px.com';
                $formatted_comments[$key]['comment'] = $comment_prefix . ' ' . $comment->body;
                $formatted_comments[$key]['created'] = $comment->created_at;
                $formatted_comments[$key]['updated'] = $comment->created_at;
            }
        }
        return $formatted_comments;
        
        return false;
    }
    
    /**
     * Retrieve the stored plugin option or the default if no user specified value is defined
     * 
     * @param string $option_name The name of the option you wish to retrieve
     * 
     * @uses get_option()
     * 
     * @return mixed Returns the option value or false(boolean) if the option is not found
     */
    function get_option( $option_name, $reload = false ) {
        // If reload is true, kill the existing options value so it gets fetched fresh.
        if( $reload )
            $this->options = null;
        
        // Load option values if they haven't been loaded already
        if( !isset( $this->options ) || empty( $this->options ) ) {
            $this->options = get_option( $this->option_name, $this->defaults );
        }
        
        if( isset( $this->options[$option_name] ) ) {
            return $this->options[$option_name];    // Return user's specified option value
        } elseif( isset( $this->defaults[$option_name] ) ) {
            return $this->defaults[$option_name];   // Return default option value
        }
        return false;
    }
    
    /**
     * Fetches the YouTube comments, then caches them.
     * 
     * @param string $video_id
     * 
     * @return array The comments
     */
    function get_youtube_comments( $video_id ) {
        $formatted_comments = array();
        
        $video_meta = $this->get_video_meta_from_url( $this->youtube_url_from_id( $video_id ) );
        $video_url = $video_meta['permalink'];
        $video_title = $video_meta['title'];
        
        $comment_prefix = $this->comment_prefix( $this->get_option( 'youtube_comment_prefix' ), 'youtube', $video_url, $video_title );
        
        // Create a cache key
        $cache_key = "youtube-comments-{$video_id}";
        
        // Attempt to read the cache for the response
        $response = $this->cache_read( $cache_key );
        
        if( !$response ) {
            $url = "http://gdata.youtube.com/feeds/api/videos/{$video_id}/comments?v=2&alt=json";
            $response = wp_remote_get( $url, array( 'sslverify' => false ) );
            
            // Only update the cache if this is not an error
            if( !is_wp_error( $response ) ) {
                $this->cache_write( $cache_key, $response, $this->cache_duration );
            }
        }
        
        $comments_object = json_decode( $response['body'] );
        
        if( isset( $comments_object->feed->entry ) && !empty( $comments_object->feed->entry ) ){
            foreach( (array) $comments_object->feed->entry as $key => $comment ) {
                $author = reset( $comment->author );
                $reply_to = false;
                $comment_id = false;
                foreach( $comment->link as $link ) {
                    if( preg_match( '/in-reply-to/', $link->rel ) ){
                        $reply_to = $link->href;
                    }
                    if( $link->rel == 'self' ) {
                        $comment_id = $link->href;
                    }
                }
                
                if( $reply_to ){
                    $formatted_comments[$key]['reply_to_comment_id'] = $reply_to;
                    $formatted_comments[$key]['reply_to_comment_hash'] = md5( 'YouToob' . $reply_to ); // Again, Mmmmm... tasty salt!
                }
                
                // Construct a YouTube Author URI
                $uri_segments = explode( '/', $author->uri->{'$t'} );
                $username = end( $uri_segments );
                $author_uri = "http://www.youtube.com/user/{$username}";
                
                $formatted_comments[$key]['comment_id'] = $comment_id;
                $formatted_comments[$key]['comment_hash'] = md5( 'YouToob' . $comment_id ); // Again, Mmmmm... tasty salt!
                $formatted_comments[$key]['author_name'] = $author->name->{'$t'};
                $formatted_comments[$key]['author_username'] = $username;
                $formatted_comments[$key]['author_uri'] = $author_uri;
                /**
                 * TODO: Add an option that allows the plugin user to 
                 * provide a default YouTube avatar (Until Google adds support for these)
                 * 
                 * $formatted_comments[$key]['author_avatar'] = '';
                 * 
                 */
                $formatted_comments[$key]['user_id'] = $author->{'yt$userId'}->{'$t'};
                $formatted_comments[$key]['user_fake_email'] = md5( $formatted_comments[$key]['user_id'] ) . '.fake@youtube.com';
                $formatted_comments[$key]['comment'] = $comment_prefix . ' ' . $comment->content->{'$t'};
                $formatted_comments[$key]['created'] = $comment->published->{'$t'};
                $formatted_comments[$key]['updated'] = $comment->updated->{'$t'};
            }
        }
        return $formatted_comments;
        
        return false;
    }
    
    /**
     * Get Username Maps for (service)
     * 
     * Parses the username map given on the settings page so 
     * it can be used during comment processing. The idea is to map the 
     * foreign username to a local user for attribution and correct avatar
     * and user meta attribution.
     * 
     * @param $service (string) A service slug
     * 
     * @return $maps (array) An associative array keyed on the service username
     */
    function get_username_maps( $service ) {
        $maps = array();
        $maps_text = $this->get_option( $service . '_username_map' );
        $maps_lines = explode( "\n", $maps_text );
        
        foreach( $maps_lines as $line ) {
            $map = explode( ',', $line );
            $blog_username = trim( $map[0] );
            $service_username = trim( $map[1] );
            $maps[$service_username] = $blog_username;
        }
        
        return $maps;
    }
    
    function get_dribbble_shot_meta( $shot_id ) {
        $cache_key = $shot_id . '_dribbble';
        
        // Attempt to read the cache for the response
        $response = $this->cache_read( $cache_key );
        
        if( !$response ) {
            $url = 'http://api.dribbble.com/shots/' . $shot_id;
            $response = wp_remote_get( $url, array( 'sslverify' => false ) );
            
            // Only update the cache if this is not an error
            if( !is_wp_error( $response ) ) {
                $this->cache_write( $cache_key, $response, $this->cache_duration );
            }
        }
        
        if( isset( $response['body'] ) && !empty( $response['body'] ) ) {
            $response_json = json_decode( $response['body'], true );
            return $response_json;
        }
        
        return false;
    }
    
    function get_fivehundred_pixels_photo_meta( $photo_id ) {
        $cache_key = $photo_id . '_500px';
        
        // Attempt to read the cache for the response
        $response = $this->cache_read( $cache_key );
        
        if( !$response ) {
            $url = 'https://api.500px.com/v1/photos/' . $photo_id . '?image_size=4&comments=1&consumer_key=' . RELATED_SERVICE_COMMENTS_500PX_KEY;
            $response = wp_remote_get( $url, array( 'sslverify' => false ) );
            
            // Only update the cache if this is not an error
            if( !is_wp_error( $response ) ) {
                $this->cache_write( $cache_key, $response, $this->cache_duration );
            }
        }
        
        if( isset( $response['body'] ) && !empty( $response['body'] ) ) {
            $response_json = json_decode( $response['body'], true );
            return $response_json;
        }
        
        return false;
    }
    
    /**
     * Attempts to fetch the meta for an item's ID, 
     * armed with the service slug.
     * 
     * @param integer $id
     * @param string $service (slug)
     * 
     * @uses $this->get_video_meta_from_url()
     * @uses $this->youtube_url_from_id()
     * @uses $this->get_fivehundred_pixels_photo_meta()
     * @uses $this->get_dribbble_shot_meta()
     * 
     * @return mixed Returns false on failure, the passed in ID on soft failure and an <a> tag on success.
     */
    function get_permalink_from_id_and_service( $id, $service ) {
        switch( $service ) {
            case 'youtube':
                $meta = $this->get_video_meta_from_url( $this->youtube_url_from_id( $id ) );
            break;
            case 'fivehundred_pixels':
                $meta = $this->get_fivehundred_pixels_photo_meta( $id );
            break;
            case 'dribbble':
                $meta = $this->get_dribbble_shot_meta( $id );
            break;
            default:
                return $id;
        }
        
        if( isset( $meta ) && !empty( $meta ) ) {
            return sprintf(
                '<a href="%s" target="_blank">%s</a>',
                $meta['permalink'],
                $meta['title']
            );
        }
        
        return false;
    }
    
    /**
     * Get Tagged Posts
     * 
     * Fetches all the posts that have associated meta for
     * external comment services.
     * 
     * @return array of results grouped by service and post
     */
    function get_tagged_posts() {
        global $wpdb;
        $filtered_results = array();
        
        $meta_keys = array(
            'youtube' => 'related_service_comments_youtube_ids',
            'fivehundred_pixels' => 'related_service_comments_fivehundred_pixels_ids',
            'dribbble' => 'related_service_comments_dribbble_ids',
        );
        
        /**
         * Query the meta for each service type.
         */
        foreach( $meta_keys as $service => $meta_key ) {
            /**
             * This query joins the post meta and the post. If we don't
             * do it this way, then orphaned post meta could cause us to look up data
             * for a post that has been trashed or no longer exists.
             */
            $sql = "
                SELECT post_id, meta_value
                FROM $wpdb->postmeta as m
                LEFT JOIN $wpdb->posts as p ON m.post_id = p.ID
                WHERE m.meta_key = %s
                AND p.post_status IN('draft','publish')
            ";
            $results = $wpdb->get_results( $wpdb->prepare( $sql, $meta_key ) );
            
            /**
             * If a result was found, then we need to
             * iterate each post and fetch the associated meta
             * for that service and post.
             */
            if( $results ) {
                foreach( $results as $result ) {
                    $filtered_results[$service][$result->post_id] = get_post_meta( $result->post_id, $meta_key, true );
                }
            }
        }
        
        return $filtered_results;
    }
    
    /**
     * Get Video Provider Slug From URl
     * 
     * @param string $url of a (standard) video from YouTube, Dailymotion or Vimeo
     * 
     * @return string The slug of the video service.
     */
    function get_video_provider_slug_from_url( $url ){
        // Return a youtube reference for a youtu.be URL
        if( preg_match( '/(youtu\.be)/i', $url ) ){
            return 'youtube';
        }
        
        // Detect the dotcoms normally.
        preg_match( '/((youtube|vimeo|dailymotion)\.com)/i', $url, $matches );
        
        // If nothing was detected...
        if( !isset( $matches[2] ) )
            return false;
        
        $domain = $matches[2];
        return $domain;
    }
    
    /**
     * Get a video's thumbnail
     * 
     * Extract's a video's ID and provider from the URL and retrieves the URL for the
     * thumbnail of the video from its video service's thumbnail service.
     * 
     * @param string $video_url The URL of the video being queried
     * 
     * @uses is_wp_error()
     * @uses cache_read()
     * @uses cache_write()
     * @uses get_video_id_from_url()
     * @uses get_video_provider_slug_from_url()
     * @uses wp_remote_get()
     * 
     * @return string
     */
    function get_video_thumbnail( $video_url ){
        $video_id = $this->get_video_id_from_url( $video_url );
        $video_provider = $this->get_video_provider_slug_from_url( $video_url );
        
        $thumbnail_url = '';
        
        switch( $video_provider ){
            case 'youtube':
                $thumbnail_url = 'http://img.youtube.com/vi/' . $video_id . '/2.jpg';
            break;
            
            case 'dailymotion':
                $thumbnail_url = 'http://www.dailymotion.com/thumbnail/160x120/video/' . $video_id;
            break;
            
            case 'vimeo':
                // Create a cache key
                $cache_key = $video_provider . $video_id . 'vimeo-thumbs';
                
                // Attempt to read the cache
                $_thumbnail_url = $this->cache_read( $cache_key );
                
                // if cache doesn't exist
                if( !$_thumbnail_url ){
                    $response = wp_remote_get( 'http://vimeo.com/api/v2/video/' . $video_id . '.json' );
                    if( !is_wp_error( $response ) ) {
                        $response_json = json_decode( $response['body'] );
                        $video = reset( $response_json );
                        $thumbnail_url = $video->thumbnail_small;
                        
                        // Write the cache
                        $this->cache_write( $cache_key, $thumbnail_url, $this->cache_duration );
                    }
                }
            break;
        }

        return $thumbnail_url;
    }

    /**
     * Get Video ID From URL
     * 
     * @param string $url of a (standard) video from YouTube, Dailymotion or Vimeo
     * 
     * @return string The ID of the video for the service detected.
     */
    function get_video_id_from_url( $url ){
        preg_match( '/(youtube\.com|youtu\.be|vimeo\.com|dailymotion\.com)/i', $url, $matches );
        $domain = $matches[1];
        $video_id = "";
        
        switch( $domain ){
            case 'youtube.com':
                if( preg_match( '/^[^v]+v.(.{11}).*/i', $url, $youtube_matches ) ) {
                    $video_id = $youtube_matches[1];
                } elseif( preg_match( '/youtube.com\/user\/(.*)\/(.*)$/i', $url, $youtube_matches ) ) {
                    $video_id = $youtube_matches[2];
                }
            break;
            
            case 'youtu.be':
                if( preg_match( '/youtu.be\/(.*)$/i', $url, $youtube_matches ) ) {
                    $video_id = $youtube_matches[1];
                }
            break;
            
            case 'vimeo.com':
                preg_match( '/(clip\:)?(\d+).*$/i', $url, $vimeo_matches );
                $video_id = $vimeo_matches[2];
            break;
            
        }

        return $video_id;
    }


    /**
     * Get video meta from a video source URL
     * 
     * Parses a video URL and extracts its associated id, service and API meta data
     * 
     * @param string $url The video source URL
     * 
     * @uses is_wp_error()
     * @uses cache_read()
     * @uses cache_write()
     * @uses get_video_provider_slug_from_url()
     * @uses get_video_id_from_url()
     * @uses wp_remote_get()
     * 
     * @return array
     */
    function get_video_meta_from_url( $url ) {
        $service = $this->get_video_provider_slug_from_url( $url );
        $video_id = $this->get_video_id_from_url( $url );
        
        $video_meta = array(
            'id' => $video_id,
            'service' => $service
        );
        
        // Create a cache key
        $cache_key = "video-meta-{$service}{$video_id}";
        
        // Attempt to read the cache for the response
        $response = $this->cache_read( $cache_key );
        
        if( !$response ) {
            switch( $service ) {
                case "youtube":
                    $url = 'http://gdata.youtube.com/feeds/api/videos/' . $video_id . '?v=2&alt=json';
                break;
                
                case "vimeo":
                    $url = 'http://vimeo.com/api/v2/video/' . $video_id . '.json';
                break;
            }
            
            $response = wp_remote_get( $url, array( 'sslverify' => false ) );
            
            // Only update the cache if this is not an error
            if( !is_wp_error( $response ) ) {
                $this->cache_write( $cache_key, $response, $this->cache_duration );
            }
        }
        
        if( !is_wp_error( $response ) ) {
            $response_json = json_decode( $response['body'] );
            
            if( !empty( $response_json ) ) {
                switch( $service ){
                    case 'youtube':
                        $video_meta['title'] = $response_json->entry->title->{'$t'};
                        $video_meta['permalink'] = 'http://www.youtube.com/watch?v=' . $video_id;
                        $video_meta['description'] = $response_json->entry->{'media$group'}->{'media$description'}->{'$t'};
                        $video_meta['thumbnail'] = 'http://img.youtube.com/vi/' . $video_id . '/mqdefault.jpg';
                        $video_meta['full_image'] = 'http://img.youtube.com/vi/' . $video_id . '/0.jpg';
                        $video_meta['created_at'] = strtotime( $response_json->entry->published->{'$t'} );
                        
                        $video_meta['aspect'] = 'widescreen';
                        if( isset( $response_json->entry->{'media$group'}->{'yt$aspectRatio'} ) )
                            $video_meta['aspect'] = ( $response_json->entry->{'media$group'}->{'yt$aspectRatio'}->{'$t'} == 'widescreen' ) ? 'widescreen' : 'standard';
                        
                        $video_meta['duration'] = $response_json->entry->{'media$group'}->{'yt$duration'}->{'seconds'};
                        
                        if( isset( $response_json->entry->author ) ) {
                            $author = reset( $response_json->entry->author );
                            $video_meta['author_name'] = $author->name->{'$t'};
                            $video_meta['author_url'] = "http://www.youtube.com/user/" . $author->name->{'$t'};
                        }
                    break;
                    
                    case 'vimeo':
                        $video = reset( $response_json );
                        $video_meta['title'] = $video->title;
                        $video_meta['permalink'] = 'http://vimeo.com/' . $video_id;
                        $video_meta['description'] =  $video->description;
                        $video_meta['thumbnail'] = $video->thumbnail_medium;
                        $video_meta['full_image'] = $video->thumbnail_large;
                        $video_meta['author_name'] = $video->user_name;
                        $video_meta['author_url'] = $video->user_url;
                        $video_meta['author_avatar'] = $video->user_portrait_small;
                        $video_meta['aspect'] = $video->height / $video->width;
                        $video_meta['duration'] = $video->duration;
                    break;
                }
            }
        }

        return $video_meta;
    }
    
    /**
     * Initialization function to hook into the WordPress init action
     * 
     * Instantiates the class on a global variable and sets the class, actions
     * etc. up for use.
     */
    static function instance() {
        global $RelatedServiceComments;
        
        // Only instantiate the Class if it hasn't been already
        if( !isset( $RelatedServiceComments ) ) $RelatedServiceComments = new RelatedServiceComments();
    }
    
    /**
     * Log Entry
     * 
     * Logs the message passed in and appends it to
     * a class property (as HTML) for later output or storage.
     * 
     * @param string $log_message
     * @param array $other_logs An array of slugs
     * 
     * @return void
     */
    function log_entry( $log_message, $other_logs = array() ) {
        $this->process_log .= "<p>{$log_message}</p>";
        
        // Loop through eash log slug that was sent into the function.
        foreach( (array) $other_logs as $slug ){
            /**
             * It's a bit silly that PHP throws a warning for this.
             * Essentially, we get a notice if we try to append a string
             * to an unset variable. So if it's already set, we append,
             * else we set it.
             */
            if( isset( $this->other_logs[$slug] ) ){
                $this->other_logs[$slug] .= "<p>{$log_message}</p>";
            } else {
                $this->other_logs[$slug] = "<p>{$log_message}</p>";
            }
        }
    }
    
    /**
     * Outputs the HTML necessary for the YouTube Matches
     * 
     * @param array $matches_array
     * 
     * @return string HTML list items
     */
    function output_dribbble_matches( $post_id, $matches_array, $checked_array ) {
        // Remove duplicates...
        $matches_array = array_unique( $matches_array );
        $checked_array = array_unique( $checked_array );
        
        if( empty( $checked_array ) ){
            $checked_array = (array) get_post_meta( $post_id, "{$this->namespace}_dribbble_ids", true );
        }else{
            update_post_meta( $post_id, "{$this->namespace}_dribbble_ids", $checked_array );
        }
        
        $html = '';
        foreach( $matches_array as $shot_id ) {
            // Get the video meta from the standard URL of the YouTube Video
            $shot_meta = $this->get_dribbble_shot_meta( $shot_id );
            
            // If the current item is checked, then check this box.
            $checked = '';
            if( in_array( $shot_id, $checked_array ) ){
                $checked = ' checked="checked"';
            }
            
            $html .= '<li>' . self::$html_newline;
            
            $html .= '<label>' . self::$html_newline;
            $html .= '<input' . $checked . ' class="dribbble matches" type="checkbox" name="dribbble_related_comment_id[]" value="' . $shot_id . '" />' . self::$html_newline;
            $html .= '<img height="32" src="' . $shot_meta['image_teaser_url'] . '" />';
            $html .= '<span class="title">' . $shot_meta['title'] . '</span>' . self::$html_newline;
            $html .= '</label>' . self::$html_newline;
            
            $html .= '</li>' . self::$html_newline;
        }
        
        return $html;
    }
    
    /**
     * Outputs the HTML necessary for the YouTube Matches
     * 
     * @param array $matches_array
     * 
     * @return string HTML list items
     */
    function output_youtube_matches( $post_id, $matches_array, $checked_array ) {
        // Remove duplicates...
        $matches_array = array_unique( $matches_array );
        $checked_array = array_unique( $checked_array );
        
        if( empty( $checked_array ) ){
            $checked_array = (array) get_post_meta( $post_id, "{$this->namespace}_youtube_ids", true );
        }else{
            update_post_meta( $post_id, "{$this->namespace}_youtube_ids", $checked_array );
        }
        
        $html = '';
        foreach( $matches_array as $video_id ) {
            // Get the video meta from the standard URL of the YouTube Video
            $video_meta = $this->get_video_meta_from_url( $this->youtube_url_from_id( $video_id ) );
            
            // If the current item is checked, then check this box.
            $checked = '';
            if( in_array( $video_id, $checked_array ) ){
                $checked = ' checked="checked"';
            }
            
            $html .= '<li>' . self::$html_newline;
            
            $html .= '<label>' . self::$html_newline;
            $html .= '<input' . $checked . ' class="youtube matches" type="checkbox" name="youtube_related_comment_id[]" value="' . $video_id . '" />' . self::$html_newline;
            $html .= '<img height="32" src="' . $video_meta['thumbnail'] . '" />';
            $html .= '<span class="title">' . $video_meta['title'] . '</span>' . self::$html_newline;
            $html .= '</label>' . self::$html_newline;
            
            $html .= '</li>' . self::$html_newline;
        }
        
        return $html;
    }
    
    /**
     * Outputs the HTML necessary for the YouTube Matches
     * 
     * @param array $matches_array
     * 
     * @return string HTML list items
     */
    function output_fivehundred_pixels_matches( $post_id, $matches_array, $checked_array ) {
        // Remove duplicates...
        $matches_array = array_unique( $matches_array );
        $checked_array = array_unique( $checked_array );
        
        if( empty( $checked_array ) ){
            $checked_array = (array) get_post_meta( $post_id, "{$this->namespace}_fivehundred_pixels_ids", true );
        }else{
            update_post_meta( $post_id, "{$this->namespace}_fivehundred_pixels_ids", $checked_array );
        }
        
        $html = '';
        foreach( $matches_array as $photo_id ) {
            // Get the video meta from the standard URL of the YouTube Video
            $photo_meta = $this->get_fivehundred_pixels_photo_meta( $photo_id );
            
            // If the current item is checked, then check this box.
            $checked = '';
            if( in_array( $photo_id, $checked_array ) ){
                $checked = ' checked="checked"';
            }
            
            $html .= '<li>' . self::$html_newline;
            
            $html .= '<label>' . self::$html_newline;
            $html .= '<input' . $checked . ' class="fivehundred_pixels matches" type="checkbox" name="fivehundred_pixels_related_comment_id[]" value="' . $photo_id . '" />' . self::$html_newline;
            $html .= '<img height="32" src="' . $photo_meta['photo']['image_url'] . '" />';
            $html .= '<span class="title">' . $photo_meta['photo']['name'] . '</span>' . self::$html_newline;
            $html .= '</label>' . self::$html_newline;
            
            $html .= '</li>' . self::$html_newline;
        }
        
        return $html;
    }
    
    /**
     * Hook into plugin_action_links filter
     * 
     * Adds a "Settings" link next to the "Deactivate" link in the plugin listing page
     * when the plugin is active.
     * 
     * @param object $links An array of the links to show, this will be the modified variable
     * @param string $file The name of the file being processed in the filter
     */
    function plugin_action_links( $links, $file ) {
        if( $file == plugin_basename( RELATED_SERVICE_COMMENTS_DIRNAME . '/' . basename( __FILE__ ) ) ) {
            $old_links = $links;
            $new_links = array(
                "settings" => '<a href="options-general.php?page=' . $this->namespace . '">' . __( 'Settings' ) . '</a>'
            );
            $links = array_merge( $new_links, $old_links );
        }
        
        return $links;
    }
    
    /**
     * Route the user based off of environment conditions
     * 
     * This function will handling routing of form submissions to the appropriate
     * form processor.
     * 
     * @uses RelatedServiceComments::_admin_options_update()
     */
    function route() {
        $uri = $_SERVER['REQUEST_URI'];
        $protocol = isset( $_SERVER['HTTPS'] ) ? 'https' : 'http';
        $hostname = $_SERVER['HTTP_HOST'];
        $url = "{$protocol}://{$hostname}{$uri}";
        $is_post = (bool) ( strtoupper( $_SERVER['REQUEST_METHOD'] ) == "POST" );
        
        // Check if a nonce was passed in the request
        if( isset( $_REQUEST['_wpnonce'] ) ) {
            $nonce = $_REQUEST['_wpnonce'];
            
            // Handle POST requests
            if( $is_post ) {
                if( wp_verify_nonce( $nonce, "{$this->namespace}-update-options" ) ) {
                    $this->_admin_options_update();
                }
                
                /**
                 * If we get a valid nonce coming from the update-comments-now
                 * form then we can go ahead and manually invoke the update routine.
                 */
                if( wp_verify_nonce( $nonce, "{$this->namespace}-update-comments-now" ) ) {
                    $this->log_entry( __( "Manually updating the comments..." ) );
                    $this->save_comments_for_services();
                    $this->log_entry( __( "Done manually updating." ) );
                }
                
                /**
                 * If we get a request to delete all the comments and comment meta that 
                 * this plugin is responsibel for, well then we have to do that.
                 */
                if( wp_verify_nonce( $nonce, "{$this->namespace}-delete-comments" ) ) {
                    $this->log_entry( __( "Running deletion routine...", $this->namespace ) );
                    $this->delete_all_plugin_comments();
                    $this->log_entry( __( "Finished running deletion routine.", $this->namespace ) );
                }
            } 
            // Handle GET requests
            else {
                // Nothing here yet...
            }
        }
    }
    
    /**
     * Schedule Cron
     * 
     * Unschedules the cron and then attempts 
     * to add a new cron if one was requested.
     * 
     * @uses RELATED_SERVICE_COMMENTS_CRON_PREFIX
     * @uses wp_get_schedules()
     * @uses wp_clear_scheduled_hook()
     * @uses wp_schedule_event()
     * @uses current_time()
     * @uses $this->get_option()
     * 
     * @return mixed Returns null on success, and false on failure
     */
    function schedule_cron() {
        // Grab the wordpress schedules available.
        $schedules = wp_get_schedules();
        
        // Grab the schedule requested by the user.
        $requested_schedule = RELATED_SERVICE_COMMENTS_CRON_PREFIX . '_' . $this->get_option( 'update_schedule', true );
        
        // Unset any existing cron at this time...
        wp_clear_scheduled_hook( RELATED_SERVICE_COMMENTS_CRON_PREFIX );
        
        // Maybe schedule the new cron...
        if( array_key_exists( $requested_schedule, $schedules ) ){
            return wp_schedule_event( time() + $this->first_cron_offset, $requested_schedule, RELATED_SERVICE_COMMENTS_CRON_PREFIX );
        }
        
        return false;
    }
    
    /**
     * Save Comments For Services
     * 
     * This is the Godzilla function. It requires no arguments
     * and is a real self-starter. A go getter if you will.
     * 
     * TODO: Call this with Cron
     */
    function save_comments_for_services() {
        global $wpdb;
        $comment_ids = array();
        
        // Cleanup orphaned data first!
        $this->cleanup_orphaned_data();
        
        // Fetch the tagged posts...
        $services_and_posts = $this->get_tagged_posts();
        
        // Log the services found
        $count_services = count( $services_and_posts );
        $this->log_entry( sprintf( _n( 'Found %d service with associated posts:', 'Found %d services with associated posts:', $count_services, $this->namespace ), $count_services ) . ' ' . implode( ', ', array_keys( $services_and_posts ) ) );
        
        $comments_added = 0;
        $comments_updated = 0;
        
        // Loop through each service and the corresponding posts (WordPress Posts)
        foreach( $services_and_posts as $service => $posts ) {
            // Loop through each post and it's corresponding content (IDs of the remote content. YouTube IDs, Photo IDs, etc.)
            foreach( $posts as $post_id => $content_ids ) {
                // Loop through each content ID (For YouTube an example would be: '5_sfnQDr1-o' amd for 500px an example might be '1318308')
                foreach( $content_ids as $content_id ) {
                    $comments = (array) call_user_func( array( &$this, "get_{$service}_comments" ), $content_id );
                    
                    // Log number of comments for this post and the service...
                    $count_comments = count( $comments );
                    $this->log_entry(
                        sprintf(
                            _n(
                                'Found %d comment for post: %s and item: %s from %s',
                                'Found %d comments for post: %s and item: %s from %s',
                                $count_comments
                            ),
                            $count_comments,
                            '<em><a href="' . get_permalink( $post_id ) . '" target="_blank">' . get_the_title( $post_id ) . '</a></em>',
                            $this->get_permalink_from_id_and_service( $content_id, $service ),
                            $service
                        )
                    );
                    
                    /**
                     * Loop through each comment and simply insert them... no hierarchy.
                     * Here's where we take the retrieved comments and loop through them.
                     * ** ALL YOUR COMMENTS ARE BELONG TO US **
                     */
                    foreach( $comments as $comment ) {
                        // Each comment starts as approved
                        $comment_approved = '1';
                        
                        /**
                         * SQL that checks for the existing comment meta...
                         * If there's an existing comment meta we'll need to do an
                         * update instead of an insert. Why not just use an update?
                         * Well... I'm not sure the wp_update_comment() function supports
                         * that so it's better to be safe than sorry.
                         * 
                         * TODO: The comment and the below comment meta should be checked for. Orphaned data is no good here.
                         */
                        $sql = "
                            SELECT cm.comment_id FROM $wpdb->commentmeta as cm 
                            LEFT JOIN $wpdb->comments as c ON c.comment_ID = cm.comment_id
                            WHERE cm.meta_key = %s 
                            AND cm.meta_value = %s
                        ";
                        
                        // Set the "existing" (comment) variable
                        $existing = $wpdb->get_var( $wpdb->prepare( $sql, $this->namespace . '_id', $comment['comment_hash'] ) );
                        
                        if( !empty( $existing ) ) {
                            $sql = "
                                SELECT * FROM $wpdb->comments as c 
                                WHERE c.comment_id = %d 
                            ";
                            
                            // Set the "existing" (comment data) variable
                            $existing_comment = $wpdb->get_row( $wpdb->prepare( $sql, $existing ) );
                            
                                // If the comment was previously trashed, keep it that way
                                if( isset( $existing_comment->comment_approved ) && !empty( $existing_comment->comment_approved ) )
                                    $comment_approved = $existing_comment->comment_approved;
                            
                        }
                        
                        /**
                         * SQL that checks to see that the post still exists...
                         * It turns out that if the post doesn't exist any longer... well,
                         * let's just say bad things happen. Bad things like duplicate data.
                         */
                        $sql = "
                            SELECT ID FROM $wpdb->posts 
                            WHERE ID = %d 
                            AND post_status IN('draft','publish')
                        ";
                        // Set the "post exists" variable
                        $post_exists = $wpdb->get_var( $wpdb->prepare( $sql, $post_id ) );
                            
                            /**
                             * There's no post...
                             * 
                             * Continue means that we skip the rest of this iteration
                             * and then continue with the loop... But you already knew that
                             * you savvy programmer you! jamie3d needs to be reminded.
                             */
                            if( empty( $post_exists ) )
                                continue;
                        
                        
                        /**
                         * Setup the comment data that we are about to insert.
                         */
                        $comment_data = array(
                            'comment_post_ID' => $post_id,
                            'comment_author' => $comment['author_name'],
                            'comment_author_email' => $comment['user_fake_email'],
                            'comment_author_url' => $comment['author_uri'],
                            'comment_content' => $comment['comment'],
                            'comment_type' => '',
                            'comment_parent' => 0,
                            'user_id' => 0,
                            'comment_author_IP' => '127.0.0.1',
                            'comment_agent' => "Created by {$this->namespace} for {$service}",
                            'comment_date' => $comment['created'],
                            'comment_approved' => $comment_approved,
                        );
                        
                        /**
                         * Look up the username map for this service
                         * 
                         * If we find a match, then we can look up the local
                         * user and use that info for the comment instead.
                         * This has the advantage of using the local user's avatar,
                         * role, authorship, etc. When being displayed in the comments.
                         */
                        $username_map = $this->get_username_maps( $service );
                        if( !empty( $username_map ) ) {
                            // Loop through each $username_map entry...
                            foreach( $username_map as $service_username => $blog_username ) {
                                /**
                                 * If one of the potential service usernames matches...
                                 * Then look up the blog author and attribute the comment.
                                 */
                                if( $comment['author_username'] === $service_username ) {
                                    $found = get_user_by( 'login', $blog_username );
                                    if( $found ) {
                                        $user_comment_data = array(
                                            'comment_author' => $found->display_name,
                                            'comment_author_email' => $found->user_email,
                                            'comment_author_url' => $found->user_url,
                                            'user_id' => $found->ID,
                                        );
                                        
                                        $comment_data = array_merge( $comment_data, $user_comment_data );
                                    }
                                }
                            }
                        }
                        
                        
                        /**
                         * If the comment is existing, then we'll update
                         * it instead. (by passing the found ID)
                         */
                        if( $existing ){
                            // Only update the comment if the user wishes...
                            if( $this->update_existing_comment_content ) {
                                // Update the existing comment...
                                $comment_data['comment_ID'] = $existing;
                                
                                /**
                                 * Note:
                                 * As of 3.4.1, One of the attributes that doesn't get 
                                 * overriden is the comment_parent column.
                                 * If the option is added in the future, these lines should
                                 * prevent the comment_parent from being overwritten.
                                 */ 
                                $comment_data['comment_parent'] = null;
                                unset( $comment_data['comment_parent'] );
                                // tldr; Right now they do nothing...
                                
                                // Update the comment... and log it
                                wp_update_comment( $comment_data );
    
                                /**
                                 * Add a bit of comment meta for the user's avatar, but
                                 * only if the avatar field is not empty.
                                 */
                                if( isset( $comment['author_avatar'] ) && !empty( $comment['author_avatar'] ) )
                                    update_comment_meta( $existing, $this->namespace . '_avatar', $comment['author_avatar'] );
                                
                                $comments_updated++;
                            }
                        }else{
                            // Insert a new comment...
                            $inserted_id = wp_insert_comment( $comment_data );
                            
                            // Log the insertion of a comment...
                            $comments_added++;
                            
                            /**
                             * Whether or not the comment was new or old,
                             * we should update the post meta... maybe we only need to
                             * do this for the new comments, but I'm not sure.
                             * 
                             * Turns out it does need to be done for only new comments.
                             * If it's done unconditionally, extra data gets set in the 
                             * case where a missing post is encounterd.
                             */
                            // Add a bit of comment meta about the comment's parent.
                            if( isset( $comment['reply_to_comment_hash'] ) && !empty( $comment['reply_to_comment_hash'] ) )
                                update_comment_meta( $inserted_id, $this->namespace . '_parent_id', $comment['reply_to_comment_hash'] );
                            
                            // Add a bit of comment meta about the service's comment ID.
                            update_comment_meta( $inserted_id, $this->namespace . '_id', $comment['comment_hash'] );
                            
                            // Add a bit of comment meta for the user's avatar
                            if( isset( $comment['author_avatar'] ) && !empty( $comment['author_avatar'] ) )
                                update_comment_meta( $inserted_id, $this->namespace . '_avatar', $comment['author_avatar'] );
                            
                            // Keep track of the inserted ID. We need to update the IDs next.
                            if( !empty( $inserted_id ) ){
                                $comment_ids[$inserted_id] = $comment['comment_hash'];
                            }
                        }
                    } // End of "Loop through each comment and simply insert them... no hierarchy."
                    
                    
                    
                    // Loop through each comment already inserted and apply the parent-child relationship.
                    foreach( $comments as $comment ) {
                        // Setup the reply to parent hash value for later use.
                        $reply_to_comment_hash = false;
                        if( isset( $comment['reply_to_comment_hash'] ) && !empty( $comment['reply_to_comment_hash'] ) )
                            $reply_to_comment_hash = $comment['reply_to_comment_hash'];
                        
                        /**
                         * SQL that checks for parent child relationships
                         */
                        if( $reply_to_comment_hash ) {
                            $sql = "
                                SELECT comment_id, meta_value as parent_id FROM $wpdb->commentmeta 
                                WHERE meta_key = %s 
                                AND meta_value = %s
                            ";
                            $parent_child_result = $wpdb->get_row( $wpdb->prepare( $sql, $this->namespace . '_parent_id', $reply_to_comment_hash ), ARRAY_A );
                        }
                        
                        if( !empty( $parent_child_result ) ) {
                            $child_comment_id = $parent_child_result['comment_id'];
                            $parent_comment_external_id = $parent_child_result['parent_id'];
                            
                            /**
                             * SQL that checks for child comment
                             */
                            $sql = "
                                SELECT comment_id  FROM $wpdb->commentmeta
                                
                                WHERE meta_key = %s 
                                AND meta_value = %s
                            ";
                            $parent_comment_id = $wpdb->get_var( $wpdb->prepare( $sql, $this->namespace . '_id', $parent_comment_external_id ) );
                            
                            /**
                             * It seems that wp_update_comment() does not
                             * allow you to update the comment parent, so we
                             * need to do it manaully.
                             */
                            $wpdb->query( $wpdb->prepare( "UPDATE $wpdb->comments SET comment_parent = %d WHERE $wpdb->comments.comment_ID = %d;", $parent_comment_id, $child_comment_id ) );
                        }
                        
                    } // End of "Loop through each comment already inserted and apply the parent-child relationship."
                } // End of "foreach( $content_ids as $content_id ) {"
            }
        }

        // Log the insertion of the comments...
        $this->log_entry( sprintf( _n( '%d new comment was added.', '%d new comments were added.', $comments_added, $this->namespace ), $comments_added ), array( 'summary' ) );
        $this->log_entry( sprintf( _n( '%d existing comment was updated.', '%d existing comments were updated.', $comments_updated, $this->namespace ), $comments_updated ), array( 'summary' ) );
        
        // Are we supposed to email the admin?
        if( $this->get_option( 'email_report_to_admin' ) == 'yes' ) {
            
            if( $this->get_option( 'email_type' ) == 'summary' ) {
                // Use the summary key only.
                $log = $this->other_logs['summary'];
            } else {
                // Use the full log
                $log = $this->process_log;
            }
            
            // Email the admin, let them know what just happened.
            $subject = sprintf(
                _n( '%s ran at %s and imported %d comment', '%s ran at %s and imported %d new comments', $comments_added ),
                $this->friendly_name,
                date("H:i:s"),
                $comments_added
            );
            $this->email_admin( $subject , $log );
        }
        
        /**
         * TODO: Create a logging function and update a log whenever this is run...
         */
         
    }
    
    /**
     * Register scripts used by this plugin for enqueuing elsewhere
     * 
     * @uses wp_register_script()
     */
    function wp_register_scripts() {
        // Admin JavaScript
        wp_register_script( "{$this->namespace}-admin", RELATED_SERVICE_COMMENTS_URLPATH . "/js/admin.js", array( 'jquery' ), $this->version, true );
    }
    
    /**
     * Register styles used by this plugin for enqueuing elsewhere
     * 
     * @uses wp_register_style()
     */
    function wp_register_styles() {
        // Admin Stylesheet
        wp_register_style( "{$this->namespace}-admin", RELATED_SERVICE_COMMENTS_URLPATH . "/css/admin.css", array(), $this->version, 'screen' );
    }
    
    /**
     * Return a YouTube URL from a YouTube Video ID
     * 
     * @param string $video_id YouTube Video ID
     * 
     * @return string a full URL
     */
    function youtube_url_from_id( $video_id ) {
        return 'http://www.youtube.com/watch?v=' . $video_id;
    }
    
}

if( !isset( $RelatedServiceComments ) ) {
    RelatedServiceComments::instance();
}
register_activation_hook( __FILE__, array( 'RelatedServiceComments', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RelatedServiceComments', 'deactivate' ) );
