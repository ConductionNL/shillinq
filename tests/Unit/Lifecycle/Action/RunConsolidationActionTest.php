<?php

/**
 * Unit tests for RunConsolidationAction.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-consolidation-commercial/spec.md#requirement-a-consolidation-run-sums-the-subsidiaries-and-applies-the-eliminations-req-cons-011
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\TrialBalanceServiceInterface;
use OCA\Shillinq\Lifecycle\Action\RunConsolidationAction;
use OCA\Shillinq\Service\ConsolidationMappingService;
use OCA\Shillinq\Service\ConsolidationRunService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The runConsolidation transition runs the consolidation and stamps the period.
 */
#[CoversClass(RunConsolidationAction::class)]
#[UsesClass(ConsolidationRunService::class)]
#[UsesClass(ConsolidationMappingService::class)]
#[UsesClass(ObjectIdentifier::class)]
final class RunConsolidationActionTest extends TestCase {

	/**
	 * The schemas saveObject was called with.
	 *
	 * @var string[]
	 */
	private array $saved = [];

	/**
	 * The action over a store holding one group whose parent has EUR 100 of assets.
	 *
	 * @return RunConsolidationAction
	 */
	private function action(): RunConsolidationAction {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn([]);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntityInterface {
				if ($id !== 'g-1') {
					return null;
				}

				$group = $this->createStub(ObjectEntityInterface::class);
				$group->method('getObject')->willReturn(['parentAdministrationId' => 'adm-p']);
				return $group;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null): ObjectEntityInterface {
				$this->saved[] = (string)$schema;
				return $this->createStub(ObjectEntityInterface::class);
			}
		);

		$trialBalance = $this->createMock(TrialBalanceServiceInterface::class);
		$trialBalance->method('compute')->willReturn(['data' => [['accountNumber' => '1000', 'accountType' => 'assets', 'closingBalance' => 100.0]]]);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$logger = $this->createStub(LoggerInterface::class);

		return new RunConsolidationAction(
			runs: new ConsolidationRunService(
				trialBalance: $trialBalance,
				mapping: new ConsolidationMappingService(container: $container, appConfig: $config, logger: $logger),
				objectService: $objectService,
				appConfig: $config,
				logger: $logger,
			)
		);
	}//end action()

	/**
	 * The period comes back unchanged but for the moment of the run, and both reports are written.
	 *
	 * @return void
	 */
	public function testTheRunIsStampedOnThePeriod(): void {
		$period = ['id' => 'cp-1', 'consolidationGroupId' => 'g-1', 'periodEnd' => '2026-10-31', 'status' => 'eliminationPhase', 'totalEliminationCount' => 4];

		$before = new DateTimeImmutable('-1 second');
		$result = $this->action()->execute(objectData: $period, previousData: $period, parameters: [], actionName: 'runConsolidation');

		self::assertSame(['ConsolidatedBalance', 'ConsolidatedIncomeStatement'], $this->saved);
		$stamp = new DateTimeImmutable((string)$result['executionTimestamp']);
		self::assertGreaterThanOrEqual($before->getTimestamp(), $stamp->getTimestamp());
		unset($result['executionTimestamp']);
		self::assertSame($period, $result);
	}//end testTheRunIsStampedOnThePeriod()

	/**
	 * A period that cannot be run refuses the transition.
	 *
	 * @return void
	 */
	public function testAPeriodThatCannotBeRunRefusesTheTransition(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The consolidation group of this period is unknown or names no parent administration.');

		$period = ['id' => 'cp-1', 'consolidationGroupId' => 'gone', 'periodEnd' => '2026-10-31'];
		$this->action()->execute(objectData: $period, previousData: $period, parameters: [], actionName: 'runConsolidation');
	}//end testAPeriodThatCannotBeRunRefusesTheTransition()
}//end class
