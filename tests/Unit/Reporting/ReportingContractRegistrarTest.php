<?php

/**
 * Unit tests for ReportingContractRegistrar.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude Structural contract wiring, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Kernel\Contracts\ReportGenerationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ReportRecipientPolicyInterface;
use OCA\Shillinq\Kernel\Contracts\ScheduledReportDeliveryInterface;
use OCA\Shillinq\Kernel\Contracts\ScheduledReportRunnerInterface;
use OCA\Shillinq\Kernel\Contracts\XafAuditfileGeneratorInterface;
use OCA\Shillinq\Reporting\Generator\XafAuditfileGenerator;
use OCA\Shillinq\Reporting\ReportGenerationService;
use OCA\Shillinq\Reporting\ReportingContractRegistrar;
use OCA\Shillinq\Reporting\Schedule\ReportRecipientPolicy;
use OCA\Shillinq\Reporting\Schedule\ScheduledReportDelivery;
use OCA\Shillinq\Reporting\Schedule\ScheduledReportRunner;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Every Reporting contract is aliased to its implementation, and to nothing else.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(ReportingContractRegistrar::class)]
final class ReportingContractRegistrarTest extends TestCase {

	/**
	 * The registrar aliases each contract to exactly its implementation and registers no services.
	 *
	 * @return void
	 */
	public function testEveryContractIsAliasedToItsImplementation(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->never())->method('registerService');
		$context->expects($this->exactly(5))->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);

		(new ReportingContractRegistrar())->register($context);

		self::assertSame(
			[
				ReportGenerationServiceInterface::class => ReportGenerationService::class,
				ReportRecipientPolicyInterface::class => ReportRecipientPolicy::class,
				ScheduledReportDeliveryInterface::class => ScheduledReportDelivery::class,
				ScheduledReportRunnerInterface::class => ScheduledReportRunner::class,
				XafAuditfileGeneratorInterface::class => XafAuditfileGenerator::class,
			],
			$aliases
		);

		foreach ($aliases as $contract => $implementation) {
			self::assertTrue(is_subclass_of($implementation, $contract), $implementation . ' implements ' . $contract);
		}
	}//end testEveryContractIsAliasedToItsImplementation()
}//end class
