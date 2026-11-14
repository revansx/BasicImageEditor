<?php

namespace MediaWiki\Extension\BasicImageEditor\Tests;

use LocalFile;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\BasicImageEditor\EligibilityChecker;
use MediaWiki\Extension\BasicImageEditor\SpecialBasicImageEditor;
use MediaWiki\Message\Message;
use MediaWiki\Permissions\Authority;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Title\Title;
use RepoGroup;
use SpecialPageTestBase;
use StatusValue;
use UploadBase;

/**
 * @covers \MediaWiki\Extension\BasicImageEditor\SpecialBasicImageEditor
 * @group Database
 */
class SpecialBasicImageEditorTest extends SpecialPageTestBase {

	private const FILE_TIMESTAMP = '20240102030405';

	/** @var EligibilityChecker */
	private $checker;
	/** @var RepoGroup */
	private $repoGroup;
	/** @var RevisionLookup|null */
	private $revisionLookup;
	/** @var array */
	private $configValues = [];
	/** @var SpecialBasicImageEditor|null */
	private $page;
	/** @var bool Use the page as registered by extension.json, with the real services */
	private $useWiredPage = false;

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( 'EnableUploads', true );
		$this->checker = $this->createMock( EligibilityChecker::class );
		$this->repoGroup = $this->createMock( RepoGroup::class );
		$this->revisionLookup = null;
		$this->configValues = [
			'BasicImageEditorAllowSaveAsNew' => true,
			'BasicImageEditorJpegQuality' => 92,
		];
		$this->page = null;
		$this->useWiredPage = false;
	}

	/**
	 * @return SpecialBasicImageEditor
	 */
	protected function newSpecialPage() {
		if ( $this->useWiredPage ) {
			$this->page = $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'BasicImageEditor' );
			return $this->page;
		}
		$this->page = new SpecialBasicImageEditor(
			$this->checker,
			$this->repoGroup,
			$this->revisionLookup ?? $this->getServiceContainer()->getRevisionLookup(),
			new HashConfig( $this->configValues )
		);
		return $this->page;
	}

	private function newFile(): LocalFile {
		$file = $this->createMock( LocalFile::class );
		$file->method( 'getName' )->willReturn( 'Example.jpg' );
		$file->method( 'getMimeType' )->willReturn( 'image/jpeg' );
		$file->method( 'getWidth' )->willReturn( 640 );
		$file->method( 'getHeight' )->willReturn( 480 );
		$file->method( 'getTimestamp' )->willReturn( self::FILE_TIMESTAMP );
		return $file;
	}

	private function givenEligibleFile( ?LocalFile $file = null ): LocalFile {
		$file ??= $this->newFile();
		$this->repoGroup->method( 'findFile' )->willReturn( $file );
		$this->checker->method( 'check' )->willReturn( StatusValue::newGood() );
		return $file;
	}

	/**
	 * @param string|null $subpage
	 * @param array $query
	 * @param Authority|null $performer
	 * @return string The rendered HTML
	 */
	private function render( ?string $subpage, array $query = [], ?Authority $performer = null ): string {
		[ $html ] = $this->executeSpecialPage(
			$subpage,
			new FauxRequest( $query ),
			'qqx',
			$performer ?? $this->getTestUser()->getAuthority()
		);
		return $html;
	}

	private function getEditorConfig(): array {
		$vars = $this->page->getOutput()->getJsConfigVars();
		$this->assertArrayHasKey( 'wgBasicImageEditor', $vars );
		return $vars['wgBasicImageEditor'];
	}

	private function assertEditorIsNotLoaded(): void {
		$this->assertNotContains( 'ext.basicImageEditor', $this->page->getOutput()->getModules() );
		$this->assertArrayNotHasKey( 'wgBasicImageEditor', $this->page->getOutput()->getJsConfigVars() );
	}

	public function testIsRegisteredAsAnUnlistedPage(): void {
		$page = $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'BasicImageEditor' );

		$this->assertInstanceOf( SpecialBasicImageEditor::class, $page );
		$this->assertFalse( $page->isListed(), 'The editor is reached from the tab, not from the page list' );
	}

	public function testWiredPageRefusesAnUnknownFileWithTheRealChecker(): void {
		$this->useWiredPage = true;
		[ $html ] = $this->executeSpecialPage(
			'Surely_does_not_exist.jpg',
			new FauxRequest(),
			'qqx',
			$this->getTestSysop()->getAuthority()
		);
		$this->assertStringContainsString( 'basicimageeditor-error-nofile', $html );
		$this->assertStringNotContainsString( 'ext-basicimageeditor"', $html );
	}

	/**
	 * @dataProvider provideRequestsWithoutAUsableTarget
	 */
	public function testNoUsableTargetShowsAnErrorAndNeverChecksAFile( ?string $subpage, array $query ): void {
		$this->checker->expects( $this->never() )->method( 'check' );
		$this->repoGroup->expects( $this->never() )->method( 'findFile' );

		$html = $this->render( $subpage, $query );

		$this->assertStringContainsString( 'basicimageeditor-error-notarget', $html );
		$this->assertStringContainsString( 'cdx-message--error', $html );
		$this->assertEditorIsNotLoaded();
	}

	public static function provideRequestsWithoutAUsableTarget(): array {
		return [
			'no subpage, no parameter' => [ null, [] ],
			'empty subpage' => [ '', [] ],
			'empty file parameter' => [ null, [ 'file' => '' ] ],
			'illegal characters in the name' => [ 'Foo<bar>.jpg', [] ],
			'illegal characters in the parameter' => [ null, [ 'file' => 'Foo<bar>.jpg' ] ],
			'relative path' => [ 'Foo/../bar.jpg', [] ],
		];
	}

	public function testFileParameterIsUsedWhenThereIsNoSubpage(): void {
		$this->repoGroup->expects( $this->once() )->method( 'findFile' )
			->with( $this->callback( static fn ( Title $t ) => $t->getPrefixedDBkey() === 'File:From_query.jpg' ) )
			->willReturn( $this->newFile() );
		$this->checker->method( 'check' )->willReturn( StatusValue::newGood() );

		$this->render( null, [ 'file' => 'From query.jpg' ] );

		$this->assertSame( 'Example.jpg', $this->getEditorConfig()['fileName'] );
	}

	public function testSubpageWinsOverTheFileParameter(): void {
		$this->repoGroup->expects( $this->once() )->method( 'findFile' )
			->with( $this->callback( static fn ( Title $t ) => $t->getPrefixedDBkey() === 'File:From_subpage.jpg' ) )
			->willReturn( $this->newFile() );
		$this->checker->method( 'check' )->willReturn( StatusValue::newGood() );

		$this->render( 'From_subpage.jpg', [ 'file' => 'From query.jpg' ] );
	}

	public function testChecksAreRepeatedWithFullRigorForThePerformer(): void {
		$performer = $this->getTestUser()->getAuthority();
		$file = $this->newFile();
		$this->repoGroup->expects( $this->once() )->method( 'findFile' )
			->with( $this->anything(), [ 'latest' => true ] )
			->willReturn( $file );
		$this->checker->expects( $this->once() )->method( 'check' )
			->with(
				$this->callback( static fn ( Authority $a ) => $a->getUser()->equals( $performer->getUser() ) ),
				$this->callback( static fn ( Title $t ) => $t->getPrefixedDBkey() === 'File:Example.jpg' ),
				$file,
				EligibilityChecker::RIGOR_FULL
			)
			->willReturn( StatusValue::newGood() );

		$this->render( 'Example.jpg', [], $performer );
	}

	public function testFileThatIsNotFoundIsPassedToTheCheckerAsNull(): void {
		$this->repoGroup->method( 'findFile' )->willReturn( false );
		$this->checker->expects( $this->once() )->method( 'check' )
			->with( $this->anything(), $this->anything(), null, EligibilityChecker::RIGOR_FULL )
			->willReturn( StatusValue::newFatal( 'basicimageeditor-error-nofile', 'File:Example.jpg' ) );

		$html = $this->render( 'Example.jpg' );

		$this->assertStringContainsString( 'basicimageeditor-error-nofile', $html );
	}

	public function testIneligibleFileShowsTheFirstReasonAndALinkBack(): void {
		$this->repoGroup->method( 'findFile' )->willReturn( $this->newFile() );
		$status = StatusValue::newFatal( 'basicimageeditor-error-format' );
		$status->fatal( 'basicimageeditor-error-permission' );
		$this->checker->method( 'check' )->willReturn( $status );

		$html = $this->render( 'Example.jpg' );

		$this->assertStringContainsString( 'cdx-message--error', $html );
		$this->assertStringContainsString( 'basicimageeditor-error-format', $html );
		$this->assertStringNotContainsString( 'basicimageeditor-error-permission', $html, 'Only the first reason' );
		$this->assertStringContainsString( 'File:Example.jpg', $html, 'A link back to the file page' );
		$this->assertStringContainsString( 'returnto', $html );
		$this->assertEditorIsNotLoaded();
		$this->assertStringNotContainsString( 'ext-basicimageeditor"', $html );
	}

	public function testIneligibleFileShowsTheParametersOfTheReason(): void {
		$this->repoGroup->method( 'findFile' )->willReturn( $this->newFile() );
		$this->checker->method( 'check' )->willReturn(
			StatusValue::newFatal( 'basicimageeditor-error-toolarge', Message::numParam( 1234567 ) )
		);

		$html = $this->render( 'Example.jpg' );

		$this->assertStringContainsString( 'basicimageeditor-error-toolarge: 1,234,567', $html );
	}

	public function testEligibleFileRendersTheEditorContainer(): void {
		$this->givenEligibleFile();

		$html = $this->render( 'Example.jpg' );

		$this->assertStringContainsString( 'id="ext-basicimageeditor"', $html );
		$this->assertStringContainsString( 'class="ext-basicimageeditor"', $html );
		$this->assertStringContainsString( 'basicimageeditor-loading', $html );
		$this->assertMatchesRegularExpression(
			'!<noscript>.*cdx-message--error.*basicimageeditor-nojs.*</noscript>!s',
			$html,
			'Users without JavaScript are told why nothing happens'
		);
		$this->assertContains( 'ext.basicImageEditor', $this->page->getOutput()->getModules() );
		$this->assertSame(
			'(basicimageeditor-title: Example.jpg)',
			$this->page->getOutput()->getPageTitle()
		);
	}

	public function testEditorConfigDescribesTheFile(): void {
		$this->givenEligibleFile();

		$this->render( 'Example.jpg' );
		$config = $this->getEditorConfig();

		$this->assertSame( 'Example.jpg', $config['fileName'] );
		$this->assertSame( 'File:Example.jpg', $config['fileTitle'] );
		$this->assertSame( 'image/jpeg', $config['mimeType'] );
		$this->assertSame( 640, $config['width'] );
		$this->assertSame( 480, $config['height'] );
		$this->assertSame( '2024-01-02T03:04:05Z', $config['timestamp'] );
		$this->assertSame( UploadBase::getMaxUploadSize( 'file' ), $config['maxUploadSize'] );
		$this->assertSame( 'basicimageeditor', $config['changeTag'] );
		$this->assertStringContainsString( 'File:Example.jpg', $config['filePageUrl'] );
	}

	public function testSourceUrlGoesThroughTheWikisOwnDomainAndNamesTheVersion(): void {
		$this->givenEligibleFile();

		$this->render( 'Example.jpg' );
		$url = $this->getEditorConfig()['sourceUrl'];

		$this->assertStringContainsString( 'Special:BasicImageEditorFile/Example.jpg', $url );
		$this->assertStringContainsString( 'version=' . self::FILE_TIMESTAMP, $url );
		$this->assertStringNotContainsString( '//', ltrim( $url, '/' ), 'A local URL, not the upload host' );
	}

	public function testJpegQualityIsAnInteger(): void {
		$this->configValues['BasicImageEditorJpegQuality'] = '85';
		$this->givenEligibleFile();

		$this->render( 'Example.jpg' );

		$this->assertSame( 85, $this->getEditorConfig()['jpegQuality'] );
	}

	public function testDescriptionIsCopiedVerbatimWhenSavingAsNewIsAllowed(): void {
		$wikitext = "== Source ==\n{{Own work}} by [[User:Someone]]\n\n[[Category:Photos]]";
		$title = $this->insertPage( 'File:Example.jpg', $wikitext )['title'];
		$this->givenEligibleFile();

		$this->render( $title->getText() );
		$config = $this->getEditorConfig();

		$this->assertTrue( $config['allowSaveAsNew'] );
		$this->assertSame( $wikitext, $config['sourceDescription'] );
	}

	public function testDescriptionIsNotSentWhenSavingAsNewIsDisabled(): void {
		$this->configValues['BasicImageEditorAllowSaveAsNew'] = false;
		$this->insertPage( 'File:Example.jpg', 'Some description' );
		$this->givenEligibleFile();

		$this->render( 'Example.jpg' );
		$config = $this->getEditorConfig();

		$this->assertFalse( $config['allowSaveAsNew'] );
		$this->assertNull( $config['sourceDescription'], 'No need to send text that cannot be used' );
	}

	public function testFileWithoutADescriptionPageGetsAnEmptyDescription(): void {
		$this->givenEligibleFile();
		$this->revisionLookup = $this->createMock( RevisionLookup::class );
		$this->revisionLookup->method( 'getRevisionByTitle' )->willReturn( null );

		$this->render( 'Example.jpg' );

		$this->assertSame( '', $this->getEditorConfig()['sourceDescription'] );
	}
}
