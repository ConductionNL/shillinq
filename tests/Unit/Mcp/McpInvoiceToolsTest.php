<?php

/**
 * shillinq.invoice.* and shillinq.recurring_invoice.generate.
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

use OCA\Shillinq\Controller\ARInvoiceEInvoiceController;
use OCA\Shillinq\Kernel\Contracts\BillableLinesServiceInterface;
use OCA\Shillinq\Kernel\Contracts\EInvoiceServiceInterface;
use OCA\Shillinq\Kernel\Contracts\InvoiceGenerationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\InvoicePdfGeneratorInterface;
use OCA\Shillinq\Mcp\McpAdministrationGate;
use OCA\Shillinq\Mcp\McpInvoiceTools;
use OCA\Shillinq\Mcp\McpResult;
use OCA\Shillinq\Request\InvoiceGenerationRequest;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\RecurringInvoiceGenerator;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Each invoicing tool runs its service only for a record of the named,
 * accessible administration, and maps the service's refusals as its REST
 * twin does.
 */
#[CoversClass(McpInvoiceTools::class)]
#[CoversClass(McpAdministrationGate::class)]
#[CoversClass(McpResult::class)]
#[UsesClass(InvoiceGenerationRequest::class)]
#[UsesClass(ObjectIdentifier::class)]
#[UsesClass(ARInvoiceEInvoiceController::class)]
class McpInvoiceToolsTest extends TestCase {

	/**
	 * HTTP status of the REST twin to the tool's outcome.
	 */
	private const OUTCOME = [200 => 'ok', 400 => 'invalid_arguments', 401 => 'unauthorised', 404 => 'not_found', 500 => 'failed'];

	private const DRAFT = [
		'administrationId' => 'adm-1',
		'billingModel' => 'fixed_fee',
		'customerId' => 'cus-1',
		'fromDate' => '2026-10-01',
		'toDate' => '2026-10-31',
		'fixedFeeCents' => 50000,
	];

	/**
	 * The records the object-service double serves, by schema and id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $records = [];

	/**
	 * The schema the object-service double was last scoped to.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * The generation-service double.
	 *
	 * @var InvoiceGenerationServiceInterface&MockObject
	 */
	private InvoiceGenerationServiceInterface $generation;

	/**
	 * The billable-lines double.
	 *
	 * @var BillableLinesServiceInterface&MockObject
	 */
	private BillableLinesServiceInterface $billableLines;

	/**
	 * The renderer double.
	 *
	 * @var InvoicePdfGeneratorInterface&MockObject
	 */
	private InvoicePdfGeneratorInterface $pdf;

	/**
	 * The e-invoice double.
	 *
	 * @var EInvoiceServiceInterface&MockObject
	 */
	private EInvoiceServiceInterface $eInvoices;

	/**
	 * The recurring-generator double.
	 *
	 * @var RecurringInvoiceGenerator&MockObject
	 */
	private RecurringInvoiceGenerator $recurring;

	/**
	 * A draft builds the request from the declared fields in the named administration.
	 *
	 * @return void
	 */
	public function testADraftRunsTheServiceForTheNamedAdministration(): void {
		$tools = $this->tools(canAccess: true);
		$this->billableLines->expects($this->once())->method('foreignLineIds')->with('adm-1', 'cus-1', [], [])->willReturn([]);
		$this->generation->expects($this->once())->method('draftInvoice')->willReturnCallback(
			static function (InvoiceGenerationRequest $request): array {
				return ['administrationId' => $request->administrationId, 'model' => $request->billingModel, 'fee' => $request->fixedFeeCents];
			}
		);

		$this->assertSame(['invoice' => ['administrationId' => 'adm-1', 'model' => 'fixed_fee', 'fee' => 50000]], $tools->draft(self::DRAFT));
	}//end testADraftRunsTheServiceForTheNamedAdministration()

