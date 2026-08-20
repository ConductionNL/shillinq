<?php

/**
 * Unit tests for BankStatementImportController.
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
 * @spec openspec/changes/shillinq-bank-statement-wizard/specs/shillinq-bank-statement-wizard/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BankStatementImportController;
use OCA\Shillinq\Lifecycle\StatementParser;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BankStatementImportController::import per REQ-BSW-004.
 *
 * The StatementParser is mocked to return fixed line arrays so the test
 * pins the controller's own parse→map→count + persistence + IDOR behaviour
 * independently of the (separately-tested) parser internals.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class BankStatementImportControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock StatementParser.
	 *
	 * @var StatementParser&MockObject
	 */
	private StatementParser&MockObject $parser;

	/**
	 * Mock AdministrationContextService.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $adminContext;

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock IL10N.
	 *
	 * @var IL10N&MockObject
	 */
	private IL10N&MockObject $l10n;

	/**
	 * The capturing ObjectService stub the container returns.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->parser = $this->createMock(StatementParser::class);
		$this->adminContext = $this->createMock(AdministrationContextService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $text, $params = []): string => $text);

		$this->objectService = $this->buildObjectServiceStub();
		$this->container->method('get')->willReturn($this->objectService);

	}//end setUp()

	/**
	 * A capturing fluent ObjectService stub: records every saveObject call and
	 * returns the created object with a generated id.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(): object {
		return new class {
			/**
			 * Recorded saveObject calls, keyed by schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $saved = [];

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Auto-increment id counter.
			 *
			 * @var int
			 */
			private int $seq = 0;

			/**
			 * Fluent register setter.
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
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Record + echo back the saved object with a generated id.
			 *
			 * @param array<string,mixed> $data Object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $data): array {
				$this->seq++;
				$saved = array_merge(['id' => 'obj-' . $this->seq], $data);
				$this->saved[$this->schema][] = $saved;
				return $saved;
			}//end saveObject()
		};
	}//end buildObjectServiceStub()

	/**
	 * Construct the controller under test.
	 *
	 * @return BankStatementImportController
	 */
	private function controller(): BankStatementImportController {
		return new BankStatementImportController(
			request: $this->request,
			parser: $this->parser,
			administrationContext: $this->adminContext,
			session: $this->session,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($this->objectService),
			l10n: $this->l10n,
		);
	}//end controller()

	/**
	 * Wire request params (format + glAccountId) returned by getParam().
	 *
	 * @param string $format Format value.
	 * @param string $glAccountId GL account value.
	 * @param string $contents Raw file contents param.
	 *
	 * @return void
	 */
	private function withParams(string $format, string $glAccountId = '', string $contents = 'dummy-statement-body'): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = '') use ($format, $glAccountId, $contents) {
				if ($key === 'format') {
					return $format;
				}

				if ($key === 'glAccountId') {
					return $glAccountId;
				}

				if ($key === 'contents') {
					return $contents;
				}

				return $default;
			}
		);
		$this->request->method('getUploadedFile')->willReturn(null);
	}//end withParams()

	/**
	 * Two fixed parsed CAMT.053 lines (parser-shaped: status/remittanceInfo/endToEndRef).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function twoParsedLines(): array {
		return [
			[
				'valueDate' => '2026-05-01',
				'amount' => 1210.00,
				'currency' => 'EUR',
				'remittanceInfo' => 'Factuur 2026-0042',
				'endToEndRef' => 'E2E-1',
				'status' => 'unmatched',
			],
			[
				'valueDate' => '2026-05-02',
				'amount' => -300.50,
				'currency' => 'EUR',
				'remittanceInfo' => 'Huur mei',
				'counterpartyName' => 'Verhuur B.V.',
				'counterpartyIban' => 'NL00BANK0987654321',
				'status' => 'unmatched',
			],
		];
	}//end twoParsedLines()

	/**
	 * REQ-BSW-004: a valid CAMT.053 body creates a BankStatement + N lines and
	 * returns the right counts (matched 0, unmatched N).
	 *
	 * @return void
	 */
	public function testValidImportCreatesStatementAndLines(): void {
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->adminContext->method('buildContext')->willReturn(['activeAdministrationId' => 'admin-7']);
		$this->withParams(format: 'camt053');
		$this->parser->method('parse')->willReturn($this->twoParsedLines());

		$response = $this->controller()->import();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		self::assertSame(2, $data['transactionCount']);
		self::assertSame(0, $data['matchedCount']);
		self::assertSame(2, $data['unmatchedCount']);
		self::assertNotSame('', (string)$data['statementId']);

		// One BankStatement persisted, scoped to the server-resolved admin.
		self::assertCount(1, $this->objectService->saved['BankStatement']);
		$statement = $this->objectService->saved['BankStatement'][0];
		self::assertSame('admin-7', $statement['administrationId']);
		self::assertSame('camt053', $statement['statementFormat']);
		self::assertSame(2, $statement['transactionCount']);

		// Two BankStatementLine rows, mapped parser keys → schema fields.
		self::assertCount(2, $this->objectService->saved['BankStatementLine']);
		$line1 = $this->objectService->saved['BankStatementLine'][0];
		self::assertSame(1, $line1['lineNumber']);
		self::assertSame('unmatched', $line1['matchState']);
		self::assertSame('Factuur 2026-0042', $line1['narrative']);
		self::assertSame('E2E-1', $line1['reference']);
		self::assertSame('admin-7', $line1['administrationId']);
		$line2 = $this->objectService->saved['BankStatementLine'][1];
		self::assertSame(2, $line2['lineNumber']);
		self::assertSame('NL00BANK0987654321', $line2['counterpartyIban']);

	}//end testValidImportCreatesStatementAndLines()

	/**
	 * REQ-BSW-004: empty/unparseable input returns 422 and persists nothing.
	 *
	 * @return void
	 */
	public function testUnparseableInputReturns422(): void {
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->adminContext->method('buildContext')->willReturn(['activeAdministrationId' => 'admin-7']);
		$this->withParams(format: 'camt053');
		$this->parser->method('parse')->willReturn([]);

		$response = $this->controller()->import();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame([], ($this->objectService->saved['BankStatement'] ?? []));

	}//end testUnparseableInputReturns422()

	/**
	 * REQ-BSW-004: a missing/unsupported format returns 400 (bad input).
	 *
	 * @return void
	 */
	public function testUnsupportedFormatReturns400(): void {
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->adminContext->method('buildContext')->willReturn(['activeAdministrationId' => 'admin-7']);
		$this->withParams(format: 'ofx');

		$response = $this->controller()->import();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testUnsupportedFormatReturns400()

	/**
	 * Anonymous caller is rejected (401).
	 *
	 * @return void
	 */
	public function testAnonymousReturns401(): void {
		$this->session->method('getUser')->willReturn(null);

		$response = $this->controller()->import();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnonymousReturns401()

	/**
	 * ADR-005 / REQ-BSW-004: the administration is server-resolved, never read
	 * from a client-supplied administrationId. Even when the request body would
	 * carry an attacker administrationId, the persisted objects use the
	 * AdministrationContextService value.
	 *
	 * @return void
	 */
	public function testNeverTrustsClientAdministrationId(): void {
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
		// The server resolves a DIFFERENT admin than any client value.
		$this->adminContext->method('buildContext')->willReturn(['activeAdministrationId' => 'server-admin']);
		$this->withParams(format: 'csv');
		$this->parser->method('parse')->willReturn($this->twoParsedLines());

		$response = $this->controller()->import();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		foreach ($this->objectService->saved['BankStatement'] as $statement) {
			self::assertSame('server-admin', $statement['administrationId']);
		}

		foreach ($this->objectService->saved['BankStatementLine'] as $line) {
			self::assertSame('server-admin', $line['administrationId']);
		}

	}//end testNeverTrustsClientAdministrationId()
}//end class
