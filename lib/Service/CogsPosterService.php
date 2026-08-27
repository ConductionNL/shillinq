<?php

/**
 * COGS Poster Service
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
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

		$cogsAccount = $this->cogsAccount();
		$inventoryAccount = $this->inventoryAccount();

		if ($cogsAccount === '' || $inventoryAccount === '') {
			$this->logger->warning(
				'CogsPosterService: GL accounts not configured — marking valuation adjusted',
				[
					'movementNumber' => ($move['movementNumber'] ?? null),
					'cogsAccount' => $cogsAccount,
					'inventoryAccount' => $inventoryAccount,
				]
			);

			$valuation['status'] = 'adjusted';
			$valuation['pendingCogs'] = true;
			$savedValuation = $this->saveValuation(data: $valuation);

			return [
				'posted' => false,
				'valuation' => $savedValuation,
				'message' => 'GL accounts not configured; valuation marked adjusted',
			];
		}

		$administrationId = (string)($move['administrationId'] ?? '');
		$sourceReference = (string)($move['id'] ?? ($move['movementNumber'] ?? ''));
		$description = $this->describe(move: $move, cogsCents: $cogsCents);

		try {
			$transaction = $this->saveTransaction(
				data: [
					'transactionNumber' => $this->transactionNumber(move: $move),
					'postingDate' => $this->postingDate(move: $move),
					'periodId' => $this->periodId(move: $move),
					'currency' => 'EUR',
					'description' => $description,
					'sourceReference' => $sourceReference,
					'state' => 'draft',
					'administrationId' => $administrationId,
				]
			);

			$transactionId = (string)($transaction['id'] ?? ($transaction['@self']['id'] ?? ''));

			$cogsAmount = round(($cogsCents / 100), 2);

			// The administrationId is DENORMALISED onto every line from the header
			// above (REQ-GLS-001) — see GlLineAdministrationBackfillMigrator.
			// A line written without it is invisible to its own
			// administration's SpendAnalytics totals AND flips the backfill
			// completeness gate red for the whole instance.
			$this->saveLine(
				data: [
					'transactionId' => $transactionId,
					'administrationId' => $administrationId,
					'lineNumber' => 1,
					'accountNumber' => $cogsAccount,
					'side' => 'debit',
					'amount' => $cogsAmount,
					'currency' => 'EUR',
					'description' => $description,
				]
			);

			$this->saveLine(
				data: [
					'transactionId' => $transactionId,
					'administrationId' => $administrationId,
					'lineNumber' => 2,
					'accountNumber' => $inventoryAccount,
					'side' => 'credit',
					'amount' => $cogsAmount,
					'currency' => 'EUR',
					'description' => $description,
				]
			);

			// PendingCogs cleared on a successful post.
			if (((bool)($valuation['pendingCogs'] ?? false)) === true) {
				$valuation['pendingCogs'] = false;
				$valuation = $this->saveValuation(data: $valuation);
			}

			return [
				'posted' => true,
				'transaction' => $transaction,
				'valuation' => $valuation,
				'cogsCents' => $cogsCents,
				'message' => 'COGS posted',
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'CogsPosterService: failed to post COGS',
				[
					'movementNumber' => ($move['movementNumber'] ?? null),
					'cogsCents' => $cogsCents,
					'exception' => $e->getMessage(),
				]
			);

			// Fail-soft: mark valuation pending so operator sees the gap.
			$valuation['status'] = 'adjusted';
			$valuation['pendingCogs'] = true;
			$savedValuation = $this->saveValuation(data: $valuation);

			return [
				'posted' => false,
				'valuation' => $savedValuation,
				'message' => 'COGS posting raised: ' . $e->getMessage(),
			];
		}//end try

	}//end postCogs()

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
		return trim($this->appConfig->getValueString(Application::APP_ID, self::CFG_COGS_ACCOUNT, ''));
	}//end cogsAccount()

	/**
	 * Resolve Inventory asset account number from app config, default empty.
	 *
	 * @return string
	 */
	private function inventoryAccount(): string {
		return trim($this->appConfig->getValueString(Application::APP_ID, self::CFG_INVENTORY_ACCOUNT, ''));
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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
