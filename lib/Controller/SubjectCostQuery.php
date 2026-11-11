<?php

/**
 * Subject Cost Query
 *
 * The subject, costing period and optional administration one subject-cost
 * request asks for.
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
 * @spec openspec/specs/subject-cost-aggregation/spec.md#requirement-a-subject-cost-is-reachable-over-http
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * The query parameters of a subject-cost request, trimmed.
 *
 * @spec openspec/specs/subject-cost-aggregation/spec.md#requirement-a-subject-cost-is-reachable-over-http
 */
final class SubjectCostQuery {
	/**
	 * Accepted shape for the costing period: `YYYY-MM`.
	 *
	 * @var string
	 */
	private const PERIOD_PATTERN = '/^\d{4}-\d{2}$/';

	/**
	 * The owning app of the subject.
	 *
	 * @var string
	 */
	public readonly string $subjectApp;

	/**
	 * The domain object id.
	 *
	 * @var string
	 */
	public readonly string $subjectId;

	/**
	 * The costing period, '' for all.
	 *
	 * @var string
	 */
	public readonly string $period;

	/**
	 * The named administration, '' for every reachable one.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * Read the query parameters.
	 *
	 * @param IRequest $request The request.
	 */
	public function __construct(IRequest $request) {
		$this->subjectApp = trim((string)$request->getParam('subjectApp', ''));
		$this->subjectId = trim((string)$request->getParam('subjectId', ''));
		$this->period = trim((string)$request->getParam('period', ''));
		$this->administrationId = trim((string)$request->getParam('administrationId', ''));

	}//end __construct()

	/**
	 * Whether both subjectApp and subjectId are given.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md#requirement-a-subject-cost-is-reachable-over-http
	 */
	public function hasSubject(): bool {
		return $this->subjectApp !== '' && $this->subjectId !== '';
	}//end hasSubject()

	/**
	 * Whether the period is absent or `YYYY-MM`.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md#requirement-a-subject-cost-is-reachable-over-http
	 */
	public function periodWellFormed(): bool {
		return $this->period === '' || preg_match(self::PERIOD_PATTERN, $this->period) === 1;
	}//end periodWellFormed()
}//end class
