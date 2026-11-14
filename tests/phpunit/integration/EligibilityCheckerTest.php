<?php

namespace MediaWiki\Extension\BasicImageEditor\Tests;

use File;
use LocalFile;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\BasicImageEditor\EligibilityChecker;
use MediaWiki\Message\Message;
use MediaWiki\Permissions\Authority;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use StatusValue;
use Wikimedia\Rdbms\ReadOnlyMode;

/**
 * @covers \MediaWiki\Extension\BasicImageEditor\EligibilityChecker
 * @group Database
 */
class EligibilityCheckerTest extends MediaWikiIntegrationTestCase {
	use MockAuthorityTrait;

	private const MAX_PIXELS = 1000;

	/** Everything the editor needs from the performer, as core's upload form would check it. */
	private const FULL_RIGHTS = [ 'edit', 'upload', 'reupload' ];

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( 'EnableUploads', true );
	}

	private function newChecker( int $maxPixels = self::MAX_PIXELS, bool $readOnly = false ): EligibilityChecker {
		$readOnlyMode = $this->createMock( ReadOnlyMode::class );
		$readOnlyMode->method( 'isReadOnly' )->willReturn( $readOnly );

		return new EligibilityChecker(
			new ServiceOptions(
				EligibilityChecker::CONSTRUCTOR_OPTIONS,
				[ 'BasicImageEditorMaxPixels' => $maxPixels ]
			),
			$readOnlyMode
		);
	}

	/**
	 * A local JPG that is eligible unless $overrides says otherwise.
	 *
	 * @param array $overrides Values for exists, isLocal, mime, width, height
	 * @return LocalFile
	 */
	private function newFile( array $overrides = [] ): LocalFile {
		$values = $overrides + [
			'exists' => true,
			'isLocal' => true,
			'mime' => 'image/jpeg',
			'width' => 20,
			'height' => 20,
		];
		$file = $this->createMock( LocalFile::class );
		$file->method( 'exists' )->willReturn( $values['exists'] );
		$file->method( 'isLocal' )->willReturn( $values['isLocal'] );
		$file->method( 'getMimeType' )->willReturn( $values['mime'] );
		$file->method( 'getWidth' )->willReturn( $values['width'] );
		$file->method( 'getHeight' )->willReturn( $values['height'] );
		return $file;
	}

	private function newTitle(): Title {
		return Title::makeTitle( NS_FILE, 'Example.jpg' );
	}

	private function assertFatalWith( string $expectedKey, StatusValue $status ): void {
		$this->assertFalse( $status->isOK(), 'Status must not be OK' );
		$messages = $status->getMessages();
		$this->assertCount( 1, $messages, 'Exactly one reason is reported' );
		$this->assertSame( $expectedKey, $messages[0]->getKey() );
	}

	public function testServiceIsWiredWithConfiguredPixelLimit(): void {
		$this->overrideConfigValue( 'BasicImageEditorMaxPixels', 100 );
		$checker = $this->getServiceContainer()->getService( 'BasicImageEditor.EligibilityChecker' );

		$status = $checker->check(
			$this->mockRegisteredAuthorityWithPermissions( self::FULL_RIGHTS ),
			$this->newTitle(),
			$this->newFile( [ 'width' => 11, 'height' => 10 ] )
		);

		$this->assertFatalWith( 'basicimageeditor-error-toolarge', $status );
	}

	public function testRequiresPixelLimitOption(): void {
		$this->expectException( \InvalidArgumentException::class );

		new EligibilityChecker(
			new ServiceOptions( EligibilityChecker::CONSTRUCTOR_OPTIONS, [] ),
			$this->createMock( ReadOnlyMode::class )
		);
	}

	public function testEligibleFileIsGood(): void {
		$status = $this->newChecker()->check(
			$this->mockRegisteredAuthorityWithPermissions( self::FULL_RIGHTS ),
			$this->newTitle(),
			$this->newFile()
		);

		$this->assertStatusGood( $status );
	}

	/**
	 * @dataProvider provideRigors
	 */
	public function testEligibleFileIsGoodForEveryRigor( string $rigor ): void {
		$status = $this->newChecker()->check(
			$this->mockRegisteredAuthorityWithPermissions( self::FULL_RIGHTS ),
			$this->newTitle(),
			$this->newFile(),
			$rigor
		);

		$this->assertStatusGood( $status );
	}

	public static function provideRigors(): array {
		return [
			'quick' => [ EligibilityChecker::RIGOR_QUICK ],
			'full' => [ EligibilityChecker::RIGOR_FULL ],
		];
	}

	public function testUploadsDisabled(): void {
		$this->overrideConfigValue( 'EnableUploads', false );

		$status = $this->newChecker()->check(
			$this->mockRegisteredAuthorityWithPermissions( self::FULL_RIGHTS ),
			$this->newTitle(),
			$this->newFile()
		);

		$this->assertFatalWith( 'basicimageeditor-error-uploads-disabled', $status );
	}

	public function testReadOnlyWiki(): void {
		$status = $this->newChecker( self::MAX_PIXELS, true )->check(
			$this->mockRegisteredAuthorityWithPermissions( self::FULL_RIGHTS ),
			$this->newTitle(),
			$this->newFile()
		);

		$this->assertFatalWith( 'basicimageeditor-error-readonly', $status );
	}

	public function testMissingFileObject(): void {
		$status = $this->newChecker()->check(
			$this->mockRegisteredAuthorityWithPermissions( self::FULL_RIGHTS ),
			$this->newTitle(),
			null
		);

		$this->assertFatalWith( 'basicimageeditor-error-nofile', $status );
		$this->assertSame(
			'(basicimageeditor-error-nofile: File:Example.jpg)',
			Message::newFromSpecifier( $status->getMessages()[0] )->inLanguage( 'qqx' )->text(),
			'The message names the file that was not found'
		);
	}

	public function testFileThatDoesNotExist(): void {
		$status = $this->newChecker()->check(
			$this->mockRegisteredAuthorityWithPermissions( self::FULL_RIGHTS ),
			$this->newTitle(),
			$this->newFile( [ 'exists' => false ] )
		);

		$this->assertFatalWith( 'basicimageeditor-error-nofile', $status );
	}

	public function testFileFromRemoteRepository(): void {
		$status = $this->newChecker()->check(
			$this->mockRegisteredAuthorityWithPermissions( self::FULL_RIGHTS ),
			$this->newTitle(),
			$this->newFile( [ 'isLocal' => false ] )
		);

		$this->assertFatalWith( 'basicimageeditor-error-remote', $status );
	}

	/**
	 * @dataProvider provideSupportedMimeTypes
	 */
	public function testSupportedFormats( string $mime ): void {
		$status = $this->newChecker()->check(
			$this->mockRegisteredAuthorityWithPermissions( self::FULL_RIGHTS ),
			$this->newTitle(),
			$this->newFile( [ 'mime' => $mime ] )
		);

		$this->assertStatusGood( $status );
	}

	public static function provideSupportedMimeTypes(): array {
		return [
			'JPG' => [ 'image/jpeg' ],
			'PNG' => [ 'image/png' ],
		];
	}

	/**
	 * @dataProvider provideUnsupportedMimeTypes
	 */
	public function testUnsupportedFormats( string $mime ): void {
		$status = $this->newChecker()->check(
			$this->mockRegisteredAuthorityWithPermissions( self::FULL_RIGHTS ),
			$this->newTitle(),
			$this->newFile( [ 'mime' => $mime ] )
		);

		$this->assertFatalWith( 'basicimageeditor-error-format', $status );
	}

	public static function provideUnsupportedMimeTypes(): array {
		return [
			'GIF' => [ 'image/gif' ],
			'WebP' => [ 'image/webp' ],
			'SVG' => [ 'image/svg+xml' ],
			'TIFF' => [ 'image/tiff' ],
			'PDF' => [ 'application/pdf' ],
			'non-canonical JPG alias' => [ 'image/jpg' ],
			'upper case' => [ 'IMAGE/JPEG' ],
			'unknown' => [ 'unknown/unknown' ],
			'empty' => [ '' ],
		];
	}

	/**
	 * @dataProvider providePixelCounts
	 */
	public function testPixelLimit( int $width, int $height, bool $allowed ): void {
		$status = $this->newChecker( self::MAX_PIXELS )->check(
			$this->mockRegisteredAuthorityWithPermissions( self::FULL_RIGHTS ),
			$this->newTitle(),
			$this->newFile( [ 'width' => $width, 'height' => $height ] )
		);

		if ( $allowed ) {
			$this->assertStatusGood( $status );
			return;
		}
		$this->assertFatalWith( 'basicimageeditor-error-toolarge', $status );
		$this->assertEquals(
			[ Message::numParam( self::MAX_PIXELS ) ],
			$status->getMessages()[0]->getParams(),
			'The message states the configured limit'
		);
	}

	public static function providePixelCounts(): array {
		return [
			'far below the limit' => [ 10, 10, true ],
			'exactly at the limit' => [ 40, 25, true ],
			'one pixel above the limit' => [ 7, 143, false ],
			'wide and thin, within the limit' => [ 1000, 1, true ],
			'wide and thin, above the limit' => [ 1001, 1, false ],
			'square above the limit' => [ 32, 32, false ],
		];
	}

	public function testPixelLimitFromConfigurationIsAnInteger(): void {
		$checker = new EligibilityChecker(
			new ServiceOptions( EligibilityChecker::CONSTRUCTOR_OPTIONS, [ 'BasicImageEditorMaxPixels' => '400' ] ),
			$this->createMock( ReadOnlyMode::class )
		);

		$status = $checker->check(
			$this->mockRegisteredAuthorityWithPermissions( self::FULL_RIGHTS ),
			$this->newTitle(),
			$this->newFile( [ 'width' => 20, 'height' => 20 ] )
		);

		$this->assertStatusGood( $status );
	}

	public function testAnonymousUser(): void {
		$status = $this->newChecker()->check(
			$this->mockAnonAuthorityWithPermissions( self::FULL_RIGHTS ),
			$this->newTitle(),
			$this->newFile()
		);

		$this->assertFatalWith( 'basicimageeditor-error-anon', $status );
	}

	/**
	 * @dataProvider provideMissingRights
	 */
	public function testMissingRights( array $rights ): void {
		$status = $this->newChecker()->check(
			$this->mockRegisteredAuthorityWithPermissions( $rights ),
			$this->newTitle(),
			$this->newFile()
		);

		$this->assertFatalWith( 'basicimageeditor-error-permission', $status );
	}

	public static function provideMissingRights(): array {
		return [
			'no rights' => [ [] ],
			'upload but not edit' => [ [ 'upload', 'reupload' ] ],
			'edit but not upload' => [ [ 'edit', 'reupload' ] ],
			'only reupload' => [ [ 'reupload' ] ],
		];
	}

	public function testReuploadOwnAllowsFileUploadedByThePerformer(): void {
		$performer = $this->mockRegisteredAuthorityWithPermissions( [ 'edit', 'upload', 'reupload-own' ] );
		$file = $this->newFile();
		$file->method( 'getUploader' )->willReturn( $performer->getUser() );

		$status = $this->newChecker()->check( $performer, $this->newTitle(), $file );

		$this->assertStatusGood( $status );
	}

	public function testReuploadOwnRefusesFileUploadedBySomeoneElse(): void {
		$performer = $this->mockRegisteredAuthorityWithPermissions( [ 'edit', 'upload', 'reupload-own' ] );
		$file = $this->newFile();
		$file->method( 'getUploader' )->willReturn( $this->getTestSysop()->getUserIdentity() );

		$status = $this->newChecker()->check( $performer, $this->newTitle(), $file );

		$this->assertFatalWith( 'basicimageeditor-error-reupload', $status );
	}

	public function testNeitherReuploadRight(): void {
		$status = $this->newChecker()->check(
			$this->mockRegisteredAuthorityWithPermissions( [ 'edit', 'upload' ] ),
			$this->newTitle(),
			$this->newFile()
		);

		$this->assertFatalWith( 'basicimageeditor-error-reupload', $status );
	}

	/**
	 * @dataProvider provideOrderOfChecks
	 */
	public function testReportsTheFirstReasonThatApplies(
		array $fileOverrides,
		array $rights,
		string $expectedKey
	): void {
		$status = $this->newChecker()->check(
			$this->mockRegisteredAuthorityWithPermissions( $rights ),
			$this->newTitle(),
			$this->newFile( $fileOverrides )
		);

		$this->assertFatalWith( $expectedKey, $status );
	}

	public static function provideOrderOfChecks(): array {
		return [
			'remote beats format' => [
				[ 'isLocal' => false, 'mime' => 'image/gif' ],
				self::FULL_RIGHTS,
				'basicimageeditor-error-remote',
			],
			'format beats size' => [
				[ 'mime' => 'image/gif', 'width' => 5000, 'height' => 5000 ],
				self::FULL_RIGHTS,
				'basicimageeditor-error-format',
			],
			'file problems beat missing rights' => [
				[ 'mime' => 'image/gif' ],
				[],
				'basicimageeditor-error-format',
			],
			'size beats missing rights' => [
				[ 'width' => 5000, 'height' => 5000 ],
				[],
				'basicimageeditor-error-toolarge',
			],
		];
	}

	public function testReadOnlyBeatsMissingFile(): void {
		$status = $this->newChecker( self::MAX_PIXELS, true )->check(
			$this->mockRegisteredAuthorityWithPermissions( self::FULL_RIGHTS ),
			$this->newTitle(),
			null
		);

		$this->assertFatalWith( 'basicimageeditor-error-readonly', $status );
	}

	/**
	 * @dataProvider provideRigorsAndTheirPermissionCheck
	 */
	public function testRigorChoosesThePermissionCheck(
		string $rigor,
		string $expectedMethod,
		string $otherMethod
	): void {
		$asked = [];
		$performer = $this->createMock( Authority::class );
		$performer->method( 'isRegistered' )->willReturn( true );
		$performer->method( 'isAllowed' )->willReturn( true );
		$performer->method( $expectedMethod )->willReturnCallback(
			static function ( string $action, $target ) use ( &$asked ) {
				$asked[] = $action;
				return true;
			}
		);
		// Neither the other check nor authorizeWrite() may run: viewing a File:
		// page must never count against upload rate limits.
		$performer->expects( $this->never() )->method( $otherMethod );
		$performer->expects( $this->never() )->method( 'authorizeWrite' );
		$performer->expects( $this->never() )->method( 'authorizeAction' );

		$status = $this->newChecker()->check( $performer, $this->newTitle(), $this->newFile(), $rigor );

		$this->assertStatusGood( $status );
		$this->assertSame( [ 'edit', 'upload' ], $asked );
	}

	public static function provideRigorsAndTheirPermissionCheck(): array {
		return [
			'quick checks use probablyCan' => [ EligibilityChecker::RIGOR_QUICK, 'probablyCan', 'definitelyCan' ],
			'full checks use definitelyCan' => [ EligibilityChecker::RIGOR_FULL, 'definitelyCan', 'probablyCan' ],
		];
	}

	public function testRigorDefaultsToFull(): void {
		$performer = $this->createMock( Authority::class );
		$performer->method( 'isRegistered' )->willReturn( true );
		$performer->method( 'isAllowed' )->willReturn( true );
		$performer->expects( $this->exactly( 2 ) )->method( 'definitelyCan' )->willReturn( true );
		$performer->expects( $this->never() )->method( 'probablyCan' );

		$status = $this->newChecker()->check( $performer, $this->newTitle(), $this->newFile() );

		$this->assertStatusGood( $status );
	}

	public function testPermissionIsDeniedWhenEitherTitleCheckFails(): void {
		foreach ( [ 'edit', 'upload' ] as $deniedAction ) {
			$performer = $this->createMock( Authority::class );
			$performer->method( 'isRegistered' )->willReturn( true );
			$performer->method( 'isAllowed' )->willReturn( true );
			$performer->method( 'definitelyCan' )->willReturnCallback(
				static fn ( string $action ) => $action !== $deniedAction
			);

			$status = $this->newChecker()->check( $performer, $this->newTitle(), $this->newFile() );

			$this->assertFatalWith( 'basicimageeditor-error-permission', $status );
		}
	}

	/**
	 * @dataProvider provideRigors
	 */
	public function testPageProtectionDeniesAnOrdinaryUser( string $rigor ): void {
		$title = $this->insertPage( 'File:Protected example.jpg' )['title'];
		$sysop = $this->getTestSysop()->getUser();
		$cascade = false;
		$protectStatus = $this->getServiceContainer()->getWikiPageFactory()
			->newFromTitle( $title )
			->doUpdateRestrictions(
				[ 'edit' => 'sysop', 'upload' => 'sysop' ],
				[ 'edit' => 'infinity', 'upload' => 'infinity' ],
				$cascade,
				'test',
				$sysop
			);
		$this->assertStatusGood( $protectStatus );

		$user = $this->getTestUser()->getAuthority();
		$status = $this->newChecker()->check( $user, $title, $this->newFile(), $rigor );

		$this->assertFatalWith( 'basicimageeditor-error-permission', $status );
	}

	public function testSysopMayEditAProtectedFile(): void {
		$title = $this->insertPage( 'File:Protected for sysops.jpg' )['title'];
		$sysop = $this->getTestSysop()->getUser();
		$cascade = false;
		$this->getServiceContainer()->getWikiPageFactory()
			->newFromTitle( $title )
			->doUpdateRestrictions(
				[ 'edit' => 'sysop', 'upload' => 'sysop' ],
				[ 'edit' => 'infinity', 'upload' => 'infinity' ],
				$cascade,
				'test',
				$sysop
			);

		$status = $this->newChecker()->check(
			$this->getTestSysop()->getAuthority(),
			$title,
			$this->newFile()
		);

		$this->assertStatusGood( $status );
	}

	public function testReuploadOwnNeedsALocalFile(): void {
		// Core's rule only recognises the uploader of a LocalFile; any other
		// File subclass is refused even if the performer would otherwise qualify.
		$file = $this->createMock( File::class );
		$file->method( 'exists' )->willReturn( true );
		$file->method( 'isLocal' )->willReturn( true );
		$file->method( 'getMimeType' )->willReturn( 'image/jpeg' );
		$file->method( 'getWidth' )->willReturn( 20 );
		$file->method( 'getHeight' )->willReturn( 20 );

		$status = $this->newChecker()->check(
			$this->mockRegisteredAuthorityWithPermissions( [ 'edit', 'upload', 'reupload-own' ] ),
			$this->newTitle(),
			$file
		);

		$this->assertFatalWith( 'basicimageeditor-error-reupload', $status );
	}
}
