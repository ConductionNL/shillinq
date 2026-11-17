<?php

/**
 * Unit tests for SubjectCostReportController.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-same-case-cost-source-is-grouped-per-case-type-per-period-req-iv3-012
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\SubjectCostReportController;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Reporting\CostSource\ClassifiedSubjectCostSource;
use OCA\Shillinq\Reporting\CostSource\ReportPeriod;
use OCA\Shillinq\Reporting\CostSource\SubjectCostGrouping;
use OCA\Shillinq\Reporting\CostSource\SubjectCostReport;
use OCA\Shillinq\Reporting\CostSource\SubjectCostSourceConfiguration;
use OCA\Shillinq\Reporting\CostSource\TaskFieldRollup;
use OCA\Shillinq\Service\SubjectCostAggregator;
use OCA\Shillinq\Service\SubjectCostService;
use OCA\Shillinq\Support\FleetAppId;
use OCA\Shillinq\Tests\Unit\Reporting\CostSource\CostSourceWorld;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The rows of the two case cost tables: per type and per taakveld, the
 * named missing app with no figure, and the access and input refusals.
 */
#[CoversClass(SubjectCostReportController::class)]
#[CoversClass(SubjectCostReport::class)]
#[UsesClass(SubjectCostGrouping::class)]
#[UsesClass(ClassifiedSubjectCostSource::class)]
#[UsesClass(TaskFieldRollup::class)]
#[UsesClass(SubjectCostSourceConfiguration::class)]
#[UsesClass(ReportPeriod::class)]
#[UsesClass(SubjectCostService::class)]
#[UsesClass(SubjectCostAggregator::class)]
#[UsesClass(FleetAppId::class)]
#[UsesClass(ObjectIdentifier::class)]
final class SubjectCostReportControllerTest extends TestCase {

	/**
	 * The world of the per-type scenario.
	 *
	 * @var CostSourceWorld
	 */
	private CostSourceWorld $world;

	/**
	 * Whether the caller may read the administration.
	 *
	 * @var boolean
	 */
	private bool $canAccess = true;

	/**
	 * The administrations canAccess() was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * Two Omgevingsvergunning cases, a costed and an unpriced Bezwaar case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->world = new CostSourceWorld($this);
		$this->world->rates = ['ann' => 10000, 'bob' => 5000];
		$this->world->caseType('t-vth', 'Omgevingsvergunning', '8.3');
		$this->world->caseType('t-bzw', 'Bezwaar', null);
		$this->world->caseWithHours('c1', 't-vth', [['ann', 10.0, '2026-07-01']]);
		$this->world->caseWithHours('c2', 't-vth', [['ann', 2.5, '2026-08-01']]);
		$this->world->caseWithHours('c3', 't-bzw', [['bob', 8.0, '2026-09-01']]);
		$this->world->caseWithHours('c4', 't-bzw', [['nobody', 2.0, '2026-09-02']]);
	}//end setUp()

	/**
	 * The controller for these request parameters.
	 *
	 * @param array<string, string> $params The parameters.
	 * @param string|null           $user   The caller; null is anonymous.
	 *
	 * @return SubjectCostReportController
	 */
	private function controller(array $params, ?string $user = 'controller'): SubjectCostReportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		$context = $this->createMock(AdministrationContextServiceInterface::class);
		$context->method('currentUserId')->willReturn($user);
		$context->method('buildContext')->willReturn(['activeAdministrationId' => 'adm-1']);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId): bool {
				$this->asked[] = $administrationId;
				return $this->canAccess;
			}
		);

		return new SubjectCostReportController(request: $request, subjectCosts: $this->world->report(), context: $context);
	}//end controller()

	/**
	 * Per type: one row per type with the incomplete case under it, then the total.
	 *
	 * @return void
	 */
	public function testTheRowsPerType(): void {
		$response = $this->controller(['grouping' => 'type', 'period' => '2026-Q3'])->rows();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				['label' => 'Bezwaar', 'objects' => 2, 'hours' => 10.0, 'cost' => 400.0, 'incomplete' => 'c4', 'types' => ''],
				['label' => 'Omgevingsvergunning', 'objects' => 2, 'hours' => 12.5, 'cost' => 1250.0, 'incomplete' => '', 'types' => ''],
				['label' => 'Total', 'objects' => 4, 'hours' => 22.5, 'cost' => 1650.0, 'incomplete' => '1', 'types' => ''],
			],
			$response->getData()['rows']
		);
		$this->assertSame(['adm-1'], $this->asked);
	}//end testTheRowsPerType()

	/**
	 * Per taakveld: the type without one forms the named row, and the total
	 * equals the per-type total.
	 *
	 * @return void
	 */
	public function testTheRowsPerTaskField(): void {
		$rows = $this->controller(['period' => '2026-Q3', 'administrationId' => 'adm-1'])->rows()->getData()['rows'];

		$this->assertSame(['8.3', 'No task field', 'Total'], array_column($rows, 'label'));
		$this->assertSame('Bezwaar', $rows[1]['types']);
		$this->assertSame(1650.0, $rows[2]['cost']);
	}//end testTheRowsPerTaskField()

	/**
	 * Without dossiq the table names it and shows no figure, not EUR 0.00.
	 *
	 * @return void
	 */
	public function testAMissingAppIsNamedWithoutAFigure(): void {
		$this->world->installed = ['humaniq'];

		$rows = $this->controller(['grouping' => 'type', 'period' => '2026-Q3'])->rows()->getData()['rows'];

		$this->assertCount(1, $rows);
		$this->assertStringContainsString('Dossiq is not installed', $rows[0]['label']);
		$this->assertNull($rows[0]['cost']);
		$this->assertNull($rows[0]['hours']);
		$this->assertNull($rows[0]['objects']);
	}//end testAMissingAppIsNamedWithoutAFigure()

	/**
	 * Anonymous, a bad period and a foreign administration are refused.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$this->assertSame(401, $this->controller(['period' => '2026-Q3'], null)->rows()->getStatus());
		$this->assertSame(400, $this->controller(['period' => '2026-Q5'])->rows()->getStatus());
		$this->assertSame(400, $this->controller(['period' => '2026-13'])->rows()->getStatus());
		$this->assertSame(200, $this->controller(['period' => '2026-09'])->rows()->getStatus());
		$this->assertSame(200, $this->controller(['period' => '2026'])->rows()->getStatus());
		$this->assertSame(['adm-1', 'adm-1'], $this->asked);

		$this->asked     = [];
		$this->canAccess = false;
		$this->assertSame(404, $this->controller(['period' => '2026-Q3', 'administrationId' => 'elders'])->rows()->getStatus());
		$this->assertSame(['elders'], $this->asked);
	}//end testRefusals()
}//end class
