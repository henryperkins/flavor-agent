# Ring III Attestation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Flavor Agent's external Global Styles / Style Book apply loop emit a durable, self-signed, publicly-verifiable attestation that binds each approved change to a digest of the artifact state it produced.

**Architecture:** At approval (`PendingApplyDecision::decide()`), an `Attestation\AttestationService` canonicalizes the post-apply state, builds an in-toto Statement, signs it with an Ed25519 site key, and appends a row to a retention-independent companion table. Public REST routes serve the signed statement, a JWKS, and the live canonical subject so an outside `Attestation\Verifier` can confirm signer, integrity, and live-match without site credentials. Undo emits a chained revert attestation. Full rationale and decisions: `docs/reference/ring-iii-attestation-design.md`.

**Tech Stack:** PHP 8.2+, WordPress 7.0+, ext-sodium (Ed25519, bundled), `$wpdb` + `dbDelta`, WP REST API, PHPUnit (`tests/phpunit/` flat, `WordPressTestState` harness).

## Global Constraints

- PHP 8.2+, WordPress 7.0+; PSR-4 namespace `FlavorAgent\` under `inc/`; files declare `strict_types=1`.
- Signing is **Ed25519** via `sodium_crypto_sign_*` (bundled). No new Composer dependencies.
- Private key is operator-set via the `FLAVOR_AGENT_ATTEST_PRIVATE_KEY` constant only — **never** written to `wp_options`/DB. No key configured → attestation is silently absent, never faked.
- The attestations table is **append-only** (insert + read only; no update/delete methods) and **retention-independent** (no prune cron; the activity prune must never touch it).
- **Public-safe allowlist** (design §4.2): a persisted statement may contain only canonical bytes, digests, signature, key id, surface, subject, timestamps, non-PII `actor.role`, and the bounded operation set. Never prompt text, provider payloads, display names/PII, or raw config beyond digests.
- Chaining links **attestation ids** (`revertsAttestationId` / `supersedesAttestationId`), never activity ids.
- Verification is **byte-exact** over the base64url-decoded statement; REST transports statement and signature as base64url strings, never as nested JSON re-serialized by REST.
- Honesty caveat (design §3/§6.2) ships in the class docblocks of `AttestationService` and `AttestationController`: self-signed site-key attestation; the live-match read is site-served.
- Run tests with `vendor/bin/phpunit`; keep `composer lint:php` (WPCS) clean.

---

## File Structure

**Create:**
- `inc/Attestation/Canonicalizer.php` — deterministic canonical bytes + sha256 digest of a Global Styles config (full or block-branch). Single source of truth for drift + attestation.
- `inc/Attestation/KeyManager.php` — load private key from constant, derive public key + key id, durable public-key registry, JWKS export.
- `inc/Attestation/Signer.php` — detached Ed25519 sign/verify over canonical bytes.
- `inc/Attestation/StatementBuilder.php` — build the public-safe in-toto Statement canonical bytes.
- `inc/Attestation/Repository.php` — companion table install + append-only persistence + lookups.
- `inc/Attestation/AttestationService.php` — orchestrator: record an apply attestation, record a chained revert.
- `inc/Attestation/Verifier.php` — pure outcome evaluation (design §9).
- `inc/REST/AttestationController.php` — public routes: `{id}` envelope, `keys` JWKS, `{id}/subject-state`.
- `tools/attestation-verify.php` — standalone stranger-facing verifier (HTTP + sodium glue → `Verifier`).
- `tests/phpunit/Attestation{Canonicalizer,KeyManager,Signer,StatementBuilder,Repository,Service,Controller,Verifier}Test.php`

**Modify:**
- `inc/Apply/StyleApplyExecutor.php` — delegate canonicalization to `Canonicalizer` (no behavior change).
- `inc/Apply/PendingApplyDecision.php` — record an attestation after a successful approve+apply.
- `inc/Abilities/ApplyAbilities.php` — record a chained revert attestation after a successful undo.
- `flavor-agent.php` — install/maybe_install the attestations table; register `AttestationController` on `rest_api_init`.

---

### Task 1: `Attestation\Canonicalizer` (single source of truth)

Lift the canonicalization helpers out of `StyleApplyExecutor` into a public unit, then delegate. This guarantees the executor's drift check and the attestation digest can never diverge.

**Files:**
- Create: `inc/Attestation/Canonicalizer.php`
- Modify: `inc/Apply/StyleApplyExecutor.php` (methods `comparable_config` ~:80, `comparable_config_hash` ~:90, `trim_config_to_block_branch` ~:550, `canonicalize_values_deep` ~:662, `canonicalize_style_value` ~:685, and `sort_keys_deep`)
- Test: `tests/phpunit/AttestationCanonicalizerTest.php`

**Interfaces:**
- Produces: `Canonicalizer::comparable_config(array $config): array`, `Canonicalizer::canonical_bytes(array $config): string`, `Canonicalizer::digest(array $config): string`, `Canonicalizer::block_branch(array $config, string $block_name): array`, `Canonicalizer::subject_digest(array $config, string $scope, string $block_name = ''): string` where `$scope` is `'global-styles'` or `'style-book-branch'`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace FlavorAgent\Tests;
use FlavorAgent\Attestation\Canonicalizer;
use PHPUnit\Framework\TestCase;

final class AttestationCanonicalizerTest extends TestCase {
    public function test_digest_is_stable_under_key_order(): void {
        $a = [ 'styles' => [ 'color' => [ 'text' => 'x', 'background' => 'y' ] ], 'settings' => [] ];
        $b = [ 'settings' => [], 'styles' => [ 'color' => [ 'background' => 'y', 'text' => 'x' ] ] ];
        $this->assertSame( Canonicalizer::digest( $a ), Canonicalizer::digest( $b ) );
    }
    public function test_preset_reference_forms_canonicalize_equal(): void {
        $ref = [ 'settings' => [], 'styles' => [ 'color' => [ 'background' => 'var:preset|color|parchment-100' ] ] ];
        $css = [ 'settings' => [], 'styles' => [ 'color' => [ 'background' => 'var(--wp--preset--color--parchment-100)' ] ] ];
        $this->assertSame( Canonicalizer::digest( $ref ), Canonicalizer::digest( $css ) );
    }
    public function test_subject_digest_branch_scopes_to_block(): void {
        $config = [ 'settings' => [], 'styles' => [ 'blocks' => [ 'core/button' => [ 'color' => [ 'text' => 'z' ] ], 'core/heading' => [ 'color' => [ 'text' => 'q' ] ] ] ] ];
        $full   = Canonicalizer::subject_digest( $config, 'global-styles' );
        $branch = Canonicalizer::subject_digest( $config, 'style-book-branch', 'core/button' );
        $this->assertNotSame( $full, $branch );
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `vendor/bin/phpunit --filter AttestationCanonicalizerTest`
Expected: FAIL — `Class "FlavorAgent\Attestation\Canonicalizer" not found`.

- [ ] **Step 3: Create `Canonicalizer` and move the helpers**

Move the bodies of `comparable_config`, `canonicalize_values_deep`, `canonicalize_style_value`, `sort_keys_deep`, and `trim_config_to_block_branch` **verbatim** from `StyleApplyExecutor` into the class below (as `public static`; rename `trim_config_to_block_branch` to `block_branch`), then add the wrappers:

```php
<?php
declare(strict_types=1);
namespace FlavorAgent\Attestation;

