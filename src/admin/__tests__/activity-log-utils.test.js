import {
	buildDecisionRequest,
	clampActivityViewPage,
	DEFAULT_ACTIVITY_VIEW,
	areActivityViewsEqual,
	buildActivityTargetLink,
	formatActivityTimestamp,
	getActivityStatusLabel,
	getExternalApplyDetails,
	isPendingExternalApply,
	normalizeActivityEntries,
	normalizeStoredActivityView,
	readPersistedActivityView,
	writePersistedActivityView,
} from '../activity-log-utils';

function createEntry( overrides = {} ) {
	return {
		id: 'activity-1',
		surface: 'block',
		timestamp: '2026-03-26T10:00:00Z',
		target: {
			blockName: 'core/paragraph',
			blockPath: [ 0 ],
		},
		document: {
			scopeKey: 'post:42',
			postType: 'post',
			entityId: '42',
		},
		undo: {
			status: 'available',
			canUndo: true,
			error: null,
			updatedAt: '2026-03-26T10:00:00Z',
			undoneAt: null,
		},
		persistence: {
			status: 'server',
		},
		request: {},
		before: {},
		after: {},
		...overrides,
	};
}

function createStorage() {
	const values = new Map();

	return {
		getItem( key ) {
			return values.has( key ) ? values.get( key ) : null;
		},
		setItem( key, value ) {
			values.set( key, value );
		},
	};
}

