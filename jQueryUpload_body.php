<?php
/**
 * Main class for the jQueryUpload MediaWiki extension
 * 
 * The ajax handler, head items, form and templates are based on
 * the jQueryUpload demo by Sebastian Tschan (https://blueimp.net)
 *
 * @ingroup Extensions
 * @author Aran Dunkley (http://www.organicdesign.co.nz/nad)
 *
 */
class jQueryUpload {

	public static $instance = null;
	public static $desc = array();
	public static $action = null;
	public static $path;

	var $id = 0;

	public static function onRegistration() {
		global $wgExtensionFunctions, $wgAPIModules;

		// Register our API Ajax handler
		$wgAPIModules['jqu'] = 'ApijQueryUpload';

		// Create a singleton instance
		self::$instance = new self();
		$wgExtensionFunctions[] = array( self::$instance, 'setup' );

		// If the query-string arg mwaction is supplied, rename action and change mwaction to action
		// - this hack was required because the jQueryUpload module uses the name "action" too
		if( array_key_exists( 'mwaction', $_REQUEST ) ) {
			self::$action = array_key_exists( 'action', $_REQUEST ) ? $_REQUEST['action'] : false;
			$_REQUEST['action'] = $_GET['action'] = $_POST['action'] = 'jqu';
		}
	}

	public function setup() {
		global $wgOut, $wgResourceModules, $wgHooks, $wgParser, $wgJQUploadFileMagic, $IP, $wgExtensionAssetsPath, $wgAutoloadClasses;

		// Calculate the base path of the extension files accounting for symlinks
		self::$path = $wgExtensionAssetsPath . str_replace( "$IP/extensions", '', dirname( $wgAutoloadClasses[__CLASS__] ) );

		// If attachments allowed in this page, add the module into the page
		if( $title = array_key_exists( 'title', $_GET ) ? Title::newFromText( $_GET['title'] ) : false )
			$this->id = $title->getArticleID();

		// Set up the #file parser-function
		$wgParser->setFunctionHook( $wgJQUploadFileMagic, array( $this, 'expandFile' ), SFH_NO_HASH );

		// Allow overriding of the file ID
		Hooks::run( 'jQueryUploadSetId', array( $title, &$this->id ) );

		// If attachments allowed in this page, add the module into the page
		$attach = is_object( $title ) && $this->id && !$title->isRedirect()
			&& !array_key_exists( 'action', $_REQUEST ) && $title->getNamespace() != 6;
		if( $attach ) Hooks::run( 'jQueryUploadAddAttachLink', array( $title, &$attach ) );
		if( $attach ) {
			$this->head();
			$wgHooks['BeforePageDisplay'][] = $this;
		}

		// Add the extensions own js
		$wgResourceModules['ext.jqueryupload']['remoteExtPath'] = self::$path;
		$wgOut->addModules( 'ext.jqueryupload' );
		$wgOut->addStyle( self::$path . '/styles/jqueryupload.css' );
	}

	/**
	 * Render scripts and form into an article
	 */
	function onBeforePageDisplay( $out, $skin ) {
		$out->addHtml( '<h2 class="jqueryupload">' . wfMessage( 'jqueryupload-attachments' )->text() . '</h2>' );
		$out->addHtml( $this->form() );
		$out->addHtml( $this->templates() );
		return true;
	}

	/**
	 * Return a file icon for the passed filename
	 */
	public static function icon( $file ) {
		global $IP, $wgJQUploadIconPrefix;
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$prefix = $wgJQUploadIconPrefix ? $wgJQUploadIconPrefix : "$IP/skins/common/images/icons/fileicon-";
		$icon = "$prefix$ext.png";
		if( !file_exists( $icon ) ) $icon = preg_replace( '|[-_]$|', '', $prefix ) . '.png';
		return $icon;
	}

	/**
	 * Return the filename appended with .png for non-image files
	 * (so that thumbnails always have an image extension)
	 */
	public static function thumbFilename( $file ) {
		return $file;
		return preg_match( "/^.+\.(jpe?g$|png|gif)$/", $file ) ? $file : "$file.png";
	}

