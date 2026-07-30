/**
 * Per-document detail page e2e: élő kliens-oldali analízis + pdf.js előnézet.
 * A fixture PDF-et futásidőben másoljuk a mountolt plugin-fába és wp-cli-vel
 * importáljuk a tests site médiatárába (a repo nem duplikál binárist).
 */
const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'node:child_process' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

// The fixture PDF lives in the private development tree — in the public
// plugin repo it is absent and this spec skips itself (see test.skip below).
const pluginRoot = path.resolve( __dirname, '../..' );
const fixtureSrc = path.resolve( pluginRoot, '../web/tests/e2e/fixtures/0000054.pdf' );
const fixtureTmp = path.join( __dirname, '.tmp-doc-fixture.pdf' );
const containerPath = 'wp-content/plugins/wp-plugin/tests/e2e/.tmp-doc-fixture.pdf';

let attachmentId = 0;

async function login( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', process.env.WP_ADMIN_USER || 'admin' );
	await page.fill( '#user_pass', process.env.WP_ADMIN_PASS || 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

test.skip( ! fs.existsSync( fixtureSrc ), 'fixture PDF not available (private development tree only)' );

test.beforeAll( () => {
	fs.copyFileSync( fixtureSrc, fixtureTmp );
	const out = execSync(
		`npx wp-env run tests-cli -- wp media import ${ containerPath } --porcelain`,
		{ cwd: pluginRoot, encoding: 'utf8' }
	);
	attachmentId = parseInt( ( out.match( /^\d+$/m ) || [ '0' ] )[ 0 ], 10 );
	if ( ! attachmentId ) {
		throw new Error( 'fixture import failed: ' + out );
	}
} );

test.afterAll( () => {
	fs.rmSync( fixtureTmp, { force: true } );
	if ( attachmentId ) {
		execSync( `npx wp-env run tests-cli -- wp post delete ${ attachmentId } --force`, { cwd: pluginRoot } );
	}
} );

test.beforeEach( async ( { page } ) => {
	await login( page );
} );

test( 'document page live-analyzes the PDF and renders the preview', async ( { page } ) => {
	await page.goto( `/wp-admin/admin.php?page=a11yfy-document&id=${ attachmentId }` );

	await expect( page.locator( 'h1.a11yfy-doc__title' ) ).toContainText( '.pdf' );

	// Live analysis: the summary fills in when the engine worker finishes.
	await expect( page.locator( '#a11yfy-doc-summary .a11yfy-doc__score' ) ).toBeVisible( { timeout: 45_000 } );

	// The fixture is a wild, non-compliant PDF — findings must show up,
	// grouped under user-facing category sections.
	await expect( page.locator( '.a11yfy-doc__category' ).first() ).toBeVisible();
	await expect( page.locator( '.a11yfy-issue' ).first() ).toBeVisible();

	// Viewer: pdf.js rendered page 1 onto the canvas.
	await expect( page.locator( '#a11yfy-doc-pageinfo' ) ).toContainText( /1/, { timeout: 45_000 } );
	const canvasWidth = await page.locator( '#a11yfy-doc-canvas' ).evaluate( ( c ) => c.width );
	expect( canvasWidth ).toBeGreaterThan( 0 );

	// Overlay: the fixture is partially tagged — the deep 01-005 (untagged
	// content) findings must appear as boxes on page 1.
	await expect( page.locator( '.a11yfy-doc__box' ).first() ).toBeVisible( { timeout: 15_000 } );

	// Manual checklist is part of the report.
	await expect( page.locator( '.a11yfy-doc__checklist input[type=checkbox]' ) ).toHaveCount( 6 );
} );

test( 'dashboard Details action links to the document page', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=a11yfy' );
	const link = page.locator( '#a11yfy-pdfs-table a', { hasText: 'Details' } ).first();
	await expect( link ).toBeVisible( { timeout: 15_000 } );
	await expect( link ).toHaveAttribute( 'href', /page=a11yfy-document&id=\d+/ );
} );

test( 'invalid attachment id shows the styled error state', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=a11yfy-document&id=999999' );
	await expect( page.locator( '.a11yfy-card h1' ) ).toContainText( 'Document not found' );
} );
