<?php

/**
 * Unit tests for the shillinq MCP tool provider.
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

use OCA\Shillinq\Kernel\Contracts\TimeIntakeServiceInterface;
use OCA\Shillinq\Mcp\McpAdministrationGate;
use OCA\Shillinq\Mcp\McpArgumentValidator;
use OCA\Shillinq\Kernel\Contracts\PeriodCloseAssistantServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PeriodCloseServiceInterface;
use OCA\Shillinq\Kernel\Contracts\VATReturnServiceInterface;
use OCA\Shillinq\Kernel\Contracts\DunningRunServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RequisitionConversionServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RequisitionServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentRunExportServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentRunReconciliationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ReconciliationResolutionServiceInterface;
use OCA\Shillinq\Kernel\Contracts\StatementIntakeServiceInterface;
use OCA\Shillinq\Kernel\Contracts\StatementParserInterface;
use OCA\Shillinq\Kernel\Contracts\InvoiceGenerationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BillableLinesServiceInterface;
use OCA\Shillinq\Kernel\Contracts\InvoicePdfGeneratorInterface;
use OCA\Shillinq\Kernel\Contracts\EInvoiceServiceInterface;
use OCA\Shillinq\Kernel\Contracts\IncassoDossierComposerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ExtractionPrefillServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Service\Extraction\ExtractionDraftCommitter;
use OCA\Shillinq\Mcp\McpInvocationAudit;
use OCA\Shillinq\Mcp\McpPeriodCloseTools;
use OCA\Shillinq\Mcp\McpVatReturnTools;
use OCA\Shillinq\Mcp\McpDunningTools;
use OCA\Shillinq\Mcp\McpRequisitionTools;
use OCA\Shillinq\Mcp\McpPaymentRunTools;
use OCA\Shillinq\Mcp\McpBankTools;
use OCA\Shillinq\Mcp\McpExpenseTools;
use OCA\Shillinq\Mcp\McpHoursTools;
use OCA\Shillinq\Mcp\McpInvoiceTools;
use OCA\Shillinq\Service\RecurringInvoiceGenerator;
use OCA\Shillinq\Mcp\McpResult;
use OCA\Shillinq\Mcp\ShillinqToolProvider;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The catalogue's invariants, and that every invocation — valid, invalid or
 * unknown — is validated before any service runs and audited once.
 */
#[CoversClass(ShillinqToolProvider::class)]
#[CoversClass(McpInvocationAudit::class)]
#[UsesClass(McpArgumentValidator::class)]
#[UsesClass(McpAdministrationGate::class)]
#[UsesClass(McpPeriodCloseTools::class)]
#[UsesClass(McpVatReturnTools::class)]
#[UsesClass(McpDunningTools::class)]
#[UsesClass(McpRequisitionTools::class)]
#[UsesClass(McpPaymentRunTools::class)]
#[UsesClass(McpBankTools::class)]
#[UsesClass(McpInvoiceTools::class)]
#[UsesClass(McpHoursTools::class)]
#[UsesClass(McpExpenseTools::class)]
#[UsesClass(McpResult::class)]
class ShillinqToolProviderTest extends TestCase {

	/**
	 * The service behind requisition.decide.
	 *
	 * @var RequisitionServiceInterface&MockObject
	 */
	private RequisitionServiceInterface&MockObject $requisitions;

	/**
	 * The audit records dispatched, as [message, parameters].
	 *
	 * @var list<array{0: string, 1: array<string, string>}>
	 */
	private array $audited = [];

	/**
	 * The provider under test.
	 *
	 * @var ShillinqToolProvider
	 */
	private ShillinqToolProvider $provider;

