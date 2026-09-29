<?php

/**
 * Payment Plan Allocator
 *
 * Puts a payment received on a plan onto its instalments in order, and each
 * euro onto the covered invoices oldest first (receivables-payment-plans
 * design.md D4, REQ-RPPL-003). An instalment paid in full takes its declared
 * `pay` transition; an amount above the instalment pays the next ones. Each
 * invoice's `paidAmount` rises and `amountDue` falls, and an invoice with
 * nothing left due moves to paid through its own transition (`mark-paid` from
 * issued, `pay-overdue` from overdue), the path a bank match settles by.
 * Every allocation is recorded on the instalment, so an auditor can follow
 * each euro to its invoice.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Allocates plan payments to instalments and invoices.
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 */
class PaymentPlanAllocator {
	/**
	 * The ARInvoice transition that pays it, per state.
	 *
	 * @var array<string,string>
	 */
	private const INVOICE_PAY_TRANSITIONS = [
		'issued' => 'mark-paid',
		'overdue' => 'pay-overdue',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object surface.
	 * @param SettingsService        $settings      Register slug.
	 * @param ObjectTransitionRunner $transitions   Runs the declared transitions.
	 * @param LoggerInterface        $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly ObjectTransitionRunner $transitions,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Allocate one payment to the plan.
	 *
	 * @param array<string,mixed> $plan     The plan, with its id.
	 * @param float               $amount   The amount received.
	 * @param string              $paidDate When it arrived, Y-m-d.
	 * @param string              $source    `bank`, `payment-link` or `by-hand`.
	 *
	 * @return array{allocated:float,unallocated:float,invoices:array<string,float>,instalmentsPaid:array<int,int>,allPaid:bool}
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.2
	 */
	public function allocate(array $plan, float $amount, string $paidDate, string $source): array {
		$left = (int)round($amount * 100);
		$invoices = $this->invoicesOldestFirst(plan: $plan);
		$openByInvoice = [];
		foreach ($invoices as $invoice) {
			$openByInvoice[(string)$invoice['id']] = (int)round((float)($invoice['amountDue'] ?? 0) * 100);
		}

		$perInvoice = [];
		$instalmentsPaid = [];
		foreach ($this->instalments(planId: (string)$plan['id']) as $instalment) {
			$open = ((int)round((float)($instalment['amount'] ?? 0) * 100) - (int)round((float)($instalment['paidAmount'] ?? 0) * 100));
			if ($left <= 0 || $open <= 0) {
				continue;
			}

			$take = min($open, $left);
			$left -= $take;
			$allocations = (array)($instalment['allocations'] ?? []);
			foreach ($this->spread(cents: $take, openByInvoice: $openByInvoice) as $invoiceId => $cents) {
				$perInvoice[$invoiceId] = (($perInvoice[$invoiceId] ?? 0) + $cents);
				$allocations[] = [
					'invoiceId' => $invoiceId,
					'invoiceNumber' => (string)($invoices[$invoiceId]['invoiceNumber'] ?? ''),
					'amount' => ($cents / 100.0),
					'paidDate' => $paidDate,
					'source' => $source,
				];
			}

			$paidCents = ((int)round((float)($instalment['paidAmount'] ?? 0) * 100) + $take);
			$paidInFull = ($take === $open);
			$this->saveInstalment(instalment: $instalment, paidCents: $paidCents, allocations: $allocations, paidDate: $paidDate, paidInFull: $paidInFull);
			if ($paidInFull === true) {
				$instalmentsPaid[] = (int)($instalment['instalmentNumber'] ?? 0);
			}
		}//end foreach

		foreach ($perInvoice as $invoiceId => $cents) {
			$this->payInvoice(invoice: $invoices[$invoiceId], cents: $cents);
		}

		$allocated = (int)round($amount * 100) - $left;
		return [
			'allocated' => ($allocated / 100.0),
			'unallocated' => ($left / 100.0),
			'invoices' => array_map(static fn (int $cents): float => ($cents / 100.0), $perInvoice),
			'instalmentsPaid' => $instalmentsPaid,
			'allPaid' => $this->allPaid(planId: (string)$plan['id']),
		];

	}//end allocate()

	/**
	 * The plan's instalments in schedule order.
	 *
	 * @param string $planId The plan id.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.2
	 */
	public function instalments(string $planId): array {
		$rows = $this->scoped(schema: 'PaymentPlanInstalment')->findAll(['filters' => ['planId' => $planId], 'limit' => 1000]);
		$instalments = [];
		foreach ($rows as $row) {
			$instalment = ObjectIdentifier::recordWithId(candidate: $row);
			if ($instalment !== null) {
				$instalments[] = $instalment;
			}
		}

		usort($instalments, static fn (array $a, array $b): int => ((int)($a['instalmentNumber'] ?? 0) <=> (int)($b['instalmentNumber'] ?? 0)));
		return $instalments;

	}//end instalments()

	/**
	 * The plan's invoices, keyed by id, oldest first.
	 *
	 * @param array<string,mixed> $plan The plan.
	 *
	 * @return array<string,array<string,mixed>>
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.2
	 */
	public function invoicesOldestFirst(array $plan): array {
		$invoices = [];
		foreach ((array)($plan['invoiceIds'] ?? []) as $invoiceId) {
			$invoice = ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'ARInvoice'), id: (string)$invoiceId);
			if ($invoice !== null) {
				$invoice['id'] = (string)$invoiceId;
				$invoices[] = $invoice;
			}
		}

