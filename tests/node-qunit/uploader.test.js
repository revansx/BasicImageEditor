'use strict';

const sinon = require( 'sinon' );
const uploader = require( '../../resources/ext.basicImageEditor/uploader.js' );

/**
 * Stand-in for the jQuery promise that mw.Api#chunkedUpload() returns:
 * progress() is chainable, then() takes a success and a failure handler, and
 * the failure handler receives ( code, result ) like mw.Api does.
 *
 * @param {Object} outcome
 * @param {number[]} [outcome.progress] Fractions reported before the end
 * @param {Object} [outcome.resolve] Value of a successful upload
 * @param {Array} [outcome.reject] Arguments of a failed upload
 * @return {Object}
 */
function fakeUpload( outcome ) {
	const progressHandlers = [];
	const promise = {
		progress( handler ) {
			progressHandlers.push( handler );
			return promise;
		},
		then( onSuccess, onFailure ) {
			( outcome.progress || [] ).forEach( ( fraction ) => {
				progressHandlers.forEach( ( handler ) => handler( fraction ) );
			} );
			if ( outcome.reject ) {
				onFailure( ...outcome.reject );
			} else {
				onSuccess( outcome.resolve );
			}
			return promise;
		}
	};
	return promise;
}

/**
 * Same for a plain API request: a failure is reported as ( code, result ).
 *
 * @param {Object} outcome
 * @param {Object} [outcome.resolve] Value of a successful request
 * @param {Array} [outcome.reject] Arguments of a failed request
 * @return {Object}
 */
function fakeRequest( outcome ) {
	return {
		then( onSuccess, onFailure ) {
			if ( outcome.reject ) {
				onFailure( ...outcome.reject );
			} else {
				onSuccess( outcome.resolve );
			}
		}
	};
}

