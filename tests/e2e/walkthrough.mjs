/**
 * Browser walkthrough of BasicImageEditor against a running wiki.
 *
 * Drives the real editor in headless Chromium: every tool, flip after rotate
 * (preview and saved output), sliders keeping the crop, EXIF orientation,
 * and saving as a new version and as a new file. It saves to the wiki, so
 * run it only on a development wiki. See README.md in this directory.
 *
 * Environment:
 *   BIE_WIKI_URL       Wiki root, e.g. https://wiki.example (required)
 *   BIE_USER           Account with upload and reupload rights (required)
 *   BIE_PASSWORD       Its password (required)
 *   BIE_OUTPUT         Folder for screenshots and results.json (default: e2e-output)
 *   BIE_INSECURE_TLS   "1" to accept a self-signed certificate
 */
// Installed by run.sh inside the Playwright image, not a package.json dependency.
// eslint-disable-next-line n/no-missing-import
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const BASE = ( process.env.BIE_WIKI_URL || '' ).replace( /\/$/, '' );
const USER = process.env.BIE_USER;
const PASSWORD = process.env.BIE_PASSWORD;
const OUT = path.resolve( process.env.BIE_OUTPUT || 'e2e-output' );

if ( !BASE || !USER || !PASSWORD ) {
	console.error( 'Set BIE_WIKI_URL, BIE_USER and BIE_PASSWORD.' );
	process.exit( 2 );
}
fs.mkdirSync( OUT, { recursive: true } );

/** 1600×1200 JPG with a description page. Gets new versions. */
const MAIN_FILE = 'BIE-test.jpg';
/** 1600×1200 JPG with four differently coloured quadrants. Not changed. */
const QUADRANT_FILE = 'BIE-test-quadrants.jpg';
/** Portrait JPG stored sideways with EXIF orientation 6. Not changed. */
const ORIENTATION_FILE = 'BIE-test-orientation.jpg';

const results = [];

function check( name, ok, detail ) {
	results.push( { name, ok: !!ok, detail } );
	const suffix = detail === undefined ? '' : '  ' + JSON.stringify( detail );
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + suffix );
}

const browser = await chromium.launch();
const context = await browser.newContext( {
	ignoreHTTPSErrors: process.env.BIE_INSECURE_TLS === '1',
	viewport: { width: 1400, height: 1000 }
} );
const page = await context.newPage();
const consoleErrors = [];
page.on( 'console', ( message ) => {
	if ( message.type() === 'error' ) {
		consoleErrors.push( message.text() );
	}
} );
page.on( 'pageerror', ( error ) => consoleErrors.push( 'pageerror: ' + error.message ) );
// Leaving the editor with unsaved changes asks for confirmation.
page.on( 'dialog', ( dialog ) => dialog.accept() );

/**
 * Helpers installed in the editor page. They read the Vue app's state and
 * measure images by sampling the average colour near each corner.
 */
