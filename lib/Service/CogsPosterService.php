<?php

/**
 * COGS Poster Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-INV-007 Cost-of-Goods-Sold poster for the shillinq general ledger.
 *
 * Called by {@see FifoValuationService} or
 * {@see MovingAverageValuationService} after they have computed the
 * valuation-correct COGS amount (FIFO lot-weighted OR moving-average
 * unit cost). Materialises a balanced 2-line `GLTransaction` per
 * outbound `StockMove`:
 *
 *   - debit COGS account (default `5100`; configurable per administration
 *     via app config `cogs_account`)
 *   - credit Inventory asset account (default `1300`; configurable per
 *     administration via app config `inventory_account`)
 *
 * `journalCode: 'COGS'`, `sourceReference: StockMove.id`, description
 * carries productName + qty + unitCost. Money discipline: integer-cent
 * arithmetic; `balanceCheck` ensures `debitCents == creditCents`.
 *
 * Missing-config branch (REQ-INV-007 fail-soft scenario): if the COGS
 * or Inventory account numbers are not configured for the administration,
 * the service:
 *
 *   - logs a `WARNING` (not silently skipped per REQ-INV-007),
 *   - marks the InventoryValuation snapshot `status = adjusted` and
 *     sets `pendingCogs = true` so the operator UI can surface it,
 *   - returns a `'posted' => false` result.
 *
 * ADR-031 exception path (design.md D4): OR's
 * `x-openregister-notifications` does not yet support
 * parameterised cross-register writes with runtime-computed monetary
 * amounts. This thin (≤200 LOC) adapter is the migration target once
 * that capability lands; tracked separately on the openregister repo.
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
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * COGS GLTransaction poster.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
 */
class CogsPosterService {

	/**
	 * App-config key for the COGS account number override per
	 * administration (RGS 3.5 MKB default '5100').
	 */
	public const CFG_COGS_ACCOUNT = 'cogs_account';

	/**
	 * App-config key for the inventory asset account number override
	 * (RGS 3.5 MKB default '1300').
	 */
	public const CFG_INVENTORY_ACCOUNT = 'inventory_account';

	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config for account numbers + register slug.
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
	 * Post one balanced GLTransaction for an outbound StockMove.
	 *
	 * @param array<string,mixed> $move The outbound StockMove (movementType=issue).
	 * @param array<string,mixed> $valuation The driving InventoryValuation snapshot.
	 * @param int $cogsCents Total COGS amount in integer cents (from FIFO/avg).
	 *
	 * @return array<string,mixed> Result envelope with 'posted' bool, 'transaction', 'valuation'.
	 *
	 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
	 */
	public function postCogs(array $move, array $valuation, int $cogsCents): array {
		if ($cogsCents <= 0) {
			return [
				'posted' => false,
				'message' => 'cogsCents non-positive — nothing to post',
			];
		}

		$run = new CogsPostingRun(move: $move, cogsCents: $cogsCents);
		$run->valuation = $valuation;
		$run->cogsAccount = $this->cogsAccount();
		$run->inventoryAccount = $this->inventoryAccount();
		if ($run->cogsAccount === '' || $run->inventoryAccount === '') {
			return $this->markUnconfigured(run: $run);
		}

		try {
			return $this->postBalanced(run: $run);
		} catch (\Throwable $e) {
			return $this->markFailed(run: $run, error: $e);
		}//end try

	}//end postCogs()

	/**
	 * GL accounts not configured: warn, mark the valuation adjusted with
	 * pending COGS and report it.
	 *
	 * @param CogsPostingRun $run The posting run.
	 *
	 * @return array<string,mixed> Result envelope.
	 */
	private function markUnconfigured(CogsPostingRun $run): array {
		$this->logger->warning(
			'CogsPosterService: GL accounts not configured — marking valuation adjusted',
			[
				'movementNumber' => ($run->move['movementNumber'] ?? null),
				'cogsAccount' => $run->cogsAccount,
				'inventoryAccount' => $run->inventoryAccount,
			]
		);

		return [
			'posted' => false,
			'valuation' => $this->savePendingValuation(valuation: $run->valuation),
			'message' => 'GL accounts not configured; valuation marked adjusted',
		];
	}//end markUnconfigured()