describe( 'activity log utils', () => {
	test( 'normalizeActivityEntries preserves server-resolved blocked status for admin rows', () => {
		const olderEntry = createEntry( {
			id: 'activity-older',
			timestamp: '2026-03-26T10:00:00Z',
			status: 'blocked',
		} );
		const newerEntry = createEntry( {
			id: 'activity-newer',
			timestamp: '2026-03-26T10:00:01Z',
			status: 'applied',
		} );

		const entries = normalizeActivityEntries( [ olderEntry, newerEntry ] );

		expect( entries[ 0 ].status ).toBe( 'blocked' );
		expect( entries[ 0 ].statusLabel ).toBe( 'Undo blocked' );
		expect( entries[ 1 ].status ).toBe( 'applied' );
		expect( entries[ 1 ].statusLabel ).toBe( 'Applied' );
	} );

	test( 'normalizeActivityEntries renders request diagnostics as review-only rows', () => {
		const entries = normalizeActivityEntries( [
			createEntry( {
				type: 'request_diagnostic',
				surface: 'navigation',
				suggestion: 'Group utility links',
				target: {
					clientId: 'nav-1',
					blockName: 'core/navigation',
				},
				document: {
					scopeKey: 'wp_template:theme//home',
					postType: 'wp_template',
					entityId: 'theme//home',
				},
				executionResult: 'review',
				undo: {
					status: 'review',
					canUndo: false,
					error: null,
					updatedAt: '2026-03-26T10:00:00Z',
					undoneAt: null,
				},
				request: {
					reference: 'navigation:wp_template:theme//home:nav-1',
				},
			} ),
		] );

		expect( entries[ 0 ] ).toMatchObject( {
			status: 'review',
			statusLabel: 'Review',
			activityTypeLabel: 'Record request',
			operationType: 'request-diagnostic',
			operationTypeLabel: 'Request diagnostic',
			surfaceLabel: 'Navigation',
		} );
	} );

	test( 'normalizeActivityEntries exposes allow-listed no-model request markers', () => {
		const entries = normalizeActivityEntries( [
			{
				type: 'request_diagnostic',
				after: {
					modelRequest: {
						attempted: false,
						reason: 'no_rankable_candidates',
					},
				},
				request: {
					ai: {
						requestToken: 'token-only',
					},
				},
			},
		] );

		expect( entries[ 0 ].modelRequest ).toEqual( {
			attempted: false,
			reason: 'no_rankable_candidates',
		} );
	} );

	test( 'normalizeActivityEntries drops malformed no-model request markers', () => {
		const entries = normalizeActivityEntries( [
			{
				type: 'request_diagnostic',
				after: {
					modelRequest: {
						attempted: true,
						reason: 'no_rankable_candidates',
					},
				},
			},
			{
				type: 'request_diagnostic',
				after: {
					modelRequest: {
						attempted: false,
						reason: 'not_allowed',
					},
				},
			},
		] );

		expect( entries[ 0 ].modelRequest ).toBeNull();
		expect( entries[ 1 ].modelRequest ).toBeNull();
	} );

	test( 'normalizeActivityEntries truncates verbose stored suggestion titles', () => {
		const entries = normalizeActivityEntries( [
			createEntry( {
				type: 'request_diagnostic',
				surface: 'block',
				suggestion:
					'With no mapped Inspector panels available, the most reliable improvements are structural: split the home-page shell into semantic sections and convert it to reusable section patterns so it naturally adopts the theme preset system.',
				undo: {
					status: 'review',
					canUndo: false,
				},
			} ),
		] );

		expect( entries[ 0 ].title.length ).toBeLessThanOrEqual( 96 );
		expect( entries[ 0 ].title ).toMatch(
			/^With no mapped Inspector panels available/
		);
		expect( entries[ 0 ].title ).toMatch( /\.\.\.$/ );
		expect( entries[ 0 ].title ).not.toContain(
			'convert it to reusable section patterns'
		);
	} );

	test( 'normalizeActivityEntries marks failed request diagnostics as request failures', () => {
		const entries = normalizeActivityEntries( [
			createEntry( {
				type: 'request_diagnostic',
				surface: 'content',
				suggestion: 'Content request failed: Missing draft context.',
				undo: {
					status: 'failed',
					canUndo: false,
					error: 'Missing draft context.',
					updatedAt: '2026-03-26T10:00:00Z',
					undoneAt: null,
				},
				request: {
					prompt: 'Critique this post.',
					reference: 'content:post:42',
				},
			} ),
		] );

		expect( entries[ 0 ] ).toMatchObject( {
			status: 'failed',
			statusLabel: 'Request failed',
			undoStatusLabel: 'Undo unavailable',
			undoReason: 'Missing draft context.',
			entity: 'Content',
		} );
	} );

	test( 'normalizeActivityEntries derives operation, document, path, and diff metadata', () => {
		const entries = normalizeActivityEntries( [
			createEntry( {
				request: {
					ai: {
						backendLabel: 'Azure OpenAI responses',
						providerLabel: 'Azure OpenAI',
						model: 'gpt-5.3-chat',
						pathLabel: 'Azure OpenAI via Settings > Flavor Agent',
						ownerLabel: 'Settings > Flavor Agent',
						credentialSourceLabel: 'Settings > Flavor Agent',
						selectedProviderLabel: 'Azure OpenAI',
						ability: 'flavor-agent/recommend-block',
						route: 'wp-abilities:flavor-agent/recommend-block',
						usedFallback: false,
						transport: {
							host: 'judas2.openai.azure.com',
							path: '/openai/v1/responses',
							timeoutSeconds: 180,
						},
						requestSummary: {
							bodyBytes: 18420,
							instructionsChars: 17200,
							inputChars: 512,
							reasoningEffort: 'high',
						},
						responseSummary: {
							httpStatus: 504,
							providerRequestId: 'apim-1234',
						},
						errorSummary: {
							wrappedMessage:
								'cURL error 28: Operation timed out after 180001 milliseconds with 0 bytes received',
						},
					},
					reference: 'block:block-1:4',
				},
				before: {
					attributes: {
						content: 'Before copy',
					},
				},
				after: {
					attributes: {
						content: 'After copy',
					},
				},
			} ),
		] );

		expect( entries[ 0 ] ).toMatchObject( {
			operationType: 'modify-attributes',
			operationTypeLabel: 'Modify attributes',
			postType: 'post',
			entityId: '42',
			blockPath: 'Paragraph · 1',
			provider: 'Azure OpenAI responses',
			model: 'gpt-5.3-chat',
			providerPath: 'Azure OpenAI via Settings > Flavor Agent',
			configurationOwner: 'Settings > Flavor Agent',
			credentialSource: 'Settings > Flavor Agent',
			selectedProvider: 'Azure OpenAI',
			connector: 'Not recorded',
			connectorPlugin: 'Not recorded',
			requestFallback: 'No fallback',
			requestAbility: 'flavor-agent/recommend-block',
			requestRoute: 'wp-abilities:flavor-agent/recommend-block',
			transportEndpoint: 'judas2.openai.azure.com/openai/v1/responses',
			timeout: '180 s',
			requestPayload:
				'18420 bytes · 17200 instruction chars · 512 input chars · reasoning high',
			responseSummary: 'HTTP 504',
			providerRequestId: 'apim-1234',
			transportError:
				'cURL error 28: Operation timed out after 180001 milliseconds with 0 bytes received',
			undoReason:
				'This is the newest still-applied AI action for this entity.',
		} );
		expect( entries[ 0 ].stateDiff ).toContain(
			'~ content: Before copy → After copy'
		);
		expect( entries[ 0 ].tokenUsage ).toBe( 'Not recorded' );
		expect( entries[ 0 ].latency ).toBe( 'Not recorded' );
	} );

	test( 'normalizeActivityEntries prefers canonical admin metadata for dashboard display', () => {
		const entries = normalizeActivityEntries( [
			createEntry( {
				before: {
					attributes: {
						content: 'Before copy',
					},
				},
				after: {
					attributes: {
						content: 'After copy',
					},
				},
				admin: {
					status: 'blocked',
					statusLabel: 'Undo blocked',
					surface: 'block',
					surfaceLabel: 'Block',
					postType: 'page',
					entityId: '99',
					blockPath: 'Group · 2',
					userId: 14,
					userLabel: 'Ada Lovelace',
					operationType: 'insert',
					operationTypeLabel: 'Insert pattern',
					provider: 'WordPress AI Client',
					model: 'provider-managed',
					providerPath:
						'WordPress AI Client via Settings > Connectors',
					configurationOwner: 'Settings > Connectors',
					credentialSource: 'Provider-managed',
					selectedProvider: 'Anthropic',
					requestAbility: 'flavor-agent/recommend-block',
					requestRoute: 'wp-abilities:flavor-agent/recommend-block',
					requestReference: 'block:99:2',
					requestPrompt: 'Use a stronger hero.',
				},
			} ),
		] );

		expect( entries[ 0 ] ).toMatchObject( {
			status: 'blocked',
			statusLabel: 'Undo blocked',
			surface: 'block',
			surfaceLabel: 'Block',
			operationType: 'insert',
			operationTypeLabel: 'Insert pattern',
			postType: 'page',
			entityId: '99',
			blockPath: 'Group · 2',
			userId: '14',
			user: 'Ada Lovelace',
			provider: 'WordPress AI Client',
			model: 'provider-managed',
			providerPath: 'WordPress AI Client via Settings > Connectors',
			configurationOwner: 'Settings > Connectors',
			credentialSource: 'Provider-managed',
			selectedProvider: 'Anthropic',
			requestAbility: 'flavor-agent/recommend-block',
			requestRoute: 'wp-abilities:flavor-agent/recommend-block',
			requestReference: 'block:99:2',
			requestPrompt: 'Use a stronger hero.',
		} );
		expect( entries[ 0 ].description ).toContain( 'Insert pattern' );
		expect( entries[ 0 ].description ).toContain( 'Group · 2' );
	} );

	test( 'normalizeActivityEntries records fallback provenance and blocked undo reason', () => {
		const entries = normalizeActivityEntries( [
			createEntry( {
				status: 'blocked',
				request: {
					ai: {
						backendLabel: 'WordPress AI Client',
						model: 'provider-managed',
						pathLabel:
							'WordPress AI Client via Settings > Connectors',
						ownerLabel: 'Settings > Connectors',
						selectedProviderLabel: 'Azure OpenAI',
						usedFallback: true,
					},
				},
				undo: {
					status: 'blocked',
					canUndo: false,
					error: 'Undo blocked by newer AI actions.',
				},
			} ),
		] );

		expect( entries[ 0 ] ).toMatchObject( {
			provider: 'WordPress AI Client',
			requestFallback: 'Fallback from selected Azure OpenAI.',
			undoReason: 'Undo blocked by newer AI actions.',
		} );
	} );

	test( 'normalizeActivityEntries exposes connector identity from stored request metadata', () => {
		const entries = normalizeActivityEntries( [
			createEntry( {
				request: {
					ai: {
						backendLabel: 'Anthropic',
						connectorLabel: 'Anthropic',
						connectorPluginSlug: 'ai-services-anthropic',
					},
				},
			} ),
		] );

		expect( entries[ 0 ] ).toMatchObject( {
			provider: 'Anthropic',
			connector: 'Anthropic',
			connectorPlugin: 'ai-services-anthropic',
		} );
	} );

	test( 'normalizeActivityEntries formats token usage and latency from request.ai metrics', () => {
		const entries = normalizeActivityEntries( [
			createEntry( {
				request: {
					ai: {
						backendLabel: 'Azure OpenAI responses',
						tokenUsage: {
							total: 96,
							input: 40,
							output: 56,
						},
						latencyMs: 275,
					},
				},
			} ),
		] );

		expect( entries[ 0 ] ).toMatchObject( {
			tokenUsage: '96 total tokens',
			latency: '275 ms',
		} );
	} );

	test( 'normalizeActivityEntries exposes core AI request log identifiers and link', () => {
		const entries = normalizeActivityEntries(
			[
				createEntry( {
					request: {
						ai: {
							requestToken:
								'7a85fe6b-ad73-4c0f-931b-0b0a70bc09c0',
							requestLogId:
								'c85ee60d-700b-48a7-b831-5784d5ad32b1',
						},
					},
				} ),
			],
			{
				adminBaseUrl: 'https://example.test/wp-admin/',
			}
		);

		expect( entries[ 0 ] ).toMatchObject( {
			aiRequestToken: '7a85fe6b-ad73-4c0f-931b-0b0a70bc09c0',
			aiRequestLogId: 'c85ee60d-700b-48a7-b831-5784d5ad32b1',
			aiRequestLogsUrl:
				'https://example.test/wp-admin/tools.php?page=ai-request-logs',
		} );
	} );

	test( 'normalizeActivityEntries does not misclassify unchanged structured style payloads as style edits', () => {
		const entries = normalizeActivityEntries( [
			createEntry( {
				before: {
					attributes: {
						style: {
							color: {
								text: 'red',
							},
						},
						content: 'Before copy',
					},
				},
				after: {
					attributes: {
						style: {
							color: {
								text: 'red',
							},
						},
						content: 'After copy',
					},
				},
			} ),
		] );

		expect( entries[ 0 ] ).toMatchObject( {
			operationType: 'modify-attributes',
			operationTypeLabel: 'Modify attributes',
		} );
		expect( entries[ 0 ].stateDiff ).toContain(
			'~ content: Before copy → After copy'
		);
	} );

	test( 'normalizeActivityEntries still classifies nested style changes as apply style', () => {
		const entries = normalizeActivityEntries( [
			createEntry( {
				before: {
					attributes: {
						style: {
							color: {
								text: 'red',
							},
						},
					},
				},
				after: {
					attributes: {
						style: {
							color: {
								text: 'blue',
							},
						},
					},
				},
			} ),
		] );

		expect( entries[ 0 ] ).toMatchObject( {
			operationType: 'apply-style',
			operationTypeLabel: 'Apply style',
		} );
		expect( entries[ 0 ].stateDiff ).toContain(
			'~ style.color.text: red → blue'
		);
	} );

	test( 'normalizeActivityEntries maps template operations to normalized action types', () => {
		const entries = normalizeActivityEntries( [
			createEntry( {
				type: 'apply_template_suggestion',
				surface: 'template',
				target: {
					templateRef: 'theme//home',
				},
				document: {
					scopeKey: 'wp_template:theme//home',
					postType: 'wp_template',
					entityId: 'theme//home',
				},
				after: {
					operations: [
						{
							type: 'insert_pattern',
							patternName: 'theme/hero',
						},
					],
				},
			} ),
		] );

		expect( entries[ 0 ] ).toMatchObject( {
			operationType: 'insert',
			operationTypeLabel: 'Insert pattern',
			activityTypeLabel: 'Apply template suggestion',
			postType: 'wp_template',
			entityId: 'theme//home',
		} );
	} );

	test( 'normalizeActivityEntries labels block structural apply rows distinctly from inline block applies', () => {
		const entries = normalizeActivityEntries( [
			createEntry( {
				type: 'apply_block_structural_suggestion',
				after: {
					operations: [
						{
							type: 'insert_pattern',
							patternName: 'theme/hero',
						},
					],
				},
			} ),
		] );

		expect( entries[ 0 ] ).toMatchObject( {
			activityTypeLabel: 'Apply block structural suggestion',
			operationType: 'insert',
			operationTypeLabel: 'Insert pattern',
		} );
	} );

	test( 'formatActivityTimestamp uses the same timezone basis for grouping and display', () => {
		const formatted = formatActivityTimestamp( '2026-03-27T00:30:00Z', {
			locale: 'en-US',
			timeZone: 'America/Los_Angeles',
		} );

		expect( formatted.dayKey ).toBe( '2026-03-26' );
		expect( formatted.timestampDisplay ).toContain( 'Mar 26' );
	} );

	test( 'buildActivityTargetLink uses honest styles labels for style surfaces', () => {
		const globalStylesLink = buildActivityTargetLink(
			createEntry( {
				surface: 'global-styles',
				target: {
					globalStylesId: '17',
				},
				document: {
					scopeKey: 'global_styles:17',
					postType: 'global_styles',
					entityId: '17',
				},
			} ),
			'https://example.test/wp-admin/'
		);
		const styleBookLink = buildActivityTargetLink(
			createEntry( {
				surface: 'style-book',
				target: {
					globalStylesId: '17',
					blockName: 'core/paragraph',
					blockTitle: 'Paragraph',
				},
				document: {
					scopeKey: 'style_book:17:core/paragraph',
					postType: 'global_styles',
					entityId: '17',
				},
			} ),
			'https://example.test/wp-admin/'
		);

		expect( globalStylesLink.label ).toBe( 'Open Styles' );
		expect( globalStylesLink.url ).toContain(
			'/wp-admin/site-editor.php?'
		);
		expect( styleBookLink.label ).toBe( 'Open Styles' );
		expect( styleBookLink.url ).toContain( '/wp-admin/site-editor.php?' );
	} );

	test( 'normalizeActivityEntries labels Style Book activity against the selected block target', () => {
		const entries = normalizeActivityEntries( [
			createEntry( {
				surface: 'style-book',
				target: {
					globalStylesId: '17',
					blockName: 'core/paragraph',
					blockTitle: 'Paragraph',
				},
				document: {
					scopeKey: 'style_book:17:core/paragraph',
					postType: 'global_styles',
					entityId: '17',
				},
			} ),
		] );

		expect( entries[ 0 ] ).toMatchObject( {
			surfaceLabel: 'Style Book',
		} );
		expect( entries[ 0 ].description ).toContain( 'Style Book' );
	} );

	test( 'persisted views round-trip through storage', () => {
		const storage = createStorage();
		const nextView = {
			...DEFAULT_ACTIVITY_VIEW,
			search: 'template',
			perPage: 50,
			groupBy: {
				field: 'surface',
				direction: 'asc',
				showLabel: true,
			},
		};

		writePersistedActivityView( nextView, storage );

		expect(
			areActivityViewsEqual(
				readPersistedActivityView( storage ),
				nextView
			)
		).toBe( true );
	} );

	test( 'normalizeStoredActivityView drops filters with unknown fields', () => {
		const normalized = normalizeStoredActivityView( {
			...DEFAULT_ACTIVITY_VIEW,
			filters: [
				{ field: 'surface', operator: 'is', value: 'block' },
				{ field: 'mysteryField', operator: 'is', value: 'foo' },
			],
		} );

		expect( normalized.filters ).toEqual( [
			{ field: 'surface', operator: 'is', value: 'block' },
		] );
	} );

	test( 'normalizeStoredActivityView coerces unsupported sort fields to timestamp', () => {
		const normalized = normalizeStoredActivityView( {
			...DEFAULT_ACTIVITY_VIEW,
			sort: {
				field: 'mysteryField',
				direction: 'asc',
			},
		} );

		expect( normalized.sort ).toEqual( {
			field: 'timestamp',
			direction: 'asc',
		} );
	} );

	test( 'normalizeStoredActivityView drops explicit-field filters with text operators', () => {
		const normalized = normalizeStoredActivityView( {
			...DEFAULT_ACTIVITY_VIEW,
			filters: [
				{ field: 'surface', operator: 'contains', value: 'bl' },
				{ field: 'status', operator: 'startsWith', value: 'app' },
			],
		} );

		expect( normalized.filters ).toEqual( [] );
	} );

	test( 'normalizeStoredActivityView drops text-field filters with explicit operators', () => {
		const normalized = normalizeStoredActivityView( {
			...DEFAULT_ACTIVITY_VIEW,
			filters: [
				{ field: 'entityId', operator: 'is', value: '42' },
				{ field: 'blockPath', operator: 'isNot', value: '0/' },
			],
		} );

		expect( normalized.filters ).toEqual( [] );
	} );

	test( 'normalizeStoredActivityView keeps valid text-field filters', () => {
		const normalized = normalizeStoredActivityView( {
			...DEFAULT_ACTIVITY_VIEW,
			filters: [
				{ field: 'entityId', operator: 'contains', value: '42' },
				{ field: 'blockPath', operator: 'startsWith', value: '0/' },
				{ field: 'entityId', operator: 'notContains', value: 'abc' },
			],
		} );

		expect( normalized.filters ).toEqual( [
			{ field: 'entityId', operator: 'contains', value: '42' },
			{ field: 'blockPath', operator: 'startsWith', value: '0/' },
			{ field: 'entityId', operator: 'notContains', value: 'abc' },
		] );
	} );

	test( 'normalizeStoredActivityView keeps valid day filters and drops day filters with non-day operators', () => {
		const normalized = normalizeStoredActivityView( {
			...DEFAULT_ACTIVITY_VIEW,
			filters: [
				{ field: 'day', operator: 'on', value: '2026-01-01' },
				{
					field: 'day',
					operator: 'between',
					value: [ '2026-01-01', '2026-01-31' ],
				},
				{
					field: 'day',
					operator: 'inThePast',
					value: { value: 7, unit: 'days' },
				},
				{ field: 'day', operator: 'is', value: '2026-01-01' },
				{ field: 'day', operator: 'contains', value: '2026' },
			],
		} );

		expect( normalized.filters ).toEqual( [
			{ field: 'day', operator: 'on', value: '2026-01-01' },
			{
				field: 'day',
				operator: 'between',
				value: [ '2026-01-01', '2026-01-31' ],
			},
			{
				field: 'day',
				operator: 'inThePast',
				value: { value: 7, unit: 'days' },
			},
		] );
	} );

	test( 'normalizeStoredActivityView drops malformed filter entries', () => {
		const normalized = normalizeStoredActivityView( {
			...DEFAULT_ACTIVITY_VIEW,
			filters: [
				{ field: 'surface', operator: 'is', value: 'block' },
				null,
				'not-an-object',
				{},
				{ field: 'surface' },
				{ operator: 'is', value: 'x' },
			],
		} );

		expect( normalized.filters ).toEqual( [
			{ field: 'surface', operator: 'is', value: 'block' },
		] );
	} );

	test( 'clampActivityViewPage keeps pagination in range', () => {
		expect(
			clampActivityViewPage(
				{
					...DEFAULT_ACTIVITY_VIEW,
					page: 5,
				},
				{
					totalPages: 2,
				}
			).page
		).toBe( 2 );
		expect(
			clampActivityViewPage(
				{
					...DEFAULT_ACTIVITY_VIEW,
					page: 2,
				},
				{
					totalPages: 4,
				}
			).page
		).toBe( 2 );
	} );

	test( 'clampActivityViewPage leaves page untouched when totalPages is unknown', () => {
		// Pre-fetch state: paginationInfo.totalPages is 0 because no REST response
		// has populated it yet. A persisted page > 1 must survive until real
		// pagination metadata arrives.
		expect(
			clampActivityViewPage(
				{
					...DEFAULT_ACTIVITY_VIEW,
					page: 3,
				},
				{
					totalPages: 0,
				}
			).page
		).toBe( 3 );
		expect(
			clampActivityViewPage( {
				...DEFAULT_ACTIVITY_VIEW,
				page: 4,
			} ).page
		).toBe( 4 );
	} );
} );