		usort(
			$invoices,
			static fn (array $a, array $b): int => [(string)($a['dueDate'] ?? ''), (string)($a['invoiceDate'] ?? ''), (string)($a['invoiceNumber'] ?? '')]
				<=> [(string)($b['dueDate'] ?? ''), (string)($b['invoiceDate'] ?? ''), (string)($b['invoiceNumber'] ?? '')]
		);

		$keyed = [];
		foreach ($invoices as $invoice) {
			$keyed[(string)$invoice['id']] = $invoice;
		}

		return $keyed;

	}//end invoicesOldestFirst()

	/**
	 * Spread an amount over the invoices, oldest first.
	 *
	 * @param int                $cents         The amount in cents.
	 * @param array<string,int>  $openByInvoice Open cents per invoice, updated in place.
	 *
	 * @return array<string,int> Cents per invoice.
	 */
	private function spread(int $cents, array &$openByInvoice): array {
		$spread = [];
		foreach ($openByInvoice as $invoiceId => $open) {
			if ($cents <= 0) {
				break;
			}

			$take = min($open, $cents);
			if ($take <= 0) {
				continue;
			}

			$spread[$invoiceId] = $take;
			$openByInvoice[$invoiceId] = ($open - $take);
			$cents -= $take;
		}

		return $spread;

	}//end spread()

	/**
	 * Write an instalment's payment, and pay it when it is paid in full.
	 *
	 * @param array<string,mixed>            $instalment  The instalment.
	 * @param int                            $paidCents   What is paid on it now.
	 * @param array<int,array<string,mixed>> $allocations Its allocations.
	 * @param string                         $paidDate    The payment date.
	 * @param bool                           $paidInFull  Whether it is now paid.
	 *
	 * @return void
	 */
	private function saveInstalment(array $instalment, int $paidCents, array $allocations, string $paidDate, bool $paidInFull): void {
		$patch = ['paidAmount' => ($paidCents / 100.0), 'allocations' => $allocations];
		if ($paidInFull === true) {
			$patch['paidDate'] = $paidDate;
		}

		$id = (string)$instalment['id'];
		$this->scoped(schema: 'PaymentPlanInstalment')->patchObject($id, $patch);
		if ($paidInFull === true) {
			$this->transitions->run(objectId: $id, action: 'pay');
		}

	}//end saveInstalment()

	/**
	 * Raise an invoice's paid amount, lower its amount due, pay it at zero.
	 *
	 * @param array<string,mixed> $invoice The invoice.
	 * @param int                 $cents   The amount allocated to it.
	 *
	 * @return void
	 */
	private function payInvoice(array $invoice, int $cents): void {
		$id = (string)$invoice['id'];
		$due = max(0, (int)round((float)($invoice['amountDue'] ?? 0) * 100) - $cents);
		$paid = ((int)round((float)($invoice['paidAmount'] ?? 0) * 100) + $cents);
		$this->scoped(schema: 'ARInvoice')->patchObject($id, ['amountDue' => ($due / 100.0), 'paidAmount' => ($paid / 100.0)]);
		if ($due > 0) {
			return;
		}

		$transition = (self::INVOICE_PAY_TRANSITIONS[(string)($invoice['lifecycleState'] ?? '')] ?? null);
		if ($transition === null) {
			$this->logger->info('PaymentPlanAllocator: invoice fully paid but not in a payable state', ['invoiceId' => $id]);
			return;
		}

		try {
			$this->transitions->run(objectId: $id, action: $transition);
		} catch (Throwable $e) {
			$this->logger->error('PaymentPlanAllocator: invoice could not be moved to paid', ['invoiceId' => $id, 'exception' => $e->getMessage()]);
		}

	}//end payInvoice()

	/**
	 * Whether every instalment of the plan is paid.
	 *
	 * @param string $planId The plan id.
	 *
	 * @return bool
	 */
	private function allPaid(string $planId): bool {
		$instalments = $this->instalments(planId: $planId);
		if ($instalments === []) {
			return false;
		}

		foreach ($instalments as $instalment) {
			if ((string)($instalment['state'] ?? 'pending') !== 'paid') {
				return false;
			}
		}

		return true;

	}//end allPaid()

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
