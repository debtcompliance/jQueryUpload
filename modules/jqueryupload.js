/*
 * jQuery File Upload Plugin JS Example 6.7
 * https://github.com/blueimp/jQuery-File-Upload
 *
 * Copyright 2010, Sebastian Tschan
 * https://blueimp.net
 *
 * Licensed under the MIT license:
 * http://www.opensource.org/licenses/MIT
 * 
 * Note: This file has been modified for use in the jQueryUpload MediaWiki extension
 *       - a path based on the article ID is added so that files can be attached to pages
 *       - the PHP server code called is a MediaWiki Ajax handler
 */

/*jslint nomen: true, unparam: true, regexp: true */
/*global $, window, document */
			
$(function() {
	'use strict';

	if($('#fileupload').length > 0) {

		// Initialize the jQuery File Upload widget:
		$('#fileupload').fileupload();

		// Enable iframe cross-domain access via redirect option:
		$('#fileupload').fileupload(
			'option',
			'redirect',
			window.location.href.replace( /\/[^\/]*$/, '/cors/result.html?%s' )
		);

		// Load existing files using a path set to the current article ID if non-zero
		var path = mw.config.get('jQueryUploadID');
		path = path ? '&path=' + path : '';
		$('#fileupload').each(function () {
			var that = this;
			$.getJSON(mw.util.wikiScript('api') + '?action=jqu' + path, function(result) {
				if(result && result.length) {
					$(that).fileupload('option', 'done').call(that, null, {result: result});
				}
			});
		});
	}

	// Replace the spans with A elements (a hack to avoid the parser adding p's when isHTML = true)
	$('.jqu-span').each(function() {
		var e = $('span:first',this);
		var classes = e.attr('class');
		var href = e.attr('title');
		var html = e.html();
		$(this).replaceWith($('<a></a>').html(html).attr({'href': href,'class': classes}).addClass('jqu-link'));
	});

	// Add popup hover to all file links made with the parser-function
	$('.jqu-link').hover(function() {
		var a = $(this);
		var span = $('span',a);
		if(span.length > 0 ) {
			if( $('#jqu-popup').length == 0 ) $('#bodyContent').prepend('<div id="jqu-popup" />');
			$('#jqu-popup').html(span.html()).dialog({
				title: 'File description',
				closeText: '',
				resizable: false,
				width: 450,
			}).parent().css('left',a.position().left+20).css('top',a.position().top+15);
		}
	});
});

window.uploadRenameBase = function(name) {
	var re = /^(.+)(\..+?)$/;
	var m = re.exec(name);
	return m[1];
};

window.uploadRenameExt = function(name) {
	var re = /^(.+)(\..+?)$/;
	var m = re.exec(name);
	return m[2];
};
