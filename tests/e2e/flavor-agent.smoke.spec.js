const { test, expect } = require('@playwright/test');

const BLOCK_RESPONSE = {
	payload: {
		settings: [],
		styles: [],
		block: [
			{
				label: 'Update content',
				attributeUpdates: {
					content: 'Hello from Flavor Agent',
				},
			},
		],
		explanation: 'Mocked block recs',
	},
};
const PATTERN_REASON = 'Recommended for this content block.';
const NAVIGATION_PROMPT = 'Simplify the header navigation.';
const TEMPLATE_PROMPT =
	'Make this template read more like an editorial front page.';
const TEMPLATE_INSERTED_CONTENT = 'Inserted by Flavor Agent';
const TEMPLATE_PATTERN_NAME = 'flavor-agent/editorial-banner';
const TEMPLATE_PATTERN_TITLE = 'Editorial Banner';
const TEMPLATE_MAIN_CONTENT_TARGET_PATH = [1, 0];
const TEMPLATE_MAIN_CONTENT_TARGET = {
	name: 'core/heading',
	label: 'Heading',
};
const TEMPLATE_PART_PROMPT = 'Add a compact utility row before the navigation.';
const TEMPLATE_PART_INSERTED_CONTENT =
	'Inserted into the template part by Flavor Agent';
const TEMPLATE_PART_PATTERN_NAME = 'flavor-agent/header-utility-row';
const TEMPLATE_PART_PATTERN_TITLE = 'Header Utility Row';
const GLOBAL_STYLES_PROMPT =
	'Warm the canvas slightly and tighten the site-wide vertical rhythm.';
const GLOBAL_STYLES_BACKGROUND_VALUE = 'var:preset|color|signal';
const GLOBAL_STYLES_LINE_HEIGHT_VALUE = 1.73;
const GLOBAL_STYLES_SIDEBAR_SELECTOR =
	'.editor-global-styles-sidebar__panel, .editor-global-styles-sidebar, [role="region"][aria-label="Styles"]';
const GLOBAL_STYLES_RESPONSE = {
	explanation:
		'Use the theme signal preset for the canvas and tighten line height slightly.',
	suggestions: [
		{
			label: 'Adjust canvas tone and rhythm',
			description:
				'Apply the signal canvas preset and tighten the global line height.',
			category: 'color',
			tone: 'executable',
			operations: [
				{
					type: 'set_styles',
					path: ['color', 'background'],
					value: GLOBAL_STYLES_BACKGROUND_VALUE,
					valueType: 'preset',
					presetType: 'color',
					presetSlug: 'signal',
					cssVar: 'var(--wp--preset--color--signal)',
				},
				{
					type: 'set_styles',
					path: ['typography', 'lineHeight'],
					value: GLOBAL_STYLES_LINE_HEIGHT_VALUE,
					valueType: 'freeform',
				},
			],
		},
	],
};

async function dismissWelcomeGuide(page) {
	const welcomeOverlay = page.locator('.components-modal__screen-overlay');

	await page
		.evaluate(() => {
			const preferences = window.wp?.data?.dispatch('core/preferences');

			preferences?.set?.('core/edit-post', 'welcomeGuide', false);
			preferences?.set?.('core/edit-post', 'welcomeGuideTemplate', false);
			preferences?.set?.('core/edit-site', 'welcomeGuide', false);
			preferences?.set?.('core/edit-site', 'welcomeGuideTemplate', false);
			preferences?.set?.('core/edit-site', 'welcomeGuideStyles', false);
		})
		.catch(() => {});

	for (let attempt = 0; attempt < 4; attempt++) {
		if (await welcomeOverlay.isVisible().catch(() => false)) {
			break;
		}

		await page.waitForTimeout(250);
	}

	for (let attempt = 0; attempt < 4; attempt++) {
		const isVisible = await welcomeOverlay.isVisible().catch(() => false);

		if (!isVisible) {
			return;
		}

		const closeButton = welcomeOverlay
			.getByRole('button', { name: 'Close' })
			.first();
		const getStartedButton = welcomeOverlay
			.getByRole('button', { name: 'Get started' })
			.first();

		if (await closeButton.isVisible().catch(() => false)) {
			await closeButton.click();
		} else if (await getStartedButton.isVisible().catch(() => false)) {
			await getStartedButton.click();
		} else {
			await page.keyboard.press('Escape').catch(() => {});
		}

		await page.waitForTimeout(250);
	}

	await expect(welcomeOverlay).toBeHidden({ timeout: 10000 });
}

async function dismissSiteEditorWelcomeGuide(page) {
	await dismissWelcomeGuide(page);
}

async function mockGlobalStylesRecommendations(page, styleRequests) {
	await page.route('**/*recommend-style*', async (route) => {
		styleRequests.push(route.request().postDataJSON());
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify(GLOBAL_STYLES_RESPONSE),
		});
	});
}

async function waitForWordPressReady(page) {
	for (let attempt = 0; attempt < 12; attempt++) {
		const loadingText = page.getByText('WordPress is not ready yet');

		if (!(await loadingText.count())) {
			return;
		}

		await page.waitForTimeout(1000);
		await page.reload({ waitUntil: 'domcontentloaded' });
	}

	await expect(page.getByText('WordPress is not ready yet')).toHaveCount(0);
}

async function waitForFlavorAgent(page) {
	await page.waitForFunction(() =>
		Boolean(window.wp?.data?.select('flavor-agent')),
	);
}

async function getCurrentPostEditUrl(page) {
	return page.evaluate(() => {
		const editor = window.wp?.data?.select('core/editor');
		const postId = editor?.getCurrentPostId?.();

		if (!postId) {
			return window.location.pathname + window.location.search;
		}

		return `/wp-admin/post.php?post=${postId}&action=edit`;
	});
}

async function saveCurrentPost(page) {
	await page.evaluate(() => {
		return window.wp?.data?.dispatch('core/editor')?.savePost?.();
	});

	await expect
		.poll(() =>
			page.evaluate(() => ({
				isAutosaving:
					window.wp?.data?.select('core/editor')?.isAutosavingPost?.() || false,
				isSaving:
					window.wp?.data?.select('core/editor')?.isSavingPost?.() || false,
			})),
		)
		.toEqual({
			isAutosaving: false,
			isSaving: false,
		});
}

async function seedParagraphBlock(page) {
	const canvas = page.frameLocator('iframe').first();
	const defaultBlockButton = canvas.getByRole('button', {
		name: /Add default block|Type \/ to choose a block/i,
	});

	await page.evaluate(() => {
		window.flavorAgentData.canRecommendBlocks = true;
		window.wp?.data?.dispatch('core/editor')?.editPost({
			title: 'Smoke Test',
		});
	});
	await expect(
		canvas.getByRole('textbox', { name: 'Add title' }),
	).toBeVisible();
	await dismissWelcomeGuide(page);

	if (await defaultBlockButton.count()) {
		await defaultBlockButton.click();
	} else {
		await canvas.locator('body').click();
	}

	await page.keyboard.type('Hello world');
	await expect
		.poll(() =>
			page.evaluate(() => {
				const blocks =
					window.wp?.data?.select('core/block-editor')?.getBlocks?.() || [];
				const paragraph = blocks.find(
					(block) => block?.name === 'core/paragraph',
				);

				return paragraph?.attributes?.content || '';
			}),
		)
		.toContain('Hello world');

	return page.evaluate(() => {
		return (
			window.wp?.data
				?.select('core/block-editor')
				?.getSelectedBlockClientId?.() ||
			window.wp?.data?.select('core/block-editor')?.getBlockOrder?.()[0] ||
			null
		);
	});
}