	/**
	 * A draft refuses a foreign administration, lines of another customer, an incomplete model and an already-invoiced line.
	 *
	 * @return void
	 */
	public function testDraftRefusals(): void {
		$foreign = $this->tools(canAccess: false);
		$this->generation->expects($this->never())->method('draftInvoice');
		$this->assertSame(McpResult::error(code: 'not_found', message: McpInvoiceTools::ADMINISTRATION_NOT_FOUND), $foreign->draft(self::DRAFT));

		$tools = $this->tools(canAccess: true);
		$this->billableLines->method('foreignLineIds')->willReturnCallback(static fn (string $a, string $c, array $time): array => $time);
		$this->generation->method('draftInvoice')->willThrowException(new RuntimeException('Conflict: entry te-1 already invoiced'));

		$this->assertSame('invalid_arguments', $tools->draft(['timeEntryIds' => ['te-9']] + self::DRAFT)['error']);
		$incomplete = $tools->draft(['fixedFeeCents' => null] + self::DRAFT);
		$this->assertSame('invalid_arguments', $incomplete['error']);
		$this->assertStringContainsString('fixedFeeCents > 0 is required', $incomplete['message']);
		$this->assertSame(
			McpResult::error(code: 'conflict', message: 'One or more time entries, expenses or meter readings are already invoiced.'),
			$tools->draft(self::DRAFT)
		);
	}//end testDraftRefusals()

	/**
	 * The situations issuing an invoice faces.
	 *
	 * @return array<string, array{0: bool, 1: array<string, mixed>|null, 2: \Throwable|null, 3: string}>
	 */
	public static function issues(): array {
		$own = ['id' => 'inv-1', 'administrationId' => 'adm-1', 'status' => 'draft'];
		return [
			'issued'               => [true, $own, null, 'ok'],
			'foreign admin'        => [false, $own, null, 'not_found'],
			'missing invoice'      => [true, null, null, 'not_found'],
			'invoice of another'   => [true, ['id' => 'inv-1', 'administrationId' => 'adm-2'], null, 'not_found'],
			'already posted'       => [true, $own, new RuntimeException('Invoice is already posted'), 'conflict'],
			'service refuses'      => [true, $own, new RuntimeException('No lines'), 'invalid_arguments'],
			'unexpected failure'   => [true, $own, new \LogicException('db'), 'failed'],
		];
	}//end issues()

	/**
	 * issue posts only an invoice of the named administration, and maps refusals as the controller does.
	 *
	 * @param bool                      $canAccess Whether adm-1 is accessible.
	 * @param array<string, mixed>|null $invoice   The stored invoice.
	 * @param \Throwable|null           $thrown    What postInvoice() throws.
	 * @param string                    $expected  The outcome.
	 *
	 * @return void
	 */
	#[DataProvider('issues')]
	public function testIssue(bool $canAccess, ?array $invoice, ?\Throwable $thrown, string $expected): void {
		if ($invoice !== null) {
			$this->records['BillableInvoice']['inv-1'] = $invoice;
		}

		$tools = $this->tools(canAccess: $canAccess, accessible: ['adm-1', 'adm-2']);
		$posted = [];
		$this->generation->method('postInvoice')->willReturnCallback(
			static function (array $record) use (&$posted, $thrown): array {
				$posted[] = $record['id'];
				if ($thrown !== null) {
					throw $thrown;
				}

				return ['id' => $record['id'], 'status' => 'posted', 'number' => 'F2026-0001'];
			}
		);

		$result = $tools->issue(['administrationId' => 'adm-1', 'invoiceId' => 'inv-1']);

		$this->assertSame($expected, McpResult::isError($result) === true ? $result['error'] : 'ok');
		$reached = ($expected === 'ok' || $thrown !== null);
		$this->assertSame($reached === true ? ['inv-1'] : [], $posted);
		if ($expected === 'ok') {
			$this->assertSame(['invoice' => ['id' => 'inv-1', 'status' => 'posted', 'number' => 'F2026-0001']], $result);
		}
	}//end testIssue()

