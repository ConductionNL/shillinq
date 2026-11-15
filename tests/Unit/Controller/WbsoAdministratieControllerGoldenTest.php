<?php

/**
 * Golden test for WbsoAdministratieController::realisatie.
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
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\WbsoAdministratieController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\WbsoAdministratieService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Psr\Log\LoggerInterface;

/**
 * Pins every answer of realisatie, allowed and refused, and every call behind it.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class WbsoAdministratieControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Build the controller.
	 *
	 * @param mixed $param   The administration_id parameter value.
	 * @param array $setup   member (bool), fail (bool).
	 *
	 * @return WbsoAdministratieController
	 */
	private function controller(mixed $param, array $setup=[]): WbsoAdministratieController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default=null) use ($param): mixed {
				$this->calls[] = ['getParam', $key, $default];
				return $param;
			}
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return ($setup['member'] ?? true);
			}
		);
		$fail    = ($setup['fail'] ?? false);
		$service = $this->createMock(WbsoAdministratieService::class);
		$service->method('realisatieSummary')->willReturnCallback(
			function (string $administrationId) use ($fail): array {
				$this->calls[] = ['realisatieSummary', $administrationId];
				if ($fail === true) {
					throw new RuntimeException('boom');
				}

				return ['data' => [['b' => 1]], 'total' => 1];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		return new WbsoAdministratieController($request, $service, $logger, $context);
	}//end controller()

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
	 * Missing and malformed identifiers are 400s answered before the membership check.
	 *
	 * @return void
	 */
	public function testInputRefusals(): void {
		$missing = [400, ['error' => 'administration_id is required']];
		self::assertSame(expected: $missing, actual: self::answer($this->controller(param: '  ')->realisatie()));
		self::assertSame(expected: $missing, actual: self::answer($this->controller(param: null)->realisatie()));
		$malformed = [400, ['error' => 'administration_id must be a valid administration identifier']];
		self::assertSame(expected: $malformed, actual: self::answer($this->controller(param: 'a/b')->realisatie()));
		self::assertSame(expected: $malformed, actual: self::answer($this->controller(param: str_repeat('a', 65))->realisatie()));
		$expected = array_fill(0, 4, ['getParam', 'administration_id', '']);
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testInputRefusals()

	/**
	 * A non-member is masked as 404 without touching the service.
	 *
	 * @return void
	 */
	public function testForeignAdministration(): void {
		$actual = self::answer($this->controller(param: ' a-1.b_2 ', setup: ['member' => false])->realisatie());
		self::assertSame(expected: [404, ['error' => 'Administration not found']], actual: $actual);
		$expected = [['getParam', 'administration_id', ''], ['canAccess', 'a-1.b_2']];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testForeignAdministration()

	/**
	 * A member gets the service result as is; a failure is a logged 500 without the trace.
	 *
	 * @return void
	 */
	public function testMemberAnswers(): void {
		$actual = self::answer($this->controller(param: str_repeat('a', 64))->realisatie());
		self::assertSame(expected: [200, ['data' => [['b' => 1]], 'total' => 1]], actual: $actual);
		$this->calls = [];
		$actual = self::answer($this->controller(param: 'a1', setup: ['fail' => true])->realisatie());
		self::assertSame(expected: [500, ['error' => 'Failed to compute WBSO realisatie summary']], actual: $actual);
		$log = ['error', 'WbsoAdministratieController: failed to compute realisatie summary', ['administrationId' => 'a1', 'exception' => 'boom']];
		$expected = [['getParam', 'administration_id', ''], ['canAccess', 'a1'], ['realisatieSummary', 'a1'], $log];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testMemberAnswers()
}//end class
