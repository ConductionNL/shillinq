<?php

/**
 * Inventory GL Adjustment Poster
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared balanced-posting adapter used by the two inventory
 * balance-sheet-correctness adjustment engines
 * ({@see LandedCostAllocationService} and {@see NrvWriteDownService}).
 *
 * Materialises a single, always-balanced two-line `GLTransaction` from an
 * explicit `(debitAccount, creditAccount, amountCents)` triple. Money
 * discipline is integer cents throughout; the debit and credit legs are
 * derived from the same `amountCents` so `debitCents === creditCents` holds
 * by construction — a caller can never emit a lopsided journal.
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
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
	 * @param GlAdjustmentPosting $posting The adjustment to post.
	 *
	 * @return array<string,mixed> Result envelope with 'posted', 'balanced', 'transaction', 'debitCents', 'creditCents'.
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function postAdjustment(GlAdjustmentPosting $posting): array {
		$refusal = $this->refusal(posting: $posting);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return $this->persist(posting: $posting);
		} catch (\Throwable $e) {
			return $this->failure(posting: $posting, error: $e);
		}

	}//end postAdjustment()

	/**
	 * Log a failed post and report it in the envelope.
	 *
	 * @param GlAdjustmentPosting $posting The adjustment that failed.
	 * @param \Throwable          $error   What the store raised.
	 *
	 * @return array<string,mixed> The not-posted envelope.
	 */
	private function failure(GlAdjustmentPosting $posting, \Throwable $error): array {
		$this->logger->error(
			'InventoryGlAdjustmentPoster: failed to post inventory adjustment',
			[
				'journalCode' => $posting->journalCode,
				'amountCents' => $posting->amountCents,
				'exception' => $error->getMessage(),
			]
		);

		return [
			'posted' => false,
			'message' => 'posting raised: ' . $error->getMessage(),
		];
	}//end failure()

	/**
	 * The not-posted envelope for a non-positive amount or unconfigured
	 * accounts, or null when the posting may proceed.
	 *
	 * @param GlAdjustmentPosting $posting The adjustment to post.
	 *
	 * @return array<string,mixed>|null The refusal envelope, or null.
	 */
	private function refusal(GlAdjustmentPosting $posting): ?array {
		if ($posting->amountCents <= 0) {
			return [
				'posted' => false,
				'message' => 'amountCents non-positive — nothing to post',
			];
		}

		return $this->missingAccountRefusal(posting: $posting);
	}//end refusal()

	/**
	 * The not-posted envelope when an account is not configured, or null.
	 *
	 * @param GlAdjustmentPosting $posting The adjustment to post.
	 *
	 * @return array<string,mixed>|null The refusal envelope, or null.
	 */
	private function missingAccountRefusal(GlAdjustmentPosting $posting): ?array {
		if ($posting->debitAccount === '' || $posting->creditAccount === '') {
			$this->logger->warning(
				'InventoryGlAdjustmentPoster: GL accounts not configured — skipping post',
				[
					'journalCode' => $posting->journalCode,
					'debitAccount' => $posting->debitAccount,
					'creditAccount' => $posting->creditAccount,
				]
			);
			return [
				'posted' => false,
				'message' => 'GL accounts not configured',
			];
		}

		return null;
	}//end missingAccountRefusal()

	/**
	 * Persist the header and both legs. Both legs carry the same amount by
	 * construction, so debitCents === creditCents.
	 *
	 * @param GlAdjustmentPosting $posting The adjustment to post.
	 *
	 * @return array<string,mixed> The posted envelope.
	 */
	private function persist(GlAdjustmentPosting $posting): array {
		$transaction = $this->saveOnSchema(schema: 'GLTransaction', data: $this->transactionHeader(posting: $posting));
		$transactionId = (string)($transaction['id'] ?? ($transaction['@self']['id'] ?? ''));
		$this->saveLegs(posting: $posting, transactionId: $transactionId);

		return [
			'posted' => true,
			'balanced' => true,
			'transaction' => $transaction,
			'debitCents' => $posting->amountCents,
			'creditCents' => $posting->amountCents,
			'message' => 'inventory adjustment posted',
		];
	}//end persist()

	/**
	 * The GLTransaction header; the transaction number carries the posting
	 * year, or the current year when the posting date has none.
	 *
	 * @param GlAdjustmentPosting $posting The adjustment to post.
	 *
	 * @return array<string,mixed> The header.
	 */
	private function transactionHeader(GlAdjustmentPosting $posting): array {
		$year = (int)substr($posting->postingDate, 0, 4);
		if ($year === 0) {
			$year = (int)date('Y');
		}

		return [
			'transactionNumber' => sprintf('%s-%04d-%s', $posting->journalCode, $year, $posting->sourceReference),
			'postingDate' => $posting->postingDate,
			'periodId' => $posting->periodId,
			'currency' => 'EUR',
			'description' => $posting->description,
			'sourceReference' => $posting->sourceReference,
			'state' => 'draft',
			'administrationId' => $posting->administrationId,
		];
	}//end transactionHeader()

	/**
	 * Save the debit leg (line 1) and the credit leg (line 2).
	 *
	 * The administrationId is DENORMALISED onto every line from the header
	 * (REQ-GLS-001). GLLine declares the property, and SpendAnalyticsService
	 * filters the category / cost-centre / period aggregations on it — a
	 * line written without it would be invisible to its own administration's
	 * totals while the backfill completeness gate flipped red for the whole
	 * instance.
	 *
	 * @param GlAdjustmentPosting $posting       The adjustment to post.
	 * @param string              $transactionId The saved header's id.
	 *
	 * @return void
	 */
	private function saveLegs(GlAdjustmentPosting $posting, string $transactionId): void {
		$legs = [
			['transactionId' => $transactionId, 'lineNumber' => 1, 'accountNumber' => $posting->debitAccount, 'side' => 'debit'],
			['transactionId' => $transactionId, 'lineNumber' => 2, 'accountNumber' => $posting->creditAccount, 'side' => 'credit'],
		];
		foreach ($legs as $leg) {
			$this->saveOnSchema(schema: 'GLLine', data: $this->legLine(posting: $posting, leg: $leg));
		}
	}//end saveLegs()

	/**
	 * One GLLine: the leg's header id, line number, account and side, with
	 * the posting's administration, amount and description.
	 *
	 * @param GlAdjustmentPosting $posting The adjustment to post.
	 * @param array<string,mixed> $leg     transactionId, lineNumber, accountNumber and side.
	 *
	 * @return array<string,mixed> The GLLine body.
	 */
	private function legLine(GlAdjustmentPosting $posting, array $leg): array {
		return [
			'transactionId' => $leg['transactionId'],
			'administrationId' => $posting->administrationId,
			'lineNumber' => $leg['lineNumber'],
			'accountNumber' => $leg['accountNumber'],
			'side' => $leg['side'],
			'amount' => $posting->amount(),
			'currency' => 'EUR',
			'description' => $posting->description,
		];
	}//end legLine()

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

		// ADR-084: saveObject() is declared `: ObjectEntityInterface`, which
		// extends JsonSerializable — so the is_object()/method_exists() guards
		// that used to wrap this call could never be false, and neither the
		// getObject() fallback nor the trailing throw was reachable.
		// jsonSerialize() still returns mixed, so that check stays.
		$out = $saved->jsonSerialize();
		if (is_array($out) === true) {
			return $out;
		}

		return [];
	}//end saveOnSchema()

	/**
	 * Resolve the OR register slug, defaulting to 'shillinq'.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