	/**
	 * Build the provider over a signed-in user with access to adm-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('agent-alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id === 'adm-1');
		$this->requisitions = $this->createMock(RequisitionServiceInterface::class);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$this->assertInstanceOf(CriticalActionPerformedEvent::class, $event);
				$this->audited[] = [$event->getLogMessage(), $event->getParameters()];
			}
		);
		$gate = new McpAdministrationGate($session, $context);
		$this->provider = new ShillinqToolProvider(
			new McpArgumentValidator(),
			$gate,
			new McpInvocationAudit($dispatcher),
			new McpPeriodCloseTools(
				$gate,
				$this->createMock(PeriodCloseServiceInterface::class),
				$this->createMock(PeriodCloseAssistantServiceInterface::class),
				$this->createMock(LoggerInterface::class)
			),
			new McpVatReturnTools(
				$gate,
				$this->createMock(VATReturnServiceInterface::class),
				$this->createMock(ContainerInterface::class),
				$this->createMock(LoggerInterface::class)
			),
			new McpDunningTools(
				$gate,
				$this->createMock(DunningRunServiceInterface::class),
				$this->createMock(IncassoDossierComposerInterface::class),
				$this->createMock(ObjectServiceInterface::class),
				$this->createMock(IAppConfig::class),
				$this->createMock(LoggerInterface::class)
			),
			new McpRequisitionTools(
				$gate,
				$this->requisitions,
				$this->createMock(RequisitionConversionServiceInterface::class),
				$this->createMock(LoggerInterface::class)
			),
			new McpPaymentRunTools(
				$gate,
				$this->createMock(PaymentRunExportServiceInterface::class),
				$this->createMock(PaymentRunReconciliationServiceInterface::class),
				$this->createMock(ObjectServiceInterface::class),
				$this->createMock(LoggerInterface::class)
			),
			new McpBankTools(
				$gate,
				$this->createMock(StatementParserInterface::class),
				$this->createMock(StatementIntakeServiceInterface::class),
				$this->createMock(ReconciliationResolutionServiceInterface::class),
				$this->createMock(ObjectServiceInterface::class),
				$this->createMock(IAppConfig::class),
				$this->createMock(LoggerInterface::class)
			),
			new McpInvoiceTools(
				$gate,
				$this->createMock(InvoiceGenerationServiceInterface::class),
				$this->createMock(BillableLinesServiceInterface::class),
				$this->createMock(InvoicePdfGeneratorInterface::class),
				$this->createMock(EInvoiceServiceInterface::class),
				$this->createMock(RecurringInvoiceGenerator::class),
				$this->createMock(ObjectServiceInterface::class),
				$this->createMock(LoggerInterface::class)
			),
			new McpHoursTools($gate, $this->createMock(TimeIntakeServiceInterface::class), $this->createMock(LoggerInterface::class)),
			new McpExpenseTools(
				$gate,
				$this->createMock(ObjectServiceInterface::class),
				$this->createMock(ExtractionPrefillServiceInterface::class),
				$this->createMock(ExtractionDraftCommitter::class),
				$this->createMock(ObjectTransitionRunnerInterface::class),
				$this->createMock(LoggerInterface::class)
			)
		);
	}//end setUp()

	/**
	 * Every tool is shillinq-prefixed, unique, and declares its grant axes:
	 * a write both scope and reach, a read readOnlyHint.
	 *
	 * @return void
	 */
	public function testEveryToolDeclaresItsGrantAxes(): void {
		$this->assertSame('shillinq', $this->provider->getAppId());
		$tools = $this->provider->getTools();
		$this->assertNotSame([], $tools);
		$ids = array_column($tools, 'id');
		$this->assertSame($ids, array_values(array_unique($ids)));

		foreach ($tools as $tool) {
			$this->assertStringStartsWith('shillinq.', $tool['id']);
			$this->assertNotSame('', $tool['name']);
			$this->assertNotSame('', $tool['description']);
			$this->assertSame('object', $tool['inputSchema']['type']);
			$this->assertContains($tool['scope'], ['read', 'create', 'update', 'delete'], $tool['id']);
			if ($tool['scope'] === 'read') {
				$this->assertTrue($tool['readOnlyHint'], $tool['id']);
				continue;
			}

			$this->assertContains($tool['reach'] ?? null, ['self', 'user', 'instance', 'external'], $tool['id']);
			$this->assertFalse($tool['readOnlyHint'], $tool['id']);
		}
	}//end testEveryToolDeclaresItsGrantAxes()

	/**
	 * No tool duplicates a derived CRUD id (`shillinq.{Schema}.{verb}`), and
	 * no tool deletes from the ledger.
	 *
	 * @return void
	 */
	public function testNoToolIsCrudShapedOrDeletesTheLedger(): void {
		foreach ($this->provider->getTools() as $tool) {
			$this->assertDoesNotMatchRegularExpression('/^shillinq\.[A-Z][A-Za-z]*\.(search|get|create|update|delete)$/', $tool['id']);
			$this->assertStringNotContainsStringIgnoringCase('gl_transaction', $tool['id']);
			if ($tool['scope'] === 'delete') {
				$this->assertSame('shillinq.vat_return.destroy', $tool['id']);
			}
		}
	}//end testNoToolIsCrudShapedOrDeletesTheLedger()

	/**
	 * Every catalogue tool dispatches to a handler (none falls through).
	 *
	 * @return void
	 */
	public function testEveryCatalogueToolDispatches(): void {
		foreach ($this->provider->getTools() as $tool) {
			$result = $this->provider->invokeTool($tool['id'], []);
			$this->assertSame('invalid_arguments', $result['error'], $tool['id']);
		}
	}//end testEveryCatalogueToolDispatches()

