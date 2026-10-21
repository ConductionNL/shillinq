<?php

/**
 * Tests for AdministrationResultsController.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\AdministrationResultsController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\AdministrationResultsService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Commitments and segment results answer only for an administration the
 * caller belongs to, and always narrow the aggregation to it.
 */
class AdministrationResultsControllerTest extends TestCase {

	/**
	 * The aggregation runs the controller asked for, as [schema, name, extraFilter].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $runs = [];

	/**
	 * A controller for a caller who belongs to adm-1 only.
	 *
	 * @param array<string, string> $params The request parameters.
	 * @param string|null           $userId The caller.
	 *
	 * @return AdministrationResultsController The controller.
	 */
	private function controller(array $params, ?string $userId = 'controller'): AdministrationResultsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($userId);
		$context->method('buildContext')->willReturn(['activeAdministrationId' => 'adm-1']);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id === 'adm-1');

		$runs   = &$this->runs;
		$runner = new class($runs) {
			/**
			 * @param array<int, mixed> $runs The recorded runs.
			 */
			public function __construct(private array &$runs) {
			}

			/**
			 * Record the run and answer one group.
			 *
			 * @param string               $registerRef The register.
			 * @param string               $schemaRef   The schema.
			 * @param string               $name        The aggregation.
			 * @param array<string, mixed> $extraFilter The narrowing filter.
			 *
			 * @return array<string, mixed> The envelope.
			 */
			public function run(string $registerRef, string $schemaRef, string $name, array $extraFilter=[]): array {
				$this->runs[] = [$schemaRef, $name, $extraFilter];
				return ['groups' => [['keys' => ['programme' => '5.1'], 'values' => ['sum_remaining_committed' => 100]]]];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($runner);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$objects = new InMemoryObjectServiceStub(
			[
				'CommitmentLine' => [
					['id' => 'l-1', 'administrationId' => 'adm-1', 'programme' => '5.1', 'costCentre' => 'FAC'],
					['id' => 'l-2', 'administrationId' => 'adm-2', 'programme' => '5.1', 'costCentre' => 'FAC'],
					['id' => 'l-3', 'administrationId' => 'adm-1', 'programme' => '6.0', 'costCentre' => 'FAC'],
				],
				'FiscalPeriod'   => [
					['administrationId' => 'adm-1', 'periodId' => '2026-M09', 'name' => 'September 2026', 'startDate' => '2026-09-01'],
					['administrationId' => 'adm-2', 'periodId' => '2026-M10', 'name' => 'October 2026', 'startDate' => '2026-10-01'],
				],
			]
		);

		return new AdministrationResultsController(
			$request,
			$context,
			new AdministrationResultsService($container, $objects, $settings),
			$this->createMock(LoggerInterface::class)
		);

	}//end controller()

	/**
	 * The commitments of the active administration are aggregated for it only.
	 *
	 * @return void
	 */
	public function testCommitmentsAreNarrowedToTheActiveAdministration(): void {
		$response = $this->controller([])->commitments();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[['CommitmentLine', 'committedVsRealisedPerBudgetLine', ['administrationId' => 'adm-1']]],
			$this->runs
		);
		$this->assertSame('5.1', $response->getData()['groups'][0]['keys']['programme']);

	}//end testCommitmentsAreNarrowedToTheActiveAdministration()

	/**
	 * Another administration's commitments are masked as absent and never aggregated.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationsCommitmentsAreNotFound(): void {
		$response = $this->controller(['administrationId' => 'adm-2'])->commitments();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $this->runs);

	}//end testAnotherAdministrationsCommitmentsAreNotFound()

	/**
	 * The drill-down lists the budget line's lines of the caller's administration only.
	 *
	 * @return void
	 */
	public function testTheDrillDownListsOnlyTheAdministrationsLines(): void {
		$response = $this->controller(['programme' => '5.1', 'costCentre' => 'FAC'])->commitmentLines();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['l-1'], array_column($response->getData()['results'], 'id'));
		$this->assertSame(1, $response->getData()['total']);

	}//end testTheDrillDownListsOnlyTheAdministrationsLines()

	/**
	 * The drill-down refuses another administration.
	 *
	 * @return void
	 */
	public function testTheDrillDownOfAnotherAdministrationIsNotFound(): void {
		$response = $this->controller(['administrationId' => 'adm-2', 'programme' => '5.1'])->commitmentLines();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testTheDrillDownOfAnotherAdministrationIsNotFound()

	/**
	 * Segment results are narrowed to the administration and period, with its periods.
	 *
	 * @return void
	 */
	public function testSegmentResultsAreNarrowedToTheAdministrationAndPeriod(): void {
		$response = $this->controller(['segment' => 'project', 'periodId' => '2026-M09'])->segments();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[['GLLine', 'byProject', ['administrationId' => 'adm-1', 'periodId' => '2026-M09']]],
			$this->runs
		);
		$this->assertSame(['2026-M09'], array_column($response->getData()['periods'], 'periodId'));

	}//end testSegmentResultsAreNarrowedToTheAdministrationAndPeriod()

	/**
	 * Another administration's segment results are masked as absent.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationsSegmentResultsAreNotFound(): void {
		$response = $this->controller(['administrationId' => 'adm-2'])->segments();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $this->runs);

	}//end testAnotherAdministrationsSegmentResultsAreNotFound()

	/**
	 * An unknown segment is a bad request.
	 *
	 * @return void
	 */
	public function testAnUnknownSegmentIsABadRequest(): void {
		$response = $this->controller(['segment' => 'byEverything'])->segments();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame([], $this->runs);

	}//end testAnUnknownSegmentIsABadRequest()

	/**
	 * A malformed period is a bad request.
	 *
	 * @return void
	 */
	public function testAMalformedPeriodIsABadRequest(): void {
		$response = $this->controller(['periodId' => "2026'; --"])->segments();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testAMalformedPeriodIsABadRequest()

	/**
	 * Nobody signed in gets nothing.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller([], null)->commitments()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller([], null)->segments()->getStatus());

	}//end testAnAnonymousCallerIsRefused()

	/**
	 * Every segment names an aggregation declared on GLLine.
	 *
	 * @return void
	 */
	public function testEverySegmentNamesADeclaredAggregation(): void {
		$fragment = json_decode(
			(string)file_get_contents(__DIR__.'/../../../lib/Settings/register.d/bookkeeping-cost-centers-dimensions.json'),
			true
		);
		$declared = $fragment['components']['schemas']['GLLine']['x-openregister-aggregations'];
		foreach (AdministrationResultsService::SEGMENT_AGGREGATIONS as $segment => $name) {
			$this->assertArrayHasKey($name, $declared, $segment);
		}

	}//end testEverySegmentNamesADeclaredAggregation()
}//end class