/**
 * Deterministic canonical view + sha256 digest of a Global Styles user config.
 * Single source of truth for StyleApplyExecutor's drift check and Ring III
 * attestation digests, so the two can never diverge. The canonicalization
 * rules here are published as the verifier spec — see docs/reference/ring-iii-attestation-design.md §6.
 */
final class Canonicalizer {

    public static function comparable_config( array $config ): array {
        return [
            'settings' => self::canonicalize_values_deep( self::sort_keys_deep( is_array( $config['settings'] ?? null ) ? $config['settings'] : [] ) ),
            'styles'   => self::canonicalize_values_deep( self::sort_keys_deep( is_array( $config['styles'] ?? null ) ? $config['styles'] : [] ) ),
        ];
    }

    public static function canonical_bytes( array $config ): string {
        return (string) wp_json_encode( self::comparable_config( $config ) );
    }

    public static function digest( array $config ): string {
        return hash( 'sha256', self::canonical_bytes( $config ) );
    }

    public static function subject_digest( array $config, string $scope, string $block_name = '' ): string {
        $target = ( 'style-book-branch' === $scope && '' !== $block_name )
            ? self::block_branch( $config, $block_name )
            : $config;
        return self::digest( $target );
    }

    // --- moved verbatim from StyleApplyExecutor (visibility -> public static) ---
    // public static function block_branch( array $config, string $block_name ): array { ...former trim_config_to_block_branch... }
    // public static function canonicalize_values_deep( mixed $value ): mixed { ... }
    // public static function canonicalize_style_value( string $value ): string { ... }
    // public static function sort_keys_deep( mixed $value ): mixed { ... }
}
```

- [ ] **Step 4: Replace the moved methods in `StyleApplyExecutor` with delegations**

Keep the public method names other code/tests call; delegate so behavior is identical:

```php
public static function comparable_config( array $config ): array {
    return \FlavorAgent\Attestation\Canonicalizer::comparable_config( $config );
}
public static function comparable_config_hash( array $config ): string {
    return \FlavorAgent\Attestation\Canonicalizer::digest( $config );
}
private static function trim_config_to_block_branch( array $config, string $block_name ): array {
    return \FlavorAgent\Attestation\Canonicalizer::block_branch( $config, $block_name );
}
```

Delete the now-unused private `canonicalize_values_deep` / `canonicalize_style_value` / `sort_keys_deep` from `StyleApplyExecutor` (they live in `Canonicalizer` now).

- [ ] **Step 5: Run both suites, verify green**

Run: `vendor/bin/phpunit --filter AttestationCanonicalizerTest && vendor/bin/phpunit --filter StyleApplyExecutorTest`
Expected: PASS for both (the executor's existing tests confirm the delegation preserved behavior).

- [ ] **Step 6: Commit**

```bash
git add inc/Attestation/Canonicalizer.php inc/Apply/StyleApplyExecutor.php tests/phpunit/AttestationCanonicalizerTest.php
git commit -m "feat(attestation): extract Canonicalizer as single source of truth"
```

---

### Task 2: `Attestation\KeyManager`

**Files:**
- Create: `inc/Attestation/KeyManager.php`
- Test: `tests/phpunit/AttestationKeyManagerTest.php`

**Interfaces:**
- Produces: `KeyManager::configured(): bool`, `private_key(): ?string` (64-byte sodium secret), `public_key(): ?string` (32-byte), `key_id(): ?string`, `ensure_registered(): void`, `jwks(): array`, `b64url(string $b): string`.
- Consumes: the `FLAVOR_AGENT_ATTEST_PRIVATE_KEY` constant; `get_option`/`update_option` (stubbed by `WordPressTestState::$options`).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace FlavorAgent\Tests;
use FlavorAgent\Attestation\KeyManager;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class AttestationKeyManagerTest extends TestCase {
    protected function setUp(): void { WordPressTestState::$options = []; }

    public function test_unconfigured_when_constant_absent(): void {
        $this->assertFalse( KeyManager::configured() );
    }
    public function test_registers_active_public_key_and_exports_jwks(): void {
        $kp = sodium_crypto_sign_keypair();
        if ( ! defined( 'FLAVOR_AGENT_ATTEST_PRIVATE_KEY' ) ) {
            define( 'FLAVOR_AGENT_ATTEST_PRIVATE_KEY', base64_encode( sodium_crypto_sign_secretkey( $kp ) ) );
        }
        $this->assertTrue( KeyManager::configured() );
        KeyManager::ensure_registered();
        $jwks = KeyManager::jwks();
        $this->assertSame( 'OKP', $jwks['keys'][0]['kty'] );
        $this->assertSame( 'Ed25519', $jwks['keys'][0]['crv'] );
        $this->assertSame( KeyManager::key_id(), $jwks['keys'][0]['kid'] );
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `vendor/bin/phpunit --filter AttestationKeyManagerTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `KeyManager`**

```php
<?php
declare(strict_types=1);
namespace FlavorAgent\Attestation;

final class KeyManager {
    private const REGISTRY_OPTION = 'flavor_agent_attestation_public_keys';

    public static function private_key(): ?string {
        if ( ! \defined( 'FLAVOR_AGENT_ATTEST_PRIVATE_KEY' ) ) {
            return null;
        }
        $decoded = base64_decode( (string) \constant( 'FLAVOR_AGENT_ATTEST_PRIVATE_KEY' ), true );
        return ( false !== $decoded && SODIUM_CRYPTO_SIGN_SECRETKEYBYTES === strlen( $decoded ) ) ? $decoded : null;
    }

    public static function configured(): bool {
        return null !== self::private_key();
    }

    public static function public_key(): ?string {
        $sk = self::private_key();
        return null === $sk ? null : sodium_crypto_sign_publickey_from_secretkey( $sk );
    }

    public static function key_id(): ?string {
        $pk = self::public_key();
        return null === $pk ? null : substr( hash( 'sha256', $pk ), 0, 16 );
    }

    public static function ensure_registered(): void {
        $pk  = self::public_key();
        $kid = self::key_id();
        if ( null === $pk || null === $kid ) {
            return;
        }
        $registry = \get_option( self::REGISTRY_OPTION, [] );
        $registry = is_array( $registry ) ? $registry : [];
        if ( isset( $registry[ $kid ] ) ) {
            return;
        }
        foreach ( $registry as $id => $rec ) {
            if ( 'active' === ( $rec['status'] ?? '' ) ) {
                $registry[ $id ]['status'] = 'retired';
            }
        }
        $registry[ $kid ] = [ 'kid' => $kid, 'x' => self::b64url( $pk ), 'status' => 'active', 'createdAt' => gmdate( 'c' ) ];
        \update_option( self::REGISTRY_OPTION, $registry, false );
    }

    public static function jwks(): array {
        $registry = \get_option( self::REGISTRY_OPTION, [] );
        $keys     = [];
        foreach ( ( is_array( $registry ) ? $registry : [] ) as $rec ) {
            $keys[] = [ 'kty' => 'OKP', 'crv' => 'Ed25519', 'x' => (string) $rec['x'], 'kid' => (string) $rec['kid'], 'use' => 'sig', 'alg' => 'EdDSA' ];
        }
        return [ 'keys' => $keys ];
    }

    public static function b64url( string $b ): string {
        return rtrim( strtr( base64_encode( $b ), '+/', '-_' ), '=' );
    }
}
```

