<?php

/**
 * Unit tests for CbcrController: who may run the CbC and minimum tax
 * actions, the fiscal year, and what each answers.
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
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Controller\CbcrController;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\Cbcr\CbcrCheckRunner;
use OCA\Shillinq\Service\Cbcr\CbcrFiguresService;
use OCA\Shillinq\Service\Cbcr\GroupScopeService;
use OCA\Shillinq\Service\Cbcr\Pillar2SafeHarbourService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The controller over mocked services and an in-memory register.
 */
#[CoversClass(CbcrController::class)]
#[UsesClass(ObjectIdentifier::class)]
#[UsesClass(CbcrCheckRunner::class)]
final class CbcrControllerTest extends TestCase {
	/**
	 * The scope service.
	 *
	 * @var GroupScopeService&MockObject
	 */
	private GroupScopeService $scope;

	/**
	 * The figures service.
	 *
	 * @var CbcrFiguresService&MockObject
	 */
	private CbcrFiguresService $figures;

	/**
	 * The safe harbour service.
	 *
	 * @var Pillar2SafeHarbourService&MockObject
	 */
	private Pillar2SafeHarbourService $safeHarbour;

	/**
	 * The check runner.
	 *
	 * @var CbcrCheckRunner&MockObject
	 */
	private CbcrCheckRunner $checks;

