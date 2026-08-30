<?php

/**
 * Unit tests for APGuard.
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
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\APGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class APGuardTest extends TestCase {
	/**
	 * @param array<int,array<string,mixed>> $transactions APTransaction rows.
	 *
	 * @return APGuard
	 */
	private function buildGuard(array $transactions): APGuard {
		$stub = new class($transactions) {
			/**
			 * @var array<int,array<string,mixed>>
			 */
			private array $transactions;

			/**
			 * @param array<int,array<string,mixed>> $transactions APTransaction rows.
			 */
			public function __construct(array $transactions) {
				$this->transactions = $transactions;
			}//end __construct()

			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$filters = ($params['filters'] ?? []);
				return array_values(
					array_filter(
						$this->transactions,
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

		return new APGuard(
			container: $container,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end buildGuard()

	/**
	 * Good path: no other transaction shares this invoice number.
	 *
	 * @return void
	 */
	public function testReceiveAllowedWhenInvoiceNumberUnique(): void {
		$guard = $this->buildGuard([]);

		$allowed = $guard->isInvoiceNumberUnique(
			['invoiceNumber' => 'INV-001', 'vendorId' => 'v-1', 'administrationId' => 'adm-1']
		);
		self::assertTrue($allowed);

	}//end testReceiveAllowedWhenInvoiceNumberUnique()

	/**
	 * Bad path: another APTransaction for the same vendor+administration
	 * already carries this invoice number.
	 *
	 * @return void
	 */
	public function testReceiveDeniedOnDuplicateInvoiceNumber(): void {
		$guard = $this->buildGuard(
			[
				['id' => 'ap-1', 'invoiceNumber' => 'INV-001', 'vendorId' => 'v-1', 'administrationId' => 'adm-1'],
			]
		);

		$allowed = $guard->isInvoiceNumberUnique(
			['id' => 'ap-2', 'invoiceNumber' => 'INV-001', 'vendorId' => 'v-1', 'administrationId' => 'adm-1']
		);
		self::assertFalse($allowed);

	}//end testReceiveDeniedOnDuplicateInvoiceNumber()

	/**
	 * Re-saving the SAME record (same id) does not self-collide.
	 *
	 * @return void
	 */
	public function testReceiveAllowedWhenOnlyMatchIsSelf(): void {
		$guard = $this->buildGuard(
			[
				['id' => 'ap-1', 'invoiceNumber' => 'INV-001', 'vendorId' => 'v-1', 'administrationId' => 'adm-1'],
			]
		);

		$allowed = $guard->isInvoiceNumberUnique(
			['id' => 'ap-1', 'invoiceNumber' => 'INV-001', 'vendorId' => 'v-1', 'administrationId' => 'adm-1']
		);
		self::assertTrue($allowed);

	}//end testReceiveAllowedWhenOnlyMatchIsSelf()

	/**
	 * writeOff good path: reason present.
	 *
	 * @return void
	 */
	public function testWriteOffAllowedWithReason(): void {
		$guard = $this->buildGuard([]);
		self::assertTrue($guard->requireWriteOffReason(['writeOffReason' => 'Vendor liquidated']));

	}//end testWriteOffAllowedWithReason()

	/**
	 * writeOff bad path: reason missing.
	 *
	 * @return void
	 */
	public function testWriteOffDeniedWithoutReason(): void {
		$guard = $this->buildGuard([]);
		self::assertFalse($guard->requireWriteOffReason(['writeOffReason' => '']));

	}//end testWriteOffDeniedWithoutReason()
}//end class
