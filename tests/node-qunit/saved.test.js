'use strict';

const sinon = require( 'sinon' );

const SAVED_JS = require.resolve( '../../resources/ext.basicImageEditor.saved/saved.js' );

/**
 * Let the promises started by the script settle.
 *
 * @return {Promise}
 */
function settle() {
	return new Promise( ( resolve ) => {
		setTimeout( resolve, 0 );
	} );
}

/**
 * Run saved.js against the given page markup and URL, as it runs when the
 * File: page is shown after saving.
 *
 * @param {string} bodyHtml
 * @param {string} url Path and query of the page
 */
function runOnPage( bodyHtml, url ) {
	document.body.innerHTML = bodyHtml;
	history.replaceState( { kept: true }, '', url );
	delete require.cache[ SAVED_JS ];
	require( SAVED_JS );
}

QUnit.module( 'ext.basicImageEditor.saved', ( hooks ) => {
	let fetchStub;

	hooks.beforeEach( () => {
		fetchStub = sinon.stub( global, 'fetch' ).resolves( {} );
		global.mw.notify = sinon.spy();
		global.mw.msg = sinon.stub().callsFake( ( key ) => '(' + key + ')' );
	} );

	hooks.afterEach( () => {
		sinon.restore();
		document.body.innerHTML = '';
		history.replaceState( null, '', '/wiki/Main_Page' );
	} );

	QUnit.test( 'confirms the save with a success notification', ( assert ) => {
		runOnPage( '', '/wiki/File:Example.jpg?basicimageeditor=saved' );

		assert.true( global.mw.msg.calledWith( 'basicimageeditor-saved' ) );
		assert.deepEqual(
			global.mw.notify.args,
			[ [ '(basicimageeditor-saved)', { type: 'success' } ] ]
		);
	} );

	QUnit.test( 'removes only the marker from the address, so a reload does not repeat it', ( assert ) => {
		runOnPage( '', '/wiki/File:Example.jpg?action=view&basicimageeditor=saved&uselang=de#file' );

		assert.strictEqual( location.pathname, '/wiki/File:Example.jpg' );
		assert.strictEqual( location.search, '?action=view&uselang=de' );
		assert.strictEqual( location.hash, '#file', 'The fragment is kept' );
		assert.deepEqual( history.state, { kept: true }, 'The history state is kept' );
	} );

	QUnit.test( 'removes the marker when it is the only parameter', ( assert ) => {
		runOnPage( '', '/wiki/File:Example.jpg?basicimageeditor=saved' );

		assert.strictEqual( location.search, '' );
	} );

	QUnit.test( 'fetches the image again, bypassing the cache, with the session cookie', ( assert ) => {
		runOnPage(
			'<div id="file"><img src="/images/a/ab/Example.jpg"></div>',
			'/wiki/File:Example.jpg?basicimageeditor=saved'
		);

		assert.deepEqual(
			fetchStub.args,
			[ [ '/images/a/ab/Example.jpg', { cache: 'reload', credentials: 'same-origin' } ] ]
		);
	} );

	QUnit.test( 'also refreshes every candidate of srcset', ( assert ) => {
		runOnPage(
			'<div id="file"><img src="/img/a.jpg" srcset="/img/a-1.5x.jpg 1.5x,  /img/a-2x.jpg   2x"></div>',
			'/wiki/File:Example.jpg?basicimageeditor=saved'
		);

		assert.deepEqual(
			fetchStub.args.map( ( args ) => args[ 0 ] ),
			[ '/img/a.jpg', '/img/a-1.5x.jpg', '/img/a-2x.jpg' ]
		);
	} );

	QUnit.test( 'covers the images in the file history as well', ( assert ) => {
		runOnPage(
			'<div id="file"><img src="/img/current.jpg"></div>' +
			'<table class="filehistory"><tr><td><img src="/img/old.jpg"></td></tr></table>',
			'/wiki/File:Example.jpg?basicimageeditor=saved'
		);

		assert.deepEqual(
			fetchStub.args.map( ( args ) => args[ 0 ] ),
			[ '/img/current.jpg', '/img/old.jpg' ]
		);
	} );

	QUnit.test( 'leaves other images on the page alone', ( assert ) => {
		runOnPage(
			'<div id="file"><img src="/img/current.jpg"></div>' +
			'<div id="content"><img src="/img/logo.png" srcset="/img/logo-2x.png 2x"></div>',
			'/wiki/File:Example.jpg?basicimageeditor=saved'
		);

		assert.deepEqual( fetchStub.args.map( ( args ) => args[ 0 ] ), [ '/img/current.jpg' ] );
	} );

	QUnit.test( 'makes the browser use the refreshed copy once it has been fetched', async ( assert ) => {
		runOnPage(
			'<div id="file"><img src="/img/a.jpg" srcset="/img/a-2x.jpg 2x"></div>',
			'/wiki/File:Example.jpg?basicimageeditor=saved'
		);
		const image = document.querySelector( '#file img' );
		const attributeChanges = [];
		new window.MutationObserver( ( records ) => {
			records.forEach( ( record ) => attributeChanges.push( record.attributeName ) );
		} ).observe( image, { attributes: true } );

		await settle();

		assert.strictEqual( image.getAttribute( 'src' ), '/img/a.jpg' );
		assert.strictEqual( image.getAttribute( 'srcset' ), '/img/a-2x.jpg 2x' );
		assert.deepEqual(
			attributeChanges,
			[ 'srcset', 'src', 'src', 'srcset' ],
			'srcset and src are dropped, then src is set before srcset is restored'
		);
	} );

	QUnit.test( 'does not add a srcset the image did not have', async ( assert ) => {
		runOnPage( '<div id="file"><img src="/img/a.jpg"></div>', '/wiki/File:Example.jpg?basicimageeditor=saved' );

		await settle();

		assert.false( document.querySelector( '#file img' ).hasAttribute( 'srcset' ) );
	} );

	QUnit.test( 'a failed fetch does not stop the image from being refreshed', async ( assert ) => {
		fetchStub.rejects( new Error( 'offline' ) );
		runOnPage(
			'<div id="file"><img src="/img/a.jpg"></div>',
			'/wiki/File:Example.jpg?basicimageeditor=saved'
		);
		const image = document.querySelector( '#file img' );
		const changes = [];
		new window.MutationObserver( ( records ) => {
			records.forEach( ( record ) => changes.push( record.attributeName ) );
		} ).observe( image, { attributes: true } );

		await settle();

		assert.strictEqual( image.getAttribute( 'src' ), '/img/a.jpg' );
		assert.true( changes.includes( 'src' ), 'The source was set again' );
	} );

	QUnit.test( 'still confirms the save on a page without images', ( assert ) => {
		runOnPage(
			'<div id="content">No image here</div>',
			'/wiki/File:Example.jpg?basicimageeditor=saved'
		);

		assert.strictEqual( fetchStub.callCount, 0 );
		assert.strictEqual( global.mw.notify.callCount, 1 );
	} );
} );
