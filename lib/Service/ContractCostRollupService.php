<?php

/**
 * Contract Cost Rollup Service
 *
 * A contract with an expiry nobody watches is the most common avoidable
 * overspend in a gemeente, and a contract whose cost nobody adds up is the
 * second. `compliance-deadline-calendar` already watches the expiry. This adds
 * the money: what have the objects raised under this contract cost so far, and
 * how much of the agreed value is left.
 *
 * TWO RULES THAT LOOK SMALL AND ARE NOT
 * -------------------------------------
 * The total is STAMPED with the time it was computed. A total with no timestamp
 * cannot be told apart from a total that stopped updating six months ago, and
 * the second one is what people make decisions on.
 *
 * Unlinking an object recomputes the total and DOES NOT TOUCH the object. The
 * link is the contract's claim about the object, not the object's own property;
 * a roll-up that wrote back into a case in another app would be exactly the
 * cross-app write ADR-066 exists to prevent.
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
 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;

/**
 * Rolls the cost of a contract's linked objects up onto the contract.
 *
 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-005)
 */
final class ContractCostRollupService {
	/**
	 * The schema holding contracts.
	 *
	 * @var string
	 */
	public const SCHEMA = 'Contract';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param SubjectCostService $subjectCosts What one linked object has cost, in cents.
	 * @param IAppConfig $appConfig App config, for the register slug.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SubjectCostService $subjectCosts,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Recompute `incurredCost` and `remainingValue` for one contract and persist
	 * them with the time of the computation.
	 *
	 * @param array<string, mixed> $contract The contract.
	 *
	 * @return array<string, mixed> The contract with the roll-up applied.
	 *
	 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-005)
	 */
	public function rollUp(array $contract): array {
		$total = 0.0;
		$unreadable = 0;
		$links = ($contract['linkedObjects'] ?? []);
		if (is_array($links) === true) {
			foreach ($links as $index => $link) {
				if (is_array($link) === false) {
					++$unreadable;
					continue;
				}

				$cost = $this->costOf(link: $link);
				if ($cost === null) {
					// Counted, not summed. A subject that cannot be priced used
					// to contribute zero in silence, and the comment beside it
					// said the tell was a total that stops moving. It is not:
					// the timestamp below moves on every run, complete or not.
					++$unreadable;
					$links[$index]['cost'] = null;
					continue;
				}

				$links[$index]['cost'] = $cost;
				$total += $cost;
			}
		}

		$linked = [];
		if (is_array($links) === true) {
			$linked = array_values($links);
		}

		$contract['linkedObjects'] = $linked;
		$contract['incurredCost'] = round($total, 2);
		$contract['incurredCostComputedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$contract['incurredCostComplete'] = ($unreadable === 0);
		$contract['incurredCostUnreadableLinks'] = $unreadable;

		$agreed = ($contract['totalContractValue'] ?? null);
		if (is_numeric($agreed) === true && $unreadable === 0) {
			$contract['remainingValue'] = round(((float)$agreed - $contract['incurredCost']), 2);
		} elseif ($unreadable > 0) {
			// An incomplete total understates the cost, so a remaining value
			// derived from it OVERSTATES the budget left, and somebody commits
			// money that is already spent. No number is the honest answer.
			unset($contract['remainingValue']);
		}

		$this->objectService->saveObject(
			object: $contract,
			register: $this->registerSlug(),
			schema: self::SCHEMA,
		);

		return $contract;
	}//end rollUp()

	/**
	 * Remove one link and recompute, leaving the unlinked object untouched.
	 *
	 * @param array<string, mixed> $contract The contract.
	 * @param array<string, mixed> $reference The link to remove: register, schema, id.
	 *
	 * @return array<string, mixed> The contract with the link gone and the total recomputed.
	 *
	 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-005)
	 */
	public function unlink(array $contract, array $reference): array {
		$key = $this->linkKey(link: $reference);

		$kept = [];
		foreach (($contract['linkedObjects'] ?? []) as $link) {
			if (is_array($link) === true && $this->linkKey(link: $link) === $key) {
				continue;
			}

			$kept[] = $link;
		}

		$contract['linkedObjects'] = $kept;

		// Deliberately nothing is written to the unlinked object. The link was
		// the contract's claim about it, not a property of the object.
		return $this->rollUp(contract: $contract);
	}//end unlink()

	/**
	 * What one linked object has cost.
	 *
	 * @param array<string, mixed> $link The link.
	 *
	 * @return float|null The cost, or null when it cannot be read. Null and 0.0
	 *                    are different answers: one is a subject nobody could
	 *                    price, the other a subject that cost nothing.
	 */
	private function costOf(array $link): ?float {
		try {
			$aggregate = $this->subjectCosts->costFor(
				subjectApp: (string)($link['register'] ?? ''),
				subjectId: (string)($link['id'] ?? ''),
				administrationIds: null,
			);
		} catch (\Throwable $e) {
			// A subject whose cost cannot be read does not take the whole
			// roll-up down, but it is not silently worth nothing either. The
			// caller counts it and marks the total incomplete.
			return null;
		}

		$cents = ($aggregate['costCents'] ?? null);
		if (is_numeric($cents) === false) {
			// SubjectCostService withholds the total when a person is unpriced,
			// and it is right to. A withheld cost is withheld here too: reading
			// it as zero is the guess that service refused to make.
			return null;
		}

		return round(((float)$cents / 100), 2);
	}//end costOf()

	/**
	 * The identity of a link, over register, schema and id.
	 *
	 * @param array<string, mixed> $link The link.
	 *
	 * @return string The key.
	 */
	private function linkKey(array $link): string {
		return implode(
			'|',
			[
				(string)($link['register'] ?? ''),
				(string)($link['schema'] ?? ''),
				(string)($link['id'] ?? ''),
			]
		);
	}//end linkKey()

	/**
	 * The register slug holding shillinq's own objects.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		$register = $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
		if ($register === '') {
			// An admin who blanks the setting must not silently point every
			// read and write at the empty register. Fall back to the default.
			return 'shillinq';
		}

		return $register;
	}//end registerSlug()
}//end class
