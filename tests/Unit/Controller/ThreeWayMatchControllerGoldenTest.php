<?php

/**
 * Golden tests for ThreeWayMatchController::evaluate: check order, the membership check, the call and answers.
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
 * @spec openspec/specs/bookkeeping-three-way-matching-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ThreeWayMatchController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ThreeWayMatchingEngine;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every answer of the evaluate action, allowed and refused, and every call behind it.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class ThreeWayMatchControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Call evaluate once.
	 *
	 * @param array<string,mixed> $setup user (bool), params, member (bool), fail (Throwable).
	 *
	 * @return JSONResponse
	 */
	private function evaluate(array $setup=[]): JSONResponse {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default=null) use ($setup): mixed {
				$this->calls[] = ['getParam', $key, $default];
				return ($setup['params'][$key] ?? $default);
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if (($setup['user'] ?? true) === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturn($user);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return ($setup['member'] ?? true);
			}
		);
		$engine = $this->createMock(ThreeWayMatchingEngine::class);
		$engine->method('evaluateMatch')->willReturnCallback(
			function (string $administrationId, string $invoiceId) use ($setup): array {
				$this->calls[] = ['evaluateMatch', $administrationId, $invoiceId];
				if (isset($setup['fail']) === true) {
					throw $setup['fail'];
				}

				return ['status' => 'matched', 'invoiceId' => $invoiceId];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		return (new ThreeWayMatchController($request, $engine, $context, $session, $logger))->evaluate();
	}//end evaluate()

	/**
	 * Status and body.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private static function answer(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end answer()

	/**
	 * 401, then the administration 400 and masked 404, before the invoice id is read; then the invoice 400.
	 *
	 * @return void
	 */
	public function testRefusalOrder(): void {
		self::assertSame(expected: [401, ['error' => 'Not logged in']], actual: self::answer($this->evaluate(setup: ['user' => false])));
		self::assertSame(expected: [], actual: $this->calls);
		$missing = [400, ['error' => 'administrationId is required']];
		self::assertSame(expected: $missing, actual: self::answer($this->evaluate(setup: ['params' => ['administrationId' => 'a b']])));
		self::assertSame(expected: [['getParam', 'administrationId', '']], actual: $this->calls);
		$this->calls = [];
		$foreign = ['params' => ['administrationId' => ' adm ', 'invoiceId' => 'i1'], 'member' => false];
		self::assertSame(expected: [404, ['error' => 'Supplier invoice not found']], actual: self::answer($this->evaluate(setup: $foreign)));
		self::assertSame(expected: [['getParam', 'administrationId', ''], ['canAccess', 'adm']], actual: $this->calls);
		$this->calls = [];
		$noInvoice = ['params' => ['administrationId' => 'adm', 'invoiceId' => str_repeat('i', 65)]];
		self::assertSame(expected: [400, ['error' => 'invoiceId is required']], actual: self::answer($this->evaluate(setup: $noInvoice)));
		self::assertSame(expected: [['getParam', 'administrationId', ''], ['canAccess', 'adm'], ['getParam', 'invoiceId', '']], actual: $this->calls);
	}//end testRefusalOrder()

	/**
	 * A member gets the match; service refusals map to 404 / 400 and anything else is a logged 500.
	 *
	 * @return void
	 */
	public function testAllowedAndFailures(): void {
		$params = ['administrationId' => 'adm', 'invoiceId' => ' i1 '];
		$actual = self::answer($this->evaluate(setup: ['params' => $params]));
		self::assertSame(expected: [200, ['status' => 'matched', 'invoiceId' => 'i1']], actual: $actual);
		self::assertSame(expected: ['evaluateMatch', 'adm', 'i1'], actual: end($this->calls));
		$notFound = ['params' => $params, 'fail' => new \RuntimeException('Invoice not found')];
		self::assertSame(expected: [404, ['error' => 'Invoice not found']], actual: self::answer($this->evaluate(setup: $notFound)));
		$bad = ['params' => $params, 'fail' => new \UnexpectedValueException('No PO Not Found')];
		self::assertSame(expected: [400, ['error' => 'No PO Not Found']], actual: self::answer($this->evaluate(setup: $bad)));
		$this->calls = [];
		$broken = ['params' => $params, 'fail' => new \LogicException('bug')];
		self::assertSame(expected: [500, ['error' => 'Could not evaluate three-way match']], actual: self::answer($this->evaluate(setup: $broken)));
		self::assertSame(
			expected: [
				'error',
				'ThreeWayMatchController: failed to evaluate match',
				['administrationId' => 'adm', 'invoiceId' => 'i1', 'exception' => 'bug',
			]],
			actual: end($this->calls)
		);
	}//end testAllowedAndFailures()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