async function seedNavigationBlock(page) {
	await page.evaluate(() => {
		const { createBlock } = window.wp.blocks;
		const navigationLink = createBlock('core/navigation-link', {
			label: 'Home',
			url: '/',
		});
		const navigationBlock = createBlock(
			'core/navigation',
			{
				overlayMenu: 'mobile',
			},
			[navigationLink],
		);

		window.flavorAgentData.canRecommendNavigation = true;
		window.wp?.data?.dispatch('core/editor')?.editPost({
			title: 'Navigation Smoke',
		});
		window.wp?.data
			?.dispatch('core/block-editor')
			?.resetBlocks?.([navigationBlock]);
		window.wp?.data
			?.dispatch('core/block-editor')
			?.selectBlock?.(navigationBlock.clientId);
	});

	await expect
		.poll(() =>
			page.evaluate(() => {
				const blockEditor = window.wp?.data?.select('core/block-editor');
				const block = blockEditor?.getBlocks?.()?.[0] || null;

				return {
					name: block?.name || '',
					selectedClientId: blockEditor?.getSelectedBlockClientId?.() || null,
				};
			}),
		)
		.toEqual(
			expect.objectContaining({
				name: 'core/navigation',
			}),
		);

	return page.evaluate(
		() =>
			window.wp?.data
				?.select('core/block-editor')
				?.getSelectedBlockClientId?.() || null,
	);
}

async function ensureSettingsSidebarOpen(page) {
	await dismissWelcomeGuide(page);

	await page.evaluate(() => {
		window.wp?.data
			?.dispatch('core/edit-post')
			?.openGeneralSidebar?.('edit-post/block');
	});

	await page.waitForFunction(
		() =>
			window.wp?.data
				?.select('core/edit-post')
				?.getActiveGeneralSidebarName?.() === 'edit-post/block',
	);

	const blockTab = page.getByRole('tab', {
		name: 'Block',
		exact: true,
	});

	if (await blockTab.isVisible().catch(() => false)) {
		await blockTab.click();
	}

	const inspectorSettingsTab = page
		.getByRole('region', { name: 'Editor settings' })
		.getByRole('tab', {
			name: 'Settings',
			exact: true,
		});

	if (await inspectorSettingsTab.isVisible().catch(() => false)) {
		await inspectorSettingsTab.click();
	}
}

async function ensurePanelOpen(page, title, content) {
	if (await content.isVisible().catch(() => false)) {
		return;
	}

	const toggle = page
		.locator(
			`button:has-text("${title}"), [role="button"]:has-text("${title}")`,
		)
		.first();

	await expect(toggle).toBeVisible();

	if ((await toggle.getAttribute('aria-expanded')) !== 'true') {
		await toggle.click();
	}

	await expect(content).toBeVisible();
}

function getVisibleSearchInput(page) {
	return page
		.locator('[role="searchbox"]:visible, input[type="search"]:visible')
		.first();
}

async function getTemplateTarget(page) {
	return page.evaluate(() => {
		function inferArea(attributes) {
			if (typeof attributes?.area === 'string' && attributes.area) {
				return attributes.area;
			}

			if (
				typeof attributes?.slug === 'string' &&
				window.flavorAgentData?.templatePartAreas?.[attributes.slug]
			) {
				return window.flavorAgentData.templatePartAreas[attributes.slug];
			}

			if (
				attributes?.slug === 'header' ||
				attributes?.slug === 'footer' ||
				attributes?.slug === 'sidebar'
			) {
				return attributes.slug;
			}

			return '';
		}

		function findTemplatePart(blocks) {
			let fallback = null;

			for (const block of blocks) {
				if (block?.name === 'core/template-part') {
					const candidate = {
						clientId: block.clientId,
						slug: block.attributes?.slug || '',
						area: inferArea(block.attributes),
					};

					if (candidate.slug && candidate.area) {
						return candidate;
					}

					if (!fallback && candidate.slug) {
						fallback = candidate;
					}
				}

				if (block?.innerBlocks?.length) {
					const nested = findTemplatePart(block.innerBlocks);

					if (nested) {
						return nested;
					}
				}
			}

			return fallback;
		}

		const blockEditor = window.wp?.data?.select('core/block-editor');
		const editSite = window.wp?.data?.select('core/edit-site');
		const templatePart = findTemplatePart(blockEditor?.getBlocks?.() || []);

		if (!templatePart?.slug) {
			return null;
		}

		return {
			templateRef: editSite?.getEditedPostId?.() || null,
			templatePart,
		};
	});
}

async function openFirstTemplateEditor(page) {
	const templatesNavButton = page.getByRole('button', {
		name: 'Templates',
		exact: true,
	});

	if (await templatesNavButton.count()) {
		await templatesNavButton.click();
	}

	await expect(page.getByRole('region', { name: 'Templates' })).toBeVisible();

	const templateButton = page
		.getByRole('button', {
			name: 'Blog Home',
			exact: true,
		})
		.first();

	await expect(templateButton).toBeVisible();
	await templateButton.click();
	await page.waitForFunction(
		() =>
			window.wp?.data?.select('core/edit-site')?.getEditedPostType?.() ===
				'wp_template' &&
			Boolean(window.wp?.data?.select('core/edit-site')?.getEditedPostId?.()),
	);
	await waitForFlavorAgent(page);
}

function buildTemplatePartRefFromTemplateTarget(templateTarget) {
	const templateRef = templateTarget?.templateRef || '';
	const slug = templateTarget?.templatePart?.slug || '';
	const themePrefix = templateRef.includes('//')
		? templateRef.slice(0, templateRef.indexOf('//'))
		: '';

	if (!themePrefix || !slug) {
		return null;
	}

	return `${themePrefix}//${slug}`;
}

function formatTemplatePartTitle(templatePartRef) {
	const slug = templatePartRef.includes('//')
		? templatePartRef.slice(templatePartRef.indexOf('//') + 2)
		: templatePartRef;

	return slug
		.split(/[-_]/)
		.filter(Boolean)
		.map((part) => part.charAt(0).toUpperCase() + part.slice(1))
		.join(' ');
}

async function openTemplatePartEditor(page, templatePartRef) {
	const waitForTemplatePartEditor = async () =>
		page.waitForFunction(
			(nextTemplatePartRef) =>
				window.wp?.data?.select('core/edit-site')?.getEditedPostType?.() ===
					'wp_template_part' &&
				window.wp?.data?.select('core/edit-site')?.getEditedPostId?.() ===
					nextTemplatePartRef,
			templatePartRef,
			{ timeout: 10000 },
		);

	await page.goto(
		`/wp-admin/site-editor.php?postType=wp_template_part&postId=${encodeURIComponent(
			templatePartRef,
		)}`,
		{
			waitUntil: 'domcontentloaded',
		},
	);
	await waitForWordPressReady(page);
	await waitForFlavorAgent(page);
	await dismissWelcomeGuide(page);
	await dismissSiteEditorWelcomeGuide(page);

	const openedDirectly = await waitForTemplatePartEditor()
		.then(() => true)
		.catch(() => false);

	if (openedDirectly) {
		return;
	}

	const title = formatTemplatePartTitle(templatePartRef);
	const templatePartCard = page
		.getByRole('button', {
			name: title,
			exact: true,
		})
		.first();

	await expect(templatePartCard).toBeVisible();
	await templatePartCard.click();
	await waitForWordPressReady(page);
	await waitForFlavorAgent(page);
	await dismissWelcomeGuide(page);
	await dismissSiteEditorWelcomeGuide(page);
	await waitForTemplatePartEditor();
}

async function enableTemplateDocumentSidebar(page) {
	await enableSiteEditorDocumentSidebar(page);
	await expect(
		page.getByRole('tab', { name: 'Template', exact: true }),
	).toBeVisible();
}

async function enableSiteEditorDocumentSidebar(page) {
	await page.evaluate(() => {
		window.wp.data
			.dispatch('core/preferences')
			.set('core/edit-site', 'welcomeGuideTemplate', true);
		window.wp.data
			.dispatch('core/interface')
			.enableComplementaryArea('core/edit-site', 'edit-post/document');
	});
}

async function enableSiteEditorGlobalStylesSidebar(page) {
	await dismissSiteEditorWelcomeGuide(page);

	const stylesLauncher = page.getByRole('button', {
		name: 'Styles',
		exact: true,
	});

	if (await stylesLauncher.count()) {
		await stylesLauncher.first().click();
	}

	await page.evaluate(() => {
		window.wp?.data
			?.dispatch('core/interface')
			?.enableComplementaryArea?.('core/edit-site', 'edit-site/global-styles');
	});
	await page.waitForFunction(
		(selector) => {
			const activeArea = window.wp?.data
				?.select('core/interface')
				?.getActiveComplementaryArea?.('core');

			return (
				activeArea === 'edit-site/global-styles' ||
				Boolean(document.querySelector(selector))
			);
		},
		GLOBAL_STYLES_SIDEBAR_SELECTOR,
	);
	await page.waitForFunction(
		(selector) => Boolean(document.querySelector(selector)),
		GLOBAL_STYLES_SIDEBAR_SELECTOR,
	);
}

