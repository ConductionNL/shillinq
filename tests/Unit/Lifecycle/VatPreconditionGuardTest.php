<?php

/**
 * Unit tests for VatPreconditionGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-nl-btw-invoice/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\VatPreconditionGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for VatPreconditionGuard covering REQ-VAT-002 and REQ-VAT-006.
 *
 * Covers:
 * - Product line with permitted rate (21%) returns true
 * - Service line with permitted rate (9%) returns true
 * - Service line with forbidden rate (6%) returns false
 * - Exempt line with non-zero rate (21%) returns false
 * - Override record permits otherwise-forbidden combination
 * - Missing VAT GL accounts blocks issuance (returns false)
 * - Exception in ObjectService causes fail-closed (returns false)
 * - Mixed valid lines all pass
 */
class VatPreconditionGuardTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var VatPreconditionGuard
	 */
	private VatPreconditionGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new VatPreconditionGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter(
				$this->buildObjectServiceStub(vatGlAccounts: [], lines: [], overrides: [])
			),
		);

	}//end setUp()

	/**
	 * Point the guard at the given duck-typed ObjectService store.
	 *
	 * The store is a constructor dependency since ADR-084, so the guard has to
	 * be rebuilt whenever a test seeds different records.
	 *
	 * @param object $store The in-memory ObjectService double.
	 *
	 * @return void
	 */
	private function wireObjectService(object $store): void {
		$this->container->method('get')->willReturn($store);

		$this->guard = new VatPreconditionGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end wireObjectService()

	/**
	 * Product line with permitted rate (21%) and configured GL accounts returns true.
	 *
	 * @return void
	 */
	public function testProductLineWith21PercentReturnsTrue(): void {
		$lines = [
			['invoiceId' => 'inv-1', 'lineSequence' => 1, 'serviceCategory' => 'product', 'vatRate' => 21],
		];

		$this->wireObjectService(
			store: $this->buildObjectServiceStub(
				vatGlAccounts: [$this->defaultGlAccounts()],
				lines: $lines,
				overrides: []
			)
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->validate(invoiceId: 'inv-1', administrationId: 'adm-1'));

	}//end testProductLineWith21PercentReturnsTrue()

	/**
	 * Service line with permitted rate (9%) returns true per REQ-VAT-002.
	 *
	 * @return void
	 */
	public function testServiceLineWith9PercentReturnsTrue(): void {
		$lines = [
			['invoiceId' => 'inv-2', 'lineSequence' => 1, 'serviceCategory' => 'service', 'vatRate' => 9],
		];

		$this->wireObjectService(
			store: $this->buildObjectServiceStub(
				vatGlAccounts: [$this->defaultGlAccounts()],
				lines: $lines,
				overrides: []
			)
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->validate(invoiceId: 'inv-2', administrationId: 'adm-1'));

	}//end testServiceLineWith9PercentReturnsTrue()

	/**
	 * Service line with rate 6% (books-only) returns false — service does not permit 6%.
	 *
	 * @return void
	 */
	public function testServiceLineWith6PercentReturnsFalse(): void {
		$lines = [
			['invoiceId' => 'inv-3', 'lineSequence' => 1, 'serviceCategory' => 'service', 'vatRate' => 6],
		];

		$this->wireObjectService(
			store: $this->buildObjectServiceStub(
				vatGlAccounts: [$this->defaultGlAccounts()],
				lines: $lines,
				overrides: []
			)
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->validate(invoiceId: 'inv-3', administrationId: 'adm-1'));

	}//end testServiceLineWith6PercentReturnsFalse()

	/**
	 * Exempt line with rate 21% returns false — exempt only permits 0%.
	 *
	 * @return void
	 */
	public function testExemptLineWithNonZeroRateReturnsFalse(): void {
		$lines = [
			['invoiceId' => 'inv-4', 'lineSequence' => 1, 'serviceCategory' => 'exempt', 'vatRate' => 21],
		];

		$this->wireObjectService(
			store: $this->buildObjectServiceStub(
				vatGlAccounts: [$this->defaultGlAccounts()],
				lines: $lines,
				overrides: []
			)
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->validate(invoiceId: 'inv-4', administrationId: 'adm-1'));

	}//end testExemptLineWithNonZeroRateReturnsFalse()

	/**
	 * ServiceCategoryOverride record permits an otherwise-forbidden combination.
	 *
	 * Service + 6% is normally forbidden, but an override record permits it.
	 *
	 * @return void
	 */
	public function testOverrideRecordPermitsForbiddenCombination(): void {
		$lines = [
			['invoiceId' => 'inv-5', 'lineSequence' => 1, 'serviceCategory' => 'service', 'vatRate' => 6],
		];

		$override = [
			'administrationId' => 'adm-1',
			'serviceCategory' => 'service',
			'vatRate' => 6,
			'reason' => 'Special educational services agreement',
		];

		$this->wireObjectService(
			store: $this->buildObjectServiceStub(
				vatGlAccounts: [$this->defaultGlAccounts()],
				lines: $lines,
				overrides: [$override]
			)
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->validate(invoiceId: 'inv-5', administrationId: 'adm-1'));

	}//end testOverrideRecordPermitsForbiddenCombination()

	/**
	 * Missing VATGLAccounts record blocks issuance (returns false) per REQ-VAT-006.
	 *
	 * @return void
	 */
	public function testMissingVatGlAccountsReturnsFalse(): void {
		$lines = [
			['invoiceId' => 'inv-6', 'lineSequence' => 1, 'serviceCategory' => 'product', 'vatRate' => 21],
		];

		$this->wireObjectService(
			store: $this->buildObjectServiceStub(
				vatGlAccounts: [],
				lines: $lines,
				overrides: []
			)
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->validate(invoiceId: 'inv-6', administrationId: 'adm-1'));

	}//end testMissingVatGlAccountsReturnsFalse()

	/**
	 * Exception in ObjectService causes fail-closed response (returns false, logs error).
	 *
	 * @return void
	 */
	public function testExceptionCausesFailClosed(): void {
		$this->wireObjectService(store: $this->buildFailingObjectServiceStub());

		$this->logger->expects($this->once())->method('error');

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->validate(invoiceId: 'inv-fail', administrationId: 'adm-1'));

	}//end testExceptionCausesFailClosed()

	/**
	 * Mixed invoice with valid lines (product@21%, service@9%, exempt@0%) returns true.
	 *
	 * @return void
	 */
	public function testMixedValidLinesAllReturnTrue(): void {
		$lines = [
			['invoiceId' => 'inv-7', 'lineSequence' => 1, 'serviceCategory' => 'product', 'vatRate' => 21],
			['invoiceId' => 'inv-7', 'lineSequence' => 2, 'serviceCategory' => 'service', 'vatRate' => 9],
			['invoiceId' => 'inv-7', 'lineSequence' => 3, 'serviceCategory' => 'exempt',  'vatRate' => 0],
		];

		$this->wireObjectService(
			store: $this->buildObjectServiceStub(
				vatGlAccounts: [$this->defaultGlAccounts()],
				lines: $lines,
				overrides: []
			)
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->validate(invoiceId: 'inv-7', administrationId: 'adm-1'));

	}//end testMixedValidLinesAllReturnTrue()

	/**
	 * Build an anonymous ObjectService stub returning given datasets per schema.
	 *
	 * @param array<mixed> $vatGlAccounts VATGLAccounts records.
	 * @param array<mixed> $lines InvoiceLine records.
	 * @param array<mixed> $overrides ServiceCategoryOverride records.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(
		array $vatGlAccounts,
		array $lines,
		array $overrides,
	): object {
		return new class($vatGlAccounts, $lines, $overrides) {
			/**
			 * Currently selected schema.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * VATGLAccounts records.
			 *
			 * @var array<mixed>
			 */
			private array $vatGlAccounts;

			/**
			 * InvoiceLine records.
			 *
			 * @var array<mixed>
			 */
			private array $lines;

			/**
			 * ServiceCategoryOverride records.
			 *
			 * @var array<mixed>
			 */
			private array $overrides;

			/**
			 * Constructor.
			 *
			 * @param array<mixed> $vatGlAccounts VAT GL account records.
			 * @param array<mixed> $lines InvoiceLine records.
			 * @param array<mixed> $overrides Override records.
			 */
			public function __construct(array $vatGlAccounts, array $lines, array $overrides) {
				$this->vatGlAccounts = $vatGlAccounts;
				$this->lines = $lines;
				$this->overrides = $overrides;
			}//end __construct()

			/**
			 * Fluent register setter (stub).
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter — remembers which schema was selected.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return records for the currently selected schema.
			 *
			 * @param array<string,mixed> $params Query params (unused in stub).
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return match ($this->currentSchema) {
					'VATGLAccounts' => $this->vatGlAccounts,
					'InvoiceLine' => $this->lines,
					'ServiceCategoryOverride' => $this->overrides,
					default => [],
				};
			}//end findAll()
		};
	}//end buildObjectServiceStub()

	/**
	 * Return a default VATGLAccounts record with all four accounts configured.
	 *
	 * @return array<string, string>
	 */
	private function defaultGlAccounts(): array {
		return [
			'administrationId' => 'adm-1',
			'vat21Account' => '2020',
			'vat9Account' => '2021',
			'vat6Account' => '2022',
			'vat0Account' => '2023',
		];
	}//end defaultGlAccounts()

	/**
	 * Build an ObjectService store that refuses every read.
	 *
	 * Since the store is injected rather than pulled from the container, an
	 * unavailable OpenRegister is modelled by a store that throws.
	 *
	 * @return object
	 */
	private function buildFailingObjectServiceStub(): object {
		return new class {

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug (unused).
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug (unused).
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Refuse the read, as an unavailable ObjectService would.
			 *
			 * @param array<string,mixed> $params Query parameters (unused in stub).
			 *
			 * @return array<mixed>
			 *
			 * @throws \RuntimeException Always.
			 */
			public function findAll(array $params = []): array {
				throw new \RuntimeException('ObjectService unavailable');
			}//end findAll()
		};
	}//end buildFailingObjectServiceStub()
}//end class
