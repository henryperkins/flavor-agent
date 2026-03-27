import { getCapabilityNotice, getSurfaceCapability } from '../capability-flags';

describe( 'capability-flags', () => {
	afterEach( () => {
		delete window.flavorAgentData;
	} );

	test( 'uses structured surface metadata when available', () => {
		window.flavorAgentData = {
			capabilities: {
				surfaces: {
					navigation: {
						available: false,
						reason: 'missing_theme_capability',
						message:
							'Navigation recommendations require the edit_theme_options capability.',
					},
				},
			},
		};

		expect( getSurfaceCapability( 'navigation' ) ).toMatchObject( {
			available: false,
			reason: 'missing_theme_capability',
			actionHref: '',
		} );
		expect( getCapabilityNotice( 'navigation' ) ).toMatchObject( {
			status: 'info',
			message:
				'Navigation recommendations require the edit_theme_options capability.',
			actions: [],
		} );
	} );

	test( 'falls back to both block setup paths when only legacy editor flags are available', () => {
		window.flavorAgentData = {
			canRecommendBlocks: false,
			settingsUrl:
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
			connectorsUrl:
				'https://example.test/wp-admin/options-connectors.php',
		};

		expect( getCapabilityNotice( 'block' ) ).toMatchObject( {
			status: 'warning',
			actionLabel: 'Open Flavor Agent settings',
			actionHref:
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
			actions: [
				{
					label: 'Open Flavor Agent settings',
					href: 'https://example.test/wp-admin/options-general.php?page=flavor-agent',
				},
				{
					label: 'Open Connectors',
					href: 'https://example.test/wp-admin/options-connectors.php',
				},
			],
		} );
		expect( getCapabilityNotice( 'block' )?.message ).toContain(
			'Settings > Flavor Agent'
		);
		expect( getCapabilityNotice( 'block' )?.message ).toContain(
			'Settings > Connectors'
		);
	} );

	test( 'does not fabricate configuration links when structured capabilities intentionally omit them', () => {
		window.flavorAgentData = {
			canManageFlavorAgentSettings: false,
			settingsUrl:
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
			connectorsUrl:
				'https://example.test/wp-admin/options-connectors.php',
			capabilities: {
				surfaces: {
					block: {
						available: false,
						reason: 'block_backend_unconfigured',
						message:
							'Block recommendations are not configured yet. Ask an administrator to configure Flavor Agent or Connectors for this site.',
						actions: [],
					},
				},
			},
		};

		expect( getCapabilityNotice( 'block' ) ).toMatchObject( {
			status: 'warning',
			actionLabel: '',
			actionHref: '',
			actions: [],
		} );
	} );

	test( 'suppresses legacy fallback links when the boot data says settings are inaccessible', () => {
		window.flavorAgentData = {
			canManageFlavorAgentSettings: false,
			canRecommendTemplateParts: false,
			settingsUrl:
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
		};

		expect( getCapabilityNotice( 'template-part' ) ).toMatchObject( {
			status: 'warning',
			actionLabel: '',
			actionHref: '',
			actions: [],
		} );
	} );

	test( 'returns template-part settings guidance when the provider is unavailable', () => {
		window.flavorAgentData = {
			canRecommendTemplateParts: false,
			settingsUrl:
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
		};

		expect( getCapabilityNotice( 'template-part' ) ).toMatchObject( {
			status: 'warning',
			actionLabel: 'Open Flavor Agent settings',
			actionHref:
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
		} );
		expect( getCapabilityNotice( 'template-part' )?.message ).toContain(
			'Settings > Flavor Agent'
		);
	} );

	test( 'returns pattern settings guidance when plugin ranking backends are unavailable', () => {
		window.flavorAgentData = {
			canRecommendPatterns: false,
			settingsUrl:
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
		};

		expect( getCapabilityNotice( 'pattern' ) ).toMatchObject( {
			status: 'warning',
			actionLabel: 'Open Flavor Agent settings',
			actionHref:
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
		} );
		expect( getCapabilityNotice( 'pattern' )?.message ).toContain(
			'Settings > Flavor Agent'
		);
	} );
} );