function installPageHelpers() {
	window.bie = () => document.getElementById( 'ext-basicimageeditor' )._vnode.component.proxy;

	function cornerRegions( width, height ) {
		const size = Math.max( 4, Math.round( Math.min( width, height ) * 0.04 ) );
		const inset = Math.round( Math.min( width, height ) * 0.06 );
		return {
			TL: [ inset, inset ],
			TR: [ width - inset - size, inset ],
			BL: [ inset, height - inset - size ],
			BR: [ width - inset - size, height - inset - size ],
			size
		};
	}

	function drawToCanvas( source, width, height ) {
		const canvas = document.createElement( 'canvas' );
		canvas.width = width;
		canvas.height = height;
		const ctx = canvas.getContext( '2d' );
		ctx.drawImage( source, 0, 0, width, height );
		return ctx;
	}

	window.sampleCorners = ( source, width, height ) => {
		const ctx = drawToCanvas( source, width, height );
		const regions = cornerRegions( width, height );
		const result = {};
		[ 'TL', 'TR', 'BL', 'BR' ].forEach( ( key ) => {
			const [ x, y ] = regions[ key ];
			const data = ctx.getImageData( x, y, regions.size, regions.size ).data;
			const sum = [ 0, 0, 0 ];
			for ( let i = 0; i < data.length; i += 4 ) {
				sum[ 0 ] += data[ i ];
				sum[ 1 ] += data[ i + 1 ];
				sum[ 2 ] += data[ i + 2 ];
			}
			result[ key ] = sum.map( ( value ) => Math.round( value / ( data.length / 4 ) ) );
		} );
		return result;
	};

	/** Mean and standard deviation of luminance. */
	window.luminanceStats = ( source, width, height ) => {
		const data = drawToCanvas( source, width, height ).getImageData( 0, 0, width, height ).data;
		let sum = 0;
		let squares = 0;
		const count = data.length / 4;
		for ( let i = 0; i < data.length; i += 4 ) {
			const luminance = 0.299 * data[ i ] + 0.587 * data[ i + 1 ] + 0.114 * data[ i + 2 ];
			sum += luminance;
			squares += luminance * luminance;
		}
		const mean = sum / count;
		return {
			mean: Math.round( mean * 10 ) / 10,
			sd: Math.round( Math.sqrt( squares / count - mean * mean ) * 10 ) / 10
		};
	};

	window.originalInfo = () => {
		const image = window.bie().fullImage;
		const width = image.naturalWidth;
		const height = image.naturalHeight;
		return {
			w: width,
			h: height,
			corners: window.sampleCorners( image, width, height ),
			stats: window.luminanceStats( image, width, height )
		};
	};

	/** The image exactly as a save would upload it. */
	window.outputInfo = async () => {
		const blob = await window.bie().renderBlob();
		const bitmap = await createImageBitmap( blob );
		return {
			w: bitmap.width,
			h: bitmap.height,
			corners: window.sampleCorners( bitmap, bitmap.width, bitmap.height ),
			stats: window.luminanceStats( bitmap, bitmap.width, bitmap.height )
		};
	};

	window.cornersFromPng = async ( base64 ) => {
		const blob = await ( await fetch( 'data:image/png;base64,' + base64 ) ).blob();
		const bitmap = await createImageBitmap( blob );
		return {
			w: bitmap.width,
			h: bitmap.height,
			corners: window.sampleCorners( bitmap, bitmap.width, bitmap.height )
		};
	};
}

/** Evaluate an expression with `a` bound to the editor's Vue component. */
function app( expression ) {
	return page.evaluate( `( () => { const a = bie(); return ${ expression }; } )()` );
}

function clickButton( name ) {
	return page.getByRole( 'button', { name, exact: true } ).first().click();
}

/** Wait until brightness/contrast preview renders have finished. */
async function settle() {
	await page.waitForFunction( () => !window.bie().previewBusy && !window.bie().previewPending );
	await page.waitForTimeout( 400 );
}

async function setSlider( id, value ) {
	await page.locator( '#' + id ).fill( String( value ) );
	await settle();
}

async function chooseAspect( label ) {
	await page.locator( '.ext-basicimageeditor-tools .cdx-select-vue__handle' ).click();
	await page.getByRole( 'option', { name: label } ).click();
	await page.waitForTimeout( 300 );
}

async function openEditor( fileName ) {
	await page.goto( BASE + '/wiki/File:' + encodeURIComponent( fileName ) );
	const tab = page.locator( '#ca-basicimageeditor a' ).first();
	check( `"Edit image" tab on File:${ fileName }`, await tab.count() > 0 );
	await tab.click();
	await page.waitForFunction( () => {
		const el = document.getElementById( 'ext-basicimageeditor' );
		return el && el._vnode && el._vnode.component.proxy.ready;
	}, null, { timeout: 30000 } );
	await page.evaluate( installPageHelpers );
	await page.waitForTimeout( 300 );
}

/** Corner colours of the image as displayed in the editor (screenshot). */
async function previewCorners( tag ) {
	// Hide the crop overlay so only the displayed image is captured.
	const style = await page.addStyleTag( {
		content: '.cropper-modal, .cropper-crop-box { display: none !important; }'
	} );
	const clip = await page.evaluate( () => {
		const rect = document.querySelector( '.cropper-canvas img' ).getBoundingClientRect();
		return { x: rect.x, y: rect.y, width: rect.width, height: rect.height };
	} );
	const png = await page.screenshot( { clip } );
	fs.writeFileSync( path.join( OUT, `preview-${ tag }.png` ), png );
	await style.evaluate( ( element ) => element.remove() );
	return page.evaluate( ( base64 ) => window.cornersFromPng( base64 ), png.toString( 'base64' ) );
}

