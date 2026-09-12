import apiFetch from '@wordpress/api-fetch';
import { select } from '@wordpress/data';
import { buildSavePersistenceOutcome } from './save-persistence-outcomes';

const STORAGE_VERSION = 1;
const HEADER = 'X-Flavor-Agent-Save-Occurrence';
const LOOKUP_LIMIT = 50;
const MAX_LOOKUP_ATTEMPTS = 6;
const ASSURANCE_FIELDS = [
	'persistenceVerdict',
	'verificationCoverage',
	'requestStatus',
	'undoState',
];
const TARGET_FIELDS = [
	'clientId',
	'blockName',
	'blockPath',
	'templateRef',
	'templatePartRef',
	'globalStylesId',
];
export const EDITOR_APPLY_TYPES = new Set( [
	'apply_suggestion',
	'apply_block_structural_suggestion',
	'apply_template_suggestion',
	'apply_template_part_suggestion',
	'apply_global_styles_suggestion',
	'apply_style_book_suggestion',
] );

function copy( value ) {
	return JSON.parse( JSON.stringify( value ) );
}

function createSaveOccurrenceId() {
	if ( typeof globalThis.crypto.randomUUID === 'function' ) {
		return globalThis.crypto.randomUUID();
	}
	// getRandomValues also works in HTTP editors where randomUUID is unavailable.
	const bytes = globalThis.crypto.getRandomValues( new Uint8Array( 16 ) );
	bytes[ 6 ] = ( bytes[ 6 ] % 16 ) + 64;
	bytes[ 8 ] = ( bytes[ 8 ] % 64 ) + 128;
	const hex = Array.from( bytes, ( value ) =>
		value.toString( 16 ).padStart( 2, '0' )
	).join( '' );
	return `${ hex.slice( 0, 8 ) }-${ hex.slice( 8, 12 ) }-${ hex.slice(
		12,
		16
	) }-${ hex.slice( 16, 20 ) }-${ hex.slice( 20 ) }`;
}

function canonicalRestUrl( restUrl ) {
	try {
		return new URL( restUrl ).href;
	} catch {
		return '';
	}
}

export function getSavePersistenceStorageKey( restUrl, currentUserId ) {
	const site = canonicalRestUrl( restUrl );
	const user = Number( currentUserId );
	return site && Number.isInteger( user ) && user > 0
		? `flavor-agent:save-persistence:v1:${ encodeURIComponent(
				site
		  ) }:${ user }`
		: '';
}

function requestRoute( options, restUrl ) {
	try {
		if ( options.url || /^https?:/i.test( options.path || '' ) ) {
			const root = new URL( restUrl );
			const url = new URL( options.url || options.path, root );
			if ( url.origin !== root.origin ) {
				return null;
			}
			if ( root.searchParams.has( 'rest_route' ) ) {
				if (
					url.pathname !== root.pathname ||
					! url.searchParams.has( 'rest_route' )
				) {
					return null;
				}
				return {
					path: url.searchParams.get( 'rest_route' ),
					query: url.searchParams,
				};
			}
			if ( ! url.pathname.startsWith( root.pathname ) ) {
				return null;
			}
			return {
				path: `/${ url.pathname.slice( root.pathname.length ) }`,
				query: url.searchParams,
			};
		}
		if (
			typeof options.path !== 'string' ||
			! options.path.startsWith( '/' ) ||
			options.path.startsWith( '//' )
		) {
			return null;
		}
		const url = new URL( options.path, restUrl );
		return { path: url.pathname, query: url.searchParams };
	} catch {
		return null;
	}
}

/**
 * Match loaded core-data entity routes, never arbitrary REST POST requests.
 *
 * @param {Object} options Request options supplied to apiFetch.
 * @param {Object} core    Core-data selectors.
 * @param {string} restUrl Current site's REST root.
 * @return {Object|null} Physical entity identity for a qualifying save.
 */
