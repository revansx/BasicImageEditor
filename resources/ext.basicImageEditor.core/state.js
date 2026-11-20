/**
 * Editor state helpers: change detection, edit summary, file names and the
 * description of a "New file".
 */

/** Tools in the order they are named in the edit summary. */
const TOOLS = [ 'crop', 'rotate', 'flip', 'straighten', 'brightness', 'contrast' ];

/**
 * @return {Object} State of an unedited image
 */
function createInitialState() {
	return {
		rotate90: 0,
		straighten: 0,
		scaleX: 1,
		scaleY: 1,
		brightness: 0,
		contrast: 0
	};
}

/**
 * Tools that have changed something, in summary order.
 *
 * @param {Object} state See createInitialState()
 * @param {boolean} cropped Whether the crop removes part of the image
 * @return {string[]} Keys from TOOLS
 */
function getChangedTools( state, cropped ) {
	const changed = {
		crop: cropped,
		rotate: state.rotate90 !== 0,
		flip: state.scaleX !== 1 || state.scaleY !== 1,
		straighten: state.straighten !== 0,
		brightness: state.brightness !== 0,
		contrast: state.contrast !== 0
	};
	return TOOLS.filter( ( tool ) => changed[ tool ] );
}

/**
 * Default edit summary, such as "Cropped, straightened, brightness adjusted".
 *
 * @param {string[]} tools Keys from getChangedTools()
 * @param {Function} describe Returns the summary phrase for a tool key
 * @param {string} separator List separator, such as ", "
 * @return {string}
 */
function buildSummary( tools, describe, separator ) {
	const text = tools.map( describe ).join( separator );
	return text.charAt( 0 ).toUpperCase() + text.slice( 1 );
}

/**
 * Split a file name into base name and extension.
 *
 * @param {string} fileName Such as "Holiday_photo.jpg"
 * @return {{base: string, extension: string}}
 */
function splitFileName( fileName ) {
	const dot = fileName.lastIndexOf( '.' );
	if ( dot <= 0 ) {
		return { base: fileName, extension: '' };
	}
	return { base: fileName.slice( 0, dot ), extension: fileName.slice( dot + 1 ) };
}

/**
 * Description page text for a "New file": the source page's text, followed by
 * a line naming the source.
 *
 * @param {string|null} sourceText Wikitext of the source description page
 * @param {string} derivedLine Such as "Derived from [[:File:Example.jpg]]"
 * @return {string}
 */
function buildNewFileDescription( sourceText, derivedLine ) {
	const text = ( sourceText || '' ).replace( /\s+$/, '' );
	return text ? text + '\n\n' + derivedLine : derivedLine;
}

/**
 * Whether the file has a newer version than the one loaded into the editor.
 *
 * @param {string} loadedTimestamp ISO 8601 timestamp recorded on load
 * @param {string} currentTimestamp ISO 8601 timestamp from the API
 * @return {boolean}
 */
function hasConflict( loadedTimestamp, currentTimestamp ) {
	return Date.parse( currentTimestamp ) !== Date.parse( loadedTimestamp );
}

module.exports = {
	TOOLS,
	createInitialState,
	getChangedTools,
	buildSummary,
	splitFileName,
	buildNewFileDescription,
	hasConflict
};