function colourDistance( a, b ) {
	return Math.hypot( a[ 0 ] - b[ 0 ], a[ 1 ] - b[ 1 ], a[ 2 ] - b[ 2 ] );
}

/** For each corner of `got`, the corner of `original` with the closest colour. */
function mapCorners( original, got ) {
	const mapping = {};
	Object.keys( got ).forEach( ( key ) => {
		mapping[ key ] = Object.keys( original ).reduce(
			( best, candidate ) => colourDistance( got[ key ], original[ candidate ] ) <
				colourDistance( got[ key ], original[ best ] ) ? candidate : best,
			'TL'
		);
	} );
	return mapping;
}

function sameMapping( a, b ) {
	return JSON.stringify( a ) === JSON.stringify( b );
}

function api( params ) {
	return page.evaluate( async ( query ) => {
		const response = await fetch( mw.util.wikiScript( 'api' ) + '?' + new URLSearchParams(
			Object.assign( { format: 'json', formatversion: 2 }, query )
		), { credentials: 'same-origin' } );
		return response.json();
	}, params );
}

/** Revert MAIN_FILE to its first 1600×1200 version if a run changed it. */
async function restoreMainFile( label ) {
	const data = await api( {
		action: 'query', prop: 'imageinfo', titles: 'File:' + MAIN_FILE,
		iiprop: 'size|archivename', iilimit: 500
	} );
	const versions = data.query.pages[ 0 ].imageinfo;
	if ( versions[ 0 ].width === 1600 && versions[ 0 ].height === 1200 ) {
		return;
	}
	const original = versions.slice().reverse().find(
		( version ) => version.width === 1600 && version.height === 1200 && version.archivename
	);
	if ( !original ) {
		check( `${ label }: no 1600×1200 version of ${ MAIN_FILE } to restore`, false );
		return;
	}
	const result = await page.evaluate( ( params ) => new mw.Api().postWithEditToken( params ), {
		action: 'filerevert',
		filename: MAIN_FILE,
		archivename: original.archivename,
		comment: 'Restore test image after BasicImageEditor walkthrough'
	} );
	check( `${ label }: ${ MAIN_FILE } reverted to its 1600×1200 version`,
		result.filerevert && result.filerevert.result === 'Success', original.archivename );
}

async function fileState( title ) {
	const data = await api( {
		action: 'query', prop: 'imageinfo|revisions', titles: title,
		iiprop: 'timestamp|user|size|comment', iilimit: 500,
		rvprop: 'content', rvslots: 'main'
	} );
	return data.query.pages[ 0 ];
}

async function uploadLogEntry( title ) {
	const data = await api( {
		action: 'query', list: 'logevents', letype: 'upload', leuser: USER,
		lelimit: 10, leprop: 'title|type|tags|comment'
	} );
	return data.query.logevents.find( ( entry ) => entry.title === title );
}

