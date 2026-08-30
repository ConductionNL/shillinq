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

use OCA\Shillinq\AppInfo\Application;
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
		parent::__construct(appName: Application::APP_ID, request: $request);

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
	 * Assemble the full JSON envelope: columns (+ a synthetic TOTAAL column),
	 * the recursive row tree with every column's value pre-computed, and the
	 * computed-rows waterfall — the single payload the Vue component fetches
	 * once and toggles expand/collapse over with zero further requests.
	 *
	 * @param array<string,mixed> $grid The {@see BudgetGridReader::loadGrid()} bundle.
	 *
	 * @return array<string,mixed>
	 */
	private function buildEnvelope(array $grid): array {
		$columns = $grid['columns'];
		$rowTree = $grid['rowTree'];
		$bvaContext = $grid['bvaContext'];
		$budgetLinesByFiscalYear = $grid['budgetLinesByFiscalYear'];
		$accountTypeByNumber = $grid['accountTypeByNumber'];
		$accountByNumber = $grid['accountByNumber'];

		$rows = [];
		$rootValuesByColumn = [];
		foreach ($rowTree['rootIndexes'] as $rootIndex) {
			$row = $this->buildLedgerGroupRow(
				index: $rootIndex,
				rowTree: $rowTree,
				columns: $columns,
				bvaContext: $bvaContext,
				budgetLinesByFiscalYear: $budgetLinesByFiscalYear,
				accountTypeByNumber: $accountTypeByNumber,
				accountByNumber: $accountByNumber
			);
			$rows[] = $row;

			$code = $row['code'];
			if ($code !== '') {
				foreach ($row['cells'] as $columnKey => $cell) {
					$rootValuesByColumn[$columnKey]['budget'][$code] = $cell['budget'];
					$rootValuesByColumn[$columnKey]['actual'][$code] = $cell['actual'];
				}
			}
		}

		$computedRows = [];
		foreach (self::COMPUTED_ROWS as $definition) {
			$computedRows[] = ['code' => $definition['code'], 'label' => $definition['label'], 'cells' => []];
		}

		$columnKeys = array_map(static fn (array $c): string => $c['key'], $columns);
		$columnKeys[] = 'TOTAAL';

		foreach ($columnKeys as $columnKey) {
			$budgetByCode = ($rootValuesByColumn[$columnKey]['budget'] ?? []);
			$actualByCode = ($rootValuesByColumn[$columnKey]['actual'] ?? []);

			$budgetResults = $this->calculator->evaluateComputedRows(computedRows: self::COMPUTED_ROWS, rowValuesByCode: $budgetByCode);
			$actualResults = $this->calculator->evaluateComputedRows(computedRows: self::COMPUTED_ROWS, rowValuesByCode: $actualByCode);

			foreach (self::COMPUTED_ROWS as $rowIndex => $definition) {
				$code = $definition['code'];
				$budget = ($budgetResults[$code] ?? null);
				$actual = ($actualResults[$code] ?? null);
				$deviation = null;
				$favorable = null;
				if ($budget !== null && $actual !== null) {
					$direction = ($definition['favorableDirection'] ?? 'higher');
					$deviation = ($actual - $budget);
					if ($direction === 'lower') {
						$deviation = ($budget - $actual);
					}

					$favorable = ($deviation >= 0);
				}

				$computedRows[$rowIndex]['cells'][$columnKey] = [
					'budget' => $budget,
					'actual' => $actual,
					'deviation' => $deviation,
					'favorable' => $favorable,
				];
			}
		}

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

		return ['columns' => $columnDescriptors, 'rows' => $rows, 'computedRows' => $computedRows];

	}//end buildEnvelope()

	/**
	 * Recursively build one `LedgerGroup` row: its own per-column cells, plus
	 * either its child `LedgerGroup` rows or (leaf case) its resolved member
	 * `Account` rows (design.md §1b).
	 *
	 * @param integer $index The row's index in `$rowTree['entries']`.
	 * @param array<string,mixed> $rowTree The {@see BudgetGridReader::rowsFor()} bundle.
	 * @param list<array<string,mixed>> $columns The generated columns.
	 * @param array<string,mixed> $bvaContext The BudgetVsActualsReader context bundle.
	 * @param array<int,list<array<string,mixed>>|null> $budgetLinesByFiscalYear Per-fiscal-year BudgetLine slices.
	 * @param array<string,string> $accountTypeByNumber Account number => accountType.
	 * @param array<string,array<string,mixed>> $accountByNumber Account number => raw Account row.
	 *
	 * @return array<string,mixed>
	 */
	private function buildLedgerGroupRow(
		int $index,
		array $rowTree,
		array $columns,
		array $bvaContext,
		array $budgetLinesByFiscalYear,
		array $accountTypeByNumber,
		array $accountByNumber
	): array {
		$entry = $rowTree['entries'][$index];
		$key = $entry['slug'];
		if ($entry['id'] !== '') {
			$key = $entry['id'];
		}

		$cells = [];
		foreach ($columns as $column) {
			$cells[$column['key']] = $this->calculator->evaluateColumn(
				ledgerGroupKey: $key,
				column: $column,
				isPast: $column['isPast'],
				bvaContext: $bvaContext,
				budgetLinesByFiscalYear: $budgetLinesByFiscalYear,
				accountTypeByNumber: $accountTypeByNumber
			);
		}

		$cells['TOTAAL'] = $this->calculator->cumulative(
			ledgerGroupKey: $key,
			columns: $columns,
			bvaContext: $bvaContext,
			budgetLinesByFiscalYear: $budgetLinesByFiscalYear,
			accountTypeByNumber: $accountTypeByNumber
		);

		$childIndexes = ($rowTree['childrenByIndex'][$index] ?? []);
		$children = [];
		if ($childIndexes === []) {
			$children = $this->buildAccountLeafRows(
				ledgerGroupKey: $key,
				bvaContext: $bvaContext,
				columns: $columns,
				accountByNumber: $accountByNumber
			);
		}

		foreach ($childIndexes as $childIndex) {
			$children[] = $this->buildLedgerGroupRow(
				index: $childIndex,
				rowTree: $rowTree,
				columns: $columns,
				bvaContext: $bvaContext,
				budgetLinesByFiscalYear: $budgetLinesByFiscalYear,
				accountTypeByNumber: $accountTypeByNumber,
				accountByNumber: $accountByNumber
			);
		}

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
	 * Build the leaf `Account` rows for a `LedgerGroup` with no children —
	 * each carries only an `actual` per past column (budget is not resolved
	 * at Account granularity, `BudgetLine` is `LedgerGroup`-scoped) and a
	 * route to `ChartOfAccountsDetail` (REQ-BGV-007).
	 *
	 * @param string $ledgerGroupKey The leaf row's id or slug.
	 * @param array<string,mixed> $bvaContext The BudgetVsActualsReader context bundle.
	 * @param list<array<string,mixed>> $columns The generated columns.
	 * @param array<string,array<string,mixed>> $accountByNumber Account number => raw Account row.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function buildAccountLeafRows(string $ledgerGroupKey, array $bvaContext, array $columns, array $accountByNumber): array {
		$index = ($bvaContext['ledgerGroupKeyToIndex'][$ledgerGroupKey] ?? null);
		if ($index === null) {
			return [];
		}

		$memberNumbers = ($bvaContext['ledgerGroupEntries'][$index]['memberAccountNumbers'] ?? []);
		sort($memberNumbers);

		$rows = [];
		foreach ($memberNumbers as $accountNumber) {
			$account = ($accountByNumber[$accountNumber] ?? null);
			if ($account === null) {
				continue;
			}

			$accountId = (string)($account['@self']['id'] ?? $account['id'] ?? '');

			$cells = [];
			foreach ($columns as $column) {
				$actual = null;
				if ($column['isPast'] === true) {
					$actual = 0;
					foreach ($column['monthKeys'] as $monthKey) {
						$actual += (int)($bvaContext['actualsByAccountMonth'][$accountNumber][$monthKey] ?? 0);
					}
				}

				$cells[$column['key']] = ['actual' => $actual];
			}

			$totaalActual = 0;
			foreach ($columns as $column) {
				if ($column['isPast'] !== true) {
					continue;
				}

				foreach ($column['monthKeys'] as $monthKey) {
					$totaalActual += (int)($bvaContext['actualsByAccountMonth'][$accountNumber][$monthKey] ?? 0);
				}
			}

			$cells['TOTAAL'] = ['actual' => $totaalActual];

			$rows[] = [
				'id' => $accountId,
				'accountNumber' => $accountNumber,
				'label' => (string)($account['name'] ?? $accountNumber),
				'kind' => 'account',
				'route' => ('/chart-of-accounts/' . $accountId),
				'cells' => $cells,
			];
		}

		return $rows;

	}//end buildAccountLeafRows()
}//end class
