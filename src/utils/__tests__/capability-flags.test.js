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

	test( 'falls back to the connectors setup path when only legacy editor flags are available', () => {
		window.flavorAgentData = {
			canRecommendBlocks: false,
			settingsUrl:
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
			connectorsUrl:
				'https://example.test/wp-admin/options-connectors.php',
		};

		expect( getCapabilityNotice( 'block' ) ).toMatchObject( {
			status: 'warning',
			actionLabel: 'Open Connectors',
			actionHref: 'https://example.test/wp-admin/options-connectors.php',
			actions: [
				{
					label: 'Open Connectors',
					href: 'https://example.test/wp-admin/options-connectors.php',
				},
			],
		} );
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

	test( 'fails closed when no structured or legacy capability data exists', () => {
		window.flavorAgentData = {};

		expect( getSurfaceCapability( 'template-part' ) ).toMatchObject( {
			available: false,
			reason: 'plugin_provider_unconfigured',
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
			'Settings > Connectors'
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

	test( 'uses structured style-book metadata when the surface is not shipped yet', () => {
		window.flavorAgentData = {
			capabilities: {
				surfaces: {
					styleBook: {
						available: false,
						reason: 'surface_not_implemented',
						message:
							'Style Book recommendations are not available in this plugin build yet.',
						actions: [],
					},
				},
			},
		};

		expect( getSurfaceCapability( 'style-book' ) ).toMatchObject( {
			available: false,
			reason: 'surface_not_implemented',
			actionHref: '',
			actions: [],
		} );
		expect( getCapabilityNotice( 'style-book' ) ).toMatchObject( {
			status: 'warning',
			message:
				'Style Book recommendations are not available in this plugin build yet.',
			actions: [],
		} );
	} );
} );
