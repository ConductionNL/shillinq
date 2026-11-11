<?php

/**
 * Golden tests for BankStatementImportController::import outcomes.
 *
 * Pins the exact bodies, the parser and intake arguments, the administration
 * fallback and the failure log entries before the action is split.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BankStatementImportController;
use OCA\Shillinq\Lifecycle\StatementParser;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Bank\StatementIntakeService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Exact outcomes of the bank statement import endpoint.
 */
final class BankStatementImportGoldenTest extends TestCase {
	/** @var array<string, mixed> Request parameters. */
	private array $params = [];

	/** @var mixed The uploaded file entry. */
	private mixed $upload = null;

	/** @var bool Whether a user is logged in. */
	private bool $loggedIn = true;

	/** @var array<string, mixed>|\Throwable buildContext result. */
	private array|\Throwable $built = ['activeAdministrationId' => 'adm-1'];

	/** @var list<array<string, mixed>> Parsed lines. */
	private array $parsed = [['amount' => 1], ['amount' => 2]];

	/** @var \Throwable|null Failure of the parser. */
	private ?\Throwable $parseFailure = null;

	/** @var \Throwable|null Failure of the intake. */
	private ?\Throwable $intakeFailure = null;

	/** @var list<string> Calls made, in order. */
	private array $calls = [];

	/** @var list<array{0: string, 1: array}> Logged errors. */
	private array $logged = [];