async function getGlobalStylesState(page) {
	return page.evaluate(() => {
		function normalizeValue(value) {
			if (Array.isArray(value)) {
				return value.map((item) =>
					normalizeValue(item === undefined ? null : item),
				);
			}

			if (value && typeof value === 'object') {
				return Object.fromEntries(
					Object.entries(value)
						.filter(([, entryValue]) => entryValue !== undefined)
						.sort(([leftKey], [rightKey]) => leftKey.localeCompare(rightKey))
						.map(([key, entryValue]) => [key, normalizeValue(entryValue)]),
				);
			}

			return value;
		}

		const core = window.wp?.data?.select('core');
		const flavorAgent = window.wp?.data?.select('flavor-agent');
		const globalStylesId =
			core?.__experimentalGetCurrentGlobalStylesId?.() || null;
		const record = globalStylesId
			? core?.getEditedEntityRecord?.('root', 'globalStyles', globalStylesId) ||
			  core?.getEntityRecord?.('root', 'globalStyles', globalStylesId) ||
			  null
			: null;
		const activityLog = flavorAgent?.getActivityLog?.() || [];
		const lastActivity = activityLog[activityLog.length - 1] || null;

		return {
			globalStylesId: globalStylesId ? String(globalStylesId) : null,
			settings: normalizeValue(record?.settings || {}),
			styles: normalizeValue(record?.styles || {}),
			background: record?.styles?.color?.background || '',
			lineHeight: record?.styles?.typography?.lineHeight ?? null,
			applyStatus: flavorAgent?.getGlobalStylesApplyStatus?.() || '',
			undoStatus: flavorAgent?.getUndoStatus?.() || '',
			activityType: lastActivity?.type || '',
		};
	});
}

async function openTemplatePartRecommendationsPanel(page) {
	const promptInput = page.getByPlaceholder(
		'Describe the structure or layout you want.',
	);

	await ensurePanelOpen(page, 'AI Template Part Recommendations', promptInput);

	return promptInput;
}

async function registerTemplatePattern(
	page,
	{ insertedContent, patternName, patternTitle },
) {
	await page.evaluate(
		({
			insertedContent: nextInsertedContent,
			patternName: nextPatternName,
			patternTitle: nextPatternTitle,
		}) => {
			const blockEditorDispatch = window.wp.data.dispatch('core/block-editor');
			const blockEditorSelect = window.wp.data.select('core/block-editor');
			const settings = blockEditorSelect.getSettings?.() || {};
			const existingPatterns = Array.isArray(
				settings.__experimentalBlockPatterns,
			)
				? settings.__experimentalBlockPatterns.filter(
						(pattern) => pattern?.name !== nextPatternName,
				  )
				: [];

			blockEditorDispatch.updateSettings({
				__experimentalBlockPatterns: [
					...existingPatterns,
					{
						name: nextPatternName,
						title: nextPatternTitle,
						content: `<!-- wp:paragraph --><p>${nextInsertedContent}</p><!-- /wp:paragraph -->`,
					},
				],
			});
		},
		{
			insertedContent,
			patternName,
			patternTitle,
		},
	);
}

async function openTemplateRecommendationsPanel(page) {
	const promptInput = page.getByPlaceholder(
		'Describe the structure or layout you want.',
	);

	await ensurePanelOpen(page, 'AI Template Recommendations', promptInput);

	return promptInput;
}

async function getTemplateInsertState(page, insertedContent) {
	return page.evaluate(
		({ nextInsertedContent }) => {
			function normalizeValue(value) {
				if (Array.isArray(value)) {
					return value.map((item) =>
						normalizeValue(item === undefined ? null : item),
					);
				}

				if (value && typeof value === 'object') {
					return Object.fromEntries(
						Object.entries(value)
							.filter(([, entryValue]) => entryValue !== undefined)
							.sort(([leftKey], [rightKey]) => leftKey.localeCompare(rightKey))
							.map(([key, entryValue]) => [key, normalizeValue(entryValue)]),
					);
				}

				return value;
			}

			function normalizeBlockSnapshot(block) {
				return {
					name: block?.name || '',
					attributes: normalizeValue(block?.attributes || {}),
					innerBlocks: Array.isArray(block?.innerBlocks)
						? block.innerBlocks.map(normalizeBlockSnapshot)
						: [],
				};
			}

			function getBlockByPath(blocks, path = []) {
				let currentBlocks = blocks;
				let block = null;

				for (const index of path) {
					if (!Array.isArray(currentBlocks)) {
						return null;
					}

					block = currentBlocks[index] || null;

					if (!block) {
						return null;
					}

					currentBlocks = block.innerBlocks || [];
				}

				return block;
			}

			function resolveRootBlocks(blocks, rootLocator) {
				if (
					!rootLocator ||
					rootLocator.type === 'root' ||
					(Array.isArray(rootLocator.path) && rootLocator.path.length === 0)
				) {
					return blocks;
				}

				const rootBlock = getBlockByPath(blocks, rootLocator.path || []);

				return Array.isArray(rootBlock?.innerBlocks)
					? rootBlock.innerBlocks
					: [];
			}

			function hasInsertedParagraph(blocks) {
				const flavorAgent = window.wp.data.select('flavor-agent');
				const activityLog = flavorAgent.getActivityLog?.() || [];
				const lastActivity = activityLog[activityLog.length - 1] || null;
				const insertOperation =
					(lastActivity?.after?.operations || []).find(
						(operation) => operation?.type === 'insert_pattern',
					) || null;

				if (
					insertOperation?.rootLocator &&
					Number.isInteger(insertOperation?.index) &&
					Array.isArray(insertOperation?.insertedBlocksSnapshot)
				) {
					const rootBlocks = resolveRootBlocks(
						blocks,
						insertOperation.rootLocator,
					);
					const slice = rootBlocks.slice(
						insertOperation.index,
						insertOperation.index +
							insertOperation.insertedBlocksSnapshot.length,
					);

					return (
						JSON.stringify(slice.map(normalizeBlockSnapshot)) ===
						JSON.stringify(insertOperation.insertedBlocksSnapshot)
					);
				}

				for (const block of blocks) {
					const content = String(block?.attributes?.content || '');

					if (
						block?.name === 'core/paragraph' &&
						content.includes(nextInsertedContent)
					) {
						return true;
					}

					if (
						Array.isArray(block?.innerBlocks) &&
						hasInsertedParagraph(block.innerBlocks)
					) {
						return true;
					}
				}

				return false;
			}

			const flavorAgent = window.wp.data.select('flavor-agent');
			const activityLog = flavorAgent.getActivityLog?.() || [];
			const lastActivity = activityLog[activityLog.length - 1] || null;
			const blocks =
				window.wp.data.select('core/block-editor').getBlocks?.() || [];

			return {
				hasInsertedContent: hasInsertedParagraph(blocks),
				undoStatus: lastActivity?.undo?.status || '',
			};
		},
		{ nextInsertedContent: insertedContent },
	);
}