	/**
	 * Expand the #file parser-function
	 */
	function expandFile( $parser, $filename, $anchor = false ) {
		global $wgJQUploadFileLinkPopup;
		$class = '';
		$href = false;
		$info = '';
		if( $anchor === false ) $anchor = $filename;

		// Check if the file is a locally uploaded one
		$img = wfLocalFile( $filename );
		if( $img->exists() ) {
			global $wgLang;
			$href = $img->getUrl();
			$class = ' local-file';
			if( $wgJQUploadFileLinkPopup ) {
				$title = $img->getTitle();
				$article = new Article( $title );
				$wikitext = $article->getPage()->getContent()->getNativeData();
				$info = $parser->parse( $wikitext, $parser->getTitle(), new ParserOptions(), false, false )->getText();
				if( !empty( $info ) ) $info = '<span class="file-desc">' . $info . '</span>';
				$date = wfMsg( 'jqueryupload-uploadinfo', $img->user_text, $wgLang->date( $img->timestamp, true ) );
				$info = '<span class="file-info">' . $date . '</span><br />' . $info;
			}
		}

		// Not local, check if it's a jQuery one
		if( $href === false ) {
			global $wgUploadDirectory, $wgScriptPath;
			if( $glob = glob( "$wgUploadDirectory/jquery_upload_files/*/$filename" ) ) {
				if( preg_match( "|jquery_upload_files/(\d+)/|", $glob[0], $m ) ) {
					$path = $m[1];
					$class = ' jquery-file';
					$href = self::fileUrl( $path, $filename );
					if( $wgJQUploadFileLinkPopup ) {
						$meta = "$wgUploadDirectory/jquery_upload_files/$path/meta/$filename";
						if( file_exists( $meta ) ) {
							$data = unserialize( file_get_contents( $meta ) );
							$info = '<span class="file-info">' . MWUploadHandler::renderData( $data ) . '</span>';
							if( $data[2] ) $info .= '<br /><span class="file-desc">' . $data[2] . '</span>';
						}
					}
				}
			}
		}

		if( $href === false ) $class = ' redlink';
		if( !empty( $info ) ) $info = "<span style=\"display:none\">$info</span>";
		return "<span class=\"jqu-span\"><span class=\"plainlinks$class\" title=\"$href\">$anchor$info</span></span>";
	}

	/**
	 * Register #file magic word
	 */
	public static function onLanguageGetMagic( &$magicWords, $langCode = 0 ) {
		global $wgJQUploadFileMagic;
		$magicWords[$wgJQUploadFileMagic] = array( 0, $wgJQUploadFileMagic );
		return true;
	}

	/**
	 * Return the external URL for the passed path (page id) and file
	 */
	public static function fileUrl( $path, $name ) {
		global $wgScriptPath;
		return "$wgScriptPath/api.php?action=jqu&path=$path&name=" . urlencode( $name );
	}

	private function head() {
		global $wgOut;
		$css = self::$path . '/upload/css';

		// CSS to style the file input field as button and adjust the Bootstrap progress bars
		$wgOut->addStyle( "$css/jquery.fileupload-ui.css", 'screen' );

		// Bootstrap CSS fixes for IE6
		$wgOut->addHeadItem( 'IE6', "<!--[if lt IE 7]><link rel=\"stylesheet\" href=\"http://blueimp.github.com/cdn/css/bootstrap-ie6.min.css\"><![endif]-->\n" );

		// Shim to make HTML5 elements usable in older Internet Explorer versions
		$wgOut->addHeadItem( 'HTML5', "<!--[if lt IE 9]><script src=\"http://html5shim.googlecode.com/svn/trunk/html5.js\"></script><![endif]-->\n" );

		// Set the ID to use for images on this page (defaults to article ID)
		$wgOut->addJsConfigVars( 'jQueryUploadID', $this->id );
	}

	private function form() {
		global $wgScriptPath, $wgTitle;
		if( $this->id === false ) $this->id = $wgTitle->getArticleID();
		$path = ( is_object( $wgTitle ) && $this->id ) ? "<input type=\"hidden\" name=\"path\" value=\"{$this->id}\" />" : '';
		return '<form id="fileupload" action="' . $wgScriptPath . '/api.php" method="POST" enctype="multipart/form-data">
			<!-- The fileupload-buttonbar contains buttons to add/delete files and start/cancel the upload -->
			<div class="row fileupload-buttonbar">
				<div class="span7">
					<!-- The fileinput-button span is used to style the file input field as button -->
					<span class="btn btn-success fileinput-button">
						<i class="icon-plus icon-white"></i>
						<span>' . wfMsg( 'jqueryupload-add' ) . '</span>
						<input type="file" name="files[]" multiple>
					</span>
					<button type="submit" class="btn btn-primary start">
						<i class="icon-upload icon-white"></i>
						<span>' . wfMsg( 'jqueryupload-start' ) . '</span>
					</button>
					<button type="reset" class="btn btn-warning cancel">
						<i class="icon-ban-circle icon-white"></i>
						<span>' . wfMsg( 'jqueryupload-cancel' ) . '</span>
					</button>
					<button type="button" class="btn btn-danger delete">
						<i class="icon-trash icon-white"></i>
						<span>' . wfMsg( 'jqueryupload-delsel' ) . '</span>
					</button>
					<input type="checkbox" class="toggle">
				</div>
				<!-- The global progress information -->
				<div class="span5 fileupload-progress fade">
					<!-- The global progress bar -->
					<div class="progress progress-success progress-striped active" role="progressbar" aria-valuemin="0" aria-valuemax="100">
						<div class="bar" style="width:0%;"></div>
					</div>
					<!-- The extended global progress information -->
					<div class="progress-extended">&nbsp;</div>
				</div>
			</div>
			<!-- The loading indicator is shown during file processing -->
			<div class="fileupload-loading"></div>
			<br>
			<!-- The table listing the files available for upload/download -->
			<table role="presentation" class="table table-striped"><tbody class="files" data-toggle="modal-gallery" data-target="#modal-gallery"></tbody></table>
			<input type="hidden" name="mwaction" value="jqu" />' . $path . '
		</form>';
	}

