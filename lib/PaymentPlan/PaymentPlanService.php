<?php

/**
 * Payment Plan Service
 *
 * Agrees, follows and ends payment plans for a customer's overdue invoices
 * (receivables-payment-plans, REQ-RPPL-001 to REQ-RPPL-005):
 *
 * - draft(): the plan and its schedule, adding up to the plan total to the cent;
 * - activate(): the declared `activate` transition (PaymentPlanGuard refuses an
 *   unbalanced schedule), a PAYMENT_PLAN dunning pause per invoice whose
 *   deadline is the last due date plus the grace period, the plan stamped on
 *   each invoice, and the schedule mailed to the customer;
 * - receive(): a payment allocated by PaymentPlanAllocator, the plan completed
 *   when every instalment is paid;
 * - monitor(): instalments falling due and missed, a plan broken on the first
 *   missed instalment, its pauses resumed and the customer told.
 *
 * Only completing, breaking or cancelling a plan resumes its pauses.
 *
 * @category PaymentPlan
 * @package  OCA\Shillinq\PaymentPlan
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\PaymentPlan;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\DunningRunService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The payment plan flow.
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 */
class PaymentPlanService {
	/**
	 * The dunning pause reason a plan writes.
	 *
	 * @var string
	 */
	public const PAUSE_REASON = 'PAYMENT_PLAN';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object surface.
	 * @param SettingsService        $settings      Register slug.
	 * @param PaymentPlanSchedule    $schedule      Draws up the instalments.
	 * @param PaymentPlanAllocator   $allocator     Allocates payments.
	 * @param DunningRunService      $dunning       Pauses and resumes dunning.
	 * @param ObjectTransitionRunner $transitions   Runs the declared transitions.
	 * @param PaymentPlanMailer      $mailer        Mails the customer.
	 * @param ITimeFactory           $time          Today.
	 * @param LoggerInterface        $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly PaymentPlanSchedule $schedule,
		private readonly PaymentPlanAllocator $allocator,
		private readonly DunningRunService $dunning,
		private readonly ObjectTransitionRunner $transitions,
		private readonly PaymentPlanMailer $mailer,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Draw up a plan and its schedule, in draft.
	 *
	 * @param array<string,mixed> $input  administrationId, customerId, invoiceIds, instalmentCount or
	 *                                    instalmentAmount, frequency, firstDueDate, graceDays,
	 *                                    includesCharges, chargesAmount, agreedWith, note.
	 * @param string              $actor  The user agreeing the plan.
	 *
	 * @return array{plan:array<string,mixed>,instalments:array<int,array<string,mixed>>}
	 *
	 * @throws PaymentPlanRefusedException When the invoices or the terms cannot make a plan.
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.1
	 */
	public function draft(array $input, string $actor): array {
		$administrationId = (string)($input['administrationId'] ?? '');
		$customerId = (string)($input['customerId'] ?? '');
		$invoices = $this->eligibleInvoices(
			administrationId: $administrationId,
			customerId: $customerId,
			invoiceIds: array_values(array_filter(array_map('strval', (array)($input['invoiceIds'] ?? []))))
		);

		$charges = 0.0;
		$includesCharges = (($input['includesCharges'] ?? false) === true);
		if ($includesCharges === true) {
			$charges = round(max(0.0, (float)($input['chargesAmount'] ?? 0)), 2);
		}

		$open = array_sum(array_map(static fn (array $invoice): float => (float)($invoice['amountDue'] ?? 0), $invoices));
		$total = round($open + $charges, 2);
		$count = null;
		if ((int)($input['instalmentCount'] ?? 0) > 0) {
			$count = (int)$input['instalmentCount'];
		}

		$amount = null;
		if ($count === null && (float)($input['instalmentAmount'] ?? 0) > 0) {
			$amount = round((float)$input['instalmentAmount'], 2);
		}

		$frequency = (string)($input['frequency'] ?? 'monthly');
		$firstDueDate = (string)($input['firstDueDate'] ?? '');
		try {
			$rows = $this->schedule->build(total: $total, frequency: $frequency, firstDueDate: $firstDueDate, count: $count, amount: $amount);
		} catch (InvalidArgumentException $e) {
			throw new PaymentPlanRefusedException(template: $e->getMessage());
		}

		$customer = $this->customer(customerId: $customerId);
		$planNumber = $this->nextPlanNumber(administrationId: $administrationId);
		$plan = [
			'planNumber' => $planNumber,
			'paymentReference' => $planNumber,
			'customerId' => $customerId,
			'customerName' => $this->customerName(customer: $customer),
			'customerEmail' => (string)($customer['email'] ?? ''),
			'invoiceIds' => array_keys($invoices),
			'invoiceNumbers' => array_values(array_map(static fn (array $invoice): string => (string)($invoice['invoiceNumber'] ?? ''), $invoices)),
			'totalAmount' => $total,
			'chargesAmount' => $charges,
			'includesCharges' => $includesCharges,
			'paidAmount' => 0,
			'arrears' => 0,
			'instalmentCount' => count($rows),
			'instalmentAmount' => $amount,
			'frequency' => $frequency,
			'firstDueDate' => $firstDueDate,
			'nextDueDate' => $rows[0]['dueDate'],
			'nextDueAmount' => $rows[0]['amount'],
			'lastDueDate' => $rows[(count($rows) - 1)]['dueDate'],
			'graceDays' => max(0, (int)($input['graceDays'] ?? 14)),
			'agreedOn' => $this->today(),
			'agreedWith' => (string)($input['agreedWith'] ?? ''),
			'note' => (string)($input['note'] ?? ''),
			'pauseIds' => [],
			'administrationId' => $administrationId,
			'lifecycleState' => 'draft',
		];
		$plan = $this->save(schema: 'PaymentPlan', object: $plan);

		$instalments = [];
		foreach ($rows as $row) {
			$instalments[] = $this->save(
				schema: 'PaymentPlanInstalment',
				object: $row + [
					'planId' => (string)$plan['id'],
					'planNumber' => $planNumber,
					'paidAmount' => 0,
					'allocations' => [],
					'administrationId' => $administrationId,
					'state' => 'pending',
				]
			);
		}

		$this->logger->info('PaymentPlanService: plan drawn up', ['planNumber' => $planNumber, 'actor' => $actor]);
		return ['plan' => $plan, 'instalments' => $instalments];

	}//end draft()

