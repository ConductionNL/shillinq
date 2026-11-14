<?php

/**
 * Golden test for the LeaseReassessmentController actions.
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
 * @spec openspec/changes/revive-lease-capabilities/specs/revive-lease-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\LeaseReassessmentController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\LeaseReassessmentService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every action, allowed and refused, with every parameter read and service call in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class LeaseReassessmentControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * The parameters every allowed call starts from.
	 *
	 * @var array<string,mixed>
	 */
	private const BASE = ['administration_id' => ' a1 ', 'lease_id' => 'l1', 'trigger_description' => ' why ', 'approver' => 'p1'];

	/**
	 * Build the controller.
	 *
	 * @param array<string,mixed> $params The request parameters.
	 * @param array<string,mixed> $setup  user (bool), member (bool), result (array|null), fail (bool).
	 *
	 * @return LeaseReassessmentController
	 */
	private function controller(array $params, array $setup=[]): LeaseReassessmentController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default=null) use ($params): mixed {
				$this->calls[] = ['getParam', $key, $default];
				if (array_key_exists($key, $params) === true) {
					return $params[$key];
				}

				return $default;
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if (($setup['user'] ?? true) === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturnCallback(
			function () use ($user): ?IUser {
				$this->calls[] = ['getUser'];
				return $user;
			}
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return ($setup['member'] ?? true);
			}
		);
		$service = $this->createMock(LeaseReassessmentService::class);
		$methods = ['recordIndexationEvent', 'recordExtensionOptionReassessment', 'recordModification', 'recordImpairment'];
		foreach ($methods as $method) {
			$service->method($method)->willReturnCallback(
				function (mixed ...$args) use ($method, $setup): ?array {
					$this->calls[] = array_merge([$method], $args);
					if (($setup['fail'] ?? false) === true) {
						throw new RuntimeException('store down');
					}

					if (array_key_exists('result', $setup) === true) {
						return $setup['result'];
					}

					return ['event' => $method];
				}
			);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		return new LeaseReassessmentController($request, $service, $context, $session, $logger);
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
	 * The calls of a scope that passes: session, administration, membership, lease.
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private static function scopeCalls(): array {
		return [['getUser'], ['getParam', 'administration_id', ''], ['canAccess', 'a1'], ['getParam', 'lease_id', '']];
	}//end scopeCalls()

	/**
	 * The shared scope refusals, in order, on every action.
	 *
	 * @return void
	 */
	public function testScopeRefusals(): void {
		foreach (['indexation', 'extensionOption', 'modification', 'impairment'] as $action) {
			$this->calls = [];
			$actual      = self::answer($this->controller(params: self::BASE, setup: ['user' => false])->$action());
			self::assertSame(expected: [401, ['error' => 'Not logged in']], actual: $actual);
			$actual = self::answer($this->controller(params: ['administration_id' => 'a/1'])->$action());
			self::assertSame(expected: [400, ['error' => 'administration_id is required']], actual: $actual);
			$actual = self::answer($this->controller(params: self::BASE, setup: ['member' => false])->$action());
			self::assertSame(expected: [404, ['error' => 'Administration not found']], actual: $actual);
			$actual = self::answer($this->controller(params: ['administration_id' => 'a1', 'lease_id' => ' '])->$action());
			self::assertSame(expected: [400, ['error' => 'lease_id is required']], actual: $actual);
			$expected = [
				['getUser'],
				['getUser'],
				['getParam', 'administration_id', ''],
				['getUser'],
				['getParam', 'administration_id', ''],
				['canAccess', 'a1'],
				['getUser'],
				['getParam', 'administration_id', ''],
				['canAccess', 'a1'],
				['getParam', 'lease_id', ''],
			];
			self::assertSame(expected: $expected, actual: $this->calls);
		}
	}//end testScopeRefusals()

	/**
	 * Each action's own 400, read after the scope and before any service call.
	 *
	 * @return void
	 */
	public function testActionRefusals(): void {
		$cases = [
			['indexation', ['new_payment_amount' => '0'], 'new_payment_amount', 0, 'new_payment_amount must be a positive amount'],
			['indexation', ['new_payment_amount' => -1], 'new_payment_amount', 0, 'new_payment_amount must be a positive amount'],
			['extensionOption', ['extension_options' => 'x'], 'extension_options', [], 'extension_options must be an array'],
			['modification', ['new_terms' => []], 'new_terms', [], 'new_terms must be a non-empty object'],
			['modification', ['new_terms' => 'x'], 'new_terms', [], 'new_terms must be a non-empty object'],
			['impairment', [], 'recoverable_value', null, 'recoverable_value must be a number'],
			['impairment', ['recoverable_value' => 'abc'], 'recoverable_value', null, 'recoverable_value must be a number'],
		];
		foreach ($cases as [$action, $params, $key, $default, $error]) {
			$this->calls = [];
			$actual      = self::answer($this->controller(params: array_merge(self::BASE, $params))->$action());
			self::assertSame(expected: [400, ['error' => $error]], actual: $actual);
			self::assertSame(expected: array_merge(self::scopeCalls(), [['getParam', $key, $default]]), actual: $this->calls);
		}
	}//end testActionRefusals()

	/**
	 * Each action's service call and parameter order, then the 201.
	 *
	 * @return void
	 */
	public function testRecorded(): void {
		$tail  = [['getParam', 'trigger_description', ''], ['getParam', 'approver', '']];
		$cases = [
			[
				'indexation',
				['new_payment_amount' => '12.5'],
				[['getParam', 'new_payment_amount', 0]],
				['recordIndexationEvent', 'l1', 'a1', 12.5, 'why', 'p1'],
			],
			[
				'extensionOption',
				['extension_options' => [['o' => 1]]],
				[['getParam', 'extension_options', []]],
				['recordExtensionOptionReassessment', 'l1', 'a1', [['o' => 1]], 'why', 'p1'],
			],
			[
				'modification',
				['new_terms' => ['t' => 1]],
				[['getParam', 'new_terms', []], ['getParam', 'approach', '']],
				['recordModification', 'l1', 'a1', ['t' => 1], 'catch-up-adjustment', 'why', 'p1'],
			],
			[
				'modification',
				['new_terms' => ['t' => 1], 'approach' => ' retro '],
				[['getParam', 'new_terms', []], ['getParam', 'approach', '']],
				['recordModification', 'l1', 'a1', ['t' => 1], 'retro', 'why', 'p1'],
			],
			['impairment', ['recoverable_value' => '7'], [['getParam', 'recoverable_value', null]], ['recordImpairment', 'l1', 'a1', 7.0, 'why', 'p1']],
		];
		foreach ($cases as [$action, $params, $reads, $call]) {
			$this->calls = [];
			$actual      = self::answer($this->controller(params: array_merge(self::BASE, $params))->$action());
			self::assertSame(expected: [201, ['event' => $call[0]]], actual: $actual);
			self::assertSame(expected: array_merge(self::scopeCalls(), $reads, $tail, [$call]), actual: $this->calls);
		}
	}//end testRecorded()

	/**
	 * A lease outside the administration is a 404; a failure is a logged 500.
	 *
	 * @return void
	 */
	public function testNotFoundAndFailure(): void {
		$params = array_merge(self::BASE, ['recoverable_value' => 3]);
		$actual = self::answer($this->controller(params: $params, setup: ['result' => null])->impairment());
		self::assertSame(expected: [404, ['error' => 'Lease not found']], actual: $actual);
		$this->calls = [];
		$actual      = self::answer($this->controller(params: $params, setup: ['fail' => true])->impairment());
		self::assertSame(expected: [500, ['error' => 'Could not record the lease remeasurement']], actual: $actual);
		$log = ['error', 'LeaseReassessmentController: failed to record the lease remeasurement', ['exception' => 'store down']];
		self::assertSame(expected: $log, actual: $this->calls[count($this->calls) - 1]);
	}//end testNotFoundAndFailure()
}//end class
