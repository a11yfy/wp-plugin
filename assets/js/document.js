/**
 * Per-document detail page orchestrator: live client-side re-analysis (engine
 * Web Worker) + pdf.js canvas preview (a11yfy-viewer bundle, main thread).
 *
 * The findings panel is the authoritative, accessible report; the preview is
 * a visual aid (canvas is aria-hidden). No PDF byte ever leaves the site.
 */
/* global a11yfyDoc, A11yfyViewer */
( function () {
	'use strict';

	if ( typeof a11yfyDoc === 'undefined' || typeof A11yfyViewer === 'undefined' ) {
		return;
	}

	// ── Generic helpers (same idioms as admin.js) ───────────────────────────

	function sprintf( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return template.replace( /%(\d+\$)?[ds]/g, function ( match, pos ) {
			var index = pos ? parseInt( pos, 10 ) - 1 : i++;
			return String( args[ index ] );
		} ).replace( /%%/g, '%' );
	}

	function post( action, fields ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', a11yfyDoc.nonce );
		Object.keys( fields || {} ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );
		return fetch( a11yfyDoc.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) { return response.json(); } );
	}

	// Shared helper (hash.js): SubtleCrypto with a pure-JS fallback for
	// insecure (plain-HTTP) admin origins where crypto.subtle is undefined.
	var sha256hex = window.a11yfyHash.sha256hex;

	// Which file the page inspects: the current (remediated) file, or the
	// pre-remediation backup streamed by the a11yfy_fetch_backup endpoint.
	var source = 'current';

	async function fetchBackup() {
		var response = await fetch(
			a11yfyDoc.ajaxUrl + '?' + new URLSearchParams( {
				action: 'a11yfy_fetch_backup',
				nonce: a11yfyDoc.nonce,
				id: a11yfyDoc.attachment.id,
			} ).toString(),
			{ credentials: 'same-origin', cache: 'no-store' }
		);
		if ( ! response.ok ) {
			throw new Error( 'backup fetch failed: ' + response.status );
		}
		return await response.arrayBuffer();
	}

	/** Direct same-origin fetch first, admin-ajax proxy as CORS fallback. */
	async function fetchPdf() {
		try {
			var response = await fetch( a11yfyDoc.attachment.url, { credentials: 'same-origin', cache: 'no-store' } );
			if ( response.ok ) {
				return await response.arrayBuffer();
			}
		} catch ( e ) {
			// Cross-origin/CDN — fall through to the proxy.
		}
		var proxied = await fetch(
			a11yfyDoc.ajaxUrl + '?' + new URLSearchParams( {
				action: 'a11yfy_fetch_pdf',
				nonce: a11yfyDoc.nonce,
				id: a11yfyDoc.attachment.id,
			} ).toString(),
			{ credentials: 'same-origin', cache: 'no-store' }
		);
		if ( ! proxied.ok ) {
			throw new Error( 'fetch failed: ' + proxied.status );
		}
		return await proxied.arrayBuffer();
	}

	var worker = null;
	var workerSeq = 0;
	var pendingJobs = {};

	function analyzeInWorker( buffer ) {
		if ( ! worker ) {
			worker = new Worker( a11yfyDoc.workerUrl );
			worker.onmessage = function ( event ) {
				var msg = event.data || {};
				var entry = pendingJobs[ msg.id ];
				if ( ! entry ) {
					return;
				}
				delete pendingJobs[ msg.id ];
				if ( msg.error ) {
					entry.reject( new Error( msg.error ) );
				} else {
					entry.resolve( msg.report );
				}
			};
		}
		return new Promise( function ( resolve, reject ) {
			var id = ++workerSeq;
			pendingJobs[ id ] = { resolve: resolve, reject: reject };
			worker.postMessage( { id: id, buffer: buffer }, [ buffer ] );
		} );
	}

	// ── Findings model ──────────────────────────────────────────────────────

	var SEVERITY_RANK = { critical: 0, major: 1, minor: 2 };

	function categoryOf( check ) {
		var map = a11yfyDoc.categoryMap || { groups: {}, ids: {} };
		return map.ids[ check.id ] || map.groups[ check.group ] || 'other';
	}

	/**
	 * @param {object} report Engine report.
	 * @return {{findings: object[], docLevel: object[], byPage: Object<number,number>,
	 *           boxesByPage: Object<number,object[]>, passed: number}}
	 */
	function buildModel( report ) {
		var findings = [];
		var docLevel = [];
		var byPage = {};
		var boxesByPage = {};
		var passed = 0;

		( report.checks || [] ).forEach( function ( check ) {
			if ( 'pass' === check.status ) {
				passed++;
			}
			if ( 'fail' !== check.status ) {
				return;
			}
			var entry = a11yfyDoc.catalog[ check.id ] || {};
			var severity = SEVERITY_RANK.hasOwnProperty( entry.severity_label ) ? entry.severity_label : 'major';
			var pages = [];
			check.items.forEach( function ( it ) {
				if ( ! it.page ) {
					return;
				}
				if ( pages.indexOf( it.page ) === -1 ) {
					pages.push( it.page );
				}
				byPage[ it.page ] = ( byPage[ it.page ] || 0 ) + 1;
				( it.rects || [] ).forEach( function ( rect ) {
					( boxesByPage[ it.page ] = boxesByPage[ it.page ] || [] ).push( {
						rect: rect,
						severity: severity,
						findingId: 'a11yfy-finding-' + check.id,
						title: entry.title || check.id,
					} );
				} );
			} );
			pages.sort( function ( a, b ) { return a - b; } );

			var finding = {
				id: check.id,
				category: categoryOf( check ),
				severity: severity,
				severityLabel: a11yfyDoc.severityLabels[ severity ] || severity,
				title: entry.title || check.id,
				description: entry.description || '',
				fix: entry.suggested_fix || '',
				count: Math.max( 1, check.count || 0 ),
				pages: pages,
				items: check.items,
			};
			( pages.length ? findings : docLevel ).push( finding );
		} );

		var bySeverity = function ( a, b ) {
			return ( SEVERITY_RANK[ a.severity ] - SEVERITY_RANK[ b.severity ] ) || ( b.count - a.count );
		};
		findings.sort( bySeverity );
		docLevel.sort( bySeverity );

		return { findings: findings, docLevel: docLevel, byPage: byPage, boxesByPage: boxesByPage, passed: passed };
	}

	// ── Findings rendering ──────────────────────────────────────────────────

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( undefined !== text ) {
			node.textContent = text;
		}
		return node;
	}

	function renderFinding( finding ) {
		var div = el( 'div', 'a11yfy-issue a11yfy-issue--' + finding.severity );
		div.id = 'a11yfy-finding-' + finding.id;

		var head = el( 'p', 'a11yfy-issue__head' );
		head.appendChild( el( 'span', 'a11yfy-issue__sev', finding.severityLabel ) );
		head.appendChild( el( 'strong', '', ' ' + finding.title + ' ' ) );
		head.appendChild( el( 'span', 'a11yfy-issue__count', sprintf( a11yfyDoc.i18n.issueCount, finding.count ) ) );
		div.appendChild( head );

		if ( finding.description ) {
			div.appendChild( el( 'p', 'a11yfy-issue__desc', finding.description ) );
		}
		if ( finding.fix ) {
			div.appendChild( el( 'p', 'a11yfy-issue__fix', '💡 ' + finding.fix ) );
		}

		if ( finding.pages.length ) {
			var pages = el( 'p', 'a11yfy-issue__pages' );
			finding.pages.forEach( function ( page ) {
				var chip = el( 'button', 'a11yfy-page-chip', sprintf( a11yfyDoc.i18n.pageChip, page ) );
				chip.type = 'button';
				chip.setAttribute( 'aria-label', sprintf( a11yfyDoc.i18n.goToPage, page ) );
				chip.addEventListener( 'click', function () {
					goToPage( page );
				} );
				pages.appendChild( chip );
			} );
			div.appendChild( pages );
		}

		// Raw engine item details — the professional/technical layer, collapsed.
		if ( finding.items.length ) {
			var details = el( 'details', 'a11yfy-issue__tech' );
			var summary = el( 'summary', '', a11yfyDoc.i18n.techDetails + ' (' + finding.id + ')' );
			details.appendChild( summary );
			var list = el( 'ul' );
			finding.items.forEach( function ( it ) {
				var li = el( 'li', '', ( it.page ? sprintf( a11yfyDoc.i18n.pageChip, it.page ) + ': ' : '' ) + it.detail );
				list.appendChild( li );
			} );
			details.appendChild( list );
			div.appendChild( details );
		}

		return div;
	}

	function renderModel( report, model ) {
		var summary = document.getElementById( 'a11yfy-doc-summary' );
		summary.textContent = '';
		var scoreLine = el( 'p', 'a11yfy-doc__score' );
		var badge = el( 'span', 'a11yfy-badge a11yfy-doc__risk--' + report.risk, a11yfyDoc.i18n.riskLabels[ report.risk ] || report.risk );
		scoreLine.appendChild( badge );
		scoreLine.appendChild( document.createTextNode( ' ' + sprintf( a11yfyDoc.i18n.score, report.score ) ) );
		summary.appendChild( scoreLine );
		var total = model.findings.length + model.docLevel.length;
		summary.appendChild( el( 'p', 'a11yfy-details__summary', total
			? sprintf( a11yfyDoc.i18n.summary, total, model.passed )
			: a11yfyDoc.i18n.noIssues ) );

		// Tag-coverage headline: the single most telling number of the report.
		if ( report.coverage && report.coverage.totalChars > 0 && report.coverage.untaggedChars > 0 ) {
			var pct = Math.min( 100, Math.round( ( report.coverage.untaggedChars / report.coverage.totalChars ) * 100 ) );
			summary.appendChild( el( 'p', 'a11yfy-doc__coverage', sprintf( a11yfyDoc.i18n.coverage, pct ) ) );
		}

		var docLevelBox = document.getElementById( 'a11yfy-doc-doclevel' );
		docLevelBox.textContent = '';
		if ( model.docLevel.length ) {
			var section = el( 'section', 'a11yfy-doc__category' );
			section.appendChild( el( 'h2', 'a11yfy-doc__category-title', a11yfyDoc.i18n.docLevel + ' (' + model.docLevel.length + ')' ) );
			model.docLevel.forEach( function ( finding ) {
				section.appendChild( renderFinding( finding ) );
			} );
			docLevelBox.appendChild( section );
		}

		var findingsBox = document.getElementById( 'a11yfy-doc-findings' );
		findingsBox.textContent = '';
		Object.keys( a11yfyDoc.categories ).forEach( function ( slug ) {
			var inCategory = model.findings.filter( function ( f ) { return f.category === slug; } );
			if ( ! inCategory.length ) {
				return;
			}
			var section = el( 'section', 'a11yfy-doc__category' );
			section.appendChild( el( 'h2', 'a11yfy-doc__category-title', a11yfyDoc.categories[ slug ] + ' (' + inCategory.length + ')' ) );
			inCategory.forEach( function ( finding ) {
				section.appendChild( renderFinding( finding ) );
			} );
			findingsBox.appendChild( section );
		} );

		var pageCount = document.getElementById( 'a11yfy-doc-pagecount' );
		if ( pageCount && report.pages ) {
			pageCount.textContent = sprintf( a11yfyDoc.i18n.pages, report.pages );
		}
	}

	// ── Viewer ──────────────────────────────────────────────────────────────

	var view = {
		pdf: null,
		page: 1,
		zoom: 1,
		renderTask: null,
		byPage: {},
		boxesByPage: {},
	};

	/**
	 * Draw the finding boxes for the current page. cssViewport is the pdf.js
	 * viewport in CSS pixels (no devicePixelRatio) — user-space rects map to
	 * screen with convertToViewportRectangle. The boxes are aria-hidden visual
	 * aids; clicking one jumps to its finding card in the accessible list.
	 */
	function renderOverlay( cssViewport ) {
		var overlay = document.getElementById( 'a11yfy-doc-overlay' );
		overlay.textContent = '';
		overlay.style.width = Math.floor( cssViewport.width ) + 'px';
		overlay.style.height = Math.floor( cssViewport.height ) + 'px';
		( view.boxesByPage[ view.page ] || [] ).forEach( function ( box ) {
			var v = cssViewport.convertToViewportRectangle( box.rect );
			var left = Math.min( v[ 0 ], v[ 2 ] );
			var top = Math.min( v[ 1 ], v[ 3 ] );
			var node = el( 'div', 'a11yfy-doc__box a11yfy-doc__box--' + box.severity );
			node.style.left = left + 'px';
			node.style.top = top + 'px';
			node.style.width = Math.max( 4, Math.abs( v[ 2 ] - v[ 0 ] ) ) + 'px';
			node.style.height = Math.max( 4, Math.abs( v[ 3 ] - v[ 1 ] ) ) + 'px';
			node.title = box.title;
			node.addEventListener( 'click', function () {
				var card = document.getElementById( box.findingId );
				if ( ! card ) {
					return;
				}
				card.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				card.classList.remove( 'is-flash' );
				void card.offsetWidth; // restart the CSS animation
				card.classList.add( 'is-flash' );
			} );
			overlay.appendChild( node );
		} );
	}

	function updateToolbar() {
		var info = document.getElementById( 'a11yfy-doc-pageinfo' );
		if ( view.pdf ) {
			info.textContent = sprintf( a11yfyDoc.i18n.pageInfo, view.page, view.pdf.numPages );
		}
		document.getElementById( 'a11yfy-doc-prev' ).disabled = view.page <= 1;
		document.getElementById( 'a11yfy-doc-next' ).disabled = ! view.pdf || view.page >= view.pdf.numPages;
		var findingsEl = document.getElementById( 'a11yfy-doc-pagefindings' );
		var n = view.byPage[ view.page ] || 0;
		findingsEl.textContent = n
			? ( 1 === n ? a11yfyDoc.i18n.pageFindingsOne : sprintf( a11yfyDoc.i18n.pageFindings, n ) )
			: '';
	}

	async function renderPage() {
		if ( ! view.pdf ) {
			return;
		}
		var canvas = document.getElementById( 'a11yfy-doc-canvas' );
		var wrap = document.getElementById( 'a11yfy-doc-canvas-wrap' );
		try {
			var page = await view.pdf.getPage( view.page );
			var base = page.getViewport( { scale: 1 } );
			var fit = Math.max( 0.1, ( wrap.clientWidth - 26 ) / base.width );
			var scale = fit * view.zoom;
			// Render at device-pixel resolution for crisp text.
			var dpr = window.devicePixelRatio || 1;
			var viewport = page.getViewport( { scale: scale * dpr } );
			var cssViewport = page.getViewport( { scale: scale } );
			canvas.width = Math.floor( viewport.width );
			canvas.height = Math.floor( viewport.height );
			canvas.style.width = Math.floor( viewport.width / dpr ) + 'px';
			canvas.style.height = Math.floor( viewport.height / dpr ) + 'px';
			if ( view.renderTask ) {
				view.renderTask.cancel();
				// The cancelled task's promise rejects — swallow it, nobody awaits it.
				view.renderTask.promise.catch( function () {} );
			}
			view.renderTask = page.render( { canvasContext: canvas.getContext( '2d' ), viewport: viewport } );
			await view.renderTask.promise;
			view.renderTask = null;
			renderOverlay( cssViewport );
		} catch ( err ) {
			if ( err && 'RenderingCancelledException' === err.name ) {
				return; // A newer render superseded this one.
			}
			throw err;
		}
		updateToolbar();
	}

	function goToPage( page ) {
		if ( ! view.pdf ) {
			return;
		}
		view.page = Math.min( Math.max( 1, page ), view.pdf.numPages );
		updateToolbar();
		renderPage().catch( function () {} );
		document.getElementById( 'a11yfy-doc-canvas-wrap' ).scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	async function loadPreview( buffer ) {
		try {
			view.pdf = await A11yfyViewer.load( new Uint8Array( buffer ) );
			view.page = 1;
			await renderPage();
			updateToolbar();
		} catch ( err ) {
			var note = document.querySelector( '.a11yfy-doc__viewer-note' );
			if ( note ) {
				note.textContent = a11yfyDoc.i18n.previewFailed;
			}
			window.console && console.warn( 'a11yfy preview failed', err );
		}
	}

	// ── Scan flow ───────────────────────────────────────────────────────────

	/**
	 * Unsendable document (password/XFA/portfolio): the free scan already
	 * knows — disable the Fix button with the reason before any server
	 * round-trip could be attempted. Re-enables after a fixed re-upload.
	 *
	 * Precedence (2026-07-23): an already-accessible document comes FIRST —
	 * it gets no fix affordance and no blocker notice at all. A digitally
	 * signed (non-compliant) document keeps an ACTIVE Fix button; the click
	 * asks for confirmation instead (the signature becomes invalid).
	 */
	/**
	 * Credit estimate next to the Fix button — the same formula as the web
	 * app's estimateCreditRange(): born-digital + tagged → pages×1…×3,
	 * everything else pages×3. The binding quote is computed server-side at
	 * diagnostic time; this is orientation only.
	 */
	function toggleEstimate( report, visible ) {
		var node = document.getElementById( 'a11yfy-doc-estimate' );
		if ( ! node ) {
			return;
		}
		var pages = report.pages || 0;
		if ( ! visible || ! pages ) {
			node.hidden = true;
			return;
		}
		var min = ( ! report.scannedLikely && report.tagged ) ? pages : pages * 3;
		var max = pages * 3;
		node.textContent = min === max
			? sprintf( a11yfyDoc.i18n.estimateOne, max )
			: sprintf( a11yfyDoc.i18n.estimateRange, min, max );
		node.hidden = false;
	}

	function applyBlockers( report ) {
		var fixBtn = document.getElementById( 'a11yfy-doc-fix' );
		var notice = document.getElementById( 'a11yfy-doc-blocked' );

		// Mirror of the server-side `compliant` gate in save_scan(): full
		// score + tagged + no errored (= not actually run) checks.
		var hasError = ( report.checks || [] ).some( function ( c ) {
			return c && 'error' === c.status;
		} );
		var isCompliant = 100 === report.score && !! report.tagged && ! hasError;

		var code = [ 'encrypted', 'signed', 'xfa', 'portfolio' ].filter( function ( c ) {
			return !! report[ c ];
		} )[ 0 ];

		if ( isCompliant ) {
			if ( notice ) {
				notice.hidden = true;
			}
			if ( fixBtn ) {
				fixBtn.hidden = true;
			}
			toggleEstimate( report, false );
			return;
		}

		if ( ! code ) {
			if ( notice ) {
				notice.hidden = true;
			}
			if ( fixBtn ) {
				fixBtn.hidden = false;
				fixBtn.removeAttribute( 'data-signed' );
				if ( fixBtn.hasAttribute( 'data-a11yfy-blocked' ) ) {
					fixBtn.removeAttribute( 'data-a11yfy-blocked' );
					fixBtn.removeAttribute( 'title' );
					fixBtn.disabled = false;
				}
			}
			toggleEstimate( report, true );
			return;
		}

		var msg = ( a11yfyDoc.blockedMessages && a11yfyDoc.blockedMessages[ code ] ) || code;
		if ( fixBtn ) {
			fixBtn.hidden = false;
			if ( 'signed' === code ) {
				// Stays clickable — the click handler asks for confirmation.
				fixBtn.disabled = false;
				fixBtn.title = msg;
				fixBtn.setAttribute( 'data-signed', '1' );
				fixBtn.removeAttribute( 'data-a11yfy-blocked' );
			} else {
				fixBtn.disabled = true;
				fixBtn.title = msg;
				fixBtn.setAttribute( 'data-a11yfy-blocked', code );
			}
		}
		if ( notice ) {
			notice.textContent = '⚠ ' + msg;
			notice.hidden = false;
		}
		// Signed keeps the active Fix button → the estimate stays relevant;
		// password/XFA/portfolio cannot be sent at all → hide it.
		toggleEstimate( report, 'signed' === code );
	}

	var running = false;

	async function run() {
		if ( running ) {
			return;
		}
		running = true;
		var progress = document.getElementById( 'a11yfy-doc-progress' );
		var rescan = document.getElementById( 'a11yfy-doc-rescan' );
		// Disable everything that would race the run (incl. the source toggle
		// — a mid-run switch would render mismatched findings and preview).
		var lockButtons = [ rescan,
			document.getElementById( 'a11yfy-doc-src-current' ),
			document.getElementById( 'a11yfy-doc-src-original' ) ].filter( Boolean );
		progress.textContent = a11yfyDoc.i18n.loading;
		lockButtons.forEach( function ( b ) { b.disabled = true; } );

		try {
			var bytes = 'original' === source ? await fetchBackup() : await fetchPdf();
			// Preview gets its own copy — the analysis buffer is transferred to
			// the worker (detached), and pdf.js also takes ownership of its copy.
			loadPreview( bytes.slice( 0 ) );

			var hash = await sha256hex( bytes );
			var report = await analyzeInWorker( bytes );
			report.fileHash = hash;

			var model = buildModel( report );
			view.byPage = model.byPage;
			view.boxesByPage = model.boxesByPage;
			renderModel( report, model );
			if ( 'current' === source ) {
				applyBlockers( report );
			}
			renderPage().catch( function () {} ); // refresh the overlay with the new boxes
			updateToolbar();
			progress.textContent = 'original' === source ? a11yfyDoc.i18n.originalNote : '';

			// Keep the stored verdict fresh (same contract as the dashboard
			// batch scan); non-fatal if the server rejects it. The backup's
			// verdict is never saved — it would not match the file on disk.
			if ( 'current' === source ) {
				post( 'a11yfy_save_scan', {
					id: a11yfyDoc.attachment.id,
					report: JSON.stringify( report ),
				} ).then( function ( saved ) {
					// The stored verdict changed — refresh the server-rendered
					// header badge so it matches the fresh analysis.
					var badge = document.getElementById( 'a11yfy-doc-badge' );
					if ( badge && saved && saved.success && saved.data && saved.data.badge ) {
						badge.innerHTML = saved.data.badge;
					}
				} ).catch( function () {} );
			}
		} catch ( err ) {
			progress.textContent = a11yfyDoc.i18n.analyzeFailed;
			window.console && console.warn( 'a11yfy analyze failed', err );
		} finally {
			lockButtons.forEach( function ( b ) { b.disabled = false; } );
			running = false;
		}
	}

	// ── Wiring ──────────────────────────────────────────────────────────────

	document.addEventListener( 'DOMContentLoaded', function () {
		document.getElementById( 'a11yfy-doc-prev' ).addEventListener( 'click', function () {
			goToPage( view.page - 1 );
		} );
		document.getElementById( 'a11yfy-doc-next' ).addEventListener( 'click', function () {
			goToPage( view.page + 1 );
		} );
		document.getElementById( 'a11yfy-doc-zoomin' ).addEventListener( 'click', function () {
			view.zoom = Math.min( 4, view.zoom * 1.25 );
			renderPage().catch( function () {} );
		} );
		document.getElementById( 'a11yfy-doc-zoomout' ).addEventListener( 'click', function () {
			view.zoom = Math.max( 0.5, view.zoom / 1.25 );
			renderPage().catch( function () {} );
		} );
		document.getElementById( 'a11yfy-doc-canvas-wrap' ).addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowLeft' === event.key ) {
				event.preventDefault();
				goToPage( view.page - 1 );
			} else if ( 'ArrowRight' === event.key ) {
				event.preventDefault();
				goToPage( view.page + 1 );
			}
		} );

		var resizeTimer = null;
		window.addEventListener( 'resize', function () {
			window.clearTimeout( resizeTimer );
			resizeTimer = window.setTimeout( function () {
				renderPage().catch( function () {} );
			}, 200 );
		} );

		document.getElementById( 'a11yfy-doc-rescan' ).addEventListener( 'click', run );

		// Remediated file: toggle between the current file and the backup.
		var srcCurrent = document.getElementById( 'a11yfy-doc-src-current' );
		var srcOriginal = document.getElementById( 'a11yfy-doc-src-original' );
		function setSource( next ) {
			if ( running || next === source ) {
				return;
			}
			source = next;
			[ [ srcCurrent, 'current' ], [ srcOriginal, 'original' ] ].forEach( function ( pair ) {
				pair[ 0 ].classList.toggle( 'is-active', source === pair[ 1 ] );
				pair[ 0 ].setAttribute( 'aria-pressed', source === pair[ 1 ] ? 'true' : 'false' );
			} );
			run();
		}
		if ( srcCurrent && srcOriginal ) {
			srcCurrent.addEventListener( 'click', function () { setSource( 'current' ); } );
			srcOriginal.addEventListener( 'click', function () { setSource( 'original' ); } );
		}

		var printBtn = document.getElementById( 'a11yfy-doc-print' );
		printBtn.addEventListener( 'click', function () {
			// Expand the technical sections so the printed report is complete.
			Array.prototype.forEach.call( document.querySelectorAll( '.a11yfy-issue__tech' ), function ( d ) {
				d.open = true;
			} );
			window.print();
		} );

		var fixBtn = document.getElementById( 'a11yfy-doc-fix' );
		if ( fixBtn ) {
			fixBtn.addEventListener( 'click', function () {
				// Digitally signed PDF: explicit confirmation before the
				// signature is invalidated by remediation.
				var confirmSigned = '1' === fixBtn.getAttribute( 'data-signed' );
				if ( confirmSigned && ! window.confirm( a11yfyDoc.i18n.signedConfirm ) ) {
					return;
				}
				var fields = { id: fixBtn.getAttribute( 'data-id' ) };
				if ( confirmSigned ) {
					fields.confirm_signed = '1';
				}
				fixBtn.disabled = true;
				post( 'a11yfy_remediate', fields ).then( function ( response ) {
					if ( response && response.success ) {
						fixBtn.textContent = '✓ ' + a11yfyDoc.i18n.queued;
					} else {
						fixBtn.disabled = false;
						window.alert( ( response && response.data && response.data.message ) || a11yfyDoc.i18n.error );
					}
				} ).catch( function () {
					fixBtn.disabled = false;
					window.alert( a11yfyDoc.i18n.error );
				} );
			} );
		}

		run();
	} );
} )();
