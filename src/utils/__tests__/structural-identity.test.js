import {
	annotateStructuralIdentity,
	buildStructuralContext,
} from '../structural-identity';

describe( 'structural-identity', () => {
	test( 'distinguishes navigation blocks by their template-part location', () => {
		const tree = [
			{
				clientId: 'header-part',
				name: 'core/template-part',
				title: 'Template Part',
				currentAttributes: { slug: 'header' },
				innerBlocks: [
					{
						clientId: 'header-nav',
						name: 'core/navigation',
						title: 'Navigation',
						currentAttributes: { ref: 11 },
						innerBlocks: [],
					},
				],
			},
			{
				clientId: 'footer-part',
				name: 'core/template-part',
				title: 'Template Part',
				currentAttributes: { slug: 'footer-newsletter' },
				innerBlocks: [
					{
						clientId: 'footer-nav',
						name: 'core/navigation',
						title: 'Navigation',
						currentAttributes: { ref: 22 },
						innerBlocks: [],
					},
				],
			},
		];

		const annotated = annotateStructuralIdentity( tree, {
			templatePartAreas: {
				header: 'header',
				'footer-newsletter': 'footer',
			},
		} );

		expect( annotated[ 0 ].innerBlocks[ 0 ].structuralIdentity.role ).toBe(
			'primary-navigation'
		);
		expect( annotated[ 1 ].innerBlocks[ 0 ].structuralIdentity.role ).toBe(
			'footer-navigation'
		);
		expect(
			annotated[ 1 ].innerBlocks[ 0 ].structuralIdentity.templateArea
		).toBe( 'footer' );
	} );

	test( 'distinguishes main and sidebar queries from tree evidence', () => {
		const tree = [
			{
				clientId: 'main-query',
				name: 'core/query',
				title: 'Query Loop',
				currentAttributes: {
					query: {
						inherit: true,
					},
				},
				innerBlocks: [],
			},
			{
				clientId: 'sidebar-part',
				name: 'core/template-part',
				title: 'Template Part',
				currentAttributes: { slug: 'sidebar' },
				innerBlocks: [
					{
						clientId: 'sidebar-query',
						name: 'core/query',
						title: 'Query Loop',
						currentAttributes: {
							query: {
								inherit: false,
							},
						},
						innerBlocks: [],
					},
				],
			},
		];

		const annotated = annotateStructuralIdentity( tree, {
			templatePartAreas: {
				sidebar: 'sidebar',
			},
		} );

		expect( annotated[ 0 ].structuralIdentity.role ).toBe( 'main-query' );
		expect( annotated[ 1 ].innerBlocks[ 0 ].structuralIdentity.role ).toBe(
			'sidebar-query'
		);
		expect( annotated[ 0 ].structuralIdentity.evidence ).toContain(
			'inherited-query'
		);
	} );

	test( 'buildStructuralContext returns the nearest structural branch', () => {
		const tree = [
			{
				clientId: 'header-part',
				name: 'core/template-part',
				title: 'Template Part',
				currentAttributes: { slug: 'header' },
				innerBlocks: [
					{
						clientId: 'header-group',
						name: 'core/group',
						title: 'Group',
						currentAttributes: {},
						innerBlocks: [
							{
								clientId: 'header-nav',
								name: 'core/navigation',
								title: 'Navigation',
								currentAttributes: { ref: 11 },
								innerBlocks: [],
							},
						],
					},
				],
			},
		];

		const context = buildStructuralContext( tree, 'header-nav', {
			templatePartAreas: {
				header: 'header',
			},
		} );

		expect( context.blockIdentity.role ).toBe( 'primary-navigation' );
		expect( context.structuralAncestors ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					block: 'core/template-part',
					role: 'header-slot',
				} ),
			] )
		);
		expect( context.branchRoot?.clientId ).toBe( 'header-part' );
	} );
} );