async function getTemplatePartInsertState(page, insertedContent) {
	return page.evaluate(
		({ nextInsertedContent }) => {
			function normalizeValue(value) {
				if (Array.isArray(value)) {
					return value.map((item) =>
						normalizeValue(item === undefined ? null : item),
					);
				}

				if (value && typeof value === 'object') {
					return Object.fromEntries(
						Object.entries(value)
							.filter(([, entryValue]) => entryValue !== undefined)
							.sort(([leftKey], [rightKey]) => leftKey.localeCompare(rightKey))
							.map(([key, entryValue]) => [key, normalizeValue(entryValue)]),
					);
				}

				return value;
			}

			function normalizeBlockSnapshot(block) {
				return {
					name: block?.name || '',
					attributes: normalizeValue(block?.attributes || {}),
					innerBlocks: Array.isArray(block?.innerBlocks)
						? block.innerBlocks.map(normalizeBlockSnapshot)
						: [],
				};
			}

			function hasInsertedParagraph(blocks) {
				for (const block of blocks) {
					const content = String(block?.attributes?.content || '');

					if (
						block?.name === 'core/paragraph' &&
						content.includes(nextInsertedContent)
					) {
						return true;
					}

					if (
						Array.isArray(block?.innerBlocks) &&
						hasInsertedParagraph(block.innerBlocks)
					) {
						return true;
					}
				}

				return false;
			}

			const flavorAgent = window.wp.data.select('flavor-agent');
			const activityLog = (flavorAgent.getActivityLog?.() || []).filter(
				(entry) => entry?.surface === 'template-part',
			);
			const lastActivity = activityLog[activityLog.length - 1] || null;
			const blocks =
				window.wp.data.select('core/block-editor').getBlocks?.() || [];
			const lastOperation =
				(lastActivity?.after?.operations || []).find(
					(operation) =>
						operation?.type === 'insert_pattern' ||
						operation?.type === 'replace_block_with_pattern',
				) || null;
			const insertedBlocksSnapshot = Array.isArray(
				lastOperation?.insertedBlocksSnapshot,
			)
				? lastOperation.insertedBlocksSnapshot
				: [];
			let hasInsertedContent = hasInsertedParagraph(blocks);

			if (
				!hasInsertedContent &&
				insertedBlocksSnapshot.length > 0 &&
				lastOperation?.rootLocator &&
				Number.isInteger(lastOperation?.index)
			) {
				let currentBlocks = blocks;

				if (
					lastOperation.rootLocator.type === 'block' &&
					Array.isArray(lastOperation.rootLocator.path) &&
					lastOperation.rootLocator.path.length > 0
				) {
					let rootBlock = null;

					for (const index of lastOperation.rootLocator.path) {
						rootBlock = currentBlocks[index] || null;

						if (!rootBlock) {
							currentBlocks = [];
							break;
						}

						currentBlocks = rootBlock.innerBlocks || [];
					}
				}

				const slice = currentBlocks.slice(
					lastOperation.index,
					lastOperation.index + insertedBlocksSnapshot.length,
				);

				hasInsertedContent =
					JSON.stringify(slice.map(normalizeBlockSnapshot)) ===
					JSON.stringify(insertedBlocksSnapshot);
			}

			return {
				hasInsertedContent,
				undoStatus: lastActivity?.undo?.status || '',
			};
		},
		{ nextInsertedContent: insertedContent },
	);
}

async function selectFirstNavigationBlock(page) {
	return page.evaluate(() => {
		function findNavigation(blocks) {
			for (const block of blocks) {
				if (block?.name === 'core/navigation') {
					return block;
				}

				if (Array.isArray(block?.innerBlocks)) {
					const nested = findNavigation(block.innerBlocks);

					if (nested) {
						return nested;
					}
				}
			}

			return null;
		}

		const blockEditor = window.wp?.data?.select('core/block-editor');
		const navigationBlock = findNavigation(blockEditor?.getBlocks?.() || []);

		if (!navigationBlock?.clientId) {
			return null;
		}

		window.wp.data
			.dispatch('core/block-editor')
			.selectBlock(navigationBlock.clientId);

		return {
			clientId: navigationBlock.clientId,
			menuId: navigationBlock.attributes?.ref || null,
		};
	});
}

test('block inspector smoke applies, persists, and undoes AI recommendations', async ({
	page,
}) => {
	await page.goto('/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	});
	await waitForWordPressReady(page);
	await waitForFlavorAgent(page);
	await dismissWelcomeGuide(page);

	const clientId = await seedParagraphBlock(page);
	await ensureSettingsSidebarOpen(page);

	const promptInput = page.getByPlaceholder('What are you trying to achieve?');

	await ensurePanelOpen(page, 'AI Recommendations', promptInput);
	await expect(
		page.getByRole('button', { name: 'Get Suggestions' }),
	).toBeVisible();

	await page.evaluate(
		({ selectedClientId, payload }) => {
			window.wp.data
				.dispatch('flavor-agent')
				.setBlockRecommendations(selectedClientId, {
					blockName: 'core/paragraph',
					blockContext: { name: 'core/paragraph' },
					...payload,
				});
		},
		{
			selectedClientId: clientId,
			payload: BLOCK_RESPONSE.payload,
		},
	);

	await expect(
		page.getByText(BLOCK_RESPONSE.payload.explanation, {
			exact: true,
		}),
	).toBeVisible();

	const suggestionButton = page.getByRole('button', {
		name: 'Update content',
		exact: true,
	});

	await expect(suggestionButton).toBeVisible();
	await expect(suggestionButton).toBeEnabled();
	await suggestionButton.click();

	await expect
		.poll(() =>
			page.evaluate(
				({ selectedClientId }) => {
					return (
						window.wp.data
							.select('core/block-editor')
							.getBlockAttributes?.(selectedClientId)?.content || ''
					);
				},
				{ selectedClientId: clientId },
			),
		)
		.toBe('Hello from Flavor Agent');

	await expect(page.getByText('Recent AI Actions')).toBeVisible();
	await expect
		.poll(() =>
			page.evaluate(
				() =>
					window.wp?.data?.select('core/editor')?.getCurrentPostId?.() || null,
			),
		)
		.toBeTruthy();
	await saveCurrentPost(page);

	const editUrl = await getCurrentPostEditUrl(page);

	await page.goto(editUrl, {
		waitUntil: 'domcontentloaded',
	});
	await waitForWordPressReady(page);
	await waitForFlavorAgent(page);
	await dismissWelcomeGuide(page);
	await ensureSettingsSidebarOpen(page);
	await page.waitForFunction(
		() =>
			(window.wp?.data?.select('core/block-editor')?.getBlocks?.() || [])
				.length > 0,
	);
	await page.evaluate(() => {
		const blockEditor = window.wp.data.select('core/block-editor');
		const paragraph = (blockEditor.getBlocks?.() || []).find(
			(block) => block?.name === 'core/paragraph',
		);

		if (paragraph?.clientId) {
			window.wp.data
				.dispatch('core/block-editor')
				.selectBlock(paragraph.clientId);
		}
	});

	const refreshedPromptInput = page.getByPlaceholder(
		'What are you trying to achieve?',
	);

	await ensurePanelOpen(page, 'AI Recommendations', refreshedPromptInput);
	await page
		.locator('.flavor-agent-activity-row')
		.getByRole('button', { name: 'Undo', exact: true })
		.click();

	await expect
		.poll(() =>
			page.evaluate(() => {
				const flavorAgent = window.wp.data.select('flavor-agent');
				const blockEditor = window.wp.data.select('core/block-editor');
				const paragraph = (blockEditor.getBlocks?.() || []).find(
					(block) => block?.name === 'core/paragraph',
				);
				const activityLog = flavorAgent.getActivityLog?.() || [];
				const lastActivity = activityLog[activityLog.length - 1] || null;

				return {
					content: paragraph?.attributes?.content || '',
					undoStatus: lastActivity?.undo?.status || '',
				};
			}),
		)
		.toEqual({
			content: 'Hello world',
			undoStatus: 'undone',
		});
});

test('block and pattern surfaces explain unavailable providers in native UI', async ({
	page,
}) => {
	await page.goto('/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	});
	await waitForWordPressReady(page);
	await waitForFlavorAgent(page);
	await dismissWelcomeGuide(page);

	await page.evaluate(() => {
		const data = window.flavorAgentData || {};

		data.canRecommendBlocks = false;
		data.canRecommendPatterns = false;

		if (data.capabilities?.surfaces?.block) {
			data.capabilities.surfaces.block.available = false;
			data.capabilities.surfaces.block.reason = 'block_backend_unconfigured';
		}

		if (data.capabilities?.surfaces?.pattern) {
			data.capabilities.surfaces.pattern.available = false;
			data.capabilities.surfaces.pattern.reason =
				'pattern_backend_unconfigured';
		}

		window.wp?.data?.dispatch('flavor-agent')?.setPatternRecommendations?.([]);
		window.wp?.data?.dispatch('flavor-agent')?.setPatternStatus?.('idle');
	});
	await seedParagraphBlock(page);
	await ensureSettingsSidebarOpen(page);

	const promptInput = page.getByPlaceholder('What are you trying to achieve?');

	await ensurePanelOpen(page, 'AI Recommendations', promptInput);
	const recommendationsPanel = promptInput.locator(
		'xpath=ancestor::*[contains(concat(" ", normalize-space(@class), " "), " components-panel__body ")][1]',
	);
	await expect(
		recommendationsPanel.getByRole('link', {
			name: 'Settings > Flavor Agent',
		}),
	).toBeVisible();
	await expect(
		recommendationsPanel.getByRole('link', {
			name: 'Settings > Connectors',
		}),
	).toBeVisible();
	await expect(
		recommendationsPanel.getByRole('button', { name: 'Get Suggestions' }),
	).toBeDisabled();

	await page
		.getByRole('button', {
			name: 'Block Inserter',
			exact: true,
		})
		.click();

	await expect(
		page
			.locator('.flavor-agent-pattern-notice')
			.getByText('Pattern recommendations rely on Flavor Agent'),
	).toBeVisible();
	await expect(
		page
			.locator('.flavor-agent-pattern-notice')
			.getByRole('link', { name: 'Settings > Flavor Agent' }),
	).toBeVisible();
});

test('navigation surface smoke renders advisory recommendations for a selected navigation block', async ({
	page,
}) => {
	const navigationRequests = [];

	await page.route('**/*recommend-navigation*', async (route) => {
		navigationRequests.push(route.request().postDataJSON());
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				explanation: 'Keep utility links together and simplify the top level.',
				suggestions: [
					{
						label: 'Group utility links',
						description: 'Move account and contact items into one submenu.',
						category: 'structure',
						changes: [
							{
								type: 'group',
								target: 'Account and Contact',
								detail: 'Keep the top level shorter.',
							},
						],
					},
				],
			}),
		});
	});

	await page.goto('/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	});
	await waitForWordPressReady(page);
	await waitForFlavorAgent(page);
	await dismissWelcomeGuide(page);
	await seedNavigationBlock(page);
	await ensureSettingsSidebarOpen(page);

	const promptInput = page.getByPlaceholder(
		'Describe the structure or behavior you want.',
	);

	await ensurePanelOpen(page, 'AI Recommendations', promptInput);
	const recommendationsPanel = promptInput.locator(
		'xpath=ancestor::*[contains(concat(" ", normalize-space(@class), " "), " components-panel__body ")][1]',
	);
	await promptInput.fill(NAVIGATION_PROMPT);
	await recommendationsPanel
		.getByRole('button', { name: 'Get Navigation Suggestions' })
		.click();

	await expect.poll(() => navigationRequests.length).toBe(1);
	expect(navigationRequests[0].prompt).toBe(NAVIGATION_PROMPT);
	expect(navigationRequests[0].navigationMarkup).toContain('wp:navigation');

	const navigationSummarySection = recommendationsPanel
		.locator('.flavor-agent-advisory-section')
		.first();

	await expect(
		navigationSummarySection.getByText('Navigation recommendations'),
	).toBeVisible();
	await expect(
		navigationSummarySection.getByText('Advisory only', { exact: true }),
	).toBeVisible();
	await expect(
		recommendationsPanel.getByText(
			'Keep utility links together and simplify the top level.',
		),
	).toBeVisible();
	await expect(
		recommendationsPanel.getByText('Group utility links', { exact: true }),
	).toBeVisible();
});