	/**
	 * The controller with the given request parameters, for a member of adm-holding and adm-productie only.
	 *
	 * @param array<string,mixed> $parameters The request parameters.
	 *
	 * @return CbcrController
	 */
	private function controller(array $parameters = []): CbcrController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($parameters[$key] ?? $default));

		$store = new InMemoryObjectServiceStub(
			[
				'GroupEntityRegistry' => [
					['id' => 'ge-holding', 'entityName' => 'Voorbeeld Groep Holding B.V.', 'administrationId' => 'adm-holding'],
					[
						'id' => 'ge-productie',
						'entityName' => 'Voorbeeld Productie B.V.',
						'administrationId' => 'adm-productie',
						'ultimateParentEntity' => 'ge-holding',
					],
					['id' => 'ge-gmbh', 'entityName' => 'Voorbeeld GmbH', 'ultimateParentEntity' => 'ge-holding'],
					['id' => 'ge-ie', 'entityName' => 'Voorbeeld Ireland Ltd', 'administrationId' => 'adm-ie', 'ultimateParentEntity' => 'ge-holding'],
					['id' => 'ge-other', 'entityName' => 'Andere Groep B.V.', 'administrationId' => 'adm-other'],
				],
				'Pillar2JurisdictionComputation' => [['id' => 'comp-nl', 'ultimateParentEntity' => 'ge-holding', 'jurisdiction' => 'NL']],
				'CbcrReturn' => [['id' => 'cbc-2026', 'reportingEntity' => 'ge-productie', 'period' => 'FY2026']],
				'GlobeInformationReturn' => [['id' => 'gir-2026', 'ultimateParent' => 'ge-other', 'period' => 'FY2026']],
			]
		);
		$context = $this->createMock(AdministrationContextServiceInterface::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $administrationId): bool => in_array(
			$administrationId,
			['adm-holding', 'adm-productie'],
			true
		));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('tax.director');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new CbcrController(
			$request,
			$this->scope,
			$this->figures,
			$this->safeHarbour,
			$this->checks,
			$store,
			$context,
			$session,
			$appConfig,
			$l10n,
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * Fresh service mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->scope       = $this->createMock(GroupScopeService::class);
		$this->figures     = $this->createMock(CbcrFiguresService::class);
		$this->safeHarbour = $this->createMock(Pillar2SafeHarbourService::class);
		$this->checks      = $this->createMock(CbcrCheckRunner::class);
	}//end setUp()

	/**
	 * "Assess scope" assesses the year as the signed-in user, with the entered revenue.
	 *
	 * @return void
	 */
	public function testAssessScopeRunsAsTheCaller(): void {
		$this->scope->expects($this->once())->method('assess')
			->with('ge-holding', 2026, [2025 => 805000000.0], 'tax.director')
			->willReturn(['id' => 'gsa-2026', 'cbcrInScope' => true]);

		$response = $this->controller(['fiscalYear' => '2026', 'enteredRevenue' => ['2025' => '805000000', 'x' => '1']])->assessScope('ge-holding');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'gsa-2026', 'cbcrInScope' => true], $response->getData());
	}//end testAssessScopeRunsAsTheCaller()

	/**
	 * Another group's entity, an unknown id and a malformed id all answer 404, and nothing runs.
	 *
	 * @return void
	 */
	public function testAnotherGroupOrAnUnknownEntityIsNotFound(): void {
		$this->scope->expects($this->never())->method('assess');
		$this->figures->expects($this->never())->method('readFromBooks');
		$controller = $this->controller(['fiscalYear' => 2026]);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->assessScope('ge-other')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->assessScope('ge-missing')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->readFigures('../etc')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->readFigures('ge-gmbh')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->girChecks('gir-2026')->getStatus());
	}//end testAnotherGroupOrAnUnknownEntityIsNotFound()

	/**
	 * A missing or absurd fiscal year is a 400; a refusal from the service is a 422 with its reason.
	 *
	 * @return void
	 */
	public function testTheYearIsRequiredAndARefusalSaysWhy(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->readFigures('ge-productie')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(['fiscalYear' => 99])->readFigures('ge-productie')->getStatus());

		$refusal = 'Enter the average and closing USD to EUR rate for the year on the scope assessment first.';
		$this->figures->method('readFromBooks')->willThrowException(new RuntimeException($refusal));
		$response = $this->controller(['fiscalYear' => 2026])->readFigures('ge-productie');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => $refusal], $response->getData());
	}//end testTheYearIsRequiredAndARefusalSaysWhy()

	/**
	 * "Prepare from the books" reads every entity with books the caller may
	 * read, names the ones it could not, and summarises.
	 *
	 * @return void
	 */
	public function testPrepareReadsEachBookkeepingEntityAndSummarises(): void {
		$read = [];
		$this->figures->method('readFromBooks')->willReturnCallback(
			static function (string $entityId, int $fiscalYear) use (&$read): array {
				$read[] = [$entityId, $fiscalYear];
				if ($entityId === 'ge-holding') {
					throw new InvalidArgumentException('No rate.');
				}

				return [];
			}
		);
		$this->figures->expects($this->once())->method('summarise')->with('ge-holding', 2026)
			->willReturn(['summaries' => [['jurisdiction' => 'NL']], 'skipped' => ['DE'], 'withoutFigures' => ['ge-gmbh']]);

		$data = $this->controller(['fiscalYear' => 2026])->prepare('ge-holding')->getData();

		$this->assertSame([['ge-holding', 2026], ['ge-productie', 2026]], $read);
		$this->assertSame(['DE'], $data['skipped']);
		$this->assertSame(['ge-holding' => 'No rate.', 'ge-ie' => "You have no access to this entity's administration."], $data['notRead']);
	}//end testPrepareReadsEachBookkeepingEntityAndSummarises()

	/**
	 * The safe harbour, QDMTT and checks actions answer with the services' results.
	 *
	 * @return void
	 */
	public function testSafeHarbourQdmttAndChecksAnswerWithTheResults(): void {
		$this->safeHarbour->method('test')->with('ge-holding', 2026)->willReturn(['NL' => ['passed' => ['simplified-etr'], 'computation' => null]]);
		$this->safeHarbour->method('applyQdmtt')->with('comp-nl')->willReturn(['qdmttPayable' => 165000.0]);
		$this->checks->method('runCbc')->willReturn(
			[
				['id' => 'cbc-accounts-mapped', 'severity' => 'mandatory', 'passed' => false, 'source' => 's', 'statement' => 'x'],
				['id' => 'cbc-figures-from-books', 'severity' => 'recommended', 'passed' => false, 'source' => 's', 'statement' => 'y'],
			]
		);
		$controller = $this->controller(['fiscalYear' => 2026]);

		$this->assertSame(
			['jurisdictions' => ['NL' => ['passed' => ['simplified-etr'], 'computation' => null]]],
			$controller->safeHarbour('ge-holding')->getData()
		);
		$this->assertSame(['qdmttReturn' => ['qdmttPayable' => 165000.0]], $controller->qdmtt('comp-nl')->getData());
		$checks = $controller->cbcChecks('cbc-2026')->getData();
		$this->assertSame(['cbc-accounts-mapped'], $checks['blocking']);
		$this->assertCount(2, $checks['checks']);
	}//end testSafeHarbourQdmttAndChecksAnswerWithTheResults()
}//end class
