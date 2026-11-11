<?php

/**
 * Golden test for PortalContributionDeclineController::decline.
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
 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PortalContributionDeclineController;
use OCA\Shillinq\Portal\PortalAssertionVerifier;
use OCA\Shillinq\Service\ContributionDeclineService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every answer of decline, allowed and refused, and every read and call behind it in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class PortalContributionDeclineControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Run decline.
	 *
	 * @param array<string,mixed> $params The body parameters.
	 * @param array<string,mixed> $setup  claims (array|null), result (string), fail (bool).
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function decline(array $params, array $setup=[]): array {
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
		$claims = ['audience' => 'parent', 'sub' => 's1'];
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
		$result  = ($setup['result'] ?? ContributionDeclineService::DECLINED);
		$fail    = ($setup['fail'] ?? false);
		$service = $this->createMock(ContributionDeclineService::class);
		$service->method('decline')->willReturnCallback(
			function (array $claims, string $target) use ($result, $fail): string {
				$this->calls[] = ['decline', $claims['sub'], $target];
				if ($fail === true) {
					throw new RuntimeException('store down');
				}

				return $result;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		$response = (new PortalContributionDeclineController($request, $verifier, $service, $logger))->decline();
		return [$response->getStatus(), $response->getData()];
	}//end decline()

	/**
	 * No assertion is a 401 and a wrong or missing audience a 403, both before the body is read.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		self::assertSame(expected: [401, ['error' => 'unauthorized']], actual: $this->decline(params: [], setup: ['claims' => null]));
		self::assertSame(expected: [403, ['error' => 'forbidden']], actual: $this->decline(params: [], setup: ['claims' => ['audience' => 'employee']]));
		self::assertSame(expected: [403, ['error' => 'forbidden']], actual: $this->decline(params: [], setup: ['claims' => ['sub' => 's1']]));
		$head = [['getHeader', PortalAssertionVerifier::HEADER], ['verify', 'jwt']];
		self::assertSame(expected: array_merge($head, $head, $head), actual: $this->calls);
	}//end testRefusals()

	/**
	 * Every outcome maps to its answer; a non-string invoice id is sent as ''; a failure is a logged 502.
	 *
	 * @return void
	 */
	public function testOutcomes(): void {
		self::assertSame(expected: [200, ['status' => 'declined']], actual: $this->decline(params: ['invoiceId' => 'i1']));
		$this->calls = [];
		$customer    = $this->decline(params: ['invoiceId' => 5], setup: ['claims' => ['audience' => 'customer', 'sub' => 's2']]);
		self::assertSame(expected: [200, ['status' => 'declined']], actual: $customer);
		self::assertSame(expected: ['decline', 's2', ''], actual: $this->calls[3]);
		$downstream = $this->decline(params: ['invoiceId' => 'i1'], setup: ['result' => ContributionDeclineService::DOWNSTREAM_ERROR]);
		self::assertSame(expected: [502, ['error' => 'downstream_error']], actual: $downstream);
		$forbidden = $this->decline(params: ['invoiceId' => 'i1'], setup: ['result' => ContributionDeclineService::FORBIDDEN]);
		self::assertSame(expected: [403, ['error' => 'forbidden']], actual: $forbidden);
		self::assertSame(expected: [403, ['error' => 'forbidden']], actual: $this->decline(params: ['invoiceId' => 'i1'], setup: ['result' => 'other']));
		$this->calls = [];
		self::assertSame(expected: [502, ['error' => 'downstream_error']], actual: $this->decline(params: ['invoiceId' => 'i1'], setup: ['fail' => true]));
		$expected = [
			['getHeader', PortalAssertionVerifier::HEADER],
			['verify', 'jwt'],
			['getParam', 'invoiceId', null],
			['decline', 's1', 'i1'],
			['error', 'Shillinq: portal contribution decline failed unexpectedly', ['exception' => 'store down']],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testOutcomes()
}//end class
