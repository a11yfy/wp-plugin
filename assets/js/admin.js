/**
 * a11yfy admin orchestrator: batch client-side scanning via the engine worker,
 * dashboard job list refresh, media row actions.
 *
 * The heavy engine (pdf.js + pdf-lib) lives in assets/js/dist/ and runs inside
 * a dedicated Web Worker — the admin UI never blocks (§13.4).
 */
/* global a11yfyAdmin */
( function () {
	'use strict';

	if ( typeof a11yfyAdmin === 'undefined' ) {
		return;
	}

	var worker = null;
	var workerSeq = 0;
	var pending = {};

	// Two user-facing remediation types only (technical | full); legacy rows
	// may still hold raw pipeline values (in-place | rebuild | overlay).
	function treatmentLabel( treatment ) {
		if ( ! treatment || 'noop' === treatment ) {
			return '';
		}
		if ( 'technical' === treatment || 'in-place' === treatment ) {
			return a11yfyAdmin.i18n.treatmentTechnical;
		}
		return a11yfyAdmin.i18n.treatmentFull;
	}

	function getWorker() {
		if ( ! worker ) {
			worker = new Worker( a11yfyAdmin.workerUrl );
			worker.onmessage = function ( event ) {
				var msg = event.data || {};
				var entry = pending[ msg.id ];
				if ( ! entry ) {
					return;
				}
				delete pending[ msg.id ];
				if ( msg.error ) {
					entry.reject( new Error( msg.error ) );
				} else {
					entry.resolve( msg.report );
				}
			};
		}
		return worker;
	}

	function analyzeInWorker( buffer ) {
		return new Promise( function ( resolve, reject ) {
			var id = ++workerSeq;
			pending[ id ] = { resolve: resolve, reject: reject };
			getWorker().postMessage( { id: id, buffer: buffer }, [ buffer ] );
		} );
	}

	function sprintf( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return template.replace( /%(\d+\$)?[ds]/g, function ( match, pos ) {
			var index = pos ? parseInt( pos, 10 ) - 1 : i++;
			return String( args[ index ] );
		} );
	}

	function post( action, fields ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', a11yfyAdmin.nonce );
		Object.keys( fields || {} ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );
		return fetch( a11yfyAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) { return response.json(); } );
	}

	function getJSON( params ) {
		var url = a11yfyAdmin.ajaxUrl + '?' + new URLSearchParams(
			Object.assign( { nonce: a11yfyAdmin.nonce }, params )
		).toString();
		return fetch( url, { credentials: 'same-origin' } )
			.then( function ( response ) { return response.json(); } );
	}

	// Shared helper (hash.js): SubtleCrypto with a pure-JS fallback for
	// insecure (plain-HTTP) admin origins where crypto.subtle is undefined.
	var sha256hex = window.a11yfyHash.sha256hex;

	/**
	 * Fetch the PDF bytes: direct same-origin fetch first (zero PHP overhead),
	 * admin-ajax proxy as CORS fallback (§13.4).
	 */
	async function fetchPdf( item ) {
		try {
			// no-store: the HTTP cache may hold stale bytes (e.g. the file was
			// restored/replaced on disk) — the hash gate would reject those.
			var response = await fetch( item.url, { credentials: 'same-origin', cache: 'no-store' } );
			if ( response.ok ) {
				return await response.arrayBuffer();
			}
		} catch ( e ) {
			// Cross-origin/CDN — fall through to the proxy.
		}
		var proxied = await fetch(
			a11yfyAdmin.ajaxUrl + '?' + new URLSearchParams( {
				action: 'a11yfy_fetch_pdf',
				nonce: a11yfyAdmin.nonce,
				id: item.id,
			} ).toString(),
			{ credentials: 'same-origin', cache: 'no-store' }
		);
		if ( ! proxied.ok ) {
			throw new Error( 'fetch failed: ' + proxied.status );
		}
		return await proxied.arrayBuffer();
	}

	// ── Batch scan loop ─────────────────────────────────────────────────────

	var scanning = false;

	function setProgress( progressEl, text ) {
		if ( progressEl ) {
			progressEl.textContent = text;
		}
	}

	/**
	 * @param {Element|null} progressEl Status line (dashboard only).
	 * @param {boolean}      auto       Background run: no page reload at the
	 *                                  end, the PDF table refreshes in place.
	 */
	async function runScan( progressEl, auto ) {
		if ( scanning ) {
			return;
		}
		scanning = true;
		var done = 0;
		var failed = 0;
		// Loop guard: a file whose report the server rejects (e.g. hash gate)
		// stays "stale" and would be served in every batch — try each id once.
		var attempted = {};

		try {
			for ( ;; ) {
				var batchResponse = await getJSON( { action: 'a11yfy_scan_batch' } );
				var allItems = batchResponse && batchResponse.success ? batchResponse.data.items : [];
				var items = allItems.filter( function ( it ) { return ! attempted[ it.id ]; } );
				if ( ! items.length ) {
					break;
				}

				for ( var i = 0; i < items.length; i++ ) {
					var item = items[ i ];
					attempted[ item.id ] = true;
					setProgress( progressEl, sprintf( a11yfyAdmin.i18n.scanning, done + 1, done + items.length - i ) );
					try {
						var buffer = await fetchPdf( item );
						// Hash BEFORE transferring the buffer to the worker.
						var hash = await sha256hex( buffer );
						var report = await analyzeInWorker( buffer );
						report.fileHash = hash;
						var saved = await post( 'a11yfy_save_scan', {
							id: item.id,
							report: JSON.stringify( report ),
						} );
						if ( ! saved || ! saved.success ) {
							failed++;
						}
					} catch ( err ) {
						failed++;
						window.console && console.warn( sprintf( a11yfyAdmin.i18n.scanFailed, item.filename ), err );
						// Tell the server so a permanently failing file is parked
						// in the weekly retry window (two-strike, server-side)
						// instead of being re-scanned on every page load.
						post( 'a11yfy_scan_failed', { id: item.id, code: 'client' } ).catch( function () {} );
					}
					done++;
				}
			}

			if ( done || ! auto ) {
				setProgress(
					progressEl,
					done
						? sprintf( a11yfyAdmin.i18n.scanDone, done ) + ( failed ? ' (' + failed + '×✗)' : '' )
						: a11yfyAdmin.i18n.noPdfs
				);
			}
			if ( done && ! auto ) {
				window.setTimeout( function () { window.location.reload(); }, 1500 );
			}
			if ( done && auto ) {
				loadPdfs( false ); // Refresh the dashboard table in place (no-op elsewhere).
			}
		} finally {
			scanning = false;
		}
	}

	// ── Background auto-scan (§7): no button press needed ───────────────────

	function autoScan() {
		runScan( document.getElementById( 'a11yfy-scan-progress' ), true );
	}

	/**
	 * Deep-scan fresh uploads immediately: when the media uploader finishes a
	 * queue (Media Library grid / media-new), the batch endpoint hands back
	 * any new stale PDFs and the engine runs on them right away.
	 */
	function watchUploader( retries ) {
		var uploader = window.wp && window.wp.Uploader && window.wp.Uploader.queue;
		if ( ! uploader ) {
			if ( retries > 0 ) {
				window.setTimeout( function () { watchUploader( retries - 1 ); }, 2000 );
			}
			return;
		}
		uploader.on( 'reset', function () {
			// 'reset' fires when the upload queue drains.
			window.setTimeout( autoScan, 1000 );
		} );
	}

	// ── Dashboard wiring ────────────────────────────────────────────────────

	function renderJobs( data ) {
		var container = document.getElementById( 'a11yfy-jobs' );
		var table = document.getElementById( 'a11yfy-jobs-table' );
		if ( ! container || ! table ) {
			return;
		}
		var tbody = table.querySelector( 'tbody' );
		tbody.textContent = '';

		if ( ! data.jobs.length ) {
			var p = document.createElement( 'p' );
			p.textContent = container.getAttribute( 'data-empty' );
			tbody.appendChild( document.createElement( 'tr' ) ).appendChild( document.createElement( 'td' ) ).appendChild( p );
			return;
		}

		data.jobs.forEach( function ( job ) {
			var tr = document.createElement( 'tr' );

			var file = document.createElement( 'td' );
			file.textContent = job.file_name;
			tr.appendChild( file );

			// Best-effort: done, de a kimenet nem teljes PDF/UA-1 (compliant=0) —
			// külön chip + magyarázó title, a kredit-oszlopban 0 jön a szervertől.
			var bestEffort = 'done' === job.status && 0 === job.compliant;

			var status = document.createElement( 'td' );
			status.textContent = bestEffort ? a11yfyAdmin.i18n.bestEffort : job.status;
			status.className = 'a11yfy-status a11yfy-status--' + ( bestEffort ? 'besteffort' : job.status );
			if ( bestEffort ) {
				status.title = a11yfyAdmin.i18n.bestEffortNote;
			} else if ( job.error_message ) {
				status.title = job.error_message;
			}
			tr.appendChild( status );

			var result = document.createElement( 'td' );
			if ( 'done' === job.status && null !== job.before_issues ) {
				result.textContent = job.before_issues + ( bestEffort ? ' → ⚠' : ' → ✓' ) + ( treatmentLabel( job.treatment ) ? ' (' + treatmentLabel( job.treatment ) + ')' : '' );
				if ( bestEffort ) {
					result.title = a11yfyAdmin.i18n.bestEffortNote;
				}
			} else if ( job.error_message ) {
				result.textContent = job.error_message;
			} else {
				result.textContent = '—';
			}
			tr.appendChild( result );

			var credits = document.createElement( 'td' );
			credits.textContent = null === job.credits_used ? '—' : String( job.credits_used );
			tr.appendChild( credits );

			tbody.appendChild( tr );
		} );
	}

	function pollJobs() {
		getJSON( { action: 'a11yfy_status' } ).then( function ( response ) {
			if ( response && response.success ) {
				renderJobs( response.data );
				// Heartbeat polling while the dashboard is open (§4): denser when
				// jobs are actually running.
				window.setTimeout( pollJobs, response.data.active > 0 ? 10000 : 60000 );
			}
		} ).catch( function () {
			window.setTimeout( pollJobs, 60000 );
		} );
	}

	// ── Dashboard PDF table (status chips act as filters) ──────────────────

	var pdfTable = {
		paged: 1,
		total: 0,
		loading: false,
	};

	function activeStatuses() {
		return Array.prototype.map.call(
			document.querySelectorAll( '#a11yfy-filters .a11yfy-chip.is-active' ),
			function ( chip ) { return chip.getAttribute( 'data-status' ); }
		);
	}

	function actionButton( className, id, label ) {
		var a = document.createElement( 'a' );
		a.href = '#';
		a.className = 'button button-small ' + className;
		a.setAttribute( 'data-id', String( id ) );
		a.setAttribute( 'data-nonce', a11yfyAdmin.nonce );
		a.textContent = label;
		return a;
	}

	function renderPdfRows( items, append ) {
		var tbody = document.querySelector( '#a11yfy-pdfs-table tbody' );
		if ( ! append ) {
			tbody.textContent = '';
		}
		items.forEach( function ( item ) {
			var tr = document.createElement( 'tr' );

			var file = document.createElement( 'td' );
			if ( item.url ) {
				var link = document.createElement( 'a' );
				link.href = item.url;
				link.target = '_blank';
				link.rel = 'noopener';
				link.textContent = item.filename;
				file.appendChild( link );
			} else {
				file.textContent = item.filename;
			}
			tr.appendChild( file );

			var status = document.createElement( 'td' );
			status.innerHTML = item.badge; // Server-rendered badge_html (trusted).
			if ( item.tampered ) {
				// The remediated bytes were rewritten by another program
				// (optimizer/FTP) — the "Remediated" badge alone would mislead.
				var warn = document.createElement( 'span' );
				warn.className = 'a11yfy-badge a11yfy-badge--err';
				warn.textContent = '⚠ ' + a11yfyAdmin.i18n.tampered;
				status.appendChild( document.createTextNode( ' ' ) );
				status.appendChild( warn );
			}
			tr.appendChild( status );

			var actions = document.createElement( 'td' );
			actions.className = 'a11yfy-row-actions';
			if ( item.reapply ) {
				actions.appendChild( actionButton( 'a11yfy-reapply button-primary', item.id, a11yfyAdmin.i18n.reapply ) );
			}
			if ( item.blocked ) {
				// Unsendable document (password/XFA/portfolio): keep the Fix
				// affordance visible but inert, with the reason on it. The
				// reason lives ONLY in the title (description) — putting it in
				// aria-label too made screen readers announce it twice.
				var blockedBtn = document.createElement( 'button' );
				blockedBtn.type = 'button';
				blockedBtn.className = 'button button-small a11yfy-remediate--locked';
				blockedBtn.disabled = true;
				blockedBtn.title = item.blocked_msg;
				// Emoji-free accessible name; the title is the description.
				blockedBtn.setAttribute( 'aria-label', a11yfyAdmin.i18n.fix );
				blockedBtn.textContent = '🔒 ' + a11yfyAdmin.i18n.fix;
				actions.appendChild( blockedBtn );
			} else if ( item.remediate_signed ) {
				// Digitally signed: ACTIVE Fix button — the delegated click
				// handler asks for confirmation before queueing (the signature
				// becomes invalid).
				var signedBtn = actionButton( 'a11yfy-remediate button-primary', item.id, a11yfyAdmin.i18n.fix );
				signedBtn.setAttribute( 'data-signed', '1' );
				if ( item.blocked_msg ) {
					signedBtn.title = item.blocked_msg;
				}
				actions.appendChild( signedBtn );
			} else if ( item.remediate ) {
				actions.appendChild( actionButton( 'a11yfy-remediate button-primary', item.id, a11yfyAdmin.i18n.fix ) );
			} else if ( item.remediate_locked ) {
				// Not connected: keep the Fix affordance visible but inert. The
				// connect hint lives only in the title — see the aria note above.
				var locked = document.createElement( 'button' );
				locked.type = 'button';
				locked.className = 'button button-small a11yfy-remediate--locked';
				locked.disabled = true;
				locked.title = a11yfyAdmin.i18n.fixLocked;
				locked.textContent = a11yfyAdmin.i18n.fix;
				actions.appendChild( locked );
			}
			// Server-formatted credit estimate — only sent for rows with a
			// live Fix affordance (never for blocked/compliant files).
			if ( item.estimate && ( item.remediate || item.remediate_signed || item.remediate_locked ) ) {
				var estimate = document.createElement( 'span' );
				estimate.className = 'a11yfy-credit-est';
				estimate.textContent = item.estimate;
				actions.appendChild( estimate );
			}
			if ( item.restore ) {
				actions.appendChild( actionButton( 'a11yfy-restore', item.id, a11yfyAdmin.i18n.restore ) );
			}
			// Detail page link — the page live-scans, so it is meaningful even
			// for files with no stored verdict yet.
			var details = document.createElement( 'a' );
			details.href = a11yfyAdmin.docUrl + '&id=' + item.id;
			details.className = 'button button-small';
			details.textContent = a11yfyAdmin.i18n.details;
			actions.appendChild( details );
			tr.appendChild( actions );

			tr.setAttribute( 'data-a11yfy-id', String( item.id ) );
			tr.setAttribute( 'data-edit-url', item.edit_url || '' );
			tbody.appendChild( tr );
		} );
	}

	// Plain-language issue details now live on the per-document detail page
	// (admin.php?page=a11yfy-document&id=N) — the expandable row is gone.

	function loadPdfs( append ) {
		if ( pdfTable.loading || ! document.getElementById( 'a11yfy-pdfs-table' ) ) {
			return;
		}
		pdfTable.loading = true;
		pdfTable.paged = append ? pdfTable.paged + 1 : 1;

		getJSON( {
			action: 'a11yfy_list_pdfs',
			statuses: activeStatuses().join( ',' ),
			paged: pdfTable.paged,
		} ).then( function ( response ) {
			if ( ! response || ! response.success ) {
				return;
			}
			pdfTable.total = response.data.total;
			renderPdfRows( response.data.items, append );

			var shown = document.querySelectorAll( '#a11yfy-pdfs-table tbody tr' ).length;
			var empty = document.getElementById( 'a11yfy-pdfs-empty' );
			var more = document.getElementById( 'a11yfy-pdfs-more' );
			if ( empty ) {
				empty.hidden = shown > 0;
			}
			if ( more ) {
				more.hidden = shown >= pdfTable.total;
			}
		} ).finally( function () {
			pdfTable.loading = false;
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var scanBtn = document.getElementById( 'a11yfy-scan-btn' );
		var progress = document.getElementById( 'a11yfy-scan-progress' );
		if ( scanBtn && progress ) {
			scanBtn.addEventListener( 'click', function () {
				scanBtn.disabled = true;
				runScan( progress, false ).finally( function () {
					scanBtn.disabled = false;
				} );
			} );
		}

		var fixAll = document.getElementById( 'a11yfy-fix-all' );
		if ( fixAll ) {
			fixAll.addEventListener( 'click', function () {
				fixAll.disabled = true;
				post( 'a11yfy_fix_all', {} ).then( function ( response ) {
					if ( response && response.success ) {
						fixAll.textContent = sprintf( a11yfyAdmin.i18n.queued, response.data.queued );
					} else {
						fixAll.disabled = false;
						window.alert( ( response && response.data && response.data.message ) || 'Error' );
					}
				} );
			} );
		}

		if ( document.getElementById( 'a11yfy-jobs' ) ) {
			pollJobs();
		}

		var filters = document.getElementById( 'a11yfy-filters' );
		if ( filters ) {
			filters.addEventListener( 'click', function ( event ) {
				var chip = event.target.closest ? event.target.closest( '.a11yfy-chip' ) : null;
				if ( ! chip ) {
					return;
				}
				chip.classList.toggle( 'is-active' );
				chip.setAttribute( 'aria-pressed', chip.classList.contains( 'is-active' ) ? 'true' : 'false' );
				loadPdfs( false );
			} );
			loadPdfs( false );
		}

		var more = document.getElementById( 'a11yfy-pdfs-more' );
		if ( more ) {
			more.addEventListener( 'click', function () {
				loadPdfs( true );
			} );
		}

		// Background auto-scan (default on, a11yfy_auto_scan filter): stale
		// PDFs are deep-scanned whenever an admin has the dashboard or the
		// Media Library open — and right after browser uploads finish.
		if ( a11yfyAdmin.autoScan ) {
			window.setTimeout( autoScan, 1500 );
			watchUploader( 3 );
		}

		var disableSp = document.getElementById( 'a11yfy-disable-sp-pdf' );
		if ( disableSp ) {
			disableSp.addEventListener( 'click', function () {
				disableSp.disabled = true;
				post( 'a11yfy_disable_pdf_optimization', {} ).then( function ( response ) {
					if ( response && response.success ) {
						disableSp.textContent = '✓ ' + a11yfyAdmin.i18n.spDisabled;
					} else {
						disableSp.disabled = false;
						window.alert( ( response && response.data && response.data.message ) || 'Error' );
					}
				} ).catch( function ( err ) {
					disableSp.disabled = false;
					window.alert( String( err ) );
				} );
			} );
		}
	} );

	// ── Media list row actions (upload.php) — delegated listener, the rows
	//    are re-rendered by the list-table AJAX ─────────────────────────────
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest ? event.target.closest( '.a11yfy-remediate, .a11yfy-restore, .a11yfy-reapply' ) : null;
		if ( ! link ) {
			return;
		}
		event.preventDefault();
		var action = 'a11yfy_remediate';
		if ( link.classList.contains( 'a11yfy-restore' ) ) {
			action = 'a11yfy_restore';
		} else if ( link.classList.contains( 'a11yfy-reapply' ) ) {
			action = 'a11yfy_reapply';
		}
		// Digitally signed PDF: explicit confirmation before the signature is
		// invalidated by remediation.
		var confirmSigned = 'a11yfy_remediate' === action && '1' === link.getAttribute( 'data-signed' );
		if ( confirmSigned && ! window.confirm( a11yfyAdmin.i18n.signedConfirm ) ) {
			return;
		}
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', link.getAttribute( 'data-nonce' ) );
		body.append( 'id', link.getAttribute( 'data-id' ) );
		if ( confirmSigned ) {
			body.append( 'confirm_signed', '1' );
		}
		link.style.opacity = '0.5';
		fetch( ( window.ajaxurl || '/wp-admin/admin-ajax.php' ), { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( response ) {
				if ( response && response.success ) {
					window.location.reload();
				} else {
					link.style.opacity = '';
					window.alert( ( response && response.data && response.data.message ) || 'Error' );
				}
			} );
	} );
} )();
