<div id="<?php echo $this->namespace; ?>_actions">
	<a class="button" id="<?php echo $this->namespace; ?>-check-related-selection" href="#check">Re-check Content/Save</a>
	<span class="saving message">checking/saving...</span>
</div>
<div class="<?php echo $this->namespace; ?>_detected">
	<h4>Detected items will show up here...</h4>
</div>
<div style="display:none;">
	<?php wp_nonce_field( "{$this->namespace}_fetch_content_preview", 'fetch_content_preview_nonce' ); ?>
</div>
