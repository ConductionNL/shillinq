<?php

/**
 * shillinq.requisition.* refuse exactly where their REST twins do.
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

use OCA\Shillinq\Controller\RequisitionController;
use OCA\Shillinq\Kernel\Contracts\RequisitionConversionServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RequisitionServiceInterface;
use OCA\Shillinq\Mcp\McpAdministrationGate;
use OCA\Shillinq\Mcp\McpRequisitionTools;
use OCA\Shillinq\Mcp\McpResult;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\IL10N;
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
 * The tools and RequisitionController run against the same session,
 * administration access and services, and must agree on every outcome.
 */
#[CoversClass(McpRequisitionTools::class)]
#[CoversClass(McpAdministrationGate::class)]
#[CoversClass(McpResult::class)]
#[UsesClass(RequisitionController::class)]
class McpRequisitionToolsTest extends TestCase {

	/**
	 * HTTP status of the REST twin to the tool's outcome.
	 */
	private const OUTCOME = [200 => 'ok', 201 => 'ok', 400 => 'invalid_arguments', 401 => 'unauthorised', 404 => 'not_found', 409 => 'conflict', 500 => 'failed'];

	private const LINES = [['description' => 'Laptop', 'glAccountSuggestion' => '4100', 'quantity' => 2, 'unitPrice' => 950.0]];

	/**
	 * The requisition-service double.
	 *
	 * @var RequisitionServiceInterface&MockObject
	 */
	private RequisitionServiceInterface $service;

	/**
	 * The conversion-service double.
	 *
	 * @var RequisitionConversionServiceInterface&MockObject
	 */
	private RequisitionConversionServiceInterface $conversion;

	/**
	 * The situations every action on an existing requisition faces.
	 *
	 * @return array<string, array{0: bool, 1: bool, 2: \Throwable|null, 3: string}>
	 */
	public static function situations(): array {
		return [
			'permitted'              => [true, true, null, 'ok'],
			'not signed in'          => [false, true, null, 'unauthorised'],
			'foreign administration' => [true, false, null, 'not_found'],
			'requisition not found'  => [true, true, new RuntimeException('Requisition req-1 not found'), 'not_found'],
			'wrong state'            => [true, true, new RuntimeException('Requisition is not submitted'), 'conflict'],
			'unexpected failure'     => [true, true, new \LogicException('db down'), 'failed'],
		];
	}//end situations()

	/**
	 * submit, approve, reject and convert: both surfaces agree on every situation.
	 *
	 * @param bool            $signedIn  Whether a user is signed in.
	 * @param bool            $canAccess Whether adm-1 is accessible.
	 * @param \Throwable|null $thrown    What the service throws.
	 * @param string          $expected  The outcome both must reach.
	 *
	 * @return void
	 */
	#[DataProvider('situations')]
	public function testEveryActionAgreesWithTheController(bool $signedIn, bool $canAccess, ?\Throwable $thrown, string $expected): void {
		$params = ['administrationId' => 'adm-1', 'reason' => 'Over budget'];
		[$controller, $tools] = $this->surfaces(signedIn: $signedIn, canAccess: $canAccess, params: $params);
		$calls = [];
		$answer = static function (string $what) use (&$calls, $thrown): array {
			$calls[] = $what;
			if ($thrown !== null) {
				throw $thrown;
			}

			return ['id' => 'req-1', 'did' => $what];
		};
		$this->service->method('submitRequisition')->willReturnCallback(static fn (string $a, string $r): array => $answer("submit:$a:$r"));
		$this->service->method('approveRequisition')->willReturnCallback(static fn (string $a, string $r, string $u): array => $answer("approve:$a:$r:$u"));
		$this->service->method('rejectRequisition')->willReturnCallback(
			static fn (string $a, string $r, string $u, string $why): array => $answer("reject:$a:$r:$u:$why")
		);
		$this->conversion->method('convertToPurchaseOrder')->willReturnCallback(static fn (string $a, string $r): array => $answer("convert:$a:$r"));

		$pairs = [
			'submit' => [$controller->submit('req-1'), $tools->submit(['administrationId' => 'adm-1', 'requisitionId' => 'req-1'])],
			'approve' => [$controller->approve('req-1'), $tools->decide(['administrationId' => 'adm-1', 'requisitionId' => 'req-1', 'decision' => 'approved'])],
			'reject' => [
				$controller->reject('req-1'),
				$tools->decide(['administrationId' => 'adm-1', 'requisitionId' => 'req-1', 'decision' => 'rejected', 'reason' => ' Over budget ']),
			],
			'convert' => [$controller->convert('req-1'), $tools->convert(['administrationId' => 'adm-1', 'requisitionId' => 'req-1'])],
		];
		foreach ($pairs as $action => [$response, $tool]) {
			$this->assertSame($expected, self::OUTCOME[$response->getStatus()], $action . ' REST');
			$this->assertSame($expected, McpResult::isError($tool) === true ? $tool['error'] : 'ok', $action . ' tool');
		}

		$reached = ($signedIn === true && $canAccess === true);
		$each = ['submit:adm-1:req-1', 'approve:adm-1:req-1:alice', 'reject:adm-1:req-1:alice:Over budget', 'convert:adm-1:req-1'];
		$expectedCalls = [];
		if ($reached === true) {
			$expectedCalls = array_merge($each, $each);
		}

		sort($calls);
		sort($expectedCalls);
		$this->assertSame($expectedCalls, $calls);
		if ($expected === 'ok') {
			$this->assertSame(['conversion' => ['id' => 'req-1', 'did' => 'convert:adm-1:req-1']], $pairs['convert'][1]);
			$this->assertSame(['requisition' => ['id' => 'req-1', 'did' => 'submit:adm-1:req-1']], $pairs['submit'][1]);
		}
	}//end testEveryActionAgreesWithTheController()

