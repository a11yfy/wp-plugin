/**
 * a11yfy visitor on-demand flow (public front-end).
 *
 * Batch-checks the accessibility status of same-origin PDF links, intercepts
 * clicks on not-accessible ones and offers an accessible modal to request an
 * accessible version by email. Fail-open by design: no answer (yet) = the
 * link navigates normally; only a confirmed not-accessible/processing status
 * blocks the first click.
 *
 * No dependencies, no innerHTML with remote data (all text via textContent).
 */
( function () {
	'use strict';

	var cfg = window.a11yfyVisitor;
	if ( ! cfg || ! cfg.statusUrl ) {
		return;
	}

	var statuses = {}; // href (no fragment) -> { status, accessible_url? }
	var pendingLookups = [];
	var lookupTimer = null;
	var modal = null;
	var lastTrigger = null;
	var lastHref = '';
	var lastTarget = '';

	function txt( key ) {
		return ( cfg.texts && cfg.texts[ key ] ) || '';
	}

	function normalizeHref( a ) {
		return a.href.split( '#' )[ 0 ];
	}

	function isPdfLink( a ) {
		if ( ! a.href || 0 !== a.href.indexOf( window.location.protocol + '//' + window.location.host ) ) {
			// Same-origin only (protocol-relative and cross-host links pass through).
			if ( 0 !== a.href.indexOf( 'https://' + window.location.host ) && 0 !== a.href.indexOf( 'http://' + window.location.host ) ) {
				return false;
			}
		}
		var path;
		try {
			path = new URL( a.href, window.location.href ).pathname;
		} catch ( e ) {
			return false;
		}
		return /\.pdf$/i.test( path );
	}

	function dismissKey( href ) {
		// djb2 — tiny stable hash so the sessionStorage keys stay short.
		var h = 5381;
		for ( var i = 0; i < href.length; i++ ) {
			h = ( ( h << 5 ) + h + href.charCodeAt( i ) ) >>> 0;
		}
		return 'a11yfy_dm_' + h.toString( 36 );
	}

	function isDismissed( href ) {
		try {
			return '1' === window.sessionStorage.getItem( dismissKey( href ) );
		} catch ( e ) {
			return false;
		}
	}

	function setDismissed( href ) {
		try {
			window.sessionStorage.setItem( dismissKey( href ), '1' );
		} catch ( e ) { /* storage unavailable — modal may repeat, harmless */ }
	}

	// ── Status collection ──────────────────────────────────────────────────

	function collectLinks( root ) {
		var links = ( root || document ).querySelectorAll( 'a[href]' );
		var fresh = [];
		for ( var i = 0; i < links.length; i++ ) {
			var a = links[ i ];
			if ( ! isPdfLink( a ) ) {
				continue;
			}
			var href = normalizeHref( a );
			if ( ! ( href in statuses ) && -1 === pendingLookups.indexOf( href ) && -1 === fresh.indexOf( href ) ) {
				fresh.push( href );
			}
		}
		if ( fresh.length ) {
			pendingLookups = pendingLookups.concat( fresh );
			scheduleLookup();
		}
	}

	function scheduleLookup() {
		if ( lookupTimer ) {
			return;
		}
		lookupTimer = window.setTimeout( runLookup, 250 );
	}

	function runLookup() {
		lookupTimer = null;
		var batch = pendingLookups.splice( 0, 100 );
		if ( ! batch.length ) {
			return;
		}
		window.fetch( cfg.statusUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { urls: batch } )
		} ).then( function ( res ) {
			return res.ok ? res.json() : null;
		} ).then( function ( data ) {
			if ( data && data.statuses ) {
				for ( var url in data.statuses ) {
					if ( Object.prototype.hasOwnProperty.call( data.statuses, url ) ) {
						statuses[ url ] = data.statuses[ url ];
					}
				}
				annotateLinks();
			}
			if ( pendingLookups.length ) {
				scheduleLookup();
			}
		} ).catch( function () { /* fail-open */ } );
	}

	function annotateLinks() {
		var links = document.querySelectorAll( 'a[href]' );
		for ( var i = 0; i < links.length; i++ ) {
			var a = links[ i ];
			if ( ! isPdfLink( a ) ) {
				continue;
			}
			var info = statuses[ normalizeHref( a ) ];
			if ( ! info ) {
				continue;
			}
			a.setAttribute( 'data-a11yfy-status', info.status );
			// Conservative mode: menus/widgets links are not swapped by PHP —
			// point them straight at the accessible copy.
			if ( info.accessible_url && a.href !== info.accessible_url ) {
				a.href = info.accessible_url;
			}
		}
	}

	// ── Click interception ─────────────────────────────────────────────────

	document.addEventListener( 'click', function ( event ) {
		if ( event.defaultPrevented || 0 !== event.button
			|| event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return; // Modified/middle clicks pass through (spec §2.1).
		}
		var a = event.target && event.target.closest ? event.target.closest( 'a[href]' ) : null;
		if ( ! a || ! isPdfLink( a ) ) {
			return;
		}
		var href = normalizeHref( a );
		var info = statuses[ href ];
		if ( ! info || isDismissed( href ) ) {
			return; // Unknown / not-yet-answered / dismissed → fail-open.
		}
		if ( 'not_accessible' !== info.status && 'processing' !== info.status ) {
			return;
		}
		event.preventDefault();
		lastTrigger = a;
		lastHref = a.href;
		lastTarget = a.getAttribute( 'target' ) || '';
		openModal( info.status );
	}, true );

	// ── Modal ──────────────────────────────────────────────────────────────

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function buildModal() {
		var overlay = el( 'div', 'a11yfy-modal__overlay' );
		var dialog = el( 'div', 'a11yfy-modal' );
		dialog.setAttribute( 'role', 'dialog' );
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.setAttribute( 'aria-labelledby', 'a11yfy-modal-title' );
		dialog.setAttribute( 'aria-describedby', 'a11yfy-modal-body' );
		// Explicit accent (admin-picked color): enforce it over the theme.
		// Without it the primary buttons carry wp-element-button, so block
		// themes style them from theme.json (classic themes: CSS fallback).
		if ( cfg.accent ) {
			dialog.className += ' a11yfy-modal--accent';
			dialog.style.setProperty( '--a11yfy-accent', cfg.accent );
		}

		var close = el( 'button', 'a11yfy-modal__close', '×' );
		close.setAttribute( 'type', 'button' );
		close.setAttribute( 'aria-label', txt( 'close' ) );
		close.addEventListener( 'click', closeModal );

		var title = el( 'h2', 'a11yfy-modal__title', txt( 'modal_title' ) );
		title.id = 'a11yfy-modal-title';
		title.setAttribute( 'tabindex', '-1' );

		var body = el( 'p', 'a11yfy-modal__body', txt( 'modal_body' ) );
		body.id = 'a11yfy-modal-body';

		var actions = el( 'div', 'a11yfy-modal__actions' );
		var openBtn = el( 'button', 'a11yfy-modal__btn a11yfy-modal__btn--primary wp-element-button', txt( 'btn_open' ) );
		openBtn.setAttribute( 'type', 'button' );
		openBtn.addEventListener( 'click', openDocument );
		var requestBtn = el( 'button', 'a11yfy-modal__btn a11yfy-modal__btn--secondary', txt( 'btn_request' ) );
		requestBtn.setAttribute( 'type', 'button' );
		requestBtn.addEventListener( 'click', showRequestStep );
		actions.appendChild( openBtn );
		actions.appendChild( requestBtn );

		var live = el( 'div', 'a11yfy-modal__step' );
		live.setAttribute( 'aria-live', 'polite' );

		dialog.appendChild( close );
		dialog.appendChild( title );
		dialog.appendChild( body );
		dialog.appendChild( actions );
		dialog.appendChild( live );
		overlay.appendChild( dialog );

		overlay.addEventListener( 'mousedown', function ( ev ) {
			if ( ev.target === overlay ) {
				closeModal();
			}
		} );
		overlay.addEventListener( 'keydown', function ( ev ) {
			if ( 'Escape' === ev.key ) {
				ev.stopPropagation();
				closeModal();
				return;
			}
			if ( 'Tab' === ev.key ) {
				trapFocus( ev, dialog );
			}
		} );

		return { overlay: overlay, dialog: dialog, title: title, body: body, actions: actions, live: live, requestBtn: requestBtn };
	}

	function trapFocus( ev, dialog ) {
		var focusable = dialog.querySelectorAll( 'button, input, a[href], [tabindex="-1"]' );
		var list = [];
		for ( var i = 0; i < focusable.length; i++ ) {
			if ( null !== focusable[ i ].offsetParent ) {
				list.push( focusable[ i ] );
			}
		}
		if ( ! list.length ) {
			return;
		}
		var first = list[ 0 ];
		var last = list[ list.length - 1 ];
		if ( ev.shiftKey && document.activeElement === first ) {
			ev.preventDefault();
			last.focus();
		} else if ( ! ev.shiftKey && document.activeElement === last ) {
			ev.preventDefault();
			first.focus();
		}
	}

	function openModal( status ) {
		if ( ! modal ) {
			modal = buildModal();
			document.body.appendChild( modal.overlay );
		}
		modal.body.textContent = 'processing' === status ? txt( 'processing_info' ) : txt( 'modal_body' );
		modal.live.textContent = '';
		while ( modal.live.firstChild ) {
			modal.live.removeChild( modal.live.firstChild );
		}
		modal.requestBtn.disabled = false;
		modal.overlay.style.display = 'flex';
		modal.title.focus();
	}

	function closeModal() {
		if ( modal ) {
			modal.overlay.style.display = 'none';
		}
		if ( lastTrigger && lastTrigger.focus ) {
			lastTrigger.focus();
		}
	}

	function openDocument() {
		setDismissed( lastHref.split( '#' )[ 0 ] );
		closeModal();
		if ( '_blank' === lastTarget ) {
			window.open( lastHref, '_blank', 'noopener' );
		} else {
			window.location.assign( lastHref );
		}
	}

	function showRequestStep() {
		modal.requestBtn.disabled = true;

		var step = modal.live;
		while ( step.firstChild ) {
			step.removeChild( step.firstChild );
		}

		step.appendChild( el( 'p', 'a11yfy-modal__info', txt( 'request_info' ) ) );

		var form = el( 'form', 'a11yfy-modal__form' );
		var label = el( 'label', 'a11yfy-modal__label', txt( 'email_label' ) );
		label.setAttribute( 'for', 'a11yfy-visitor-email' );
		var input = el( 'input', 'a11yfy-modal__input' );
		input.type = 'email';
		input.id = 'a11yfy-visitor-email';
		input.required = true;
		input.autocomplete = 'email';
		// Honeypot: visually hidden, never labeled — humans skip it.
		var hp = el( 'input', 'a11yfy-modal__hp' );
		hp.type = 'text';
		hp.name = 'a11yfy_website';
		hp.tabIndex = -1;
		hp.setAttribute( 'aria-hidden', 'true' );
		hp.autocomplete = 'off';
		var submit = el( 'button', 'a11yfy-modal__btn a11yfy-modal__btn--primary wp-element-button', txt( 'btn_submit' ) );
		submit.type = 'submit';

		form.appendChild( label );
		form.appendChild( input );
		form.appendChild( hp );
		form.appendChild( submit );

		var note = el( 'p', 'a11yfy-modal__privacy', txt( 'privacy_note' ) );
		if ( cfg.privacyUrl ) {
			note.appendChild( document.createTextNode( ' ' ) );
			var link = el( 'a', '', cfg.privacyUrl.replace( /^https?:\/\//, '' ).replace( /\/$/, '' ) );
			link.href = cfg.privacyUrl;
			link.target = '_blank';
			link.rel = 'noopener';
			note.appendChild( link );
		}

		var feedback = el( 'p', 'a11yfy-modal__feedback' );
		feedback.setAttribute( 'role', 'status' );

		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			feedback.textContent = '';
			feedback.className = 'a11yfy-modal__feedback';
			if ( ! input.value || ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( input.value ) ) {
				feedback.textContent = txt( 'err_email' );
				feedback.className += ' a11yfy-modal__feedback--error';
				input.focus();
				return;
			}
			submit.disabled = true;
			window.fetch( cfg.requestUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { url: lastHref, email: input.value, hp: hp.value } )
			} ).then( function ( res ) {
				return res.json().then( function ( data ) {
					return { ok: res.ok, status: res.status, data: data };
				} );
			} ).then( function ( result ) {
				if ( result.ok ) {
					while ( step.firstChild ) {
						step.removeChild( step.firstChild );
					}
					step.appendChild( el( 'p', 'a11yfy-modal__success', txt( 'success_msg' ) ) );
					return;
				}
				submit.disabled = false;
				feedback.textContent = 429 === result.status ? txt( 'err_rate' )
					: ( result.data && 'invalid_email' === result.data.error ? txt( 'err_email' ) : txt( 'err_generic' ) );
				feedback.className += ' a11yfy-modal__feedback--error';
			} ).catch( function () {
				submit.disabled = false;
				feedback.textContent = txt( 'err_generic' );
				feedback.className += ' a11yfy-modal__feedback--error';
			} );
		} );

		step.appendChild( form );
		step.appendChild( note );
		step.appendChild( feedback );
		input.focus();
	}

	// ── Boot ───────────────────────────────────────────────────────────────

	function boot() {
		collectLinks( document );
		if ( 'MutationObserver' in window ) {
			var debounce = null;
			new MutationObserver( function () {
				if ( debounce ) {
					return;
				}
				debounce = window.setTimeout( function () {
					debounce = null;
					collectLinks( document );
				}, 2000 );
			} ).observe( document.body, { childList: true, subtree: true } );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