export function matchEntitySaveRequest( options, core = {}, restUrl ) {
	if (
		! [ 'POST', 'PUT', 'PATCH' ].includes(
			String( options.method || 'GET' ).toUpperCase()
		)
	) {
		return null;
	}
	const route = requestRoute( options, restUrl );
	if (
		! route ||
		[ 'preview', 'wp_theme_preview', 'autosave', 'revision' ].some(
			( key ) => route.query.has( key ) || options.data?.[ key ]
		) ||
		options.isAutosave ||
		options.data?.isAutosave ||
		options.data?.status === 'trash'
	) {
		return null;
	}
	const entities = [ ...( core.getEntitiesConfig?.( 'postType' ) || [] ) ];
	const styles = core.getEntityConfig?.( 'root', 'globalStyles' );
	if ( styles ) {
		entities.push( styles );
	}
	for ( const entity of entities ) {
		if ( ! entity?.baseURL ) {
			continue;
		}
		const bases = [ entity.baseURL.replace( /\/$/, '' ) ];
		// core-data keeps the legacy templates route for theme//slug IDs.
		if ( entity.kind === 'postType' && entity.name === 'wp_template' ) {
			bases.push( bases[ 0 ].replace( /\/[^/]+$/, '/templates' ) );
		}
		for ( const base of bases ) {
			if (
				route.path !== base &&
				! route.path.startsWith( `${ base }/` )
			) {
				continue;
			}
			let id;
			try {
				id = decodeURIComponent(
					route.path.slice( base.length ).replace( /^\//, '' )
				);
			} catch {
				continue;
			}
			const template = [ 'wp_template', 'wp_template_part' ].includes(
				entity.name
			);
			if (
				id &&
				! /^\d+$/.test( id ) &&
				! ( template && /^[^/]+\/\/[^/]+$/.test( id ) )
			) {
				continue;
			}
			if (
				core.isAutosavingEntityRecord?.(
					entity.kind,
					entity.name,
					id || undefined
				)
			) {
				return null;
			}
			return {
				kind: entity.kind,
				name: entity.name,
				postType:
					entity.name === 'globalStyles'
						? 'wp_global_styles'
						: entity.name,
				id,
			};
		}
	}
	return null;
}

function candidateEntity( entry ) {
	if ( [ 'global-styles', 'style-book' ].includes( entry.surface ) ) {
		return {
			postType: 'wp_global_styles',
			id: String(
				entry.target?.globalStylesId || entry.document?.entityId || ''
			),
		};
	}
	if ( entry.surface === 'template' || entry.surface === 'template-part' ) {
		return {
			postType:
				entry.surface === 'template'
					? 'wp_template'
					: 'wp_template_part',
			id: String(
				entry.target?.templateRef ||
					entry.target?.templatePartRef ||
					entry.document?.entityId ||
					''
			),
		};
	}
	return {
		postType: entry.document?.postType || '',
		id: String( entry.document?.entityId || '' ),
	};
}

function candidateMetadata( entry ) {
	if (
		! entry?.id ||
		entry.applyLane !== 'editor-state' ||
		! EDITOR_APPLY_TYPES.has( entry.type )
	) {
		return null;
	}
	return copy( {
		id: entry.id,
		type: entry.type,
		applyLane: entry.applyLane,
		surface: entry.surface,
		document: entry.document || null,
		target: Object.fromEntries(
			TARGET_FIELDS.filter(
				( field ) => entry.target?.[ field ] !== undefined
			).map( ( field ) => [ field, entry.target[ field ] ] )
		),
	} );
}

function errorStatus( error ) {
	return Number(
		error?.data?.status || error?.status || error?.response?.status || 0
	);
}

function retryDelay( attempts ) {
	return Math.min( 30000, 1000 * 2 ** Math.max( 0, attempts - 1 ) );
}

/**
 * Session storage survives reloads and scope changes, but not tab closure.
 * This metadata-only outbox is separate from the v4 displayed activity cache.
 *
 * @param {Object}   options                    Controller dependencies.
 * @param {Function} options.apiFetch           Independent telemetry transport.
 * @param {Function} options.getCoreData        Live entity selectors.
 * @param {Storage}  options.storage            Session-scoped storage when available.
 * @param {string}   options.restUrl            Current site's REST root.
 * @param {number}   options.currentUserId      Authenticated editor user.
 * @param {Function} options.createOccurrenceId Fresh correlation token generator.
 * @param {Function} options.isCurrentUser      Stops delivery after identity changes.
 * @param {Function} options.onReconcile        Receives server-authored read fields.
 * @return {Object} Save middleware and candidate/reconciliation lifecycle.
 */
export function createSavePersistenceController( {
	apiFetch: fetch,
	getCoreData,
	storage,
	restUrl,
	currentUserId,
	createOccurrenceId = createSaveOccurrenceId,
	isCurrentUser = () => true,
	onReconcile = () => {},
} ) {
	const storageKey = getSavePersistenceStorageKey( restUrl, currentUserId );
	const candidates = new Map();
	const assurance = new Map();
	const outbox = new Map();
	const lookups = new Map();
	let timer = null;
	let inFlight = null;
	let disposed = false;
	const active = () => {
		try {
			return ! disposed && Boolean( storageKey ) && isCurrentUser();
		} catch {
			return false;
		}
	};

	try {
		const saved = JSON.parse( storage?.getItem( storageKey ) || 'null' );
		if ( saved?.version === STORAGE_VERSION ) {
			for ( const entry of saved.candidates || [] ) {
				const candidate = candidateMetadata( entry );
				if ( candidate ) {
					candidates.set( candidate.id, candidate );
				}
			}
			for ( const [ id, facts ] of saved.assurance || [] ) {
				assurance.set( id, facts );
			}
			for ( const job of saved.outbox || [] ) {
				if (
					job?.key &&
					buildSavePersistenceOutcome( {
						...job.entry,
						event: job.entry?.after?.outcome?.event,
					} )
				) {
					outbox.set( job.key, job );
				}
			}
			for ( const job of saved.lookups || [] ) {
				if (
					job?.key &&
					Array.isArray( job.applyIds ) &&
					job.attempts < MAX_LOOKUP_ATTEMPTS
				) {
					lookups.set( job.key, job );
				}
			}
		}
	} catch {
		// An unavailable/corrupt cache must never affect WordPress saves.
	}

	function persist() {
		if ( ! active() ) {
			return;
		}
		try {
			storage?.setItem(
				storageKey,
				JSON.stringify( {
					version: STORAGE_VERSION,
					candidates: [ ...candidates.values() ],
					assurance: [ ...assurance ],
					outbox: [ ...outbox.values() ],
					lookups: [ ...lookups.values() ],
				} )
			);
		} catch {
			// Keep the in-memory retry queue when browser storage is unavailable.
		}
	}

	function schedule() {
		if ( ! active() || timer !== null || inFlight ) {
			return;
		}
		const jobs = [ ...outbox.values(), ...lookups.values() ];
		if ( jobs.length ) {
			timer = setTimeout(
				() => {
					timer = null;
					void flush();
				},
				Math.max(
					0,
					Math.min( ...jobs.map( ( job ) => job.nextAttemptAt ) ) -
						Date.now()
				)
			);
		}
	}

	function cacheServerEntries( entries, notify = true ) {
		if ( ! active() ) {
			return;
		}
		const changed = [];
		for ( const entry of entries || [] ) {
			if ( ! candidates.has( entry?.id ) ) {
				continue;
			}
			const previous = assurance.get( entry.id ) || {};
			if (
				Number( entry.persistenceVerdict?.saveSequence || 0 ) <
				Number( previous.persistenceVerdict?.saveSequence || 0 )
			) {
				continue;
			}
			const fields = Object.fromEntries(
				ASSURANCE_FIELDS.filter(
					( key ) => entry[ key ] && typeof entry[ key ] === 'object'
				).map( ( key ) => [ key, copy( entry[ key ] ) ] )
			);
			if ( ! Object.keys( fields ).length ) {
				continue;
			}
			assurance.set( entry.id, { ...previous, ...fields } );
			changed.push( {
				id: entry.id,
				document: candidates.get( entry.id ).document,
				...assurance.get( entry.id ),
			} );
		}
		persist();
		if ( notify && changed.length ) {
			try {
				onReconcile( changed );
			} catch {
				/* Read-side feedback is best effort. */
			}
		}
	}

	function enqueueEvent( frozen, occurrenceId, event ) {
		if ( ! active() ) {
			return;
		}
		const timestamp = new Date().toISOString();
		for ( const candidate of frozen ) {
			const entry = buildSavePersistenceOutcome( {
				...candidate,
				event,
				linkedApplyActivityId: candidate.id,
				saveOccurrenceId: occurrenceId,
				timestamp,
			} );
			const key = `${ occurrenceId }:${ candidate.id }:${ event }`;
			if ( entry && ! outbox.has( key ) ) {
				outbox.set( key, {
					key,
					entry,
					attempts: 0,
					nextAttemptAt: Date.now(),
				} );
			}
		}
		persist();
		schedule();
	}

	function enqueueLookup( ids, occurrenceId, prefix = '' ) {
		if ( ! active() ) {
			return;
		}
		for ( let offset = 0; offset < ids.length; offset += LOOKUP_LIMIT ) {
			const key = `${ occurrenceId }:${ prefix }${ offset }`;
			if ( ! lookups.has( key ) ) {
				lookups.set( key, {
					key,
					occurrenceId,
					applyIds: ids.slice( offset, offset + LOOKUP_LIMIT ),
					attempts: 0,
					nextAttemptAt: Date.now(),
				} );
			}
		}
		persist();
		schedule();
	}

	async function deliver() {
		for ( const job of [ ...outbox.values() ] ) {
			if ( ! active() ) {
				return;
			}
			if ( job.nextAttemptAt > Date.now() ) {
				continue;
			}
			try {
				await fetch( {
					path: '/flavor-agent/v1/activity',
					method: 'POST',
					data: { entry: job.entry },
				} );
				outbox.delete( job.key );
				// A late original apply can become readable after an earlier lookup
				// returned no row. Refresh coverage; do not attach it to that capture.
				const applyId = job.entry.linkedApplyActivityId;
				if (
					job.attempts > 0 &&
					! [ ...lookups.values() ].some(
						( lookup ) =>
							lookup.occurrenceId ===
								job.entry.saveOccurrenceId &&
							lookup.applyIds.includes( applyId )
					)
				) {
					enqueueLookup(
						[ applyId ],
						job.entry.saveOccurrenceId,
						`retry:${ applyId }:`
					);
				}
			} catch ( error ) {
				if ( [ 401, 403 ].includes( errorStatus( error ) ) ) {
					outbox.delete( job.key );
				} else {
					job.attempts += 1;
					job.nextAttemptAt = Date.now() + retryDelay( job.attempts );
				}
			}
			persist();
		}
		for ( const job of [ ...lookups.values() ] ) {
			if ( ! active() ) {
				return;
			}
			if ( job.nextAttemptAt > Date.now() ) {
				continue;
			}
			job.attempts += 1;
			try {
				const query = new URLSearchParams( {
					applyIds: job.applyIds.join( ',' ),
					saveOccurrenceId: job.occurrenceId,
				} );
				const response = await fetch( {
					path: `/flavor-agent/v1/activity?${ query }`,
					method: 'GET',
				} );
				cacheServerEntries( response?.entries );
				// pending:false can mean the still-running save has not reached
				// its capture hook yet. Only evidence for this occurrence can end
				// that polling early; an exhausted window leaves facts unknown.
				const compared = job.applyIds.every( ( id ) =>
					response?.entries?.some(
						( entry ) =>
							entry.id === id &&
							entry.persistenceVerdict?.saveOccurrenceId ===
								job.occurrenceId
					)
				);
				if (
					( ! response?.pending && compared ) ||
					job.attempts >= MAX_LOOKUP_ATTEMPTS
				) {
					lookups.delete( job.key );
				} else {
					job.nextAttemptAt = Date.now() + retryDelay( job.attempts );
				}
			} catch ( error ) {
				if (
					[ 401, 403 ].includes( errorStatus( error ) ) ||
					job.attempts >= MAX_LOOKUP_ATTEMPTS
				) {
					lookups.delete( job.key );
				} else {
					job.nextAttemptAt = Date.now() + retryDelay( job.attempts );
				}
			}
			persist();
		}
	}

	function flush() {
		if ( ! active() ) {
			return Promise.resolve();
		}
		if ( inFlight ) {
			return inFlight;
		}
		if ( timer !== null ) {
			clearTimeout( timer );
			timer = null;
		}
		inFlight = deliver()
			.catch( () => {} )
			.finally( () => {
				inFlight = null;
				schedule();
			} );
		return inFlight;
	}

	function middleware( options, next ) {
		let entity;
		let occurrenceId;
		let known;
		let frozen;
		if ( ! active() ) {
			return next( options );
		}
		try {
			entity = matchEntitySaveRequest( options, getCoreData(), restUrl );
		} catch {
			entity = null;
		}
		if ( ! entity ) {
			return next( options );
		}
		try {
			occurrenceId = createOccurrenceId();
			known = [ ...candidates.values() ].filter( ( entry ) => {
				const target = candidateEntity( entry );
				return (
					entity.id &&
					target.id === entity.id &&
					target.postType === entity.postType
				);
			} );
			frozen = copy(
				known.filter(
					( entry ) =>
						! [ 'save_confirmed', 'save_discarded' ].includes(
							assurance.get( entry.id )?.persistenceVerdict?.state
						)
				)
			);
		} catch {
			return next( options );
		}
		const headers = {
			...Object.fromEntries(
				Object.entries( options.headers || {} ).filter(
					( [ name ] ) => name.toLowerCase() !== HEADER.toLowerCase()
				)
			),
			[ HEADER ]: occurrenceId,
		};
		enqueueEvent( frozen, occurrenceId, 'save_attempted' );
		// Persist the occurrence before submission: navigation can destroy the
		// save promise's handlers even after its attempt was delivered.
		enqueueLookup(
			known.map( ( entry ) => entry.id ),
			occurrenceId
		);
		let result;
		try {
			result = next( { ...options, headers } );
		} catch ( error ) {
			enqueueEvent( frozen, occurrenceId, 'save_failed' );
			enqueueLookup(
				known.map( ( entry ) => entry.id ),
				occurrenceId
			);
			throw error;
		}
		// Observe a separate branch and return the exact original promise.
		Promise.resolve( result )
			.then(
				() =>
					enqueueLookup(
						known.map( ( entry ) => entry.id ),
						occurrenceId
					),
				() => {
					enqueueEvent( frozen, occurrenceId, 'save_failed' );
					enqueueLookup(
						known.map( ( entry ) => entry.id ),
						occurrenceId
					);
				}
			)
			.catch( () => {} );
		return result;
	}

	schedule();
	return {
		middleware,
		flush,
		cacheServerEntries,
		getAssurance: ( id ) => copy( assurance.get( id ) || {} ),
		registerCandidate( entry ) {
			if ( ! active() ) {
				return;
			}
			const candidate = candidateMetadata( entry );
			if ( candidate ) {
				candidates.set( candidate.id, candidate );
				cacheServerEntries( [ entry ], false );
				persist();
			}
		},
		refreshCandidates( entries ) {
			for ( const entry of entries ) {
				if ( candidates.has( entry?.id ) ) {
					const candidate = candidateMetadata( entry );
					if ( candidate ) {
						candidates.set( entry.id, candidate );
					}
				}
			}
			cacheServerEntries( entries, false );
		},
		dispose() {
			disposed = true;
			if ( timer !== null ) {
				clearTimeout( timer );
				timer = null;
			}
		},
	};
}

let sharedController = null;
let sharedKey = '';
let installed = false;
let getCoreData = () => select( 'core' );
const listeners = new Set();

function currentSettings() {
	return typeof window === 'undefined' ? {} : window.flavorAgentData || {};
}

function getSharedController() {
	const { restUrl, currentUserId } = currentSettings();
	const key = getSavePersistenceStorageKey( restUrl, currentUserId );
	if ( key !== sharedKey ) {
		sharedController?.dispose();
		sharedController = null;
		sharedKey = key;
	}
	if ( ! key ) {
		return null;
	}
	if ( ! sharedController ) {
		let storage;
		try {
			storage = window.sessionStorage;
		} catch {
			/* Use in-memory fallback. */
		}
		sharedController = createSavePersistenceController( {
			apiFetch,
			getCoreData: () => getCoreData(),
			storage,
			restUrl,
			currentUserId,
			isCurrentUser: () =>
				getSavePersistenceStorageKey(
					currentSettings().restUrl,
					currentSettings().currentUserId
				) === key,
			onReconcile: ( entries ) =>
				listeners.forEach( ( listener ) => listener( entries ) ),
		} );
	}
	return sharedController;
}

export function registerSavePersistenceCandidate( entry ) {
	try {
		getSharedController()?.registerCandidate( entry );
	} catch {
		/* Activity creation must still succeed. */
	}
}

export function refreshSavePersistenceCandidates( entries ) {
	try {
		getSharedController()?.refreshCandidates( entries );
	} catch {
		/* The display cache is independent. */
	}
}

export function getSavePersistenceAssurance( id ) {
	return getSharedController()?.getAssurance( id ) || {};
}

export function installSavePersistenceObserver( {
	selectCore = () => select( 'core' ),
	onReconcile,
} = {} ) {
	getCoreData = selectCore;
	if ( onReconcile ) {
		listeners.add( onReconcile );
	}
	if ( ! installed ) {
		apiFetch.use( ( options, next ) => {
			const controller = getSharedController();
			return controller
				? controller.middleware( options, next )
				: next( options );
		} );
		installed = true;
	}
	getSharedController();
	return () => {
		if ( onReconcile ) {
			listeners.delete( onReconcile );
		}
	};
}
