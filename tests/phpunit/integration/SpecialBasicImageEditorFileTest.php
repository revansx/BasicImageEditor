<?php

namespace MediaWiki\Extension\BasicImageEditor\Tests;

use LocalFile;
use LocalRepo;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\BasicImageEditor\SpecialBasicImageEditorFile;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\Authority;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Request\FauxResponse;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use RepoGroup;
use StatusValue;

/**
 * @covers \MediaWiki\Extension\BasicImageEditor\SpecialBasicImageEditorFile
 * @group Database
 */
class SpecialBasicImageEditorFileTest extends MediaWikiIntegrationTestCase {
	use MockAuthorityTrait;

	private const NOT_FOUND_BODY = 'No such file.';
	private const FORBIDDEN_BODY = 'You may not view this file.';

	/** @var FauxResponse */
	private $response;
	/** @var OutputPage */
	private $output;

	/**
	 * Run the special page and return what it echoed.
	 *
	 * @param RepoGroup $repoGroup
	 * @param string|null $subpage
	 * @param Authority|null $performer Defaults to a user who may read
	 * @return string
	 */
	private function execute( RepoGroup $repoGroup, ?string $subpage, ?Authority $performer = null ): string {
		$request = new FauxRequest();
		$this->response = $request->response();

		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setRequest( $request );
		$context->setAuthority( $performer ?? $this->mockRegisteredAuthorityWithPermissions( [ 'read' ] ) );
		$this->output = new OutputPage( $context );
		$context->setOutput( $this->output );

		$page = new SpecialBasicImageEditorFile( $repoGroup );
		$page->setContext( $context );

		ob_start();
		try {
			$page->execute( $subpage );
		} finally {
			$body = ob_get_clean();
		}
		return $body;
	}

	/**
	 * A repository group whose local repository must never be asked for a file.
	 */
	private function newUnusedRepoGroup(): RepoGroup {
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->expects( $this->never() )->method( 'getLocalRepo' );
		return $repoGroup;
	}