	/**
	 * Agree a drafted plan: pause dunning, stamp the invoices, mail the schedule.
	 *
	 * @param string $planId The plan id.
	 * @param string $actor  The user activating it.
	 *
	 * @return array<string,mixed> The plan as stored.
	 *
	 * @throws PaymentPlanRefusedException When the plan is not a draft or its schedule does not add up.
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.1
	 */
	public function activate(string $planId, string $actor): array {
		$plan = $this->plan(planId: $planId);
		if ((string)($plan['lifecycleState'] ?? '') !== 'draft') {
			throw new PaymentPlanRefusedException(template: 'Only a draft plan can be activated.');
		}

		try {
			$this->transitions->run(objectId: $planId, action: 'activate');
		} catch (Throwable $e) {
			throw new PaymentPlanRefusedException(template: 'The plan was not activated: %1$s', parameters: [$e->getMessage()]);
		}

		$deadline = (new DateTimeImmutable((string)$plan['lastDueDate']))->modify('+' . (int)$plan['graceDays'] . ' days');
		$details = sprintf('Payment plan %s: dunning paused while the plan is kept.', (string)$plan['planNumber']);
		$pauseIds = [];
		$invoices = $this->allocator->invoicesOldestFirst(plan: $plan);
		foreach ($invoices as $invoiceId => $invoice) {
			$pause = $this->dunning->pause(
				administrationId: (string)$plan['administrationId'],
				invoiceId: $invoiceId,
				reason: self::PAUSE_REASON,
				details: $details,
				pausedBy: $actor,
				evidenceRefs: null,
				hardDeadline: $deadline
			);
			$pauseIds[] = (string)($pause['id'] ?? '');
			$this->scoped(schema: 'ARInvoice')->patchObject($invoiceId, ['paymentPlanId' => $planId]);
		}

		$mail = $this->mailer->confirmation(
			plan: $plan,
			instalments: $this->allocator->instalments(planId: $planId),
			invoices: array_values($invoices),
			iban: $this->administrationIban(administrationId: (string)$plan['administrationId'])
		);
		$patch = ['pauseIds' => array_values(array_filter($pauseIds)), 'confirmationSentAt' => null];
		if ($this->mailer->send(recipient: (string)($plan['customerEmail'] ?? ''), mail: $mail) === true) {
			$patch['confirmationSentAt'] = $this->time->getDateTime()->format(DATE_ATOM);
		}

		$this->scoped(schema: 'PaymentPlan')->patchObject($planId, $patch);
		return $this->plan(planId: $planId);

	}//end activate()