async function testAllTools() {
	await openEditor( QUADRANT_FILE );
	await page.screenshot( { path: path.join( OUT, '01-editor-open.png' ) } );
	const original = await page.evaluate( () => window.originalInfo() );
	check( 'editor loads the full image, 1600×1200', original.w === 1600 && original.h === 1200, [ original.w, original.h ] );
	check( 'Save and Reset disabled before any change',
		await page.getByRole( 'button', { name: 'Save', exact: true } ).isDisabled() &&
		await page.getByRole( 'button', { name: 'Reset', exact: true } ).isDisabled() );

	const keys = Object.keys( original.corners );
	let minDistance = Infinity;
	keys.forEach( ( a, i ) => keys.slice( i + 1 ).forEach( ( b ) => {
		minDistance = Math.min( minDistance, colourDistance( original.corners[ a ], original.corners[ b ] ) );
	} ) );
	check( 'quadrant image corners are distinct', minDistance > 40, { corners: original.corners } );

	// Rotate
	await clickButton( 'Rotate right' );
	let output = await page.evaluate( () => window.outputInfo() );
	check( 'rotate right: output 1200×1600', await app( 'a.rotate90' ) === 90 && output.w === 1200 && output.h === 1600, [ output.w, output.h ] );
	await clickButton( 'Rotate left' );
	await clickButton( 'Rotate left' );
	check( 'rotate left twice: 270°', await app( 'a.rotate90' ) === 270 );
	await clickButton( 'Rotate right' );
	check( 'back to 0°: no changes', await app( 'a.rotate90' ) === 0 && !await app( 'a.hasChanges' ) );

	// Flip
	await clickButton( 'Flip horizontally' );
	output = await page.evaluate( () => window.outputInfo() );
	check( 'flip horizontally: corners mirrored left/right',
		sameMapping( mapCorners( original.corners, output.corners ), { TL: 'TR', TR: 'TL', BL: 'BR', BR: 'BL' } ),
		mapCorners( original.corners, output.corners ) );
	await clickButton( 'Flip horizontally' );
	await clickButton( 'Flip vertically' );
	output = await page.evaluate( () => window.outputInfo() );
	check( 'flip vertically: corners mirrored top/bottom',
		sameMapping( mapCorners( original.corners, output.corners ), { TL: 'BL', TR: 'BR', BL: 'TL', BR: 'TR' } ),
		mapCorners( original.corners, output.corners ) );
	await clickButton( 'Reset' );
	await settle();

	// Straighten
	await setSlider( 'ext-basicimageeditor-straighten', 10 );
	const straightened = await app( '( { degrees: a.straighten, crop: a.lastValidCrop, cropped: a.cropped } )' );
	output = await page.evaluate( () => window.outputInfo() );
	check( 'straighten 10°: largest 4:3 rectangle inside the image (about 1315×986)',
		straightened.degrees === 10 && Math.abs( straightened.crop.width - 1315 ) <= 2 && Math.abs( straightened.crop.height - 986 ) <= 2,
		straightened.crop );
	check( 'straightening alone does not count as cropping', straightened.cropped === false );
	check( 'straightened output has no empty (white) corners',
		Object.values( output.corners ).every( ( c ) => !( c[ 0 ] > 250 && c[ 1 ] > 250 && c[ 2 ] > 250 ) ),
		output.corners );
	await page.screenshot( { path: path.join( OUT, '02-straighten.png' ) } );
	await clickButton( 'Reset' );
	await settle();

	// Brightness and contrast
	await setSlider( 'ext-basicimageeditor-brightness', 40 );
	output = await page.evaluate( () => window.outputInfo() );
	check( 'brightness +40: output brighter', output.stats.mean > original.stats.mean + 5, { before: original.stats, after: output.stats } );
	await setSlider( 'ext-basicimageeditor-brightness', 0 );
	await setSlider( 'ext-basicimageeditor-contrast', -50 );
	output = await page.evaluate( () => window.outputInfo() );
	check( 'contrast -50: output less contrasty', output.stats.sd < original.stats.sd * 0.8, { before: original.stats, after: output.stats } );
	await setSlider( 'ext-basicimageeditor-contrast', 0 );
	check( 'sliders back to 0: no changes', !await app( 'a.hasChanges' ) );

	// Crop ratios
	await chooseAspect( '1:1' );
	output = await page.evaluate( () => window.outputInfo() );
	check( 'crop 1:1: square output', output.w === 1200 && output.h === 1200 && await app( 'a.cropped' ), [ output.w, output.h ] );
	await chooseAspect( '16:9' );
	output = await page.evaluate( () => window.outputInfo() );
	check( 'crop 16:9: 16:9 output', Math.abs( output.w / output.h - 16 / 9 ) < 0.01, [ output.w, output.h ] );

	// All tools together
	await clickButton( 'Rotate right' );
	await clickButton( 'Flip horizontally' );
	await setSlider( 'ext-basicimageeditor-straighten', -5 );
	await setSlider( 'ext-basicimageeditor-brightness', 10 );
	await setSlider( 'ext-basicimageeditor-contrast', 10 );
	await page.locator( '.ext-basicimageeditor-stage' ).focus();
	for ( let i = 0; i < 5; i++ ) {
		await page.keyboard.press( 'Shift+ArrowLeft' );
	}
	await clickButton( 'Save' );
	await page.waitForSelector( '.cdx-dialog' );
	const summary = await page.locator( '.cdx-dialog input.cdx-text-input__input' ).last().inputValue();
	check( 'summary names all six tools',
		[ 'cropped', 'rotated', 'flipped', 'straightened', 'brightness adjusted', 'contrast adjusted' ]
			.every( ( tool ) => summary.toLowerCase().includes( tool ) ),
		summary );
	check( 'summary ends with the BasicImageEditor note',
		summary.endsWith( ' (edited with BasicImageEditor)' ), summary );
	await page.screenshot( { path: path.join( OUT, '03-save-dialog.png' ) } );
	await page.keyboard.press( 'Escape' );
	await page.waitForTimeout( 300 );
	await clickButton( 'Reset' );
	await settle();
	check( 'Reset clears every change', !await app( 'a.hasChanges' ) && await app( 'a.aspect' ) === 'free' );
	return original;
}

