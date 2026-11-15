<?php

/**
 * shillinq.purchase_order.decide refuses exactly where its REST twin does.
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

use OCA\Shillinq\Controller\ApprovalDecision;
use OCA\Shillinq\Controller\PurchaseOrderApprovalController;
use OCA\Shillinq\Mcp\McpAdministrationGate;
use OCA\Shillinq\Mcp\McpPurchaseOrderTools;
use OCA\Shillinq\Mcp\McpResult;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PurchaseOrderApprovalService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The tool and PurchaseOrderApprovalController::decide() run against the same
 * session, administration access and service, and must agree on every
 * outcome: the HTTP status maps one to one onto the tool's error code.
 */
#[CoversClass(McpPurchaseOrderTools::class)]
#[CoversClass(McpAdministrationGate::class)]
#[CoversClass(McpResult::class)]
#[UsesClass(PurchaseOrderApprovalController::class)]
#[UsesClass(ApprovalDecision::class)]
class McpPurchaseOrderDecideParityTest extends TestCase {

	/**
	 * HTTP status of the REST twin to the tool's outcome.
	 */
	private const OUTCOME = [200 => 'ok', 401 => 'unauthorised', 404 => 'not_found', 409 => 'conflict', 400 => 'invalid_arguments', 500 => 'failed'];

	private const ARGS = ['administrationId' => 'adm-1', 'purchaseOrderId' => 'po-7', 'decision' => 'approved', 'comment' => 'ok'];

	/**
	 * The situations both surfaces face.
	 *
	 * @return array<string, array{0: bool, 1: bool, 2: \Throwable|null, 3: string}>
	 */
	public static function situations(): array {
		return [
			'decided'                 => [true, true, null, 'ok'],
			'not signed in'           => [false, true, null, 'unauthorised'],
			'foreign administration'  => [true, false, null, 'not_found'],
			'order not found'         => [true, true, new RuntimeException('Purchase order po-7 not found'), 'not_found'],
			'order not pending'       => [true, true, new RuntimeException('Purchase order is not pending approval'), 'conflict'],
			'chain fully signed'      => [true, true, new RuntimeException('Approval chain is fully signed'), 'conflict'],
			'decision refused'        => [true, true, new RuntimeException('Invalid decision'), 'invalid_arguments'],
			'unexpected failure'      => [true, true, new \LogicException('db down'), 'failed'],
		];
	}//end situations()

	/**
	 * Both surfaces reach the same outcome, and only a permitted call reaches the service.
	 *
	 * @param bool            $signedIn  Whether a user is signed in.
	 * @param bool            $canAccess Whether the administration is accessible.
	 * @param \Throwable|null $thrown    What the service throws, or null.
	 * @param string          $expected  The outcome both must reach.
	 *
	 * @return void
	 */
	#[DataProvider('situations')]
	public function testTheToolRefusesExactlyWhereTheControllerDoes(bool $signedIn, bool $canAccess, ?\Throwable $thrown, string $expected): void {
		$serviceCalls = 0;
		$service = $this->createMock(PurchaseOrderApprovalService::class);
		$service->method('recordApprovalDecision')->willReturnCallback(
			function (string $administrationId, string $purchaseOrderId, string $decision, ?string $comment) use (&$serviceCalls, $thrown): array {
				$serviceCalls++;
				$this->assertSame(['adm-1', 'po-7', 'approved', 'ok'], [$administrationId, $purchaseOrderId, $decision, $comment]);
				if ($thrown !== null) {
					throw $thrown;
				}

				return ['id' => 'po-7', 'status' => 'approved'];
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $canAccess === true && $id === 'adm-1');
		$logger = $this->createMock(LoggerInterface::class);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => (self::ARGS[$key] ?? $default));
		$controller = new PurchaseOrderApprovalController($request, $service, $context, $session, $logger);
		$rest = self::OUTCOME[$controller->decide('po-7')->getStatus()];

		$tool = (new McpPurchaseOrderTools(new McpAdministrationGate($session, $context), $service, $logger))->decide(self::ARGS);
		$mcp = McpResult::isError($tool) === true ? $tool['error'] : 'ok';

		$this->assertSame($expected, $rest, 'the REST twin');
		$this->assertSame($expected, $mcp, 'the tool');
		$this->assertSame(($signedIn === true && $canAccess === true) ? 2 : 0, $serviceCalls);
		if ($expected === 'ok') {
			$this->assertSame(['purchaseOrder' => ['id' => 'po-7', 'status' => 'approved']], $tool);
		}
	}//end testTheToolRefusesExactlyWhereTheControllerDoes()

	/**
	 * A foreign administration answers the same masked message as a missing one.
	 *
	 * @return void
	 */
	public function testAForeignAdministrationIsMaskedAsNotFound(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturn(false);
		$service = $this->createMock(PurchaseOrderApprovalService::class);
		$service->expects($this->never())->method('recordApprovalDecision');

		$tool = new McpPurchaseOrderTools(new McpAdministrationGate($session, $context), $service, $this->createMock(LoggerInterface::class));

		$this->assertSame(
			['isError' => true, 'error' => 'not_found', 'message' => 'Purchase order not found'],
			$tool->decide(self::ARGS)
		);
	}//end testAForeignAdministrationIsMaskedAsNotFound()

	/**
	 * An unexpected failure is logged without leaking its message to the model.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIsLoggedAndNotLeaked(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturn(true);
		$service = $this->createMock(PurchaseOrderApprovalService::class);
		$service->method('recordApprovalDecision')->willThrowException(new \LogicException('secret detail'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Shillinq MCP: failed to record approval decision',
			['administrationId' => 'adm-1', 'purchaseOrderId' => 'po-7', 'exception' => 'secret detail']
		);

		$result = (new McpPurchaseOrderTools(new McpAdministrationGate($session, $context), $service, $logger))->decide(self::ARGS);

		$this->assertSame(['isError' => true, 'error' => 'failed', 'message' => 'Could not record approval decision'], $result);
	}//end testAnUnexpectedFailureIsLoggedAndNotLeaked()
}//end class
