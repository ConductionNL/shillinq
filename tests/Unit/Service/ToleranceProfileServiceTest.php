<?php

/**
 * Unit tests for ToleranceProfileService (slice 06 of
 * bookkeeping-purchase-order-3way).
 *
 * Covers REQ-PO3W-006 (configurable tolerance profiles) + REQ-PO3W-004
 * (the "more permissive" rule):
 *  - getApplicableProfile() resolves the most-specific scope
 *    (supplier > category > gl_account > global);
 *  - evaluateWithinTolerance() succeeds when EITHER the absolute
 *    cents threshold OR the basis-points percentage threshold is
 *    satisfied (the more permissive rule);
 *  - evaluateQuantityVariance() compares thousandths against a
 *    basis-points threshold;
 *  - evaluateDateVariance() compares day delta against the absolute
 *    dateToleranceDays;
 *  - retired profiles are skipped during resolution.
 *
 * The OpenRegister ObjectService is stubbed with an in-memory schema-keyed
 * store that honours equality filters so cross-administration data never
 * leaks. Mirrors the slice-05 SupplierInvoiceServiceTest stub.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ToleranceProfileService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the OpenRegister-backed ToleranceProfileService.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ToleranceProfileServiceTest extends TestCase {
	/**
	 * Build the service over an in-memory ObjectService stub.
	 *
	 * @param array<int,array<string,mixed>> $profiles ToleranceProfile rows.
	 *
	 * @return ToleranceProfileService
	 */
	private function buildService(array $profiles): ToleranceProfileService {
		$stub = new class($profiles) {

			/**
			 * Schema rows.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $profiles Tolerance profile rows.
			 */
			public function __construct(array $profiles) {
				$this->data = ['ToleranceProfile' => $profiles];
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return rows for the active schema, applying equality filters.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->data[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$logger = $this->createMock(LoggerInterface::class);

		return new ToleranceProfileService(
			container: $container,
			appConfig: $appConfig,
			logger:    $logger,
		);

	}//end buildService()

	/**
	 * Supplier-scoped profile overrides a global profile (REQ-PO3W-006).
	 *
	 * @return void
	 */
	public function testSupplierScopedProfileOverridesGlobal(): void {
		$service = $this->buildService(
			profiles: [
				['profileId' => 'TP-GLOBAL', 'scope' => 'global', 'scopeReference' => null, 'priceToleranceAmount' => 1000, 'priceTolerancePercentage' => 50, 'status' => 'active', 'administrationId' => 'adm-1'],
				['profileId' => 'TP-SUPPLIER', 'scope' => 'supplier', 'scopeReference' => 'vendor-001', 'priceToleranceAmount' => 0, 'priceTolerancePercentage' => 0, 'status' => 'active', 'administrationId' => 'adm-1'],
			]
		);

		$profile = $service->getApplicableProfile(
			administrationId: 'adm-1',
			candidate: ['supplierId' => 'vendor-001', 'productCategory' => '', 'glAccount' => '']
		);

		self::assertNotNull($profile);
		self::assertSame('TP-SUPPLIER', $profile['profileId']);

	}//end testSupplierScopedProfileOverridesGlobal()

	/**
	 * Global profile is the fallback when no narrower scope matches.
	 *
	 * @return void
	 */
	public function testGlobalProfileWinsWhenNoNarrowerScopeMatches(): void {
		$service = $this->buildService(
			profiles: [
				['profileId' => 'TP-GLOBAL', 'scope' => 'global', 'scopeReference' => null, 'priceToleranceAmount' => 1000, 'priceTolerancePercentage' => 50, 'status' => 'active', 'administrationId' => 'adm-1'],
				['profileId' => 'TP-SUPPLIER', 'scope' => 'supplier', 'scopeReference' => 'vendor-999', 'priceToleranceAmount' => 0, 'priceTolerancePercentage' => 0, 'status' => 'active', 'administrationId' => 'adm-1'],
			]
		);

		$profile = $service->getApplicableProfile(
			administrationId: 'adm-1',
			candidate: ['supplierId' => 'vendor-001', 'productCategory' => '', 'glAccount' => '']
		);

		self::assertNotNull($profile);
		self::assertSame('TP-GLOBAL', $profile['profileId']);

	}//end testGlobalProfileWinsWhenNoNarrowerScopeMatches()

	/**
	 * Retired profiles are skipped during resolution.
	 *
	 * @return void
	 */
	public function testRetiredProfilesAreSkipped(): void {
		$service = $this->buildService(
			profiles: [
				['profileId' => 'TP-GLOBAL-OLD', 'scope' => 'global', 'scopeReference' => null, 'priceToleranceAmount' => 5000, 'priceTolerancePercentage' => 250, 'status' => 'retired', 'administrationId' => 'adm-1'],
				['profileId' => 'TP-GLOBAL-NEW', 'scope' => 'global', 'scopeReference' => null, 'priceToleranceAmount' => 1000, 'priceTolerancePercentage' => 50, 'status' => 'active', 'administrationId' => 'adm-1'],
			]
		);

		$profile = $service->getApplicableProfile(
			administrationId: 'adm-1',
			candidate: ['supplierId' => '', 'productCategory' => '', 'glAccount' => '']
		);

		self::assertNotNull($profile);
		self::assertSame('TP-GLOBAL-NEW', $profile['profileId']);

	}//end testRetiredProfilesAreSkipped()

	/**
	 * Cross-administration profiles never leak — admin scope is enforced.
	 *
	 * @return void
	 */
	public function testCrossAdministrationProfilesAreFiltered(): void {
		$service = $this->buildService(
			profiles: [
				['profileId' => 'TP-OTHER-ADMIN', 'scope' => 'global', 'scopeReference' => null, 'priceToleranceAmount' => 1000, 'priceTolerancePercentage' => 50, 'status' => 'active', 'administrationId' => 'adm-other'],
			]
		);

		$profile = $service->getApplicableProfile(
			administrationId: 'adm-1',
			candidate: ['supplierId' => '', 'productCategory' => '', 'glAccount' => '']
		);

		self::assertNull($profile);

	}//end testCrossAdministrationProfilesAreFiltered()

	/**
	 * Category scope overrides gl_account scope which overrides global.
	 *
	 * @return void
	 */
	public function testCategoryScopeOverridesGlAccountAndGlobal(): void {
		$service = $this->buildService(
			profiles: [
				['profileId' => 'TP-GL', 'scope' => 'gl_account', 'scopeReference' => '4400', 'priceToleranceAmount' => 100, 'priceTolerancePercentage' => 10, 'status' => 'active', 'administrationId' => 'adm-1'],
				['profileId' => 'TP-CAT', 'scope' => 'category', 'scopeReference' => 'electronics', 'priceToleranceAmount' => 500, 'priceTolerancePercentage' => 25, 'status' => 'active', 'administrationId' => 'adm-1'],
				['profileId' => 'TP-GLOBAL', 'scope' => 'global', 'scopeReference' => null, 'priceToleranceAmount' => 1000, 'priceTolerancePercentage' => 50, 'status' => 'active', 'administrationId' => 'adm-1'],
			]
		);

		$profile = $service->getApplicableProfile(
			administrationId: 'adm-1',
			candidate: ['supplierId' => '', 'productCategory' => 'electronics', 'glAccount' => '4400']
		);

		self::assertNotNull($profile);
		self::assertSame('TP-CAT', $profile['profileId']);

	}//end testCategoryScopeOverridesGlAccountAndGlobal()

	/**
	 * evaluateWithinTolerance — exact match always passes (no profile needed).
	 *
	 * @return void
	 */
	public function testExactMatchPassesWithoutProfile(): void {
		$service = $this->buildService(profiles: []);

		self::assertTrue($service->evaluateWithinTolerance(expectedCents: 18500, actualCents: 18500, profile: null));

	}//end testExactMatchPassesWithoutProfile()

	/**
	 * REQ-PO3W-004 example: €18,547 vs €18,500 is a €47 / 0.25 % delta;
	 * within tolerance of €10-absolute-OR-0.5%-percentage because the
	 * percentage threshold (whichever is more permissive) is satisfied.
	 *
	 * @return void
	 */
	public function testMorePermissiveRulePassesViaPercentageWhenAbsoluteFails(): void {
		$service = $this->buildService(profiles: []);

		// €18,547 actual against €18,500 expected, in cents:
		// expected 1850000, actual 1854700, delta 4700 cents (€47).
		// Profile: absolute 1000 cents (€10), percentage 50 bps (0.5%).
		// - Absolute threshold: 4700 > 1000 → fails.
		// - Percentage threshold: 1850000 × 50 / 10000 = 9250 cents → passes.
		// The MORE PERMISSIVE result wins → within tolerance.
		$profile = [
			'priceToleranceAmount' => 1000,
			'priceTolerancePercentage' => 50,
		];

		self::assertTrue(
			$service->evaluateWithinTolerance(
				expectedCents: 1850000,
				actualCents:   1854700,
				profile:       $profile
			)
		);

	}//end testMorePermissiveRulePassesViaPercentageWhenAbsoluteFails()

	/**
	 * Small absolute deltas pass via the absolute threshold even when the
	 * percentage threshold would fail.
	 *
	 * @return void
	 */
	public function testMorePermissiveRulePassesViaAbsoluteWhenPercentageFails(): void {
		$service = $this->buildService(profiles: []);

		// €5.00 expected, €5.10 actual = 10 cent delta = 2% relative.
		// Absolute threshold 50 cents → passes. Percentage threshold 50 bps
		// (0.5%) → fails. MORE PERMISSIVE = within tolerance.
		$profile = [
			'priceToleranceAmount' => 50,
			'priceTolerancePercentage' => 50,
		];

		self::assertTrue(
			$service->evaluateWithinTolerance(
				expectedCents: 500,
				actualCents:   510,
				profile:       $profile
			)
		);

	}//end testMorePermissiveRulePassesViaAbsoluteWhenPercentageFails()

	/**
	 * Delta exceeds both thresholds → out of tolerance.
	 *
	 * @return void
	 */
	public function testWithinToleranceFailsWhenBothThresholdsExceeded(): void {
		$service = $this->buildService(profiles: []);

		$profile = [
			'priceToleranceAmount' => 100,
			'priceTolerancePercentage' => 50,
		];

		// €100 expected vs €110 actual = 1000 cent delta = 10% relative.
		// 1000 > 100 absolute AND 1000 > 50 cents (€100 × 0.5%) → both fail.
		self::assertFalse(
			$service->evaluateWithinTolerance(
				expectedCents: 10000,
				actualCents:   11000,
				profile:       $profile
			)
		);

	}//end testWithinToleranceFailsWhenBothThresholdsExceeded()

	/**
	 * Quantity variance — proportional, basis points (REQ-PO3W-004).
	 *
	 * @return void
	 */
	public function testEvaluateQuantityVarianceUsesBasisPoints(): void {
		$service = $this->buildService(profiles: []);

		// 180 expected vs 180.5 actual, in thousandths: expected 180000,
		// actual 180500, delta 500 / 180000 = 27.7 bps. Profile 100 bps
		// (1%) → passes.
		self::assertTrue(
			$service->evaluateQuantityVariance(
				expectedThousandths: 180000,
				actualThousandths:   180500,
				profile:             ['quantityTolerancePercentage' => 100]
			)
		);

		// Same delta against a 10 bps (0.1%) tolerance → fails.
		self::assertFalse(
			$service->evaluateQuantityVariance(
				expectedThousandths: 180000,
				actualThousandths:   180500,
				profile:             ['quantityTolerancePercentage' => 10]
			)
		);

	}//end testEvaluateQuantityVarianceUsesBasisPoints()

	/**
	 * Date variance — absolute day delta (REQ-PO3W-004).
	 *
	 * @return void
	 */
	public function testEvaluateDateVarianceComparesAbsoluteDays(): void {
		$service = $this->buildService(profiles: []);

		$profile = ['dateToleranceDays' => 3];

		self::assertTrue($service->evaluateDateVariance(deltaDays: 0, profile: $profile));
		self::assertTrue($service->evaluateDateVariance(deltaDays: 3, profile: $profile));
		self::assertTrue($service->evaluateDateVariance(deltaDays: -3, profile: $profile));
		self::assertFalse($service->evaluateDateVariance(deltaDays: 4, profile: $profile));
		self::assertFalse($service->evaluateDateVariance(deltaDays: -5, profile: $profile));

	}//end testEvaluateDateVarianceComparesAbsoluteDays()
}//end class
