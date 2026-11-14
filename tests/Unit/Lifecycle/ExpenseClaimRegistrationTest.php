<?php

/**
 * Unit tests for ExpenseClaimRegistration.
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
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\ExpenseClaimGuard;
use OCA\Shillinq\Lifecycle\ExpenseClaimRegistration;
use OCA\Shillinq\Lifecycle\ExpenseReimbursementGuard;
use OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every `requires` tag the expense claim transitions declare is registered
 * and reaches the guard method it names.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(ExpenseClaimRegistration::class)]
#[UsesClass(RegisterRequiresGuardAdapter::class)]
final class ExpenseClaimRegistrationTest extends TestCase {
	/**
	 * Every tag declared in the register, and only those, is registered.
	 *
	 * @return void
	 */
	public function testEveryDeclaredTagIsRegistered(): void {
		$this->assertSame(
			[
				'OCA\Shillinq\Lifecycle\ExpenseReimbursementGuard::requireSettlementModeConsistency',
				'OCA\Shillinq\Lifecycle\ExpenseReimbursementGuard::requireMarkupApprovalIfThreshold',
				'OCA\Shillinq\Lifecycle\ExpenseReimbursementGuard::requirePassThroughMode',
				'OCA\Shillinq\Lifecycle\ExpenseReimbursementGuard::requireGlReversalForModeChange',
				'OCA\Shillinq\Lifecycle\ExpenseClaimGuard::requireCostCentresAndItems',
				'OCA\Shillinq\Lifecycle\ExpenseClaimGuard::requireOpenPeriodAndCostCentres',
			],
			array_keys($this->factories())
		);

		$declared = file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/expense-reimbursement-or-passthrough.json')
			. file_get_contents(__DIR__ . '/../../../lib/Settings/shillinq_register.json');
		foreach (array_keys(ExpenseClaimRegistration::GUARDS) as $tag) {
			$this->assertStringContainsString(json_encode($tag), $declared, "{$tag} is declared as a requires tag");
		}
	}//end testEveryDeclaredTagIsRegistered()

	/**
	 * The markup-threshold tag denies through the guard with its message, and
	 * allows when the guard allows.
	 *
	 * @return void
	 */
	public function testTheMarkupThresholdTagReachesItsGuard(): void {
		$guard = $this->createMock(ExpenseReimbursementGuard::class);
		$guard->expects($this->exactly(2))->method('requireMarkupApprovalIfThreshold')
			->with(['id' => 'claim-1'])
			->willReturnOnConsecutiveCalls(false, true);

		$adapter = $this->factories()['OCA\Shillinq\Lifecycle\ExpenseReimbursementGuard::requireMarkupApprovalIfThreshold'](
			$this->container(guardClass: ExpenseReimbursementGuard::class, guard: $guard)
		);

		$denied = $adapter->check(['id' => 'claim-1'], 'approve', 'alice');
		$this->assertFalse($denied->isAllowed());
		$this->assertSame(
			'The claim cannot be approved here: its pass-through markup reaches the policy threshold and needs the extra approver.',
			$denied->getMessage()
		);
		$this->assertTrue($adapter->check(['id' => 'claim-1'], 'approve', 'alice')->isAllowed());
	}//end testTheMarkupThresholdTagReachesItsGuard()

	/**
	 * The post tag reaches ExpenseClaimGuard.
	 *
	 * @return void
	 */
	public function testThePostTagReachesTheClaimGuard(): void {
		$guard = $this->createMock(ExpenseClaimGuard::class);
		$guard->expects($this->once())->method('requireOpenPeriodAndCostCentres')->with(['id' => 'claim-1'])->willReturn(true);

		$adapter = $this->factories()['OCA\Shillinq\Lifecycle\ExpenseClaimGuard::requireOpenPeriodAndCostCentres'](
			$this->container(guardClass: ExpenseClaimGuard::class, guard: $guard)
		);

		$this->assertTrue($adapter->check(['id' => 'claim-1'], 'post', 'alice')->isAllowed());
	}//end testThePostTagReachesTheClaimGuard()

	/**
	 * The factories registered, by tag.
	 *
	 * @return array<string, callable>
	 */
	private function factories(): array {
		$factories = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		(new ExpenseClaimRegistration())->register(context: $context);

		return $factories;
	}//end factories()

	/**
	 * A container answering the guard and a logger.
	 *
	 * @param string $guardClass The guard class.
	 * @param object $guard      The guard.
	 *
	 * @return ContainerInterface
	 */
	private function container(string $guardClass, object $guard): ContainerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				$guardClass => $guard,
				LoggerInterface::class => $logger,
			}
		);

		return $container;
	}//end container()
}//end class