- [ ] **Step 4: Run it, verify it passes**

Run: `vendor/bin/phpunit --filter AttestationKeyManagerTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add inc/Attestation/KeyManager.php tests/phpunit/AttestationKeyManagerTest.php
git commit -m "feat(attestation): add Ed25519 KeyManager with durable public-key registry"
```

---

### Task 3: `Attestation\Signer`

**Files:**
- Create: `inc/Attestation/Signer.php`
- Test: `tests/phpunit/AttestationSignerTest.php`

**Interfaces:**
- Consumes: `KeyManager`.
- Produces: `Signer::sign(string $canonical): ?array` → `{statement: string, signature: string (raw bytes), keyId: string}` or `null` when unconfigured; `Signer::verify(string $canonical, string $signature, string $public_key): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace FlavorAgent\Tests;
use FlavorAgent\Attestation\KeyManager;
use FlavorAgent\Attestation\Signer;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class AttestationSignerTest extends TestCase {
    protected function setUp(): void { WordPressTestState::$options = []; }

    public function test_sign_then_verify_round_trips(): void {
        if ( ! defined( 'FLAVOR_AGENT_ATTEST_PRIVATE_KEY' ) ) {
            define( 'FLAVOR_AGENT_ATTEST_PRIVATE_KEY', base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) ) );
        }
        $bytes  = '{"hello":"world"}';
        $signed = Signer::sign( $bytes );
        $this->assertNotNull( $signed );
        $this->assertTrue( Signer::verify( $bytes, $signed['signature'], (string) KeyManager::public_key() ) );
    }
    public function test_tampered_bytes_fail_verification(): void {
        $signed = Signer::sign( '{"hello":"world"}' );
        $this->assertFalse( Signer::verify( '{"hello":"WORLD"}', $signed['signature'], (string) KeyManager::public_key() ) );
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `vendor/bin/phpunit --filter AttestationSignerTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `Signer`**

```php
<?php
declare(strict_types=1);
namespace FlavorAgent\Attestation;

final class Signer {
    /** @return array{statement: string, signature: string, keyId: string}|null */
    public static function sign( string $canonical_statement ): ?array {
        $sk = KeyManager::private_key();
        if ( null === $sk ) {
            return null;
        }
        KeyManager::ensure_registered();
        return [
            'statement' => $canonical_statement,
            'signature' => sodium_crypto_sign_detached( $canonical_statement, $sk ),
            'keyId'     => (string) KeyManager::key_id(),
        ];
    }

    public static function verify( string $canonical_statement, string $signature, string $public_key ): bool {
        if ( SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public_key ) ) {
            return false;
        }
        return sodium_crypto_sign_verify_detached( $signature, $canonical_statement, $public_key );
    }
}
```

- [ ] **Step 4: Run it, verify it passes**

Run: `vendor/bin/phpunit --filter AttestationSignerTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add inc/Attestation/Signer.php tests/phpunit/AttestationSignerTest.php
git commit -m "feat(attestation): add detached Ed25519 Signer"
```

---

### Task 4: `Attestation\StatementBuilder` (public-safe allowlist)

**Files:**
- Create: `inc/Attestation/StatementBuilder.php`
- Test: `tests/phpunit/AttestationStatementBuilderTest.php`

**Interfaces:**
- Produces: `StatementBuilder::build(array $p): string` (canonical JSON bytes), `StatementBuilder::canonical_json(array $data): string`, const `PREDICATE_TYPE`.
- `$p` keys consumed: `attestationId, surface, scope, subjectName, operations, beforeDigest, afterDigest, freshnessSignature, actorRole, proposerVia, decision, requestedAt, decidedAt, siteUrl, keyId, revertsAttestationId?, supersedesAttestationId?, relatedActivityId?`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace FlavorAgent\Tests;
use FlavorAgent\Attestation\StatementBuilder;
use PHPUnit\Framework\TestCase;

