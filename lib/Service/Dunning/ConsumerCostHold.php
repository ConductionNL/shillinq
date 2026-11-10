<?php

/**
 * Consumer collection-cost hold.
 *
 * A consumer may be charged collection costs only once 14 days have passed
 * after the letter that announced them (article 6:96 lid 6 BW). The ladder
 * marks that letter with statutoryEffect 14_DAYS_BRIEF_BIK; a stage carrying
 * costs goes without them until such a letter was delivered at least 15 days
 * before (design D7 of receivables-automatic-dunning).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

use DateTimeImmutable;
use Throwable;

/**
 * Holds collection costs back for a consumer until the 14-day letter period passed.
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.4
 */
final class ConsumerCostHold {

	/**
	 * The statutory effect that marks the 14-day letter.
	 *
	 * @var string
	 */
	public const LETTER_EFFECT = '14_DAYS_BRIEF_BIK';

	/**
	 * Days after the delivered letter before costs may be charged: the 14 days
	 * the debtor is given, plus the day the letter arrives.
	 *
	 * @var int
	 */
	public const WAIT_DAYS = 15;

	/**
	 * Whether the debtor is a consumer: no KvK number and no VAT id, on the
	 * customer or on the invoice. A debtor nobody can identify counts as a
	 * consumer, the side the law protects.
	 *
	 * @param array<string,mixed> $customer The CustomerMaster, or [] when unknown.
	 * @param array<string,mixed> $invoice  The invoice.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.4
	 */
	public function isConsumer(array $customer, array $invoice): bool {
		foreach ([$customer['kvkNumber'] ?? null, $customer['vatId'] ?? null, $invoice['buyerVatId'] ?? null] as $identifier) {
			if (trim((string)$identifier) !== '') {
				return false;
			}
		}

		return true;
	}//end isConsumer()

	/**
	 * The run's params with the collection costs removed when a consumer's
	 * 14-day letter period has not passed.
	 *
	 * @param CostHoldRequest $request The params, debtor, ladder, runs and moment.
	 *
	 * @return array<string,mixed> The params.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.4
	 */
	public function apply(CostHoldRequest $request): array {
		$params = $request->params;
		if ((float)($params['collectionCostAmount'] ?? 0.0) <= 0.0 || $this->isConsumer(customer: $request->customer, invoice: $request->invoice) === false) {
			return $params;
		}

		$letterSince = $this->letterDeliveredOn(stages: $request->stages, runs: $request->runs);
		if ($letterSince !== null && $letterSince <= $request->now->modify('-' . self::WAIT_DAYS . ' days')) {
			return $params;
		}

		$params['collectionCostAmount'] = null;
		return $params;
	}//end apply()

	/**
	 * When the earliest delivered 14-day letter went out, or null when none did.
	 *
	 * @param array<int,mixed>               $stages The ladder's stages.
	 * @param array<int,array<string,mixed>> $runs   The invoice's earlier runs.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function letterDeliveredOn(array $stages, array $runs): ?DateTimeImmutable {
		$letters  = $this->letterStageNumbers(stages: $stages);
		$earliest = null;
		foreach ($runs as $run) {
			$sent = null;
			if (in_array((int)($run['stageNr'] ?? 0), $letters, true) === true) {
				$sent = $this->deliveredOn(run: $run);
			}

			if ($sent !== null && ($earliest === null || $sent < $earliest)) {
				$earliest = $sent;
			}
		}

		return $earliest;
	}//end letterDeliveredOn()

	/**
	 * The numbers of the ladder's 14-day letter stages.
	 *
	 * @param array<int,mixed> $stages The ladder's stages.
	 *
	 * @return list<int>
	 */
	private function letterStageNumbers(array $stages): array {
		$letters = [];
		foreach ($stages as $stage) {
			if (is_array($stage) === true && ($stage['statutoryEffect'] ?? null) === self::LETTER_EFFECT) {
				$letters[] = (int)($stage['nr'] ?? 0);
			}
		}

		return $letters;
	}//end letterStageNumbers()

	/**
	 * When a delivered run went out, or null when it was not delivered or
	 * carries no readable date.
	 *
	 * @param array<string,mixed> $run One DunningRun.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function deliveredOn(array $run): ?DateTimeImmutable {
		$raw = (string)($run['executedOn'] ?? '');
		if (($run['deliveryStatus'] ?? null) !== 'DELIVERED' || $raw === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($raw);
		} catch (Throwable $e) {
			return null;
		}
	}//end deliveredOn()
}//end class