	/**
	 * Every catalogue tool, given well-formed arguments, reaches its handler:
	 * the gate answers for an administration the caller may not reach, rather
	 * than the dispatcher saying the tool has no handler.
	 *
	 * @return void
	 */
	public function testEveryCatalogueToolReachesItsHandler(): void {
		$values = [
			'administrationId' => 'adm-other',
			'purchaseOrderId' => 'po-1',
			'periodId' => '2026-10',
			'decision' => 'approved',
			'returnId' => 'ret-1',
			'period' => 'quarter',
			'periodYear' => 2024,
			'periodNumber' => 1,
			'regime' => 'standard',
			'invoiceId' => 'inv-1',
			'customerId' => 'cus-1',
			'reason' => 'DISPUTED',
			'pauseId' => 'pause-1',
			'principalDepreciated' => 10.0,
			'art29OBDeclaration' => 'Bankrupt',
			'requisitionId' => 'req-1',
			'programme' => 'ICT',
			'financialYear' => 2026,
			'neededByDate' => '2026-12-01',
			'justification' => 'New starters',
			'kind' => 'goods',
			'lines' => [['description' => 'Laptop', 'glAccountSuggestion' => '4100', 'quantity' => 1, 'unitPrice' => 900]],
			'paymentRunId' => 'run-1',
			'contents' => 'statement',
			'format' => 'camt053',
			'reconId' => 'rec-1',
			'matchIds' => ['m-1'],
			'resolutionStatus' => 'matched',
			'resolutionReason' => 'Paid',
			'batchId' => 'b-1',
			'organisationRef' => 'org-1',
			'entries' => [['externalId' => 'e1', 'date' => '2026-11-02', 'minutes' => 60, 'description' => 'Work']],
			'billingModel' => 'fixed_fee',
			'fromDate' => '2026-10-01',
			'toDate' => '2026-10-31',
			'invoiceNumber' => 'F-1',
			'profileId' => 'p-1',
			'receiptId' => 'rcp-1',
			'claimId' => 'clm-1',
		];
		foreach ($this->provider->getTools() as $tool) {
			$args = array_intersect_key($values, array_flip($tool['inputSchema']['required']));
			$result = $this->provider->invokeTool($tool['id'], $args);
			$this->assertSame('not_found', $result['error'], $tool['id']);
		}
	}//end testEveryCatalogueToolReachesItsHandler()

	/**
	 * A permitted decision runs the service and is audited as ok under the principal.
	 *
	 * @return void
	 */
	public function testADecisionRunsAndIsAudited(): void {
		$this->requisitions->expects($this->once())->method('rejectRequisition')
			->with('adm-1', 'req-7', 'agent-alice', 'over budget')
			->willReturn(['id' => 'req-7', 'status' => 'rejected']);

		$result = $this->provider->invokeTool('shillinq.requisition.decide', ['administrationId' => 'adm-1', 'requisitionId' => 'req-7', 'decision' => 'rejected', 'reason' => 'over budget']);

		$this->assertSame(['requisition' => ['id' => 'req-7', 'status' => 'rejected']], $result);
		$this->assertSame(
			[[McpInvocationAudit::MESSAGE, ['tool' => 'shillinq.requisition.decide', 'user' => 'agent-alice', 'administrationId' => 'adm-1', 'outcome' => 'ok']]],
			$this->audited
		);
	}//end testADecisionRunsAndIsAudited()

	/**
	 * Malformed arguments are refused before any service call, and the refusal is audited.
	 *
	 * @return void
	 */
	public function testMalformedArgumentsNeverReachTheServiceButAreAudited(): void {
		$this->requisitions->expects($this->never())->method('rejectRequisition');
		$this->requisitions->expects($this->never())->method('approveRequisition');

		$result = $this->provider->invokeTool(
			'shillinq.requisition.decide',
			['administrationId' => 'adm-1', 'requisitionId' => '../etc', 'decision' => 'maybe', 'userId' => 'bob']
		);

		$this->assertSame('invalid_arguments', $result['error']);
		$this->assertStringContainsString('requisitionId is not well-formed', $result['message']);
		$this->assertStringContainsString('decision must be one of approved, rejected', $result['message']);
		$this->assertStringContainsString('userId is not an argument of this tool', $result['message']);
		$this->assertSame('invalid_arguments', $this->audited[0][1]['outcome']);
		$this->assertCount(1, $this->audited);
	}//end testMalformedArgumentsNeverReachTheServiceButAreAudited()

	/**
	 * A gate refusal is audited with its code, and an unknown tool is a structured error that is audited too.
	 *
	 * @return void
	 */
	public function testRefusalsAndUnknownToolsAreAudited(): void {
		$this->requisitions->expects($this->never())->method('approveRequisition');

		$refused = $this->provider->invokeTool('shillinq.requisition.decide', ['administrationId' => 'adm-9', 'requisitionId' => 'req-7', 'decision' => 'approved']);
		$unknown = $this->provider->invokeTool('shillinq.gl_transaction.delete', ['administrationId' => ['x']]);

		$this->assertSame('not_found', $refused['error']);
		$this->assertSame('unknown_tool', $unknown['error']);
		$this->assertStringContainsString('shillinq.requisition.decide', $unknown['message']);
		$this->assertSame(
			[
				['tool' => 'shillinq.requisition.decide', 'user' => 'agent-alice', 'administrationId' => 'adm-9', 'outcome' => 'not_found'],
				['tool' => 'shillinq.gl_transaction.delete', 'user' => 'agent-alice', 'administrationId' => '', 'outcome' => 'unknown_tool'],
			],
			array_column($this->audited, 1)
		);
	}//end testRefusalsAndUnknownToolsAreAudited()
}//end class