	private function templates() {
		return '<!-- The template to display files available for upload -->
		<script id="template-upload" type="text/x-tmpl">
		{% for (var i=0, file; file=o.files[i]; i++) { %}
			<tr class="template-upload fade">
				<td class="preview"><span class="fade"></span></td>
				<td class="name">
					<input type="hidden" name="upload_rename_from[]" value="{%=file.name%}" />
					<input type="text" name="upload_rename_to[]" value="{%=uploadRenameBase(file.name)%}" />{%=uploadRenameExt(file.name)%}<br />
					<input type="text" name="upload_desc[]" value="' . wfMsg( 'jqueryupload-enterdesc' ) . '" style="width:100%" />
				</td>
				<td class="size"><span>{%=o.formatFileSize(file.size)%}</span></td>
				{% if (file.error) { %}
					<td class="error" colspan="2"><span class="label label-important">{%=locale.fileupload.error%}</span> {%=locale.fileupload.errors[file.error] || file.error%}</td>
				{% } else if (o.files.valid && !i) { %}
					<td>
						<div class="progress progress-success progress-striped active" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="bar" style="width:0%;"></div></div>
					</td>
					<td class="start">{% if (!o.options.autoUpload) { %}
						<button class="btn btn-primary">
							<i class="icon-upload icon-white"></i>
							<span>' . wfMsg( 'jqueryupload-start' ) . '</span>
						</button>
					{% } %}</td>
				{% } else { %}
					<td colspan="2"></td>
				{% } %}
				<td class="cancel">{% if (!i) { %}
					<button class="btn btn-warning">
						<i class="icon-ban-circle icon-white"></i>
						<span>' . wfMsg( 'jqueryupload-cancel' ) . '</span>
					</button>
				{% } %}</td>
			</tr>
		{% } %}
		</script>
		<!-- The template to display files available for download -->
		<script id="template-download" type="text/x-tmpl">
		{% for (var i=0, file; file=o.files[i]; i++) { %}
			<tr class="template-download fade">
				{% if (file.error) { %}
					<td></td>
					<td class="name"><span>{%=file.name%}</span></td>
					<td class="size"><span>{%=o.formatFileSize(file.size)%}</span></td>
					<td class="error" colspan="2"><span class="label label-important">{%=locale.fileupload.error%}</span> {%=locale.fileupload.errors[file.error] || file.error%}</td>
				{% } else { %}
					<td class="preview">{% if (file.thumbnail_url) { %}
						<a href="{%=file.url%}" title="{%=file.name%}" rel="gallery" download="{%=file.name%}"><img src="{%=file.thumbnail_url%}"></a>
					{% } %}</td>
					<td class="name">
						<a href="{%=file.url%}" title="{%=file.name%}" rel="{%=file.thumbnail_url&&\'gallery\'%}" download="{%=file.name%}">{%=file.name%}</a><br />
						<span class="file-info">{%=file.info%}</span><br />
						<span class="file-desc">{%=file.desc%}</span>
					</td>
					<td class="size"><span>{%=o.formatFileSize(file.size)%}</span></td>
					<td colspan="2"></td>
				{% } %}
				<td class="delete">
					<button class="btn btn-danger" data-type="{%=file.delete_type%}" data-url="{%=file.delete_url%}">
						<i class="icon-trash icon-white"></i>
						<span>' . wfMsg( 'jqueryupload-del' ) . '</span>
					</button>
					<input type="checkbox" name="delete" value="1">
				</td>
			</tr>
		{% } %}
		</script>';
	}
}
