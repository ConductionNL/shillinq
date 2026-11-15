<?php

/**
 * shillinq.payment_run.* and shillinq.bank_* refuse where their REST twins do.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Mcp;

use DomainException;
use InvalidArgumentException;
use OCA\Shillinq\Controller\PaymentRunController;
use OCA\Shillinq\Controller\StatementBodyReader;
use OCA\Shillinq\Kernel\Contracts\PaymentRunExportServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentRunProposalServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentRunReconciliationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ReconciliationResolutionServiceInterface;
use OCA\Shillinq\Kernel\Contracts\StatementIntakeServiceInterface;
use OCA\Shillinq\Kernel\Contracts\StatementParserInterface;
use OCA\Shillinq\Mcp\McpAdministrationGate;
use OCA\Shillinq\Mcp\McpBankTools;
use OCA\Shillinq\Mcp\McpPaymentRunTools;
use OCA\Shillinq\Mcp\McpResult;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The payment-run tools against PaymentRunController, and the bank tools'
 * own refusals, over the same doubles.
 */
#[CoversClass(McpPaymentRunTools::class)]
#[CoversClass(McpBankTools::class)]
#[CoversClass(McpAdministrationGate::class)]
#[CoversClass(McpResult::class)]
#[UsesClass(PaymentRunController::class)]
#[UsesClass(StatementBodyReader::class)]
#[UsesClass(ObjectIdentifier::class)]
class McpPaymentRunAndBankToolsTest extends TestCase {

	/**
	 * HTTP status of the REST twin to the tool's outcome.
	 */
	private const OUTCOME = [200 => 'ok', 400 => 'invalid_arguments', 401 => 'unauthorised', 404 => 'not_found', 409 => 'conflict', 422 => 'invalid_arguments', 500 => 'failed'];

	/**
	 * The records the object-service double serves, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $records = [];

	/**
	 * The export situations.
	 *
	 * @return array<string, array{0: bool, 1: bool, 2: array<string, mixed>|null, 3: array<string, mixed>|\Throwable, 4: string}>
	 */
	public static function exports(): array {
		$run = ['id' => 'run-1', 'administrationId' => 'adm-1', 'status' => 'approved'];
		return [
			'exported'               => [true, true, $run, ['xml' => '<Document/>'], 'ok'],
			'not signed in'          => [false, true, $run, ['xml' => ''], 'unauthorised'],
			'foreign administration' => [true, false, $run, ['xml' => ''], 'not_found'],
			'run not found'          => [true, true, null, ['xml' => ''], 'not_found'],
			'not approved'           => [true, true, $run, ['error' => 'not-approved', 'message' => 'Run is not approved'], 'conflict'],
			'unprocessable'          => [true, true, $run, ['error' => 'no-lines'], 'invalid_arguments'],
			'unexpected failure'     => [true, true, $run, new RuntimeException('disk full'), 'failed'],
		];
	}//end exports()