QUnit.module( 'ext.basicImageEditor.uploader', ( hooks ) => {
	hooks.afterEach( () => {
		sinon.restore();
	} );

	QUnit.module( 'fetchCurrentTimestamp', () => {
		QUnit.test( 'asks for the timestamp of the file by its title', async ( assert ) => {
			const api = { get: sinon.stub().returns( Promise.resolve( {
				query: { pages: [ { imageinfo: [ { timestamp: '2024-05-06T07:08:09Z' } ] } ] }
			} ) ) };

			const timestamp = await uploader.fetchCurrentTimestamp( api, 'File:Example.jpg' );

			assert.strictEqual( timestamp, '2024-05-06T07:08:09Z' );
			assert.deepEqual( api.get.args, [ [ {
				action: 'query',
				prop: 'imageinfo',
				iiprop: 'timestamp',
				titles: 'File:Example.jpg',
				formatversion: 2
			} ] ] );
		} );

		QUnit.test( 'is empty when the file no longer exists', async ( assert ) => {
			const missing = { query: { pages: [ { missing: true } ] } };
			const api = { get: () => Promise.resolve( missing ) };

			assert.strictEqual( await uploader.fetchCurrentTimestamp( api, 'File:Gone.jpg' ), '' );
		} );
	} );

	QUnit.module( 'stash', () => {
		QUnit.test( 'uploads in chunks to the stash and resolves with the file key', async ( assert ) => {
			const blob = { size: 3 };
			const api = { chunkedUpload: sinon.stub().returns( fakeUpload( {
				resolve: { upload: { filekey: 'abc.jpg' } }
			} ) ) };

			const key = await uploader.stash( api, blob, 'Target.jpg' );

			assert.strictEqual( key, 'abc.jpg' );
			assert.deepEqual(
				api.chunkedUpload.args,
				[ [ blob, { stash: true, filename: 'Target.jpg', ignorewarnings: true } ] ]
			);
		} );

		QUnit.test( 'reports the progress as it is made', async ( assert ) => {
			const api = { chunkedUpload: () => fakeUpload( {
				progress: [ 0.25, 0.5, 1 ],
				resolve: { upload: { filekey: 'k' } }
			} ) };
			const onProgress = sinon.spy();

			await uploader.stash( api, {}, 'T.jpg', onProgress );

			assert.deepEqual( onProgress.args, [ [ 0.25 ], [ 0.5 ], [ 1 ] ] );
		} );

		QUnit.test( 'works without a progress callback', async ( assert ) => {
			const api = { chunkedUpload: () => fakeUpload( {
				progress: [ 0.5 ],
				resolve: { upload: { filekey: 'k' } }
			} ) };

			assert.strictEqual( await uploader.stash( api, {}, 'T.jpg' ), 'k' );
		} );

		QUnit.test( 'a stashed file counts even if a warning is reported as an error', async ( assert ) => {
			const api = { chunkedUpload: () => fakeUpload( {
				reject: [ 'duplicate', { upload: { result: 'Warning', filekey: 'warned.jpg' } } ]
			} ) };

			assert.strictEqual( await uploader.stash( api, {}, 'T.jpg' ), 'warned.jpg' );
		} );

		QUnit.test( 'a failure is not accepted even if it carries a file key', async ( assert ) => {
			const result = { upload: { result: 'Failure', filekey: 'bad.jpg' } };
			const api = { chunkedUpload: () => fakeUpload( { reject: [ 'internal', result ] } ) };

			try {
				await uploader.stash( api, {}, 'T.jpg' );
				assert.true( false, 'should have been rejected' );
			} catch ( error ) {
				assert.deepEqual( error, { code: 'internal', result } );
			}
		} );

		QUnit.test( 'rejects with the code and result when nothing was stashed', async ( assert ) => {
			const api = { chunkedUpload: () => fakeUpload( {
				reject: [ 'http', { error: { info: 'timeout' } } ]
			} ) };

			try {
				await uploader.stash( api, {}, 'T.jpg' );
				assert.true( false, 'should have been rejected' );
			} catch ( error ) {
				assert.strictEqual( error.code, 'http' );
				assert.deepEqual( error.result, { error: { info: 'timeout' } } );
			}
		} );

		QUnit.test( 'a failure without a file key is rejected, not resolved with nothing', async ( assert ) => {
			const result = { upload: { result: 'Warning' } };
			const api = { chunkedUpload: () => fakeUpload( { reject: [ 'duplicate', result ] } ) };

			try {
				await uploader.stash( api, {}, 'T.jpg' );
				assert.true( false, 'should have been rejected' );
			} catch ( error ) {
				assert.deepEqual( error, { code: 'duplicate', result } );
			}
		} );

		QUnit.test( 'rejects when the failure has no result at all', async ( assert ) => {
			const api = { chunkedUpload: () => fakeUpload( { reject: [ 'http' ] } ) };

			try {
				await uploader.stash( api, {}, 'T.jpg' );
				assert.true( false, 'should have been rejected' );
			} catch ( error ) {
				assert.deepEqual( error, { code: 'http', result: undefined } );
			}
		} );
	} );

	QUnit.module( 'publish', () => {
		QUnit.test( 'publishes the stashed file with an edit token and the given parameters', async ( assert ) => {
			const result = { upload: { result: 'Success', filename: 'Target.jpg' } };
			const post = sinon.stub().returns( fakeRequest( { resolve: result } ) );
			const api = { postWithEditToken: post };

			const published = await uploader.publish( api, 'abc.jpg', {
				filename: 'Target.jpg',
				comment: 'Cropped',
				tags: 'basicimageeditor'
			} );

			assert.strictEqual( published, result );
			assert.deepEqual( post.args, [ [ {
				action: 'upload',
				format: 'json',
				filekey: 'abc.jpg',
				filename: 'Target.jpg',
				comment: 'Cropped',
				tags: 'basicimageeditor'
			} ] ] );
		} );

		QUnit.test( 'success counts even when the API lists warnings next to it', async ( assert ) => {
			const result = { upload: { result: 'Success', warnings: { exists: 'Target.jpg' } } };
			const api = { postWithEditToken: () => fakeRequest( { resolve: result } ) };

			assert.strictEqual( await uploader.publish( api, 'k', {} ), result );
		} );

		QUnit.test( 'stopping on warnings rejects with the warnings so the user can decide', async ( assert ) => {
			const result = { upload: { result: 'Warning', warnings: { exists: 'Target.jpg' }, filekey: 'k2' } };
			const api = { postWithEditToken: () => fakeRequest( { resolve: result } ) };

			try {
				await uploader.publish( api, 'k', {} );
				assert.true( false, 'should have been rejected' );
			} catch ( error ) {
				assert.deepEqual( error, { code: 'warnings', result } );
			}
		} );

		QUnit.test( 'an answer that is neither success nor warnings is unknown', async ( assert ) => {
			const unexpected = [
				{ upload: { result: 'Failure' } },
				{ upload: {} },
				{}
			];
			for ( const result of unexpected ) {
				const api = { postWithEditToken: () => fakeRequest( { resolve: result } ) };
				try {
					await uploader.publish( api, 'k', {} );
					assert.true( false, 'should have been rejected: ' + JSON.stringify( result ) );
				} catch ( error ) {
					assert.deepEqual( error, { code: 'unknown', result }, JSON.stringify( result ) );
				}
			}
		} );

		QUnit.test( 'an API error rejects with its code and result', async ( assert ) => {
			const result = { error: { code: 'badtoken', info: 'Invalid CSRF token.' } };
			const api = { postWithEditToken: () => fakeRequest( { reject: [ 'badtoken', result ] } ) };

			try {
				await uploader.publish( api, 'k', {} );
				assert.true( false, 'should have been rejected' );
			} catch ( error ) {
				assert.deepEqual( error, { code: 'badtoken', result } );
			}
		} );
	} );
} );
