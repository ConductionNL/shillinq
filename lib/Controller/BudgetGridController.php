<?php

/**
 * Budget Grid Controller
 *
 * REST surface for the begroting grid (`budget-grid-view`, REQ-BGV-001..009):
 * `GET /api/budget-grid` returns the fully pre-computed row tree + column
 * set + computed-row waterfall for one render, in ONE request — the grid's
 * own `design.md` §1c requirement that expanding/collapsing a row costs
 * zero further network requests means every child row (LedgerGroup or
 * resolved Account) and every column's value must already be in this single
 * response.
 *
 * ## Authorisation posture
 *
 * `#[NoAdminRequired]` — reading a begroting is a controller/bookkeeper
 * capability, not an admin one. `administrationId` is caller-supplied (this
 * app has no persisted server-side "active administration" session slot —
 * every multi-administration page, e.g. `BBVComplianceDashboard`, resolves
 * it the same way: `fetchAdministrationContext()` on the client, then passes
 * `activeAdministrationId` back as a request param) and is validated against
 * {@see AdministrationContextService::canAccess()} before any read — a
 * non-member's request is masked as 404, never 403 (REQ-MA-001, the
 * `ThreeWayMatchExceptionController`/`RequisitionController` precedent).
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BudgetGridCalculator;
use OCA\Shillinq\Service\BudgetGridReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only JSON endpoint for the begroting grid.
 *
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
 */
class BudgetGridController extends Controller {

	/**
	 * Identifier-safe pattern for `administrationId` (ADR-005).
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Allowed `granularity` values.
	 *
	 * @var array<string,bool>
	 */
	private const GRANULARITIES = ['month' => true, 'quarter' => true, 'year' => true];

