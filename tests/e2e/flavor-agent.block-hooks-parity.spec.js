const { test, expect } = require( '@playwright/test' );
const { getWp70HarnessConfig, runWpCli } = require( '../../scripts/wp70-e2e' );

const harness = getWp70HarnessConfig();

function runBlockHooksParityProbe() {
	const result = runWpCli( harness, [
		'eval',
		`
wp_set_current_user( 1 );

$theme        = '${ harness.themeSlug }';
$template_ref = $theme . '//home';
$part_ref     = $theme . '//header';
$hook_name    = 'flavor-agent/e2e-hook';
$pattern_name = 'flavor-agent/e2e-parity';
$contexts     = array();

$cleanup_rows = static function () use ( $theme ): void {
	$targets = array(
		'wp_template'      => 'home',
		'wp_template_part' => 'header',
	);

	foreach ( $targets as $post_type => $slug ) {
		$post_ids = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'any',
				'name'             => $slug,
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		foreach ( $post_ids as $post_id ) {
			$post_themes = wp_get_object_terms( $post_id, 'wp_theme', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $post_themes ) && in_array( $theme, $post_themes, true ) ) {
				wp_delete_post( $post_id, true );
			}
		}
	}
};

$require_result = static function ( $value, string $label ): array {
	if ( is_wp_error( $value ) ) {
		throw new \\RuntimeException(
			$label . ': ' . $value->get_error_code() . ' - ' . $value->get_error_message()
		);
	}
	if ( ! is_array( $value ) ) {
		throw new \\RuntimeException( $label . ': expected an array result.' );
	}

	return $value;
};

$require_content = static function ( $value, string $label ): string {
	if ( is_wp_error( $value ) ) {
		throw new \\RuntimeException(
			$label . ': ' . $value->get_error_code() . ' - ' . $value->get_error_message()
		);
	}
	if ( ! is_string( $value ) ) {
		throw new \\RuntimeException( $label . ': expected string content.' );
	}

	return $value;
};

$find_ignored_hooks = static function ( array $blocks, string $block_name ) use ( &$find_ignored_hooks ): array {
	foreach ( $blocks as $block ) {
		if ( $block_name === ( $block['blockName'] ?? null ) ) {
			$ignored = $block['attrs']['metadata']['ignoredHookedBlocks'] ?? array();

			return is_array( $ignored ) ? array_values( $ignored ) : array();
		}

		$inner_blocks = $block['innerBlocks'] ?? array();
		if ( is_array( $inner_blocks ) && ! empty( $inner_blocks ) ) {
			$ignored = $find_ignored_hooks( $inner_blocks, $block_name );
			if ( ! empty( $ignored ) ) {
				return $ignored;
			}
		}
	}

	return array();
};

$term_names = static function ( int $post_id, string $taxonomy ): array {
	$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );

	return is_wp_error( $terms ) ? array() : array_values( $terms );
};

$cleanup_rows();

$registered_block = register_block_type(
	$hook_name,
	array(
		'api_version' => 3,
		'title'       => 'Flavor Agent E2E Hook',
		'category'    => 'text',
		'supports'    => array( 'multiple' => true ),
	)
);
if ( false === $registered_block ) {
	throw new \\RuntimeException( 'Could not register the Block Hooks fixture block.' );
}

$registered_pattern = register_block_pattern(
	$pattern_name,
	array(
		'title'   => 'Flavor Agent E2E parity pattern',
		'content' => '<!-- wp:paragraph --><p>Flavor Agent parity insert.</p><!-- /wp:paragraph -->',
	)
);
if ( ! $registered_pattern ) {
	throw new \\RuntimeException( 'Could not register the parity pattern.' );
}

$hook_filter = static function ( array $hooked, string $position, $anchor, $context ) use (
	&$contexts,
	$template_ref,
	$part_ref,
	$hook_name
): array {
	if ( ! $context instanceof \\WP_Block_Template ) {
		return $hooked;
	}

	$context_id   = (string) ( $context->id ?? '' );
	$context_type = (string) ( $context->type ?? '' );
	$anchor       = (string) $anchor;
	$is_template  = $template_ref === $context_id
		&& 'wp_template' === $context_type
		&& 'after' === $position
		&& 'core/heading' === $anchor;
	$is_part      = $part_ref === $context_id
		&& 'wp_template_part' === $context_type
		&& 'first_child' === $position
		&& 'core/template-part' === $anchor;

	if ( ! $is_template && ! $is_part ) {
		return $hooked;
	}

	$contexts[] = array(
		'id'          => $context_id,
		'type'        => $context_type,
		'wpId'        => (int) ( $context->wp_id ?? 0 ),
		'source'      => (string) ( $context->source ?? '' ),
		'origin'      => (string) ( $context->origin ?? '' ),
		'description' => (string) ( $context->description ?? '' ),
		'area'        => (string) ( $context->area ?? '' ),
		'position'    => $position,
		'anchor'      => $anchor,
	);

	if ( ! in_array( $hook_name, $hooked, true ) ) {
		$hooked[] = $hook_name;
	}

	return $hooked;
};
add_filter( 'hooked_block_types', $hook_filter, 10, 4 );

try {
	$template_source = \\FlavorAgent\\Context\\ServerCollector::resolve_template_for_apply( $template_ref );
	$part_source     = \\FlavorAgent\\Context\\ServerCollector::resolve_template_part_for_apply( $part_ref );
	if ( ! is_object( $template_source ) || ! is_object( $part_source ) ) {
		throw new \\RuntimeException( 'Could not resolve the WP70 theme fixtures.' );
	}

	$template_entry = array(
		'surface'  => 'template',
		'target'   => array(
			'templateRef'  => $template_ref,
			'templateType' => 'home',
		),
		'document' => array(
			'entityId' => $template_ref,
			'postType' => 'wp_template',
			'scopeKey' => 'wp_template:' . $template_ref,
		),
		'apply'    => array(
			'operations' => array(
				array(
					'type'        => 'insert_pattern',
					'patternName' => $pattern_name,
					'placement'   => 'end',
				),
			),
		),
	);
	$part_entry = array(
		'surface'  => 'template-part',
		'target'   => array(
			'templatePartId'  => $part_ref,
			'templatePartRef' => $part_ref,
		),
		'document' => array(
			'entityId' => $part_ref,
			'postType' => 'wp_template_part',
			'scopeKey' => 'wp_template_part:' . $part_ref,
		),
		'apply'    => array(
			'operations' => array(
				array(
					'type'        => 'insert_pattern',
					'patternName' => $pattern_name,
					'placement'   => 'end',
				),
			),
		),
	);

	$template_result = $require_result(
		\\FlavorAgent\\Apply\\TemplateApplyExecutor::execute( $template_entry ),
		'Template execute'
	);
	$part_result = $require_result(
		\\FlavorAgent\\Apply\\TemplatePartApplyExecutor::execute( $part_entry ),
		'Template-part execute'
	);

	$template_live = \\FlavorAgent\\Context\\ServerCollector::resolve_template_for_apply( $template_ref );
	$part_live     = \\FlavorAgent\\Context\\ServerCollector::resolve_template_part_for_apply( $part_ref );
	if (
		! is_object( $template_live )
		|| ! is_object( $part_live )
		|| empty( $template_live->wp_id )
		|| empty( $part_live->wp_id )
	) {
		throw new \\RuntimeException( 'The executors did not materialize both fixtures.' );
	}

	$template_post = get_post( (int) $template_live->wp_id );
	$part_post     = get_post( (int) $part_live->wp_id );
	if ( ! $template_post instanceof \\WP_Post || ! $part_post instanceof \\WP_Post ) {
		throw new \\RuntimeException( 'Could not read the materialized fixture rows.' );
	}

	$template_raw      = (string) $template_post->post_content;
	$part_raw          = (string) $part_post->post_content;
	$template_semantic = $require_content(
		\\FlavorAgent\\Apply\\TemplateApplyExecutor::resolve_attested_content( $template_ref ),
		'Template attested content'
	);
	$part_semantic = $require_content(
		\\FlavorAgent\\Apply\\TemplatePartApplyExecutor::resolve_attested_content( $part_ref ),
		'Template-part attested content'
	);

	$part_ignored_raw = (string) get_post_meta( (int) $part_live->wp_id, '_wp_ignored_hooked_blocks', true );
	$part_ignored     = json_decode(
		$part_ignored_raw,
		true
	);
	if ( ! is_array( $part_ignored ) ) {
		$part_ignored = array();
	}

	update_post_meta( (int) $part_live->wp_id, '_wp_ignored_hooked_blocks', wp_slash( '[]' ) );
	global $wpdb;
	$seeded_modified = '2020-01-02 03:04:05';
	$wpdb->update(
		$wpdb->posts,
		array(
			'post_modified'     => $seeded_modified,
			'post_modified_gmt' => $seeded_modified,
		),
		array( 'ID' => (int) $part_live->wp_id )
	);
	clean_post_cache( (int) $part_live->wp_id );
	$transition_before_post = get_post( (int) $part_live->wp_id );
	if ( ! $transition_before_post instanceof \\WP_Post ) {
		throw new \\RuntimeException( 'Could not seed the existing-row timestamp transition.' );
	}
	$transition_pre_modified  = array();
	$transition_post_modified = array();
	$observe_pre_update       = static function ( int $post_id, array $data ) use (
		$part_live,
		&$transition_pre_modified
	): void {
		if ( (int) $part_live->wp_id !== $post_id ) {
			return;
		}

		$transition_pre_modified = array(
			'local' => (string) ( $data['post_modified'] ?? '' ),
			'gmt'   => (string) ( $data['post_modified_gmt'] ?? '' ),
		);
	};
	$observe_post_update      = static function ( int $post_id, \\WP_Post $post_after ) use (
		$part_live,
		&$transition_post_modified
	): void {
		if ( (int) $part_live->wp_id !== $post_id ) {
			return;
		}

		$transition_post_modified = array(
			'local' => (string) $post_after->post_modified,
			'gmt'   => (string) $post_after->post_modified_gmt,
		);
	};
	add_action( 'pre_post_update', $observe_pre_update, PHP_INT_MAX, 2 );
	add_action( 'post_updated', $observe_post_update, PHP_INT_MAX, 2 );
	$write_context = \\FlavorAgent\\Apply\\MaterializationWriteContext::capture();
	if ( is_wp_error( $write_context ) ) {
		throw new \\RuntimeException( 'Could not capture the existing-row metadata write context.' );
	}
	try {
		$metadata_transition = $require_result(
			\\FlavorAgent\\Apply\\ExistingPostContentWriter::update(
				(int) $part_live->wp_id,
				'wp_template_part',
				(string) $transition_before_post->post_name,
				$part_raw,
				$part_raw,
				'template-part',
				array( '_wp_ignored_hooked_blocks' => $part_ignored_raw ),
				$write_context
			),
			'Existing template-part metadata transition'
		);
	} finally {
		remove_action( 'pre_post_update', $observe_pre_update, PHP_INT_MAX );
		remove_action( 'post_updated', $observe_post_update, PHP_INT_MAX );
	}
	$transition_after_post = get_post( (int) $part_live->wp_id );
	if ( ! $transition_after_post instanceof \\WP_Post ) {
		throw new \\RuntimeException( 'Could not read the existing-row timestamp transition.' );
	}
	$metadata_transition_exact = $part_ignored_raw === (string) get_post_meta(
		(int) $part_live->wp_id,
		'_wp_ignored_hooked_blocks',
		true
	);

	$template_after_digest = \\FlavorAgent\\Attestation\\BlockContentCanonicalizer::digest( $template_semantic );
	$part_after_digest     = \\FlavorAgent\\Attestation\\BlockContentCanonicalizer::digest( $part_semantic );

	$template_undo_entry = array(
		'surface'  => $template_entry['surface'],
		'target'   => $template_entry['target'],
		'document' => $template_entry['document'],
		'before'   => $template_result['before'],
		'after'    => $template_result['after'],
	);
	$part_undo_entry = array(
		'surface'  => $part_entry['surface'],
		'target'   => $part_entry['target'],
		'document' => $part_entry['document'],
		'before'   => $part_result['before'],
		'after'    => $part_result['after'],
	);

	$template_undo = $require_result(
		\\FlavorAgent\\Apply\\TemplateApplyExecutor::undo( $template_undo_entry ),
		'Template undo'
	);
	$part_undo = $require_result(
		\\FlavorAgent\\Apply\\TemplatePartApplyExecutor::undo( $part_undo_entry ),
		'Template-part undo'
	);

	$template_after_undo = $require_content(
		\\FlavorAgent\\Apply\\TemplateApplyExecutor::resolve_attested_content( $template_ref ),
		'Template content after undo'
	);
	$part_after_undo = $require_content(
		\\FlavorAgent\\Apply\\TemplatePartApplyExecutor::resolve_attested_content( $part_ref ),
		'Template-part content after undo'
	);

	$payload = array(
		'hookName' => $hook_name,
		'contexts' => $contexts,
		'template' => array(
			'sourceDescription'  => (string) ( $template_source->description ?? '' ),
			'beforeHookCount'    => substr_count( (string) $template_result['before']['content'], '<!-- wp:' . $hook_name ),
			'afterHookCount'     => substr_count( (string) $template_result['after']['content'], '<!-- wp:' . $hook_name ),
			'rawHookCount'       => substr_count( $template_raw, '<!-- wp:' . $hook_name ),
			'semanticHookCount'  => substr_count( $template_semantic, '<!-- wp:' . $hook_name ),
			'ignoredHooks'       => $find_ignored_hooks( parse_blocks( $template_raw ), 'core/heading' ),
			'afterDigest'        => $template_after_digest,
			'expectedAfterDigest' => \\FlavorAgent\\Attestation\\BlockContentCanonicalizer::digest(
				(string) $template_result['after']['content']
			),
			'origin'             => (string) get_post_meta( (int) $template_live->wp_id, 'origin', true ),
			'excerpt'            => (string) $template_post->post_excerpt,
			'themes'             => $term_names( (int) $template_live->wp_id, 'wp_theme' ),
			'undoResult'         => $template_undo['result'] ?? '',
			'undoDigest'         => \\FlavorAgent\\Attestation\\BlockContentCanonicalizer::digest( $template_after_undo ),
			'expectedUndoDigest' => \\FlavorAgent\\Attestation\\BlockContentCanonicalizer::digest(
				(string) $template_result['before']['content']
			),
		),
		'part'     => array(
			'sourceDescription'  => (string) ( $part_source->description ?? '' ),
			'beforeHookCount'    => substr_count( (string) $part_result['before']['content'], '<!-- wp:' . $hook_name ),
			'afterHookCount'     => substr_count( (string) $part_result['after']['content'], '<!-- wp:' . $hook_name ),
			'rawHookCount'       => substr_count( $part_raw, '<!-- wp:' . $hook_name ),
			'semanticHookCount'  => substr_count( $part_semantic, '<!-- wp:' . $hook_name ),
			'ignoredHooks'       => array_values( $part_ignored ),
			'afterDigest'        => $part_after_digest,
			'expectedAfterDigest' => \\FlavorAgent\\Attestation\\BlockContentCanonicalizer::digest(
				(string) $part_result['after']['content']
			),
			'origin'             => (string) get_post_meta( (int) $part_live->wp_id, 'origin', true ),
			'excerpt'            => (string) $part_post->post_excerpt,
			'themes'             => $term_names( (int) $part_live->wp_id, 'wp_theme' ),
			'areas'              => $term_names( (int) $part_live->wp_id, 'wp_template_part_area' ),
			'metadataTransitionExact' => $metadata_transition_exact && isset( $metadata_transition['content'] ),
			'modifiedBefore'      => (string) $transition_before_post->post_modified,
			'modifiedGmtBefore'   => (string) $transition_before_post->post_modified_gmt,
			'modifiedAfter'       => (string) $transition_after_post->post_modified,
			'modifiedGmtAfter'    => (string) $transition_after_post->post_modified_gmt,
			'preModifiedObserved' => $transition_pre_modified,
			'postModifiedObserved' => $transition_post_modified,
			'undoResult'         => $part_undo['result'] ?? '',
			'undoDigest'         => \\FlavorAgent\\Attestation\\BlockContentCanonicalizer::digest( $part_after_undo ),
			'expectedUndoDigest' => \\FlavorAgent\\Attestation\\BlockContentCanonicalizer::digest(
				(string) $part_result['before']['content']
			),
		),
	);
} finally {
	remove_filter( 'hooked_block_types', $hook_filter, 10 );
	$cleanup_rows();
}

echo wp_json_encode( $payload );
`,
	] );

	return JSON.parse( result.stdout.trim() );
}

