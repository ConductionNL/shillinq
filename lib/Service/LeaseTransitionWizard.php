<?php

/**
 * Lease Transition Wizard (skeleton)
 *
 * One-time IFRS 16 adoption wizard. Customers transitioning from operating-
 * lease accounting (IAS 17 / pre-IFRS 16 Dutch GAAP) need to recognise their
 * lease portfolio under IFRS 16 as of the adoption date. The standard offers
 * two approaches:
 *
 *  - Modified-retrospective (IFRS 16.C5(b)) — recognise the lease liability at
 *    the transition date using the transition-date IBR; the RoU asset can be
 *    measured equal to the liability (adjusted for prepaid / accrued rent) so
 *    no comparative restatement is needed.
 *  - Full-retrospective (IFRS 16.C5(a) + IAS 8) — restate every comparative
 *    period as if IFRS 16 had always applied; the opening retained-earnings
 *    adjustment recognises the cumulative catch-up.
 *
 * Together with the IFRS 16.C3 / C10 practical-expedient elections, the wizard
 * produces the per-lease recognition payload (delegated to LeaseRecognition-
 * Service) plus the transition disclosure note seed.
 *
 * This is a Phase-2 skeleton: the live wizard UI / docudesk PDF render lands
 * on a running instance. The class already exposes the deterministic, unit-
 * testable computation entry points.
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
 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Skeleton service computing the IFRS 16 transition recognition entries.
 *
 * Two transition methods plus the IFRS 16.C3 / C10 practical-expedient
 * elections are honoured at the input layer; the underlying recognition
 * arithmetic is delegated to LeaseRecognitionService so the transition wizard
 * shares the production code path (no parallel maths). Pure-logic — no
 * OpenRegister dependency, fully unit-testable.
 *
 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.LongVariable)
 * Pre-existing debt (issue #506): early-return refactor and variable
 * renames deferred pending a dedicated pass.
 */
class LeaseTransitionWizard {
	/**
	 * Construct the wizard with the recognition service.
	 *
	 * @param LeaseRecognitionService $recognitionService Delegates the RoU / liability maths.
	 * @param LeaseAmortizationCalculator $calculator Pure-logic IFRS 16 helper.
	 */
	public function __construct(
		private readonly LeaseRecognitionService $recognitionService,
		private readonly LeaseAmortizationCalculator $calculator,
	) {
	}//end __construct()

