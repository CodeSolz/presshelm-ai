/* PressHelm settings: the AI & Model sections (vertical tabs) and the Connect AI form. */
( function () {
	'use strict';

	// ------------------------------------------------------------------ vertical tabs
	var list = document.querySelector( '.ph-vnav-list[role="tablist"]' );
	if ( list ) {
		var tabs = Array.prototype.slice.call( list.querySelectorAll( '[role="tab"]' ) );
		var select = function ( tab, focus ) {
			tabs.forEach( function ( t ) {
				var on = t === tab;
				t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				t.tabIndex = on ? 0 : -1;
				var panel = document.getElementById( t.getAttribute( 'aria-controls' ) );
				if ( panel ) {
					panel.hidden = ! on;
				}
			} );
			if ( focus ) {
				tab.focus();
			}
			if ( window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', tab.href );
			}
		};
		tabs.forEach( function ( tab, i ) {
			tab.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				select( tab, false );
			} );
			tab.addEventListener( 'keydown', function ( e ) {
				var to = null;
				if ( 'ArrowDown' === e.key || 'ArrowRight' === e.key ) {
					to = tabs[ ( i + 1 ) % tabs.length ];
				} else if ( 'ArrowUp' === e.key || 'ArrowLeft' === e.key ) {
					to = tabs[ ( i - 1 + tabs.length ) % tabs.length ];
				} else if ( 'Home' === e.key ) {
					to = tabs[ 0 ];
				} else if ( 'End' === e.key ) {
					to = tabs[ tabs.length - 1 ];
				} else if ( ' ' === e.key || 'Enter' === e.key ) {
					e.preventDefault();
					select( tab, true );
					return;
				}
				if ( to ) {
					e.preventDefault();
					select( to, true );
				}
			} );
		} );
	}

	// ------------------------------------------------------------------ copy buttons
	Array.prototype.forEach.call( document.querySelectorAll( '.ph-copy' ), function ( btn ) {
		btn.addEventListener( 'click', function () {
			var input = document.getElementById( btn.getAttribute( 'data-copy' ) );
			if ( ! input ) {
				return;
			}
			var label = btn.textContent;
			var done = function () {
				btn.textContent = btn.getAttribute( 'data-done' ) || label;
				window.setTimeout( function () {
					btn.textContent = label;
				}, 1600 );
			};
			input.select();
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( input.value ).then( done, function () {} );
			} else {
				try {
					document.execCommand( 'copy' );
					done();
				} catch ( e ) {}
			}
		} );
	} );

	// ------------------------------------------------------------------ "Try asking": copy a prompt
	Array.prototype.forEach.call( document.querySelectorAll( '.ph-idea[data-copy-text]' ), function ( btn ) {
		btn.addEventListener( 'click', function () {
			var text = btn.getAttribute( 'data-copy-text' );
			var label = btn.querySelector( 'span' );
			var before = label.textContent;
			var done = function () {
				btn.classList.add( 'is-done' );
				label.textContent = btn.getAttribute( 'data-done' );
				window.setTimeout( function () {
					btn.classList.remove( 'is-done' );
					label.textContent = before;
				}, 1400 );
			};
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then( done, function () {} );
			}
		} );
	} );

	// ------------------------------------------------------------------ Connect AI form
	var form = document.getElementById( 'presshelm-ai-form' );
	var dataEl = document.getElementById( 'presshelm-ai-data' );
	if ( ! form || ! dataEl ) {
		return;
	}
	var data = {};
	try {
		data = JSON.parse( dataEl.textContent );
	} catch ( e ) {
		return;
	}
	var q = function ( sel ) {
		return form.querySelector( sel );
	};
	var status = q( '.ph-form-status' );
	var keyInput = q( '#ph-key' );
	var baseInput = q( '#ph-base' );
	var model = q( '#ph-model' );
	var fallback = q( '#ph-fallback' );
	var staging = '1' === form.getAttribute( 'data-staging' );
	var mode = form.getAttribute( 'data-mode' );
	var current = form.getAttribute( 'data-current' );
	var currentMethod = form.getAttribute( 'data-current-method' );
	var save = q( '.ph-save' );
	var health = q( '.ph-health' );
	var healthText = health ? health.querySelector( '.ph-health-text' ) : null;

	// The connection status row: ok / warn / idle.
	var setHealth = function ( kind, text ) {
		if ( ! health ) {
			return;
		}
		health.className = 'ph-health is-' + kind;
		healthText.textContent = text;
	};
	var resetHealth = function () {
		if ( ! health ) {
			return;
		}
		if ( provider() === current && method() === currentMethod ) {
			setHealth( health.getAttribute( 'data-initial' ), healthText.getAttribute( 'data-initial-text' ) );
		} else {
			setHealth( 'idle', form.getAttribute( 'data-idle' ) );
		}
	};

	var say = function ( text, kind ) {
		status.className = 'ph-form-status' + ( kind ? ' is-' + kind : '' );
		status.textContent = text || '';
	};
	var provider = function () {
		var r = q( 'input[name="provider"]:checked' );
		return r ? r.value : '';
	};
	var method = function () {
		var r = q( 'input[name="method"]:checked' );
		return r ? r.value : 'api_key';
	};
	var noneLabel = fallback && fallback.options.length ? fallback.options[ 0 ].textContent : '';
	var fill = function ( select, models, chosen, optional ) {
		select.innerHTML = '';
		if ( optional ) {
			select.appendChild( new Option( noneLabel, '' ) );
		}
		models.forEach( function ( m ) {
			select.appendChild( new Option( m, m ) );
		} );
		if ( chosen && models.indexOf( chosen ) !== -1 ) {
			select.value = chosen;
		} else {
			select.value = optional ? '' : models[ 0 ] || '';
		}
	};

	var sync = function ( providerChanged ) {
		var id = provider();
		var p = data[ id ];
		if ( ! p ) {
			return;
		}
		// How to connect: only the methods this provider offers.
		var segs = form.querySelectorAll( '.ph-segment' );
		var offered = 0;
		Array.prototype.forEach.call( segs, function ( s ) {
			var ok = p.methods.indexOf( s.getAttribute( 'data-method' ) ) !== -1;
			s.hidden = ! ok;
			offered += ok ? 1 : 0;
			if ( ! ok ) {
				s.querySelector( 'input' ).checked = false;
			}
		} );
		if ( ! q( 'input[name="method"]:checked' ) ) {
			var first = q( '.ph-segment:not([hidden]) input' );
			if ( first ) {
				first.checked = true;
			}
		}
		var m = method();
		q( '.ph-auth-key' ).hidden = 'api_key' !== m;
		q( '.ph-auth-oauth' ).hidden = 'oauth' !== m;
		if ( baseInput ) {
			baseInput.closest( '.ph-base-url' ).hidden = 'openai_compatible' !== id;
		}
		var getKey = q( '.ph-get-key' );
		if ( getKey ) {
			getKey.hidden = ! p.key_url;
			getKey.href = p.key_url || '#';
		}
		var savedNote = q( '.ph-key-saved' );
		var hasSaved = id === current && 'api_key' === currentMethod && p.saved;
		if ( savedNote ) {
			savedNote.hidden = ! hasSaved;
		}
		if ( keyInput ) {
			keyInput.placeholder = hasSaved ? keyInput.getAttribute( 'data-saved-placeholder' ) : keyInput.getAttribute( 'data-placeholder' );
		}
		if ( providerChanged ) {
			var models = p.models.length ? p.models : p.presets;
			fill( model, models, p.model, false );
			fill( fallback, models, p.fallback, true );
			say( '' );
		}
		resetHealth();
	};


	Array.prototype.forEach.call( form.querySelectorAll( 'input[name="provider"]' ), function ( r ) {
		r.addEventListener( 'change', function () {
			sync( true );
		} );
	} );
	Array.prototype.forEach.call( form.querySelectorAll( '.ph-segment input' ), function ( r ) {
		r.addEventListener( 'change', function () {
			sync( false );
		} );
	} );

	// Show / hide the key.
	var reveal = q( '.ph-reveal' );
	if ( reveal && keyInput ) {
		var showLabel = reveal.getAttribute( 'aria-label' );
		reveal.addEventListener( 'click', function () {
			var show = 'password' === keyInput.type;
			keyInput.type = show ? 'text' : 'password';
			reveal.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
			reveal.setAttribute( 'aria-label', show ? reveal.getAttribute( 'data-hide-label' ) : showLabel );
		} );
	}

	// "Refresh models" and "Test connection": check the key that was pasted, or the saved one.
	var load = q( '.ph-load-models' );
	var test = q( '.ph-test' );
	var check = function ( button ) {
		var body = new window.FormData();
		body.append( 'action', 'presshelm_ai_models' );
		body.append( 'nonce', form.getAttribute( 'data-nonce' ) );
		body.append( 'provider', provider() );
		body.append( 'mode', mode );
		if ( baseInput ) {
			body.append( 'base_url', baseInput.value );
		}
		if ( keyInput && keyInput.value && ! staging && 'api_key' === method() ) {
			body.append( 'api_key', keyInput.value );
		}
		button.disabled = true;
		if ( health ) {
			health.classList.add( 'is-busy' );
		}
		setHealth( 'idle', form.getAttribute( 'data-checking' ) );
		say( '' );
		window.fetch( form.getAttribute( 'data-ajax' ), { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( r ) {
				if ( ! r || ! r.success ) {
					setHealth( 'warn', r && r.data && r.data.message ? r.data.message : form.getAttribute( 'data-failed' ) );
					return;
				}
				var p = data[ provider() ];
				p.models = r.data.models;
				p.verified = true;
				fill( model, r.data.models, model.value || r.data.model, false );
				fill( fallback, r.data.models, fallback.value || r.data.fallback, true );
				setHealth( 'ok', form.getAttribute( 'data-ok' ) + ' · ' + r.data.message );
			} )
			.catch( function () {
				setHealth( 'warn', form.getAttribute( 'data-failed' ) );
			} )
			.then( function () {
				button.disabled = false;
				if ( health ) {
					health.classList.remove( 'is-busy' );
				}
			} );
	};
	[ load, test ].forEach( function ( b ) {
		if ( b ) {
			b.addEventListener( 'click', function () {
				check( b );
			} );
		}
	} );

	// Validate, then save (a new key goes straight to PressHelm Cloud when the site can't keep it).
	form.addEventListener( 'submit', function ( e ) {
		var id = provider();
		var m = method();
		var p = data[ id ] || {};
		var reauth = e.submitter && 'reauth' === e.submitter.value;
		var hasKey = keyInput && '' !== keyInput.value.trim();
		var keep = id === current && m === currentMethod;
		var problem = '';
		[ keyInput, baseInput, model ].forEach( function ( el ) {
			if ( el ) {
				el.removeAttribute( 'aria-invalid' );
			}
		} );
		if ( ! id ) {
			problem = form.getAttribute( 'data-err-provider' );
		} else if ( 'api_key' === m && ! hasKey && ! keep && ! p.verified ) {
			problem = form.getAttribute( 'data-err-key' );
			keyInput.setAttribute( 'aria-invalid', 'true' );
			keyInput.focus();
		} else if ( 'api_key' === m && 'openai_compatible' === id && hasKey && ! /^https:\/\//.test( baseInput.value ) ) {
			problem = form.getAttribute( 'data-err-base' );
			baseInput.setAttribute( 'aria-invalid', 'true' );
			baseInput.focus();
		} else if ( ! model.value && ! ( 'oauth' === m && ( ! keep || reauth ) ) ) {
			problem = form.getAttribute( 'data-err-model' );
			model.setAttribute( 'aria-invalid', 'true' );
			model.focus();
		}
		if ( problem ) {
			e.preventDefault();
			say( problem, '' );
			return;
		}
		var toCloud = staging && 'api_key' === m && hasKey;
		Array.prototype.forEach.call( form.querySelectorAll( '[data-site-field]' ), function ( f ) {
			f.disabled = toCloud;
		} );
		Array.prototype.forEach.call( form.querySelectorAll( 'input[name="kind"], input[name="site_key"], input[name="state"], input[name="return_to"]' ), function ( f ) {
			f.disabled = ! toCloud;
		} );
		if ( staging ) {
			form.action = toCloud ? form.getAttribute( 'data-cloud-action' ) : form.getAttribute( 'data-site-action' );
			if ( keyInput ) {
				keyInput.name = toCloud ? 'secret' : '';
				if ( ! toCloud ) {
					keyInput.removeAttribute( 'name' );
				}
			}
			var back = q( 'input[name="return_to"]' );
			if ( toCloud && back ) {
				// The model chosen here comes back with the browser.
				var u = new window.URL( back.value );
				u.searchParams.set( 'ph_model', model.value );
				u.searchParams.set( 'ph_fallback', fallback.value );
				back.value = u.toString();
			}
		}
		form.classList.add( 'is-busy' );
		save.querySelector( 'span' ).textContent = save.getAttribute( 'data-saving' );
	} );

	sync( false );
} )();
