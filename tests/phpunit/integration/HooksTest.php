<?php

namespace MediaWiki\Extension\BasicImageEditor\Tests;

use File;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\BasicImageEditor\EligibilityChecker;
use MediaWiki\Extension\BasicImageEditor\Hooks;
use MediaWiki\Message\Message;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\Authority;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use RepoGroup;
use Skin;
use SkinTemplate;
use StatusValue;

/**
 * @covers \MediaWiki\Extension\BasicImageEditor\Hooks
 * @group Database
 */
class HooksTest extends MediaWikiIntegrationTestCase {

	private const MODULE = 'ext.basicImageEditor.saved';

	/**
	 * @param EligibilityChecker|null $checker
	 * @param RepoGroup|null $repoGroup
	 * @return Hooks
	 */
	private function newHooks( ?EligibilityChecker $checker = null, ?RepoGroup $repoGroup = null ): Hooks {
		return new Hooks(
			$checker ?? $this->createMock( EligibilityChecker::class ),
			$repoGroup ?? $this->createMock( RepoGroup::class )
		);
	}

	private function newSkin( ?Title $relevantTitle, ?Authority $authority = null ): SkinTemplate {
		$skin = $this->createMock( SkinTemplate::class );
		$skin->method( 'getRelevantTitle' )->willReturn( $relevantTitle );
		$skin->method( 'getAuthority' )->willReturn( $authority ?? $this->getTestUser()->getAuthority() );
		$skin->method( 'msg' )->willReturnCallback(
			static fn ( $key ) => Message::newFromKey( $key )->inLanguage( 'qqx' )
		);
		return $skin;
	}

	private function newExistingFileTitle( string $name = 'Hooks example.jpg' ): Title {
		return $this->insertPage( "File:$name" )['title'];
	}

	private function newOutputPage( Title $title, array $query = [] ): OutputPage {
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setTitle( $title );
		$context->setRequest( new FauxRequest( $query ) );
		return new OutputPage( $context );
	}

	public function testTabIsAddedForAnEligibleFile(): void {
		$title = $this->newExistingFileTitle();
		$performer = $this->getTestUser()->getAuthority();
		$file = $this->createMock( File::class );
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->expects( $this->once() )->method( 'findFile' )->with( $title )->willReturn( $file );
		$checker = $this->createMock( EligibilityChecker::class );
		$checker->expects( $this->once() )->method( 'check' )
			->with( $performer, $title, $file, EligibilityChecker::RIGOR_QUICK )
			->willReturn( StatusValue::newGood() );
		$links = [ 'views' => [ 'view' => [ 'text' => 'Read' ] ] ];

		$this->newHooks( $checker, $repoGroup )
			->onSkinTemplateNavigation__Universal( $this->newSkin( $title, $performer ), $links );

		$this->assertSame( [ 'view', 'basicimageeditor' ], array_keys( $links['views'] ), 'Tab is appended' );
		$this->assertSame( [ 'text' => 'Read' ], $links['views']['view'], 'Existing tabs are untouched' );
		$tab = $links['views']['basicimageeditor'];
		$this->assertSame( '(basicimageeditor-tab)', $tab['text'] );
		$this->assertFalse( $tab['class'] );
		$this->assertStringContainsString( 'Special:BasicImageEditor/Hooks_example.jpg', $tab['href'] );
	}

	public function testTabLinkEncodesTheFileName(): void {
		$title = $this->newExistingFileTitle( 'Fish & chips.jpg' );
		$checker = $this->createMock( EligibilityChecker::class );
		$checker->method( 'check' )->willReturn( StatusValue::newGood() );
		$links = [ 'views' => [] ];

		$this->newHooks( $checker )->onSkinTemplateNavigation__Universal( $this->newSkin( $title ), $links );

		$href = $links['views']['basicimageeditor']['href'];
		$this->assertStringContainsString( 'Special:BasicImageEditor/Fish_%26_chips.jpg', $href );
		$this->assertStringNotContainsString( '& chips', $href );
	}

	public function testNoTabWhenTheFileIsNotEligible(): void {
		$title = $this->newExistingFileTitle();
		$checker = $this->createMock( EligibilityChecker::class );
		$checker->expects( $this->once() )->method( 'check' )
			->willReturn( StatusValue::newFatal( 'basicimageeditor-error-permission' ) );
		$links = [ 'views' => [ 'view' => [] ] ];

		$this->newHooks( $checker )->onSkinTemplateNavigation__Universal( $this->newSkin( $title ), $links );

		$this->assertSame( [ 'views' => [ 'view' => [] ] ], $links );
	}

	public function testMissingFileIsPassedToTheCheckerAsNull(): void {
		$title = $this->newExistingFileTitle();
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )->willReturn( false );
		$checker = $this->createMock( EligibilityChecker::class );
		$checker->expects( $this->once() )->method( 'check' )
			->with( $this->anything(), $title, null, EligibilityChecker::RIGOR_QUICK )
			->willReturn( StatusValue::newFatal( 'basicimageeditor-error-nofile', 'x' ) );
		$links = [ 'views' => [] ];