	/**
	 * The waterfall of subtotal/derived rows (design.md §4 / REQ-BGV-008),
	 * matching `rj270-pl.json`'s `SOM-OPB → SOM-KOS → BEDR-RES → FIN-RES →
	 * RES-VBB → NET-RES` chain, resolved against `budget-core-schema`'s
	 * amended seed's real root `LedgerGroup` `code`s. Page-config, not a
	 * schema field — kept here (rather than only in the manifest) so the
	 * calculator can evaluate it server-side without the client re-deriving
	 * the waterfall; the manifest's own `computedRows` block (task group 6)
	 * is the declarative source of truth this mirrors.
	 *
	 * @var list<array{code:string,label:string,formula:string,favorableDirection?:string,asPercent?:bool}>
	 */
	private const COMPUTED_ROWS = [
		['code' => 'bruto-marge', 'label' => 'Bruto Marge', 'formula' => 'omzet - kostprijs-van-de-omzet', 'favorableDirection' => 'higher'],
		[
			'code' => 'kosten',
			'label' => 'Kosten',
			'formula' => 'personeel + huisvesting + afschrijvingen-op-vaste-activa + exploitatie-en-machinekosten + verkoopkosten + algemene-kosten',
			'favorableDirection' => 'lower',
		],
		['code' => 'bedrijfsresultaat', 'label' => 'Bedrijfsresultaat', 'formula' => 'bruto-marge - kosten', 'favorableDirection' => 'higher'],
		['code' => 'financieel-resultaat', 'label' => 'Financieel resultaat', 'formula' => 'rentebaten - rentelasten', 'favorableDirection' => 'higher'],
		[
			'code' => 'resultaat-voor-belastingen',
			'label' => 'Resultaat voor belastingen',
			'formula' => 'bedrijfsresultaat + financieel-resultaat',
			'favorableDirection' => 'higher',
		],
		[
			'code' => 'nettoresultaat',
			'label' => 'Nettoresultaat',
			'formula' => 'resultaat-voor-belastingen - vennootschapsbelasting',
			'favorableDirection' => 'higher',
		],
		['code' => 'nettoresultaat-pct', 'label' => '% van omzet', 'formula' => 'nettoresultaat / omzet', 'asPercent' => true],
	];

	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request Request.
	 * @param IL10N $l10n Localized strings for client-facing error messages (ADR-050).
	 * @param LoggerInterface $logger Logger — never receives a record body.
	 * @param BudgetGridReader $reader Resolves rows/columns/past-boundary.
	 * @param BudgetGridCalculator $calculator Pure arithmetic over the reader's bundle.
	 * @param AdministrationContextService $administrationContext IDOR guard (REQ-MA-001).
	 * @param IUserSession $userSession Session.
	 */
	public function __construct(
		IRequest $request,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly BudgetGridReader $reader,
		private readonly BudgetGridCalculator $calculator,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * GET /api/budget-grid?administrationId=...&startPeriod=YYYY-MM&endPeriod=YYYY-MM&granularity=month|quarter|year
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-001
	 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
	 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-003
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => $this->l10n->t('Not logged in')], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		if ($administrationId === '' || preg_match(self::ID_PATTERN, $administrationId) !== 1) {
			return new JSONResponse(['error' => $this->l10n->t('administrationId is required')], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Administration not found')], Http::STATUS_NOT_FOUND);
		}

		$granularity = (string)$this->request->getParam('granularity', 'month');
		if ((self::GRANULARITIES[$granularity] ?? false) === false) {
			return new JSONResponse(['error' => $this->l10n->t('granularity must be month, quarter, or year')], Http::STATUS_BAD_REQUEST);
		}

		$startPeriod = (string)$this->request->getParam('startPeriod', '');
		$endPeriod = (string)$this->request->getParam('endPeriod', '');
		if (preg_match('/^\d{4}-\d{2}$/', $startPeriod) !== 1 || preg_match('/^\d{4}-\d{2}$/', $endPeriod) !== 1) {
			return new JSONResponse(['error' => $this->l10n->t('startPeriod and endPeriod must be YYYY-MM')], Http::STATUS_BAD_REQUEST);
		}

		try {
			$grid = $this->reader->loadGrid(
				administrationId: $administrationId,
				startPeriod: $startPeriod,
				endPeriod: $endPeriod,
				granularity: $granularity
			);
		} catch (Throwable $e) {
			$this->logger->error('BudgetGridController: failed to load grid', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => $this->l10n->t('Could not load the begroting grid')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($this->buildEnvelope(grid: $grid));

	}//end index()

	/**
	 * Build the response envelope: column descriptors, the ledger-group rows
	 * and the computed (waterfall) rows.
	 *
	 * @param array<string,mixed> $grid The grid BudgetGridReader::loadGrid() returns.
	 *
	 * @return array{columns: array<int,array<string,mixed>>, rows: array<int,array<string,mixed>>, computedRows: array<int,array<string,mixed>>}
	 */
	private function buildEnvelope(array $grid): array {
		$run = new BudgetGridRun(grid: $grid);

		$rows = [];
		foreach ($run->rowTree['rootIndexes'] as $rootIndex) {
			$rows[] = $this->buildLedgerGroupRow(run: $run, index: $rootIndex);
		}

		$computedRows = $this->computedRows(columns: $run->columns, rootValuesByColumn: self::rootValuesByColumn(rows: $rows));

		return ['columns' => self::columnDescriptors(columns: $run->columns), 'rows' => $rows, 'computedRows' => $computedRows];

	}//end buildEnvelope()

	/**
	 * Per column, the budget and actual of every root row with a code.
	 *
	 * @param array<int,array<string,mixed>> $rows The root ledger-group rows.
	 *
	 * @return array<string,array{budget: array<string,mixed>, actual: array<string,mixed>}>
	 */
	private static function rootValuesByColumn(array $rows): array {
		$rootValuesByColumn = [];
		foreach ($rows as $row) {
			$code = $row['code'];
			if ($code === '') {
				continue;
			}

			foreach ($row['cells'] as $columnKey => $cell) {
				$rootValuesByColumn[$columnKey]['budget'][$code] = $cell['budget'];
				$rootValuesByColumn[$columnKey]['actual'][$code] = $cell['actual'];
			}
		}

		return $rootValuesByColumn;

	}//end rootValuesByColumn()

	/**
	 * The computed rows, with a cell per column and for TOTAAL.
	 *
	 * @param array<int,array<string,mixed>> $columns The period columns.
	 * @param array<string,array<string,array<string,mixed>>> $rootValuesByColumn Root values per column.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function computedRows(array $columns, array $rootValuesByColumn): array {
		$computedRows = [];
		foreach (self::COMPUTED_ROWS as $definition) {
			$computedRows[] = ['code' => $definition['code'], 'label' => $definition['label'], 'cells' => []];
		}

		foreach (self::columnKeys(columns: $columns) as $columnKey) {
			$cells = $this->computedCells(values: ($rootValuesByColumn[$columnKey] ?? []));
			foreach ($cells as $rowIndex => $cell) {
				$computedRows[$rowIndex]['cells'][$columnKey] = $cell;
			}
		}

		return $computedRows;

	}//end computedRows()

	/**
	 * The column keys and TOTAAL.
	 *
	 * @param array<int,array<string,mixed>> $columns The period columns.
	 *
	 * @return array<int,string>
	 */
	private static function columnKeys(array $columns): array {
		$columnKeys = array_map(static fn (array $c): string => $c['key'], $columns);
		$columnKeys[] = 'TOTAAL';
		return $columnKeys;

	}//end columnKeys()

	/**
	 * The computed-row cells of one column, by computed-row index.
	 *
	 * @param array<string,array<string,mixed>> $values The column's root budget and actual values by code.
	 *
	 * @return array<int,array{budget: mixed, actual: mixed, deviation: mixed, favorable: ?bool}>
	 */
	private function computedCells(array $values): array {
		$budgetResults = $this->calculator->evaluateComputedRows(computedRows: self::COMPUTED_ROWS, rowValuesByCode: ($values['budget'] ?? []));
		$actualResults = $this->calculator->evaluateComputedRows(computedRows: self::COMPUTED_ROWS, rowValuesByCode: ($values['actual'] ?? []));

		$cells = [];
		foreach (self::COMPUTED_ROWS as $rowIndex => $definition) {
			$code = $definition['code'];
			$cells[$rowIndex] = self::deviationCell(
				definition: $definition,
				cell: ['budget' => ($budgetResults[$code] ?? null), 'actual' => ($actualResults[$code] ?? null)]
			);
		}

		return $cells;

	}//end computedCells()

	/**
	 * A computed cell with its deviation and whether it is favorable; both
	 * null unless budget and actual are known.
	 *
	 * @param array<string,mixed> $definition The computed-row definition.
	 * @param array{budget: mixed, actual: mixed} $cell The budget and actual.
	 *
	 * @return array{budget: mixed, actual: mixed, deviation: mixed, favorable: ?bool}
	 */
	private static function deviationCell(array $definition, array $cell): array {
		$budget = $cell['budget'];
		$actual = $cell['actual'];
		$deviation = null;
		$favorable = null;
		if ($budget !== null && $actual !== null) {
			$deviation = ($actual - $budget);
			if (($definition['favorableDirection'] ?? 'higher') === 'lower') {
				$deviation = ($budget - $actual);
			}

			$favorable = ($deviation >= 0);
		}

		return ['budget' => $budget, 'actual' => $actual, 'deviation' => $deviation, 'favorable' => $favorable];

	}//end deviationCell()

	/**
	 * The column descriptors and the synthetic TOTAAL column.
	 *
	 * @param array<int,array<string,mixed>> $columns The period columns.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function columnDescriptors(array $columns): array {
		$columnDescriptors = [];
		foreach ($columns as $column) {
			$columnDescriptors[] = [
				'key' => $column['key'],
				'label' => $column['label'],
				'granularity' => $column['granularity'],
				'isPast' => $column['isPast'],
				'isTotal' => false,
			];
		}

		$columnDescriptors[] = ['key' => 'TOTAAL', 'label' => 'TOTAAL', 'granularity' => 'total', 'isPast' => false, 'isTotal' => true];

		return $columnDescriptors;

	}//end columnDescriptors()

	/**
	 * A ledger-group row: its cells and its children, which are its child
	 * groups or, for a leaf group, its member accounts.
	 *
	 * @param BudgetGridRun $run The grid.
	 * @param int $index The group's index in the row tree.
	 *
	 * @return array<string,mixed>
	 */
	private function buildLedgerGroupRow(BudgetGridRun $run, int $index): array {
		$entry = $run->rowTree['entries'][$index];
		$key = self::ledgerGroupKey(entry: $entry);
		$cells = $this->groupCells(run: $run, ledgerGroupKey: $key);
		$children = $this->groupChildren(run: $run, index: $index);

		return [
			'id' => $key,
			'code' => $entry['code'],
			'label' => $entry['name'],
			'kind' => 'ledgerGroup',
			'hasChildren' => ($children !== []),
			'cells' => $cells,
			'children' => $children,
		];

	}//end buildLedgerGroupRow()

	/**
	 * A ledger group's key: its id, else its slug.
	 *
	 * @param array<string,mixed> $entry The row-tree entry.
	 *
	 * @return string
	 */
	private static function ledgerGroupKey(array $entry): string {
		if ($entry['id'] !== '') {
			return $entry['id'];
		}

		return $entry['slug'];

	}//end ledgerGroupKey()

	/**
	 * A ledger group's cell per column and its cumulative TOTAAL cell.
	 *
	 * @param BudgetGridRun $run The grid.
	 * @param string $ledgerGroupKey The group's key.
	 *
	 * @return array<string,mixed>
	 */
	private function groupCells(BudgetGridRun $run, string $ledgerGroupKey): array {
		$cells = [];
		foreach ($run->columns as $column) {
			$cells[$column['key']] = $this->calculator->evaluateColumn(
				ledgerGroupKey: $ledgerGroupKey,
				column: $column,
				isPast: $column['isPast'],
				bvaContext: $run->bvaContext,
				budgetLinesByFiscalYear: $run->budgetLinesByFiscalYear,
				accountTypeByNumber: $run->accountTypeByNumber
			);
		}

		$cells['TOTAAL'] = $this->totalCell(run: $run, ledgerGroupKey: $ledgerGroupKey);
		return $cells;

	}//end groupCells()

	/**
	 * A ledger group's cumulative cell over all columns.
	 *
	 * @param BudgetGridRun $run The grid.
	 * @param string $ledgerGroupKey The group's key.
	 *
	 * @return mixed
	 */
	private function totalCell(BudgetGridRun $run, string $ledgerGroupKey): mixed {
		return $this->calculator->cumulative(
			ledgerGroupKey: $ledgerGroupKey,
			columns: $run->columns,
			bvaContext: $run->bvaContext,
			budgetLinesByFiscalYear: $run->budgetLinesByFiscalYear,
			accountTypeByNumber: $run->accountTypeByNumber
		);

	}//end totalCell()

	/**
	 * The child group rows, or for a group without child groups its account rows.
	 *
	 * @param BudgetGridRun $run The grid.
	 * @param int $index The group's index in the row tree.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function groupChildren(BudgetGridRun $run, int $index): array {
		$childIndexes = ($run->rowTree['childrenByIndex'][$index] ?? []);
		if ($childIndexes === []) {
			return $this->buildAccountLeafRows(run: $run, ledgerGroupKey: self::ledgerGroupKey(entry: $run->rowTree['entries'][$index]));
		}

		$children = [];
		foreach ($childIndexes as $childIndex) {
			$children[] = $this->buildLedgerGroupRow(run: $run, index: $childIndex);
		}

		return $children;

	}//end groupChildren()

	/**
	 * The account rows of a leaf ledger group, by account number; members
	 * without an account are skipped.
	 *
	 * @param BudgetGridRun $run The grid.
	 * @param string $ledgerGroupKey The group's key.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function buildAccountLeafRows(BudgetGridRun $run, string $ledgerGroupKey): array {
		$index = ($run->bvaContext['ledgerGroupKeyToIndex'][$ledgerGroupKey] ?? null);
		if ($index === null) {
			return [];
		}

		$memberNumbers = ($run->bvaContext['ledgerGroupEntries'][$index]['memberAccountNumbers'] ?? []);
		sort($memberNumbers);

		$rows = [];
		foreach ($memberNumbers as $accountNumber) {
			if (isset($run->accountByNumber[$accountNumber]) === true) {
				$rows[] = self::accountRow(run: $run, accountNumber: $accountNumber);
			}
		}

		return $rows;

	}//end buildAccountLeafRows()

	/**
	 * An account row with its actual per past column and in total.
	 *
	 * @param BudgetGridRun $run The grid.
	 * @param int|string $accountNumber The account number.
	 *
	 * @return array<string,mixed>
	 */
	private static function accountRow(BudgetGridRun $run, int|string $accountNumber): array {
		$account = $run->accountByNumber[$accountNumber];
		$accountId = (string)($account['@self']['id'] ?? $account['id'] ?? '');

		return [
			'id' => $accountId,
			'accountNumber' => $accountNumber,
			'label' => (string)($account['name'] ?? $accountNumber),
			'kind' => 'account',
			'route' => ('/chart-of-accounts/' . $accountId),
			'cells' => self::accountCells(columns: $run->columns, actuals: ($run->bvaContext['actualsByAccountMonth'][$accountNumber] ?? [])),
		];

	}//end accountRow()

	/**
	 * An account's actual per column (null for a future column) and TOTAAL,
	 * the sum over the past columns.
	 *
	 * @param array<int,array<string,mixed>> $columns The period columns.
	 * @param mixed $actuals The account's actuals by month.
	 *
	 * @return array<string,array{actual: ?int}>
	 */
	private static function accountCells(array $columns, mixed $actuals): array {
		$cells = [];
		$total = 0;
		foreach ($columns as $column) {
			$actual = null;
			if ($column['isPast'] === true) {
				$actual = self::actualOver(actuals: $actuals, monthKeys: $column['monthKeys']);
				$total += $actual;
			}

			$cells[$column['key']] = ['actual' => $actual];
		}

		$cells['TOTAAL'] = ['actual' => $total];
		return $cells;

	}//end accountCells()

	/**
	 * The sum of an account's actuals over the months.
	 *
	 * @param mixed $actuals The account's actuals by month.
	 * @param array<int,string> $monthKeys The months.
	 *
	 * @return int
	 */
	private static function actualOver(mixed $actuals, array $monthKeys): int {
		$sum = 0;
		foreach ($monthKeys as $monthKey) {
			$sum += (int)($actuals[$monthKey] ?? 0);
		}

		return $sum;

	}//end actualOver()
}//end class
