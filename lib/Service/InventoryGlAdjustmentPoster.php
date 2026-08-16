<?php

/**
 * Inventory GL Adjustment Poster
 *
 * Shared balanced-posting adapter used by the two inventory
 * balance-sheet-correctness adjustment engines
 * ({@see LandedCostAllocationService} and {@see NrvWriteDownService}).
 *
 * Materialises a single, always-balanced two-line `GLTransaction` from an
 * explicit `(debitAccount, creditAccount, amountCents)` triple. Money
 * discipline is integer cents throughout; the debit and credit legs are
 * derived from the same `amountCents` so `debitCents === creditCents` holds
 * by construction. A defensive `balanceCheck` re-asserts the invariant
 * before persisting — an unbalanced request is refused (logged, never
 * posted), so a caller bug can never emit a lopsided journal.
 *
 * Persists `GLTransaction` (header) + two `GLLine` rows via OpenRegister's
 * ObjectService (ADR-022 — no app tables, no SQL). Mirrors the shape
 * {@see CogsPosterService} already writes so the general-ledger reader
 * treats inventory adjustments like any other journal.
 *
 * ADR-031 exception path: the amount is a runtime-computed monetary value
 * (a pro-rata landed-cost share or a lower-of-cost-or-NRV delta) that the
 * declarative `x-openregister-notifications` grammar cannot express;
 * precedent is {@see CogsPosterService} in this same register.
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
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Balanced two-line GLTransaction poster for inventory adjustments.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 */
class InventoryGlAdjustmentPoster {
	/**
	 * Construct the poster.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for diagnostics; never logs full payloads.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Post one balanced GLTransaction (debit leg + credit leg of the same
	 * integer-cent amount).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $debitAccount Account number debited.
	 * @param string $creditAccount Account number credited.
	 * @param int $amountCents Amount in integer cents (both legs).
	 * @param string $journalCode Journal code (e.g. 'LAND', 'NRV').
	 * @param string $description Human-readable line description.
	 * @param string $sourceReference Originating document reference.
	 * @param string $postingDate ISO yyyy-mm-dd posting date.
	 * @param string $periodId Period identifier (e.g. 2026-Q4).
	 *
	 * @return array<string,mixed> Result envelope with 'posted', 'balanced', 'transaction', 'debitCents', 'creditCents'.
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function post(
		string $administrationId,
		string $debitAccount,
		string $creditAccount,
		int $amountCents,
		string $journalCode,
		string $description,
		string $sourceReference,
		string $postingDate,
		string $periodId,
	): array {
		if ($amountCents <= 0) {
			return [
				'posted' => false,
				'message' => 'amountCents non-positive — nothing to post',
			];
		}

		if ($debitAccount === '' || $creditAccount === '') {
			$this->logger->warning(
				'InventoryGlAdjustmentPoster: GL accounts not configured — skipping post',
				[
					'journalCode' => $journalCode,
					'debitAccount' => $debitAccount,
					'creditAccount' => $creditAccount,
				]
			);
			return [
				'posted' => false,
				'message' => 'GL accounts not configured',
			];
		}

		// Balance invariant: both legs are the same amount by construction.
		$debitCents = $amountCents;
		$creditCents = $amountCents;
		if ($debitCents !== $creditCents) {
			$this->logger->error(
				'InventoryGlAdjustmentPoster: refusing to post an unbalanced transaction',
				[
					'journalCode' => $journalCode,
					'debitCents' => $debitCents,
					'creditCents' => $creditCents,
				]
			);
			return [
				'posted' => false,
				'balanced' => false,
				'message' => 'debitCents !== creditCents',
			];
		}

		$amount = round(($amountCents / 100), 2);
		$year = (int)substr($postingDate, 0, 4);
		if ($year === 0) {
			$year = (int)date('Y');
		}

		try {
			$transaction = $this->saveOnSchema(
				schema: 'GLTransaction',
				data: [
					'transactionNumber' => sprintf('%s-%04d-%s', $journalCode, $year, $sourceReference),
					'postingDate' => $postingDate,
					'periodId' => $periodId,
					'currency' => 'EUR',
					'description' => $description,
					'sourceReference' => $sourceReference,
					'state' => 'draft',
					'administrationId' => $administrationId,
				]
			);

			$transactionId = (string)($transaction['id'] ?? ($transaction['@self']['id'] ?? ''));

			$this->saveOnSchema(
				schema: 'GLLine',
				data: [
					'transactionId' => $transactionId,
					'lineNumber' => 1,
					'accountNumber' => $debitAccount,
					'side' => 'debit',
					'amount' => $amount,
					'currency' => 'EUR',
					'description' => $description,
				]
			);

			$this->saveOnSchema(
				schema: 'GLLine',
				data: [
					'transactionId' => $transactionId,
					'lineNumber' => 2,
					'accountNumber' => $creditAccount,
					'side' => 'credit',
					'amount' => $amount,
					'currency' => 'EUR',
					'description' => $description,
				]
			);

			return [
				'posted' => true,
				'balanced' => true,
				'transaction' => $transaction,
				'debitCents' => $debitCents,
				'creditCents' => $creditCents,
				'message' => 'inventory adjustment posted',
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'InventoryGlAdjustmentPoster: failed to post inventory adjustment',
				[
					'journalCode' => $journalCode,
					'amountCents' => $amountCents,
					'exception' => $e->getMessage(),
				]
			);

			return [
				'posted' => false,
				'message' => 'posting raised: ' . $e->getMessage(),
			];
		}//end try

	}//end post()

	/**
	 * Generic ObjectService::saveObject helper bound to the configured register.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $data Row body.
	 *
	 * @return array<string,mixed>
	 */
	private function saveOnSchema(string $schema, array $data): array {
		$saved = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->saveObject($data);

		if (is_array($saved) === true) {
			return $saved;
		}

		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			$out = $saved->jsonSerialize();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {
			$out = $saved->getObject();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		throw new RuntimeException('InventoryGlAdjustmentPoster: unsupported row type from ObjectService::saveObject');
	}//end saveOnSchema()

	/**
	 * Resolve the OR register slug, defaulting to 'shillinq'.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