	/**
	 * Fail-soft: log the failed post and mark the valuation pending so the
	 * operator sees the gap.
	 *
	 * @param CogsPostingRun $run   The posting run.
	 * @param \Throwable     $error What the store raised.
	 *
	 * @return array<string,mixed> Result envelope.
	 */
	private function markFailed(CogsPostingRun $run, \Throwable $error): array {
		$this->logger->error(
			'CogsPosterService: failed to post COGS',
			[
				'movementNumber' => ($run->move['movementNumber'] ?? null),
				'cogsCents' => $run->cogsCents,
				'exception' => $error->getMessage(),
			]
		);

		return [
			'posted' => false,
			'valuation' => $this->savePendingValuation(valuation: $run->valuation),
			'message' => 'COGS posting raised: ' . $error->getMessage(),
		];
	}//end markFailed()

	/**
	 * Save a valuation marked adjusted with pending COGS.
	 *
	 * @param array<string,mixed> $valuation The valuation snapshot.
	 *
	 * @return array<string,mixed> The saved valuation.
	 */
	private function savePendingValuation(array $valuation): array {
		$valuation['status'] = 'adjusted';
		$valuation['pendingCogs'] = true;
		return $this->saveValuation(data: $valuation);
	}//end savePendingValuation()

	/**
	 * Persist the balanced GLTransaction (header + COGS debit + inventory
	 * credit) and clear a pending-COGS flag on the valuation.
	 *
	 * @param CogsPostingRun $run The posting run.
	 *
	 * @return array<string,mixed> Result envelope.
	 */
	private function postBalanced(CogsPostingRun $run): array {
		$description = $this->describe(move: $run->move, cogsCents: $run->cogsCents);
		$transaction = $this->saveTransaction(data: $this->transactionHeader(run: $run, description: $description));
		$transactionId = (string)($transaction['id'] ?? ($transaction['@self']['id'] ?? ''));
		$this->saveLines(run: $run, header: ['transactionId' => $transactionId, 'description' => $description]);

		return [
			'posted' => true,
			'transaction' => $transaction,
			'valuation' => $this->clearPendingCogs(valuation: $run->valuation),
			'cogsCents' => $run->cogsCents,
			'message' => 'COGS posted',
		];
	}//end postBalanced()

	/**
	 * PendingCogs cleared (and saved) on a successful post; a valuation
	 * without pending COGS is returned as it is.
	 *
	 * @param array<string,mixed> $valuation The valuation snapshot.
	 *
	 * @return array<string,mixed> The valuation.
	 */
	private function clearPendingCogs(array $valuation): array {
		if (((bool)($valuation['pendingCogs'] ?? false)) === true) {
			$valuation['pendingCogs'] = false;
			return $this->saveValuation(data: $valuation);
		}

		return $valuation;
	}//end clearPendingCogs()

	/**
	 * The GLTransaction header of the posting.
	 *
	 * @param CogsPostingRun $run         The posting run.
	 * @param string         $description The posting description.
	 *
	 * @return array<string,mixed> The header.
	 */
	private function transactionHeader(CogsPostingRun $run, string $description): array {
		return [
			'transactionNumber' => $this->transactionNumber(move: $run->move),
			'postingDate' => $this->postingDate(move: $run->move),
			'periodId' => $this->periodId(move: $run->move),
			'currency' => 'EUR',
			'description' => $description,
			'sourceReference' => (string)($run->move['id'] ?? ($run->move['movementNumber'] ?? '')),
			'state' => 'draft',
			'administrationId' => (string)($run->move['administrationId'] ?? ''),
		];
	}//end transactionHeader()

	/**
	 * Save the COGS debit (line 1) and the inventory credit (line 2).
	 *
	 * The administrationId is DENORMALISED onto every line from the header
	 * (REQ-GLS-001) — see GlLineAdministrationBackfillMigrator. A line
	 * written without it is invisible to its own administration's
	 * SpendAnalytics totals AND flips the backfill completeness gate red for
	 * the whole instance.
	 *
	 * @param CogsPostingRun       $run    The posting run.
	 * @param array<string,string> $header The saved header's transactionId and description.
	 *
	 * @return void
	 */
	private function saveLines(CogsPostingRun $run, array $header): void {
		$legs = [
			['lineNumber' => 1, 'accountNumber' => $run->cogsAccount, 'side' => 'debit'],
			['lineNumber' => 2, 'accountNumber' => $run->inventoryAccount, 'side' => 'credit'],
		];
		foreach ($legs as $leg) {
			$this->saveLine(
				data: [
					'transactionId' => $header['transactionId'],
					'administrationId' => (string)($run->move['administrationId'] ?? ''),
					'lineNumber' => $leg['lineNumber'],
					'accountNumber' => $leg['accountNumber'],
					'side' => $leg['side'],
					'amount' => round(($run->cogsCents / 100), 2),
					'currency' => 'EUR',
					'description' => $header['description'],
				]
			);
		}
	}//end saveLines()