	/**
	 * The create situations.
	 *
	 * @return array<string, array{0: bool, 1: bool, 2: \Throwable|null, 3: string}>
	 */
	public static function creations(): array {
		return [
			'created'                => [true, true, null, 'ok'],
			'not signed in'          => [false, true, null, 'unauthorised'],
			'foreign administration' => [true, false, null, 'not_found'],
			'refused by the service' => [true, true, new RuntimeException('Line 1 is missing description'), 'invalid_arguments'],
			'unexpected failure'     => [true, true, new \LogicException('db down'), 'failed'],
		];
	}//end creations()

	/**
	 * create: both surfaces agree, and send the service the same payload.
	 *
	 * @param bool            $signedIn  Whether a user is signed in.
	 * @param bool            $canAccess Whether adm-1 is accessible.
	 * @param \Throwable|null $thrown    What the service throws.
	 * @param string          $expected  The outcome both must reach.
	 *
	 * @return void
	 */
	#[DataProvider('creations')]
	public function testCreateAgreesWithTheController(bool $signedIn, bool $canAccess, ?\Throwable $thrown, string $expected): void {
		$fields = [
			'programme' => 'ICT',
			'financialYear' => 2026,
			'neededByDate' => '2026-12-01',
			'justification' => 'New starters',
			'kind' => 'goods',
			'preferredSupplierId' => 'sup-1',
			'lines' => self::LINES,
		];
		[$controller, $tools] = $this->surfaces(signedIn: $signedIn, canAccess: $canAccess, params: ['administrationId' => 'adm-1'] + $fields);
		$payloads = [];
		$this->service->method('createRequisition')->willReturnCallback(
			static function (string $administrationId, array $payload) use (&$payloads, $thrown): array {
				$payloads[] = [$administrationId, $payload];
				if ($thrown !== null) {
					throw $thrown;
				}

				return ['id' => 'req-new'];
			}
		);

		$rest = self::OUTCOME[$controller->create()->getStatus()];
		$tool = $tools->create(['administrationId' => 'adm-1'] + $fields);

		$this->assertSame($expected, $rest, 'the REST twin');
		$this->assertSame($expected, McpResult::isError($tool) === true ? $tool['error'] : 'ok', 'the tool');
		if ($signedIn === true && $canAccess === true) {
			$this->assertCount(2, $payloads);
			$this->assertSame($payloads[0], $payloads[1]);
			$this->assertSame(['adm-1', $fields], $payloads[1]);
		} else {
			$this->assertSame([], $payloads);
		}
	}//end testCreateAgreesWithTheController()

	/**
	 * A rejection without a reason passes an empty reason, which the service judges.
	 *
	 * @return void
	 */
	public function testARejectionWithoutAReasonPassesAnEmptyOne(): void {
		[, $tools] = $this->surfaces(signedIn: true, canAccess: true, params: []);
		$this->service->expects($this->once())->method('rejectRequisition')->with('adm-1', 'req-1', 'alice', '')->willReturn(['id' => 'req-1']);
		$this->service->expects($this->never())->method('approveRequisition');

		$this->assertSame(['requisition' => ['id' => 'req-1']], $tools->decide(['administrationId' => 'adm-1', 'requisitionId' => 'req-1', 'decision' => 'rejected']));
	}//end testARejectionWithoutAReasonPassesAnEmptyOne()

	/**
	 * The controller and the tools over the same doubles.
	 *
	 * @param bool                 $signedIn  Whether a user is signed in.
	 * @param bool                 $canAccess Whether adm-1 is accessible.
	 * @param array<string, mixed> $params    The REST request's parameters.
	 *
	 * @return array{0: RequisitionController, 1: McpRequisitionTools}
	 */
	private function surfaces(bool $signedIn, bool $canAccess, array $params): array {
		$this->service = $this->createMock(RequisitionServiceInterface::class);
		$this->conversion = $this->createMock(RequisitionConversionServiceInterface::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $canAccess === true && $id === 'adm-1');
		$logger = $this->createMock(LoggerInterface::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		$controller = new RequisitionController($request, $this->service, $this->conversion, $context, $session, $logger, $l10n);
		$tools = new McpRequisitionTools(new McpAdministrationGate($session, $context), $this->service, $this->conversion, $logger);

		return [$controller, $tools];
	}//end surfaces()
}//end class