	/**
	 * export_sepa: both surfaces agree on every situation.
	 *
	 * @param bool                                 $signedIn  Whether a user is signed in.
	 * @param bool                                 $canAccess Whether adm-1 is accessible.
	 * @param array<string, mixed>|null            $run       The stored run.
	 * @param array<string, mixed>|\Throwable      $answer    The export service's answer.
	 * @param string                               $expected  The outcome both must reach.
	 *
	 * @return void
	 */
	#[DataProvider('exports')]
	public function testExportAgreesWithTheController(bool $signedIn, bool $canAccess, ?array $run, array|\Throwable $answer, string $expected): void {
		if ($run !== null) {
			$this->records['run-1'] = $run;
		}

		$export = $this->createMock(PaymentRunExportServiceInterface::class);
		$exported = [];
		$export->method('export')->willReturnCallback(
			static function (array $paymentRun) use (&$exported, $answer): array {
				$exported[] = $paymentRun['id'];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		[$controller, $tools] = $this->paymentSurfaces(signedIn: $signedIn, canAccess: $canAccess, export: $export, params: []);

		$rest = self::OUTCOME[$controller->export('run-1')->getStatus()];
		$tool = $tools->exportSepa(['administrationId' => 'adm-1', 'paymentRunId' => 'run-1']);

		$this->assertSame($expected, $rest, 'the REST twin');
		$this->assertSame($expected, McpResult::isError($tool) === true ? $tool['error'] : 'ok', 'the tool');
		$reached = ($signedIn === true && $canAccess === true && $run !== null);
		$this->assertSame($reached === true ? ['run-1', 'run-1'] : [], $exported);
		if ($expected === 'ok') {
			$this->assertSame(['xml' => '<Document/>'], $tool);
		}
	}//end testExportAgreesWithTheController()

	/**
	 * A run of another administration than the one the call names is the masked not_found.
	 *
	 * @return void
	 */
	public function testARunOfAnotherAdministrationIsNotFound(): void {
		$this->records['run-1'] = ['id' => 'run-1', 'administrationId' => 'adm-2'];
		$export = $this->createMock(PaymentRunExportServiceInterface::class);
		$export->expects($this->never())->method('export');
		[, $tools] = $this->paymentSurfaces(signedIn: true, canAccess: true, export: $export, params: [], accessible: ['adm-1', 'adm-2']);

		$this->assertSame(McpResult::error(code: 'not_found', message: 'Not found'), $tools->exportSepa(['administrationId' => 'adm-1', 'paymentRunId' => 'run-1']));
	}//end testARunOfAnotherAdministrationIsNotFound()

	/**
	 * reconcile: an empty statement is refused, a not-exported run is a conflict, as in the controller.
	 *
	 * @return void
	 */
	public function testReconcileAgreesWithTheController(): void {
		$this->records['run-1'] = ['id' => 'run-1', 'administrationId' => 'adm-1'];
		$reconciliation = $this->createMock(PaymentRunReconciliationServiceInterface::class);
		$reconciliation->method('reconcile')->willReturnCallback(
			static fn (array $paymentRun, string $contents): array => $contents === 'camt'
				? ['matched' => 3]
				: ['error' => 'not-exported']
		);

		foreach (['camt' => 'ok', 'other' => 'conflict', '  ' => 'invalid_arguments'] as $contents => $expected) {
			[$controller, $tools] = $this->paymentSurfaces(
				signedIn: true,
				canAccess: true,
				export: $this->createMock(PaymentRunExportServiceInterface::class),
				params: ['contents' => $contents],
				reconciliation: $reconciliation
			);
			$tool = $tools->reconcile(['administrationId' => 'adm-1', 'paymentRunId' => 'run-1', 'contents' => $contents]);

			$this->assertSame($expected, self::OUTCOME[$controller->reconcile('run-1')->getStatus()], $contents);
			$this->assertSame($expected, McpResult::isError($tool) === true ? $tool['error'] : 'ok', $contents);
		}
	}//end testReconcileAgreesWithTheController()

	/**
	 * import: the parsed lines land in the named administration with the call's account details.
	 *
	 * @return void
	 */
	public function testImportIngestsIntoTheNamedAdministration(): void {
		$parser = $this->createMock(StatementParserInterface::class);
		$parser->expects($this->once())->method('parse')->with('file', 'mt940')->willReturn([['amount' => 10], ['amount' => -4]]);
		$intake = $this->createMock(StatementIntakeServiceInterface::class);
		$intake->expects($this->once())->method('ingest')
			->with('adm-1', 'NL91ABNA0417164300', [['amount' => 10], ['amount' => -4]], ['source' => 'file', 'format' => 'mt940', 'glAccountId' => '1100'])
			->willReturn(['statementId' => 'st-1', 'written' => ['l1', 'l2']]);
		$tools = $this->bankTools(canAccess: true, parser: $parser, intake: $intake);

		$result = $tools->importStatement(
			['administrationId' => 'adm-1', 'format' => 'mt940', 'contents' => 'file', 'bankAccountIban' => 'NL91ABNA0417164300', 'glAccountId' => '1100']
		);

		$this->assertSame(['statementId' => 'st-1', 'transactionCount' => 2, 'matchedCount' => 0, 'unmatchedCount' => 2], $result);
	}//end testImportIngestsIntoTheNamedAdministration()

	/**
	 * import refuses a foreign administration, an empty file, an unparsable file and invalid input, as the controller words them.
	 *
	 * @return void
	 */
	public function testImportRefusals(): void {
		$args = ['administrationId' => 'adm-1', 'format' => 'csv', 'contents' => 'x'];
		$intake = $this->createMock(StatementIntakeServiceInterface::class);
		$intake->expects($this->never())->method('ingest');

		$foreign = $this->bankTools(canAccess: false, parser: $this->createMock(StatementParserInterface::class), intake: $intake);
		$this->assertSame('not_found', $foreign->importStatement($args)['error']);

		$parser = $this->createMock(StatementParserInterface::class);
		$parser->method('parse')->willReturnCallback(
			static function (string $contents): array {
				if ($contents === 'bad') {
					throw new InvalidArgumentException('broken');
				}

				return [];
			}
		);
		$tools = $this->bankTools(canAccess: true, parser: $parser, intake: $intake);
		$this->assertSame(McpResult::error(code: 'invalid_arguments', message: 'Empty file — nothing to import'), $tools->importStatement(['contents' => ' '] + $args));
		$this->assertSame(
			McpResult::error(code: 'invalid_arguments', message: 'Could not parse any transactions from the supplied file'),
			$tools->importStatement($args)
		);
		$this->assertSame(McpResult::error(code: 'invalid_arguments', message: 'Invalid bank statement import request'), $tools->importStatement(['contents' => 'bad'] + $args));
	}//end testImportRefusals()

	/**
	 * resolve: each match is resolved as the signed-in user, failures are reported per match with the controller's wording.
	 *
	 * @return void
	 */
	public function testResolveAppliesEachMatchAndReportsFailures(): void {
		$this->records['rec-1'] = ['id' => 'rec-1', 'administrationId' => 'adm-1'];
		$resolution = $this->createMock(ReconciliationResolutionServiceInterface::class);
		$calls = [];
		$resolution->method('resolveMatch')->willReturnCallback(
			static function (string $reconId, string $matchId, string $status, string $reason, string $actor) use (&$calls): array {
				$calls[] = [$reconId, $matchId, $status, $reason, $actor];
				return match ($matchId) {
					'm-2' => throw new OutOfBoundsException('match m-2 not found'),
					'm-3' => throw new DomainException('reconciliation is closed'),
					'm-4' => throw new RuntimeException('db'),
					default => ['id' => $matchId],
				};
			}
		);
		$tools = $this->bankTools(canAccess: true, resolution: $resolution);

		$result = $tools->resolve(
			['administrationId' => 'adm-1', 'reconId' => 'rec-1', 'matchIds' => ['m-1', 'm-2', 'm-3', 'm-4'], 'resolutionStatus' => 'timing', 'resolutionReason' => ' Paid next day ']
		);

		$this->assertSame(
			[
				'applied' => 1,
				'failed' => [
					'm-2' => 'Unable to find the reconciliation match',
					'm-3' => 'Unable to resolve a match on a locked reconciliation',
					'm-4' => 'Unable to resolve this match',
				],
			],
			$result
		);
		$this->assertSame(['rec-1', 'm-1', 'timing', 'Paid next day', 'alice'], $calls[0]);
		$this->assertCount(4, $calls);
	}//end testResolveAppliesEachMatchAndReportsFailures()

	/**
	 * resolve refuses a missing reason, a missing reconciliation and one of another administration before any match.
	 *
	 * @return void
	 */
	public function testResolveRefusals(): void {
		$this->records['rec-2'] = ['id' => 'rec-2', 'administrationId' => 'adm-2'];
		$resolution = $this->createMock(ReconciliationResolutionServiceInterface::class);
		$resolution->expects($this->never())->method('resolveMatch');
		$tools = $this->bankTools(canAccess: true, resolution: $resolution, accessible: ['adm-1', 'adm-2']);
		$args = ['administrationId' => 'adm-1', 'matchIds' => ['m-1'], 'resolutionStatus' => 'matched', 'resolutionReason' => 'ok'];

		$this->assertSame('invalid_arguments', $tools->resolve(['reconId' => 'rec-2', 'resolutionReason' => ' '] + $args)['error']);
		$this->assertSame(McpResult::error(code: 'not_found', message: McpBankTools::RECONCILIATION_NOT_FOUND), $tools->resolve(['reconId' => 'rec-9'] + $args));
		$this->assertSame(McpResult::error(code: 'not_found', message: McpBankTools::RECONCILIATION_NOT_FOUND), $tools->resolve(['reconId' => 'rec-2'] + $args));
	}//end testResolveRefusals()

	/**
	 * The payment-run controller and tools over the same doubles.
	 *
	 * @param bool                                          $signedIn       Whether a user is signed in.
	 * @param bool                                          $canAccess      Whether the listed administrations are accessible.
	 * @param PaymentRunExportServiceInterface              $export         The export service.
	 * @param array<string, mixed>                          $params         The REST request's parameters.
	 * @param PaymentRunReconciliationServiceInterface|null $reconciliation The reconciliation service.
	 * @param list<string>                                  $accessible     The administrations the caller may reach.
	 *
	 * @return array{0: PaymentRunController, 1: McpPaymentRunTools}
	 */
	private function paymentSurfaces(
		bool $signedIn,
		bool $canAccess,
		PaymentRunExportServiceInterface $export,
		array $params,
		?PaymentRunReconciliationServiceInterface $reconciliation = null,
		array $accessible = ['adm-1'],
	): array {
		$reconciliation ??= $this->createMock(PaymentRunReconciliationServiceInterface::class);
		[$session, $context] = $this->caller(signedIn: $signedIn, canAccess: $canAccess, accessible: $accessible);
		$objects = new DuckObjectServiceAdapter($this->store());
		$logger = $this->createMock(LoggerInterface::class);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$request->method('getUploadedFile')->willReturn(null);

		$controller = new PaymentRunController(
			$request,
			$export,
			$reconciliation,
			$context,
			$session,
			$logger,
			$objects,
			$this->createMock(PaymentRunProposalServiceInterface::class)
		);
		$tools = new McpPaymentRunTools(new McpAdministrationGate($session, $context), $export, $reconciliation, $objects, $logger);

		return [$controller, $tools];
	}//end paymentSurfaces()

	/**
	 * The bank tools for a signed-in caller.
	 *
	 * @param bool                                          $canAccess  Whether the listed administrations are accessible.
	 * @param StatementParserInterface|null                 $parser     The parser.
	 * @param StatementIntakeServiceInterface|null          $intake     The intake.
	 * @param ReconciliationResolutionServiceInterface|null $resolution The resolution service.
	 * @param list<string>                                  $accessible The administrations the caller may reach.
	 *
	 * @return McpBankTools
	 */
	private function bankTools(
		bool $canAccess,
		?StatementParserInterface $parser = null,
		?StatementIntakeServiceInterface $intake = null,
		?ReconciliationResolutionServiceInterface $resolution = null,
		array $accessible = ['adm-1'],
	): McpBankTools {
		[$session, $context] = $this->caller(signedIn: true, canAccess: $canAccess, accessible: $accessible);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new McpBankTools(
			new McpAdministrationGate($session, $context),
			($parser ?? $this->createMock(StatementParserInterface::class)),
			($intake ?? $this->createMock(StatementIntakeServiceInterface::class)),
			($resolution ?? $this->createMock(ReconciliationResolutionServiceInterface::class)),
			new DuckObjectServiceAdapter($this->store()),
			$appConfig,
			$this->createMock(LoggerInterface::class)
		);
	}//end bankTools()

	/**
	 * A session and administration context for a caller.
	 *
	 * @param bool         $signedIn   Whether a user is signed in.
	 * @param bool         $canAccess  Whether the listed administrations are accessible.
	 * @param list<string> $accessible The administrations the caller may reach.
	 *
	 * @return array{0: IUserSession, 1: AdministrationContextService}
	 */
	private function caller(bool $signedIn, bool $canAccess, array $accessible): array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $canAccess === true && in_array($id, $accessible, true));

		return [$session, $context];
	}//end caller()

	/**
	 * A duck-typed object store over {@see $records}; find() throws on a miss, as the real one does.
	 *
	 * @return object
	 */
	private function store(): object {
		return new class($this) {
			/**
			 * Constructor.
			 *
			 * @param McpPaymentRunAndBankToolsTest $test The test whose records it serves.
			 */
			public function __construct(private readonly McpPaymentRunAndBankToolsTest $test) {
			}

			/**
			 * Fluent register selector.
			 *
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * Fluent schema selector.
			 *
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;
			}

			/**
			 * The record by id.
			 *
			 * @param string $id The id.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id): ?array {
				return ($this->test->records[$id] ?? null);
			}
		};
	}//end store()
}//end class