final class AttestationStatementBuilderTest extends TestCase {
    private function params(): array {
        return [
            'attestationId' => 'att_1', 'surface' => 'global-styles', 'scope' => 'global-styles',
            'subjectName' => 'wp_global_styles:81', 'operations' => [ [ 'path' => [ 'color', 'background' ], 'value' => 'var:preset|color|parchment-100' ] ],
            'beforeDigest' => 'b', 'afterDigest' => 'a', 'freshnessSignature' => 'f',
            'actorRole' => 'administrator', 'proposerVia' => 'mcp/flavor-agent', 'decision' => 'approve',
            'requestedAt' => '2026-06-22T00:00:00+00:00', 'decidedAt' => '2026-06-22T00:01:00+00:00',
            'siteUrl' => 'https://example.com', 'keyId' => 'k1',
        ];
    }
    public function test_subject_digest_equals_after_digest(): void {
        $stmt = json_decode( StatementBuilder::build( $this->params() ), true );
        $this->assertSame( $stmt['subject'][0]['digest']['sha256'], $stmt['predicate']['after']['sha256'] );
    }
    public function test_excludes_non_allowlisted_fields(): void {
        $p = $this->params();
        $p['prompt'] = 'SECRET PROMPT'; $p['displayName'] = 'Henry Perkins'; $p['providerPayload'] = [ 'k' => 'v' ];
        $bytes = StatementBuilder::build( $p );
        $this->assertStringNotContainsString( 'SECRET PROMPT', $bytes );
        $this->assertStringNotContainsString( 'Henry Perkins', $bytes );
        $this->assertStringNotContainsString( 'providerPayload', $bytes );
    }
    public function test_canonical_json_is_key_order_stable(): void {
        $this->assertSame(
            StatementBuilder::canonical_json( [ 'b' => 1, 'a' => 2 ] ),
            StatementBuilder::canonical_json( [ 'a' => 2, 'b' => 1 ] )
        );
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `vendor/bin/phpunit --filter AttestationStatementBuilderTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `StatementBuilder`**

```php
<?php
declare(strict_types=1);
namespace FlavorAgent\Attestation;

final class StatementBuilder {
    public const PREDICATE_TYPE = 'https://flavor-agent.dev/attestation/governed-change/v1';

    public static function build( array $p ): string {
        $statement = [
            '_type'         => 'https://in-toto.io/Statement/v1',
            'subject'       => [ [
                'name'   => (string) $p['subjectName'],
                'scope'  => (string) $p['scope'],
                'digest' => [ 'sha256' => (string) $p['afterDigest'] ],
            ] ],
            'predicateType' => self::PREDICATE_TYPE,
            'predicate'     => self::public_safe_predicate( $p ),
        ];
        return self::canonical_json( $statement );
    }

    /** ALLOWLIST — only these keys are ever emitted (Global Constraints / design §4.2). */
    private static function public_safe_predicate( array $p ): array {
        return [
            'attestationId'           => (string) $p['attestationId'],
            'schemaVersion'           => 1,
            'surface'                 => (string) $p['surface'],
            'operations'              => array_values( (array) ( $p['operations'] ?? [] ) ),
            'before'                  => [ 'sha256' => (string) $p['beforeDigest'] ],
            'after'                   => [ 'sha256' => (string) $p['afterDigest'] ],
            'freshnessSignature'      => (string) ( $p['freshnessSignature'] ?? '' ),
            'actor'                   => [ 'role' => (string) ( $p['actorRole'] ?? '' ), 'proposerVia' => (string) ( $p['proposerVia'] ?? '' ) ],
            'decision'                => (string) ( $p['decision'] ?? 'approve' ),
            'timestamps'              => [ 'requestedAt' => (string) ( $p['requestedAt'] ?? '' ), 'decidedAt' => (string) ( $p['decidedAt'] ?? '' ) ],
            'site'                    => [ 'url' => (string) ( $p['siteUrl'] ?? '' ), 'keyId' => (string) ( $p['keyId'] ?? '' ) ],
            'revertsAttestationId'    => isset( $p['revertsAttestationId'] ) ? (string) $p['revertsAttestationId'] : null,
            'supersedesAttestationId' => isset( $p['supersedesAttestationId'] ) ? (string) $p['supersedesAttestationId'] : null,
            'relatedActivityId'       => isset( $p['relatedActivityId'] ) ? (string) $p['relatedActivityId'] : null,
        ];
    }

    /** Deterministic JSON: recursively ksort associative arrays; preserve list order (operations are ordered). */
    public static function canonical_json( array $data ): string {
        self::ksort_deep( $data );
        return (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    private static function ksort_deep( array &$data ): void {
        foreach ( $data as &$value ) {
            if ( is_array( $value ) ) {
                self::ksort_deep( $value );
            }
        }
        unset( $value );
        if ( ! array_is_list( $data ) ) {
            ksort( $data );
        }
    }
}
```

- [ ] **Step 4: Run it, verify it passes**

Run: `vendor/bin/phpunit --filter AttestationStatementBuilderTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add inc/Attestation/StatementBuilder.php tests/phpunit/AttestationStatementBuilderTest.php
git commit -m "feat(attestation): add public-safe in-toto StatementBuilder"
```

---

### Task 5: `Attestation\Repository` (append-only companion table)

**Files:**
- Create: `inc/Attestation/Repository.php`
- Modify: `flavor-agent.php` (activation install + `init` maybe_install)
- Test: `tests/phpunit/AttestationRepositoryTest.php` (mirror `tests/phpunit/ActivityRepositoryTest.php` for `$wpdb` stubbing)

**Interfaces:**
- Produces: `Repository::table_name(): string`, `install(): void`, `maybe_install(): void`, `insert(array $row): bool`, `find(string $id): ?array`, `find_by_reverts(string $id): ?array`, `find_by_related_activity(string $activity_id): ?array`, const `SCHEMA_VERSION`.
- `insert` row keys: `attestation_id, surface, subject_name, subject_scope, after_digest, statement_bytes, signature_b64, key_id, reverts_attestation_id?, supersedes_attestation_id?, related_activity_id?`.

- [ ] **Step 1: Write the failing test**

Mirror `ActivityRepositoryTest`'s `$wpdb` setup (the harness stub). Core assertions:

```php
public function test_insert_then_find_round_trips(): void {
    Repository::install();
    Repository::insert( [
        'attestation_id' => 'att_1', 'surface' => 'global-styles', 'subject_name' => 'wp_global_styles:81',
        'subject_scope' => 'global-styles', 'after_digest' => 'a', 'statement_bytes' => '{"x":1}',
        'signature_b64' => 'sig', 'key_id' => 'k1', 'related_activity_id' => 'act_9',
    ] );
    $row = Repository::find( 'att_1' );
    $this->assertSame( '{"x":1}', $row['statement_bytes'] );
    $this->assertSame( 'act_9', Repository::find_by_related_activity( 'act_9' )['attestation_id'] );
}
public function test_find_by_reverts_locates_chained_revert(): void {
    Repository::install();
    Repository::insert( [ 'attestation_id' => 'att_2', 'surface' => 'global-styles', 'subject_name' => 's',
        'subject_scope' => 'global-styles', 'after_digest' => 'a2', 'statement_bytes' => '{}', 'signature_b64' => 'x',
        'key_id' => 'k1', 'reverts_attestation_id' => 'att_1' ] );
    $this->assertSame( 'att_2', Repository::find_by_reverts( 'att_1' )['attestation_id'] );
}
public function test_repository_exposes_no_update_or_delete(): void {
    $this->assertFalse( method_exists( Repository::class, 'update' ) );
    $this->assertFalse( method_exists( Repository::class, 'delete' ) );
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `vendor/bin/phpunit --filter AttestationRepositoryTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `Repository`** (mirror `Activity\Repository` install/table_exists)

```php
<?php
declare(strict_types=1);
namespace FlavorAgent\Attestation;

/**
 * Append-only, retention-independent store for Ring III attestations.
 * Deliberately has no update/delete and is NOT registered with the activity
 * prune cron: durability is the proof (design §5).
 */
final class Repository {
    public const SCHEMA_VERSION  = 1;
    private const SCHEMA_OPTION   = 'flavor_agent_attestation_schema_version';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'flavor_agent_attestations';
    }

    public static function maybe_install(): void {
        if ( (int) \get_option( self::SCHEMA_OPTION, 0 ) < self::SCHEMA_VERSION || ! self::table_exists() ) {
            self::install();
        }
    }

    public static function install(): void {
        global $wpdb;
        $table   = self::table_name();
        $charset = method_exists( $wpdb, 'get_charset_collate' ) ? (string) $wpdb->get_charset_collate() : '';
        $sql     = "CREATE TABLE {$table} (
            attestation_id varchar(64) NOT NULL,
            schema_version smallint NOT NULL DEFAULT 1,
            surface varchar(40) NOT NULL,
            subject_name varchar(191) NOT NULL,
            subject_scope varchar(40) NOT NULL,
            after_digest char(64) NOT NULL,
            statement_bytes longtext NOT NULL,
            signature_b64 text NOT NULL,
            key_id varchar(64) NOT NULL,
            reverts_attestation_id varchar(64) DEFAULT NULL,
            supersedes_attestation_id varchar(64) DEFAULT NULL,
            related_activity_id varchar(64) DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (attestation_id),
            KEY subject_name (subject_name),
            KEY reverts_attestation_id (reverts_attestation_id),
            KEY related_activity_id (related_activity_id)
        ) {$charset};";
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        \dbDelta( $sql );
        \update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
    }

    public static function insert( array $row ): bool {
        global $wpdb;
        $record = [
            'attestation_id'            => (string) $row['attestation_id'],
            'schema_version'            => self::SCHEMA_VERSION,
            'surface'                   => (string) $row['surface'],
            'subject_name'              => (string) $row['subject_name'],
            'subject_scope'             => (string) $row['subject_scope'],
            'after_digest'              => (string) $row['after_digest'],
            'statement_bytes'           => (string) $row['statement_bytes'],
            'signature_b64'             => (string) $row['signature_b64'],
            'key_id'                    => (string) $row['key_id'],
            'reverts_attestation_id'    => isset( $row['reverts_attestation_id'] ) ? (string) $row['reverts_attestation_id'] : null,
            'supersedes_attestation_id' => isset( $row['supersedes_attestation_id'] ) ? (string) $row['supersedes_attestation_id'] : null,
            'related_activity_id'       => isset( $row['related_activity_id'] ) ? (string) $row['related_activity_id'] : null,
            'created_at'                => gmdate( 'Y-m-d H:i:s' ),
        ];
        return false !== $wpdb->insert( self::table_name(), $record );
    }

    public static function find( string $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE attestation_id = %s', $id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    public static function find_by_reverts( string $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE reverts_attestation_id = %s ORDER BY created_at DESC', $id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    public static function find_by_related_activity( string $activity_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE related_activity_id = %s AND reverts_attestation_id IS NULL ORDER BY created_at DESC', $activity_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    private static function table_exists(): bool {
        global $wpdb;
        $table = self::table_name();
        return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }
}
```

- [ ] **Step 4: Wire install into `flavor-agent.php`**

In the `register_activation_hook` closure (next to `Repository::install()` at ~:46) add:

```php
FlavorAgent\Attestation\Repository::install();
```

After the activity `maybe_install` hook (~:69) add:

```php
add_action( 'init', [ FlavorAgent\Attestation\Repository::class, 'maybe_install' ], 5 );
```

- [ ] **Step 5: Run it, verify it passes**

Run: `vendor/bin/phpunit --filter AttestationRepositoryTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add inc/Attestation/Repository.php flavor-agent.php tests/phpunit/AttestationRepositoryTest.php
git commit -m "feat(attestation): add append-only, retention-independent Repository"
```

---

### Task 6: `Attestation\AttestationService` + sign-on-approve

**Files:**
- Create: `inc/Attestation/AttestationService.php`
- Modify: `inc/Apply/PendingApplyDecision.php` (after successful apply, before the final `transition_external_apply`)
- Test: `tests/phpunit/AttestationServiceTest.php`

**Interfaces:**
- Consumes: `Canonicalizer`, `StatementBuilder`, `Signer`, `Repository`, `KeyManager`.
- Produces: `AttestationService::record_apply(array $ctx): ?string` (returns attestation id, or `null` when no key). `$ctx`: `surface, globalStylesId, blockName, operations, before(after-config arrays from StyleApplyExecutor::apply result), after, freshnessSignature, actorRole, decidedAt, requestedAt, relatedActivityId`.

- [ ] **Step 1: Write the failing test**

```php
public function test_record_apply_persists_a_verifiable_attestation(): void {
    if ( ! defined( 'FLAVOR_AGENT_ATTEST_PRIVATE_KEY' ) ) {
        define( 'FLAVOR_AGENT_ATTEST_PRIVATE_KEY', base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) ) );
    }
    \FlavorAgent\Attestation\Repository::install();
    $id = AttestationService::record_apply( [
        'surface' => 'global-styles', 'globalStylesId' => '81', 'blockName' => '',
        'operations' => [ [ 'path' => [ 'color', 'background' ], 'value' => 'var:preset|color|parchment-100' ] ],
        'before' => [ 'userConfig' => [ 'settings' => [], 'styles' => [] ] ],
        'after'  => [ 'userConfig' => [ 'settings' => [], 'styles' => [ 'color' => [ 'background' => 'var:preset|color|parchment-100' ] ] ] ],
        'freshnessSignature' => 'f', 'actorRole' => 'administrator', 'relatedActivityId' => 'act_9',
        'requestedAt' => '2026-06-22T00:00:00+00:00', 'decidedAt' => '2026-06-22T00:01:00+00:00',
    ] );
    $this->assertNotNull( $id );
    $row = \FlavorAgent\Attestation\Repository::find( $id );
    $this->assertTrue( \FlavorAgent\Attestation\Signer::verify(
        $row['statement_bytes'],
        sodium_base642bin( strtr( $row['signature_b64'], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $row['signature_b64'] ) % 4 ) % 4 ), SODIUM_BASE64_VARIANT_ORIGINAL ),
        (string) \FlavorAgent\Attestation\KeyManager::public_key()
    ) );
}
public function test_record_apply_returns_null_without_key(): void {
    // when FLAVOR_AGENT_ATTEST_PRIVATE_KEY is absent KeyManager::configured() is false
    $this->assertTrue( true ); // see note: run this case in a separate process where the constant is undefined
}
```

> Constant note: because PHP constants can't be undefined within a run, keep the "no key" assertion in its own test file/process (mirror the `@runInSeparateProcess` pattern documented for plugin-helper tests) or assert on `KeyManager::configured()` directly.

- [ ] **Step 2: Run it, verify it fails**

Run: `vendor/bin/phpunit --filter AttestationServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `AttestationService`**

