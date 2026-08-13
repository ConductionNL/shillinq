<?php

/**
 * Unit tests for the add-shillinq-bookkeeping-operations register fragment.
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
 * @spec openspec/changes/add-shillinq-bookkeeping-operations/tasks.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the T3 NL-compliance fragment is valid JSON, declares the missing
 * schemas, and merges additively onto the monolith Account schema (ADR-037).
 */
final class BookkeepingOperationsFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/add-shillinq-bookkeeping-operations.json';

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
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * The fragment file is present and valid JSON.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		self::assertArrayHasKey('schemas', $data['components']);
	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares every schema that was missing from the monolith.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresMissingSchemas(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		$schemas = $data['components']['schemas'];

		$expected = [
			'VatReturn',
			'IcpStatement',
			'VatCorrection',
			'VatTariff',
			'BbvAccountMapping',
			'BbvTaakveld',
			'BcfClaim',
			'ZzpDeduction',
			'IbAangifteExport',
			'SchatkistPosition',
			'RepaymentInstallment',
		];
		foreach ($expected as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
		}

		// Subsidie was consolidated OUT of this fragment into the canonical
		// monolith definition (commit 07709a0f, prereq for
		// abstract-order-primitive) — assert the canonical home still carries
		// it so the consolidation never silently regresses.
		$monolith = json_decode((string)file_get_contents($this->registerPath), true);
		self::assertArrayHasKey(
			'Subsidie',
			$monolith['components']['schemas'],
			'Canonical Subsidie must live in the monolith after the 07709a0f consolidation'
		);
	}//end testFragmentDeclaresMissingSchemas()

	/**
	 * State-bearing schemas declare an x-openregister-lifecycle block.
	 *
	 * @return void
	 */
	public function testStateBearingSchemasDeclareLifecycle(): void {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		$schemas = $data['components']['schemas'];

		foreach (['VatReturn', 'BcfClaim', 'IcpStatement', 'VatCorrection'] as $name) {
			self::assertArrayHasKey('x-openregister-lifecycle', $schemas[$name], "$name must declare a lifecycle");
			self::assertSame('state', $schemas[$name]['x-openregister-lifecycle']['field']);
		}

		// Subsidie's lifecycle moved with it to the canonical monolith
		// definition (commit 07709a0f) — keep the state-bearing guarantee.
		$monolith = json_decode((string)file_get_contents($this->registerPath), true);
		$subsidy = $monolith['components']['schemas']['Subsidie'];
		self::assertArrayHasKey('x-openregister-lifecycle', $subsidy, 'Subsidie must declare a lifecycle');
		self::assertSame('state', $subsidy['x-openregister-lifecycle']['field']);
	}//end testStateBearingSchemasDeclareLifecycle()

	/**
	 * Merging the fragment onto the monolith adds the schemas and additively
	 * extends Account with isSchatkistAccount without dropping existing fields.
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = json_decode((string)file_get_contents($this->fragmentPath), true);

		$accountFieldCountBefore = count($base['components']['schemas']['Account']['properties']);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		// New schemas present.
		self::assertArrayHasKey('VatReturn', $schemas);
		self::assertArrayHasKey('Subsidie', $schemas);

		// Account extended additively (REQ-SBK-002).
		self::assertArrayHasKey('isTreasuryAccount', $schemas['Account']['properties']);
		self::assertGreaterThan(
			$accountFieldCountBefore,
			count($schemas['Account']['properties']),
			'Account must gain a field, not lose any'
		);
		// Pre-existing Account fields survive the merge.
		foreach ($base['components']['schemas']['Account']['properties'] as $field => $_) {
			self::assertArrayHasKey($field, $schemas['Account']['properties'], "Account.$field must survive merge");
		}
	}//end testFragmentMergesAdditivelyOntoMonolith()
}//end class
