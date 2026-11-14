<?php

/**
 * Golden tests for PaymentRunController: every answer of export, reconcile and propose.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/payment-run-sepa-export/specs/payment-run-sepa-export/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PaymentRunController;
use OCA\Shillinq\PaymentRun\PaymentRunExportService;
use OCA\Shillinq\PaymentRun\PaymentRunProposalService;
use OCA\Shillinq\PaymentRun\PaymentRunReconciliationService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the status, body, log line and service calls of every branch of
 * PaymentRunController before its actions are split into named steps.
 */
final class PaymentRunControllerGoldenTest extends TestCase {
	/**
	 * The run find() returns (null = not found).
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $run = ['id' => 'pr-1', 'administrationId' => 'adm-1'];

	/**
	 * Whether a user is logged in.
	 *
	 * @var bool
	 */
	private bool $loggedIn = true;

	/**
	 * canAccess() answer.
	 *
	 * @var bool
	 */
	private bool $canAccess = true;

	/**
	 * Request params.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Recorded calls, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Export service mock.
	 *
	 * @var PaymentRunExportService&MockObject
	 */
	private PaymentRunExportService&MockObject $export;

	/**
	 * Reconciliation service mock.
	 *
	 * @var PaymentRunReconciliationService&MockObject
	 */
	private PaymentRunReconciliationService&MockObject $reconcile;

	/**
	 * Proposal service mock.
	 *
	 * @var PaymentRunProposalService&MockObject
	 */
	private PaymentRunProposalService&MockObject $proposal;

	/**
	 * Build the service mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->export = $this->createMock(PaymentRunExportService::class);
		$this->reconcile = $this->createMock(PaymentRunReconciliationService::class);
		$this->proposal = $this->createMock(PaymentRunProposalService::class);
	}//end setUp()

	/**
	 * Build the controller over the current fixture state.
	 *
	 * @return PaymentRunController
	 */
	private function controller(): PaymentRunController {
		$calls = &$this->calls;
		$run = $this->run;
		$objects = new class($run, $calls) {
			/**
			 * @param array<string,mixed>|null $run   The run.
			 * @param array<int,string>        $calls The call log.
			 */
			public function __construct(
				private ?array $run,
				private array &$calls,
			) {
			}//end __construct()

			public function setRegister(string $r): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $s): self {
				return $this;
			}//end setSchema()

			/**
			 * @param string $id The id.
			 *
			 * @return array<string,mixed>|null
			 */
			public function find(string $id): ?array {
				$this->calls[] = 'find:' . $id;
				return $this->run;
			}//end find()
		};

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use (&$calls): bool {
				$calls[] = 'canAccess:' . $administrationId;
				return $this->canAccess;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->loggedIn === true ? $this->createMock(IUser::class) : null);