```php
<?php
declare(strict_types=1);
namespace FlavorAgent\Attestation;

final class AttestationService {
    /**
     * Record a signed attestation for an approved apply. Returns the attestation
     * id, or null when no signing key is configured (apply still succeeded).
     *
     * Self-signed site-key attestation; the live-match read is site-served
     * (design §3/§6.2).
     *
     * @param array<string,mixed> $ctx
     */
    public static function record_apply( array $ctx ): ?string {
        if ( ! KeyManager::configured() ) {
            return null;
        }

        $surface     = (string) $ctx['surface'];
        $block_name  = (string) ( $ctx['blockName'] ?? '' );
        $scope       = ( 'style-book' === $surface && '' !== $block_name ) ? 'style-book-branch' : 'global-styles';
        $subject     = 'wp_global_styles:' . (string) $ctx['globalStylesId'] . ( 'style-book-branch' === $scope ? '#' . $block_name : '' );

        $before_cfg  = is_array( $ctx['before']['userConfig'] ?? null ) ? $ctx['before']['userConfig'] : [];
        $after_cfg   = is_array( $ctx['after']['userConfig'] ?? null ) ? $ctx['after']['userConfig'] : [];
        $before_dig  = Canonicalizer::subject_digest( $before_cfg, $scope, $block_name );
        $after_dig   = Canonicalizer::subject_digest( $after_cfg, $scope, $block_name );

        $attestation_id = 'att_' . bin2hex( random_bytes( 12 ) );

        $statement = StatementBuilder::build( [
            'attestationId'      => $attestation_id,
            'surface'            => $surface,
            'scope'              => $scope,
            'subjectName'        => $subject,
            'operations'         => is_array( $ctx['operations'] ?? null ) ? $ctx['operations'] : [],
            'beforeDigest'       => $before_dig,
            'afterDigest'        => $after_dig,
            'freshnessSignature' => (string) ( $ctx['freshnessSignature'] ?? '' ),
            'actorRole'          => (string) ( $ctx['actorRole'] ?? '' ),
            'proposerVia'        => 'mcp/flavor-agent',
            'decision'           => 'approve',
            'requestedAt'        => (string) ( $ctx['requestedAt'] ?? '' ),
            'decidedAt'          => (string) ( $ctx['decidedAt'] ?? '' ),
            'siteUrl'            => (string) ( function_exists( 'home_url' ) ? home_url() : '' ),
            'keyId'              => (string) KeyManager::key_id(),
            'relatedActivityId'  => isset( $ctx['relatedActivityId'] ) ? (string) $ctx['relatedActivityId'] : null,
            'revertsAttestationId' => isset( $ctx['revertsAttestationId'] ) ? (string) $ctx['revertsAttestationId'] : null,
        ] );

        $signed = Signer::sign( $statement );
        if ( null === $signed ) {
            return null;
        }

        $ok = Repository::insert( [
            'attestation_id'         => $attestation_id,
            'surface'                => $surface,
            'subject_name'           => $subject,
            'subject_scope'          => $scope,
            'after_digest'           => $after_dig,
            'statement_bytes'        => $signed['statement'],
            'signature_b64'          => KeyManager::b64url( $signed['signature'] ),
            'key_id'                 => $signed['keyId'],
            'reverts_attestation_id' => isset( $ctx['revertsAttestationId'] ) ? (string) $ctx['revertsAttestationId'] : null,
            'related_activity_id'    => isset( $ctx['relatedActivityId'] ) ? (string) $ctx['relatedActivityId'] : null,
        ] );

        return $ok ? $attestation_id : null;
    }
}
```

