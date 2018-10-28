<?php
/**
 * API module for jQueryUpload extension
 * @ingroup API
 */
class ApijQueryUpload extends ApiBase {

	public function execute() {
		global $wgScriptPath, $wgUploadDirectory, $wgRequest, $wgFileExtensions;
		$params = $this->extractRequestParams();
		$thumb = $params['thumb'];
		$path = $params['path'];
		$name = $params['name'];

		// So that meaningful errors can be sent back to the client
		error_reporting( E_ALL | E_STRICT );

		// But turn off error output as warnings can break the json syntax
		ini_set("display_errors", "off");

		header( 'Pragma: no-cache' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );

		// If a file name is supplied, then this is a file or thumbnail request
		if( $name ) {
			global $wgUser;

			// Only return the file if the user is logged in
			if( !$wgUser->isLoggedIn() ) ApiBase::dieDebug( __METHOD__, 'Not logged in' );

			// Get the file or thumb location
			if( $thumb ) $name = jQueryUpload::thumbFilename( "thumb/$name" );
			$file = "$wgUploadDirectory/jquery_upload_files/$path/$name";

			// Set the headers, output the file and bail
			header( "Content-Length: " . filesize( $file ) );
			header( "Content-Disposition: inline; filename=\"$name\"" );
			//header( "Content-Transfer-Encoding: binary" );   IE was not rendering PDF's inline with this header included
			header( "Pragma: private" );

			$this->getResult()->addValue( null, 'file', $file );
			$this->getResult()->addValue( null, 'mime', mime_content_type( $file ) );
			return;
		}

		header( 'Content-Disposition: inline; filename="files.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: OPTIONS, HEAD, GET, POST, PUT, DELETE' );
		header( 'Access-Control-Allow-Headers: X-File-Name, X-File-Type, X-File-Size' );

		// Process the rename and desc text inputs added to the upload form rows
		if( array_key_exists( 'upload_rename_from', $_REQUEST ) && array_key_exists( 'files', $_FILES ) ) {
			foreach( $_REQUEST['upload_rename_from'] as $i => $from ) {
				if( false !== $j = array_search( $from, $_FILES['files']['name'] ) ) {
					$ext = pathinfo( $from, PATHINFO_EXTENSION );
					$name = $_REQUEST['upload_rename_to'][$i] . ".$ext";
					$_FILES['files']['name'][$j] = $name;
					jQueryUpload::$desc[$name] = $_REQUEST['upload_desc'][$i];
				}
			}
		}

		// Get the file locations
		$dir = "$wgUploadDirectory/jquery_upload_files/$path";
		if( $path ) $dir .= '/';
		$thm = $dir . 'thumb/';
		$meta = $dir . 'meta/';

		// Set the initial options for the upload file object
		$url = "$wgScriptPath/api.php?action=jqu";
		if( $path ) $url .= "&path=$path";
		$upload_options = array(
			'script_url' => $url,
			'upload_dir' => $dir,
			'upload_url' => "$url&name=",
			'accept_file_types' => '/(' . implode( '|', $wgFileExtensions ) . ')/i',
			'delete_type' => 'POST',
			'max_file_size' => 50000000,
			'image_versions' => array(
				'thumbnail' => array(
					'upload_dir' => $thm,
					'upload_url' => "$url&thumb=1&name=",
					'max_width' => 80,
					'max_height' => 80
				)
			)
		);

		// jQueryUpload module expects the action parameter to be populated with it's own action, not the API action
		$_REQUEST['action'] = jQueryUpload::$action;

		// Create the file upload object
		$upload_handler = new MWUploadHandler( $upload_options );

		// Buffer the output so we the API formatter can send it
		ob_start();

		// Call the appropriate method based on the request
		switch( $_SERVER['REQUEST_METHOD'] ) {
			case 'OPTIONS':
				break;
			case 'HEAD':
			case 'GET':
				$upload_handler->get();
				break;
			case 'POST':

				// Create the directories if they don't exist (we do it here so they're not created for every dir read)
				if( !is_dir( "$wgUploadDirectory/jquery_upload_files" ) ) mkdir( "$wgUploadDirectory/jquery_upload_files" );
				if( !is_dir( $dir ) ) mkdir( $dir );
				if( !is_dir( $thm ) ) mkdir( $thm );
				if( !is_dir( $meta ) ) mkdir( $meta );

				$upload_handler->post();
				break;
			default:
				header( 'HTTP/1.1 405 Method Not Allowed' );
		}

		// Store the buffered output in the result
		$this->getResult()->addValue( null, 'text', ob_get_contents() );
		ob_end_clean();

		// API needs a MIME type set, so we'll populate it from the existing header
		$mime = preg_match( '%^Content-type: (.+?)$%m', implode( "\n", headers_list() ), $m ) ? $m[1] : 'text/plain';
		$this->getResult()->addValue( null, 'mime', $mime );

		return;
	}

	/**
	 * This API should return plain text
	 */
	public function getCustomPrinter() {
		return new ApiFormatFile( $this->getMain(), $this->getMain()->createPrinterByName( 'jsonfm' ) );
	}

	public function mustBePosted() {
		return false;
	}

	/**
	 * Just allow any params for now (by adding the keys in $_REQUEST to the allowed params)
	 */
	public function getAllowedParams() {
		foreach( array_keys( $_REQUEST ) as $k ) {
			$params[$k] = array( ApiBase::PARAM_TYPE => 'string' );
		}
		$params['thumb'] = array( ApiBase::PARAM_TYPE => 'boolean' );
		$params['path'] = array( ApiBase::PARAM_TYPE => 'string' );
		$params['name'] = array( ApiBase::PARAM_TYPE => 'string' );
		return $params;
	}
}