		$params = $this->params;
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturn(null);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []) use (&$calls): void {
				$calls[] = 'error:' . $message . json_encode($context);
			}
		);

		return new PaymentRunController(
			$request,
			$this->export,
			$this->reconcile,
			$context,
			$session,
			$logger,
			objectService: new DuckObjectServiceAdapter($objects),
			proposalService: $this->proposal,
		);
	}//end controller()

	/**
	 * Anonymous callers get 401 from every action and nothing is looked up.
	 *
	 * @return void
	 */
	public function testAnonymousIsUnauthorised(): void {
		$this->loggedIn = false;
		$controller = $this->controller();

		foreach ([$controller->export('pr-1'), $controller->reconcile('pr-1'), $controller->propose()] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertSame(['error' => 'Not logged in'], $response->getData());
		}

		$this->assertSame([], $this->calls);
	}//end testAnonymousIsUnauthorised()

	/**
	 * An unknown or blank run id is a 404 before any access check.
	 *
	 * @return void
	 */
	public function testUnknownRunIsNotFound(): void {
		$this->run = null;
		$controller = $this->controller();

		$this->assertSame(['error' => 'Not found'], $controller->export('pr-x')->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->reconcile('pr-x')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->export('  ')->getStatus());
		$this->assertSame(['find:pr-x', 'find:pr-x'], $this->calls);
	}//end testUnknownRunIsNotFound()

	/**
	 * A refused administration is a masked 404 and the services are not called.
	 *
	 * @return void
	 */
	public function testRefusedAdministrationIsMaskedNotFound(): void {
		$this->canAccess = false;
		$this->export->expects($this->never())->method('export');
		$this->reconcile->expects($this->never())->method('reconcile');
		$controller = $this->controller();

		$this->assertSame(['error' => 'Not found'], $controller->export('pr-1')->getData());
		$this->assertSame(['error' => 'Not found'], $controller->reconcile('pr-1')->getData());
		$this->assertSame(['find:pr-1', 'canAccess:adm-1', 'find:pr-1', 'canAccess:adm-1'], $this->calls);
	}//end testRefusedAdministrationIsMaskedNotFound()

	/**
	 * Export answers: 200, 409 for not-approved, 422 for any other error.
	 *
	 * @return void
	 */
	public function testExportAnswers(): void {
		$this->export->method('export')->willReturnOnConsecutiveCalls(
			['file' => 'x'],
			['error' => 'not-approved'],
			['error' => 'no-lines']
		);
		$controller = $this->controller();

		$ok = $controller->export('pr-1');
		$this->assertSame([Http::STATUS_OK, ['file' => 'x']], [$ok->getStatus(), $ok->getData()]);
		$this->assertSame(Http::STATUS_CONFLICT, $controller->export('pr-1')->getStatus());
		$other = $controller->export('pr-1');
		$this->assertSame([Http::STATUS_UNPROCESSABLE_ENTITY, ['error' => 'no-lines']], [$other->getStatus(), $other->getData()]);
	}//end testExportAnswers()

	/**
	 * A failing export or reconcile is a logged 500.
	 *
	 * @return void
	 */
	public function testFailuresAreLogged500(): void {
		$this->export->method('export')->willThrowException(new \RuntimeException('boom'));
		$this->reconcile->method('reconcile')->willThrowException(new \RuntimeException('bang'));
		$this->params = ['contents' => '<Document/>'];
		$controller = $this->controller();

		$export = $controller->export('pr-1');
		$reconcile = $controller->reconcile('pr-1');

		$this->assertSame([Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Internal error']], [$export->getStatus(), $export->getData()]);
		$this->assertSame([Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Internal error']], [$reconcile->getStatus(), $reconcile->getData()]);
		$this->assertContains('error:PaymentRunController.export failed: boom[]', $this->calls);
		$this->assertContains('error:PaymentRunController.reconcile failed: bang[]', $this->calls);
	}//end testFailuresAreLogged500()

	/**
	 * Reconcile answers 400 on an empty statement without calling the service.
	 *
	 * @return void
	 */
	public function testReconcileAnswers(): void {
		$this->params = ['contents' => '  '];
		$this->reconcile->expects($this->never())->method('reconcile');
		$empty = $this->controller()->reconcile('pr-1');
		$this->assertSame(
			[Http::STATUS_BAD_REQUEST, ['error' => 'Empty statement — nothing to reconcile']],
			[$empty->getStatus(), $empty->getData()]
		);
	}//end testReconcileAnswers()

	/**
	 * Reconcile passes the statement to the service and maps its answer.
	 *
	 * @return void
	 */
	public function testReconcileResultMapping(): void {
		$this->params = ['contents' => '<Document/>'];
		$this->reconcile->expects($this->exactly(3))->method('reconcile')
			->with($this->run, '<Document/>')
			->willReturnOnConsecutiveCalls(['matchedCount' => 1], ['error' => 'not-exported'], ['error' => 'bad-xml']);
		$controller = $this->controller();

		$this->assertSame(Http::STATUS_OK, $controller->reconcile('pr-1')->getStatus());
		$this->assertSame(Http::STATUS_CONFLICT, $controller->reconcile('pr-1')->getStatus());
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $controller->reconcile('pr-1')->getStatus());
	}//end testReconcileResultMapping()

	/**
	 * A complete proposal form.
	 *
	 * @return array<string, mixed>
	 */
	private static function proposalForm(): array {
		return [
			'administrationId' => ' adm-1 ',
			'dueOnOrBefore' => '2026-10-01',
			'executionDate' => '2026-09-30',
			'debtorAccountIban' => 'nl91 abna 0417 1643 00',
		];
	}//end proposalForm()

	/**
	 * Propose refuses a blank or inaccessible administration with 403.
	 *
	 * @return void
	 */
	public function testProposeRefusesTheAdministration(): void {
		$this->proposal->expects($this->never())->method('propose');
		$this->params = ['administrationId' => ''] + self::proposalForm();
		$blank = $this->controller()->propose();
		$this->assertSame([Http::STATUS_FORBIDDEN, ['error' => 'Not allowed for this administration']], [$blank->getStatus(), $blank->getData()]);
		$this->assertSame([], $this->calls);

		$this->canAccess = false;
		$this->params = self::proposalForm();
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->propose()->getStatus());
		$this->assertSame(['canAccess:adm-1'], $this->calls);
	}//end testProposeRefusesTheAdministration()

	/**
	 * Propose answers 400 on a bad date or a blank IBAN.
	 *
	 * @return void
	 */
	public function testProposeRejectsAnIncompleteForm(): void {
		$this->proposal->expects($this->never())->method('propose');
		foreach ([['dueOnOrBefore' => '2026-02-30'], ['executionDate' => '30-09-2026'], ['debtorAccountIban' => ' ']] as $bad) {
			$this->params = $bad + self::proposalForm();
			$response = $this->controller()->propose();
			$this->assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'invalid-request']], [$response->getStatus(), $response->getData()]);
		}
	}//end testProposeRejectsAnIncompleteForm()

	/**
	 * Propose passes the normalised form and the line-date mode, and maps the result.
	 *
	 * @return void
	 */
	public function testProposeAnswers(): void {
		$this->params = ['payOnDueDate' => 'true'] + self::proposalForm();
		$this->proposal->expects($this->exactly(2))->method('propose')
			->with('adm-1', '2026-10-01', 'NL91ABNA0417164300', '2026-09-30', PaymentRunProposalService::DATES_DUE)
			->willReturnOnConsecutiveCalls(
				['paymentRun' => ['id' => 'r'], 'skipped' => []],
				['paymentRun' => null, 'skipped' => ['a']]
			);
		$controller = $this->controller();

		$created = $controller->propose();
		$this->assertSame([Http::STATUS_CREATED, ['paymentRun' => ['id' => 'r'], 'skipped' => []]], [$created->getStatus(), $created->getData()]);
		$none = $controller->propose();
		$this->assertSame(
			[Http::STATUS_UNPROCESSABLE_ENTITY, ['paymentRun' => null, 'skipped' => ['a'], 'error' => 'nothing-due']],
			[$none->getStatus(), $none->getData()]
		);
	}//end testProposeAnswers()

	/**
	 * A failing proposal is a logged 500; without payOnDueDate the run date is used.
	 *
	 * @return void
	 */
	public function testProposeFailureIsLogged500(): void {
		$this->params = self::proposalForm();
		$this->proposal->expects($this->once())->method('propose')
			->with('adm-1', '2026-10-01', 'NL91ABNA0417164300', '2026-09-30', PaymentRunProposalService::DATES_RUN)
			->willThrowException(new \RuntimeException('down'));

		$response = $this->controller()->propose();

		$this->assertSame([Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'proposal-failed']], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['canAccess:adm-1', 'error:PaymentRunController: proposal failed{"exception":"down"}'], $this->calls);
	}//end testProposeFailureIsLogged500()
}//end class
