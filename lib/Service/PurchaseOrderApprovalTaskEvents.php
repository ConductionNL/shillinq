<?php

/**
 * Purchase order approval task events
 *
 * The approval decisions on a purchase order as audit ledger events, read
 * from OpenRegister's task store through its published
 * `ObjectTaskReaderInterface` (purchasing-approval-delegation task 3.1,
 * REQ-PAD-003): who decided each step, on whose behalf when the step was
 * handed to a colleague, under which mandate and when.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the decided approval steps of one purchase order.
 *
 * Since the approval runs on OpenRegister (task 1.3) the order's own
 * `approvalChain` history is no longer written; the decisions live on the
 * order's tasks. The caller has checked access to the order before it asks:
 * the reader applies no task rights.
 *
 * @spec openspec/changes/purchasing-approval-delegation/specs/bookkeeping-purchase-order-3way/spec.md#requirement-the-approval-audit-export-names-who-acted-on-whose-behalf-req-pad-003
 */
class PurchaseOrderApprovalTaskEvents {

	/**
	 * OpenRegister's published read of the tasks on an object.
	 */
	public const TASK_READER = 'OCA\\OpenRegister\\Contract\\ObjectTaskReaderInterface';

	/**
	 * Wire collaborators.
	 *
	 * @param ContainerInterface $container Container, for the optional OpenRegister reader.
	 * @param LoggerInterface    $logger    PSR logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-3.1
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * One `po_approval_decision` event per decided approval step of the order.
	 *
	 * The actor is the decider; the details carry the decision, the step, the
	 * decider again, the approver on whose behalf it was decided (empty for a
	 * direct decision) and the mandate. Open steps are left out.
	 *
	 * @param string $orderId The purchase order's uuid.
	 *
	 * @return list<array<string, mixed>> The events, unsorted.
	 *
	 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-3.1
	 */
	public function events(string $orderId): array {
		$orderId = trim($orderId);
		$tasks = $this->tasks(orderId: $orderId);

		$events = [];
		foreach ($tasks as $task) {
			$event = $this->decisionEvent(orderId: $orderId, task: $task);
			if ($event !== null) {
				$events[] = $event;
			}
		}

		return $events;
	}//end events()

	/**
	 * The order's tasks, or none when OpenRegister's reader is not there.
	 *
	 * @param string $orderId The purchase order's uuid.
	 *
	 * @return array<int, mixed> The task records.
	 *
	 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-3.1
	 */
	private function tasks(string $orderId): array {
		if ($orderId === '') {
			return [];
		}

		try {
			$reader = $this->container->get(self::TASK_READER);
			if (is_object($reader) === false || method_exists($reader, 'tasksOnObject') === false) {
				$this->logger->warning('PurchaseOrderApprovalTaskEvents: OpenRegister task reader not available; approval steps left out of the audit export');
				return [];
			}

			$tasks = $reader->tasksOnObject($orderId);
		} catch (\Throwable $e) {
			$this->logger->warning('PurchaseOrderApprovalTaskEvents: approval steps not readable: ' . $e->getMessage());
			return [];
		}

		if (is_array($tasks) === false) {
			return [];
		}

		return $tasks;
	}//end tasks()

	/**
	 * The ledger event of one decided task, or null for an open one.
	 *
	 * @param string $orderId The purchase order's uuid.
	 * @param mixed  $task    One `ObjectTaskRecord`.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-3.1
	 */
	private function decisionEvent(string $orderId, mixed $task): ?array {
		if (is_object($task) === false) {
			return null;
		}

		$decision = self::text(value: $task->outcome ?? null);
		if (($task->isTerminal ?? false) !== true || $decision === '') {
			return null;
		}

		$decidedBy = self::text(value: $task->decidedBy ?? ($task->assignee ?? null));
		$decidedAt = '';
		if (($task->decidedAt ?? null) instanceof DateTimeInterface) {
			$decidedAt = $task->decidedAt->format(DateTimeInterface::ATOM);
		}

		return [
			'event' => 'po_approval_decision',
			'timestamp' => $decidedAt,
			'actor' => $decidedBy,
			'objectType' => 'PurchaseOrder',
			'objectId' => $orderId,
			'details' => [
				'decision' => $decision,
				'step' => self::text(value: $task->title ?? null),
				'decidedBy' => $decidedBy,
				'onBehalfOf' => self::text(value: $task->onBehalfOf ?? null),
				'mandate' => self::text(value: $task->mandate ?? null),
				'taskId' => self::text(value: $task->uuid ?? null),
			],
		];
	}//end decisionEvent()

	/**
	 * A trimmed string of a scalar, '' otherwise.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-3.1
	 */
	private static function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()
}//end class
