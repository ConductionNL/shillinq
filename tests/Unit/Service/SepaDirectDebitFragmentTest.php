<?php

/**
 * Unit tests for the bookkeeping-sepa-direct-debit register fragment.
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
 * @spec openspec/changes/bookkeeping-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the SEPA Direct Debit fragment is valid JSON, declares the five
 * new schemas with lifecycle/RBAC, and merges additively onto the monolith
 * (ADR-037) without dropping any existing schema.
 *
 * @spec openspec/changes/bookkeeping-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md
 */
final class SepaDirectDebitFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-sepa-direct-debit.json';

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
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * Load and decode the fragment as an array.
	 *
	 * @return array<string,mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents($this->fragmentPath), true);
	}//end fragment()

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
	 * The fragment declares all five SEPA schemas (REQ-SDD-001..007).
	 *
	 * @return void
	 */
	public function testFragmentDeclaresFiveSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (['SepaMandate', 'DirectDebitCollection', 'DirectDebitBatch', 'RTransaction', 'PreNotification'] as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
		}
	}//end testFragmentDeclaresFiveSchemas()

	/**
	 * Mandate and collection declare a status-field lifecycle with guard refs.
	 *
	 * @return void
	 */
	public function testStateBearingSchemasDeclareLifecycle(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$mandate = $schemas['SepaMandate']['x-openregister-lifecycle'];
		self::assertSame('status', $mandate['field']);
		self::assertSame('pending', $mandate['initialState']);
		self::assertStringContainsString(
			'MandateGuard::canCancel',
			$mandate['transitions']['cancel']['requires']
		);

		$collection = $schemas['DirectDebitCollection']['x-openregister-lifecycle'];
		self::assertSame('status', $collection['field']);
		self::assertStringContainsString(
			'RepostingEligibilityGuard::canRepost',
			$collection['transitions']['repost']['requires']
		);
	}//end testStateBearingSchemasDeclareLifecycle()

	/**
	 * sequenceType is an enum, never free-form, so operator input is constrained.
	 *
	 * @return void
	 */
	public function testSequenceTypeIsEnumerated(): void {
		$schemas = $this->fragment()['components']['schemas'];
		$seq = $schemas['DirectDebitCollection']['properties']['sequenceType'];
		self::assertSame(['FRST', 'RCUR', 'OOFF', 'FNAL'], $seq['enum']);
	}//end testSequenceTypeIsEnumerated()

	/**
	 * Merging the fragment onto the monolith adds the SEPA schemas without
	 * dropping any existing schema (ADR-037 additive union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$existingSchemas = array_keys($base['components']['schemas']);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		// New schemas present.
		self::assertArrayHasKey('SepaMandate', $schemas);
		self::assertArrayHasKey('DirectDebitBatch', $schemas);

		// No existing schema dropped.
		foreach ($existingSchemas as $name) {
			self::assertArrayHasKey($name, $schemas, "Existing schema $name must survive merge");
		}
	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * The seed objects all bind to the shillinq register (importable).
	 *
	 * @return void
	 */
	public function testSeedObjectsTargetShillinqRegister(): void {
		$objects = $this->fragment()['objects'];
		self::assertNotEmpty($objects);
		foreach ($objects as $object) {
			self::assertSame('shillinq', $object['@self']['register']);
		}
	}//end testSeedObjectsTargetShillinqRegister()
}//end class
