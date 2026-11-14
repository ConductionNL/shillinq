<?php

/**
 * Payout Bank Matcher
 *
 * Reconciles the bank statement line a provider payout arrived as with that
 * payout (design D4, REQ-RPPO-004): a line whose amount equals a booked
 * payout's net amount and whose remittance names its settlement reference gets
 * one confirmed `provider-payout` match, and the payout moves to `reconciled`
 * with the line on it. The payout's posting already booked the money, so the
 * match books nothing.
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
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Payout;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ManualMatchServiceInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Bank statement line to the booked provider payout it brought in.
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.5
 */
class PayoutBankMatcher {

	/**
	 * The actor recorded on an automatic payout match.
	 *
	 * @var string
	 */
	public const ACTOR = 'system:bank-match';

	/**
	 * The most booked payouts read for one line.
	 *
	 * @var int
	 */
	private const READ_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface      $objectService    OpenRegister's object service.
	 * @param RegisterLocation            $registerLocation Where the register lives.
	 * @param ManualMatchServiceInterface $manualMatch      Writes and confirms the match.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly ManualMatchServiceInterface $manualMatch,
	) {
	}//end __construct()

	/**
	 * The booked, not yet reconciled payout a bank line brought in, or null.
	 *
	 * @param array<string, mixed> $line The bank statement line.
	 *
	 * @return array<string, mixed>|null The payout, with its id.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.5
	 */
	public function find(array $line): ?array {
		$cents = (int)round(((float)($line['amount'] ?? 0)) * 100);
		$text = mb_strtolower(
			implode(' ', [(string)($line['remittanceInfo'] ?? ''), (string)($line['endToEndRef'] ?? ''), (string)($line['reference'] ?? '')])
		);
		if ($cents <= 0 || trim($text) === '') {
			return null;
		}

		$administrationId = trim((string)($line['administrationId'] ?? ''));
		$found = [];
		foreach ($this->bookedPayouts() as $payout) {
			if ((int)round(((float)($payout['amountNet'] ?? 0)) * 100) !== $cents) {
				continue;
			}

			if ($administrationId !== '' && trim((string)($payout['administrationId'] ?? '')) !== $administrationId) {
				continue;
			}

			if ($this->quotes(text: $text, reference: (string)($payout['settlementReference'] ?? '')) === true) {
				$found[] = $payout;
			}
		}

		// Two payouts with the same net and reference cannot be told apart: leave the line to the bookkeeper.
		if (count($found) !== 1) {
			return null;
		}

		return $found[0];
	}//end find()

	/**
	 * Match a new bank line to its payout, if it brought one in.
	 *
	 * @param array<string, mixed> $line The unmatched bank statement line, with its id.
	 *
	 * @return array<string, mixed>|null The confirmed match, or null when the line brought in no payout.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.5
	 */
	public function reconcile(array $line): ?array {
		$payout = $this->find(line: $line);
		if ($payout === null) {
			return null;
		}

		$match = $this->manualMatch->matchProviderPayout(line: $line, payout: $payout, actor: self::ACTOR);

		$payout['bankStatementLineId'] = (string)($line['id'] ?? '');
		$payout['state'] = 'reconciled';
		$this->scoped()->saveObject(object: $payout);

		return $match;
	}//end reconcile()

	/**
	 * The payouts that are booked and carry no bank line yet.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function bookedPayouts(): array {
		$payouts = [];
		foreach ($this->scoped()->findAll(['filters' => ['state' => 'booked'], 'limit' => self::READ_LIMIT]) as $row) {
			$payout = ObjectIdentifier::recordWithId(candidate: $row);
			if ($payout === null || (string)($payout['state'] ?? '') !== 'booked' || trim((string)($payout['bankStatementLineId'] ?? '')) !== '') {
				continue;
			}

			$payouts[] = $payout;
		}

		return $payouts;
	}//end bookedPayouts()

	/**
	 * Whether the line text holds the settlement reference as a whole token.
	 *
	 * @param string $text      The lower-cased line text.
	 * @param string $reference The settlement reference.
	 *
	 * @return bool
	 */
	private function quotes(string $text, string $reference): bool {
		$needle = mb_strtolower(trim($reference));
		if ($needle === '') {
			return false;
		}

		return preg_match('/(?<![a-z0-9_])' . preg_quote($needle, '/') . '(?![a-z0-9_])/u', $text) === 1;
	}//end quotes()

	/**
	 * The object service scoped to the payout schema.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(): ObjectServiceInterface {
		return $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema(PayoutIntakeService::SCHEMA);
	}//end scoped()
}//end class
