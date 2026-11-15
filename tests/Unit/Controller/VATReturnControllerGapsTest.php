<?php

/**
 * Gap tests for VATReturnController.
 *
 * Covers what VATReturnControllerTest leaves open: the anonymous and
 * malformed-id refusals on every verb, the logged 500 each action answers
 * when OpenRegister or the service fails, updating notes on a draft, the
 * list filters, same-year future-period checks and the 'system' actor
 * fallback.
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
 * @spec openspec/changes/bookkeeping-vat-btw-filing/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use LogicException;
use OCA\Shillinq\Controller\VATReturnController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\VATReturnService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the VAT-return controller's refusal and failure branches.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(VATReturnController::class)]
final class VATReturnControllerGapsTest extends TestCase {

	/**
	 * Mock service.
	 *
	 * @var VATReturnService&MockObject
	 */
	private VATReturnService&MockObject $service;

	/**
	 * Mock container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock administration context; the caller is a member of adm-1 only.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Answers getUser() gives, in order; the last one repeats.
	 *
	 * @var array<int,IUser|null>
	 */
	private array $users = [];

	/**
	 * Request parameters.
	 *
	 * @var array<string,mixed>
	 */
	private array $params = [];

	/**
	 * Set up the boundary mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service   = $this->createMock(VATReturnService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
		$this->context   = $this->createMock(AdministrationContextService::class);
		$this->context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id === 'adm-1');
		$this->context->method('accessibleAdministrationIds')->willReturn(['adm-1']);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->users  = [$user];
		$this->params = [];
	}//end setUp()

	/**
	 * Build the controller over the current users and params.
	 *
	 * @return VATReturnController
	 */
	private function controller(): VATReturnController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default=null): mixed => ($this->params[$key] ?? $default)
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			fn (): ?IUser => (count($this->users) > 1 ? array_shift($this->users) : $this->users[0])
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new VATReturnController(
			request: $request,
			service: $this->service,
			container: $this->container,
			session: $session,
			context: $this->context,
			logger: $this->logger,
			l10n: $l10n,
		);
	}//end controller()

	/**
	 * A recording ObjectService fake.
	 *
	 * @param array<int,array<string,mixed>> $rows    What findAll answers.
	 * @param string|null                    $failing Method that throws, or null.
	 *
	 * @return object
	 */
	private function objectService(array $rows=[], ?string $failing=null): object {
		return new class($rows, $failing) {
			/**
			 * Calls made, in order.
			 *
			 * @var array<int,array<int,mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $rows    What findAll answers.
			 * @param string|null                    $failing Method that throws.
			 */
			public function __construct(private array $rows, private ?string $failing) {
			}//end __construct()

			/**
			 * Register setter.
			 *
			 * @param string $register Register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->calls[] = ['setRegister', $register];
				return $this;
			}//end setRegister()

			/**
			 * Schema setter.
			 *
			 * @param string $schema Schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->calls[] = ['setSchema', $schema];
				return $this;
			}//end setSchema()

			/**
			 * Find all.
			 *
			 * @param array<string,mixed> $params Query.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params=[]): array {
				$this->calls[] = ['findAll', $params];
				if ($this->failing === 'findAll') {
					throw new RuntimeException('or down');
				}

				return $this->rows;
			}//end findAll()

			/**
			 * Save.
			 *
			 * @param array<string,mixed> $data Body.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $data): array {
				$this->calls[] = ['saveObject', $data];
				if ($this->failing === 'saveObject') {
					throw new RuntimeException('or down');
				}

				return $data + ['saved' => true];
			}//end saveObject()

			/**
			 * Delete.
			 *
			 * @param string $id Id.
			 *
			 * @return void
			 */
			public function deleteObject(string $id): void {
				$this->calls[] = ['deleteObject', $id];
				if ($this->failing === 'deleteObject') {
					throw new RuntimeException('or down');
				}
			}//end deleteObject()
		};
	}//end objectService()

	/**
	 * Every verb answers 401 to an anonymous caller.
	 *
	 * @return void
	 */
	public function testAnonymousCallerIs401OnEveryVerb(): void {
		$this->users = [null];
		$this->service->expects($this->never())->method('findReturn');
		$controller = $this->controller();

		foreach (['index', 'create'] as $action) {
			$response = $controller->$action();
			self::assertSame(401, $response->getStatus(), $action);
			self::assertSame(['error' => 'Not logged in'], $response->getData());
		}

		foreach (['show', 'update', 'submit', 'rebase', 'destroy'] as $action) {
			$response = $controller->$action('r1');
			self::assertSame(401, $response->getStatus(), $action);
			self::assertSame(['error' => 'Not logged in'], $response->getData());
		}
	}//end testAnonymousCallerIs401OnEveryVerb()

	/**
	 * A malformed return id is 400 on every id verb, before the service is asked.
	 *
	 * @return void
	 */
	public function testMalformedReturnIdIs400(): void {
		$this->service->expects($this->never())->method('findReturn');
		$controller = $this->controller();

		foreach (['show', 'update', 'submit', 'rebase', 'destroy'] as $action) {
			foreach (['', 'r/1', str_repeat('a', 65)] as $id) {
				$response = $controller->$action($id);
				self::assertSame(400, $response->getStatus(), $action . ' ' . $id);
				self::assertSame(['error' => 'returnId is required'], $response->getData());
			}
		}
	}//end testMalformedReturnIdIs400()

	/**
	 * index() passes only whitelisted filters, and a failing ObjectService is a logged 500.
	 *
	 * @return void
	 */
	public function testIndexFiltersAndFailure(): void {
		$this->params = ['period' => 'month', 'regime' => 'kor', 'status' => 'filed'];
		$or = $this->objectService(rows: [['id' => 'r1']]);
		$this->container->method('get')->willReturnOnConsecutiveCalls($or, $this->throwException(new RuntimeException('no OR')));
		$this->logger->expects($this->once())->method('error')
			->with('VATReturnController: failed to list VAT returns', ['exception' => 'no OR']);
		$controller = $this->controller();

		$ok = $controller->index();
		self::assertSame(200, $ok->getStatus());
		self::assertSame([['id' => 'r1']], $ok->getData()['data']);
		$filters = ['period' => 'month', 'regime' => 'kor', 'statusCode' => 'filed', 'administrationId' => 'adm-1'];
		self::assertContains(['findAll', ['filters' => $filters]], $or->calls);

		$failed = $controller->index();
		self::assertSame(500, $failed->getStatus());
		self::assertSame(['error' => 'Failed to list VAT returns'], $failed->getData());
	}//end testIndexFiltersAndFailure()

	/**
	 * index() drops filter values outside the whitelist.
	 *
	 * @return void
	 */
	public function testIndexDropsUnknownFilterValues(): void {
		$this->params = ['period' => 'week', 'regime' => 'x', 'status' => 'deleted'];
		$or = $this->objectService();
		$this->container->method('get')->willReturn($or);

		$this->controller()->index();

		self::assertContains(['findAll', ['filters' => ['administrationId' => 'adm-1']]], $or->calls);
	}//end testIndexDropsUnknownFilterValues()

	/**
	 * show() answers a logged 500 when loading the children fails.
	 *
	 * @return void
	 */
	public function testShowFailureIsALogged500(): void {
		$this->service->method('findReturn')->with('r1')->willReturn(['id' => 'r1', 'administrationId' => 'adm-1']);
		$this->container->method('get')->willReturn($this->objectService(failing: 'findAll'));
		$this->logger->expects($this->once())->method('error')
			->with('VATReturnController: failed to load VAT return', ['returnId' => 'r1', 'exception' => 'or down']);

		$response = $this->controller()->show('r1');

		self::assertSame(500, $response->getStatus());
		self::assertSame(['error' => 'Failed to load VAT return'], $response->getData());
	}//end testShowFailureIsALogged500()

	/**
	 * create() answers a logged 500 when the service fails.
	 *
	 * @return void
	 */
	public function testCreateFailureIsALogged500(): void {
		$this->params = ['administrationId' => 'adm-1', 'period' => 'year', 'periodYear' => 2024, 'periodNumber' => 1];
		$this->service->expects($this->once())->method('createReturn')->willThrowException(new RuntimeException('busy'));
		$this->logger->expects($this->once())->method('error')
			->with('VATReturnController: failed to create VAT return', ['exception' => 'busy']);

		$response = $this->controller()->create();

		self::assertSame(500, $response->getStatus());
		self::assertSame(['error' => 'Failed to create VAT return'], $response->getData());
	}//end testCreateFailureIsALogged500()

	/**
	 * create() for the current year: quarter 1, month 1 and the year are accepted; a later month is not.
	 *
	 * @return void
	 */
	public function testCreateSameYearPeriods(): void {
		$year  = (int)gmdate('Y');
		$month = (int)gmdate('n');
		$seen  = [];
		$this->service->method('createReturn')->willReturnCallback(
			static function (string $administrationId, string $period, int $periodYear, int $periodNumber, string $regime) use (&$seen): array {
				$seen[] = [$period, $periodYear, $periodNumber];
				return ['id' => 'new'];
			}
		);
		$controller = $this->controller();

		foreach ([['quarter', 1], ['month', 1], ['year', 1]] as [$period, $number]) {
			$this->params = ['administrationId' => 'adm-1', 'period' => $period, 'periodYear' => $year, 'periodNumber' => $number];
			$response = $controller->create();
			self::assertSame(201, $response->getStatus(), $period);
		}

		self::assertSame([['quarter', $year, 1], ['month', $year, 1], ['year', $year, 1]], $seen);

		// A same-year month after this one, and a same-year quarter starting after this month.
		$this->params = ['administrationId' => 'adm-1', 'period' => 'month', 'periodYear' => $year, 'periodNumber' => min(12, ($month + 1))];
		$expected     = $month < 12 ? 400 : 201;
		self::assertSame($expected, $controller->create()->getStatus());
		$quarter      = intdiv(($month - 1), 3) + 2;
		$this->params = ['administrationId' => 'adm-1', 'period' => 'quarter', 'periodYear' => $year, 'periodNumber' => min(4, $quarter)];
		$expected     = $quarter <= 4 ? 400 : 201;
		self::assertSame($expected, $controller->create()->getStatus());
	}//end testCreateSameYearPeriods()

	/**
	 * update() saves the notes on a draft and answers the saved row.
	 *
	 * @return void
	 */
	public function testUpdateSavesNotesOnADraft(): void {
		$this->params = ['notes' => 'checked by accountant'];
		$this->service->method('findReturn')->with('r1')
			->willReturn(['id' => 'r1', 'administrationId' => 'adm-1', 'statusCode' => 'draft']);
		$or = $this->objectService();
		$this->container->expects($this->once())->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($or);

		$response = $this->controller()->update('r1');

		$row = ['id' => 'r1', 'administrationId' => 'adm-1', 'statusCode' => 'draft', 'notes' => 'checked by accountant'];
		self::assertSame(200, $response->getStatus());
		self::assertSame(['data' => $row + ['saved' => true]], $response->getData());
		self::assertSame([['setRegister', 'shillinq'], ['setSchema', 'BtwAangifte'], ['saveObject', $row]], $or->calls);
	}//end testUpdateSavesNotesOnADraft()

	/**
	 * update() masks missing and foreign returns as 404 and refuses a non-draft with 409.
	 *
	 * @return void
	 */
	public function testUpdateRefusals(): void {
		$this->service->method('findReturn')->willReturnCallback(
			static fn (string $id): ?array => [
				'r-foreign' => ['id' => 'r-foreign', 'administrationId' => 'adm-2', 'statusCode' => 'draft'],
				'r-filed' => ['id' => 'r-filed', 'administrationId' => 'adm-1', 'statusCode' => 'submitted'],
			][$id] ?? null
		);
		$this->container->expects($this->never())->method('get');
		$controller = $this->controller();

		foreach (['r-missing', 'r-foreign'] as $id) {
			$response = $controller->update($id);
			self::assertSame(404, $response->getStatus(), $id);
			self::assertSame(['error' => 'VAT return not found'], $response->getData());
		}

		$response = $controller->update('r-filed');
		self::assertSame(409, $response->getStatus());
		self::assertSame(['error' => 'Only draft returns can be edited; rebase first'], $response->getData());
	}//end testUpdateRefusals()

	/**
	 * update() answers a logged 500 when the save fails.
	 *
	 * @return void
	 */
	public function testUpdateFailureIsALogged500(): void {
		$this->service->method('findReturn')->willReturn(['id' => 'r1', 'administrationId' => 'adm-1', 'statusCode' => 'draft']);
		$this->container->method('get')->willReturn($this->objectService(failing: 'saveObject'));
		$this->logger->expects($this->once())->method('error')
			->with('VATReturnController: failed to update VAT return', ['returnId' => 'r1', 'exception' => 'or down']);

		$response = $this->controller()->update('r1');

		self::assertSame(500, $response->getStatus());
		self::assertSame(['error' => 'Failed to update VAT return'], $response->getData());
	}//end testUpdateFailureIsALogged500()

	/**
	 * submit() answers a non-runtime failure as a logged 500, and stamps 'system' when the session drops.
	 *
	 * @return void
	 */
	public function testSubmitNonRuntimeFailureAndSystemActor(): void {
		$user        = $this->users[0];
		$this->users = [$user, null];
		$this->service->method('findReturn')->willReturn(['id' => 'r1', 'administrationId' => 'adm-1']);
		$this->service->expects($this->once())->method('submitReturn')->with('r1', 'system')
			->willThrowException(new LogicException('bad state'));
		$this->logger->expects($this->once())->method('error')
			->with('VATReturnController: failed to submit VAT return', ['returnId' => 'r1', 'exception' => 'bad state']);

		$response = $this->controller()->submit('r1');

		self::assertSame(500, $response->getStatus());
		self::assertSame(['error' => 'Failed to submit VAT return'], $response->getData());
	}//end testSubmitNonRuntimeFailureAndSystemActor()

	/**
	 * rebase() masks a foreign return as 404, answers a runtime failure 409 and any other a 500.
	 *
	 * @return void
	 */
	public function testRebaseRefusalAndFailures(): void {
		$this->service->method('findReturn')->willReturnCallback(
			static fn (string $id): array => ['id' => $id, 'administrationId' => ($id === 'r-foreign' ? 'adm-2' : 'adm-1')]
		);
		$this->service->expects($this->exactly(2))->method('rebaseReturn')->with('r1', 'alice')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('not submitted')),
			$this->throwException(new LogicException('broken'))
		);
		$controller = $this->controller();

		$foreign = $controller->rebase('r-foreign');
		self::assertSame(404, $foreign->getStatus());
		self::assertSame(['error' => 'VAT return not found'], $foreign->getData());

		$conflict = $controller->rebase('r1');
		self::assertSame(409, $conflict->getStatus());
		self::assertSame(['message' => 'Unable to rebase VAT return', 'error' => 'vat-return-rebase-failed'], $conflict->getData());

		$failed = $controller->rebase('r1');
		self::assertSame(500, $failed->getStatus());
		self::assertSame(['error' => 'Failed to rebase VAT return'], $failed->getData());
	}//end testRebaseRefusalAndFailures()

	/**
	 * destroy() answers a logged 500 when the delete fails.
	 *
	 * @return void
	 */
	public function testDestroyFailureIsALogged500(): void {
		$this->service->method('findReturn')->willReturn(['id' => 'r1', 'administrationId' => 'adm-1', 'statusCode' => 'draft']);
		$or = $this->objectService(failing: 'deleteObject');
		$this->container->method('get')->willReturn($or);
		$this->logger->expects($this->once())->method('error')
			->with('VATReturnController: failed to delete VAT return', ['returnId' => 'r1', 'exception' => 'or down']);

		$response = $this->controller()->destroy('r1');

		self::assertSame(500, $response->getStatus());
		self::assertSame(['error' => 'Failed to delete VAT return'], $response->getData());
		self::assertContains(['deleteObject', 'r1'], $or->calls);
	}//end testDestroyFailureIsALogged500()
}//end class
