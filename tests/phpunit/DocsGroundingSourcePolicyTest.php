<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\DocsGroundingSourcePolicy;
use PHPUnit\Framework\TestCase;

final class DocsGroundingSourcePolicyTest extends TestCase {

	public function test_label_for_url_labels_without_dropping(): void {
		$this->assertSame(
			'developer-blog',
			DocsGroundingSourcePolicy::label_for_url( 'https://developer.wordpress.org/news/2026/05/x/' )
		);
		$this->assertSame(
			'make-core',
			DocsGroundingSourcePolicy::label_for_url( 'https://make.wordpress.org/core/2026/05/x/' )
		);
		$this->assertSame(
			'developer-docs',
			DocsGroundingSourcePolicy::label_for_url( 'https://developer.wordpress.org/block-editor/' )
		);
		$this->assertSame(
			'developer-docs',
			DocsGroundingSourcePolicy::label_for_url( 'https://example.com/whatever' )
		);
	}

	public function test_label_for_url_handles_unparseable_input(): void {
		$this->assertSame(
			'developer-docs',
			DocsGroundingSourcePolicy::label_for_url( '' )
		);
		$this->assertSame(
			'developer-docs',
			DocsGroundingSourcePolicy::label_for_url( 'http:///' )
		);
	}
}