async function testFlipAfterRotate( original ) {
	// Expected: which original corner ends up in each output corner.
	const cases = [
		{ tag: 'rotR-flipH', steps: [ 'Rotate right', 'Flip horizontally' ], expect: { TL: 'TL', TR: 'BL', BL: 'TR', BR: 'BR' } },
		{ tag: 'rotR-flipV', steps: [ 'Rotate right', 'Flip vertically' ], expect: { TL: 'BR', TR: 'TR', BL: 'BL', BR: 'TL' } },
		{ tag: 'rotL-flipH', steps: [ 'Rotate left', 'Flip horizontally' ], expect: { TL: 'BR', TR: 'TR', BL: 'BL', BR: 'TL' } },
		{ tag: 'flipH-rotR', steps: [ 'Flip horizontally', 'Rotate right' ], expect: { TL: 'BR', TR: 'TR', BL: 'BL', BR: 'TL' } }
	];
	for ( const testCase of cases ) {
		for ( const step of testCase.steps ) {
			await clickButton( step );
		}
		await page.waitForTimeout( 300 );
		const preview = await previewCorners( testCase.tag );
		const output = await page.evaluate( () => window.outputInfo() );
		const previewMapping = mapCorners( original.corners, preview.corners );
		const outputMapping = mapCorners( original.corners, output.corners );
		check( `${ testCase.tag }: preview orientation correct`, sameMapping( previewMapping, testCase.expect ),
			{ preview: previewMapping, expected: testCase.expect } );
		check( `${ testCase.tag }: saved output matches, 1200×1600`,
			sameMapping( outputMapping, testCase.expect ) && output.w === 1200 && output.h === 1600,
			{ output: outputMapping, size: [ output.w, output.h ] } );
		await clickButton( 'Reset' );
		await settle();
	}
}

async function testSlidersKeepCrop() {
	await page.locator( '.ext-basicimageeditor-stage' ).focus();
	const presses = [ [ 'Shift+ArrowLeft', 15 ], [ 'Shift+ArrowUp', 10 ], [ 'ArrowRight', 4 ], [ 'ArrowDown', 3 ] ];
	for ( const [ key, count ] of presses ) {
		for ( let i = 0; i < count; i++ ) {
			await page.keyboard.press( key );
		}
	}
	await page.waitForTimeout( 300 );
	const before = await app( 'a.cropper.getData( true )' );
	check( 'keyboard crop: box resized and moved', await app( 'a.cropped' ) && before.x > 0 && before.y > 0, before );
	await setSlider( 'ext-basicimageeditor-brightness', 35 );
	await setSlider( 'ext-basicimageeditor-contrast', 25 );
	await setSlider( 'ext-basicimageeditor-brightness', -20 );
	const after = await app( 'a.cropper.getData( true )' );
	check( 'brightness and contrast keep the crop box',
		[ 'x', 'y', 'width', 'height' ].every( ( key ) => Math.abs( before[ key ] - after[ key ] ) <= 1 ),
		{ before, after } );
	const output = await page.evaluate( () => window.outputInfo() );
	const crop = await app( 'a.fullResolutionCrop()' );
	check( 'output size equals the crop',
		Math.abs( output.w - Math.round( crop.width ) ) <= 1 && Math.abs( output.h - Math.round( crop.height ) ) <= 1,
		{ output: [ output.w, output.h ], crop } );
	await page.screenshot( { path: path.join( OUT, '04-crop-kept.png' ) } );
	await clickButton( 'Reset' );
	await settle();
}