	/**
	 * render_pdf renders the invoice with its own lines.
	 *
	 * @return void
	 */
	public function testRenderPdfRendersTheInvoiceWithItsLines(): void {
		$this->records['BillableInvoice']['inv-1'] = ['id' => 'inv-1', 'administrationId' => 'adm-1'];
		$this->records['BillableInvoiceLine'] = ['l1' => ['id' => 'l1', 'invoiceId' => 'inv-1'], 'l2' => ['id' => 'l2', 'invoiceId' => 'inv-9']];
		$tools = $this->tools(canAccess: true);
		$this->pdf->expects($this->once())->method('generatePdf')
			->with(['id' => 'inv-1', 'administrationId' => 'adm-1'], [['id' => 'l1', 'invoiceId' => 'inv-1']])
			->willReturn(['filename' => 'F2026-0001', 'html' => '<h1>Invoice</h1>']);

		$this->assertSame(['filename' => 'F2026-0001', 'html' => '<h1>Invoice</h1>'], $tools->renderPdf(['administrationId' => 'adm-1', 'invoiceId' => 'inv-1']));
	}//end testRenderPdfRendersTheInvoiceWithItsLines()

	/**
	 * The e-invoice situations.
	 *
	 * @return array<string, array{0: bool, 1: bool, 2: \Throwable|null, 3: string}>
	 */
	public static function sends(): array {
		return [
			'sent'                   => [true, true, null, 'ok'],
			'not signed in'          => [false, true, null, 'unauthorised'],
			'foreign administration' => [true, false, null, 'not_found'],
			'invoice not found'      => [true, true, new RuntimeException('Invoice F-1 not found'), 'not_found'],
			'not sendable'           => [true, true, new RuntimeException('Customer has no Peppol id'), 'invalid_arguments'],
			'unexpected failure'     => [true, true, new \LogicException('network'), 'failed'],
		];
	}//end sends()

	/**
	 * send_einvoice agrees with ARInvoiceEInvoiceController on every situation.
	 *
	 * @param bool            $signedIn  Whether a user is signed in.
	 * @param bool            $canAccess Whether adm-1 is accessible.
	 * @param \Throwable|null $thrown    What the service throws.
	 * @param string          $expected  The outcome both must reach.
	 *
	 * @return void
	 */
	#[DataProvider('sends')]
	public function testSendEInvoiceAgreesWithTheController(bool $signedIn, bool $canAccess, ?\Throwable $thrown, string $expected): void {
		$tools = $this->tools(canAccess: $canAccess, signedIn: $signedIn);
		$this->eInvoices->method('sendEInvoice')->willReturnCallback(
			static function (string $administrationId, string $invoiceNumber) use ($thrown): array {
				if ($thrown !== null) {
					throw $thrown;
				}

				return ['sent' => [$administrationId, $invoiceNumber]];
			}
		);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $key === 'administrationId' ? 'adm-1' : $default);
		$controller = new ARInvoiceEInvoiceController($request, $this->eInvoices, $this->session, $this->context, $this->createMock(LoggerInterface::class));

		$rest = self::OUTCOME[$controller->send('F-1')->getStatus()];
		$tool = $tools->sendEInvoice(['administrationId' => 'adm-1', 'invoiceNumber' => 'F-1']);