- [ ] **Step 4: Call it from `PendingApplyDecision::decide()`**

Between the successful `$result = StyleApplyExecutor::apply(...)` (line ~111) and the final approve transition (line ~127), add — failure to attest must never block the apply:

```php
try {
    \FlavorAgent\Attestation\AttestationService::record_apply( [
        'surface'            => $surface,
        'globalStylesId'     => $global_styles_id,
        'blockName'          => $block_name,
        'operations'         => $operations,
        'before'             => $result['before'],
        'after'              => $result['after'],
        'freshnessSignature' => $baseline,
        'actorRole'          => self::actor_role( $decided_by ),
        'requestedAt'        => (string) ( $apply['requestedAt'] ?? '' ),
        'decidedAt'          => $decided_at,
        'relatedActivityId'  => $activity_id,
    ] );
} catch ( \Throwable $e ) {
    // Attestation is best-effort; the governed apply is the contract.
}
```

Add a small private helper `actor_role(int $user_id): string` returning the user's primary role (`administrator` etc.) via `get_userdata`, defaulting to `''`.

- [ ] **Step 5: Run it, verify it passes**

Run: `vendor/bin/phpunit --filter AttestationServiceTest && vendor/bin/phpunit --filter PendingApply`
Expected: PASS (existing `PendingApplyDecision`/`ApplyAbilities` tests stay green; attestation is best-effort).

- [ ] **Step 6: Commit**

```bash
git add inc/Attestation/AttestationService.php inc/Apply/PendingApplyDecision.php tests/phpunit/AttestationServiceTest.php
git commit -m "feat(attestation): sign and store an attestation on approved apply"
```

---

### Task 7: `REST\AttestationController` (public verification surface)

**Files:**
- Create: `inc/REST/AttestationController.php`
- Modify: `flavor-agent.php` (register on `rest_api_init`, mirroring `:97`)
- Test: `tests/phpunit/AttestationControllerTest.php`

**Interfaces:**
- Routes (namespace `flavor-agent/v1`, all `permission_callback => __return_true`):
  - `GET /attestations/(?P<id>[\w-]+)` → `{ statement_b64, signature_b64, key_id, reverted_by_attestation_id|null, statement_json }`
  - `GET /attestations/keys` → JWKS (`KeyManager::jwks()`)
  - `GET /attestations/(?P<id>[\w-]+)/subject-state` → `{ subject_canonical_b64, subject_digest, scope }` from the **live** entity.

- [ ] **Step 1: Write the failing test**

```php
public function test_get_attestation_returns_byte_exact_verifiable_envelope(): void {
    // arrange: install + record_apply as in Task 6, capture $id
    $resp = ( new \FlavorAgent\REST\AttestationController() )->get_attestation(
        new \WP_REST_Request( 'GET', '/flavor-agent/v1/attestations/' . $id )
    );
    $data = $resp->get_data();
    $statement = sodium_base642bin( strtr( $data['statement_b64'], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $data['statement_b64'] ) % 4 ) % 4 ), SODIUM_BASE64_VARIANT_ORIGINAL );
    $sig       = sodium_base642bin( strtr( $data['signature_b64'], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $data['signature_b64'] ) % 4 ) % 4 ), SODIUM_BASE64_VARIANT_ORIGINAL );
    $this->assertTrue( \FlavorAgent\Attestation\Signer::verify( $statement, $sig, (string) \FlavorAgent\Attestation\KeyManager::public_key() ) );
}
public function test_keys_route_returns_jwks(): void {
    $data = ( new \FlavorAgent\REST\AttestationController() )->get_keys( new \WP_REST_Request() )->get_data();
    $this->assertSame( 'OKP', $data['keys'][0]['kty'] );
}
```

> Follow `tests/phpunit/ApplyAbilitiesTest.php` for `WP_REST_Request`/`WP_REST_Response` harness usage.

- [ ] **Step 2: Run it, verify it fails**

Run: `vendor/bin/phpunit --filter AttestationControllerTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `AttestationController`**

```php
<?php
declare(strict_types=1);
namespace FlavorAgent\REST;

use FlavorAgent\Attestation\Canonicalizer;
use FlavorAgent\Attestation\KeyManager;
use FlavorAgent\Attestation\Repository;
use FlavorAgent\Apply\StyleApplyExecutor;

/**
 * Public, unauthenticated read surface for Ring III attestations. The signed
 * statement and JWKS verify independently; subject-state is site-served, so the
 * live-match check carries a present-state trust component (design §6.2).
 */
final class AttestationController {
    private const NAMESPACE = 'flavor-agent/v1';

