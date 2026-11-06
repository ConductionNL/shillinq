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
	 * @param array<string,mixed>             $params   The run's params.
	 * @param array<string,mixed>             $customer The CustomerMaster, or [] when unknown.
	 * @param array<string,mixed>             $invoice  The invoice.
	 * @param array<int,mixed>                $stages   The ladder's stages.
	 * @param array<int,array<string,mixed>>  $runs     The invoice's earlier DunningRuns.
	 * @param DateTimeImmutable               $now      Now.
	 *
	 * @return array<string,mixed> The params.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.4
	 */
	public function apply(array $params, array $customer, array $invoice, array $stages, array $runs, DateTimeImmutable $now): array {
		if ((float)($params['collectionCostAmount'] ?? 0.0) <= 0.0 || $this->isConsumer(customer: $customer, invoice: $invoice) === false) {
			return $params;
		}

		$letterSince = $this->letterDeliveredOn(stages: $stages, runs: $runs);
		if ($letterSince !== null && $letterSince <= $now->modify('-' . self::WAIT_DAYS . ' days')) {
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
		$letters = [];
		foreach ($stages as $stage) {
			if (is_array($stage) === true && ($stage['statutoryEffect'] ?? null) === self::LETTER_EFFECT) {
				$letters[] = (int)($stage['nr'] ?? 0);
			}
		}

		$earliest = null;
		foreach ($runs as $run) {
			if (in_array((int)($run['stageNr'] ?? 0), $letters, true) === false || ($run['deliveryStatus'] ?? null) !== 'DELIVERED') {
				continue;
			}

			$raw = (string)($run['executedOn'] ?? '');
			if ($raw === '') {
				continue;
			}

			try {
				$sent = new DateTimeImmutable($raw);
			} catch (Throwable $e) {
				continue;
			}

			if ($earliest === null || $sent < $earliest) {
				$earliest = $sent;
			}
		}

		return $earliest;
	}//end letterDeliveredOn()
}//end class
