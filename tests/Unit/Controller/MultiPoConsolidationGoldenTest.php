<?php

/**
 * Golden tests for MultiPoConsolidationController.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\MultiPoConsolidationController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\MultiPoConsolidationService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the status, body, log line and calls of every branch of the three
 * multi-PO consolidation endpoints before they are split into named steps.
 */
final class MultiPoConsolidationGoldenTest extends TestCase {
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
	 * SupplierInvoice rows findAll() returns, or null to make it throw.
	 *
	 * @var array<int, mixed>|null
	 */
	private ?array $invoiceRows = [];

	/**
	 * Recorded calls, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * The consolidation service mock.
	 *
	 * @var MultiPoConsolidationService&MockObject
	 */
	private MultiPoConsolidationService&MockObject $consolidation;

	/**
	 * Build the service mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->consolidation = $this->createMock(MultiPoConsolidationService::class);
	}//end setUp()

	/**
	 * Build the controller over the current fixture state.
	 *
	 * @return MultiPoConsolidationController
	 */
	private function controller(): MultiPoConsolidationController {
		$calls = &$this->calls;
		$rows = $this->invoiceRows;
		$objects = new class($rows, $calls) {
			/**
			 * @param array<int,mixed>|null $rows  The rows (null = throw).
			 * @param array<int,string>     $calls The call log.
			 */
			public function __construct(
				private ?array $rows,
				private array &$calls,
			) {
			}//end __construct()

			public function setRegister(string $r): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $s): self {
				$this->calls[] = 'schema:' . $s;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $query The query.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $query): array {
				$this->calls[] = 'findAll:' . json_encode($query);
				if ($this->rows === null) {
					throw new \LogicException('store down');
				}

				return $this->rows;
			}//end findAll()
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($id === IAppConfig::class ? $appConfig : $objects)
		);

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
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []) use (&$calls): void {
				$calls[] = 'error:' . $message . json_encode($context);
			}
		);

		return new MultiPoConsolidationController($request, $this->consolidation, $container, $context, $session, $logger);
	}//end controller()

	/**
	 * Call one action.
	 *
	 * @param string $action consolidate, candidates or disambiguate.
	 *
	 * @return array{0: int, 1: mixed} Status and body.
	 */
	private function call(string $action): array {
		$response = $this->controller()->$action();
		return [$response->getStatus(), $response->getData()];
	}//end call()

	/**
	 * A complete form for every action.
	 *
	 * @return array<string, mixed>
	 */
	private static function form(): array {
		return [
			'administrationId' => ' adm-1 ',
			'invoiceId' => 'inv-1',
			'invoiceLineNumber' => '2',
			'chosenPoLineId' => ' pol-1 ',
			'chosenGrnLineId' => ' grn-1 ',
		];
	}//end form()

	/**
	 * Every refusal before the service is reached, with the access checks made.
	 *
	 * @param string              $action  The action.
	 * @param bool                $user    Logged in.
	 * @param bool                $access  canAccess() answer.
	 * @param array<string,mixed> $changes Form overrides.
	 * @param int                 $status  Expected status.
	 * @param string              $error   Expected error.
	 * @param array<int,string>   $calls   Expected calls.
	 *
	 * @return void
	 *
	 * @dataProvider refusals
	 */
	public function testRefusals(string $action, bool $user, bool $access, array $changes, int $status, string $error, array $calls): void {
		$this->loggedIn = $user;
		$this->canAccess = $access;
		$this->params = $changes + self::form();
		$this->consolidation->expects($this->never())->method($this->anything());

		$this->assertSame([$status, ['error' => $error]], $this->call(action: $action));
		$this->assertSame($calls, $this->calls);
	}//end testRefusals()

	/**
	 * The refusal cases.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function refusals(): array {
		$cases = [];
		foreach (['consolidate', 'candidates', 'disambiguate'] as $action) {
			$cases[$action . ' anonymous'] = [$action, false, true, [], 401, 'Not logged in', []];
			$cases[$action . ' no administration'] = [$action, true, true, ['administrationId' => ' '], 400, 'administrationId is required', []];
			$cases[$action . ' malformed administration'] = [$action, true, true, ['administrationId' => 'a b'], 400, 'administrationId is required', []];
			$cases[$action . ' no access'] = [$action, true, false, [], 404, 'Administration not found', ['canAccess:adm-1']];
			$cases[$action . ' no invoice'] = [$action, true, true, ['invoiceId' => ''], 400, 'invoiceId is required', ['canAccess:adm-1']];
		}

		foreach (['candidates', 'disambiguate'] as $action) {
			$cases[$action . ' no line'] = [$action, true, true, ['invoiceLineNumber' => '0'], 400, 'invoiceLineNumber is required', ['canAccess:adm-1']];
		}

		$cases['no po line'] = ['disambiguate', true, true, ['chosenPoLineId' => ' '], 400, 'chosenPoLineId is required', ['canAccess:adm-1']];
		$cases['malformed po line'] = ['disambiguate', true, true, ['chosenPoLineId' => 'a/b'], 400, 'chosenPoLineId is required', ['canAccess:adm-1']];
		$cases['malformed grn line'] = ['disambiguate', true, true, ['chosenGrnLineId' => 'a/b'], 400, 'chosenGrnLineId is malformed', ['canAccess:adm-1']];

		return $cases;
	}//end refusals()

	/**
	 * consolidate passes the scope and answers the lines; a missing GRN line id is allowed for disambiguate.
	 *
	 * @return void
	 */
	public function testConsolidateAndDisambiguateSucceed(): void {
		$this->params = ['chosenGrnLineId' => ''] + self::form();
		$this->consolidation->expects($this->once())->method('consolidateInvoice')->with('adm-1', 'inv-1')->willReturn([['line' => 1]]);
		$this->consolidation->expects($this->once())->method('disambiguateAmbiguousMatches')
			->with('adm-1', 'inv-1', 2, 'pol-1', '')
			->willReturn(['id' => 'm-1']);

		$this->assertSame([200, ['lines' => [['line' => 1]]]], $this->call(action: 'consolidate'));
		$this->assertSame([201, ['id' => 'm-1']], $this->call(action: 'disambiguate'));
	}//end testConsolidateAndDisambiguateSucceed()

	/**
	 * A RuntimeException naming 'not found' is a 404, any other a 400, both with its message.
	 *
	 * @return void
	 */
	public function testRuntimeExceptionsAreMapped(): void {
		$this->params = self::form();
		$this->consolidation->method('consolidateInvoice')->willThrowException(new RuntimeException('Invoice not found'));
		$this->consolidation->method('disambiguateAmbiguousMatches')->willThrowException(new RuntimeException('not in the candidate set'));

		$this->assertSame([404, ['error' => 'Invoice not found']], $this->call(action: 'consolidate'));
		$this->assertSame([400, ['error' => 'not in the candidate set']], $this->call(action: 'disambiguate'));
	}//end testRuntimeExceptionsAreMapped()

	/**
	 * Any other failure is a 500 with a fixed error and a logged context.
	 *
	 * @return void
	 */
	public function testFailuresAreLogged(): void {
		$this->params = self::form();
		$this->invoiceRows = [['id' => 'inv-1', 'lines' => [['lineNumber' => 2, 'amount' => 5]]]];
		$boom = new \LogicException('boom');
		$this->consolidation->method('consolidateInvoice')->willThrowException($boom);
		$this->consolidation->method('enumerateCandidateTuples')->willThrowException($boom);
		$this->consolidation->method('disambiguateAmbiguousMatches')->willThrowException($boom);

		$this->assertSame([500, ['error' => 'Could not consolidate invoice']], $this->call(action: 'consolidate'));
		$this->assertSame([500, ['error' => 'Could not enumerate candidates']], $this->call(action: 'candidates'));
		$this->assertSame([500, ['error' => 'Could not persist disambiguation choice']], $this->call(action: 'disambiguate'));

		$errors = array_values(array_filter($this->calls, static fn (string $c): bool => str_starts_with($c, 'error:')));
		$this->assertSame(
			[
				'error:MultiPoConsolidationController: consolidate failed{"administrationId":"adm-1","invoiceId":"inv-1","exception":"boom"}',
				'error:MultiPoConsolidationController: candidates failed{"administrationId":"adm-1","invoiceId":"inv-1","invoiceLineNumber":2,"exception":"boom"}',
				'error:MultiPoConsolidationController: disambiguate failed{"administrationId":"adm-1","invoiceId":"inv-1","invoiceLineNumber":2,"exception":"boom"}',
			],
			$errors
		);
	}//end testFailuresAreLogged()

	/**
	 * A failing invoice lookup is logged and answers 404; so does an unknown invoice.
	 *
	 * @return void
	 */
	public function testCandidatesWithoutAnInvoice(): void {
		$this->params = self::form();
		$this->consolidation->expects($this->never())->method('enumerateCandidateTuples');

		$this->invoiceRows = null;
		$this->assertSame([404, ['error' => 'Supplier invoice not found']], $this->call(action: 'candidates'));
		$this->assertSame(
			[
				'canAccess:adm-1',
				'schema:SupplierInvoice',
				'findAll:{"filters":{"id":"inv-1","administrationId":"adm-1"}}',
				'error:MultiPoConsolidationController: invoice lookup failed{"administrationId":"adm-1","invoiceId":"inv-1","exception":"store down"}',
			],
			$this->calls
		);

		$this->invoiceRows = ['not-a-row'];
		$this->assertSame([404, ['error' => 'Supplier invoice not found']], $this->call(action: 'candidates'));
	}//end testCandidatesWithoutAnInvoice()

	/**
	 * The line is found by lineNumber or by position, under lines or invoiceLines; a missing one is a 404.
	 *
	 * @return void
	 */
	public function testCandidatesLocateTheLine(): void {
		$this->params = self::form();
		$byPosition = ['id' => 'inv-1', 'invoiceLines' => ['skip-me', ['amount' => 7]]];
		$this->invoiceRows = ['junk', $byPosition];
		$this->consolidation->expects($this->once())->method('enumerateCandidateTuples')
			->with('adm-1', $byPosition, ['amount' => 7])
			->willReturn([['po' => 'p']]);

		$this->assertSame(
			[200, ['invoiceId' => 'inv-1', 'invoiceLineNumber' => 2, 'candidates' => [['po' => 'p']]]],
			$this->call(action: 'candidates')
		);

		$this->invoiceRows = [['id' => 'inv-1', 'lines' => [['lineNumber' => 9]]]];
		$this->assertSame([404, ['error' => 'Invoice line not found']], $this->call(action: 'candidates'));
		$this->invoiceRows = [['id' => 'inv-1', 'lines' => 'none']];
		$this->assertSame([404, ['error' => 'Invoice line not found']], $this->call(action: 'candidates'));
	}//end testCandidatesLocateTheLine()
}//end class
