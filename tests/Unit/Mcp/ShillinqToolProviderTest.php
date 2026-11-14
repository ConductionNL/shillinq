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

use OCA\Shillinq\Mcp\McpAdministrationGate;
use OCA\Shillinq\Mcp\McpArgumentValidator;
use OCA\Shillinq\Mcp\McpInvocationAudit;
use OCA\Shillinq\Mcp\McpPurchaseOrderTools;
use OCA\Shillinq\Mcp\McpResult;
use OCA\Shillinq\Mcp\ShillinqToolProvider;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PurchaseOrderApprovalService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The catalogue's invariants, and that every invocation — valid, invalid or
 * unknown — is validated before any service runs and audited once.
 */
#[CoversClass(ShillinqToolProvider::class)]
#[CoversClass(McpInvocationAudit::class)]
#[UsesClass(McpArgumentValidator::class)]
#[UsesClass(McpAdministrationGate::class)]
#[UsesClass(McpPurchaseOrderTools::class)]
#[UsesClass(McpResult::class)]
class ShillinqToolProviderTest extends TestCase {

	/**
	 * The service behind purchase_order.decide.
	 *
	 * @var PurchaseOrderApprovalService&MockObject
	 */
	private PurchaseOrderApprovalService&MockObject $approvals;

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
		$this->approvals = $this->createMock(PurchaseOrderApprovalService::class);
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
			new McpPurchaseOrderTools($gate, $this->approvals, $this->createMock(LoggerInterface::class))
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
	 * A permitted decision runs the service and is audited as ok under the principal.
	 *
	 * @return void
	 */
	public function testADecisionRunsAndIsAudited(): void {
		$this->approvals->expects($this->once())->method('recordApprovalDecision')
			->with('adm-1', 'po-7', 'rejected', null)
			->willReturn(['id' => 'po-7', 'status' => 'rejected']);

		$result = $this->provider->invokeTool('shillinq.purchase_order.decide', ['administrationId' => 'adm-1', 'purchaseOrderId' => 'po-7', 'decision' => 'rejected']);

		$this->assertSame(['purchaseOrder' => ['id' => 'po-7', 'status' => 'rejected']], $result);
		$this->assertSame(
			[[McpInvocationAudit::MESSAGE, ['tool' => 'shillinq.purchase_order.decide', 'user' => 'agent-alice', 'administrationId' => 'adm-1', 'outcome' => 'ok']]],
			$this->audited
		);
	}//end testADecisionRunsAndIsAudited()

	/**
	 * Malformed arguments are refused before any service call, and the refusal is audited.
	 *
	 * @return void
	 */
	public function testMalformedArgumentsNeverReachTheServiceButAreAudited(): void {
		$this->approvals->expects($this->never())->method('recordApprovalDecision');

		$result = $this->provider->invokeTool(
			'shillinq.purchase_order.decide',
			['administrationId' => 'adm-1', 'purchaseOrderId' => '../etc', 'decision' => 'maybe', 'userId' => 'bob']
		);

		$this->assertSame('invalid_arguments', $result['error']);
		$this->assertStringContainsString('purchaseOrderId is not well-formed', $result['message']);
		$this->assertStringContainsString('decision must be one of approved, rejected, delegated', $result['message']);
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
		$this->approvals->expects($this->never())->method('recordApprovalDecision');

		$refused = $this->provider->invokeTool('shillinq.purchase_order.decide', ['administrationId' => 'adm-9', 'purchaseOrderId' => 'po-7', 'decision' => 'approved']);
		$unknown = $this->provider->invokeTool('shillinq.gl_transaction.delete', ['administrationId' => ['x']]);

		$this->assertSame('not_found', $refused['error']);
		$this->assertSame('unknown_tool', $unknown['error']);
		$this->assertStringContainsString('shillinq.purchase_order.decide', $unknown['message']);
		$this->assertSame(
			[
				['tool' => 'shillinq.purchase_order.decide', 'user' => 'agent-alice', 'administrationId' => 'adm-9', 'outcome' => 'not_found'],
				['tool' => 'shillinq.gl_transaction.delete', 'user' => 'agent-alice', 'administrationId' => '', 'outcome' => 'unknown_tool'],
			],
			array_column($this->audited, 1)
		);
	}//end testRefusalsAndUnknownToolsAreAudited()
}//end class
