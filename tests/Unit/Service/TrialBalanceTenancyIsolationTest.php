<?php

/**
 * Multi-tenancy isolation tests for the trial balance pipeline (REQ-TB-017).
 *
 * Covers the controller-level IDOR guard (foreign administration → masked 404)
 * AND the service-level scoping (cross-administration lines never sum), so a
 * user authenticated against administration A cannot read trial balance data
 * for administration B via either layer.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-13-3
 * KNOWINGLY DANGLING until shillinq#500 — the multi-tenancy requirement it
 * asserts (archived REQ-TB-017) was never canonical.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Controller\TrialBalanceController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\TrialBalanceCalculator;
use OCA\Shillinq\Service\TrialBalanceService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * End-to-end (service + controller) multi-tenancy isolation tests.
 *
 * Each scenario drives the controller with a different "current user" wired to
 * the AdministrationContextService and verifies the user cannot read trial
 * balance data outside the administrations they belong to. The controller
 * route is `#[NoAdminRequired]` and uses an in-process IDOR guard that masks
 * non-membership as HTTP 404 (REQ-TB-016, REQ-TB-017).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class TrialBalanceTenancyIsolationTest extends TestCase {

	/**
	 * The shared in-memory ObjectService stub, seeded with two administrations.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * The DI container the service reads ObjectService from.
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * IAppConfig stub.
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;

	/**
	 * Set up shared GL fixtures spanning adm-A and adm-B.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$accounts = [
			// adm-A: account 1000 + 2000.
			$this->account('1000', 'Activa A', 'assets', 'adm-A'),
			$this->account('2000', 'Schulden A', 'liabilities', 'adm-A'),
			// adm-B: account 1000 + 2000 (same numbers, different tenant!).
			$this->account('1000', 'Activa B', 'assets', 'adm-B'),
			$this->account('2000', 'Schulden B', 'liabilities', 'adm-B'),
		];
		$transactions = [
			['id' => 'txn-A1', 'administrationId' => 'adm-A', 'periodId' => '2026-Q1'],
			['id' => 'txn-B1', 'administrationId' => 'adm-B', 'periodId' => '2026-Q1'],
		];
		$lines = [
			$this->line('txn-A1', '1000', 'debit', 7000.0, '2026-Q1'),
			$this->line('txn-A1', '2000', 'credit', 7000.0, '2026-Q1'),
			$this->line('txn-B1', '1000', 'debit', 333.0, '2026-Q1'),
			$this->line('txn-B1', '2000', 'credit', 333.0, '2026-Q1'),
		];

		$this->objectService = $this->newObjectServiceStub($accounts, $transactions, $lines);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);
		$this->container = $container;

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$this->appConfig = $appConfig;

	}//end setUp()

	/**
	 * The service-level scoping silently filters out the foreign tenant's GLLines
	 * even when the controller IDOR guard is bypassed (defence in depth).
	 *
	 * @return void
	 */
	public function testServiceFiltersOutForeignAdministrationGlLines(): void {
		$service = new TrialBalanceService(
			appConfig: $this->appConfig,
			calculator: new TrialBalanceCalculator(),
			objectService: new DuckObjectServiceAdapter($this->objectService),
		);

		// Compute for adm-A; adm-B GLLines must never appear in the totals.
		$result = $service->compute('adm-A', '2026-Q1');
		self::assertSame(7000.0, $result['totals']['totalDebit']);
		self::assertSame(7000.0, $result['totals']['totalCredit']);
		// Verify each row carries the correct tenant.
		foreach ($result['data'] as $row) {
			self::assertSame('adm-A', $row['administrationId']);
			self::assertNotSame(333.0, $row['debitMovement']);
			self::assertNotSame(333.0, $row['creditMovement']);
		}

		// Now compute for adm-B; adm-A's 7000 must not leak across.
		$result = $service->compute('adm-B', '2026-Q1');
		self::assertSame(333.0, $result['totals']['totalDebit']);
		self::assertSame(333.0, $result['totals']['totalCredit']);
		foreach ($result['data'] as $row) {
			self::assertSame('adm-B', $row['administrationId']);
		}

	}//end testServiceFiltersOutForeignAdministrationGlLines()

	/**
	 * A user-A request for adm-B is masked as HTTP 404 by the controller IDOR
	 * guard, and the trial-balance service is never invoked (REQ-TB-016/017).
	 *
	 * @return void
	 */
	public function testControllerMasksForeignAdministrationAs404ForOtherUser(): void {
		// user-A is a member of adm-A only.
		$contextA = $this->newContext(currentUser: 'user-A', accessibleAdministrations: ['adm-A']);
		// user-B is a member of adm-B only.
		$contextB = $this->newContext(currentUser: 'user-B', accessibleAdministrations: ['adm-B']);

		$serviceStub = $this->createMock(TrialBalanceService::class);
		// The IDOR guard MUST prevent the service from ever being invoked.
		$serviceStub->expects($this->never())->method('compute');

		// user-A → adm-B is masked as 404.
		$controllerA = new TrialBalanceController(
			request: $this->newRequest(period: '2026-Q1', administration: 'adm-B'),
			trialBalanceService: $serviceStub,
			context: $contextA,
			logger: $this->createMock(LoggerInterface::class),
		);
		$responseA = $controllerA->index();
		self::assertSame(Http::STATUS_NOT_FOUND, $responseA->getStatus());
		self::assertSame(['error' => 'Administration not found'], $responseA->getData());

		// user-B → adm-A is masked as 404 (mirror case).
		$controllerB = new TrialBalanceController(
			request: $this->newRequest(period: '2026-Q1', administration: 'adm-A'),
			trialBalanceService: $serviceStub,
			context: $contextB,
			logger: $this->createMock(LoggerInterface::class),
		);
		$responseB = $controllerB->index();
		self::assertSame(Http::STATUS_NOT_FOUND, $responseB->getStatus());

	}//end testControllerMasksForeignAdministrationAs404ForOtherUser()

	/**
	 * Each tenant sees its OWN trial balance — adm-A totals never include adm-B
	 * lines and vice-versa — when the request is end-to-end driven through the
	 * controller for the user's own administration.
	 *
	 * @return void
	 */
	public function testEachTenantOnlySeesItsOwnTrialBalance(): void {
		$service = new TrialBalanceService(
			appConfig: $this->appConfig,
			calculator: new TrialBalanceCalculator(),
			objectService: new DuckObjectServiceAdapter($this->objectService),
		);

		// user-A asks for adm-A → 200 + adm-A totals.
		$contextA = $this->newContext(currentUser: 'user-A', accessibleAdministrations: ['adm-A']);
		$controllerA = new TrialBalanceController(
			request: $this->newRequest(period: '2026-Q1', administration: 'adm-A'),
			trialBalanceService: $service,
			context: $contextA,
			logger: $this->createMock(LoggerInterface::class),
		);
		$responseA = $controllerA->index();
		self::assertSame(Http::STATUS_OK, $responseA->getStatus());
		$payloadA = $responseA->getData();
		self::assertSame(7000.0, $payloadA['totals']['totalDebit']);

		// user-B asks for adm-B → 200 + adm-B totals (must NOT include adm-A's 7000).
		$contextB = $this->newContext(currentUser: 'user-B', accessibleAdministrations: ['adm-B']);
		$controllerB = new TrialBalanceController(
			request: $this->newRequest(period: '2026-Q1', administration: 'adm-B'),
			trialBalanceService: $service,
			context: $contextB,
			logger: $this->createMock(LoggerInterface::class),
		);
		$responseB = $controllerB->index();
		self::assertSame(Http::STATUS_OK, $responseB->getStatus());
		$payloadB = $responseB->getData();
		self::assertSame(333.0, $payloadB['totals']['totalDebit']);

		// Sanity: the two responses are NOT identical (no shared mutable state).
		self::assertNotSame($payloadA['totals']['totalDebit'], $payloadB['totals']['totalDebit']);

	}//end testEachTenantOnlySeesItsOwnTrialBalance()

	/**
	 * Build an Account fixture row.
	 *
	 * @param string $number Account number.
	 * @param string $name Account name.
	 * @param string $type Account type.
	 * @param string $admin Administration id.
	 *
	 * @return array<string,mixed>
	 */
	private function account(string $number, string $name, string $type, string $admin): array {
		return [
			'accountNumber' => $number,
			'name' => $name,
			'accountType' => $type,
			'currency' => 'EUR',
			'parentAccountNumber' => null,
			'administrationId' => $admin,
		];

	}//end account()

	/**
	 * Build a GLLine fixture row.
	 *
	 * @param string $txn Transaction id.
	 * @param string $account Account number.
	 * @param string $side Debit or credit.
	 * @param float $amount Amount.
	 * @param string $period Period id.
	 *
	 * @return array<string,mixed>
	 */
	private function line(string $txn, string $account, string $side, float $amount, string $period): array {
		return [
			'transactionId' => $txn,
			'accountNumber' => $account,
			'side' => $side,
			'amount' => $amount,
			'periodId' => $period,
		];

	}//end line()

	/**
	 * Build a stub AdministrationContextService restricted to a set of administrations.
	 *
	 * @param string $currentUser Current user id.
	 * @param array<string> $accessibleAdministrations Administrations the user belongs to.
	 *
	 * @return AdministrationContextService
	 */
	private function newContext(string $currentUser, array $accessibleAdministrations): AdministrationContextService {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($currentUser);
		$context->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool
				=> in_array($administrationId, $accessibleAdministrations, true)
		);
		return $context;
	}//end newContext()

	/**
	 * Build an IRequest stub returning the supplied query parameters.
	 *
	 * @param string $period period_id param.
	 * @param string $administration administration_id param.
	 *
	 * @return IRequest
	 */
	private function newRequest(string $period, string $administration): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($period, $administration): mixed {
				return match ($key) {
					'period_id' => $period,
					'administration_id' => $administration,
					'prior_period_id' => '',
					default => $default,
				};
			}
		);
		return $request;
	}//end newRequest()

	/**
	 * Construct an anonymous ObjectService stub that mimics setRegister/setSchema/findAll
	 * with simple equality filter support — matches the shape exercised by TrialBalanceService.
	 *
	 * @param array<int,array<string,mixed>> $accounts Account fixtures.
	 * @param array<int,array<string,mixed>> $transactions GLTransaction fixtures.
	 * @param array<int,array<string,mixed>> $lines GLLine fixtures.
	 *
	 * @return object
	 */
	private function newObjectServiceStub(array $accounts, array $transactions, array $lines): object {
		return new class($accounts, $transactions, $lines) {
			/**
			 * Data sets keyed by schema slug.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Last selected schema slug.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $accounts Account fixtures.
			 * @param array<int,array<string,mixed>> $transactions GLTransaction fixtures.
			 * @param array<int,array<string,mixed>> $lines GLLine fixtures.
			 */
			public function __construct(array $accounts, array $transactions, array $lines) {
				$this->data = [
					'Account' => $accounts,
					'GLTransaction' => $transactions,
					'GLLine' => $lines,
				];
			}//end __construct()

			/**
			 * Fluent register setter (no-op for the stub).
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return rows for the active schema, filtered by simple equality.
			 *
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->data[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()
		};

	}//end newObjectServiceStub()
}//end class
