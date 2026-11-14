<?php

/**
 * Golden tests for StandardsPolicyController: the manage / access checks, the policy calls and answers.
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
 * @spec openspec/specs/accounting-standards-policy/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Controller\StandardsPolicyController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\AdministrationManagementAccess;
use OCA\Shillinq\Service\StandardsPolicyService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins both actions, allowed and refused, and every call behind them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class StandardsPolicyControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string,mixed> $setup manage (bool), member (bool), frameworks, fail (Throwable).
	 *
	 * @return StandardsPolicyController
	 */
	private function controller(array $setup=[]): StandardsPolicyController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default=null) use ($setup): mixed {
				$this->calls[] = ['getParam', $key, $default];
				return ($setup['frameworks'] ?? $default);
			}
		);
		$management = $this->createMock(AdministrationManagementAccess::class);
		$management->method('canManage')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canManage', $administrationId];
				return ($setup['manage'] ?? true);
			}
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return ($setup['member'] ?? true);
			}
		);
		$fail     = ($setup['fail'] ?? null);
		$policies = $this->createMock(StandardsPolicyService::class);
		$policies->method('policyFor')->willReturnCallback(
			function (string $administrationId) use ($fail): array {
				$this->calls[] = ['policyFor', $administrationId];
				if ($fail !== null) {
					throw $fail;
				}

				return ['frameworks' => ['f1'], 'other' => 1];
			}
		);
		$policies->method('savePolicy')->willReturnCallback(
			function (string $administrationId, array $frameworks) use ($fail): array {
				$this->calls[] = ['savePolicy', $administrationId, $frameworks];
				if ($fail !== null) {
					throw $fail;
				}

				return ['frameworks' => $frameworks, 'other' => 1];
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text): string => 'T:' . $text);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		return new StandardsPolicyController($request, $policies, $context, $management, $l10n, $logger);
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
	 * Show: a non-member is masked; a member who cannot manage reads with canEdit false; a failure is a logged 500.
	 *
	 * @return void
	 */
	public function testShow(): void {
		$actual = self::answer($this->controller(setup: ['manage' => false, 'member' => false])->show('a1'));
		self::assertSame(expected: [404, ['error' => 'not-found']], actual: $actual);
		self::assertSame(expected: [['canManage', 'a1'], ['canAccess', 'a1']], actual: $this->calls);
		$this->calls = [];
		$expected = [200, ['administrationId' => 'a1', 'frameworks' => ['f1'], 'canEdit' => false]];
		self::assertSame(expected: $expected, actual: self::answer($this->controller(setup: ['manage' => false])->show('a1')));
		$actual = self::answer($this->controller(setup: ['member' => false])->show('a1'));
		self::assertSame(expected: [200, ['administrationId' => 'a1', 'frameworks' => ['f1'], 'canEdit' => true]], actual: $actual);
		$actual = $this->calls;
		$expected = [['canManage', 'a1'], ['canAccess', 'a1'], ['policyFor', 'a1'], ['canManage', 'a1'], ['policyFor', 'a1']];
		self::assertSame(expected: $expected, actual: $actual);
		$actual = self::answer($this->controller(setup: ['fail' => new \RuntimeException('db')])->show('a1'));
		self::assertSame(expected: [500, ['error' => 'load-failed']], actual: $actual);
		$actual = end($this->calls);
		self::assertSame(expected: ['error', 'StandardsPolicyController: failed to load the policy', ['exception' => 'db']], actual: $actual);
	}//end testShow()

	/**
	 * Update: a non-member is masked, a member who cannot manage is forbidden, both before the frameworks are read.
	 *
	 * @return void
	 */
	public function testUpdateRefusals(): void {
		$actual = self::answer($this->controller(setup: ['manage' => false, 'member' => false])->update('a1'));
		self::assertSame(expected: [404, ['error' => 'not-found']], actual: $actual);
		$forbidden = [403, ['error' => 'forbidden', 'message' => 'T:Only an owner of this administration can change its standards policy.']];
		self::assertSame(expected: $forbidden, actual: self::answer($this->controller(setup: ['manage' => false])->update('a1')));
		self::assertSame(expected: [['canManage', 'a1'], ['canAccess', 'a1'], ['canManage', 'a1'], ['canAccess', 'a1']], actual: $this->calls);
		$this->calls = [];
		$actual = self::answer($this->controller(setup: ['frameworks' => 'x'])->update('a1'));
		self::assertSame(expected: [400, ['error' => 'invalid-frameworks']], actual: $actual);
		self::assertSame(expected: [['canManage', 'a1'], ['getParam', 'frameworks', null]], actual: $this->calls);
	}//end testUpdateRefusals()

	/**
	 * Update: the frameworks are saved as a list; invalid ones are a translated 400 and other failures a logged 500.
	 *
	 * @return void
	 */
	public function testUpdateSave(): void {
		$frameworks = ['b' => ['key' => 'IFRS'], 'a' => ['key' => 'NL-GAAP']];
		$expected   = [200, ['administrationId' => 'a1', 'frameworks' => [['key' => 'IFRS'], ['key' => 'NL-GAAP']], 'canEdit' => true]];
		self::assertSame(expected: $expected, actual: self::answer($this->controller(setup: ['frameworks' => $frameworks])->update('a1')));
		self::assertSame(expected: ['savePolicy', 'a1', [['key' => 'IFRS'], ['key' => 'NL-GAAP']]], actual: end($this->calls));
		$invalid = [400, ['error' => 'invalid-frameworks', 'message' => 'T:Each framework needs a key, an on or off state and a position.']];
		$actual = self::answer($this->controller(setup: ['frameworks' => [], 'fail' => new InvalidArgumentException('x')])->update('a1'));
		self::assertSame(expected: $invalid, actual: $actual);
		$actual = self::answer($this->controller(setup: ['frameworks' => [], 'fail' => new \RuntimeException('db')])->update('a1'));
		self::assertSame(expected: [500, ['error' => 'save-failed']], actual: $actual);
		$actual = end($this->calls);
		self::assertSame(expected: ['error', 'StandardsPolicyController: failed to save the policy', ['exception' => 'db']], actual: $actual);
	}//end testUpdateSave()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
