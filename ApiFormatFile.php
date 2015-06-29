<?php
/**
 * An API format based on ApiFormatRaw that outputs a file if a filename is set, text if not
 * - Later the file output can have web-server support like Apache's XSendFile module added
 */

/**
 * Formatter that spits out anything you like with any desired MIME type
 * @ingroup API
 */
class ApiFormatFile extends ApiFormatBase {

	private $errorFallback;
	private $file = false;

	public function __construct( ApiMain $main, ApiFormatBase $errorFallback ) {
		parent::__construct( $main, 'file' );
		$this->errorFallback = $errorFallback;
	}

	public function getMimeType() {
		$data = $this->getResult()->getResultData();

		if ( isset( $data['error'] ) ) {
			return $this->errorFallback->getMimeType();
		}

		if ( !isset( $data['mime'] ) ) {
			ApiBase::dieDebug( __METHOD__, 'No MIME type set for file formatter' );
		}

		return $data['mime'];
	}

	public function initPrinter( $unused = false ) {
		$data = $this->getResult()->getResultData();
		if ( isset( $data['error'] ) ) {
			$this->errorFallback->initPrinter( $unused );
		} else {
			parent::initPrinter( $unused );
		}
	}

	public function closePrinter() {
		if ( $this->isDisabled() ) {
			return;
		}
		if( $this->file ) {
			readfile( $this->file );
		} else {
			return parent::closePrinter();
		}
	}

	public function execute() {
		$data = $this->getResult()->getResultData();
		if ( isset( $data['error'] ) ) {
			$this->errorFallback->execute();
			return;
		}
		if ( isset( $data['file'] ) ) {
			$this->file = $data['file'];
		} elseif ( isset( $data['text'] ) ) {
			$this->printText( $data['text'] );
		} else {
			ApiBase::dieDebug( __METHOD__, 'No text or file given for file formatter' );
		}
	}
}
