<script type="text/javascript">var __namespace = '<?php echo $namespace; ?>';</script>
<div class="wrap <?php echo $namespace; ?>">
    <h2><?php echo $page_title; ?></h2>
    <form action="" method="post" id="<?php echo $namespace; ?>-form">
        <?php wp_nonce_field( $namespace . "-update-options" ); ?>
        <h3><?php _e( "These settings control the scheduled import of comments and general options such as email alerts.", $namespace ) ?></h3>
        <ul>
        	<li>
	            <label><?php _e( "Update frequency", $namespace ) ?>
	            	<select name="data[update_schedule]">
            		<?php foreach( (array) $this->update_schedules as $slug => $time_name ): ?>
	            		<option<?php echo ( $update_schedule == $slug ) ? ' selected="selected"' : '' ; ?> value="<?php echo $slug; ?>"><?php echo $time_name['name']; ?></option>
        			<?php endforeach; ?>
	            	</select>
            	</label>
            	<span><?php echo $next_scheduled_cron_time; ?></span>
        	</li>
        	<li>
        		<input<?php echo ( $this->get_option( 'email_report_to_admin' ) == 'yes' ) ? ' checked="checked"' : '' ; ?> id="email_report_to_admin" type="checkbox" name="data[email_report_to_admin]" value="yes" />
        		<label for="email_report_to_admin"><?php printf( __( 'Email a report to: %s when auto-updating comments?', $namespace ), $this->admin_email_address ); ?></label>
        	</li>
        	<li>
        		<label for="email_type"><?php _e( 'Email type', $namespace ); ?></label>
        		<select id="email_type" name="data[email_type]">
        			<option<?php echo ( $email_type == 'summary' ) ? ' selected="selected"' : '' ; ?> value="summary"><?php _e( 'Short Summary', $namespace ); ?></option>
        			<option<?php echo ( $email_type == 'full_log' ) ? ' selected="selected"' : '' ; ?> value="full_log"><?php _e( 'Detailed Log', $namespace ); ?></option>
        		</select>
        	</li>
        	<li>
        		<input<?php echo ( $this->get_option( 'update_existing_comment_content' ) == 'yes' ) ? ' checked="checked"' : '' ; ?> id="update_existing_comment_content" type="checkbox" name="data[update_existing_comment_content]" value="yes" />
        		<label for="update_existing_comment_content"><?php _e( 'Update existing comment content? (comment text will be re-imported, but the ID and status (trash, approved, etc.) will stay the same)', $namespace ); ?></label>
        	</li>
        	<li>
		        <input type="submit" name="submit" class="button-primary" value="<?php _e( "Save Changes", $namespace ) ?>" />
		        <?php _e( "(Saves the above settings)", $namespace ) ?>
		        <p><?php 
		        	printf( __('Note that saving the options will reset the scheduled events and cause them to run %d seconds after you save, regardless of the schedule chosen.', $namespace), $this->first_cron_offset ); 
        		?></p>
        	</li>
    	</ul>
    </form>

	<h2><?php echo __( 'Manual Controls', $namespace ); ?></h2>
    <p><?php 
    	_e('The options below do not save the setting above. They simply run the task with the last saved settings, this is by design.', $namespace ); 
	?></p>
    <p><?php 
    	_e('If you want to change the settings, do so above, save the options and then run the desired function below.', $namespace ); 
	?></p>
    <form action="" method="post" id="<?php echo $namespace; ?>-update-comments-form">
        <?php wp_nonce_field( $namespace . "-update-comments-now" ); ?>
        <h3 class="update-now"><?php _e( "Force an update of your comments", $namespace ) ?></h3>
    	<ul>
        	<li>
		        <input type="submit" name="update_now" class="button" value="<?php _e( "Update Comments Now", $namespace ) ?>" />
        	</li>
    	</ul>
	</form>

    <form action="" method="post" id="<?php echo $namespace; ?>-delete-comments-form">
        <?php wp_nonce_field( $namespace . "-delete-comments" ); ?>
        <h3 class="delete-all"><?php _e( "Delete data this plugin created", $namespace ) ?></h3>
    	<ul>
        	<li>
        		<input id="delete_postmeta" type="checkbox" name="delete_postmeta" value="yes" />
        		<label for="delete_postmeta"><?php _e( "Delete Post Meta?", $namespace ) ?></label>
        	</li>
        	<li>
        		<input id="delete_comments" type="checkbox" name="delete_comments" value="yes" />
        		<label for="delete_comments"><?php _e( "Delete Comments and Comment Meta?", $namespace ) ?></label>
        	</li>
        	<li>
        		<input id="delete_cache" type="checkbox" name="delete_cache" value="yes" />
        		<label for="delete_cache"><?php _e( "Delete Cache? (stored as 'transients' in the database)", $namespace ) ?></label>
        	</li>
        	<li>
		        <input type="submit" name="delete_now" class="button" value="<?php _e( "Delete Data Now", $namespace ) ?>" />
        	</li>
        </ul>
    </form>
    
    <?php if( !empty( $this->process_log ) ): ?>
    <div class="update-log">
    	<h3><?php _e( "This just happened:", $namespace ) ?></h3>
    	<?php echo $this->process_log; ?>
    </div>
    <?php endif; ?>
    
</div>