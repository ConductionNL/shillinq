<?php

/**
 * Tax Payment Reconciliation Service
 *
 * Tier-2 Vpb payment reconciliation (REQ-VPB-008). Matches a TaxPaymentTracking
 * record to GL postings by account + amount + date and reports the variance
 * between the GL amount and the recorded payment amount. GL is authoritative
 * (design.md D2); payment records index payments for deadline tracking and never
 * mutate the GL. Reads use the real OpenRegister ObjectService API
 * (find / findAll).
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
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-18
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Reconciles a Vpb payment record against the general ledger (REQ-VPB-008).
 *
 * Reads are scoped to a single administration: callers pass the administrationId
 * resolved from the authenticated user's context. The matched GL amount is the
 * sum of GLLine debit movements on the payment's linkedGLAccount in the period
 * derived from the payment date; the variance is GL minus payment. All money
 * arithmetic is in integer cents.
 *
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-18
 */
class TaxPaymentReconciliationService {
	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param TaxReportCalculator $calculator Pure-logic cents helper (reused for precision).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly TaxReportCalculator $calculator,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Reconcile a single payment record against the GL (REQ-VPB-008).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $paymentId Slug or id of the TaxPaymentTracking record.
	 *
	 * @return array{matched: bool, paymentAmount: float, glAmount: float, variance: float, glLineCount: int}
	 *
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-18
	 */
	public function reconcile(string $administrationId, string $paymentId): array {
		$payment = $this->findPayment(administrationId: $administrationId, paymentId: $paymentId);
		if ($payment === null) {
			return [
				'matched' => false,
				'paymentAmount' => 0.0,
				'glAmount' => 0.0,
				'variance' => 0.0,
				'glLineCount' => 0,
			];
		}

		$account = (string)($payment['linkedGLAccount'] ?? '');
		$paymentDate = (string)($payment['paymentDate'] ?? '');
		$periodId = $this->periodFromDate(date: $paymentDate);

		$glCents = 0;
		$count = 0;
		if ($account !== '' && $periodId !== '') {
			[$glCents, $count] = $this->glMovementForAccount(
				periodId: $periodId,
				accountNumber: $account
			);
		}

		$paymentCents = $this->calculator->toCents(amount: ($payment['amount'] ?? 0));
		$varianceCents = ($glCents - $paymentCents);

		return [
			'matched' => ($count > 0 && $varianceCents === 0),
			'paymentAmount' => $this->calculator->fromCents(cents: $paymentCents),
			'glAmount' => $this->calculator->fromCents(cents: $glCents),
			'variance' => $this->calculator->fromCents(cents: $varianceCents),
			'glLineCount' => $count,
		];

	}//end reconcile()

	/**
	 * Derive the quarterly period identifier (e.g. 2025-Q2) from an ISO date.
	 *
	 * @param string $date An ISO date or date-time string.
	 *
	 * @return string The period identifier, or '' when the date is unparseable.
	 */
	private function periodFromDate(string $date): string {
		if ($date === '') {
			return '';
		}

		$timestamp = strtotime($date);
		if ($timestamp === false) {
			return '';
		}

		$year = (int)date('Y', $timestamp);
		$month = (int)date('n', $timestamp);
		$quarter = (int)ceil($month / 3);

		return $year . '-Q' . $quarter;
	}//end periodFromDate()

	/**
	 * Sum GLLine debit movements (cents) for an account in a period.
	 *
	 * @param string $periodId Fiscal period identifier.
	 * @param string $accountNumber Account code to match.
	 *
	 * @return array{0:int,1:int} [summed cents, matched line count].
	 */
	private function glMovementForAccount(string $periodId, string $accountNumber): array {
		$lines = $this->objectService
			->setRegister($this->register())
			->setSchema('GLLine')
			->findAll(['filters' => ['periodId' => $periodId, 'accountNumber' => $accountNumber]]);

		$cents = 0;
		$count = 0;
		foreach ($lines as $line) {
			if (($line['eliminationFlag'] ?? false) === true) {
				continue;
			}

			if ((string)($line['accountNumber'] ?? '') !== $accountNumber) {
				continue;
			}

			$cents += $this->calculator->toCents(amount: ($line['amount'] ?? 0));
			$count++;
		}

		return [$cents, $count];
	}//end glMovementForAccount()

	/**
	 * Find a TaxPaymentTracking record by id/slug within an administration.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $paymentId Slug or id.
	 *
	 * @return array<string,mixed>|null The payment record, or null when absent.
	 */
	private function findPayment(string $administrationId, string $paymentId): ?array {
		$payments = $this->objectService
			->setRegister($this->register())
			->setSchema('TaxPaymentTracking')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		foreach ($payments as $payment) {
			$id = (string)($payment['id'] ?? ($payment['@self']['id'] ?? ''));
			$slug = (string)($payment['@self']['slug'] ?? '');
			if ($id === $paymentId || $slug === $paymentId) {
				return $payment;
			}
		}

		return null;
	}//end findPayment()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