test('pattern surface smoke uses the inserter search to fetch recommendations', async ({
	page,
}) => {
	const patternRequests = [];

	await page.route('**/*recommend-patterns*', async (route) => {
		patternRequests.push(route.request().postDataJSON());
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				recommendations: [
					{
						name: 'playground/recommended-pattern',
						score: 0.97,
						reason: PATTERN_REASON,
					},
				],
			}),
		});
	});

	await page.goto('/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	});
	await waitForWordPressReady(page);
	await waitForFlavorAgent(page);
	await dismissWelcomeGuide(page);

	await page.waitForFunction(() =>
		Boolean(window.flavorAgentData?.canRecommendPatterns),
	);
	await seedParagraphBlock(page);
	const searchPrompt = 'hero';

	await expect.poll(() => patternRequests.length > 0).toBe(true);

	await page
		.getByRole('button', {
			name: 'Block Inserter',
			exact: true,
		})
		.click();

	const searchInput = getVisibleSearchInput(page);

	await expect(searchInput).toBeVisible();
	await searchInput.fill(searchPrompt);

	await expect.poll(() => patternRequests.length >= 2).toBe(true);

	const activeRequest = patternRequests.at(-1);

	expect(activeRequest.prompt).toBe(searchPrompt);
	expect(activeRequest.blockContext).toEqual({
		blockName: 'core/paragraph',
	});

	await expect(
		page.getByLabel('1 pattern recommendation available'),
	).toBeVisible();
});

test('@wp70-site-editor global styles surface previews, applies, and undoes executable recommendations', async ({
	page,
}) => {
	const styleRequests = [];

	await mockGlobalStylesRecommendations(page, styleRequests);

	await page.goto('/wp-admin/site-editor.php', {
		waitUntil: 'domcontentloaded',
	});
	await waitForWordPressReady(page);
	await waitForFlavorAgent(page);
	await dismissWelcomeGuide(page);
	await dismissSiteEditorWelcomeGuide(page);
	await page.waitForFunction(() =>
		Boolean(window.flavorAgentData?.canRecommendGlobalStyles),
	);
	await page.waitForFunction(() =>
		Boolean(
			window.wp?.data
				?.select('core')
				?.__experimentalGetCurrentGlobalStylesId?.(),
		),
	);
	await enableSiteEditorGlobalStylesSidebar(page);

	const initialState = await getGlobalStylesState(page);

	expect(initialState.globalStylesId).toBeTruthy();

	const promptInput = page.getByLabel('Describe the style direction');

	await expect(promptInput).toBeVisible();
	await promptInput.fill(GLOBAL_STYLES_PROMPT);
	await page.getByRole('button', { name: 'Get Style Suggestions' }).click();

	await expect.poll(() => styleRequests.length).toBe(1);
	expect(styleRequests[0].prompt).toBe(GLOBAL_STYLES_PROMPT);
	expect(styleRequests[0].scope.surface).toBe('global-styles');
	expect(styleRequests[0].scope.globalStylesId).toBe(
		initialState.globalStylesId,
	);
	expect(styleRequests[0].styleContext).toHaveProperty('themeTokenDiagnostics');

	await expect(page.getByText('Adjust canvas tone and rhythm')).toBeVisible();
	await page.getByRole('button', { name: 'Review', exact: true }).click();
	await expect(page.getByText('Review Before Apply')).toBeVisible();
	await page
		.getByRole('button', { name: 'Apply Style Change', exact: true })
		.click();

	await expect
		.poll(() => getGlobalStylesState(page))
		.toEqual(
			expect.objectContaining({
				globalStylesId: initialState.globalStylesId,
				background: GLOBAL_STYLES_BACKGROUND_VALUE,
				lineHeight: GLOBAL_STYLES_LINE_HEIGHT_VALUE,
				applyStatus: 'success',
				activityType: 'apply_global_styles_suggestion',
			}),
		);

	await expect(page.getByText('Recent AI Style Actions')).toBeVisible();
	await page
		.locator('.flavor-agent-activity-row')
		.getByRole('button', { name: 'Undo', exact: true })
		.click();

	await expect
		.poll(() => getGlobalStylesState(page))
		.toEqual(
			expect.objectContaining({
				globalStylesId: initialState.globalStylesId,
				settings: initialState.settings,
				styles: initialState.styles,
				undoStatus: 'success',
				}),
			);
});

