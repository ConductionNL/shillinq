<?php

/**
 * Unit tests for BudgetScenarioController::promote().
 *
 * ## Why this file exists
 *
 * `BudgetScenarioController::promote()` wraps the whole promotion in
 * `catch (Throwable) { serverError() }`, so ANY exception below it becomes an
 * opaque HTTP 500 whose real cause is swallowed into the server log. The
 * e2e test `budget-scenarios.spec.ts::promote to default demotes the previous
 * one` failed on exactly that (CI run 32462209787, "Expected: 200 / Received:
 * 500"), with five green `BudgetScenarioDefaultPromoterTest` cases underneath
 * it — because those cases drove a double whose `findAll()` answered plain
 * ARRAYS, while the deployed engine answers `list<ObjectEntity>`.
 *
 * So the promotion path is driven here through the CONTROLLER — the surface
 * that turns a throwable into a status code — over a store constructed with
 * `findAllRendersEntities: true`, the shape
 * `RenderObject::renderEntities(): @psalm-return list<ObjectEntity>` really
 * returns. `ObjectEntity` does not implement `ArrayAccess`, so a consumer
 * that subscripts a row fatals; a test that cannot see that shape cannot see
 * the defect.
 *
 * The store is REAL (an in-memory OpenRegister double) and the promoter is
 * REAL: only `AdministrationContextService`, `IUserSession` and `IRequest`
 * are mocked, because they are the request-context boundary, not the
 * behaviour under test.
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
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BudgetScenarioController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BudgetScenarioDefaultPromoter;
use OCA\Shillinq\Service\BudgetScenarioEvaluator;
use OCA\Shillinq\Service\BudgetScenarioReader;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the REQ-BSC-002 promote endpoint against engine-shaped store rows.
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
 */
final class BudgetScenarioControllerTest extends TestCase {

	/**
	 * The administration both seeded scenarios belong to.
	 *
	 * @var string
	 */
	private const ADMINISTRATION = 'adm-1';

	/**
	 * Two scenarios in one administration: `scn-a` is the current default,
	 * `scn-b` is the one the test promotes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function seedScenarios(): array {
		return [
			[
				'id' => 'scn-a',
				'administrationId' => self::ADMINISTRATION,
				'name' => 'Scenario A',
				'isDefault' => true,
				'status' => 'active',
			],
			[
				'id' => 'scn-b',
				'administrationId' => self::ADMINISTRATION,
				'name' => 'Scenario B',
				'isDefault' => false,
				'status' => 'draft',
			],
		];
	}//end seedScenarios()

	/**
	 * Build the controller over a REAL promoter and a REAL in-memory store.
	 *
	 * @param bool $findAllRendersEntities Whether the store answers `findAll()`
	 *                                     with ObjectEntityInterface rows, as
	 *                                     the deployed OpenRegister engine does.
	 * @param bool $canAccess              What the membership guard answers.
	 *
	 * @return array{0: BudgetScenarioController, 1: InMemoryObjectServiceStub}
	 */
	private function buildController(
		bool $findAllRendersEntities = true,
		bool $canAccess = true
	): array {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$store = new InMemoryObjectServiceStub(
			data: ['BudgetScenario' => $this->seedScenarios()],
			findAllRendersEntities: $findAllRendersEntities
		);

		$promoter = new BudgetScenarioDefaultPromoter(
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: $store,
		);

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturn($canAccess);

		$user = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$controller = new BudgetScenarioController(
			request: $this->createMock(IRequest::class),
			promoter: $promoter,
			reader: $this->createMock(BudgetScenarioReader::class),
			evaluator: $this->createMock(BudgetScenarioEvaluator::class),
			context: $context,
			userSession: $userSession,
			logger: new NullLogger(),
		);

		return [$controller, $store];
	}//end buildController()