	/**
	 * Receive a payment on an active plan.
	 *
	 * @param string $planId   The plan id.
	 * @param float  $amount   The amount received.
	 * @param string $paidDate When it arrived, Y-m-d.
	 * @param string $source   `bank`, `payment-link` or `by-hand`.
	 *
	 * @return array<string,mixed> The allocation, with the plan after it.
	 *
	 * @throws PaymentPlanRefusedException When the plan is not active or the amount is not positive.
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.2
	 */
	public function receive(string $planId, float $amount, string $paidDate, string $source): array {
		$plan = $this->plan(planId: $planId);
		if ((string)($plan['lifecycleState'] ?? '') !== 'active') {
			throw new PaymentPlanRefusedException(template: 'Payments are only taken on an active plan.');
		}

		if ($amount <= 0) {
			throw new PaymentPlanRefusedException(template: 'Enter an amount above zero.');
		}

		$result = $this->allocator->allocate(plan: $plan, amount: $amount, paidDate: $paidDate, source: $source);
		if ($result['allPaid'] === true) {
			$this->end(plan: $plan, action: 'complete', reason: 'Every instalment is paid.');
		}

		$result['plan'] = $this->refresh(planId: $planId);
		return $result;

	}//end receive()

	/**
	 * Cancel a draft or active plan and resume its pauses.
	 *
	 * @param string $planId The plan id.
	 * @param string $reason Why.
	 *
	 * @return array<string,mixed> The plan as stored.
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.1
	 */
	public function cancel(string $planId, string $reason): array {
		$plan = $this->plan(planId: $planId);
		$this->end(plan: $plan, action: 'cancel', reason: $reason);
		return $this->plan(planId: $planId);

	}//end cancel()

	/**
	 * The daily pass over every active plan (REQ-RPPL-004, REQ-RPPL-005).
	 *
	 * @return array{due:int,missed:int,broken:int,completed:int}
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-3.1
	 */
	public function monitor(): array {
		$counts = ['due' => 0, 'missed' => 0, 'broken' => 0, 'completed' => 0];
		$rows = $this->scoped(schema: 'PaymentPlan')->findAll(['filters' => ['lifecycleState' => 'active'], 'limit' => 10000]);
		foreach ($rows as $row) {
			$plan = ObjectIdentifier::recordWithId(candidate: $row);
			if ($plan === null) {
				continue;
			}

			try {
				$this->monitorPlan(plan: $plan, counts: $counts);
			} catch (Throwable $e) {
				$this->logger->error('PaymentPlanService: monitor failed for a plan', ['planId' => $plan['id'], 'exception' => $e->getMessage()]);
			}
		}

		return $counts;

	}//end monitor()

