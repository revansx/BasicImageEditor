<?php

namespace MediaWiki\Extension\BasicImageEditor\Tests;

use MediaWiki\Request\FauxRequest;
use SpecialPageTestBase;

/**
 * The file name comes from the URL, so anyone can put any text into it. The
 * error shown for an unknown file names it and must show it as plain text.
 *
 * @covers \MediaWiki\Extension\BasicImageEditor\SpecialBasicImageEditor
 * @covers \MediaWiki\Extension\BasicImageEditor\EligibilityChecker
 * @group Database
 */
class SpecialBasicImageEditorEscapingTest extends SpecialPageTestBase {

	protected function newSpecialPage() {
		return $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'BasicImageEditor' );
	}

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( 'EnableUploads', true );
	}

	/**
	 * @dataProvider provideFileNames
	 */
	public function testFileNameInTheNotFoundMessageIsShownAsPlainText( string $name, string $rawFragment ): void {
		[ $html ] = $this->executeSpecialPage(
			$name,
			new FauxRequest(),
			'qqx',
			$this->getTestSysop()->getAuthority()
		);

		$this->assertStringContainsString( 'basicimageeditor-error-nofile', $html );
		$this->assertStringNotContainsString( $rawFragment, $html, 'The name must not be interpreted as wikitext' );
		$this->assertStringContainsString(
			'File:' . $name,
			html_entity_decode( strip_tags( $html ), ENT_QUOTES ),
			'The name is still shown, as text'
		);
	}

	public static function provideFileNames(): array {
		return [
			'external link' => [ 'Click http://evil.example/ here.jpg', 'href="http://evil.example/' ],
			'bold' => [ "A '''bold''' name.jpg", '<b>bold</b>' ],
			'italic' => [ "An ''italic'' name.jpg", '<i>italic</i>' ],
		];
	}
}