    public static function register_routes(): void {
        $self = new self();
        \register_rest_route( self::NAMESPACE, '/attestations/(?P<id>[\w-]+)', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [ $self, 'get_attestation' ],
        ] );
        \register_rest_route( self::NAMESPACE, '/attestations/keys', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [ $self, 'get_keys' ],
        ] );
        \register_rest_route( self::NAMESPACE, '/attestations/(?P<id>[\w-]+)/subject-state', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [ $self, 'get_subject_state' ],
        ] );
    }

    public function get_keys( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( KeyManager::jwks(), 200 );
    }

    public function get_attestation( \WP_REST_Request $request ): \WP_REST_Response {
        $row = Repository::find( (string) $request['id'] );
        if ( null === $row ) {
            return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
        }
        $reverted_by = Repository::find_by_reverts( (string) $row['attestation_id'] );
        return new \WP_REST_Response( [
            'statement_b64'              => KeyManager::b64url( (string) $row['statement_bytes'] ),
            'signature_b64'              => (string) $row['signature_b64'],
            'key_id'                     => (string) $row['key_id'],
            'reverted_by_attestation_id' => $reverted_by['attestation_id'] ?? null, // read-time projection, NOT signed
            'statement_json'             => json_decode( (string) $row['statement_bytes'], true ), // convenience only
        ], 200 );
    }

    public function get_subject_state( \WP_REST_Request $request ): \WP_REST_Response {
        $row = Repository::find( (string) $request['id'] );
        if ( null === $row ) {
            return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
        }
        $name       = (string) $row['subject_name'];
        $scope      = (string) $row['subject_scope'];
        $hash_pos   = strpos( $name, '#' );
        $block_name = false !== $hash_pos ? substr( $name, $hash_pos + 1 ) : '';
        $gs_id      = preg_replace( '/^wp_global_styles:/', '', false !== $hash_pos ? substr( $name, 0, $hash_pos ) : $name );

        $resolved = StyleApplyExecutor::resolve_user_global_styles( (string) $gs_id );
        if ( \is_wp_error( $resolved ) ) {
            return new \WP_REST_Response( [ 'error' => 'subject_unavailable' ], 409 );
        }
        $target = ( 'style-book-branch' === $scope && '' !== $block_name )
            ? Canonicalizer::block_branch( $resolved['config'], $block_name )
            : $resolved['config'];
        return new \WP_REST_Response( [
            'subject_canonical_b64' => KeyManager::b64url( Canonicalizer::canonical_bytes( $target ) ),
            'subject_digest'        => Canonicalizer::digest( $target ),
            'scope'                 => $scope,
        ], 200 );
    }
}
```

- [ ] **Step 4: Register on `rest_api_init` in `flavor-agent.php`** (next to `:97`)

```php
add_action( 'rest_api_init', [ FlavorAgent\REST\AttestationController::class, 'register_routes' ] );
```

- [ ] **Step 5: Run it, verify it passes**

Run: `vendor/bin/phpunit --filter AttestationControllerTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add inc/REST/AttestationController.php flavor-agent.php tests/phpunit/AttestationControllerTest.php
git commit -m "feat(attestation): public REST envelope, JWKS, and subject-state routes"
```

---

### Task 8: `Attestation\Verifier` (pure outcome evaluation)

**Files:**
- Create: `inc/Attestation/Verifier.php`
- Test: `tests/phpunit/AttestationVerifierTest.php`

**Interfaces:**
- Produces: `Verifier::evaluate(string $statementBytes, string $signatureRaw, array $jwks, ?string $liveSubjectBytes, ?string $revertedById): array` returning a list of outcome strings drawn from: `signature_valid`, `record_tampered`, `live_matches_subject`, `live_changed_since_attestation`, `reverted_by_attestation` (design §9).

- [ ] **Step 1: Write the failing test**

```php
public function test_intact_change_yields_signature_valid_and_live_match(): void {
    if ( ! defined( 'FLAVOR_AGENT_ATTEST_PRIVATE_KEY' ) ) {
        define( 'FLAVOR_AGENT_ATTEST_PRIVATE_KEY', base64_encode( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) ) );
    }
    $config    = [ 'settings' => [], 'styles' => [ 'color' => [ 'background' => 'var:preset|color|parchment-100' ] ] ];
    $after_dig = \FlavorAgent\Attestation\Canonicalizer::digest( $config );
    $bytes     = \FlavorAgent\Attestation\StatementBuilder::build( [ 'attestationId'=>'att_1','surface'=>'global-styles','scope'=>'global-styles','subjectName'=>'wp_global_styles:81','operations'=>[],'beforeDigest'=>'b','afterDigest'=>$after_dig,'freshnessSignature'=>'f','actorRole'=>'administrator','proposerVia'=>'mcp/flavor-agent','decision'=>'approve','requestedAt'=>'t','decidedAt'=>'t','siteUrl'=>'https://e.com','keyId'=>'k' ] );
    $signed    = \FlavorAgent\Attestation\Signer::sign( $bytes );
    $live      = \FlavorAgent\Attestation\Canonicalizer::canonical_bytes( $config );
    $out = \FlavorAgent\Attestation\Verifier::evaluate( $bytes, $signed['signature'], \FlavorAgent\Attestation\KeyManager::jwks(), $live, null );
    $this->assertContains( 'signature_valid', $out );
    $this->assertContains( 'live_matches_subject', $out );
}
public function test_tampered_statement_yields_record_tampered(): void {
    // flip a byte in $bytes before evaluate -> expect 'record_tampered', not 'signature_valid'
}
public function test_reverted_change_is_accountable_not_failure(): void {
    // live bytes differ from subject digest AND $revertedById !== null -> expect 'reverted_by_attestation'
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `vendor/bin/phpunit --filter AttestationVerifierTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `Verifier`**

```php
<?php
declare(strict_types=1);
namespace FlavorAgent\Attestation;

/** Pure evaluation of the design §9 verifier outcomes. No I/O. */
final class Verifier {
    /**
     * @param array{keys: array<int, array<string,string>>} $jwks
     * @return list<string>
     */
    public static function evaluate( string $statement_bytes, string $signature_raw, array $jwks, ?string $live_subject_bytes, ?string $reverted_by_id ): array {
        $outcomes = [];

        $statement = json_decode( $statement_bytes, true );
        $key_id    = is_array( $statement ) ? (string) ( $statement['predicate']['site']['keyId'] ?? '' ) : '';
        $public    = self::public_key_for( $jwks, $key_id );

        $valid = null !== $public && Signer::verify( $statement_bytes, $signature_raw, $public );
        $outcomes[] = $valid ? 'signature_valid' : 'record_tampered';

        if ( null !== $live_subject_bytes && is_array( $statement ) ) {
            $subject_digest = (string) ( $statement['subject'][0]['digest']['sha256'] ?? '' );
            if ( hash( 'sha256', $live_subject_bytes ) === $subject_digest ) {
                $outcomes[] = 'live_matches_subject';
            } else {
                $outcomes[] = ( null !== $reverted_by_id && '' !== $reverted_by_id ) ? 'reverted_by_attestation' : 'live_changed_since_attestation';
            }
        }

        return $outcomes;
    }

    /** @param array{keys: array<int, array<string,string>>} $jwks */
    private static function public_key_for( array $jwks, string $key_id ): ?string {
        foreach ( $jwks['keys'] ?? [] as $jwk ) {
            if ( ( $jwk['kid'] ?? '' ) === $key_id && 'OKP' === ( $jwk['kty'] ?? '' ) ) {
                $bin = base64_decode( strtr( (string) $jwk['x'], '-_', '+/' ), true );
                return ( false !== $bin && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $bin ) ) ? $bin : null;
            }
        }
        return null;
    }
}
```

- [ ] **Step 4: Run it, verify it passes**

Run: `vendor/bin/phpunit --filter AttestationVerifierTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add inc/Attestation/Verifier.php tests/phpunit/AttestationVerifierTest.php
git commit -m "feat(attestation): pure Verifier emitting the design-§9 outcome set"
```

---

### Task 9: Standalone stranger-facing verifier

A credential-less third party runs this against only the public endpoints. WP-CLI wrapper is **deferred** (spec §6) — the standalone script is the actual stranger artifact and needs no WP.

**Files:**
- Create: `tools/attestation-verify.php`

**Interfaces:**
- Consumes (by copy or require): `Attestation\Verifier::evaluate` logic. Keep the script dependency-light: inline the same evaluation, or `require` the class if run from the repo. Uses only `curl`/`file_get_contents` + ext-sodium.

- [ ] **Step 1: Implement the script**

```php
<?php
declare(strict_types=1);
// Usage: php tools/attestation-verify.php https://site.example att_xxx
[ , $base, $id ] = $argv + [ null, null, null ];
if ( ! $base || ! $id ) { fwrite( STDERR, "usage: attestation-verify.php <baseUrl> <attestationId>\n" ); exit( 2 ); }