describe( 'external apply helpers', () => {
	test( 'isPendingExternalApply requires pending status and an apply payload', () => {
		expect(
			isPendingExternalApply( {
				status: 'pending',
				apply: { status: 'pending' },
			} )
		).toBe( true );
		expect( isPendingExternalApply( { status: 'applied' } ) ).toBe( false );
		expect( isPendingExternalApply( { status: 'pending' } ) ).toBe( false );
		expect( isPendingExternalApply( null ) ).toBe( false );
	} );

	test( 'getExternalApplyDetails normalizes the lifecycle payload', () => {
		const details = getExternalApplyDetails( {
			apply: {
				status: 'pending',
				requestedBy: 7,
				requestedAt: '2026-06-10T01:00:00+00:00',
				expiresAt: '2026-06-11T01:00:00+00:00',
				operations: [
					{ type: 'set_styles', path: [ 'color', 'text' ] },
				],
				requestReference: 'agent-req-1',
				decisionNote: 'note',
				failureCode: '',
			},
		} );

		expect( details.status ).toBe( 'pending' );
		expect( details.requestedBy ).toBe( 7 );
		expect( details.operations ).toHaveLength( 1 );
		expect( details.requestReference ).toBe( 'agent-req-1' );
		expect( getExternalApplyDetails( {} ).operations ).toEqual( [] );
	} );

	test( 'buildDecisionRequest shapes the REST call for apiFetch', () => {
		const request = buildDecisionRequest(
			{ restUrl: 'https://example.test/wp-json/', nonce: 'abc123' },
			'activity-9',
			'approve',
			'Looks safe'
		);

		expect( request.url ).toBe(
			'https://example.test/wp-json/flavor-agent/v1/activity/activity-9/decision'
		);
		expect( request.method ).toBe( 'POST' );
		expect( request.headers[ 'X-WP-Nonce' ] ).toBe( 'abc123' );
		expect( request.data ).toEqual( {
			decision: 'approve',
			note: 'Looks safe',
		} );
	} );

	test( 'status labels cover the external-apply lifecycle', () => {
		expect( getActivityStatusLabel( 'pending' ) ).toBe(
			'Pending approval'
		);
		expect( getActivityStatusLabel( 'rejected' ) ).toBe( 'Rejected' );
		expect( getActivityStatusLabel( 'expired' ) ).toBe( 'Expired' );
	} );

	test( 'normalizeActivityEntries passes the apply payload through', () => {
		const [ normalized ] = normalizeActivityEntries(
			[
				{
					id: 'activity-9',
					surface: 'global-styles',
					status: 'pending',
					timestamp: '2026-06-10T01:00:00+00:00',
					apply: { status: 'pending', operations: [] },
				},
			],
			{}
		);

		expect( normalized.apply ).toEqual( {
			status: 'pending',
			operations: [],
		} );
	} );
} );
