<?php

namespace MediaWiki\Extension\jQueryUpload;

use Article;
use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use UploadHandler;

/**
 * A modified version of the Blueimp UploadHandler class
 */
class MWUploadHandler extends UploadHandler {

	/**
	 * The delete URL needs to be adjusted because it doesn't check
	 * if the script URL already has a query-string and to add path info
	 */
	protected function set_file_delete_url( $file ) {
		if ( RequestContext::getMain()->getUser()->isAllowed( 'jqudelete' ) ) {
			$path = preg_match( '|jquery_upload_files/(.+)/|', $this->options['upload_dir'], $m )
				? "&path=$m[1]"
				: '';
			$file->delete_url = $this->options['script_url'] . "$path&file=" . rawurlencode( $file->name );
			$file->delete_type = $this->options['delete_type'];
			if ( $file->delete_type !== 'DELETE' ) {
				$file->delete_url .= '&_method=DELETE';
			}
		}
	}

	/**
	 * We override the thumbnail creation to return a filetype icon when files can't be scaled as an image
	 */
	protected function create_scaled_image( $file, $options ) {
		$result = parent::create_scaled_image( $file, $options );
		if ( $result ) {
			return $result;
		}
		$icon = jQueryUpload::icon( $file );
		return symlink( $icon, $options['upload_dir'] . jQueryUpload::thumbFilename( $file ) );
	}

	/**
	 * Add info on the user who uploaded the file and the date it was uploaded, and create thumb if it doesn't exist
	 */
	protected function get_file_object( $file_name ) {

		// Create the thumb if it doesn't exist
		$thumb = $this->options['upload_dir'] . 'thumb/' . $file_name;
		$file = jQueryUpload::thumbFilename( $thumb );
		if ( !file_exists( $file ) ) {
			if ( is_link( $file ) ) {
				unlink( $file );
			}
			$this->create_scaled_image( $file_name, $this->options['image_versions']['thumbnail'] );
		}

		// Call the parent method to create the file object
		$file = parent::get_file_object( $file_name );

		// Add the meta data to the object
		if ( is_object( $file ) ) {
			$meta = $this->options['upload_dir'] . 'meta/' . $file_name;
			$file->info = $file->desc = "";

			// If the meta data file exists, extract and render the content
			if ( is_file( $meta ) ) {
				$data = unserialize( file_get_contents( $meta ) );
				$file->info = self::renderData( $data );
				$file->desc = array_key_exists( 2, $data ) ? $data[2] : '';
			}

			// If the file is a symlink to a file uploaded in the wiki, get the metadata from the wiki file instead
			elseif ( is_link( $this->options['upload_dir'] . $file_name ) ) {
				$title = Title::newFromText( $file_name, NS_FILE );
				if ( is_object( $title ) && $title->exists() ) {
					list( $uid, $ts, $file->desc ) = self::getUploadedFileInfo( $title );
					$file->info = self::renderData( [ $uid, wfTimestamp( TS_UNIX, $ts ) ] );
				}
			}
		}
		return $file;
	}

	/**
	 * Render file data
	 */
	public static function renderData( $data ) {
		$services = MediaWikiServices::getInstance();
		$context = RequestContext::getMain();

		$user = $services->getUserFactory()->newFromId( $data[0] );
		$name = $user->getRealName();
		if ( empty( $name ) ) {
			$name = $user->getName();
		}
		$contLang = $services->getContentLanguage();
		$d = $contLang->userDate( $data[1], $context->getUser() );
		$t = $contLang->userTime( $data[1], $context->getUser() );
		return wfMessage( 'jqueryupload-uploadinfo', $name, $d, $t )->text();
	}

	/**
	 * Get the description, name and upload time of the passed wiki file
	 */
	public static function getUploadedFileInfo( $title ) {
		$article = new Article( $title );
		$desc = $article->getPage()->getContent()->getNativeData();
		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
		$row = $dbr->selectRow(
			'revision',
			[ 'rev_actor', 'rev_timestamp' ],
			[ 'rev_page' => $title->getArticleID() ],
			__METHOD__,
			[ 'ORDER BY' => 'rev_timestamp','LIMIT' => 1 ]
		);

		$user = MediaWikiServices::getInstance()->getUserFactory()->newFromActorId( $row->rev_actor );

		return [ $user->getId(), $row->rev_timestamp, $desc ];
	}

	/**
	 * We should remove the unused directory after deleting a file
	 */
	public function delete() {
		if ( RequestContext::getMain()->getUser()->isAllowed( 'jqudelete' ) ) {
			parent::delete();
			$dir = $this->options['upload_dir'];

			// Delete the meta file if it exists
			if ( $file_name = isset( $_REQUEST['file'] ) ? basename( stripslashes( $_REQUEST['file'] ) ) : null ) {
				$meta = $dir . 'meta/' . $file_name;
				if ( is_file( $meta ) ) {
					unlink( $meta );
				}
			}

			// Check that the upload dir has no files in it
			$empty = true;
			foreach ( glob( "$dir/*" ) as $item ) {
				if ( is_file( $item ) ) {
					$empty = false;
				}
			}

			// There are no uploaded files in this directory, nuke it
			// - we need to use rm -rf because it still contains sub-dirs
			if ( $empty ) {
				exec( "rm -rf $dir" );
			}
		}
	}

	/**
	 * We add a meta-data file for the upload in the meta dir
	 */
	protected function handle_file_upload( $uploaded_file, $name, $size, $type, $error, $index = null ) {
		$file = parent::handle_file_upload( $uploaded_file, $name, $size, $type, $error, $index );
		if ( is_object( $file ) ) {
			$file_path = $this->options['upload_dir'] . $file->name;
			if ( is_file( $file_path ) ) {
				$user = RequestContext::getMain()->getUser();
				$hookContainer = MediaWikiServices::getInstance()->getHookContainer();
				if ( $hookContainer->run( 'jQueryUploadCheckFile', [ &$file, $file_path ] ) ) {
					$desc = jQueryUpload::$desc[$file->name];
					$meta = $this->options['upload_dir'] . 'meta/' . $file->name;
					$data = [ $user->getID(), time(), $desc == wfMessage( 'jqueryupload-enterdesc' ) ? '' : $desc ];
					file_put_contents( $meta, serialize( $data ) );
				} else {
					unlink( $file_path );
				}
			}
		}
		return $file;
	}
}
