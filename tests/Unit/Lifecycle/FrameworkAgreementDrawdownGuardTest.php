<?php

/**
 * Unit tests for FrameworkAgreementDrawdownGuard.
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
 * @spec openspec/specs/procurement-governance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\FrameworkAgreementDrawdownGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Proves the fail-closed framework-agreement ceiling gate (REQ-PG-004).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class FrameworkAgreementDrawdownGuardTest extends TestCase {
	/**
	 * Build an in-memory ObjectService stub honouring equality filters.
	 *
	 * @param array<int,array<string,mixed>> $agreements FrameworkAgreement rows.
	 *
	 * @return object
	 */
	private function buildStub(array $agreements): object {
		return new class(['FrameworkAgreement' => $agreements]) {
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
			 * @param array<string,array<int,array<string,mixed>>> $data Rows.
			 */
			public function __construct(array $data) {
				$this->data = $data;
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
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->data[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
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
	}//end buildStub()

	/**
	 * Build the guard over an in-memory ObjectService stub.
	 *
	 * @param array<int,array<string,mixed>> $agreements FrameworkAgreement rows.
	 *
	 * @return FrameworkAgreementDrawdownGuard
	 */
	private function buildGuard(array $agreements): FrameworkAgreementDrawdownGuard {
		$stub      = $this->buildStub($agreements);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new FrameworkAgreementDrawdownGuard(
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($stub),
		);
	}//end buildGuard()

	/**
	 * A near-ceiling active agreement (5 000 000 ceiling, 4 800 000 drawn).
	 *
	 * @return array<string,mixed>
	 */
	private function nearCeilingAgreement(): array {
		return [
			'administrationId' => 'adm-1',
			'agreementNumber' => 'FA-1',
			'ceilingAmount' => 5000000,
			'drawnAmount' => 4800000,
			'statusCode' => 'active',
			'validFrom' => '2026-01-01',
			'validUntil' => '2028-12-31',
		];
	}//end nearCeilingAgreement()

	/**
	 * A call-off within the remaining ceiling returns the agreement.
	 *
	 * @return void
	 */
	public function testCallOffWithinCeilingPasses(): void {
		$guard = $this->buildGuard(agreements: [$this->nearCeilingAgreement()]);

		// Remaining room = 200 000 cents; a 150 000-cent call-off fits.
		$agreement = $guard->assertWithinCeiling(administrationId: 'adm-1', frameworkAgreementId: 'FA-1', addCents: 150000);
		self::assertSame(4800000, $agreement['drawnAmount']);
	}//end testCallOffWithinCeilingPasses()

	/**
	 * A call-off exceeding the remaining ceiling is blocked.
	 *
	 * @return void
	 */
	public function testCallOffExceedingCeilingIsBlocked(): void {
		$guard = $this->buildGuard(agreements: [$this->nearCeilingAgreement()]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('exceeds the framework agreement ceiling');
		// Remaining room = 200 000 cents; a 300 000-cent call-off blows it.
		$guard->assertWithinCeiling(administrationId: 'adm-1', frameworkAgreementId: 'FA-1', addCents: 300000);
	}//end testCallOffExceedingCeilingIsBlocked()

	/**
	 * A call-off against a non-active agreement is blocked.
	 *
	 * @return void
	 */
	public function testCallOffAgainstInactiveAgreementIsBlocked(): void {
		$agreement = $this->nearCeilingAgreement();
		$agreement['statusCode'] = 'closed';
		$guard = $this->buildGuard(agreements: [$agreement]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('not active');
		$guard->assertWithinCeiling(administrationId: 'adm-1', frameworkAgreementId: 'FA-1', addCents: 1000);
	}//end testCallOffAgainstInactiveAgreementIsBlocked()

	/**
	 * A missing agreement is blocked (fail-closed).
	 *
	 * @return void
	 */
	public function testMissingAgreementIsBlocked(): void {
		$guard = $this->buildGuard(agreements: []);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('not found');
		$guard->assertWithinCeiling(administrationId: 'adm-1', frameworkAgreementId: 'FA-1', addCents: 1000);
	}//end testMissingAgreementIsBlocked()

	/**
	 * A call-off by the agreement's own supplier gets the agreement; any other
	 * supplier, a closed or a missing agreement is refused (REQ-PG-005).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/purchasing-call-offs-and-reorder-proposals/specs/procurement-governance/spec.md (REQ-PG-005)
	 */
	public function testAgreementForCallOffBelongsToTheOrdersSupplier(): void {
		$agreement = (['supplierId' => 'sup-1'] + $this->nearCeilingAgreement());
		$closed    = (['agreementNumber' => 'FA-2', 'statusCode' => 'closed'] + $agreement);
		$guard     = $this->buildGuard(agreements: [$agreement, $closed]);

		self::assertSame($agreement, $guard->agreementForCallOff(administrationId: 'adm-1', frameworkAgreementId: 'FA-1', supplierId: 'sup-1'));

		$refusals = [];
		foreach ([['adm-1', 'FA-1', 'sup-2'], ['adm-1', 'FA-1', ''], ['adm-1', 'FA-2', 'sup-1'], ['adm-2', 'FA-1', 'sup-1']] as [$administrationId, $id, $supplierId]) {
			try {
				$guard->agreementForCallOff(administrationId: $administrationId, frameworkAgreementId: $id, supplierId: $supplierId);
				$refusals[] = 'accepted';
			} catch (\RuntimeException $e) {
				$refusals[] = $e->getMessage();
			}
		}

		self::assertSame(
			[
				'Framework agreement is with another supplier.',
				'Framework agreement is with another supplier.',
				'Framework agreement is not active.',
				'Framework agreement not found.',
			],
			$refusals
		);
	}//end testAgreementForCallOffBelongsToTheOrdersSupplier()

	/**
	 * A lookup that fails is a refusal, logged, never a pass (fail-closed).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/purchasing-call-offs-and-reorder-proposals/specs/procurement-governance/spec.md (REQ-PG-005)
	 */
	public function testAgreementForCallOffFailsClosed(): void {
		$failing = new class {
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
				return $this;
			}//end setSchema()

			/**
			 * The store is down.
			 *
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				throw new \LogicException('store down');
			}//end findAll()
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'FrameworkAgreementDrawdownGuard: agreement lookup failed — denying (fail-closed)',
			['frameworkAgreementId' => 'FA-1', 'exception' => 'store down']
		);
		$guard = new FrameworkAgreementDrawdownGuard(appConfig: $appConfig, logger: $logger, objectService: new DuckObjectServiceAdapter($failing));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Framework agreement drawdown check failed.');
		$guard->agreementForCallOff(administrationId: 'adm-1', frameworkAgreementId: 'FA-1', supplierId: 'sup-1');
	}//end testAgreementForCallOffFailsClosed()

	/**
	 * The agreed prices by product code, in cents; entries without a product
	 * code or a usable amount are left out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/purchasing-call-offs-and-reorder-proposals/specs/procurement-governance/spec.md (REQ-PG-005)
	 */
	public function testAgreedUnitAmountsByProductCode(): void {
		$guard = $this->buildGuard(agreements: []);

		self::assertSame(
			['PAPER' => 4200, 'TONER' => 0, 'PEN' => 150],
			$guard->agreedUnitAmounts(
				agreement: [
					'agreedPrices' => [
						['productCode' => ' PAPER ', 'unitAmount' => 4200],
						['productCode' => 'TONER', 'unitAmount' => 0],
						['productCode' => 'PEN', 'unitAmount' => '150'],
						['productCode' => '', 'unitAmount' => 10],
						['productCode' => 'NEG', 'unitAmount' => -1],
						['productCode' => 'TEXT', 'unitAmount' => 'ten'],
						['productCode' => 'NONE'],
						'loose',
					],
				]
			)
		);
		self::assertSame([], $guard->agreedUnitAmounts(agreement: []));
	}//end testAgreedUnitAmountsByProductCode()
}//end class
