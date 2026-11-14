<?php

/**
 * Payout Intake Service
 *
 * Turns one settlement a payment provider reported (through integriq's
 * `nl.conduction.payment.settlement` CloudEvent, design D1) into a
 * `ProviderPayout`: once per settlement reference, its lines matched to what
 * they paid (D2) and its amounts booked through the clearing account (D3).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Payout
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Payout;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records a reported settlement as one matched and booked ProviderPayout.
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.1
 */
class PayoutIntakeService {

	public const RESULT_RECORDED = 'recorded';
	public const RESULT_DUPLICATE = 'duplicate';
	public const RESULT_INVALID = 'invalid';

	/**
	 * The schema a payout is kept in.
	 *
	 * @var string
	 */
	public const SCHEMA = 'ProviderPayout';

	/**
	 * The providers the ProviderPayout schema knows.
	 *
	 * @var list<string>
	 */
	private const PROVIDERS = ['mollie', 'stripe'];

	/**
	 * The line kinds the ProviderPayout schema knows.
	 *
	 * @var list<string>
	 */
	private const LINE_TYPES = ['payment', 'refund', 'chargeback'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface          $objectService    OpenRegister's object service.
	 * @param RegisterLocation                $registerLocation Where the register lives.
	 * @param PayoutMatchingService           $matching         Matches the lines to what they paid.
	 * @param PayoutPostingRule               $posting          Builds the payout's journal entry.
	 * @param ObjectTransitionRunnerInterface $transitions      Posts the journal entry.
	 * @param LoggerInterface                 $logger           Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly PayoutMatchingService $matching,
		private readonly PayoutPostingRule $posting,
		private readonly ObjectTransitionRunnerInterface $transitions,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record one reported settlement, unless its reference is already kept.
	 *
	 * @param array<string, mixed> $settlement      The CloudEvent data: settlementReference, settledAt, currency,
	 *                                              amountGross, amountFees, amountRefunds, amountChargebacks,
	 *                                              amountNet, transactions [{providerPaymentId, type, amount, fee}],
	 *                                              and optionally provider and administrationId.
	 * @param string               $defaultProvider The provider when the settlement names none.
	 *
	 * @return array{result: string, payout: array<string, mixed>|null, reason: string}
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.1
	 */
	public function record(array $settlement, string $defaultProvider='mollie'): array {
		$reference = trim((string)($settlement['settlementReference'] ?? ''));
		if ($reference === '') {
			return ['result' => self::RESULT_INVALID, 'payout' => null, 'reason' => 'The settlement names no settlement reference.'];
		}

		if (is_numeric($settlement['amountNet'] ?? null) === false) {
			return ['result' => self::RESULT_INVALID, 'payout' => null, 'reason' => 'Settlement ' . $reference . ' carries no net amount.'];
		}

		$existing = $this->findByReference(reference: $reference);
		if ($existing !== null) {
			return ['result' => self::RESULT_DUPLICATE, 'payout' => $existing, 'reason' => ''];
		}

		$payout = $this->matching->match(payout: $this->build(settlement: $settlement, reference: $reference, defaultProvider: $defaultProvider));
		$payout = $this->book(payout: $payout);

		$saved = $this->scoped()->saveObject(object: $payout);

		return ['result' => self::RESULT_RECORDED, 'payout' => (ObjectIdentifier::recordWithId(candidate: $saved) ?? $payout), 'reason' => ''];
	}//end record()

	/**
	 * The ProviderPayout for a settlement, every line still unmatched.
	 *
	 * @param array<string, mixed> $settlement      The CloudEvent data.
	 * @param string               $reference       Its settlement reference, trimmed.
	 * @param string               $defaultProvider The provider when the settlement names none.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.1
	 */
	public function build(array $settlement, string $reference, string $defaultProvider='mollie'): array {
		$provider = strtolower(trim((string)($settlement['provider'] ?? '')));
		if (in_array($provider, self::PROVIDERS, true) === false) {
			$provider = $defaultProvider;
		}

		$lines = [];
		foreach ((array)($settlement['transactions'] ?? []) as $transaction) {
			if (is_array($transaction) === false) {
				continue;
			}

			$lines[] = $this->line(transaction: $transaction);
		}

		$gross = $this->amount(value: ($settlement['amountGross'] ?? null));
		if ($gross === null) {
			$payments = array_filter($lines, static fn (array $line): bool => $line['type'] === 'payment');
			$gross = round(array_sum(array_column($payments, 'amount')), 2);
		}

		return [
			'administrationId' => trim((string)($settlement['administrationId'] ?? '')),
			'provider' => $provider,
			'settlementReference' => $reference,
			'settledAt' => substr(trim((string)($settlement['settledAt'] ?? '')), 0, 10),
			'currency' => strtoupper(trim((string)($settlement['currency'] ?? 'EUR'))),
			'amountGross' => $gross,
			'amountFees' => ($this->amount(value: ($settlement['amountFees'] ?? null)) ?? 0.0),
			'amountRefunds' => ($this->amount(value: ($settlement['amountRefunds'] ?? null)) ?? 0.0),
			'amountChargebacks' => ($this->amount(value: ($settlement['amountChargebacks'] ?? null)) ?? 0.0),
			'amountNet' => ($this->amount(value: ($settlement['amountNet'] ?? null)) ?? 0.0),
			'lines' => $lines,
			'state' => 'received',
		];
	}//end build()

	/**
	 * Book a matched payout; a payout that cannot be booked stays matched.
	 *
	 * @param array<string, mixed> $payout The matched payout.
	 *
	 * @return array<string, mixed> The payout, `booked` with its journal entry when the posting succeeded.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.1
	 */
	private function book(array $payout): array {
		if ((string)($payout['state'] ?? '') !== 'matched') {
			return $payout;
		}

		try {
			$journal = $this->posting->journal(payout: $payout);
			$saved = $this->objectService
				->setRegister($this->registerLocation->getRegisterSlug())
				->setSchema('JournalEntry')
				->saveObject(object: $journal);
			$journalId = ObjectIdentifier::resolve(saved: $saved);
			if ($journalId === '') {
				$this->logger->warning('PayoutIntakeService: the journal entry of a payout was saved without an id; left matched');
				return $payout;
			}

			$this->transitions->runAsSystem(objectId: $journalId, action: 'postDirect');
		} catch (Throwable $e) {
			$this->logger->warning(
				'PayoutIntakeService: a payout could not be booked; left matched',
				['settlementReference' => ($payout['settlementReference'] ?? ''), 'exception' => $e->getMessage()]
			);
			return $payout;
		}

		$payout['glTransactionId'] = $journalId;
		$payout['state'] = 'booked';
		return $payout;
	}//end book()

	/**
	 * One payout line from a reported transaction.
	 *
	 * @param array<string, mixed> $transaction {providerPaymentId, type, amount, fee, originalPaymentId?}.
	 *
	 * @return array<string, mixed>
	 */
	private function line(array $transaction): array {
		$type = strtolower(trim((string)($transaction['type'] ?? 'payment')));
		if (in_array($type, self::LINE_TYPES, true) === false) {
			$type = 'payment';
		}

		$line = [
			'providerPaymentId' => trim((string)($transaction['providerPaymentId'] ?? '')),
			'type' => $type,
			'amount' => abs((float)($this->amount(value: ($transaction['amount'] ?? null)) ?? 0.0)),
			'fee' => $this->amount(value: ($transaction['fee'] ?? null)),
			'status' => 'unmatched',
		];

		$original = trim((string)($transaction['originalPaymentId'] ?? ''));
		if ($original !== '') {
			$line['originalPaymentId'] = $original;
		}

		return $line;
	}//end line()

	/**
	 * The payout already kept for a settlement reference.
	 *
	 * @param string $reference The settlement reference.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findByReference(string $reference): ?array {
		$rows = $this->scoped()->findAll(['filters' => ['settlementReference' => $reference], 'limit' => 5]);
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			// The filter is re-checked: a filter the query layer ignores must not make every settlement a duplicate.
			if ($record !== null && trim((string)($record['settlementReference'] ?? '')) === $reference) {
				return $record;
			}
		}

		return null;
	}//end findByReference()

	/**
	 * A money amount rounded to cents, or null when none is given.
	 *
	 * @param mixed $value The reported value.
	 *
	 * @return float|null
	 */
	private function amount(mixed $value): ?float {
		if (is_numeric($value) === false) {
			return null;
		}

		return round((float)$value, 2);
	}//end amount()

	/**
	 * The object service scoped to the payout schema.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(): ObjectServiceInterface {
		return $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema(self::SCHEMA);
	}//end scoped()
}//end class
