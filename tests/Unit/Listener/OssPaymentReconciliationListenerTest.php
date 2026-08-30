<?php

/**
 * Unit tests for OssPaymentReconciliationListener.
 *
 * Correctness proof for tasks.md Task 18 (REQ-GLTAX-004 / REQ-OSS-008). On
 * the pre-change codebase `OssPaymentReconciliation::reconcileDistribution()`
 * had zero callers: a consolidated EU-VAT payment distributed differently
 * from what was declared was never detected, and every OssPayment stayed
 * `pending` forever. These tests drive the REAL `OssPaymentReconciliation`
 * kernel through the listener over an in-memory ObjectService.
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
 * @spec openspec/changes/revive-gl-tax-capabilities/specs/revive-gl-tax-capabilities/spec.md
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
use OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../Service/InMemoryObjectService.php';

/**
 * Tests the OssPayment-created → distribution-reconciliation wiring.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class OssPaymentReconciliationListenerTest extends TestCase {

	/**
	 * The in-memory ObjectService backing the chain.
	 *
	 * @var InMemoryObjectService
	 */
	private InMemoryObjectService $objects;

	/**
	 * The listener under test, wired to the REAL kernel.
	 *
	 * @var OssPaymentReconciliationListener
	 */
	private OssPaymentReconciliationListener $listener;

	/**
	 * Mock schema resolver — turns the entity's numeric schema id into a slug,
	 * exactly as it does in production.
	 *
	 * @var ListenerSchemaResolver&\PHPUnit\Framework\MockObject\MockObject
	 */
	private ListenerSchemaResolver $schemaResolver;

	/**
	 * Set up the real chain over an in-memory ObjectService.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objects = new InMemoryObjectService();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$resolver = new OssRecordResolver(
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($this->objects),
		);

		$this->schemaResolver = $this->createMock(ListenerSchemaResolver::class);

		$this->listener = new OssPaymentReconciliationListener(
			resolver: $resolver,
			reconciliation: new OssPaymentReconciliation(),
			schemaResolver: $this->schemaResolver,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * Stub the schema resolver so it resolves the entity's id to a slug.
	 *
	 * @param string $slug Slug the resolver reports.
	 *
	 * @return void
	 */
	private function resolvesTo(string $slug): void {
		$this->schemaResolver->method('schemaSlug')->willReturn($slug);

	}//end resolvesTo()

	/**
	 * Seed the declared OssReturn (DE 1802.00 + FR 1440.00).
	 *
	 * @return void
	 */
	private function seedReturn(): void {
		$this->objects->seed(
			'OssReturn',
			[
				[
					'id' => 'ossret-1',
					'administrationId' => 'adm-1',
					'totalVatAmount' => 3242.0,
					'lineItems' => [
						['countryCode' => 'DE', 'vatAmount' => 1802.0],
						['countryCode' => 'FR', 'vatAmount' => 1440.0],
					],
				],
			]
		);

	}//end seedReturn()

	/**
	 * Fire the OssPayment-created event.
	 *
	 * @param array<string,float> $confirmation The Belastingdienst per-country confirmation.
	 *
	 * @return void
	 */
	private function createPayment(array $confirmation): void {
		$payment = [
			'id' => 'osspay-1',
			'administrationId' => 'adm-1',
			'ossReturnId' => 'ossret-1',
			'paymentDate' => '2026-07-31',
			'bankTransactionId' => 'bank-tx-1',
			'amount' => 3242.0,
			'ibanFrom' => 'NL01BANK0123456789',
			'ibanTo' => 'NL86INGB0002445588',
			'perCountryDistribution' => $confirmation,
			'reconciliationStatus' => 'pending',
		];

		$this->objects->seed('OssPayment', [$payment]);

		$this->resolvesTo('OssPayment');

		// OpenRegister stamps the numeric schema **id** on the entity — the
		// slug only ever arrives via the resolver.
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getSchema')->willReturn('3311');
		$entity->method('getObject')->willReturn($payment);

		$event = $this->createConfiguredMock(ObjectCreatedEvent::class, ['getObject' => $entity]);

		$this->listener->handle($event);

	}//end createPayment()

	/**
	 * Read back the persisted payment.
	 *
	 * @return array<string,mixed>
	 */
	private function payment(): array {
		$rows = $this->objects->dump('OssPayment');
		return $rows[0];
	}//end payment()

	/**
	 * A confirmed distribution matching the declared return reconciles the
	 * payment (REQ-GLTAX-004).
	 *
	 * @return void
	 */
	public function testMatchingDistributionReconcilesThePayment(): void {
		$this->seedReturn();

		$this->createPayment(['DE' => 1802.0, 'FR' => 1440.0]);

		$payment = $this->payment();
		self::assertSame('reconciled', $payment['reconciliationStatus']);
		self::assertSame([], $payment['distributionDifferences'], 'A reconciled payment carries no differences.');

	}//end testMatchingDistributionReconcilesThePayment()

	/**
	 * A diverging distribution flags the payment and stores the per-country
	 * difference (REQ-GLTAX-004).
	 *
	 * @return void
	 */
	public function testDivergingDistributionFlagsADiscrepancy(): void {
		$this->seedReturn();

		$this->createPayment(['DE' => 1802.0, 'FR' => 1400.0]);

		$payment = $this->payment();
		self::assertSame('discrepancy', $payment['reconciliationStatus']);
		self::assertSame(
			['FR' => -40.0],
			$payment['distributionDifferences'],
			'The confirmed FR amount is 40.00 below the declared 1440.00.'
		);

	}//end testDivergingDistributionFlagsADiscrepancy()

	/**
	 * No confirmation yet: the payment stays pending, untouched (REQ-GLTAX-004).
	 *
	 * @return void
	 */
	public function testUnconfirmedPaymentStaysPending(): void {
		$this->seedReturn();

		$this->createPayment([]);

		self::assertSame('pending', $this->payment()['reconciliationStatus']);

	}//end testUnconfirmedPaymentStaysPending()

	/**
	 * An unresolvable OssReturn leaves the payment alone (REQ-GLTAX-004).
	 *
	 * @return void
	 */
	public function testUnresolvableReturnLeavesThePaymentPending(): void {
		$this->createPayment(['DE' => 1802.0]);

		self::assertSame('pending', $this->payment()['reconciliationStatus']);

	}//end testUnresolvableReturnLeavesThePaymentPending()

	/**
	 * An unrelated schema is ignored (REQ-GLTAX-004).
	 *
	 * @return void
	 */
	public function testOtherSchemaIsIgnored(): void {
		$this->resolvesTo('OssReturn');

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getSchema')->willReturn('3310');
		$entity->method('getObject')->willReturn(['id' => 'ossret-1']);

		$event = $this->createConfiguredMock(ObjectCreatedEvent::class, ['getObject' => $entity]);

		$this->listener->handle($event);

		self::assertSame([], $this->objects->dump('OssPayment'));

	}//end testOtherSchemaIsIgnored()
}//end class