test('@wp70-site-editor global styles surface requests defaults when the prompt is empty', async ({
	page,
}) => {
	const styleRequests = [];

	await mockGlobalStylesRecommendations(page, styleRequests);

	await page.goto('/wp-admin/site-editor.php', {
		waitUntil: 'domcontentloaded',
	});
	await waitForWordPressReady(page);
	await waitForFlavorAgent(page);
	await dismissWelcomeGuide(page);
	await dismissSiteEditorWelcomeGuide(page);
	await page.waitForFunction(() =>
		Boolean(window.flavorAgentData?.canRecommendGlobalStyles),
	);
	await page.waitForFunction(() =>
		Boolean(
			window.wp?.data
				?.select('core')
				?.__experimentalGetCurrentGlobalStylesId?.(),
		),
	);
	await enableSiteEditorGlobalStylesSidebar(page);

	const initialState = await getGlobalStylesState(page);
	const promptInput = page.getByLabel('Describe the style direction');

	await expect(promptInput).toBeVisible();
	await page.getByRole('button', { name: 'Get Style Suggestions' }).click();

	await expect.poll(() => styleRequests.length).toBe(1);
	expect(styleRequests[0].scope.surface).toBe('global-styles');
	expect(styleRequests[0].scope.globalStylesId).toBe(
		initialState.globalStylesId,
	);
	expect(styleRequests[0].styleContext).toHaveProperty(
		'themeTokenDiagnostics',
	);
	expect(styleRequests[0]).not.toHaveProperty('prompt');

	await expect(page.getByText('Adjust canvas tone and rhythm')).toBeVisible();
});

test('template surface smoke previews and applies executable template recommendations', async ({
	page,
}) => {
	let templateTarget = null;
	const templateRequests = [];

	await page.route('**/*recommend-template*', async (route) => {
		templateRequests.push(route.request().postDataJSON());

		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				explanation: `Insert ${TEMPLATE_PATTERN_TITLE} into the template flow.`,
				suggestions: [
					{
						label: 'Clarify template hierarchy',
						description: `Insert ${TEMPLATE_PATTERN_TITLE} into the template flow.`,
						operations: [
							{
								type: 'insert_pattern',
								patternName: TEMPLATE_PATTERN_NAME,
							},
						],
						templateParts: [],
						patternSuggestions: [TEMPLATE_PATTERN_NAME],
					},
				],
			}),
		});
	});

	await page.goto('/wp-admin/site-editor.php', {
		waitUntil: 'domcontentloaded',
	});
	await waitForWordPressReady(page);
	await waitForFlavorAgent(page);
	await dismissWelcomeGuide(page);
	await openFirstTemplateEditor(page);
	await dismissSiteEditorWelcomeGuide(page);

	await page.waitForFunction(
		() =>
			Boolean(window.flavorAgentData?.canRecommendTemplates) &&
			window.wp?.data?.select('core/edit-site')?.getEditedPostType?.() ===
				'wp_template',
	);
	await page.waitForFunction(() => {
		return (
			window.wp?.data?.select('core/block-editor')?.getBlocks?.().length > 0
		);
	});

	templateTarget = await getTemplateTarget(page);
	expect(templateTarget).toBeTruthy();

	await enableTemplateDocumentSidebar(page);
	await registerTemplatePattern(page, {
		insertedContent: TEMPLATE_INSERTED_CONTENT,
		patternName: TEMPLATE_PATTERN_NAME,
		patternTitle: TEMPLATE_PATTERN_TITLE,
	});

	const promptInput = await openTemplateRecommendationsPanel(page);
	await promptInput.fill(TEMPLATE_PROMPT);
	await page.getByRole('button', { name: 'Get Suggestions' }).click();

	await expect.poll(() => templateRequests.length).toBe(1);
	expect(templateRequests[0].templateRef).toBe(templateTarget.templateRef);
	expect(templateRequests[0].prompt).toBe(TEMPLATE_PROMPT);
	expect(templateRequests[0]).toHaveProperty('visiblePatternNames');
	expect(templateRequests[0].visiblePatternNames).toContain(
		TEMPLATE_PATTERN_NAME,
	);

	await expect(page.getByText('Suggested Composition')).toBeVisible();
	await page.getByRole('button', { name: 'Preview Apply' }).click();
	await expect(page.getByText('Review Before Apply')).toBeVisible();
	await page.evaluate(() => {
		window.wp.data.dispatch('core/block-editor').clearSelectedBlock();
	});
	await page.getByRole('button', { name: 'Confirm Apply' }).click();

	await expect
		.poll(() =>
			page.evaluate(
				({ patternName }) => {
					const flavorAgent = window.wp.data.select('flavor-agent');
					const operations =
						flavorAgent.getTemplateLastAppliedOperations?.() || [];
					const activityLog = flavorAgent.getActivityLog?.() || [];
					const lastActivity = activityLog[activityLog.length - 1] || null;

					return {
						applyStatus: flavorAgent.getTemplateApplyStatus?.() || '',
						hasInsertOperation: operations.some(
							(operation) =>
								operation?.type === 'insert_pattern' &&
								operation?.patternName === patternName,
						),
						lastActivityType: lastActivity?.type || '',
					};
				},
				{
					patternName: TEMPLATE_PATTERN_NAME,
				},
			),
		)
		.toEqual({
			applyStatus: 'success',
			hasInsertOperation: true,
			lastActivityType: 'apply_template_suggestion',
		});

	await page.getByRole('tab', { name: 'Template', exact: true }).click();
	await openTemplateRecommendationsPanel(page);
	await expect(page.getByText('Recent AI Actions')).toBeVisible();
	await expect(page.locator('.flavor-agent-activity-row')).toContainText(
		'Clarify template hierarchy',
	);
});

test('template surface explains unavailable plugin backends', async ({
	page,
}) => {
	await page.goto('/wp-admin/site-editor.php', {
		waitUntil: 'domcontentloaded',
	});
	await waitForWordPressReady(page);
	await waitForFlavorAgent(page);
	await dismissWelcomeGuide(page);

	await page.evaluate(() => {
		const data = window.flavorAgentData || {};

		data.canRecommendTemplates = false;

		if (data.capabilities?.surfaces?.template) {
			data.capabilities.surfaces.template.available = false;
			data.capabilities.surfaces.template.reason =
				'plugin_provider_unconfigured';
		}
	});
	await openFirstTemplateEditor(page);
	await dismissSiteEditorWelcomeGuide(page);
	await page.waitForFunction(
		() =>
			window.wp?.data?.select('core/edit-site')?.getEditedPostType?.() ===
			'wp_template',
	);
	await enableTemplateDocumentSidebar(page);
	await page.getByRole('tab', { name: 'Template', exact: true }).click();

	const templateNotice = page
		.locator('.flavor-agent-capability-notice .flavor-agent-panel__note')
		.filter({ hasText: 'Template recommendations rely on Flavor Agent' });

	await ensurePanelOpen(page, 'AI Template Recommendations', templateNotice);
	await expect(
		page.getByRole('link', { name: 'Settings > Flavor Agent' }),
	).toBeVisible();
	await expect(
		page.getByPlaceholder('Describe the structure or layout you want.'),
	).toHaveCount(0);
});

