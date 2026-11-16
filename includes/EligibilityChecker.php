<?php

namespace MediaWiki\Extension\BasicImageEditor;

use File;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Message\Message;
use MediaWiki\Permissions\Authority;
use MediaWiki\Title\Title;
use StatusValue;
use UploadBase;
use Wikimedia\Rdbms\ReadOnlyMode;

/**
 * Decides whether a user may open a file in the editor.
 *
 * Mirrors the checks core applies when a file is overwritten through the
 * upload form (UploadBase::isAllowed(), UploadBase::verifyTitlePermissions()
 * and UploadBase::userCanReUpload()), plus the extension's own format and
 * size limits. Uses probablyCan()/definitelyCan() rather than
 * authorizeWrite(), so that viewing a File: page never counts against
 * upload rate limits.
 */
class EligibilityChecker {

	public const CONSTRUCTOR_OPTIONS = [
		'BasicImageEditorMaxPixels',
	];

	/** MIME types the editor can open and save. */
	public const SUPPORTED_MIME_TYPES = [
		'image/jpeg',
		'image/png',
	];

	/** Quick checks, for deciding whether to show the tab. */
	public const RIGOR_QUICK = 'quick';

	/** Full checks, for the special page. */
	public const RIGOR_FULL = 'full';

	private ServiceOptions $options;
	private ReadOnlyMode $readOnlyMode;

	public function __construct(
		ServiceOptions $options,
		ReadOnlyMode $readOnlyMode
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
		$this->readOnlyMode = $readOnlyMode;
	}

	/**
	 * Check whether $performer may edit $file in the editor.
	 *
	 * @param Authority $performer
	 * @param Title $title A title in the File namespace
	 * @param File|null $file The file found for $title, or null if none
	 * @param string $rigor self::RIGOR_QUICK or self::RIGOR_FULL
	 * @return StatusValue Good if eligible; otherwise fatal with the first
	 *  reason that applies, as a message key from i18n/en.json
	 */
	public function check(
		Authority $performer,
		Title $title,
		?File $file,
		string $rigor = self::RIGOR_FULL
	): StatusValue {
		if ( !UploadBase::isEnabled() ) {
			return StatusValue::newFatal( 'basicimageeditor-error-uploads-disabled' );
		}
		if ( $this->readOnlyMode->isReadOnly() ) {
			return StatusValue::newFatal( 'basicimageeditor-error-readonly' );
		}

		if ( !$file || !$file->exists() ) {
			// The name comes from the URL; as a plaintext parameter it is not read as wikitext.
			return StatusValue::newFatal(
				'basicimageeditor-error-nofile',
				Message::plaintextParam( $title->getPrefixedText() )
			);
		}
		if ( !$file->isLocal() ) {
			return StatusValue::newFatal( 'basicimageeditor-error-remote' );
		}
		if ( !in_array( $file->getMimeType(), self::SUPPORTED_MIME_TYPES, true ) ) {
			return StatusValue::newFatal( 'basicimageeditor-error-format' );
		}
		$maxPixels = (int)$this->options->get( 'BasicImageEditorMaxPixels' );
		if ( $file->getWidth() * $file->getHeight() > $maxPixels ) {
			return StatusValue::newFatal(
				'basicimageeditor-error-toolarge',
				Message::numParam( $maxPixels )
			);
		}

		if ( !$performer->isRegistered() ) {
			return StatusValue::newFatal( 'basicimageeditor-error-anon' );
		}
		if ( UploadBase::isAllowed( $performer ) !== true ) {
			return StatusValue::newFatal( 'basicimageeditor-error-permission' );
		}

		$permissionCheck = $rigor === self::RIGOR_QUICK ? 'probablyCan' : 'definitelyCan';
		foreach ( [ 'edit', 'upload' ] as $action ) {
			if ( !$performer->$permissionCheck( $action, $title ) ) {
				return StatusValue::newFatal( 'basicimageeditor-error-permission' );
			}
		}

		if ( !UploadBase::userCanReUpload( $performer, $file ) ) {
			return StatusValue::newFatal( 'basicimageeditor-error-reupload' );
		}

		return StatusValue::newGood();
	}
}