		$this->assertSame($expected, $rest, 'the REST twin');
		$this->assertSame($expected, McpResult::isError($tool) === true ? $tool['error'] : 'ok', 'the tool');
		if ($expected === 'ok') {
			$this->assertSame(['sent' => ['adm-1', 'F-1']], $tool);
		}
	}//end testSendEInvoiceAgreesWithTheController()

	/**
	 * generate runs an active profile of the named administration, and refuses any other.
	 *
	 * @return void
	 */
	public function testGenerateRecurring(): void {
		$this->records['RecurringInvoiceProfile'] = [
			'p-1' => ['id' => 'p-1', 'administrationId' => 'adm-1', 'status' => 'active'],
			'p-2' => ['id' => 'p-2', 'administrationId' => 'adm-1', 'status' => 'paused'],
			'p-3' => ['id' => 'p-3', 'administrationId' => 'adm-2', 'status' => 'active'],
		];
		$tools = $this->tools(canAccess: true, accessible: ['adm-1', 'adm-2']);
		$this->recurring->expects($this->once())->method('generateForProfile')
			->with(['id' => 'p-1', 'administrationId' => 'adm-1', 'status' => 'active'])
			->willReturn(['invoice' => ['id' => 'inv-7'], 'created' => true, 'billingPeriod' => '2026-11']);

		$this->assertSame(
			['invoice' => ['id' => 'inv-7'], 'created' => true, 'billingPeriod' => '2026-11'],
			$tools->generateRecurring(['administrationId' => 'adm-1', 'profileId' => 'p-1'])
		);
		$this->assertSame('conflict', $tools->generateRecurring(['administrationId' => 'adm-1', 'profileId' => 'p-2'])['error']);
		$this->assertSame('not_found', $tools->generateRecurring(['administrationId' => 'adm-1', 'profileId' => 'p-3'])['error']);
		$this->assertSame('not_found', $tools->generateRecurring(['administrationId' => 'adm-1', 'profileId' => 'p-9'])['error']);
	}//end testGenerateRecurring()

	/**
	 * The caller's session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * The caller's administration context.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService $context;

	/**
	 * The tools over fresh doubles.
	 *
	 * @param bool         $canAccess  Whether the listed administrations are accessible.
	 * @param list<string> $accessible The administrations the caller may reach.
	 * @param bool         $signedIn   Whether a user is signed in.
	 *
	 * @return McpInvoiceTools
	 */
	private function tools(bool $canAccess, array $accessible = ['adm-1'], bool $signedIn = true): McpInvoiceTools {
		$this->generation = $this->createMock(InvoiceGenerationServiceInterface::class);
		$this->billableLines = $this->createMock(BillableLinesServiceInterface::class);
		$this->pdf = $this->createMock(InvoicePdfGeneratorInterface::class);
		$this->eInvoices = $this->createMock(EInvoiceServiceInterface::class);
		$this->recurring = $this->createMock(RecurringInvoiceGenerator::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($signedIn === true ? $user : null);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $canAccess === true && in_array($id, $accessible, true));

		return new McpInvoiceTools(
			new McpAdministrationGate($this->session, $this->context),
			$this->generation,
			$this->billableLines,
			$this->pdf,
			$this->eInvoices,
			$this->recurring,
			new DuckObjectServiceAdapter($this->store()),
			$this->createMock(LoggerInterface::class)
		);
	}//end tools()

	/**
	 * A duck-typed object store over {@see $records}, scoped by schema.
	 *
	 * @return object
	 */
	private function store(): object {
		return new class($this) {
			/**
			 * Constructor.
			 *
			 * @param McpInvoiceToolsTest $test The test whose records it serves.
			 */
			public function __construct(private readonly McpInvoiceToolsTest $test) {
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
				$this->test->schema = $schema;
				return $this;
			}

			/**
			 * The record by id in the current schema.
			 *
			 * @param string $id The id.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id): ?array {
				return ($this->test->records[$this->test->schema][$id] ?? null);
			}

			/**
			 * The records of the current schema matching the filters.
			 *
			 * @param array<string, mixed> $config The query, with `filters`.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				$rows = array_values($this->test->records[$this->test->schema] ?? []);
				foreach ((array)($config['filters'] ?? []) as $key => $value) {
					$rows = array_values(array_filter($rows, static fn (array $row): bool => ($row[$key] ?? null) === $value));
				}

				return $rows;
			}
		};
	}//end store()
}//end class