test('@wp70-site-editor template-part surface smoke previews, applies, and undoes executable recommendations', async ({
	page,
}) => {
	const templatePartRequests = [];

	await page.route('**/*recommend-template-part*', async (route) => {
		templatePartRequests.push(route.request().postDataJSON());
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				explanation: 'Add a compact utility row at the end of the header part.',
				suggestions: [
					{
						label: 'Add utility row',
						description: 'Insert a compact row at the end of this header part.',
						blockHints: [
							{
								path: [0],
								label: 'Header wrapper',
								reason: 'Keep the insertion inside the existing container.',
							},
						],
						patternSuggestions: [TEMPLATE_PART_PATTERN_NAME],
						operations: [
							{
								type: 'insert_pattern',
								patternName: TEMPLATE_PART_PATTERN_NAME,
								placement: 'end',
							},
						],
					},
				],
			}),
		});
	});

	await page.goto('/wp-admin/site-editor.php', {
		waitUntil: 'domcontentloaded',
	});
	await waitForWordPressReady(page);
	await waitForFlavorAgent(page);
	await dismissWelcomeGuide(page);
	await openFirstTemplateEditor(page);
	await dismissSiteEditorWelcomeGuide(page);

	const templateTarget = await getTemplateTarget(page);
	const templatePartRef =
		buildTemplatePartRefFromTemplateTarget(templateTarget);

	expect(templatePartRef).toBeTruthy();

	await openTemplatePartEditor(page, templatePartRef);
	await page.waitForFunction(
		() =>
			Boolean(window.flavorAgentData?.canRecommendTemplateParts) &&
			window.wp?.data?.select('core/edit-site')?.getEditedPostType?.() ===
				'wp_template_part',
	);
	await page.waitForFunction(
		() =>
			(window.wp?.data?.select('core/block-editor')?.getBlocks?.() || [])
				.length > 0,
	);

	await enableSiteEditorDocumentSidebar(page);
	await registerTemplatePattern(page, {
		insertedContent: TEMPLATE_PART_INSERTED_CONTENT,
		patternName: TEMPLATE_PART_PATTERN_NAME,
		patternTitle: TEMPLATE_PART_PATTERN_TITLE,
	});

	const promptInput = await openTemplatePartRecommendationsPanel(page);
	await promptInput.fill(TEMPLATE_PART_PROMPT);
	await page.getByRole('button', { name: 'Get Suggestions' }).click();

	await expect.poll(() => templatePartRequests.length).toBe(1);
	expect(templatePartRequests[0].templatePartRef).toBe(templatePartRef);
	expect(templatePartRequests[0].prompt).toBe(TEMPLATE_PART_PROMPT);
	expect(templatePartRequests[0]).toHaveProperty('visiblePatternNames');
	expect(templatePartRequests[0].visiblePatternNames).toContain(
		TEMPLATE_PART_PATTERN_NAME,
	);

	await expect(page.getByText('Suggested Composition')).toBeVisible();
	await page.getByRole('button', { name: 'Preview Apply' }).click();
	await expect(page.getByText('Review Before Apply')).toBeVisible();
	await page.getByRole('button', { name: 'Confirm Apply' }).click();

	await expect
		.poll(() =>
			getTemplatePartInsertState(page, TEMPLATE_PART_INSERTED_CONTENT),
		)
		.toEqual({
			hasInsertedContent: true,
			undoStatus: 'available',
		});

	await page.getByRole('tab', { name: 'Template Part', exact: true }).click();
	await openTemplatePartRecommendationsPanel(page);
	await expect(page.getByText('Recent AI Actions')).toBeVisible();
	await page
		.locator('.flavor-agent-activity-row')
		.getByRole('button', { name: 'Undo', exact: true })
		.click();

	await expect
		.poll(() =>
			getTemplatePartInsertState(page, TEMPLATE_PART_INSERTED_CONTENT),
		)
		.toEqual({
			hasInsertedContent: false,
			undoStatus: 'undone',
		});
});

test('@wp70-site-editor template undo survives a Site Editor refresh when the template has not drifted', async ({
	page,
}) => {
	await page.route('**/*recommend-template*', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				explanation: `Insert ${TEMPLATE_PATTERN_TITLE} into the template flow.`,
				suggestions: [
					{
						label: 'Clarify template hierarchy',
						description: `Insert ${TEMPLATE_PATTERN_TITLE} into the template flow.`,
						operations: [
							{
								type: 'insert_pattern',
								patternName: TEMPLATE_PATTERN_NAME,
								placement: 'before_block_path',
								targetPath: TEMPLATE_MAIN_CONTENT_TARGET_PATH,
								expectedTarget: TEMPLATE_MAIN_CONTENT_TARGET,
							},
						],
						templateParts: [],
						patternSuggestions: [TEMPLATE_PATTERN_NAME],
					},
				],
			}),
		});
	});

	await page.goto('/wp-admin/site-editor.php', {
		waitUntil: 'domcontentloaded',
	});
	await waitForWordPressReady(page);
	await waitForFlavorAgent(page);
	await dismissWelcomeGuide(page);
	await openFirstTemplateEditor(page);
	await dismissSiteEditorWelcomeGuide(page);
	await page.waitForFunction(
		() =>
			Boolean(window.flavorAgentData?.canRecommendTemplates) &&
			window.wp?.data?.select('core/edit-site')?.getEditedPostType?.() ===
				'wp_template',
	);
	await page.waitForFunction(
		() =>
			(window.wp?.data?.select('core/block-editor')?.getBlocks?.() || [])
				.length > 0,
	);

	await enableTemplateDocumentSidebar(page);
	await registerTemplatePattern(page, {
		insertedContent: TEMPLATE_INSERTED_CONTENT,
		patternName: TEMPLATE_PATTERN_NAME,
		patternTitle: TEMPLATE_PATTERN_TITLE,
	});

	const promptInput = await openTemplateRecommendationsPanel(page);
	await promptInput.fill(TEMPLATE_PROMPT);
	await page.getByRole('button', { name: 'Get Suggestions' }).click();
	await expect(page.getByText('Suggested Composition')).toBeVisible();
	await page.getByRole('button', { name: 'Preview Apply' }).click();
	await page.evaluate(() => {
		window.wp.data.dispatch('core/block-editor').clearSelectedBlock();
	});
	await page.getByRole('button', { name: 'Confirm Apply' }).click();
	const templateEditorUrl = page.url();

	await expect
		.poll(
			async () =>
				(await getTemplateInsertState(page, TEMPLATE_INSERTED_CONTENT))
					.undoStatus,
		)
		.toBe('available');
	await saveCurrentPost(page);

	await page.goto('/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	});
	await waitForWordPressReady(page);
	await page.goto(templateEditorUrl, {
		waitUntil: 'domcontentloaded',
	});
	await waitForWordPressReady(page);
	await waitForFlavorAgent(page);
	await dismissWelcomeGuide(page);
	await dismissSiteEditorWelcomeGuide(page);
	await page.waitForFunction(
		() =>
			window.wp?.data?.select('core/edit-site')?.getEditedPostType?.() ===
			'wp_template',
	);
	await page.waitForFunction(
		() =>
			(window.wp?.data?.select('core/block-editor')?.getBlocks?.() || [])
				.length > 0,
	);

	await enableTemplateDocumentSidebar(page);
	await page.getByRole('tab', { name: 'Template', exact: true }).click();
	await openTemplateRecommendationsPanel(page);
	await expect(page.getByText('Recent AI Actions')).toBeVisible();
	await page
		.locator('.flavor-agent-activity-row')
		.getByRole('button', { name: 'Undo', exact: true })
		.click();

	await expect
		.poll(() => getTemplateInsertState(page, TEMPLATE_INSERTED_CONTENT))
		.toEqual({
			hasInsertedContent: false,
			undoStatus: 'undone',
		});
});