	/**
	 * Compute the transition recognition payload for a portfolio of leases.
	 *
	 * @param array<int,array<string,mixed>> $leases Pre-IFRS-16 operating leases.
	 * @param string $method modified-retrospective | full-retrospective.
	 * @param string $transitionDate ISO date (e.g. "2026-01-01").
	 * @param array<string,bool> $practicalExpedients Optional IFRS 16.C3 / C10 election flags.
	 *
	 * @return array{
	 *   method:string,
	 *   transitionDate:string,
	 *   practicalExpedients:array<string,bool>,
	 *   recognitions:array<int,array<string,mixed>>,
	 *   totalRouAsset:float,
	 *   totalLeaseLiability:float,
	 *   openingRetainedEarningsAdjustment:float,
	 *   disclosureNoteSeed:string
	 * }
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	public function compute(
		array $leases,
		string $method,
		string $transitionDate,
		array $practicalExpedients = [],
	): array {
		if (in_array($method, ['modified-retrospective', 'full-retrospective'], true) === false) {
			$method = 'modified-retrospective';
		}

		$expedients = $this->normaliseExpedients(elections: $practicalExpedients);

		$recognitions = [];
		$totalRouCents = 0;
		$totalLiabCents = 0;
		$catchUpCents = 0;

		foreach ($leases as $lease) {
			if (is_array($lease) === false) {
				continue;
			}

			// Filter exempt leases unless the customer elected to include them.
			$classification = (string)($lease['classification'] ?? '');
			if ($expedients['short-term-exempt-at-transition'] === true
				&& $classification === 'short-term-exempt'
			) {
				continue;
			}

			$recognition = $this->recognitionService->recognise(lease: $lease);

			$rouCents = $this->calculator->toCents(amount: $recognition['rouAsset']);
			$liabCents = $this->calculator->toCents(amount: $recognition['liability']);
			$totalRouCents += $rouCents;
			$totalLiabCents += $liabCents;
			$catchUpCents += ($rouCents - $liabCents);

			$recognitions[] = [
				'leaseNumber' => (string)($lease['leaseNumber'] ?? ''),
				'method' => $method,
				'recognition' => $recognition,
			];
		}//end foreach

		// Modified-retrospective sets RoU = liability adjusted for prepaid /
		// accrued rent so the opening retained-earnings adjustment is zero per
		// lease unless the customer elected the C8(b)(ii) adjustment.
		$openingRetainedEarningsAdjustment = 0;
		if ($method === 'full-retrospective') {
			$openingRetainedEarningsAdjustment = -$catchUpCents;
		}

		return [
			'method' => $method,
			'transitionDate' => $transitionDate,
			'practicalExpedients' => $expedients,
			'recognitions' => $recognitions,
			'totalRouAsset' => $this->calculator->fromCents(cents: $totalRouCents),
			'totalLeaseLiability' => $this->calculator->fromCents(cents: $totalLiabCents),
			'openingRetainedEarningsAdjustment' => $this->calculator->fromCents(cents: $openingRetainedEarningsAdjustment),
			'disclosureNoteSeed' => $this->buildDisclosureSeed(
				method: $method,
				transitionDate: $transitionDate,
				leaseCount: count($recognitions),
				expedients: $expedients,
			),
		];

	}//end compute()

	/**
	 * Normalise the IFRS 16.C3 / C10 practical-expedient elections.
	 *
	 * Defaults follow IFRS 16.C10: none elected. The wizard surfaces every
	 * election in the disclosure note so auditors can confirm consistency.
	 *
	 * @param array<string,bool> $elections Caller-supplied elections.
	 *
	 * @return array<string,bool> Normalised elections (every key present).
	 */
	private function normaliseExpedients(array $elections): array {
		$defaults = [
			'single-discount-rate-by-class' => false,
			'short-term-exempt-at-transition' => false,
			'low-value-exempt-at-transition' => false,
			'hindsight-on-extension-options' => false,
			'exclude-initial-direct-costs' => false,
			'use-onerous-contracts-provision' => false,
		];

		$out = [];
		foreach ($defaults as $key => $default) {
			$out[$key] = (bool)($elections[$key] ?? $default);
		}

		return $out;
	}//end normaliseExpedients()

	/**
	 * Build the IFRS 16.C12 transition disclosure note seed.
	 *
	 * @param string $method Transition method.
	 * @param string $transitionDate Transition date (ISO).
	 * @param int $leaseCount Recognised lease count.
	 * @param array<string,bool> $expedients Normalised elections.
	 *
	 * @return string Seed text for the operator to refine.
	 */
	private function buildDisclosureSeed(
		string $method,
		string $transitionDate,
		int $leaseCount,
		array $expedients,
	): string {
		$elected = [];
		foreach ($expedients as $key => $on) {
			if ($on === true) {
				$elected[] = $key;
			}
		}

		if ($elected === []) {
			$electedSentence = 'No practical expedients were elected.';
		} else {
			$electedSentence = 'The following practical expedients were elected: ' . implode(', ', $elected) . '.';
		}

		return sprintf(
			'The entity adopted IFRS 16 on %s using the %s approach. %d lease(s) '
			. 'were recognised at transition. %s See the maturity analysis and weighted-'
			. 'average IBR for the discounting basis.',
			$transitionDate,
			$method,
			$leaseCount,
			$electedSentence,
		);

	}//end buildDisclosureSeed()
}//end class
