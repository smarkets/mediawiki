<?php
/**
 * Job for asynchronous upload-by-url.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup JobQueue
 */

/**
 * Job for asynchronous upload-by-url.
 *
 * This job is in fact an interface to UploadFromUrl, which is designed such
 * that it does not require any globals. If it does, fix it elsewhere, do not
 * add globals in here.
 *
 * @ingroup JobQueue
 */
class UploadFromUrlJob extends Job {
	const SESSION_KEYNAME = 'wsUploadFromUrlJobData';

	/** @var UploadFromUrl */
	public $upload;

	/** @var User */
	protected $user;

	public function __construct( Title $title, array $params ) {
		parent::__construct( 'uploadFromUrl', $title, $params );
	}

	public function run() {
		global $wgCopyUploadAsyncTimeout;
		# Initialize this object and the upload object
		$this->upload = new UploadFromUrl();
		$this->upload->initialize(
			$this->title->getText(),
			$this->params['url'],
			false
		);
		$this->user = User::newFromName( $this->params['userName'] );

		# Fetch the file
		$opts = array();
		if ( $wgCopyUploadAsyncTimeout ) {
			$opts['timeout'] = $wgCopyUploadAsyncTimeout;
		}
		$status = $this->upload->fetchFile( $opts );
		if ( !$status->isOk() ) {
			$this->leaveMessage( $status );

			return true;
		}

		# Verify upload
		$result = $this->upload->verifyUpload();
		if ( $result['status'] != UploadBase::OK ) {
			$status = $this->upload->convertVerifyErrorToStatus( $result );
			$this->leaveMessage( $status );

			return true;
		}

		# Check warnings
		if ( !$this->params['ignoreWarnings'] ) {
			$warnings = $this->upload->checkWarnings();
			if ( $warnings ) {

				# Stash the upload
				$key = $this->upload->stashFile( $this->user );

				// @todo FIXME: This has been broken for a while.
				// User::leaveUserMessage() does not exist.
				if ( $this->params['leaveMessage'] ) {
					$this->user->leaveUserMessage(
						wfMessage( 'upload-warning-subj' )->text(),
						wfMessage( 'upload-warning-msg',
							$key,
							$this->params['url'] )->text()
					);
				} else {
					$session = MediaWiki\Session\SessionManager::singleton()
						->getSessionById( $this->params['sessionId'] );
					$this->storeResultInSession( $session, 'Warning',
						'warnings', $warnings );
				}

				return true;
			}
		}

		# Perform the upload
		$status = $this->upload->performUpload(
			$this->params['comment'],
			$this->params['pageText'],
			$this->params['watch'],
			$this->user
		);
		$this->leaveMessage( $status );

		return true;
	}

	/**
	 * Leave a message on the user talk page or in the session according to
	 * $params['leaveMessage'].
	 *
	 * @param Status $status
	 */
	protected function leaveMessage( $status ) {
		if ( $this->params['leaveMessage'] ) {
			if ( $status->isGood() ) {
				// @todo FIXME: user->leaveUserMessage does not exist.
				$this->user->leaveUserMessage( wfMessage( 'upload-success-subj' )->text(),
					wfMessage( 'upload-success-msg',
						$this->upload->getTitle()->getText(),
						$this->params['url']
					)->text() );
			} else {
				// @todo FIXME: user->leaveUserMessage does not exist.
				$this->user->leaveUserMessage( wfMessage( 'upload-failure-subj' )->text(),
					wfMessage( 'upload-failure-msg',
						$status->getWikiText(),
						$this->params['url']
					)->text() );
			}
		} else {
			$session = MediaWiki\Session\SessionManager::singleton()
				->getSessionById( $this->params['sessionId'] );
			if ( $status->isOk() ) {
				$this->storeResultInSession( $session, 'Success',
					'filename', $this->upload->getLocalFile()->getName() );
			} else {
				$this->storeResultInSession( $session, 'Failure',
					'errors', $status->getErrorsArray() );
			}
		}
	}

	/**
	 * Store a result in the session data. Note that the caller is responsible
	 * for appropriate session_start and session_write_close calls.
	 *
	 * @param MediaWiki\\Session\\Session|null $session Session to store result into
	 * @param string $result The result (Success|Warning|Failure)
	 * @param string $dataKey The key of the extra data
	 * @param mixed $dataValue The extra data itself
	 */
	protected function storeResultInSession(
		MediaWiki\Session\Session $session = null, $result, $dataKey, $dataValue
	) {
		if ( $session ) {
			$data = self::getSessionData( $session, $this->params['sessionKey'] );
			$data['result'] = $result;
			$data[$dataKey] = $dataValue;
			self::setSessionData( $session, $this->params['sessionKey'], $data );
		} else {
			wfDebug( __METHOD__ . ': Cannot store result in session, session does not exist' );
		}
	}

	/**
	 * Initialize the session data. Sets the initial result to queued.
	 */
	public function initializeSessionData() {
		$session = MediaWiki\Session\SessionManager::getGlobalSession();
		$data = self::getSessionData( $session, $this->params['sessionKey'] );
		$data['result'] = 'Queued';
		self::setSessionData( $session, $this->params['sessionKey'], $data );
	}

	/**
	 * @param MediaWiki\\Session\\Session $session
	 * @param string $key
	 * @return mixed
	 */
	public static function getSessionData( MediaWiki\Session\Session $session, $key ) {
		$data = $session->get( self::SESSION_KEYNAME );
		if ( !is_array( $data ) || !isset( $data[$key] ) ) {
			self::setSessionData( $session, $key, array() );
			return array();
		}
		return $data[$key];
	}

	/**
	 * @param MediaWiki\\Session\\Session $session
	 * @param string $key
	 * @param mixed $value
	 */
	public static function setSessionData( MediaWiki\Session\Session $session, $key, $value ) {
		$data = $session->get( self::SESSION_KEYNAME, array() );
		if ( !is_array( $data ) ) {
			$data = array();
		}
		$data[$key] = $value;
		$session->set( self::SESSION_KEYNAME, $data );
	}
}
