'use strict';

/*
 * Browser globals for the node-based QUnit tests: a jsdom window and a small
 * stand-in for mw. Tests replace the pieces they need with sinon stubs.
 */
const { JSDOM } = require( 'jsdom' );

const dom = new JSDOM( '<!DOCTYPE html><html><body></body></html>', {
	url: 'https://wiki.example/wiki/Main_Page'
} );

global.window = dom.window;
global.document = dom.window.document;
global.location = dom.window.location;
global.history = dom.window.history;

global.mw = {
	msg: ( key ) => key,
	notify: () => {}
};