test('@wp70-site-editor template undo is disabled after inserted pattern content changes', async ({
	page,
}) => {
	const editedInsertedContent = 'Inserted content edited after apply';

	await page.route('**/*recommend-template*', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				explanation: `Insert ${TEMPLATE_PATTERN_TITLE} into the template flow.`,
				suggestions: [
					{
						label: 'Clarify template hierarchy',
						description: `Insert ${TEMPLATE_PATTERN_TITLE} into the template flow.`,
						operations: [
							{
								type: 'insert_pattern',
								patternName: TEMPLATE_PATTERN_NAME,
								placement: 'before_block_path',
								targetPath: TEMPLATE_MAIN_CONTENT_TARGET_PATH,
								expectedTarget: TEMPLATE_MAIN_CONTENT_TARGET,
							},
						],
						templateParts: [],
						patternSuggestions: [TEMPLATE_PATTERN_NAME],
					},
				],
			}),
		});
	});

	await page.goto('/wp-admin/site-editor.php', {
		waitUntil: 'domcontentloaded',
	});
	await waitForWordPressReady(page);
	await waitForFlavorAgent(page);
	await dismissWelcomeGuide(page);
	await openFirstTemplateEditor(page);
	await dismissSiteEditorWelcomeGuide(page);
	await page.waitForFunction(
		() =>
			Boolean(window.flavorAgentData?.canRecommendTemplates) &&
			window.wp?.data?.select('core/edit-site')?.getEditedPostType?.() ===
				'wp_template',
	);
	await page.waitForFunction(
		() =>
			(window.wp?.data?.select('core/block-editor')?.getBlocks?.() || [])
				.length > 0,
	);

	await enableTemplateDocumentSidebar(page);
	await registerTemplatePattern(page, {
		insertedContent: TEMPLATE_INSERTED_CONTENT,
		patternName: TEMPLATE_PATTERN_NAME,
		patternTitle: TEMPLATE_PATTERN_TITLE,
	});

	const promptInput = await openTemplateRecommendationsPanel(page);
	await promptInput.fill(TEMPLATE_PROMPT);
	await page.getByRole('button', { name: 'Get Suggestions' }).click();
	await expect(page.getByText('Suggested Composition')).toBeVisible();
	await page.getByRole('button', { name: 'Preview Apply' }).click();
	await page.evaluate(() => {
		window.wp.data.dispatch('core/block-editor').clearSelectedBlock();
	});
	await page.getByRole('button', { name: 'Confirm Apply' }).click();

	await expect
		.poll(
			async () =>
				(await getTemplateInsertState(page, TEMPLATE_INSERTED_CONTENT))
					.undoStatus,
		)
		.toBe('available');

	await page.evaluate(
		({ nextContent }) => {
			function normalizeValue(value) {
				if (Array.isArray(value)) {
					return value.map((item) =>
						normalizeValue(item === undefined ? null : item),
					);
				}

				if (value && typeof value === 'object') {
					return Object.fromEntries(
						Object.entries(value)
							.filter(([, entryValue]) => entryValue !== undefined)
							.sort(([leftKey], [rightKey]) => leftKey.localeCompare(rightKey))
							.map(([key, entryValue]) => [key, normalizeValue(entryValue)]),
					);
				}

				return value;
			}

			function normalizeBlockSnapshot(block) {
				return {
					name: block?.name || '',
					attributes: normalizeValue(block?.attributes || {}),
					innerBlocks: Array.isArray(block?.innerBlocks)
						? block.innerBlocks.map(normalizeBlockSnapshot)
						: [],
				};
			}

			function findParagraphBlock(blocks) {
				for (const block of blocks) {
					if (block?.name === 'core/paragraph') {
						return block;
					}

					if (Array.isArray(block?.innerBlocks)) {
						const nested = findParagraphBlock(block.innerBlocks);

						if (nested) {
							return nested;
						}
					}
				}

				return null;
			}

			function getBlockByPath(blocks, path = []) {
				let currentBlocks = blocks;
				let block = null;

				for (const index of path) {
					if (!Array.isArray(currentBlocks)) {
						return null;
					}

					block = currentBlocks[index] || null;

					if (!block) {
						return null;
					}

					currentBlocks = block.innerBlocks || [];
				}

				return block;
			}

			function resolveRootBlocks(blocks, rootLocator) {
				if (
					!rootLocator ||
					rootLocator.type === 'root' ||
					(Array.isArray(rootLocator.path) && rootLocator.path.length === 0)
				) {
					return blocks;
				}

				const rootBlock = getBlockByPath(blocks, rootLocator.path || []);

				return Array.isArray(rootBlock?.innerBlocks)
					? rootBlock.innerBlocks
					: [];
			}

			function findMatchingInsertedSlice(blocks, snapshot) {
				const sliceLength = Array.isArray(snapshot) ? snapshot.length : 0;

				if (sliceLength > 0) {
					for (let index = 0; index <= blocks.length - sliceLength; index++) {
						const candidate = blocks.slice(index, index + sliceLength);

						if (
							JSON.stringify(candidate.map(normalizeBlockSnapshot)) ===
							JSON.stringify(snapshot)
						) {
							return candidate;
						}
					}
				}

				for (const block of blocks) {
					if (Array.isArray(block?.innerBlocks)) {
						const nested = findMatchingInsertedSlice(
							block.innerBlocks,
							snapshot,
						);

						if (nested) {
							return nested;
						}
					}
				}

				return null;
			}

			const flavorAgent = window.wp.data.select('flavor-agent');
			const activityLog = flavorAgent.getActivityLog?.() || [];
			const lastActivity = activityLog[activityLog.length - 1] || null;
			const insertOperation =
				(lastActivity?.after?.operations || []).find(
					(operation) => operation?.type === 'insert_pattern',
				) || null;
			const blockEditor = window.wp.data.select('core/block-editor');
			const blockTree = blockEditor.getBlocks?.() || [];
			const rootBlocks = resolveRootBlocks(
				blockTree,
				insertOperation?.rootLocator || null,
			);
			const expectedSliceLength = Array.isArray(
				insertOperation?.insertedBlocksSnapshot,
			)
				? insertOperation.insertedBlocksSnapshot.length
				: 0;
			const insertedBlockSlice =
				insertOperation?.rootLocator &&
				Number.isInteger(insertOperation?.index) &&
				expectedSliceLength > 0
					? rootBlocks.slice(
							insertOperation.index,
							insertOperation.index + expectedSliceLength,
					  )
					: findMatchingInsertedSlice(
							blockTree,
							insertOperation?.insertedBlocksSnapshot || [],
					  );
			const insertedBlock = Array.isArray(insertedBlockSlice)
				? findParagraphBlock(insertedBlockSlice)
				: null;

			if (insertedBlock?.clientId) {
				window.wp.data
					.dispatch('core/block-editor')
					.updateBlockAttributes(insertedBlock.clientId, {
						content: nextContent,
					});
			}
		},
		{ nextContent: editedInsertedContent },
	);
	await expect
		.poll(() =>
			page.evaluate(
				({ nextContent }) => {
					function hasEditedParagraph(blocks) {
						for (const block of blocks) {
							const content = String(block?.attributes?.content || '');

							if (
								block?.name === 'core/paragraph' &&
								content.includes(nextContent)
							) {
								return true;
							}

							if (
								Array.isArray(block?.innerBlocks) &&
								hasEditedParagraph(block.innerBlocks)
							) {
								return true;
							}
						}

						return false;
					}

					return hasEditedParagraph(
						window.wp.data.select('core/block-editor').getBlocks?.() || [],
					);
				},
				{ nextContent: editedInsertedContent },
			),
		)
		.toBe(true);

	await page.getByRole('tab', { name: 'Template', exact: true }).click();
	await openTemplateRecommendationsPanel(page);

	await page.evaluate(async () => {
		const flavorAgent = window.wp.data.select('flavor-agent');
		const activityLog = flavorAgent.getActivityLog?.() || [];
		const lastActivity = activityLog[activityLog.length - 1] || null;

		if (lastActivity?.id) {
			await window.wp.data
				.dispatch('flavor-agent')
				.undoActivity(lastActivity.id);
		}
	});

	await expect(
		page
			.locator('.flavor-agent-activity-row')
			.getByText(
				'Inserted pattern content changed after apply and cannot be undone automatically.',
			),
	).toBeVisible();
	await expect(
		page
			.locator('.flavor-agent-activity-row')
			.getByRole('button', { name: 'Undo', exact: true }),
	).toHaveCount(0);
	await expect
		.poll(() =>
			page.evaluate(() => {
				const flavorAgent = window.wp.data.select('flavor-agent');
				const activityLog = flavorAgent.getActivityLog?.() || [];
				const lastActivity = activityLog[activityLog.length - 1] || null;

				return {
					undoStatus: lastActivity?.undo?.status || '',
					undoError: flavorAgent.getUndoError?.() || '',
				};
			}),
		)
		.toEqual({
			undoStatus: 'failed',
			undoError:
				'Inserted pattern content changed after apply and cannot be undone automatically.',
		});
	await expect
		.poll(() =>
			page.evaluate(
				({ nextContent }) => {
					function hasEditedParagraph(blocks) {
						for (const block of blocks) {
							const content = String(block?.attributes?.content || '');

							if (
								block?.name === 'core/paragraph' &&
								content.includes(nextContent)
							) {
								return true;
							}

							if (
								Array.isArray(block?.innerBlocks) &&
								hasEditedParagraph(block.innerBlocks)
							) {
								return true;
							}
						}

						return false;
					}

					return hasEditedParagraph(
						window.wp.data.select('core/block-editor').getBlocks?.() || [],
					);
				},
				{ nextContent: editedInsertedContent },
			),
		)
		.toBe(true);
});
