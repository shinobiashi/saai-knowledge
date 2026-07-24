// Repeated to push "Second Section" below the fold at the configured
// viewport height, so the TOC navigation test's in-viewport assertion
// actually depends on the click having scrolled the page (see
// kb-layout-*-theme.spec.js's "the table of contents navigates to the
// clicked heading" tests).
const FILLER_PARAGRAPH =
	'<!-- wp:paragraph --><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p><!-- /wp:paragraph -->';

const KB_CONTENT =
	'<!-- wp:heading --><h2>First Section</h2><!-- /wp:heading -->' +
	FILLER_PARAGRAPH.repeat( 20 ) +
	'<!-- wp:heading --><h2>Second Section</h2><!-- /wp:heading -->' +
	'<!-- wp:paragraph --><p>More content here.</p><!-- /wp:paragraph -->';

/**
 * Decodes the numeric HTML entities WordPress's wptexturize() may introduce
 * into a `title.rendered` REST field (e.g. it turns a title fragment like
 * "030x66" into "030&#215;66"), so specs can compare it against the plain
 * text a browser actually renders/exposes as an accessible name.
 *
 * @param {string} html
 * @return {string}
 */
function decodeNumericEntities( html ) {
	// String.fromCodePoint(), not fromCharCode(): a numeric character
	// reference specifies a Unicode code point, and fromCharCode() produces
	// incorrect output for code points above 0xFFFF (e.g. emoji).
	return html.replace( /&#(\d+);/g, ( _match, code ) =>
		String.fromCodePoint( Number( code ) )
	);
}

/**
 * Creates a saai_category term and a published saai_kb article (with two
 * headings, for the TOC) assigned to it, via the REST API.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @return {Promise<{term: Object, post: Object}>}
 */
async function createKbFixtures( requestUtils ) {
	const unique = Math.random().toString( 36 ).slice( 2, 8 );

	const term = await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/saai_category',
		data: { name: `E2E Category ${ unique }` },
	} );

	const post = await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/saai_kb',
		data: {
			title: `E2E KB Article ${ unique }`,
			status: 'publish',
			content: KB_CONTENT,
			saai_category: [ term.id ],
		},
	} );

	post.title.rendered = decodeNumericEntities( post.title.rendered );

	return { term, post };
}

/**
 * Deletes fixtures created by createKbFixtures().
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @param {{term: Object, post: Object}}                                fixtures
 */
async function deleteKbFixtures( requestUtils, fixtures ) {
	if ( ! fixtures ) {
		return;
	}

	await requestUtils.rest( {
		method: 'DELETE',
		path: `/wp/v2/saai_kb/${ fixtures.post.id }`,
		params: { force: true },
	} );

	await requestUtils.rest( {
		method: 'DELETE',
		path: `/wp/v2/saai_category/${ fixtures.term.id }`,
		params: { force: true },
	} );
}

module.exports = { createKbFixtures, deleteKbFixtures };
