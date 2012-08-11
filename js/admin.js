/**
 * Admin Control Panel JavaScripts
 * 
 * @version 1.0.0
 * @since 1.0.0
 */

(function($){
	RelatedServiceComments = function(){
		var self = this;
		var editorContent = '';
		var autoRefreshMilliseconds = 30 * 1000;
		var clickTimerMilliseconds = 1.5 * 1000;
		var autoPolling = false;
		var elems = {
			saving: $('#related_service_comments_actions .saving.message')
		};
		
		var youTubeRegex = /(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|[a-zA-Z0-9_\-]+\?v=)([^#\&\?\n<>]*)/gi;
		var youTubeMatches = [];
		
		var fivehundredPixelsRegex = /(\/photo\/|pcdn.500px.net\/)([0-9]+)/gi;
		var fivehundredPixelsMatches = [];
		
		var dribbbleRegex = /(dribbble(\.com|\.s3\.amazonaws\.com\/users\/)[a-zA-Z\/0-9]+(screenshots\/|shots\/))([0-9]+)/gi;
		var dribbbleMatches = [];
		
		this.pollForItems = function(){
			setInterval( function(){
				self.parsePostContent();
			}, autoRefreshMilliseconds );
		}
		
		this.parsePostContent = function(){
			// Disable the form elements
			$('.related_service_comments_detected input').attr("disabled", "disabled");
			elems.saving.addClass("show");
			
			// Grab the content from the editor... (fallback)
			editorContent = $('#wp-content-editor-container .wp-editor-area').val();
			
			// If there's an active editor...
			if (typeof(tinyMCE) != 'undefined' && tinyMCE.activeEditor != null && tinyMCE.activeEditor.isHidden() == false) {
				// Get the content from it.
				editorContent = tinyMCE.activeEditor.getContent();
			}
			
			self.checkContent( editorContent );
		}
		
		this.checkContent = function( editorContent ){
			youTubeMatches = [];
			checkedYouTubeMatches = [];
			while ( match = youTubeRegex.exec( editorContent ) ){
				var videoId = match.pop();
			    youTubeMatches.push( videoId );
			}
			$('.related_service_comments_detected input.youtube:checked').each(function(){
				checkedYouTubeMatches.push( $(this).val() );
			});
			
			fivehundredPixelsMatches = [];
			checkedFivehundredPixelsMatches = [];
			while ( match = fivehundredPixelsRegex.exec( editorContent ) ){
				var photoId = match.pop();
			    fivehundredPixelsMatches.push( photoId );
			}
			$('.related_service_comments_detected input.fivehundred_pixels:checked').each(function(){
				checkedFivehundredPixelsMatches.push( $(this).val() );
			});
			
			dribbbleMatches = [];
			checkedDribbbleMatches = [];
			while ( match = dribbbleRegex.exec( editorContent ) ){
				var shotId = match.pop();
			    dribbbleMatches.push( shotId );
			}
			$('.related_service_comments_detected input.dribbble:checked').each(function(){
				checkedDribbbleMatches.push( $(this).val() );
			});
			
			if( youTubeMatches.length || fivehundredPixelsMatches.length || dribbbleMatches.length ) {
				var nonce = $('#fetch_content_preview_nonce').val();
				$.ajax({
					type: 'POST',
					url: ajaxurl + "?action=related_service_comments_fetch_content_preview",
					data: {
						fetch_content_preview_nonce: nonce,
						post_id: $('#post_ID').val(),
						first_load: self.firstLoad,
						youtube_matches: youTubeMatches,
						checked_youtube_matches: checkedYouTubeMatches,
						dribbble_matches: dribbbleMatches,
						checked_dribbble_matches: checkedDribbbleMatches,
						fivehundred_pixels_matches: fivehundredPixelsMatches,
						checked_fivehundred_pixels_matches: checkedFivehundredPixelsMatches
					},
					success: function( data ){
		                $('.related_service_comments_detected').html( data );
		                // Enable the form elements
						$('.related_service_comments_detected input').removeAttr("disabled");
						elems.saving.removeClass("show");
					}
				});
			}
			
			// The first request cycle is over...
			self.firstLoad = 0;
		}
		
		/**
		 * Simple save binding for the Check/Save button
		 */
		$('#related_service_comments_actions').on('click', '.button', function(event){
			event.preventDefault();
			self.parsePostContent();
		});
		
		/**
		 * Trigger an update/save after changing the 
		 * selection. (With a delay)
		 */
		$('#related_service_comments-autodetect_meta_box').on( 'change', 'input', function(event){
			if ( window.relatedServiceCommentsTimer ) clearTimeout( window.relatedServiceCommentsTimer );
			window.relatedServiceCommentsTimer = setTimeout(function(){
				self.parsePostContent();
			}, clickTimerMilliseconds );
			return true;
		});
		
		// Parse the content on initial load...
		self.firstLoad = 1;
		self.parsePostContent();
		
		// Poll for items if enabled
		if( autoPolling === true ){
			this.pollForItems();
		}
	};
	
    $(document).ready(function(){
    	if( $('#related_service_comments-autodetect_meta_box').length ){
	        window.RelatedServiceComments = new RelatedServiceComments();
    	}
    });
})(jQuery);