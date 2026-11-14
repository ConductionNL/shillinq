<?php

/**
 * Flow tests for OssPaymentReconciliationListener: what is saved and logged per outcome.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Listener\OssPaymentReconciliationListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\OssPaymentReconciliation;
use OCA\Shillinq\Service\OssRecordResolver;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the saved record and the log lines of each reconciliation outcome (REQ-GLTAX-004).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class OssPaymentReconciliationFlowTest extends TestCase {

	/**
	 * The OSS record resolver.
	 *
	 * @var OssRecordResolver&MockObject
	 */
	private OssRecordResolver $resolver;

	/**
	 * The reconciliation kernel.
	 *
	 * @var OssPaymentReconciliation&MockObject
	 */
	private OssPaymentReconciliation $reconciliation;

	/**
	 * The schema resolver.
	 *
	 * @var ListenerSchemaResolver&MockObject
	 */
	private ListenerSchemaResolver $schemas;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->resolver = $this->createMock(OssRecordResolver::class);
		$this->reconciliation = $this->createMock(OssPaymentReconciliation::class);
		$this->schemas = $this->createMock(ListenerSchemaResolver::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Fire a created event for the payment under the given schema slug.
	 *
	 * @param string              $slug    The slug the schema resolver reports.
	 * @param array<string,mixed> $payment The payment record.
	 *
	 * @return void
	 */
	private function fire(string $slug, array $payment): void {
		$this->schemas->method('schemaSlug')->willReturn($slug);
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($payment);

		$listener = new OssPaymentReconciliationListener(
			resolver: $this->resolver,
			reconciliation: $this->reconciliation,
			schemaResolver: $this->schemas,
			logger: $this->logger,
		);
		$listener->handle($this->createConfiguredMock(ObjectCreatedEvent::class, ['getObject' => $entity]));

	}//end fire()

	/**
	 * A discrepancy is saved under the @self id without @self, and warned about.
	 *
	 * @return void
	 */
	public function testADiscrepancyIsSavedUnderTheSelfIdAndWarned(): void {
		$payment = ['@self' => ['id' => ' pay-9 '], 'ossReturnId' => 'ret-1', 'perCountryDistribution' => ['FR' => 1.0]];
		$this->resolver->method('findReturnForPayment')->with($payment)->willReturn(['id' => 'ret-1']);
		$this->reconciliation->expects(self::once())->method('reconcileDistribution')
			->with(['id' => 'ret-1'], ['FR' => 1.0])
			->willReturn(['status' => 'mismatch', 'differences' => ['FR' => -2.0]]);
		$this->resolver->expects(self::once())->method('savePayment')->with(
			[
				'ossReturnId' => 'ret-1',
				'perCountryDistribution' => ['FR' => 1.0],
				'id' => 'pay-9',
				'reconciliationStatus' => 'discrepancy',
				'distributionDifferences' => ['FR' => -2.0],
			]
		);
		$this->logger->expects(self::once())->method('warning')->with(
			'OssPaymentReconciliationListener: confirmed OSS distribution diverges from the declared return',
			['ossReturnId' => 'ret-1', 'differences' => ['FR' => -2.0]]
		);

		$this->fire(' OSS-Payment ', $payment);

	}//end testADiscrepancyIsSavedUnderTheSelfIdAndWarned()

	/**
	 * A reconciled result is saved without a warning; missing differences save as [].
	 *
	 * @return void
	 */
	public function testAReconciledResultIsSavedQuietly(): void {
		$payment = ['id' => 'pay-1', 'perCountryDistribution' => ['DE' => 1.0]];
		$this->resolver->method('findReturnForPayment')->willReturn(['id' => 'ret-1']);
		$this->reconciliation->method('reconcileDistribution')->willReturn(['status' => 'reconciled']);
		$this->resolver->expects(self::once())->method('savePayment')->with(
			['id' => 'pay-1', 'perCountryDistribution' => ['DE' => 1.0], 'reconciliationStatus' => 'reconciled', 'distributionDifferences' => []]
		);
		$this->logger->expects(self::never())->method('warning');

		$this->fire('osspayment', $payment);

	}//end testAReconciledResultIsSavedQuietly()

	/**
	 * A result without a status counts as a discrepancy; a payment without any id saves id ''.
	 *
	 * @return void
	 */
	public function testAMissingStatusIsADiscrepancy(): void {
		$payment = ['@self' => 'not-an-array', 'perCountryDistribution' => ['DE' => 1.0]];
		$this->resolver->method('findReturnForPayment')->willReturn(['id' => 'ret-1']);
		$this->reconciliation->method('reconcileDistribution')->willReturn([]);
		$this->resolver->expects(self::once())->method('savePayment')->with(
			['perCountryDistribution' => ['DE' => 1.0], 'id' => '', 'reconciliationStatus' => 'discrepancy', 'distributionDifferences' => []]
		);
		$this->logger->expects(self::once())->method('warning')->with(
			'OssPaymentReconciliationListener: confirmed OSS distribution diverges from the declared return',
			['ossReturnId' => null, 'differences' => []]
		);

		$this->fire('osspayment', $payment);

	}//end testAMissingStatusIsADiscrepancy()

	/**
	 * An unresolvable return is warned about and nothing is reconciled.
	 *
	 * @return void
	 */
	public function testAnUnresolvableReturnIsWarned(): void {
		$this->resolver->method('findReturnForPayment')->willReturn(null);
		$this->reconciliation->expects(self::never())->method('reconcileDistribution');
		$this->logger->expects(self::once())->method('warning')->with(
			'OssPaymentReconciliationListener: OssPayment has no resolvable OssReturn; reconciliation skipped',
			['ossReturnId' => 'ret-7']
		);

		$this->fire('osspayment', ['ossReturnId' => 'ret-7', 'perCountryDistribution' => ['DE' => 1.0]]);

	}//end testAnUnresolvableReturnIsWarned()

	/**
	 * A non-array or empty distribution is not reconciled, after the return lookup.
	 *
	 * @return void
	 */
	public function testANonArrayDistributionIsNotReconciled(): void {
		$this->resolver->expects(self::exactly(2))->method('findReturnForPayment')->willReturn(['id' => 'ret-1']);
		$this->reconciliation->expects(self::never())->method('reconcileDistribution');
		$this->resolver->expects(self::never())->method('savePayment');

		$this->fire('osspayment', ['perCountryDistribution' => 'pending']);
		$this->fire('osspayment', []);

	}//end testANonArrayDistributionIsNotReconciled()

	/**
	 * Other schemas and other events touch nothing.
	 *
	 * @return void
	 */
	public function testOtherSchemasAndEventsAreIgnored(): void {
		$this->resolver->expects(self::never())->method('findReturnForPayment');

		$this->fire('ossreturn', ['perCountryDistribution' => ['DE' => 1.0]]);
		(new OssPaymentReconciliationListener(
			resolver: $this->resolver,
			reconciliation: $this->reconciliation,
			schemaResolver: $this->schemas,
			logger: $this->logger,
		))->handle(new Event());

	}//end testOtherSchemasAndEventsAreIgnored()

	/**
	 * A failure anywhere is logged as an error and swallowed.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndSwallowed(): void {
		$this->resolver->method('findReturnForPayment')->willThrowException(new RuntimeException('register down'));
		$this->logger->expects(self::once())->method('error')->with(
			'OssPaymentReconciliationListener: failed to reconcile the OSS payment',
			['exception' => 'register down']
		);

		$this->fire('osspayment', ['perCountryDistribution' => ['DE' => 1.0]]);

	}//end testAFailureIsLoggedAndSwallowed()
}//end class