	/**
	 * Index the store's current rows by id, reading through the plain-array
	 * view so the assertions never depend on the row shape under test.
	 *
	 * @param InMemoryObjectServiceStub $store The store to read.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function rowsById(InMemoryObjectServiceStub $store): array {
		$byId = [];
		foreach ($store->setRegister('shillinq')->setSchema('BudgetScenario')->findAll() as $row) {
			$payload = $row;
			if (is_array($row) === false) {
				$payload = $row->getObject();
			}

			$byId[(string)$payload['id']] = $payload;
		}

		return $byId;
	}//end rowsById()

	/**
	 * THE REGRESSION (REQ-BSC-002, e2e `promote-to-default-demotes-previous
	 * -default`): promoting `scn-b` while `scn-a` is default must answer 200
	 * and demote `scn-a` in the same call — even though the store hands the
	 * promoter ObjectEntityInterface rows rather than arrays.
	 *
	 * Before the `findAllDefaults()` normalisation this returned 500, because
	 * `promote()` subscripted `$currentDefault['id']` on an ObjectEntity.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
	 */
	public function testPromoteAnswers200AndDemotesThePreviousDefaultOnEngineShapedRows(): void {
		[$controller, $store] = $this->buildController(findAllRendersEntities: true);

		$response = $controller->promote(scenarioId: 'scn-b');

		$this->assertSame(
			Http::STATUS_OK,
			$response->getStatus(),
			'BudgetScenarioController::promote must accept the promotion — a 500 here means the '
			. 'promoter threw on the row shape the deployed OpenRegister engine actually returns'
		);

		$outcome = $response->getData()['data'];
		$this->assertSame('scn-a', $outcome['demotedScenarioId']);
		$this->assertTrue($outcome['verified']);
		$this->assertSame(1, $outcome['defaultCount']);

		$byId = $this->rowsById($store);
		$this->assertFalse($byId['scn-a']['isDefault'], 'the previous default must have been demoted');
		$this->assertTrue($byId['scn-b']['isDefault'], 'the promoted scenario must be the default');
		$this->assertSame('active', $byId['scn-b']['status']);
	}//end testPromoteAnswers200AndDemotesThePreviousDefaultOnEngineShapedRows()

	/**
	 * The same promotion over the ARRAY-shaped double still behaves — the
	 * normalisation must accept both shapes, not swap one hard dependency
	 * for another.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
	 */
	public function testPromoteAlsoWorksWhenTheStoreAnswersPlainArrayRows(): void {
		[$controller, $store] = $this->buildController(findAllRendersEntities: false);

		$response = $controller->promote(scenarioId: 'scn-b');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('scn-a', $response->getData()['data']['demotedScenarioId']);

		$byId = $this->rowsById($store);
		$this->assertFalse($byId['scn-a']['isDefault']);
		$this->assertTrue($byId['scn-b']['isDefault']);
	}//end testPromoteAlsoWorksWhenTheStoreAnswersPlainArrayRows()

	/**
	 * An unknown scenario id is a 404 (the promoter's RuntimeException), not
	 * a 500 — the control that proves the 200 above is not simply "nothing
	 * ever throws here".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
	 */
	public function testPromoteAnswers404ForAnUnknownScenario(): void {
		[$controller] = $this->buildController();

		$response = $controller->promote(scenarioId: 'scn-nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testPromoteAnswers404ForAnUnknownScenario()

	/**
	 * A caller without membership of the scenario's administration gets a
	 * 404, never a 403 (IDOR-safe: a 403 would confirm the scenario exists).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
	 */
	public function testPromoteMasksANonMemberAsNotFound(): void {
		[$controller] = $this->buildController(canAccess: false);

		$response = $controller->promote(scenarioId: 'scn-b');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Scenario not found', $response->getData()['error']);
	}//end testPromoteMasksANonMemberAsNotFound()

	/**
	 * An anonymous caller is rejected before any store read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
	 */
	public function testPromoteRejectsAnonymousCallers(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$store = new InMemoryObjectServiceStub(
			data: ['BudgetScenario' => $this->seedScenarios()],
			findAllRendersEntities: true
		);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$controller = new BudgetScenarioController(
			request: $this->createMock(IRequest::class),
			promoter: new BudgetScenarioDefaultPromoter(
				appConfig: $appConfig,
				logger: new NullLogger(),
				objectService: $store,
			),
			reader: $this->createMock(BudgetScenarioReader::class),
			evaluator: $this->createMock(BudgetScenarioEvaluator::class),
			context: $this->createMock(AdministrationContextService::class),
			userSession: $userSession,
			logger: new NullLogger(),
		);

		$response = $controller->promote(scenarioId: 'scn-b');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testPromoteRejectsAnonymousCallers()

	/**
	 * A malformed id is rejected with a 400 before the store is touched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
	 */
	public function testPromoteRejectsAMalformedScenarioId(): void {
		[$controller] = $this->buildController();

		$response = $controller->promote(scenarioId: 'not/an/id');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testPromoteRejectsAMalformedScenarioId()
}//end class
