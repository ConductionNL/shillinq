<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\AuditExportService;
use OCA\Shillinq\Service\PurchaseOrderApprovalTaskEvents;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * The purchase order audit export names who decided, on whose behalf and under which mandate.
 *
 * @covers \OCA\Shillinq\Service\PurchaseOrderApprovalTaskEvents
 * @covers \OCA\Shillinq\Service\AuditExportService
 *
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-3.1
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PurchaseOrderApprovalTaskEventsTest extends TestCase {

	/**
	 * The order ids the reader was asked about.
	 *
	 * @var list<string>
	 */
	public array $asked = [];

	/**
	 * A task as OpenRegister's `ObjectTaskRecord` carries it.
	 *
	 * @param string               $uuid     The task.
	 * @param array<string, mixed> $decision Outcome, decidedBy, onBehalfOf, mandate and decidedAt; empty while open.
	 *
	 * @return object The record.
	 */
	private static function task(string $uuid, array $decision = []): object {
		$decidedAt = null;
		if (isset($decision['decidedAt']) === true) {
			$decidedAt = new DateTimeImmutable($decision['decidedAt']);
		}

		return (object)[
			'uuid' => $uuid,
			'title' => 'Facility manager',
			'workflowStepId' => 'step-' . $uuid,
			'isTerminal' => isset($decision['outcome']),
			'outcome' => $decision['outcome'] ?? null,
			'assignee' => 'pieter',
			'decidedBy' => $decision['decidedBy'] ?? null,
			'onBehalfOf' => $decision['onBehalfOf'] ?? null,
			'mandate' => $decision['mandate'] ?? null,
			'decidedAt' => $decidedAt,
		];
	}//end task()

	/**
	 * A container whose task reader answers with the given tasks.
	 *
	 * @param list<object> $tasks The tasks on the order.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $tasks): ContainerInterface {
		$test = $this;
		$reader = new class($tasks, $test) {
			/**
			 * @param list<object>                       $tasks The tasks.
			 * @param PurchaseOrderApprovalTaskEventsTest $test  The test, to record the ask.
			 */
			public function __construct(private array $tasks, private PurchaseOrderApprovalTaskEventsTest $test) {
			}

			/**
			 * @param string $objectUuid The object.
			 *
			 * @return list<object>
			 */
			public function tasksOnObject(string $objectUuid): array {
				$this->test->asked[] = $objectUuid;
				return $this->tasks;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($reader): object {
				if ($id !== PurchaseOrderApprovalTaskEvents::TASK_READER) {
					throw new RuntimeException('unexpected lookup ' . $id);
				}

				return $reader;
			}
		);

		return $container;
	}//end container()

	/**
	 * The tasks of PO-2026-040: a delegated decision, a direct one and an open step.
	 *
	 * @return list<object>
	 */
	private static function orderTasks(): array {
		return [
			self::task(
				't-1',
				[
					'outcome' => 'approved',
					'decidedBy' => 'karin',
					'onBehalfOf' => 'pieter',
					'mandate' => 'Holiday cover 1-14 Sept, orders up to EUR 25.000',
					'decidedAt' => '2026-09-03T10:15:00+00:00',
				]
			),
			self::task('t-2', ['outcome' => 'approved', 'decidedBy' => 'eva', 'decidedAt' => '2026-09-04T09:00:00+00:00']),
			self::task('t-3'),
		];
	}//end orderTasks()

	/**
	 * A delegated and a direct decision each become an event; the open step does not.
	 *
	 * @return void
	 */
	public function testDelegatedAndDirectDecisionsBecomeEvents(): void {
		$events = (new PurchaseOrderApprovalTaskEvents($this->container(self::orderTasks()), new NullLogger()))->events(' po-40 ');

		$this->assertSame(['po-40'], $this->asked);
		$this->assertCount(2, $events);
		$this->assertSame(
			[
				'event' => 'po_approval_decision',
				'timestamp' => '2026-09-03T10:15:00+00:00',
				'actor' => 'karin',
				'objectType' => 'PurchaseOrder',
				'objectId' => 'po-40',
				'details' => [
					'decision' => 'approved',
					'step' => 'Facility manager',
					'decidedBy' => 'karin',
					'onBehalfOf' => 'pieter',
					'mandate' => 'Holiday cover 1-14 Sept, orders up to EUR 25.000',
					'taskId' => 't-1',
				],
			],
			$events[0]
		);
		$this->assertSame(['eva', '', ''], [$events[1]['details']['decidedBy'], $events[1]['details']['onBehalfOf'], $events[1]['details']['mandate']]);
		$this->assertSame('2026-09-04T09:00:00+00:00', $events[1]['timestamp']);
	}//end testDelegatedAndDirectDecisionsBecomeEvents()

	/**
	 * Without OpenRegister's reader, or when it fails, the export goes on without task events.
	 *
	 * @return void
	 */
	public function testNoReaderOrAFailingReaderGivesNoEvents(): void {
		$missing = $this->createMock(ContainerInterface::class);
		$missing->method('get')->willThrowException(new RuntimeException('not registered'));
		$this->assertSame([], (new PurchaseOrderApprovalTaskEvents($missing, new NullLogger()))->events('po-40'));

		$other = $this->createMock(ContainerInterface::class);
		$other->method('get')->willReturn(new \stdClass());
		$this->assertSame([], (new PurchaseOrderApprovalTaskEvents($other, new NullLogger()))->events('po-40'));

		$this->assertSame([], (new PurchaseOrderApprovalTaskEvents($this->container(self::orderTasks()), new NullLogger()))->events(' '));
		$this->assertSame([], $this->asked);
	}//end testNoReaderOrAFailingReaderGivesNoEvents()

	/**
	 * The exporter's ledger and summary carry the decider, on behalf of and mandate columns.
	 *
	 * @return void
	 */
	public function testTheAuditExportShowsDeciderOnBehalfOfAndMandate(): void {
		$service = new AuditExportService(
			$this->container(self::orderTasks()),
			$this->createMock(IAppConfig::class),
			$this->createMock(AdministrationContextService::class),
			$this->createMock(IUserSession::class),
			new NullLogger()
		);

		$ledger = $service->buildLedger(
			[
				'invoice' => ['id' => 'inv-1', 'invoiceNumber' => 'INV-1', 'supplierId' => 'sup-1', 'totalInclVat' => 0, 'currency' => 'EUR'],
				'purchaseOrders' => [['id' => 'po-40', 'poNumber' => 'PO-2026-040', 'createdAt' => '2026-09-01T08:00:00+00:00', 'requesterId' => 'jan']],
				'goodsReceiptNotes' => [],
				'threeWayMatches' => [],
			]
		);

		$decisions = array_values(array_filter($ledger['events'], static fn (array $e): bool => $e['event'] === 'po_approval_decision'));
		$this->assertSame(
			[['karin', 'pieter', 'Holiday cover 1-14 Sept, orders up to EUR 25.000'], ['eva', '', '']],
			array_map(static fn (array $e): array => [$e['actor'], $e['details']['onBehalfOf'], $e['details']['mandate']], $decisions)
		);

		$rows = (new ReflectionMethod(AuditExportService::class, 'eventRows'))->invoke($service, $decisions);
		$this->assertStringContainsString(
			'<td>po_approval_decision</td><td>karin</td><td>pieter</td><td>Holiday cover 1-14 Sept, orders up to EUR 25.000</td><td>po-40</td>',
			$rows
		);
		$this->assertStringContainsString('<td>po_approval_decision</td><td>eva</td><td></td><td></td><td>po-40</td>', $rows);
	}//end testTheAuditExportShowsDeciderOnBehalfOfAndMandate()
}//end class