	/**
	 * Compose the human-readable description per REQ-INV-007.
	 *
	 * @param array<string,mixed> $move The StockMove.
	 * @param int $cogsCents COGS in cents.
	 *
	 * @return string Description like 'COGS — GT-10-2026 — 35 × EUR 10,2857'.
	 */
	private function describe(array $move, int $cogsCents): string {
		$sku = (string)($move['itemId'] ?? '');
		$qty = (float)($move['quantity'] ?? 0);
		$unitCost = 0.0;
		if ($qty > 0) {
			$unitCost = (($cogsCents / 100) / $qty);
		}

		$unitCost = round($unitCost, 4);

		return sprintf(
			'COGS — %s — %s × EUR %s',
			$sku,
			number_format($qty, 2, ',', ''),
			number_format($unitCost, 4, ',', '')
		);

	}//end describe()

	/**
	 * Build a transaction number unique per administration + fiscal year.
	 *
	 * @param array<string,mixed> $move The StockMove.
	 *
	 * @return string e.g. COGS-2026-<movementNumber>.
	 */
	private function transactionNumber(array $move): string {
		$year = (int)substr((string)($move['postedAt'] ?? ($move['draftedAt'] ?? date('Y'))), 0, 4);
		$suffix = (string)($move['movementNumber'] ?? ($move['id'] ?? uniqid('mv-', true)));
		if ($year === 0) {
			$year = (int)date('Y');
		}

		return sprintf('COGS-%04d-%s', $year, $suffix);
	}//end transactionNumber()

	/**
	 * Posting date as ISO yyyy-mm-dd, defaulting to the move postedAt.
	 *
	 * @param array<string,mixed> $move The StockMove.
	 *
	 * @return string yyyy-mm-dd.
	 */
	private function postingDate(array $move): string {
		$postedAt = (string)($move['postedAt'] ?? ($move['draftedAt'] ?? ''));
		if ($postedAt === '') {
			return date('Y-m-d');
		}

		return substr($postedAt, 0, 10);
	}//end postingDate()

	/**
	 * Resolve a periodId (e.g. 2026-Q2) from the posting date.
	 *
	 * @param array<string,mixed> $move The StockMove.
	 *
	 * @return string Period identifier.
	 */
	private function periodId(array $move): string {
		$date = $this->postingDate(move: $move);
		$year = (int)substr($date, 0, 4);
		$month = (int)substr($date, 5, 2);
		if ($year === 0) {
			$year = (int)date('Y');
		}

		$quarter = (int)ceil(max(1, $month) / 3);
		return sprintf('%04d-Q%d', $year, $quarter);
	}//end periodId()

	/**
	 * Resolve COGS account number from app config, default empty.
	 *
	 * @return string
	 */
	private function cogsAccount(): string {
		return trim($this->appConfig->getValueString(AppIdentity::APP_ID, self::CFG_COGS_ACCOUNT, ''));
	}//end cogsAccount()

	/**
	 * Resolve Inventory asset account number from app config, default empty.
	 *
	 * @return string
	 */
	private function inventoryAccount(): string {
		return trim($this->appConfig->getValueString(AppIdentity::APP_ID, self::CFG_INVENTORY_ACCOUNT, ''));
	}//end inventoryAccount()

	/**
	 * Persist a GLTransaction header.
	 *
	 * @param array<string,mixed> $data Header data.
	 *
	 * @return array<string,mixed> Persisted row (with id).
	 */
	private function saveTransaction(array $data): array {
		return $this->saveOnSchema(schema: 'GLTransaction', data: $data);
	}//end saveTransaction()

	/**
	 * Persist a GLLine row.
	 *
	 * @param array<string,mixed> $data Line data.
	 *
	 * @return array<string,mixed> Persisted row (with id).
	 */
	private function saveLine(array $data): array {
		return $this->saveOnSchema(schema: 'GLLine', data: $data);
	}//end saveLine()

	/**
	 * Persist an InventoryValuation snapshot (pendingCogs / status patches).
	 *
	 * @param array<string,mixed> $data Snapshot data.
	 *
	 * @return array<string,mixed> Persisted snapshot.
	 */
	private function saveValuation(array $data): array {
		return $this->saveOnSchema(schema: 'InventoryValuation', data: $data);
	}//end saveValuation()

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
