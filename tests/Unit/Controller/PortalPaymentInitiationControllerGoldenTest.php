<?php

/**
 * Golden test for PortalPaymentInitiationController::initiate.
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
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PortalPaymentInitiationController;
use OCA\Shillinq\Portal\PortalAssertionVerifier;
use OCA\Shillinq\Service\Payment\PortalPaymentSessionResult;
use OCA\Shillinq\Service\Payment\PortalPaymentSessionService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every answer of initiate, allowed and refused, and every read and call behind it in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class PortalPaymentInitiationControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Run initiate.
	 *
	 * @param array<string,mixed> $params The body parameters.
	 * @param array<string,mixed> $setup  claims (array|null), result (PortalPaymentSessionResult), fail (bool).
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function initiate(array $params, array $setup=[]): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			function (string $name): string {
				$this->calls[] = ['getHeader', $name];
				return 'jwt';
			}
		);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default=null) use ($params): mixed {
				$this->calls[] = ['getParam', $key, $default];
				return ($params[$key] ?? $default);
			}
		);
		$claims = ['audience' => 'customer', 'sub' => 's1'];
		if (array_key_exists('claims', $setup) === true) {
			$claims = $setup['claims'];
		}

		$verifier = $this->createMock(PortalAssertionVerifier::class);
		$verifier->method('verify')->willReturnCallback(
			function (string $jwt) use ($claims): ?array {
				$this->calls[] = ['verify', $jwt];
				return $claims;
			}
		);
		$result   = ($setup['result'] ?? PortalPaymentSessionResult::success(checkoutUrl: 'https://pay/1'));
		$fail     = ($setup['fail'] ?? false);
		$sessions = $this->createMock(PortalPaymentSessionService::class);
		foreach (['initiate', 'initiateForRequest'] as $method) {
			$sessions->method($method)->willReturnCallback(
				function (array $claims, string $target) use ($method, $result, $fail): PortalPaymentSessionResult {
					$this->calls[] = [$method, $claims['sub'], $target];
					if ($fail === true) {
						throw new RuntimeException('psp down');
					}

					return $result;
				}
			);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		$response = (new PortalPaymentInitiationController($request, $verifier, $sessions, $logger))->initiate();
		return [$response->getStatus(), $response->getData()];
	}//end initiate()

	/**
	 * The calls before the session starts.
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private static function reads(): array {
		return [
			['getHeader', PortalAssertionVerifier::HEADER],
			['verify', 'jwt'],
			['getParam', 'invoiceId', null],
			['getParam', 'paymentRequestId', null],
		];
	}//end reads()

	/**
	 * No assertion is a 401 and a wrong or missing audience a 403, both before any body read.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		self::assertSame(expected: [401, ['error' => 'unauthorized']], actual: $this->initiate(params: [], setup: ['claims' => null]));
		self::assertSame(expected: [403, ['error' => 'forbidden']], actual: $this->initiate(params: [], setup: ['claims' => ['audience' => 'employee']]));
		self::assertSame(expected: [403, ['error' => 'forbidden']], actual: $this->initiate(params: [], setup: ['claims' => ['sub' => 's1']]));
		$head = [['getHeader', PortalAssertionVerifier::HEADER], ['verify', 'jwt']];
		self::assertSame(expected: array_merge($head, $head, $head), actual: $this->calls);
	}//end testRefusals()

	/**
	 * The invoice id wins; a payment request is used only without one; a non-string invoice id counts as absent.
	 *
	 * @return void
	 */
	public function testTargets(): void {
		$cases = [
			[['invoiceId' => 'i1', 'paymentRequestId' => 'r1'], ['initiate', 's1', 'i1']],
			[['paymentRequestId' => 'r1'], ['initiateForRequest', 's1', 'r1']],
			[['invoiceId' => 5, 'paymentRequestId' => 'r1'], ['initiateForRequest', 's1', 'r1']],
			[['paymentRequestId' => ''], ['initiate', 's1', '']],
			[['paymentRequestId' => 9], ['initiate', 's1', '']],
			[[], ['initiate', 's1', '']],
		];
		foreach ($cases as [$params, $call]) {
			$this->calls = [];
			self::assertSame(expected: [200, ['checkoutUrl' => 'https://pay/1']], actual: $this->initiate(params: $params));
			self::assertSame(expected: array_merge(self::reads(), [$call]), actual: $this->calls);
		}

		$this->calls = [];
		$parent      = $this->initiate(params: ['invoiceId' => 'i2'], setup: ['claims' => ['audience' => 'parent', 'sub' => 's2']]);
		self::assertSame(expected: [200, ['checkoutUrl' => 'https://pay/1']], actual: $parent);
	}//end testTargets()

	/**
	 * Every session outcome maps to its answer; a failure is a logged 502.
	 *
	 * @return void
	 */
	public function testOutcomes(): void {
		$deferred = $this->initiate(params: ['invoiceId' => 'i1'], setup: ['result' => PortalPaymentSessionResult::deferred()]);
		self::assertSame(expected: [503, ['status' => 'deferred']], actual: $deferred);
		$downstream = $this->initiate(params: ['invoiceId' => 'i1'], setup: ['result' => PortalPaymentSessionResult::downstreamError()]);
		self::assertSame(expected: [502, ['error' => 'downstream_error']], actual: $downstream);
		$forbidden = $this->initiate(params: ['invoiceId' => 'i1'], setup: ['result' => PortalPaymentSessionResult::forbidden()]);
		self::assertSame(expected: [403, ['error' => 'forbidden']], actual: $forbidden);
		$this->calls = [];
		self::assertSame(expected: [502, ['error' => 'downstream_error']], actual: $this->initiate(params: ['invoiceId' => 'i1'], setup: ['fail' => true]));
		$log      = ['error', 'Shillinq: portal payment initiation controller caught an unexpected failure', ['exception' => 'psp down']];
		$expected = array_merge(self::reads(), [['initiate', 's1', 'i1'], $log]);
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testOutcomes()
}//end class