	/**
	 * @param LocalFile|false $file What the local repository finds
	 * @param string|null $expectedDbKey Prefixed DB key of the title it must be asked for, if checked
	 */
	private function newRepoGroupFinding( $file, ?string $expectedDbKey = null ): RepoGroup {
		$repo = $this->createMock( LocalRepo::class );
		$expectation = $repo->expects( $this->once() )->method( 'findFile' );
		if ( $expectedDbKey !== null ) {
			// The current version must be served, not a cached older one.
			$expectation->with(
				$this->callback( static fn ( Title $title ) => $title->getPrefixedDBkey() === $expectedDbKey ),
				[ 'latest' => true ]
			);
		}
		$expectation->willReturn( $file );
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'getLocalRepo' )->willReturn( $repo );
		return $repoGroup;
	}

	private function newFile( string $mime = 'image/jpeg', bool $exists = true ): LocalFile {
		$file = $this->createMock( LocalFile::class );
		$file->method( 'exists' )->willReturn( $exists );
		$file->method( 'getMimeType' )->willReturn( $mime );
		$file->method( 'getPath' )->willReturn( 'mwstore://local-backend/local-public/a/ab/Example.jpg' );
		$file->method( 'getName' )->willReturn( 'Example.jpg' );
		return $file;
	}

	private function assertErrorResponse( int $code, string $expectedBody, string $body ): void {
		$this->assertSame( $expectedBody, $body );
		$this->assertSame( $code, $this->response->getStatusCode() );
		$this->assertSame( 'text/plain; charset=utf-8', $this->response->getHeader( 'Content-Type' ) );
		$this->assertSame( 'private, no-cache', $this->response->getHeader( 'Cache-Control' ) );
		$this->assertTrue( $this->output->isDisabled(), 'No skin output may wrap the response' );
	}

	public function testIsRegisteredAsAnUnlistedPage(): void {
		$page = $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'BasicImageEditorFile' );

		$this->assertInstanceOf( SpecialBasicImageEditorFile::class, $page );
		$this->assertFalse( $page->isListed(), 'The file source must not appear on Special:SpecialPages' );
	}

	/**
	 * @dataProvider provideMissingFileNames
	 */
	public function testMissingOrInvalidFileNameIsNotFound( ?string $subpage ): void {
		$body = $this->execute( $this->newUnusedRepoGroup(), $subpage );

		$this->assertErrorResponse( 404, self::NOT_FOUND_BODY, $body );
	}

	public static function provideMissingFileNames(): array {
		return [
			'no subpage' => [ null ],
			'empty subpage' => [ '' ],
			'illegal characters' => [ 'Foo<bar>.jpg' ],
			'relative path' => [ 'Foo/../bar.jpg' ],
		];
	}

	public function testUserWhoMayNotReadGetsForbiddenWithoutALookup(): void {
		// Forbidden is decided before the file is looked up, so the response
		// cannot reveal whether a file of that name exists.
		$body = $this->execute(
			$this->newUnusedRepoGroup(),
			'Example.jpg',
			$this->mockRegisteredAuthorityWithPermissions( [] )
		);

		$this->assertErrorResponse( 403, self::FORBIDDEN_BODY, $body );
	}

	public function testFileThatIsNotFoundIsNotFound(): void {
		$body = $this->execute( $this->newRepoGroupFinding( false ), 'Example.jpg' );

		$this->assertErrorResponse( 404, self::NOT_FOUND_BODY, $body );
	}

	public function testFileThatDoesNotExistIsNotFound(): void {
		$file = $this->newFile( 'image/jpeg', false );

		$body = $this->execute( $this->newRepoGroupFinding( $file ), 'Example.jpg' );

		$this->assertErrorResponse( 404, self::NOT_FOUND_BODY, $body );
	}

	/**
	 * @dataProvider provideUnsupportedMimeTypes
	 */
	public function testOnlyJpgAndPngAreServed( string $mime ): void {
		$repo = $this->createMock( LocalRepo::class );
		$repo->expects( $this->never() )->method( 'streamFileWithStatus' );
		$file = $this->newFile( $mime );
		$file->method( 'getRepo' )->willReturn( $repo );

		$body = $this->execute( $this->newRepoGroupFinding( $file ), 'Example.jpg' );

		$this->assertErrorResponse( 404, self::NOT_FOUND_BODY, $body );
	}

	public static function provideUnsupportedMimeTypes(): array {
		return [
			'GIF' => [ 'image/gif' ],
			'SVG, which could carry script' => [ 'image/svg+xml' ],
			'PDF' => [ 'application/pdf' ],
			'HTML' => [ 'text/html' ],
			'unknown' => [ 'unknown/unknown' ],
			'empty' => [ '' ],
		];
	}

	/**
	 * @dataProvider provideSupportedMimeTypes
	 */
	public function testSupportedFileIsStreamedPrivatelyAndWithoutSniffing( string $mime ): void {
		$repo = $this->createMock( LocalRepo::class );
		$repo->expects( $this->once() )->method( 'streamFileWithStatus' )
			->with(
				'mwstore://local-backend/local-public/a/ab/Example.jpg',
				[
					'Cache-Control: private, max-age=0, must-revalidate',
					'X-Content-Type-Options: nosniff',
				]
			)
			->willReturn( StatusValue::newGood() );
		$file = $this->newFile( $mime );
		$file->method( 'getRepo' )->willReturn( $repo );

		$body = $this->execute(
			$this->newRepoGroupFinding( $file, 'File:Example.jpg' ),
			'Example.jpg'
		);

		$this->assertSame( '', $body, 'The page adds nothing to the streamed bytes' );
		$this->assertNull( $this->response->getStatusCode(), 'No error status is sent for a served file' );
		$this->assertTrue( $this->output->isDisabled() );
	}

	public static function provideSupportedMimeTypes(): array {
		return [
			'JPG' => [ 'image/jpeg' ],
			'PNG' => [ 'image/png' ],
		];
	}

	/**
	 * @dataProvider provideEquivalentFileNames
	 */
	public function testFileNameIsNormalised( string $subpage ): void {
		$repo = $this->createMock( LocalRepo::class );
		$repo->expects( $this->once() )->method( 'streamFileWithStatus' )->willReturn( StatusValue::newGood() );
		$file = $this->newFile();
		$file->method( 'getRepo' )->willReturn( $repo );

		$this->execute(
			$this->newRepoGroupFinding( $file, 'File:My_example_file.jpg' ),
			$subpage
		);
	}

	public static function provideEquivalentFileNames(): array {
		return [
			'underscores' => [ 'My_example_file.jpg' ],
			'spaces' => [ 'My example file.jpg' ],
			'lower case first letter' => [ 'my_example_file.jpg' ],
		];
	}

	public function testNamespacePrefixInTheSubpageIsPartOfTheFileName(): void {
		// The page always serves from the File: namespace; a pasted
		// "User:..." cannot be used to reach files in other namespaces.
		$this->execute(
			$this->newRepoGroupFinding( false, 'File:User:Example.jpg' ),
			'User:Example.jpg'
		);

		$this->assertSame( 404, $this->response->getStatusCode() );
	}

	public function testFailureWhileStreamingIsLoggedNotThrown(): void {
		$repo = $this->createMock( LocalRepo::class );
		$repo->expects( $this->once() )->method( 'streamFileWithStatus' )
			->willReturn( StatusValue::newFatal( 'filerepo-backend-unavailable' ) );
		$file = $this->newFile();
		$file->method( 'getRepo' )->willReturn( $repo );

		$body = $this->execute( $this->newRepoGroupFinding( $file ), 'Example.jpg' );

		$this->assertSame( '', $body, 'A failed stream must not append an error to the image bytes' );
	}
}