async function testOrientation() {
	await page.goto( BASE + '/wiki/File:' + ORIENTATION_FILE );
	const shown = await page.evaluate( () => {
		const image = document.querySelector( '#file img' );
		return { w: image.naturalWidth, h: image.naturalHeight };
	} );
	await openEditor( ORIENTATION_FILE );
	const editor = await page.evaluate( () => ( {
		full: window.originalInfo(),
		preview: window.bie().previewSize()
	} ) );
	const portrait = shown.h > shown.w;
	check( 'EXIF orientation: editor shows the image upright, like the File page',
		portrait === editor.full.h > editor.full.w && portrait === editor.preview.height > editor.preview.width,
		{ filePage: shown, editor: [ editor.full.w, editor.full.h ], preview: editor.preview } );
	await page.screenshot( { path: path.join( OUT, '05-orientation.png' ) } );
	await setSlider( 'ext-basicimageeditor-brightness', 5 );
	const output = await page.evaluate( () => window.outputInfo() );
	check( 'EXIF orientation: output stays upright', output.w === editor.full.w && output.h === editor.full.h, [ output.w, output.h ] );
	await clickButton( 'Reset' );
	await settle();
}

async function testSaveNewVersion() {
	const title = 'File:' + MAIN_FILE;
	await openEditor( MAIN_FILE );
	const before = await fileState( title );
	await clickButton( 'Rotate right' );
	await setSlider( 'ext-basicimageeditor-brightness', 10 );
	await clickButton( 'Save' );
	await page.waitForSelector( '.cdx-dialog' );
	check( 'save dialog defaults to "New version" and warns about all uses',
		await page.getByRole( 'radio', { name: 'New version of this file' } ).isChecked() &&
		await page.locator( '.cdx-dialog .cdx-message--warning' ).count() > 0 );
	await page.locator( '.cdx-dialog input.cdx-text-input__input' ).last()
		.fill( 'BasicImageEditor walkthrough: rotated, brightness adjusted' );
	await Promise.all( [
		page.waitForURL( /basicimageeditor=saved/, { timeout: 60000, waitUntil: 'commit' } ),
		page.locator( '.cdx-dialog__footer' ).getByRole( 'button', { name: 'Save', exact: true } ).click()
	] );
	await page.waitForLoadState( 'load' );
	await page.waitForSelector( '.mw-notification', { timeout: 10000 } ).catch( () => {} );
	const notices = await page.locator( '.mw-notification' ).allInnerTexts();
	check( 'back on the File page with the "saved" notice',
		notices.some( ( text ) => text.includes( 'The edited image was saved.' ) ), notices );
	await page.waitForTimeout( 500 );
	check( 'marker removed from the address bar', !page.url().includes( 'basicimageeditor=saved' ), page.url() );
	await page.screenshot( { path: path.join( OUT, '06-saved-version.png' ), fullPage: true } );

	const after = await fileState( title );
	const latest = after.imageinfo[ 0 ];
	check( 'one version added to the file history', after.imageinfo.length === before.imageinfo.length + 1,
		[ before.imageinfo.length, after.imageinfo.length ] );
	check( 'new version: right user, 1200×1600, summary kept',
		latest.user === USER && latest.width === 1200 && latest.height === 1600 && latest.comment.includes( 'walkthrough' ),
		latest );
	check( 'description page unchanged', after.revisions[ 0 ].slots.main.content === before.revisions[ 0 ].slots.main.content );
	const entry = await uploadLogEntry( title );
	check( 'upload log: overwrite, tagged basicimageeditor',
		entry && entry.action === 'overwrite' && entry.tags.includes( 'basicimageeditor' ), entry );
	const stored = await page.evaluate( async ( fileTitle ) => {
		const info = await new mw.Api().get( {
			action: 'query', prop: 'imageinfo', iiprop: 'url', titles: fileTitle, formatversion: 2
		} );
		const blob = await ( await fetch( info.query.pages[ 0 ].imageinfo[ 0 ].url, { cache: 'reload' } ) ).blob();
		const bitmap = await createImageBitmap( blob );
		return { w: bitmap.width, h: bitmap.height };
	}, title );
	check( 'stored file decodes as 1200×1600', stored.w === 1200 && stored.h === 1600, stored );
	return after;
}