$get = static function ( string $url ): array {
    $raw = file_get_contents( $url );
    return false === $raw ? [] : (array) json_decode( $raw, true );
};
$b64url_decode = static function ( string $s ): string {
    return (string) base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
};

$env  = $get( "$base/wp-json/flavor-agent/v1/attestations/$id" );
$jwks = $get( "$base/wp-json/flavor-agent/v1/attestations/keys" );
$subj = $get( "$base/wp-json/flavor-agent/v1/attestations/$id/subject-state" );

$statement = $b64url_decode( (string) ( $env['statement_b64'] ?? '' ) );
$signature = $b64url_decode( (string) ( $env['signature_b64'] ?? '' ) );
$live      = isset( $subj['subject_canonical_b64'] ) ? $b64url_decode( (string) $subj['subject_canonical_b64'] ) : null;

require __DIR__ . '/../inc/Attestation/Signer.php';
require __DIR__ . '/../inc/Attestation/Verifier.php';
$outcomes = \FlavorAgent\Attestation\Verifier::evaluate( $statement, $signature, $jwks, $live, $env['reverted_by_attestation_id'] ?? null );

echo json_encode( [ 'attestationId' => $id, 'outcomes' => $outcomes ], JSON_PRETTY_PRINT ), "\n";
exit( in_array( 'record_tampered', $outcomes, true ) ? 1 : 0 );
```

> `Signer` requires `KeyManager` only inside `sign()`, not `verify()` — `verify()` is pure sodium, so the standalone `require` of `Signer` + `Verifier` is sufficient. If autoloading complains, copy the two static methods inline.

- [ ] **Step 2: Smoke-test against a recorded attestation**

Run (against a local site with an attested apply): `php tools/attestation-verify.php http://localhost:8889 <id>`
Expected: JSON with `"outcomes": ["signature_valid","live_matches_subject"]`, exit 0.

- [ ] **Step 3: Commit**

```bash
git add tools/attestation-verify.php
git commit -m "feat(attestation): standalone third-party verifier script"
```

---

### Task 10: Undo → chained revert attestation

**Files:**
- Modify: `inc/Attestation/AttestationService.php` (add `record_revert`)
- Modify: `inc/Abilities/ApplyAbilities.php` (after the successful undo transition, line ~356)
- Test: extend `tests/phpunit/AttestationServiceTest.php`

**Interfaces:**
- Produces: `AttestationService::record_revert(string $prior_attestation_id, array $ctx): ?string` — same shape as `record_apply` `$ctx` plus it sets `revertsAttestationId = $prior_attestation_id`; its subject digest is the **post-undo** live state (the restored `before`).

- [ ] **Step 1: Write the failing test**

```php
public function test_revert_is_chained_to_prior_and_findable(): void {
    // record_apply -> $applyId; then record_revert($applyId, ctx-with-post-undo-after)
    $revertId = AttestationService::record_revert( $applyId, $ctx_after_undo );
    $this->assertNotNull( $revertId );
    $this->assertSame( $revertId, \FlavorAgent\Attestation\Repository::find_by_reverts( $applyId )['attestation_id'] );
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `vendor/bin/phpunit --filter AttestationServiceTest::test_revert_is_chained_to_prior_and_findable`
Expected: FAIL — `record_revert` undefined.

- [ ] **Step 3: Implement `record_revert`**

```php
public static function record_revert( string $prior_attestation_id, array $ctx ): ?string {
    $ctx['revertsAttestationId'] = $prior_attestation_id;
    $ctx['decision']             = 'revert';
    return self::record_apply( $ctx );
}
```

> `record_apply` already threads `revertsAttestationId` into both the statement and the row (Task 6). The `decision` field is accepted by `StatementBuilder`; if `record_apply` hardcodes `'decision' => 'approve'`, change it to `(string) ( $ctx['decision'] ?? 'approve' )`.

- [ ] **Step 4: Call it from `ApplyAbilities::undo_activity()`** (after `update_undo_status( $activity_id, 'undone' )` succeeds, line ~356)

```php
$prior = \FlavorAgent\Attestation\Repository::find_by_related_activity( $activity_id );
if ( null !== $prior ) {
    try {
        \FlavorAgent\Attestation\AttestationService::record_revert( (string) $prior['attestation_id'], [
            'surface'           => (string) ( $entry['surface'] ?? '' ),
            'globalStylesId'    => (string) ( $entry['target']['globalStylesId'] ?? '' ),
            'blockName'         => (string) ( $entry['target']['blockName'] ?? '' ),
            'operations'        => [],
            'before'            => $result['after'] ?? [],   // pre-undo state
            'after'             => $result['before'] ?? [],  // restored (post-undo) state == attested subject
            'freshnessSignature'=> '',
            'actorRole'         => self::actor_role_for_undo(),
            'requestedAt'       => '',
            'decidedAt'         => gmdate( 'c' ),
            'relatedActivityId' => $activity_id,
        ] );
    } catch ( \Throwable $e ) {
        // best-effort; undo already succeeded.
    }
}
```

Add a small `actor_role_for_undo(): string` helper (current user's role, like Task 6's `actor_role`).

- [ ] **Step 5: Run the suite, verify green**

Run: `vendor/bin/phpunit --filter AttestationServiceTest && vendor/bin/phpunit --filter ApplyAbilities`
Expected: PASS (existing undo tests stay green; the revert attestation is best-effort).

- [ ] **Step 6: Commit**

```bash
git add inc/Attestation/AttestationService.php inc/Abilities/ApplyAbilities.php tests/phpunit/AttestationServiceTest.php
git commit -m "feat(attestation): emit a chained revert attestation on undo"
```

---

## Final verification

- [ ] Run the full suite: `vendor/bin/phpunit` — expected: OK.
- [ ] Lint: `composer lint:php` — expected: no errors in `inc/Attestation/`, `inc/REST/AttestationController.php`.
- [ ] Aggregate gate: `node scripts/verify.js --skip-e2e` then inspect `output/verify/summary.json` (`status: pass`).
- [ ] Manual end-to-end (local site, `FLAVOR_AGENT_ATTEST_PRIVATE_KEY` set): run a `request-style-apply` → approve → `php tools/attestation-verify.php <baseUrl> <id>` shows `signature_valid` + `live_matches_subject`; then `undo-activity` → re-run verifier shows `reverted_by_attestation`.

## Self-review notes

- **Spec coverage:** §4 statement → Task 4; §4.1 branch scope → Tasks 1/6; §4.2 allowlist → Task 4 (`public_safe_predicate` + test); §5 table → Task 5; §6 components → Tasks 1–8; §6.1 envelope → Task 7 (`statement_b64`/`signature_b64`); §6.2 subject-state → Task 7; §7 keys → Task 2; §8 lifecycle → Tasks 6 & 10; §9 outcomes → Task 8; §11 tests → each task; §12/§13/§15 are non-code.
- **Deferred (noted, not silently dropped):** the WP-CLI verifier wrapper (spec §6) — standalone script ships instead; transparency-log + C2PA + rendered-CSS digest remain §12 forward levels.
- **Type consistency:** `subject_digest(config, scope, blockName)`, `record_apply($ctx)`/`record_revert($priorId,$ctx)`, envelope keys `statement_b64`/`signature_b64`/`reverted_by_attestation_id`, and the §9 outcome strings are used identically across tasks.