test( '@wp70-site-editor template and template-part writes preserve real Block Hooks semantics through undo', () => {
	const probe = runBlockHooksParityProbe();
	const templateWriteContext = probe.contexts.find(
		( context ) =>
			context.type === 'wp_template' &&
			context.source === 'custom' &&
			context.wpId === 0
	);
	const partWriteContext = probe.contexts.find(
		( context ) =>
			context.type === 'wp_template_part' &&
			context.source === 'custom' &&
			context.wpId === 0
	);

	expect( probe.template.beforeHookCount ).toBe( 1 );
	expect( probe.template.afterHookCount ).toBe( 1 );
	expect( probe.template.rawHookCount ).toBe( 1 );
	expect( probe.template.semanticHookCount ).toBe( 1 );
	expect( probe.template.ignoredHooks ).toContain( probe.hookName );
	expect( probe.template.afterDigest ).toBe(
		probe.template.expectedAfterDigest
	);
	expect( probe.template.origin ).toBe( 'theme' );
	expect( probe.template.excerpt ).toBe( probe.template.sourceDescription );
	expect( probe.template.sourceDescription ).not.toBe( '' );
	expect( probe.template.themes ).toEqual( [ harness.themeSlug ] );
	expect( templateWriteContext ).toMatchObject( {
		type: 'wp_template',
		source: 'custom',
		origin: 'theme',
		wpId: 0,
		description: probe.template.sourceDescription,
		position: 'after',
		anchor: 'core/heading',
	} );
	expect( probe.template.undoResult ).toBe( 'undone' );
	expect( probe.template.undoDigest ).toBe(
		probe.template.expectedUndoDigest
	);

	expect( probe.part.beforeHookCount ).toBe( 1 );
	expect( probe.part.afterHookCount ).toBe( 1 );
	expect( probe.part.rawHookCount ).toBe( 1 );
	expect( probe.part.semanticHookCount ).toBe( 1 );
	expect( probe.part.ignoredHooks ).toContain( probe.hookName );
	expect( probe.part.afterDigest ).toBe( probe.part.expectedAfterDigest );
	expect( probe.part.origin ).toBe( 'theme' );
	expect( probe.part.excerpt ).toBe( probe.part.sourceDescription );
	expect( probe.part.themes ).toEqual( [ harness.themeSlug ] );
	expect( probe.part.areas ).toEqual( [ 'header' ] );
	expect( probe.part.metadataTransitionExact ).toBe( true );
	expect( probe.part.modifiedBefore ).toBe( '2020-01-02 03:04:05' );
	expect( probe.part.modifiedGmtBefore ).toBe( '2020-01-02 03:04:05' );
	expect( probe.part.modifiedAfter ).not.toBe( probe.part.modifiedBefore );
	expect( probe.part.modifiedGmtAfter ).not.toBe(
		probe.part.modifiedGmtBefore
	);
	expect( probe.part.preModifiedObserved ).toEqual( {
		local: probe.part.modifiedAfter,
		gmt: probe.part.modifiedGmtAfter,
	} );
	expect( probe.part.postModifiedObserved ).toEqual( {
		local: probe.part.modifiedAfter,
		gmt: probe.part.modifiedGmtAfter,
	} );
	expect( partWriteContext ).toMatchObject( {
		type: 'wp_template_part',
		source: 'custom',
		origin: 'theme',
		wpId: 0,
		description: probe.part.sourceDescription,
		area: 'header',
		position: 'first_child',
		anchor: 'core/template-part',
	} );
	expect( probe.part.undoResult ).toBe( 'undone' );
	expect( probe.part.undoDigest ).toBe( probe.part.expectedUndoDigest );
} );