async function testSaveNewFile( source ) {
	const sourceTitle = 'File:' + MAIN_FILE;
	await openEditor( MAIN_FILE );
	await clickButton( 'Flip horizontally' );
	await clickButton( 'Save' );
	await page.waitForSelector( '.cdx-dialog' );
	await page.getByRole( 'radio', { name: 'New file' } ).check();
	const nameInput = page.locator( '.cdx-dialog input.cdx-text-input__input' ).first();
	const saveButton = page.locator( '.cdx-dialog__footer' ).getByRole( 'button', { name: 'Save', exact: true } );

	await nameInput.fill( MAIN_FILE.replace( /\.[^.]+$/, '' ) );
	await page.waitForTimeout( 200 );
	const sameNameError = await app( 'a.newNameError' );
	check( 'new file: the source name is refused', await saveButton.isDisabled() && !!sameNameError, sameNameError );

	await nameInput.fill( ORIENTATION_FILE.replace( /\.[^.]+$/, '' ) );
	await saveButton.click();
	await page.waitForFunction( () => window.bie().saveError || !window.bie().saving, null, { timeout: 60000 } );
	await page.waitForTimeout( 500 );
	const existsError = await app( 'a.saveError' );
	check( 'new file: an existing name is refused', existsError.includes( ORIENTATION_FILE ), existsError );

	const newName = 'BIE-test-derived-' + Date.now() + '.jpg';
	await nameInput.fill( newName.replace( /\.jpg$/, '' ) );
	await page.locator( '.cdx-dialog input.cdx-text-input__input' ).last()
		.fill( 'BasicImageEditor walkthrough: flipped copy' );
	const saved = page.waitForURL( /basicimageeditor=saved/, { timeout: 60000, waitUntil: 'commit' } );
	await saveButton.click();
	// A run identical to an earlier one triggers the "duplicate" warning.
	const saveAnyway = page.getByRole( 'button', { name: 'Save anyway', exact: true } );
	await Promise.race( [ saved, saveAnyway.waitFor( { timeout: 60000 } ) ] ).catch( () => {} );
	if ( await saveAnyway.isVisible().catch( () => false ) ) {
		const text = await page.locator( '.cdx-dialog' ).filter( { has: saveAnyway } ).innerText();
		check( 'duplicate warning dialog shown', /identical to/.test( text ), text );
		await saveAnyway.click();
	}
	await saved;
	await page.waitForLoadState( 'load' );
	await page.screenshot( { path: path.join( OUT, '07-saved-new-file.png' ), fullPage: true } );

	const created = await fileState( 'File:' + newName );
	check( 'new file created: right user, 1200×1600',
		!created.missing && created.imageinfo[ 0 ].user === USER &&
		created.imageinfo[ 0 ].width === 1200 && created.imageinfo[ 0 ].height === 1600,
		created.imageinfo );
	const description = created.revisions[ 0 ].slots.main.content;
	check( 'new file description: source description plus "Derived from"',
		description.startsWith( source.revisions[ 0 ].slots.main.content.trim() ) &&
		description.includes( `Derived from [[:${ sourceTitle }]]` ),
		description );
	const entry = await uploadLogEntry( 'File:' + newName );
	check( 'upload log: new upload, tagged basicimageeditor',
		entry && entry.action === 'upload' && entry.tags.includes( 'basicimageeditor' ), entry );
	const sourceAfter = await fileState( sourceTitle );
	check( 'source file unchanged by "New file"', sourceAfter.imageinfo.length === source.imageinfo.length );
}

try {
	await page.goto( BASE + '/wiki/Special:UserLogin' );
	await page.fill( '#wpName1', USER );
	await page.fill( '#wpPassword1', PASSWORD );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wpLoginAttempt' ) ] );
	const loggedInAs = await page.evaluate( () => mw.config.get( 'wgUserName' ) );
	check( `logged in as ${ USER }`, loggedInAs === USER, loggedInAs );

	await restoreMainFile( 'setup' );
	const original = await testAllTools();
	await testFlipAfterRotate( original );
	await testSlidersKeepCrop();
	await testOrientation();
	const source = await testSaveNewVersion();
	await testSaveNewFile( source );
	await restoreMainFile( 'teardown' );
} catch ( error ) {
	check( 'walkthrough ran to completion', false, String( error && error.stack || error ) );
	await page.screenshot( { path: path.join( OUT, 'failure.png' ), fullPage: true } ).catch( () => {} );
}

check( 'no JavaScript errors in the console', consoleErrors.length === 0, consoleErrors );
const failed = results.filter( ( result ) => !result.ok );
console.log( `\n${ results.length - failed.length }/${ results.length } checks passed` );
fs.writeFileSync( path.join( OUT, 'results.json' ), JSON.stringify( results, null, '\t' ) );
await browser.close();
process.exit( failed.length ? 1 : 0 );
