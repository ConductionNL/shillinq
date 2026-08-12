<?php

/**
 * Rule Catalogue
 *
 * Versioned, read-only catalogue of operative bookkeeping rules derived from
 * standards and laws (Category: the granular "what must I do/check" layer, below
 * the regime-level ComplianceCatalogue and the per-tenant StandardsPolicy). Rules
 * span invoicing/e-invoicing, VAT, record retention, ledger integrity, chart of
 * accounts, reporting, and the recognition/measurement/presentation/disclosure
 * requirements of the accounting frameworks — see docs/standards/.
 *
 * Rules are facts about standards/laws (identical for every tenant, changing only
 * with regulation), so they ship as versioned static data — one JSON file per
 * domain under lib/Standards/rules/ (see rules/SCHEMA.md) — NOT as OpenRegister
 * config. This class loads + merges those files and exposes query helpers for
 * business logic; it is the machine-readable source for turning rules into specs.
 *
 * @category Standards
 * @package  OCA\Shillinq\Standards
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/accounting-standards-policy/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Squiz.Operators.ComparisonOperatorUsage, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards;

/**
 * Read-only accessor over the per-domain rule JSON files.
 *
 * @SuppressWarnings(PHPMD.ShortVariable) Pre-existing debt (issue #506):
 *     not in the project's curated idiomatic-abbreviation allowlist;
 *     deferred pending a dedicated rename pass.
 */
final class RuleCatalogue {

	/**
	 * Catalogue version — bump on any change to the rule files.
	 *
	 * @var string
	 */
	public const VERSION = '2026-06b';

	/**
	 * Required keys on every rule.
	 *
	 * @var string[]
	 */
	private const REQUIRED_KEYS = [
		'id',
		'domain',
		'jurisdiction',
		'framework',
		'source',
		'statement',
		'severity',
	];

	/**
	 * Loaded + merged rules, memoised.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $cache = null;

	/**
	 * All rules from every `rules/*.json` file, merged. Malformed entries
	 * (missing a required key) are skipped defensively.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		if (self::$cache !== null) {
			return self::$cache;
		}

		$rules = [];
		foreach (glob(__DIR__ . '/rules/*.json') ?: [] as $file) {
			$decoded = json_decode((string)file_get_contents($file), true);
			if (is_array($decoded) === false || isset($decoded['rules']) === false || is_array($decoded['rules']) === false) {
				continue;
			}

			foreach ($decoded['rules'] as $rule) {
				if (self::isWellFormed($rule) === true) {
					$rules[] = $rule;
				}
			}
		}

		self::$cache = $rules;
		return $rules;
	}//end all()

	/**
	 * Rules in a given domain (invoicing, vat, retention, ...).
	 *
	 * @param string $domain Domain key.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function byDomain(string $domain): array {
		return self::where('domain', $domain);
	}//end byDomain()

	/**
	 * Rules attributed to a given framework/law (en-16931, ifrs-15, gobd, ...).
	 *
	 * @param string $framework Framework key.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function byFramework(string $framework): array {
		return self::where('framework', $framework);
	}//end byFramework()

	/**
	 * Rules for a jurisdiction, including `EU`-wide and `global` rules.
	 *
	 * @param string $jurisdiction ISO alpha-2, or 'EU' / 'US' / 'global'.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function byJurisdiction(string $jurisdiction): array {
		$code = strtoupper($jurisdiction);
		return array_values(
			array_filter(
				self::all(),
				static function (array $rule) use ($code): bool {
					$j = strtoupper((string)$rule['jurisdiction']);
					return $j === $code || $j === 'GLOBAL' || ($code !== 'US' && $j === 'EU');
				}
			)
		);

	}//end byJurisdiction()

	/**
	 * Only the machine-checkable rules (enforceable by the bookkeeping engine).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function machineCheckable(): array {
		return array_values(
			array_filter(
				self::all(),
				static function (array $rule): bool {
					return ($rule['machineCheckable'] ?? false) === true;
				}
			)
		);

	}//end machineCheckable()

	/**
	 * Total rule count.
	 *
	 * @return int
	 */
	public static function count(): int {
		return count(self::all());
	}//end count()

	/**
	 * Per-domain rule counts, keyed by domain.
	 *
	 * @return array<string, int>
	 */
	public static function countByDomain(): array {
		$counts = [];
		foreach (self::all() as $rule) {
			$domain = (string)$rule['domain'];
			$counts[$domain] = (($counts[$domain] ?? 0) + 1);
		}

		ksort($counts);
		return $counts;
	}//end countByDomain()

	/**
	 * The catalogue version string.
	 *
	 * @return string
	 */
	public static function version(): string {
		return self::VERSION;
	}//end version()

	/**
	 * Reset the memoised cache (test hook).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$cache = null;

	}//end reset()

	/**
	 * Filter all rules where $key === $value.
	 *
	 * @param string $key Rule key.
	 * @param string $value Expected value.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function where(string $key, string $value): array {
		return array_values(
			array_filter(
				self::all(),
				static function (array $rule) use ($key, $value): bool {
					return ($rule[$key] ?? null) === $value;
				}
			)
		);

	}//end where()

	/**
	 * Whether a decoded rule has all required keys.
	 *
	 * @param mixed $rule Decoded rule.
	 *
	 * @return bool
	 */
	private static function isWellFormed(mixed $rule): bool {
		if (is_array($rule) === false) {
			return false;
		}

		foreach (self::REQUIRED_KEYS as $key) {
			if (isset($rule[$key]) === false || $rule[$key] === '') {
				return false;
			}
		}

		return true;
	}//end isWellFormed()
}//end class