		$this->newHooks( $checker, $repoGroup )
			->onSkinTemplateNavigation__Universal( $this->newSkin( $title ), $links );

		$this->assertSame( [ 'views' => [] ], $links );
	}

	public function testNoTabOutsideTheFileNamespace(): void {
		$checker = $this->createMock( EligibilityChecker::class );
		$checker->expects( $this->never() )->method( 'check' );
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->expects( $this->never() )->method( 'findFile' );
		$links = [ 'views' => [] ];

		$title = $this->insertPage( 'Hooks main page' )['title'];
		$this->newHooks( $checker, $repoGroup )
			->onSkinTemplateNavigation__Universal( $this->newSkin( $title ), $links );

		$this->assertSame( [ 'views' => [] ], $links );
	}

	public function testNoTabForAFilePageThatDoesNotExist(): void {
		$checker = $this->createMock( EligibilityChecker::class );
		$checker->expects( $this->never() )->method( 'check' );
		$links = [ 'views' => [] ];

		$title = Title::makeTitle( NS_FILE, 'Never created.jpg' );
		$this->newHooks( $checker )->onSkinTemplateNavigation__Universal( $this->newSkin( $title ), $links );

		$this->assertSame( [ 'views' => [] ], $links );
	}

	public function testNoTabWithoutARelevantTitle(): void {
		$checker = $this->createMock( EligibilityChecker::class );
		$checker->expects( $this->never() )->method( 'check' );
		$links = [ 'views' => [] ];

		$this->newHooks( $checker )->onSkinTemplateNavigation__Universal( $this->newSkin( null ), $links );

		$this->assertSame( [ 'views' => [] ], $links );
	}

	public function testSavedNoticeModuleIsLoadedOnAFilePageAfterSaving(): void {
		$out = $this->newOutputPage( Title::makeTitle( NS_FILE, 'Saved.jpg' ), [ 'basicimageeditor' => 'saved' ] );

		$this->newHooks()->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );

		$this->assertContains( self::MODULE, $out->getModules() );
	}

	/**
	 * @dataProvider provideRequestsWithoutSavedNotice
	 */
	public function testSavedNoticeModuleIsNotLoadedOtherwise( int $namespace, array $query ): void {
		$out = $this->newOutputPage( Title::makeTitle( $namespace, 'Saved.jpg' ), $query );

		$this->newHooks()->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );

		$this->assertNotContains( self::MODULE, $out->getModules() );
	}

	public static function provideRequestsWithoutSavedNotice(): array {
		return [
			'file page without the parameter' => [ NS_FILE, [] ],
			'file page with another value' => [ NS_FILE, [ 'basicimageeditor' => 'cancelled' ] ],
			'file page with an empty value' => [ NS_FILE, [ 'basicimageeditor' => '' ] ],
			'value is case sensitive' => [ NS_FILE, [ 'basicimageeditor' => 'SAVED' ] ],
			'article page with the parameter' => [ NS_MAIN, [ 'basicimageeditor' => 'saved' ] ],
			'talk page of a file' => [ NS_FILE_TALK, [ 'basicimageeditor' => 'saved' ] ],
		];
	}

	public function testChangeTagConstantMatchesTheDocumentedName(): void {
		$this->assertSame( 'basicimageeditor', Hooks::CHANGE_TAG );
	}

	public function testChangeTagIsRegisteredInTheHandlers(): void {
		$hooks = $this->newHooks();
		$defined = [ 'existing' ];
		$active = [ 'existing' ];
		$allowed = [ 'existing' ];

		$hooks->onListDefinedTags( $defined );
		$hooks->onChangeTagsListActive( $active );
		$hooks->onChangeTagsAllowedAdd( $allowed, [], $this->getTestUser()->getUser() );

		$this->assertSame( [ 'existing', 'basicimageeditor' ], $defined );
		$this->assertSame( [ 'existing', 'basicimageeditor' ], $active );
		$this->assertSame( [ 'existing', 'basicimageeditor' ], $allowed );
	}

	/**
	 * The handlers are declared in extension.json; run the hooks through the
	 * hook container to prove the registration and the service wiring work.
	 *
	 * @dataProvider provideTagHooks
	 */
	public function testChangeTagIsRegisteredThroughExtensionJson( string $hook, array $extraArguments ): void {
		$tags = [];
		$arguments = array_merge( [ &$tags ], $extraArguments );

		$this->getServiceContainer()->getHookContainer()->run( $hook, $arguments );

		$this->assertContains( Hooks::CHANGE_TAG, $tags );
	}

	public static function provideTagHooks(): array {
		return [
			'ListDefinedTags' => [ 'ListDefinedTags', [] ],
			'ChangeTagsListActive' => [ 'ChangeTagsListActive', [] ],
			'ChangeTagsAllowedAdd' => [ 'ChangeTagsAllowedAdd', [ [], null ] ],
		];
	}

	public function testChangeTagIsKnownToCore(): void {
		$store = $this->getServiceContainer()->getChangeTagsStore();

		$this->assertContains( Hooks::CHANGE_TAG, $store->listDefinedTags() );
	}
}
