<?php

/**
 * Check Provider interface
 *
 * A per-domain contributor of executable rule checks to the RuleEngine. Each
 * implementation lives in its own file under lib/Standards/Checks/ and is
 * auto-discovered by RuleEngine::providers(), so a new compliance domain can add
 * an executable check per corpus rule without editing RuleEngine itself.
 *
 * Implementations MUST be side-effect free and reference only real RuleCatalogue
 * rule ids. Each predicate is `fn(array $object, array $context): bool` — true when
 * the rule is satisfied. Providers may also declare the test-data field defaults
 * their checks expect via seedSpec(), which RuleTestDataSeeder applies.
 *
 * @category Standards
 * @package  OCA\Shillinq\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards\Checks;

/**
 * Contract for a per-domain set of executable rule checks.
 */
interface CheckProvider {
	/**
	 * The domain's checks, keyed by OpenRegister object type then by RuleCatalogue
	 * rule id. Each value is a predicate `fn(array $object, array $context): bool`.
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array;

	/**
	 * Test-data field defaults this provider's checks expect, keyed by object type.
	 * Each entry is `[fieldName => defaultValue]`; the seeder backfills any missing
	 * field on the seeded objects of that type. Return [] when no seeding is needed.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array;
}//end interface