	/**
	 * A plan by id.
	 *
	 * @param string $planId The plan id.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws PaymentPlanRefusedException When there is no such plan.
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.1
	 */
	public function plan(string $planId): array {
		$plan = ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'PaymentPlan'), id: $planId);
		if ($plan === null) {
			throw new PaymentPlanRefusedException(template: 'This payment plan does not exist.');
		}

		$plan['id'] = $planId;
		return $plan;

	}//end plan()

	/**
	 * One plan's daily pass.
	 *
	 * @param array<string,mixed>                                  $plan   The active plan.
	 * @param array{due:int,missed:int,broken:int,completed:int} $counts Tally, updated.
	 *
	 * @return void
	 */
	private function monitorPlan(array $plan, array &$counts): void {
		$today = $this->today();
		$grace = (int)($plan['graceDays'] ?? 14);
		$missed = null;
		foreach ($this->allocator->instalments(planId: (string)$plan['id']) as $instalment) {
			$state = (string)($instalment['state'] ?? 'pending');
			$dueDate = (string)($instalment['dueDate'] ?? '');
			if ($state === 'paid' || $dueDate === '' || $dueDate > $today) {
				continue;
			}

			if ($state === 'missed') {
				$missed = ($missed ?? $instalment);
				continue;
			}

			$lastDay = (new DateTimeImmutable($dueDate))->modify('+' . $grace . ' days')->format('Y-m-d');
			if ($lastDay < $today) {
				$this->transitions->run(objectId: (string)$instalment['id'], action: 'miss');
				$counts['missed']++;
				$missed = ($missed ?? $instalment);
				continue;
			}

			if ($state === 'pending') {
				$this->transitions->run(objectId: (string)$instalment['id'], action: 'fall-due');
				$counts['due']++;
			}
		}//end foreach

		if ($missed !== null) {
			$reason = sprintf('Instalment %d, due on %s, was not paid.', (int)$missed['instalmentNumber'], (string)$missed['dueDate']);
			$this->end(plan: $plan, action: 'break', reason: $reason);
			$counts['broken']++;
			$this->refresh(planId: (string)$plan['id']);
			return;
		}

		$this->refresh(planId: (string)$plan['id']);

	}//end monitorPlan()

	/**
	 * End a plan: its transition, its pauses resumed, and for a break the mail.
	 *
	 * @param array<string,mixed> $plan   The plan.
	 * @param string              $action `complete`, `break` or `cancel`.
	 * @param string              $reason Why it ended.
	 *
	 * @return void
	 *
	 * @throws PaymentPlanRefusedException When the transition is refused.
	 */
	private function end(array $plan, string $action, string $reason): void {
		$planId = (string)$plan['id'];
		try {
			$this->transitions->run(objectId: $planId, action: $action);
		} catch (Throwable $e) {
			throw new PaymentPlanRefusedException(template: 'The plan could not be ended: %1$s', parameters: [$e->getMessage()]);
		}

		foreach ((array)($plan['pauseIds'] ?? []) as $pauseId) {
			try {
				$this->dunning->resumePause(administrationId: (string)$plan['administrationId'], pauseId: (string)$pauseId);
			} catch (Throwable $e) {
				$this->logger->error('PaymentPlanService: a dunning pause could not be resumed', ['pauseId' => $pauseId, 'exception' => $e->getMessage()]);
			}
		}

		$this->scoped(schema: 'PaymentPlan')->patchObject($planId, ['endedOn' => $this->today(), 'endReason' => $reason]);
		if ($action === 'break') {
			$plan = $this->refresh(planId: $planId);
			$this->mailer->send(recipient: (string)($plan['customerEmail'] ?? ''), mail: $this->mailer->ended(plan: $plan, reason: $reason));
		}

	}//end end()

	/**
	 * Recompute the plan's paid, arrears and next instalment from its schedule.
	 *
	 * @param string $planId The plan id.
	 *
	 * @return array<string,mixed> The plan as stored.
	 */
	private function refresh(string $planId): array {
		$today = $this->today();
		$paid = 0;
		$arrears = 0;
		$next = null;
		foreach ($this->allocator->instalments(planId: $planId) as $instalment) {
			$amount = (int)round((float)($instalment['amount'] ?? 0) * 100);
			$paidCents = (int)round((float)($instalment['paidAmount'] ?? 0) * 100);
			$paid += $paidCents;
			if ($paidCents >= $amount) {
				continue;
			}

			if ((string)($instalment['dueDate'] ?? '') <= $today) {
				$arrears += ($amount - $paidCents);
			}

			$next = ($next ?? ['nextDueDate' => (string)$instalment['dueDate'], 'nextDueAmount' => (($amount - $paidCents) / 100.0)]);
		}

		$patch = ['paidAmount' => ($paid / 100.0), 'arrears' => ($arrears / 100.0)];
		$patch += ($next ?? ['nextDueDate' => null, 'nextDueAmount' => null]);
		$this->scoped(schema: 'PaymentPlan')->patchObject($planId, $patch);
		return $this->plan(planId: $planId);

	}//end refresh()

	/**
	 * The selected invoices, checked: one customer, overdue, open, in no other plan.
	 *
	 * @param string            $administrationId The administration.
	 * @param string            $customerId       The customer.
	 * @param array<int,string> $invoiceIds       The selected invoice ids.
	 *
	 * @return array<string,array<string,mixed>> Keyed by id, oldest first.
	 *
	 * @throws PaymentPlanRefusedException When the selection cannot go on a plan.
	 */
	private function eligibleInvoices(string $administrationId, string $customerId, array $invoiceIds): array {
		if ($administrationId === '' || $customerId === '' || $invoiceIds === []) {
			throw new PaymentPlanRefusedException(template: 'Choose the customer and at least one overdue invoice.');
		}

		$invoices = $this->allocator->invoicesOldestFirst(plan: ['invoiceIds' => $invoiceIds]);
		$today = $this->today();
		foreach ($invoiceIds as $invoiceId) {
			$invoice = ($invoices[$invoiceId] ?? null);
			if ($invoice === null || (string)($invoice['administrationId'] ?? '') !== $administrationId) {
				throw new PaymentPlanRefusedException(template: 'Invoice %1$s could not be found.', parameters: [$invoiceId]);
			}

			$number = (string)($invoice['invoiceNumber'] ?? $invoiceId);
			if ((string)($invoice['customerId'] ?? '') !== $customerId) {
				throw new PaymentPlanRefusedException(template: 'Invoice %1$s belongs to another customer.', parameters: [$number]);
			}

			$state = (string)($invoice['lifecycleState'] ?? '');
			$overdue = ($state === 'overdue' || ($state === 'issued' && (string)($invoice['dueDate'] ?? '') < $today));
			if ($overdue === false || (float)($invoice['amountDue'] ?? 0) <= 0) {
				throw new PaymentPlanRefusedException(template: 'Invoice %1$s is not overdue with an amount due.', parameters: [$number]);
			}

			$this->assertNotInOpenPlan(invoice: $invoice, number: $number);
		}//end foreach

		return $invoices;

	}//end eligibleInvoices()

	/**
	 * Refuse an invoice another draft or active plan already covers.
	 *
	 * @param array<string,mixed> $invoice The invoice.
	 * @param string              $number  Its number, for the message.
	 *
	 * @return void
	 *
	 * @throws PaymentPlanRefusedException When it is covered.
	 */
	private function assertNotInOpenPlan(array $invoice, string $number): void {
		$planId = (string)($invoice['paymentPlanId'] ?? '');
		if ($planId === '') {
			return;
		}

		$plan = ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'PaymentPlan'), id: $planId);
		if ($plan !== null && in_array((string)($plan['lifecycleState'] ?? ''), ['draft', 'active'], true) === true) {
			throw new PaymentPlanRefusedException(
				template: 'Invoice %1$s is already on payment plan %2$s.',
				parameters: [$number, (string)($plan['planNumber'] ?? $planId)]
			);
		}

	}//end assertNotInOpenPlan()

	/**
	 * The next plan number of the administration this year: RGL-2026-0007.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return string
	 */
	private function nextPlanNumber(string $administrationId): string {
		$prefix = 'RGL-' . $this->time->getDateTime()->format('Y') . '-';
		$highest = 0;
		$rows = $this->scoped(schema: 'PaymentPlan')->findAll(['filters' => ['administrationId' => $administrationId], 'limit' => 10000]);
		foreach ($rows as $row) {
			$plan = ObjectIdentifier::recordWithId(candidate: $row);
			$number = (string)($plan['planNumber'] ?? '');
			if (str_starts_with($number, $prefix) === true) {
				$highest = max($highest, (int)substr($number, strlen($prefix)));
			}
		}

		return $prefix . str_pad((string)($highest + 1), 4, '0', STR_PAD_LEFT);

	}//end nextPlanNumber()

	/**
	 * The customer record, or an empty array.
	 *
	 * @param string $customerId The customer id.
	 *
	 * @return array<string,mixed>
	 */
	private function customer(string $customerId): array {
		return (ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'CustomerMaster'), id: $customerId) ?? []);

	}//end customer()

	/**
	 * The customer's name.
	 *
	 * @param array<string,mixed> $customer The customer record.
	 *
	 * @return string
	 */
	private function customerName(array $customer): string {
		$name = trim((string)($customer['tradeName'] ?? ''));
		if ($name === '') {
			$name = trim((string)($customer['legalName'] ?? ''));
		}

		return $name;

	}//end customerName()

	/**
	 * The administration's account to pay into.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return string
	 */
	private function administrationIban(string $administrationId): string {
		$administration = ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'Administration'), id: $administrationId);
		return (string)($administration['primaryIban'] ?? '');

	}//end administrationIban()

	/**
	 * Save an object and answer it with its id.
	 *
	 * @param string              $schema The schema.
	 * @param array<string,mixed> $object The object.
	 *
	 * @return array<string,mixed>
	 */
	private function save(string $schema, array $object): array {
		$saved = ObjectIdentifier::recordWithId(candidate: $this->scoped(schema: $schema)->saveObject($object));
		if ($saved === null) {
			throw new PaymentPlanRefusedException(template: 'The payment plan could not be saved.');
		}

		return $saved;

	}//end save()

	/**
	 * Today, Y-m-d.
	 *
	 * @return string
	 */
	private function today(): string {
		return $this->time->getDateTime()->format('Y-m-d');

	}//end today()

	/**
	 * The object service scoped to a schema of this app's register.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema($schema);

	}//end scoped()
}//end class
