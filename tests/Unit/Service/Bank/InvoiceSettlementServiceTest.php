<?php

/**
 * A confirmed match settles its invoices, and the listener wires the confirm.
 *
 * The transitions run through an engine that obeys the effective register's
 * declared lifecycles, and the listener gets the real ObjectTransitionedEvent
 * with the schema as OpenRegister stamps it: a numeric id.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Bank
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Bank;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Listener\ReconciliationMatchSettlementListener;
use OCA\Shillinq\Service\Bank\InvoiceSettlementService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\ListenerSlugContract;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * REQ-BMM-003.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InvoiceSettlementServiceTest extends TestCase {
	/**
	 * The shared store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The engine.
	 *
	 * @var LifecycleFaithfulTransitionEngine
	 */
	private LifecycleFaithfulTransitionEngine $engine;

	/**
	 * Seed two sales invoices and two AP transactions.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryObjectServiceStub(
			[
				'ARInvoice' => [
					['id' => 'ar-issued', 'invoiceNumber' => 'VF-2026-0901', 'lifecycleState' => 'issued', 'grossAmount' => 1500.0],
					['id' => 'ar-overdue', 'invoiceNumber' => 'VF-2026-0850', 'lifecycleState' => 'overdue', 'grossAmount' => 605.0],
					['id' => 'ar-paid', 'invoiceNumber' => 'VF-2026-0800', 'lifecycleState' => 'paid', 'grossAmount' => 100.0],
				],
				'APTransaction' => [
					['id' => 'ap-issued', 'invoiceNumber' => 'DV-7781', 'state' => 'issued', 'totalAmount' => 2420.0],
					['id' => 'ap-draft', 'invoiceNumber' => 'DV-7782', 'state' => 'draft', 'totalAmount' => 50.0],
				],
			]
		);
		$this->engine = new LifecycleFaithfulTransitionEngine(store: $this->store, schemas: ['ARInvoice', 'APTransaction']);

	}//end setUp()

	/**
	 * The service under test, with the engine behind the runner.
	 *
	 * @return InvoiceSettlementService
	 */
	private function service(): InvoiceSettlementService {
		$engine = $this->engine;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => $id === ObjectTransitionRunner::ENGINE_CLASS);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $engine);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new InvoiceSettlementService(
			objectService: $this->store,
			transitions: new ObjectTransitionRunner(container: $container),
			settings: $settings,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end service()

	/**
	 * State of one stored invoice.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 * @param string $field  The state field.
	 *
	 * @return string
	 */
	private function stateOf(string $schema, string $id, string $field): string {
		return (string)$this->store->find($id, schema: $schema)->getObject()[$field];

	}//end stateOf()

	/**
	 * An issued sales invoice becomes paid through mark-paid.
	 *
	 * @return void
	 */
	public function testIssuedSalesInvoiceIsMarkedPaid(): void {
		$outcomes = $this->service()->settle(['targetType' => 'ar-invoice', 'targetRefs' => ['ar-issued'], 'isPartial' => false]);

		self::assertSame(InvoiceSettlementService::SETTLED, $outcomes['ar-issued']['outcome']);
		self::assertSame('mark-paid', $outcomes['ar-issued']['transition']);
		self::assertSame('paid', $this->stateOf('ARInvoice', 'ar-issued', 'lifecycleState'));

	}//end testIssuedSalesInvoiceIsMarkedPaid()

	/**
	 * An overdue sales invoice becomes paid through pay-overdue (scenario VF-2026-0850).
	 *
	 * @return void
	 */
	public function testOverdueSalesInvoiceIsPaid(): void {
		$outcomes = $this->service()->settle(['matchType' => 'ar-invoice', 'matchedObjectId' => 'ar-overdue']);

		self::assertSame('pay-overdue', $outcomes['ar-overdue']['transition']);
		self::assertSame('paid', $this->stateOf('ARInvoice', 'ar-overdue', 'lifecycleState'));

	}//end testOverdueSalesInvoiceIsPaid()

	/**
	 * A full AP match runs matchFull; a partial one runs matchPartial.
	 *
	 * @return void
	 */
	public function testApTransactionFullAndPartial(): void {
		$this->service()->settle(['targetType' => 'ap-invoice', 'targetRefs' => ['ap-issued'], 'isPartial' => true]);
		self::assertSame('partially-paid', $this->stateOf('APTransaction', 'ap-issued', 'state'));

		$this->service()->settle(['targetType' => 'ap-invoice', 'targetRefs' => ['ap-issued'], 'isPartial' => false]);
		self::assertSame('paid', $this->stateOf('APTransaction', 'ap-issued', 'state'));
		self::assertSame(['matchPartial', 'matchFull'], array_column($this->engine->ran, 'action'));

	}//end testApTransactionFullAndPartial()

	/**
	 * A partial match leaves a sales invoice issued: ARInvoice has no partially paid state.
	 *
	 * @return void
	 */
	public function testPartialMatchLeavesSalesInvoiceIssued(): void {
		$outcomes = $this->service()->settle(['targetType' => 'ar-invoice', 'targetRefs' => ['ar-issued'], 'isPartial' => true]);

		self::assertSame(InvoiceSettlementService::SKIPPED, $outcomes['ar-issued']['outcome']);
		self::assertSame('issued', $this->stateOf('ARInvoice', 'ar-issued', 'lifecycleState'));
		self::assertSame([], $this->engine->ran);

	}//end testPartialMatchLeavesSalesInvoiceIssued()

	/**
	 * A paid invoice and a draft one are left unchanged; nothing runs.
	 *
	 * @return void
	 */
	public function testNonPayableInvoicesAreLeftAlone(): void {
		$ar = $this->service()->settle(['targetType' => 'ar-invoice', 'targetRefs' => ['ar-paid']]);
		$ap = $this->service()->settle(['targetType' => 'ap-invoice', 'targetRefs' => ['ap-draft']]);

		self::assertSame(InvoiceSettlementService::SKIPPED, $ar['ar-paid']['outcome']);
		self::assertSame(InvoiceSettlementService::SKIPPED, $ap['ap-draft']['outcome']);
		self::assertSame([], $this->engine->ran);

	}//end testNonPayableInvoicesAreLeftAlone()

	/**
	 * A ledger match settles nothing.
	 *
	 * @return void
	 */
	public function testLedgerMatchSettlesNothing(): void {
		self::assertSame([], $this->service()->settle(['targetType' => 'gl-transaction', 'targetRefs' => ['gl-1']]));

	}//end testLedgerMatchSettlesNothing()

	/**
	 * Every transition name the service can pick is declared on the effective schema.
	 *
	 * @return void
	 */
	public function testEveryPickedTransitionIsDeclared(): void {
		$cases = [
			['ARInvoice', 'issued', false],
			['ARInvoice', 'overdue', false],
			['APTransaction', 'issued', false],
			['APTransaction', 'overdue', false],
			['APTransaction', 'partially-paid', false],
			['APTransaction', 'issued', true],
			['APTransaction', 'overdue', true],
		];
		foreach ($cases as [$schema, $state, $partial]) {
			$transition = InvoiceSettlementService::transitionFor(schema: $schema, state: $state, partial: $partial);
			$declared = \OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema::schema(slug: $schema)['x-openregister-lifecycle']['transitions'][$transition] ?? null;
			self::assertNotNull($declared, $schema . '.' . $transition . ' is declared');
			self::assertContains($state, (array)$declared['from']);
		}

	}//end testEveryPickedTransitionIsDeclared()

	/**
	 * The listener settles on the real event, schema stamped as an id; twice is once.
	 *
	 * @return void
	 */
	public function testListenerSettlesOnConfirmOnce(): void {
		$listener = new ReconciliationMatchSettlementListener(
			settlement: $this->service(),
			schemas: $this->resolver(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$entity = new ObjectEntity();
		$entity->setObject(['targetType' => 'ar-invoice', 'targetRefs' => ['ar-overdue'], 'isPartial' => false]);
		$entity->setSchema('812');
		$entity->setRegister('7');

		$event = new ObjectTransitionedEvent($entity, 'confirm', 'pending', 'confirmed', 'admin', '7', '812');
		$listener->handle($event);
		$listener->handle($event);

		self::assertSame('paid', $this->stateOf('ARInvoice', 'ar-overdue', 'lifecycleState'));
		self::assertCount(1, $this->engine->ran);

	}//end testListenerSettlesOnConfirmOnce()

	/**
	 * A reject transition, or another schema's confirm, settles nothing.
	 *
	 * @return void
	 */
	public function testListenerIgnoresOtherTransitionsAndSchemas(): void {
		$listener = new ReconciliationMatchSettlementListener(
			settlement: $this->service(),
			schemas: $this->resolver(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$match = new ObjectEntity();
		$match->setObject(['targetType' => 'ar-invoice', 'targetRefs' => ['ar-issued']]);
		$match->setSchema('812');
		$match->setRegister('7');
		$listener->handle(new ObjectTransitionedEvent($match, 'reject', 'pending', 'rejected', 'admin', '7', '812'));

		$other = new ObjectEntity();
		$other->setObject(['targetType' => 'ar-invoice', 'targetRefs' => ['ar-issued']]);
		$other->setSchema('900');
		$other->setRegister('7');
		$listener->handle(new ObjectTransitionedEvent($other, 'confirm', 'pending', 'confirmed', 'admin', '7', '900'));

		self::assertSame('issued', $this->stateOf('ARInvoice', 'ar-issued', 'lifecycleState'));

	}//end testListenerIgnoresOtherTransitionsAndSchemas()

	/**
	 * A resolver that maps schema id 812 to ReconciliationMatch and 900 to another schema.
	 *
	 * @return ListenerSchemaResolver
	 */
	private function resolver(): ListenerSchemaResolver {
		$mapper = new class {
			/**
			 * Find by id.
			 *
			 * @param mixed $id The id.
			 *
			 * @return object
			 */
			public function find(mixed $id, mixed ...$rest): object {
				$slug = ['812' => 'ReconciliationMatch', '900' => 'SupplierInvoice', '7' => 'shillinq'][(string)$id] ?? '';
				return new class($slug) {
					/**
					 * Constructor.
					 *
					 * @param string $slug The slug.
					 */
					public function __construct(private string $slug) {
					}

					/**
					 * The slug.
					 *
					 * @return string
					 */
					public function getSlug(): string {
						return $this->slug;
					}
				};
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($mapper);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$contract = $this->createMock(ListenerSlugContract::class);
		$contract->method('isEnabled')->willReturn(false);

		return new ListenerSchemaResolver(
			container: $container,
			settingsService: $settings,
			contract: $contract,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end resolver()
}//end class
