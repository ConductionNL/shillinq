<?php

/**
 * Payment Plan Bank Matcher
 *
 * Finds the payment plans an incoming bank line can pay
 * (receivables-payment-plans design.md D4.1, REQ-RPPL-003):
 *
 * - high confidence: the remittance or a reference names an active plan's
 *   payment reference as a whole word (RGL-2026-0007 termijn 1);
 * - medium confidence: the amount equals the plan's next open instalment and
 *   the counterparty IBAN is the customer's.
 *
 * A single high-confidence plan is booked on arrival, the way ExactMatchBooker
 * books an invoice by its number. A medium one is only offered: a bookkeeper
 * confirms it, so nothing is allocated on a guess.
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
use OCA\Shillinq\Service\Bank\ManualMatchService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Plan candidates for bank lines, and booking a line to a plan.
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 */
class PaymentPlanBankMatcher {
	/**
	 * The confirming actor of a match made on arrival.
	 *
	 * @var string
	 */
	public const ACTOR = 'system:bankfeed';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object surface.
	 * @param SettingsService        $settings      Register slug.
	 * @param ManualMatchService     $matches       Writes the confirmed match.
	 * @param PaymentPlanService     $plans         Receives the payment.
	 * @param LoggerInterface        $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly ManualMatchService $matches,
		private readonly PaymentPlanService $plans,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The plans a line can pay, best first.
	 *
	 * @param array<string,mixed> $line The bank line.
	 *
	 * @return array<int,array{plan:array<string,mixed>,confidence:string,reason:string}>
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.3
	 */
	public function candidates(array $line): array {
		$amount = round((float)($line['amount'] ?? 0), 2);
		if ($amount <= 0) {
			return [];
		}

		$parts = [(string)($line['remittanceInfo'] ?? ''), (string)($line['endToEndRef'] ?? ''), (string)($line['reference'] ?? '')];
		$text = strtolower(implode(' ', $parts));
		$iban = $this->normaliseIban(iban: (string)($line['counterpartyIban'] ?? ''));
		$high = [];
		$medium = [];
		foreach ($this->activePlans(administrationId: (string)($line['administrationId'] ?? '')) as $plan) {
			$reference = (string)($plan['paymentReference'] ?? '');
			if ($reference !== '' && $this->namesReference(text: $text, reference: $reference) === true) {
				$high[] = ['plan' => $plan, 'confidence' => 'high', 'reason' => sprintf('The remittance names %s.', $reference)];
				continue;
			}

			$next = round((float)($plan['nextDueAmount'] ?? 0), 2);
			if ($iban !== '' && abs($next - $amount) < 0.005 && $iban === $this->customerIban(plan: $plan)) {
				$reason = sprintf('The amount is the next instalment of %s and the account is the customer\'s.', $reference);
				$medium[] = ['plan' => $plan, 'confidence' => 'medium', 'reason' => $reason];
			}
		}

		return array_merge($high, $medium);

	}//end candidates()

	/**
	 * Book a line to the one plan its remittance names, or leave it.
	 *
	 * @param array<string,mixed> $line A freshly written bank line with its id.
	 *
	 * @return array<string,mixed>|null The confirmed match, or null when the line waits.
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.3
	 */
	public function book(array $line): ?array {
		$high = array_values(array_filter($this->candidates(line: $line), static fn (array $candidate): bool => $candidate['confidence'] === 'high'));
		if (count($high) !== 1) {
			return null;
		}

		try {
			$reason = 'Booked on arrival by ' . self::ACTOR . ': ' . $high[0]['reason'];
			return $this->payFromLine(planId: (string)$high[0]['plan']['id'], line: $line, actor: self::ACTOR, reason: $reason);
		} catch (Throwable $e) {
			$this->logger->warning('PaymentPlanBankMatcher: line could not be booked to its plan, it waits', ['exception' => $e->getMessage()]);
			return null;
		}

	}//end book()

	/**
	 * Match a line to a plan and receive its amount on the plan.
	 *
	 * @param string              $planId The plan id.
	 * @param array<string,mixed> $line   The bank line with its id.
	 * @param string              $actor  The confirming user, or the bank feed.
	 * @param string              $reason Why.
	 *
	 * @return array<string,mixed> The confirmed match with the allocation.
	 *
	 * @throws PaymentPlanRefusedException When the plan cannot take the payment.
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.3
	 */
	public function payFromLine(string $planId, array $line, string $actor, string $reason): array {
		$plan = $this->plans->plan(planId: $planId);
		if ((string)($plan['lifecycleState'] ?? '') !== 'active') {
			throw new PaymentPlanRefusedException(template: 'Payments are only taken on an active plan.');
		}

		if ((string)($plan['administrationId'] ?? '') !== (string)($line['administrationId'] ?? '')) {
			throw new PaymentPlanRefusedException(template: 'This payment plan does not exist.');
		}

		$match = $this->matches->matchPaymentPlan(line: $line, plan: $plan, actor: $actor, reason: $reason);
		$paidDate = substr((string)($line['valueDate'] ?? ($line['transactionDate'] ?? '')), 0, 10);
		$match['allocation'] = $this->plans->receive(planId: $planId, amount: abs((float)($line['amount'] ?? 0)), paidDate: $paidDate, source: 'bank');
		return $match;

	}//end payFromLine()

	/**
	 * Whether the text names the reference as a whole word.
	 *
	 * @param string $text      Lower-cased remittance and references.
	 * @param string $reference The plan's payment reference.
	 *
	 * @return bool
	 */
	private function namesReference(string $text, string $reference): bool {
		$pattern = '/(^|[^a-z0-9])' . preg_quote(strtolower($reference), '/') . '($|[^a-z0-9])/';
		return preg_match($pattern, $text) === 1;

	}//end namesReference()

	/**
	 * The active plans of an administration.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function activePlans(string $administrationId): array {
		if ($administrationId === '') {
			return [];
		}

		$rows = $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema('PaymentPlan')
			->findAll(['filters' => ['administrationId' => $administrationId, 'lifecycleState' => 'active'], 'limit' => 5000]);
		$plans = [];
		foreach ($rows as $row) {
			$plan = ObjectIdentifier::recordWithId(candidate: $row);
			if ($plan !== null) {
				$plans[] = $plan;
			}
		}

		return $plans;

	}//end activePlans()

	/**
	 * The IBAN of the plan's customer, normalised.
	 *
	 * @param array<string,mixed> $plan The plan.
	 *
	 * @return string
	 */
	private function customerIban(array $plan): string {
		$customer = ObjectIdentifier::findOne(
			scoped: $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema('CustomerMaster'),
			id: (string)($plan['customerId'] ?? '')
		);
		return $this->normaliseIban(iban: (string)($customer['iban'] ?? ''));

	}//end customerIban()

	/**
	 * An IBAN without spaces, upper case.
	 *
	 * @param string $iban The IBAN.
	 *
	 * @return string
	 */
	private function normaliseIban(string $iban): string {
		return strtoupper(str_replace(' ', '', trim($iban)));

	}//end normaliseIban()
}//end class
