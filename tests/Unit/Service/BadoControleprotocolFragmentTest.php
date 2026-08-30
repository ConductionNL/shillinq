<?php

/**
 * Unit tests for the bookkeeping-bado-controleprotocol register fragment.
 *
 * Verifies the T3 BADO fragment is valid JSON, declares the 7 expected schemas,
 * that every lifecycle `requires` reference resolves to a real public method on
 * BadoControleprotocolService (ADR-031 exception-path contract), and that every
 * seed object references a declared schema (ADR-037).
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
 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BadoControleprotocolService;
use PHPUnit\Framework\TestCase;

/**
 * Validates the BADO controleprotocol register fragment + its service contract.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BadoControleprotocolFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-bado-controleprotocol.json';

	/**
	 * Decoded fragment.
	 *
	 * @var array<string,mixed>
	 */
	private array $fragment = [];

	/**
	 * Decode the fragment once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fragment = (array)json_decode((string)file_get_contents($this->fragmentPath), true);

	}//end setUp()

	/**
	 * The fragment is present and valid JSON.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertArrayHasKey('components', $this->fragment);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares the 7 BADO schemas (REQ-001..REQ-008).
	 *
	 * @return void
	 */
	public function testDeclaresSevenSchemas(): void {
		$schemas = array_keys((array)$this->fragment['components']['schemas']);
		$expected = [
			'Controleprotocol',
			'ToleranceMatrix',
			'Materialiteit',
			'AuditSample',
			'AuditFinding',
			'VerklaringDraft',
			'SiSaAssurance',
		];
		foreach ($expected as $schema) {
			self::assertContains($schema, $schemas);
		}

		self::assertCount(7, $schemas);

	}//end testDeclaresSevenSchemas()

	/**
	 * Every lifecycle `requires` reference resolves to a public service method.
	 *
	 * Guards against drift between the declarative fragment and the ADR-031
	 * exception-path service — a dangling `requires` would silently disable the
	 * precondition at runtime (fail-open), which this test forbids.
	 *
	 * @return void
	 */
	public function testLifecycleRequiresResolveToServiceMethods(): void {
		$json = (string)file_get_contents($this->fragmentPath);
		$matches = [];
		preg_match_all('/BadoControleprotocolService::([A-Za-z]+)/', $json, $matches);
		$methods = array_unique($matches[1]);

		self::assertNotEmpty($methods, 'fragment should reference at least one service method');

		foreach ($methods as $method) {
			self::assertTrue(
				method_exists(BadoControleprotocolService::class, $method),
				'BadoControleprotocolService is missing referenced method: ' . $method
			);
		}

	}//end testLifecycleRequiresResolveToServiceMethods()

	/**
	 * Every seed object references one of the declared schemas (ADR-037).
	 *
	 * @return void
	 */
	public function testSeedObjectsReferenceDeclaredSchemas(): void {
		$schemas = array_keys((array)$this->fragment['components']['schemas']);
		$objects = (array)($this->fragment['components']['objects'] ?? []);

		self::assertNotEmpty($objects, 'fragment should ship worked-example seeds');

		foreach ($objects as $slug => $object) {
			$schema = (string)($object['@self']['schema'] ?? '');
			self::assertContains($schema, $schemas, 'seed ' . $slug . ' references unknown schema ' . $schema);
		}

	}//end testSeedObjectsReferenceDeclaredSchemas()

	/**
	 * The ToleranceMatrix ceilings cannot exceed the BADO statutory maxima (REQ-002).
	 *
	 * @return void
	 */
	public function testToleranceCeilingsHonourStatutoryMaxima(): void {
		$props = (array)$this->fragment['components']['schemas']['ToleranceMatrix']['properties'];
		self::assertSame(1, $props['faithfulnessApprovalCeiling']['maximum']);
		self::assertSame(3, $props['faithfulnessQualificationCeiling']['maximum']);
		self::assertSame(1, $props['lawfulnessApprovalCeiling']['maximum']);
		self::assertSame(3, $props['lawfulnessQualificationCeiling']['maximum']);

	}//end testToleranceCeilingsHonourStatutoryMaxima()
}//end class
