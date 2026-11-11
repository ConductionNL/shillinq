<?php

/**
 * Chart Series Query
 *
 * The administration, month range and optional annual budget of a chart
 * series request as read from the request.
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
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * The trimmed chart-series parameters and their shape checks.
 *
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
 */
final class ChartSeriesQuery {
	/**
	 * Accepted shape for `from`/`to`: a `YYYY-MM` fiscal month.
	 *
	 * @var string
	 */
	private const MONTH_PATTERN = '/^\d{4}-\d{2}$/';

	/**
	 * Accepted shape for an OpenRegister object identifier (id, UUID or slug).
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\-]{1,64}$/';

	/**
	 * The administration id, '' when not given.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The first month, '' when not given.
	 *
	 * @var string
	 */
	public readonly string $from;

	/**
	 * The last month, '' when not given.
	 *
	 * @var string
	 */
	public readonly string $to;

	/**
	 * The explicit AnnualBudget override, '' when not given.
	 *
	 * @var string
	 */
	public readonly string $annualBudgetId;

	/**
	 * Read the parameters from the request.
	 *
	 * @param IRequest $request The request.
	 */
	public function __construct(IRequest $request) {
		$this->administrationId = trim((string)$request->getParam('administrationId', ''));
		$this->from = trim((string)$request->getParam('from', ''));
		$this->to = trim((string)$request->getParam('to', ''));
		$this->annualBudgetId = trim((string)$request->getParam('annualBudgetId', ''));

	}//end __construct()

	/**
	 * Whether the administration id is given and well-formed.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
	 */
	public function administrationValid(): bool {
		return $this->administrationId !== '' && preg_match(self::ID_PATTERN, $this->administrationId) === 1;
	}//end administrationValid()

	/**
	 * Whether both bounds are `YYYY-MM` months.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
	 */
	public function monthsValid(): bool {
		return preg_match(self::MONTH_PATTERN, $this->from) === 1 && preg_match(self::MONTH_PATTERN, $this->to) === 1;
	}//end monthsValid()

	/**
	 * Whether the annual budget override is absent or well-formed.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
	 */
	public function budgetValid(): bool {
		return $this->annualBudgetId === '' || preg_match(self::ID_PATTERN, $this->annualBudgetId) === 1;
	}//end budgetValid()

	/**
	 * The annual budget override for the service: null when not given.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
	 */
	public function annualBudget(): ?string {
		if ($this->annualBudgetId === '') {
			return null;
		}

		return $this->annualBudgetId;
	}//end annualBudget()
}//end class
