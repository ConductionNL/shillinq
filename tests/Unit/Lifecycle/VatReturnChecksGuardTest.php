<?php

/**
 * VatReturnChecksGuard: submit is refused while a blocking check fails,
 * naming it, allowed when only warnings fail, and refused when the checks
 * cannot run.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\Shillinq\Lifecycle\VatReturnChecksGuard;
use OCA\Shillinq\Listener\Registration\VatReturnGuardServices;
use OCA\Shillinq\Standards\Checks\VatReturnChecks;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The submit guard of a VAT return.
 *
 * @covers \OCA\Shillinq\Lifecycle\VatReturnChecksGuard
 * @covers \OCA\Shillinq\Listener\Registration\VatReturnGuardServices
 * @uses   \OCA\Shillinq\Standards\Checks\VatReturnChecks
 */
final class VatReturnChecksGuardTest extends TestCase {

	/**
	 * A guard over checks that answer these results, or throw.
	 *
	 * @param list<array<string, mixed>>|null $results The results; null throws.
	 * @param LoggerInterface|null            $logger  The logger.
	 *
	 * @return VatReturnChecksGuard
	 */
	private function guard(?array $results, ?LoggerInterface $logger = null): VatReturnChecksGuard {
		$checks = $this->createMock(VatReturnChecks::class);
		if ($results === null) {
			$checks->method('run')->willThrowException(new RuntimeException('boom'));
		} else {
			$checks->expects($this->once())->method('run')->with(['id' => 'ret-q3'])->willReturn($results);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new VatReturnChecksGuard(checks: $checks, l10n: $l10n, logger: ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end guard()

	/**
	 * A check result.
	 *
	 * @param string $id       The check.
	 * @param string $severity Its severity.
	 * @param bool   $passed   Whether it passed.
	 *
	 * @return array<string, mixed>
	 */
	private static function outcome(string $id, string $severity, bool $passed): array {
		return ['id' => $id, 'severity' => $severity, 'passed' => $passed, 'message' => $passed ? 'Passed' : 'MEM-7'];
	}//end result()

	/**
	 * A failing blocking check refuses, naming the check and its message.
	 *
	 * @return void
	 */
	public function testAFailingBlockingCheckRefusesNamingIt(): void {
		$guard = $this->guard([
			self::outcome('vat-equals-base-times-rate', 'warning', false),
			self::outcome('vat-line-has-box', 'blocking', false),
		]);

		$result = $guard->check(['id' => 'ret-q3'], 'submit', 'controller');

		$this->assertInstanceOf(LifecycleGuardInterface::class, $guard);
		$this->assertFalse($result->isAllowed());
		$this->assertSame('The check "vat-line-has-box" failed: MEM-7', $result->getMessage());
	}//end testAFailingBlockingCheckRefusesNamingIt()

	/**
	 * Only failing warnings do not refuse.
	 *
	 * @return void
	 */
	public function testFailingWarningsDoNotRefuse(): void {
		$guard = $this->guard([self::outcome('no-unposted-documents', 'warning', false), self::outcome('vat-line-has-box', 'blocking', true)]);

		$this->assertTrue($guard->check(['id' => 'ret-q3'], 'submit', 'controller')->isAllowed());
	}//end testFailingWarningsDoNotRefuse()

	/**
	 * Checks that cannot run refuse, and the failure is logged.
	 *
	 * @return void
	 */
	public function testChecksThatCannotRunRefuse(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('VatReturnChecksGuard: checks did not run, submit refused', ['exception' => 'boom']);

		$result = $this->guard(null, $logger)->check(['id' => 'ret-q3'], 'submit', 'controller');

		$this->assertFalse($result->isAllowed());
		$this->assertSame('The checks of this return could not run, so it cannot be submitted.', $result->getMessage());
	}//end testChecksThatCannotRunRefuse()

	/**
	 * The register's `requires` tag resolves to the guard.
	 *
	 * @return void
	 */
	public function testTheSubmitTagResolvesToTheGuard(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$registered): void {
				$registered[$name] = $factory;
			}
		);

		(new VatReturnGuardServices())->register($context);

		$guard = $this->guard([]);
		$container = new class($guard) {
			/**
			 * Constructor.
			 *
			 * @param object $guard The guard.
			 */
			public function __construct(private readonly object $guard) {
			}

			/**
			 * The guard, for its class name.
			 *
			 * @param string $id The service id.
			 *
			 * @return object
			 */
			public function get(string $id): object {
				return $this->guard;
			}
		};

		$this->assertSame(['OCA\Shillinq\Lifecycle\VatReturnChecksGuard::check'], array_keys($registered));
		$this->assertSame($guard, $registered[VatReturnGuardServices::SUBMIT_TAG]($container));
		$guard->check(['id' => 'ret-q3'], 'submit', 'controller');

		$register = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/register.d/bookkeeping-vat-btw-filing.json'), true);
		$submit = $register['components']['schemas']['BtwAangifte']['x-openregister-lifecycle']['transitions']['submit'];
		$this->assertSame(VatReturnGuardServices::SUBMIT_TAG, $submit['requires']);
	}//end testTheSubmitTagResolvesToTheGuard()
}//end class
