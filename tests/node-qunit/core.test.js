'use strict';

QUnit.module( 'ext.basicImageEditor.core', () => {
	const { adjustments, geometry, state } = require( '../../resources/ext.basicImageEditor.core/index.js' );

	QUnit.module( 'adjustments' );

	QUnit.test( 'zero settings leave pixels unchanged', ( assert ) => {
		const pixels = new Uint8ClampedArray( [ 0, 64, 128, 255, 200, 10, 90, 128 ] );
		adjustments.applyToPixels( pixels, 0, 0 );
		assert.deepEqual( Array.from( pixels ), [ 0, 64, 128, 255, 200, 10, 90, 128 ] );
	} );

	QUnit.test( 'lookup table with zero settings is the identity', ( assert ) => {
		const table = adjustments.buildLookupTable( 0, 0 );
		assert.deepEqual( Array.from( table ), Array.from( { length: 256 }, ( _, i ) => i ) );
	} );

	QUnit.test( 'brightness shifts values and clamps', ( assert ) => {
		const up = adjustments.buildLookupTable( 100, 0 );
		assert.strictEqual( up[ 0 ], 128, 'black becomes mid-grey at +100' );
		assert.strictEqual( up[ 200 ], 255, 'clamped at white' );
		const down = adjustments.buildLookupTable( -50, 0 );
		assert.strictEqual( down[ 128 ], 64, '-50 subtracts 64' );
		assert.strictEqual( down[ 10 ], 0, 'clamped at black' );
	} );

	QUnit.test( 'contrast keeps mid-grey and spreads or narrows around it', ( assert ) => {
		assert.strictEqual( adjustments.contrastFactor( 0 ), 259 * 255 / ( 255 * 259 ), 'factor 1 at 0' );
		const more = adjustments.buildLookupTable( 0, 50 );
		const less = adjustments.buildLookupTable( 0, -50 );
		assert.strictEqual( more[ 128 ], 128 );
		assert.strictEqual( less[ 128 ], 128 );
		assert.true( more[ 160 ] > 160 && more[ 96 ] < 96, 'more contrast spreads values' );
		assert.true( less[ 160 ] < 160 && less[ 96 ] > 96, 'less contrast narrows values' );
	} );

	QUnit.test( 'alpha is never changed', ( assert ) => {
		const pixels = new Uint8ClampedArray( [ 10, 20, 30, 77 ] );
		adjustments.applyToPixels( pixels, 80, -40 );
		assert.strictEqual( pixels[ 3 ], 77 );
	} );

	QUnit.test( 'brightness is applied before contrast', ( assert ) => {
		const table = adjustments.buildLookupTable( 50, 50 );
		const factor = adjustments.contrastFactor( 50 );
		const expected = Math.round( factor * ( Math.min( 255, 100 + 64 ) - 128 ) + 128 );
		assert.strictEqual( table[ 100 ], expected );
	} );

	QUnit.module( 'geometry' );

	QUnit.test( 'rotatedBounds', ( assert ) => {
		const quarter = geometry.rotatedBounds( 400, 300, 90 );
		assert.strictEqual( Math.round( quarter.width ), 300 );
		assert.strictEqual( Math.round( quarter.height ), 400 );
		const none = geometry.rotatedBounds( 400, 300, 0 );
		assert.deepEqual( none, { width: 400, height: 300 } );
	} );

	QUnit.test( 'largestInscribedRect without rotation is the whole image', ( assert ) => {
		assert.deepEqual(
			geometry.largestInscribedRect( 400, 300, 0, 4 / 3 ),
			{ x: 0, y: 0, width: 400, height: 300 }
		);
	} );

	QUnit.test( 'largestInscribedRect at quarter turns keeps every pixel', ( assert ) => {
		[ 90, 180, 270, -90 ].forEach( ( degrees ) => {
			const ratio = geometry.displayedAspectRatio( 1600, 1200, degrees );
			const rect = geometry.largestInscribedRect( 1600, 1200, degrees, ratio );
			const swapped = degrees % 180 !== 0;
			assert.deepEqual(
				[ rect.width, rect.height ],
				swapped ? [ 1200, 1600 ] : [ 1600, 1200 ],
				`${ degrees }°`
			);
		} );
	} );

	QUnit.test( 'largestInscribedRect after straightening lies on the image', ( assert ) => {
		[ -45, -10, -0.1, 3.7, 20, 45, 93, 180 + 12 ].forEach( ( degrees ) => {
			[ 4 / 3, 1, 16 / 9 ].forEach( ( ratio ) => {
				const rect = geometry.largestInscribedRect( 400, 300, degrees, ratio );
				assert.true(
					geometry.isRectInsideRotatedImage( rect, 400, 300, degrees, 0.001 ),
					`${ degrees }° ratio ${ ratio.toFixed( 2 ) }`
				);
				assert.true( Math.abs( rect.width / rect.height - ratio ) < 0.02, 'keeps the ratio' );
			} );
		} );
	} );

	QUnit.test( 'isRectInsideRotatedImage rejects empty corners', ( assert ) => {
		const bounds = geometry.rotatedBounds( 400, 300, 10 );
		const whole = { x: 0, y: 0, width: bounds.width, height: bounds.height };
		assert.false( geometry.isRectInsideRotatedImage( whole, 400, 300, 10 ), 'bounding box has empty corners' );
		const image = { x: 0, y: 0, width: 400, height: 300 };
		assert.true( geometry.isRectInsideRotatedImage( image, 400, 300, 0 ) );
		assert.false( geometry.isRectInsideRotatedImage( { x: 0, y: 0, width: 0, height: 10 }, 400, 300, 0 ), 'empty rectangle' );
	} );

	QUnit.test( 'flip on screen reverses the rotation and toggles the image-frame flip', ( assert ) => {
		const flipped = geometry.flip( { rotate90: 90, straighten: 2.5, scaleX: 1, scaleY: 1 }, 'horizontal' );
		assert.deepEqual( flipped, { rotate90: 270, straighten: -2.5, scaleX: -1, scaleY: 1 } );
		const twice = geometry.flip( flipped, 'horizontal' );
		assert.deepEqual( twice, { rotate90: 90, straighten: 2.5, scaleX: 1, scaleY: 1 }, 'flipping twice restores' );
		const vertical = geometry.flip( { rotate90: 0, straighten: 0, scaleX: 1, scaleY: 1 }, 'vertical' );
		assert.deepEqual( vertical, { rotate90: 0, straighten: 0, scaleX: 1, scaleY: -1 } );
	} );

	QUnit.test( 'displayedAspectRatio and normalizeAngle', ( assert ) => {
		assert.strictEqual( geometry.displayedAspectRatio( 400, 300, 0 ), 4 / 3 );
		assert.strictEqual( geometry.displayedAspectRatio( 400, 300, 270 ), 3 / 4 );
		assert.strictEqual( geometry.normalizeAngle( -90 ), 270 );
		assert.strictEqual( geometry.normalizeAngle( 450 ), 90 );
	} );

	QUnit.module( 'state' );

	QUnit.test( 'getChangedTools lists tools in summary order', ( assert ) => {
		const unchanged = state.createInitialState();
		assert.deepEqual( state.getChangedTools( unchanged, false ), [] );
		const edited = Object.assign(
			state.createInitialState(),
			{ straighten: 1.5, brightness: 10 }
		);
		assert.deepEqual( state.getChangedTools( edited, true ), [ 'crop', 'straighten', 'brightness' ] );
	} );

	QUnit.test( 'buildSummary', ( assert ) => {
		const words = { crop: 'cropped', straighten: 'straightened', brightness: 'brightness adjusted' };
		assert.strictEqual(
			state.buildSummary( [ 'crop', 'straighten', 'brightness' ], ( tool ) => words[ tool ], ', ' ),
			'Cropped, straightened, brightness adjusted'
		);
	} );

	QUnit.test( 'splitFileName', ( assert ) => {
		assert.deepEqual( state.splitFileName( 'Holiday_photo.final.jpg' ), { base: 'Holiday_photo.final', extension: 'jpg' } );
		assert.deepEqual( state.splitFileName( 'NoExtension' ), { base: 'NoExtension', extension: '' } );
	} );

	QUnit.test( 'buildNewFileDescription', ( assert ) => {
		assert.strictEqual(
			state.buildNewFileDescription( '== Summary ==\nA cat.\n\n[[Category:Cats]]\n', 'Derived from [[File:Cat.jpg]]' ),
			'== Summary ==\nA cat.\n\n[[Category:Cats]]\n\nDerived from [[File:Cat.jpg]]'
		);
		assert.strictEqual( state.buildNewFileDescription( '', 'Derived from [[File:Cat.jpg]]' ), 'Derived from [[File:Cat.jpg]]' );
		assert.strictEqual( state.buildNewFileDescription( null, 'X' ), 'X' );
	} );

	QUnit.test( 'hasConflict compares points in time', ( assert ) => {
		assert.false( state.hasConflict( '2026-11-11T17:03:05Z', '2026-11-11T17:03:05Z' ) );
		assert.true( state.hasConflict( '2026-11-11T17:03:05Z', '2026-11-11T18:00:00Z' ) );
	} );
} );