	/**
	 * Build the controller over recording doubles.
	 *
	 * @return BankStatementImportController
	 */
	private function controller(): BankStatementImportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $name, mixed $default = null): mixed => $this->params[$name] ?? $default
		);
		$request->method('getUploadedFile')->willReturnCallback(fn () => $this->upload);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn () => $this->loggedIn === true ? $this->createMock(IUser::class) : null);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('buildContext')->willReturnCallback(function () {
			$this->calls[] = 'buildContext';
			if ($this->built instanceof \Throwable) {
				throw $this->built;
			}
			return $this->built;
		});
		$parser = $this->createMock(StatementParser::class);
		$parser->method('parse')->willReturnCallback(function (string $contents, string $format) {
			$this->calls[] = 'parse:'.$contents.':'.$format;
			if ($this->parseFailure !== null) {
				throw $this->parseFailure;
			}
			return $this->parsed;
		});
		$intake = $this->createMock(StatementIntakeService::class);
		$intake->method('ingest')->willReturnCallback(function (string $administrationId, string $iban, array $lines, array $meta) {
			$this->calls[] = 'ingest:'.$administrationId.':'.$iban.':'.count($lines).':'.json_encode($meta);
			if ($this->intakeFailure !== null) {
				throw $this->intakeFailure;
			}
			return ['statementId' => 'st-1', 'written' => ['a', 'b', 'c']];
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context = []) {
			$this->logged[] = [$message, $context];
		});
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text) => 'T:'.$text);

		return new BankStatementImportController(
			request: $request,
			parser: $parser,
			administrationContext: $context,
			session: $session,
			logger: $logger,
			l10n: $l10n,
			intake: $intake,
		);
	}//end controller()

	/**
	 * Anonymous callers are refused before anything is read.
	 *
	 * @return void
	 */
	public function testAnonymous(): void {
		$this->loggedIn = false;
		$response = $this->controller()->import();
		$this->assertSame(
			[401, ['message' => 'T:Not logged in', 'error' => 'bank-statement-import-unauthenticated']],
			[$response->getStatus(), $response->getData()]
		);
		$this->assertSame([], $this->calls);
	}//end testAnonymous()

	/**
	 * Refusals of the payload, in check order.
	 *
	 * @return array<string, array>
	 */
	public static function refusals(): array {
		return [
			'no format' => [['contents' => 'x'], 400, ['error' => 'Unsupported or missing format (expected camt053, mt940 or csv)'], ['buildContext']],
			'bad format' => [['format' => 'xml', 'contents' => ''], 400, ['error' => 'Unsupported or missing format (expected camt053, mt940 or csv)'], ['buildContext']],
			'empty contents' => [['format' => ' CSV ', 'contents' => "  \n"], 400, ['error' => 'Empty file — nothing to import'], ['buildContext']],
		];
	}//end refusals()

	/**
	 * Each refusal answers its exact body.
	 *
	 * @dataProvider refusals
	 *
	 * @param array<string, mixed> $params Request parameters.
	 * @param int                  $status Expected status.
	 * @param array<string, mixed> $body   Expected body.
	 * @param list<string>         $calls  Expected calls.
	 *
	 * @return void
	 */
	public function testRefusals(array $params, int $status, array $body, array $calls): void {
		$this->params = $params;
		$response = $this->controller()->import();
		$this->assertSame([$status, $body], [$response->getStatus(), $response->getData()]);
		$this->assertSame($calls, $this->calls);
	}//end testRefusals()

	/**
	 * Nothing parsed is a 422 before the intake.
	 *
	 * @return void
	 */
	public function testNothingParsed(): void {
		$this->params = ['format' => 'mt940', 'contents' => 'raw'];
		$this->parsed = [];
		$response = $this->controller()->import();
		$this->assertSame(
			[422, ['error' => 'Could not parse any transactions from the supplied file']],
			[$response->getStatus(), $response->getData()]
		);
		$this->assertSame(['buildContext', 'parse:raw:mt940'], $this->calls);
	}//end testNothingParsed()

	/**
	 * The happy path from plain parameters, and a non-uploaded file entry is ignored.
	 *
	 * @return void
	 */
	public function testImported(): void {
		$this->params = ['format' => ' CAMT053 ', 'contents' => ' raw ', 'glAccountId' => ' gl-1 ', 'bankAccountIban' => ' NL01 '];
		$this->upload = ['tmp_name' => __FILE__];
		$response = $this->controller()->import();
		$this->assertSame(
			[200, ['statementId' => 'st-1', 'transactionCount' => 3, 'matchedCount' => 0, 'unmatchedCount' => 3]],
			[$response->getStatus(), $response->getData()]
		);
		$this->assertSame(
			['buildContext', 'parse: raw :camt053', 'ingest:adm-1:NL01:2:'.json_encode(['source' => 'file', 'format' => 'camt053', 'glAccountId' => 'gl-1'])],
			$this->calls
		);
	}//end testImported()

	/**
	 * The administration falls back to 'default' when none is active or the lookup fails.
	 *
	 * @return void
	 */
	public function testAdministrationFallback(): void {
		$this->params = ['format' => 'csv', 'contents' => 'raw'];
		foreach ([[], ['activeAdministrationId' => ''], new \RuntimeException('no context')] as $built) {
			$this->built = $built;
			$this->calls = [];
			$this->controller()->import();
			$this->assertSame('ingest:default::2:'.json_encode(['source' => 'file', 'format' => 'csv', 'glAccountId' => '']), $this->calls[2]);
		}
	}//end testAdministrationFallback()

	/**
	 * Invalid-input and other failures.
	 *
	 * @return void
	 */
	public function testFailures(): void {
		$this->params = ['format' => 'csv', 'contents' => 'raw'];
		$this->intakeFailure = new \InvalidArgumentException('bad iban');
		$response = $this->controller()->import();
		$this->assertSame(
			[400, ['message' => 'T:Invalid bank statement import request', 'error' => 'bank-statement-import-invalid-input']],
			[$response->getStatus(), $response->getData()]
		);
		$this->assertSame([['BankStatementImportController.import failed', ['exception' => $this->intakeFailure]]], $this->logged);

		$this->logged = [];
		$this->intakeFailure = null;
		$this->parseFailure = new \RuntimeException('parser down');
		$response = $this->controller()->import();
		$this->assertSame(
			[500, ['message' => 'T:Unable to import bank statement', 'error' => 'bank-statement-import-failed']],
			[$response->getStatus(), $response->getData()]
		);
		$this->assertSame([['BankStatementImportController.import failed', ['exception' => $this->parseFailure]]], $this->logged);
	}//end testFailures()
}//end class
