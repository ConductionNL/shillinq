<?php

/**
 * Unit tests for the bookkeeping-treasury-ihb register fragment.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-treasury-ihb/specs/bookkeeping-treasury-ihb/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the T3 treasury / in-house-bank fragment is valid JSON, declares the
 * nine new schemas with their lifecycles, carries seed objects, and merges
 * additively onto the monolith without dropping existing schemas (ADR-037).
 */
final class TreasuryIhbFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-treasury-ihb.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$m->setAccessible(true);
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		return $m->invoke(null, $base, $overlay);
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end merge()

	/**
	 * Decode the fragment JSON.
	 *
	 * @return array<string,mixed>
	 */
	private function fragment(): array {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(expected: JSON_ERROR_NONE, actual: json_last_error(), message: json_last_error_msg());
		self::assertIsArray(actual: $data);
		return $data;
	}//end fragment()

	/**
	 * The fragment file is present and valid JSON.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists(filename: $this->fragmentPath);
		$data = $this->fragment();
		self::assertArrayHasKey(key: 'schemas', array: $data['components']);
	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares all nine treasury schemas.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresNineSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		$expected = [
			'CashPool',
			'CashPoolMembership',
			'IntercompanyLoan',
			'IntercompanyTransaction',
			'FXContract',
			'FXPosition',
			'CashForecast',
			'BankReconciliationGroup',
			'LiquidityKPI',
		];
		foreach ($expected as $name) {
			self::assertArrayHasKey(key: $name, array: $schemas, message: "Fragment must declare $name");
		}

		self::assertCount(expectedCount: 9, haystack: $schemas);
	}//end testFragmentDeclaresNineSchemas()

	/**
	 * State-bearing schemas declare an x-openregister-lifecycle on the state field.
	 *
	 * @return void
	 */
	public function testStateBearingSchemasDeclareLifecycle(): void {
		$schemas = $this->fragment()['components']['schemas'];

		foreach (['CashPool', 'IntercompanyLoan', 'IntercompanyTransaction', 'FXContract', 'BankReconciliationGroup'] as $name) {
			self::assertArrayHasKey(
				key: 'x-openregister-lifecycle',
				array: $schemas[$name],
				message: "$name must declare a lifecycle"
			);
			self::assertSame(expected: 'state', actual: $schemas[$name]['x-openregister-lifecycle']['field']);
		}
	}//end testStateBearingSchemasDeclareLifecycle()

	/**
	 * The IntercompanyTransaction post transition references the period-close guard.
	 *
	 * @return void
	 */
	public function testIntercompanyTransactionPostReferencesGuard(): void {
		$schemas = $this->fragment()['components']['schemas'];
		$transitions = $schemas['IntercompanyTransaction']['x-openregister-lifecycle']['transitions'];

		self::assertArrayHasKey(key: 'post', array: $transitions);
		self::assertSame(
			expected: 'OCA\\Shillinq\\Lifecycle\\IntercompanyTransactionGuard::canPost',
			actual: $transitions['post']['requires'],
			message: 'Post transition must be gated by the period-close guard (REQ-IHB-005).'
		);
	}//end testIntercompanyTransactionPostReferencesGuard()

	/**
	 * The IntercompanyLoan activate transition references the arm's-length guard.
	 *
	 * @return void
	 */
	public function testIntercompanyLoanActivateReferencesGuard(): void {
		$schemas = $this->fragment()['components']['schemas'];
		$transitions = $schemas['IntercompanyLoan']['x-openregister-lifecycle']['transitions'];

		self::assertSame(
			expected: 'OCA\\Shillinq\\Lifecycle\\IntercompanyTransactionGuard::canActivateLoan',
			actual: $transitions['activate']['requires'],
			message: 'Loan activation must reference the arm\'s-length warning guard (REQ-IHB-004).'
		);
	}//end testIntercompanyLoanActivateReferencesGuard()

	/**
	 * The fragment ships seed objects: 2 pools, 5 memberships, 2 loans, 3 FX contracts.
	 *
	 * @return void
	 */
	public function testFragmentShipsSeedObjects(): void {
		$objects = $this->fragment()['objects'];
		self::assertIsArray(actual: $objects);

		$bySchema = [];
		foreach ($objects as $object) {
			self::assertArrayHasKey(
				key: '@self',
				array: $object,
				message: 'Seed object must carry an @self envelope (REQ-IHB-012).'
			);
			self::assertSame(expected: 'shillinq', actual: $object['@self']['register']);
			self::assertArrayHasKey(key: 'slug', array: $object['@self']);
			$schema = $object['@self']['schema'];
			$bySchema[$schema] = (($bySchema[$schema] ?? 0) + 1);
		}

		self::assertSame(expected: 2, actual: $bySchema['CashPool']);
		self::assertSame(expected: 5, actual: $bySchema['CashPoolMembership']);
		self::assertSame(expected: 2, actual: $bySchema['IntercompanyLoan']);
		self::assertSame(expected: 3, actual: $bySchema['FXContract']);
	}//end testFragmentShipsSeedObjects()

	/**
	 * Seed pools cover both a notional and a zero-balance pool (REQ-IHB-012).
	 *
	 * @return void
	 */
	public function testSeedPoolsCoverNotionalAndZeroBalance(): void {
		$types = [];
		foreach ($this->fragment()['objects'] as $object) {
			if ($object['@self']['schema'] === 'CashPool') {
				$types[] = $object['type'];
			}
		}

		self::assertContains(needle: 'notional', haystack: $types);
		self::assertContains(needle: 'zero-balance', haystack: $types);
	}//end testSeedPoolsCoverNotionalAndZeroBalance()

	/**
	 * Seed loans cover one fixed and one floating rate (REQ-IHB-012).
	 *
	 * @return void
	 */
	public function testSeedLoansCoverFixedAndFloating(): void {
		$rateTypes = [];
		foreach ($this->fragment()['objects'] as $object) {
			if ($object['@self']['schema'] === 'IntercompanyLoan') {
				$rateTypes[] = $object['rateType'];
			}
		}

		self::assertContains(needle: 'fixed', haystack: $rateTypes);
		self::assertContains(needle: 'floating', haystack: $rateTypes);
	}//end testSeedLoansCoverFixedAndFloating()

	/**
	 * Merging the fragment onto the monolith adds the nine schemas without
	 * dropping any pre-existing schema (ADR-037 additive union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$schemasBefore = array_keys($base['components']['schemas']);

		$merged = $this->merge(base: $base, overlay: $frag);
		$schemas = $merged['components']['schemas'];

		// New schemas present.
		self::assertArrayHasKey(key: 'CashPool', array: $schemas);
		self::assertArrayHasKey(key: 'LiquidityKPI', array: $schemas);

		// Pre-existing schemas survive the merge.
		foreach ($schemasBefore as $name) {
			self::assertArrayHasKey(key: $name, array: $schemas, message: "Monolith schema $name must survive the merge");
		}

		// Seed objects concatenate onto the base objects list.
		self::assertGreaterThanOrEqual(expected: 12, actual: count($merged['objects']));
	}//end testFragmentMergesAdditivelyOntoMonolith()
}//end class
