/**
 * Látogatói on-demand mód e2e (feature spec 2026-08-03).
 *
 * A wp-env tests site ellen fut (npm run test:e2e). A fixture-állapotot a
 * tests/e2e/fixtures/visitor-e2e.php dispatcher építi wp-cli-vel: connected
 * állapot (dummy kulcs — hálózatra nem megy ki), on_demand mód, 2 nem
 * megfelelő + 1 megfelelő PDF, publikus oldal a linkekkel. A kredit-soft-gate
 * a balance transient-tel vezérelhető, így a SaaS API-t semmi nem hívja.
 */
const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'node:child_process' );
const path = require( 'node:path' );

const PLUGIN_ROOT = path.resolve( __dirname, '..', '..' );
const FIXTURE = 'wp-content/plugins/wp-plugin/tests/e2e/fixtures/visitor-e2e.php';

/** wp-cli a tests konténerben; a stdout wp-env-zaját leszűrve. */
function cli( args ) {
	const out = execSync( `npx wp-env run tests-cli wp eval-file ${ FIXTURE } ${ args }`, {
		cwd: PLUGIN_ROOT,
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
	return out;
}

function cliJson( args ) {
	const match = cli( args ).match( /(\{.*\}|\[.*\])/s );
	if ( ! match ) {
		throw new Error( 'No JSON in wp-cli output' );
	}
	return JSON.parse( match[ 1 ] );
}

let fx; // { page_url, bad_a: {id,url}, bad_b: {id,url}, ok: {id,url} }

test.beforeAll( () => {
	fx = cliJson( 'setup' );
} );

test.beforeEach( () => {
	cli( 'purge-requests' );
} );

/** Megnyitja a fixture-oldalt és megvárja a batch státusz-annotációt. */
async function openVisitorPage( page, expectStatus = 'not_accessible', href = null ) {
	await page.goto( fx.page_url );
	const link = page.locator( `a[href="${ href || fx.bad_a.url }"]` );
	await expect( link ).toHaveAttribute( 'data-a11yfy-status', expectStatus );
	return link;
}

test( 'clicking a non-accessible PDF opens the accessible modal instead of navigating', async ( { page } ) => {
	const link = await openVisitorPage( page );
	await link.click();

	const dialog = page.getByRole( 'dialog' );
	await expect( dialog ).toBeVisible();
	await expect( page ).toHaveURL( fx.page_url ); // preventDefault worked
	// Default texts + the two CTAs, primary first.
	await expect( dialog.locator( '.a11yfy-modal__title' ) ).toContainText( /not yet accessible/i );
	await expect( dialog.locator( '.a11yfy-modal__btn--primary' ) ).toContainText( /Open document/i );
	await expect( dialog.locator( '.a11yfy-modal__btn--secondary' ) ).toContainText( /Request accessible version/i );
	// Focus lands inside the dialog (title, tabindex=-1).
	await expect( dialog.locator( '.a11yfy-modal__title' ) ).toBeFocused();
} );

test( 'Escape closes the modal and returns focus to the triggering link', async ( { page } ) => {
	const link = await openVisitorPage( page );
	await link.click();
	await expect( page.getByRole( 'dialog' ) ).toBeVisible();

	await page.keyboard.press( 'Escape' );
	await expect( page.getByRole( 'dialog' ) ).toBeHidden();
	await expect( link ).toBeFocused();
} );

test( '"Open document" navigates to the PDF and suppresses the modal for the session', async ( { page } ) => {
	// Headless Chromium downloads PDFs instead of rendering them — a download
	// event IS the successful navigation.
	const link = await openVisitorPage( page );
	await link.click();
	const dlPromise = page.waitForEvent( 'download' );
	await page.locator( '.a11yfy-modal__btn--primary' ).click();
	expect( ( await dlPromise ).url() ).toContain( 'e2e-visitor-bad-a.pdf' );

	// The same link now navigates directly (sessionStorage dismissal) — no modal.
	const dl2Promise = page.waitForEvent( 'download' );
	await page.locator( `a[href="${ fx.bad_a.url }"]` ).click();
	expect( ( await dl2Promise ).url() ).toContain( 'e2e-visitor-bad-a.pdf' );
	await expect( page.getByRole( 'dialog' ) ).toBeHidden();
} );

test( 'requesting an accessible version with enough credits queues the job', async ( { page } ) => {
	cli( 'balance 1000' );
	const link = await openVisitorPage( page );
	await link.click();

	await page.locator( '.a11yfy-modal__btn--secondary' ).click();
	// Second step appears in the aria-live region with the info text + email form.
	await expect( page.locator( '.a11yfy-modal__info' ) ).toContainText( /email you as soon as/i );
	await page.fill( '#a11yfy-visitor-email', 'visitor@example.com' );
	await page.locator( '.a11yfy-modal__form .a11yfy-modal__btn--primary' ).click();

	await expect( page.locator( '.a11yfy-modal__success' ) ).toContainText( /notify you by email/i );

	// DB state right after the response — the subscriber row is queued.
	const rows = cliJson( `requests ${ fx.bad_a.id }` );
	expect( rows ).toHaveLength( 1 );
	expect( rows[ 0 ].email ).toBe( 'visitor@example.com' );
	expect( rows[ 0 ].status ).toBe( 'queued' );
} );

test( 'without enough credits the request is parked as pending_credit', async ( { page } ) => {
	cli( 'balance 1' );
	const link = await openVisitorPage( page, 'not_accessible', fx.bad_b.url );
	await link.click();

	await page.locator( '.a11yfy-modal__btn--secondary' ).click();
	await page.fill( '#a11yfy-visitor-email', 'parked@example.com' );
	await page.locator( '.a11yfy-modal__form .a11yfy-modal__btn--primary' ).click();
	await expect( page.locator( '.a11yfy-modal__success' ) ).toBeVisible();

	const rows = cliJson( `requests ${ fx.bad_b.id }` );
	expect( rows ).toHaveLength( 1 );
	expect( rows[ 0 ].status ).toBe( 'pending_credit' );
} );

test( 'invalid email is rejected inline, then a valid one succeeds', async ( { page } ) => {
	cli( 'balance 1000' );
	const link = await openVisitorPage( page );
	await link.click();
	await page.locator( '.a11yfy-modal__btn--secondary' ).click();

	// type=email + required: the browser's native (localized) validation
	// blocks the submit — the JS regex is only a backstop for old engines.
	await page.fill( '#a11yfy-visitor-email', 'not-an-email' );
	await page.locator( '.a11yfy-modal__form .a11yfy-modal__btn--primary' ).click();
	await expect( page.locator( '#a11yfy-visitor-email' ) ).toHaveJSProperty( 'validity.valid', false );
	await expect( page.locator( '.a11yfy-modal__success' ) ).toBeHidden();

	await page.fill( '#a11yfy-visitor-email', 'second-try@example.com' );
	await page.locator( '.a11yfy-modal__form .a11yfy-modal__btn--primary' ).click();
	await expect( page.locator( '.a11yfy-modal__success' ) ).toBeVisible();
} );

test( 'an accessible PDF navigates directly — no modal', async ( { page } ) => {
	const link = await openVisitorPage( page, 'accessible', fx.ok.url );
	const dlPromise = page.waitForEvent( 'download' );
	await link.click();
	expect( ( await dlPromise ).url() ).toContain( 'e2e-visitor-ok.pdf' );
	await expect( page.getByRole( 'dialog' ) ).toBeHidden();
} );

test( 'after remediation the same link no longer triggers the modal', async ( { page } ) => {
	cli( `mark-remediated ${ fx.bad_b.id }` );
	const link = await openVisitorPage( page, 'accessible', fx.bad_b.url );
	const dlPromise = page.waitForEvent( 'download' );
	await link.click();
	expect( ( await dlPromise ).url() ).toContain( 'e2e-visitor-bad-b.pdf' );
	await expect( page.getByRole( 'dialog' ) ).toBeHidden();
} );

test( 'customized modal title shows up for visitors', async ( { page } ) => {
	cli( 'custom-texts "Egyedi e2e cím"' );
	try {
		const link = await openVisitorPage( page );
		await link.click();
		await expect( page.locator( '.a11yfy-modal__title' ) ).toHaveText( 'Egyedi e2e cím' );
	} finally {
		cli( 'custom-texts ""' );
	}
} );

test( 'REST: pdf-status reports statuses, honeypot request stores nothing', async ( { request, baseURL } ) => {
	const statusRes = await request.post( `${ baseURL }/index.php?rest_route=/a11yfy/v1/pdf-status`, {
		data: { urls: [ fx.bad_a.url, fx.ok.url, 'https://evil.example/x.pdf' ] },
	} );
	expect( statusRes.status() ).toBe( 200 );
	const { statuses } = await statusRes.json();
	expect( statuses[ fx.bad_a.url ].status ).toBe( 'not_accessible' );
	expect( statuses[ fx.ok.url ].status ).toBe( 'accessible' );
	expect( statuses[ 'https://evil.example/x.pdf' ].status ).toBe( 'unknown' );

	// Honeypot: pretends success, must not create a subscriber row.
	const hpRes = await request.post( `${ baseURL }/index.php?rest_route=/a11yfy/v1/request-remediation`, {
		data: { url: fx.bad_a.url, email: 'bot@example.com', hp: 'http://spam' },
	} );
	expect( hpRes.status() ).toBe( 200 );
	expect( cliJson( `requests ${ fx.bad_a.id }` ) ).toHaveLength( 0 );
} );

test( 'admin: settings page shows the on_demand mode radio and the visitor text fields', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', process.env.WP_ADMIN_USER || 'admin' );
	await page.fill( '#user_pass', process.env.WP_ADMIN_PASS || 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );

	await page.goto( '/wp-admin/admin.php?page=a11yfy-settings' );
	await expect( page.locator( 'input[name="a11yfy_mode"][value="on_demand"]' ) ).toBeChecked();
	await expect( page.locator( '#a11yfy_visitor_modal_title' ) ).toBeVisible();
	await expect( page.locator( '#a11yfy_visitor_email_body' ) ).toBeVisible();
	// The placeholder carries the localized default text.
	await expect( page.locator( '#a11yfy_visitor_modal_title' ) ).toHaveAttribute( 'placeholder', /not yet accessible/i );
} );
