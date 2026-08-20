<?php

/**
 * Unit tests for AccountBalanceGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-shillinq-chart-of-accounts/specs/bookkeeping-chart-of-accounts/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Guard\AccountBalanceGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AccountBalanceGuard.
 *
 * Covers:
 * - Float-to-cents fix: 0.1 + 0.2 - 0.3 is balanced
 * - GLLine register unavailable → archive permitted (T1 deferral)
 * - requireZeroBalance returns false when balance is non-zero
 * - requireSingleClosingAccount invariant
 * - Fail-closed on exception
 */
class AccountBalanceGuardTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var AccountBalanceGuard
	 */
	private AccountBalanceGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Default: return the canonical register slug.
		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = $this->buildGuard(
			store: $this->buildObjectServiceStub(lines: [], closingAccounts: [])
		);

	}//end setUp()

	/**
	 * Build the guard over a seeded in-memory store.
	 *
	 * ADR-084 injects the ObjectService through the constructor, so a test's
	 * store has to be present when the guard is built — parking it on the
	 * container after the fact leaves the guard reading an empty world.
	 *
	 * @param object $store The duck-typed in-memory ObjectService double.
	 *
	 * @return AccountBalanceGuard
	 */
	private function buildGuard(object $store): AccountBalanceGuard {
		return new AccountBalanceGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end buildGuard()

	/**
	 * requireZeroBalance returns true when the GLLine register is unavailable (T1).
	 *
	 * @return void
	 */
	public function testRequireZeroBalancePermitsArchiveInT1State(): void {
		// ADR-084 deleted the container probe this test used to simulate: with the
		// contract injected non-nullably the guard can no longer distinguish "the
		// GLLine schema is absent" from "the account has no GLLine rows". An empty
		// store is what the guard actually observes in a T1 state, and it is still
		// the case that an account carrying no postings has a zero balance.
		$this->guard = $this->buildGuard(
			store: $this->buildObjectServiceStub(lines: [], closingAccounts: [])
		);

		$result = $this->guard->requireZeroBalance(['accountNumber' => '0001', 'administrationId' => 'adm-1']);

		self::assertTrue($result, 'T1 state: archive should be permitted when GLLine register is absent');

	}//end testRequireZeroBalancePermitsArchiveInT1State()

	/**
	 * requireZeroBalance uses integer-cent arithmetic so 0.1 + 0.2 - 0.3 is treated as balanced (C1).
	 *
	 * IEEE-754: (float)(0.1 + 0.2 - 0.3) !== 0.0, but (int)round(0.1*100) + (int)round(0.2*100) - (int)round(0.3*100) === 0.
	 *
	 * @return void
	 */
	public function testRequireZeroBalanceTreatsFloatRoundingAsBalanced(): void {
		$lines = [
			['debit' => 0.1, 'credit' => 0.0],
			['debit' => 0.2, 'credit' => 0.0],
			['debit' => 0.0, 'credit' => 0.3],
		];

		// The ObjectService stub: setRegister/setSchema returns itself, findAll returns $lines.
		$objectService = $this->buildObjectServiceStub(lines: $lines, closingAccounts: []);
		$this->guard = $this->buildGuard(store: $objectService);

		$result = $this->guard->requireZeroBalance(['accountNumber' => '0001', 'administrationId' => 'adm-1']);

		self::assertTrue($result, 'C1: 0.1+0.2-0.3 must be treated as balanced via integer-cent arithmetic');

	}//end testRequireZeroBalanceTreatsFloatRoundingAsBalanced()

	/**
	 * requireZeroBalance returns false when debit > credit (actual non-zero balance).
	 *
	 * @return void
	 */
	public function testRequireZeroBalanceReturnsFalseForNonZeroBalance(): void {
		$lines = [
			['debit' => 100.0, 'credit' => 0.0],
		];

		$objectService = $this->buildObjectServiceStub(lines: $lines, closingAccounts: []);
		$this->guard = $this->buildGuard(store: $objectService);

		$result = $this->guard->requireZeroBalance(['accountNumber' => '0001', 'administrationId' => 'adm-1']);

		self::assertFalse($result, 'Non-zero balance must deny archive');

	}//end testRequireZeroBalanceReturnsFalseForNonZeroBalance()

	/**
	 * requireZeroBalance is fail-closed: returns false (denies archive) on exception.
	 *
	 * The store throws, so the outer try/catch must return false.
	 *
	 * The previous version also asserted `container->get()` was called exactly
	 * twice — a T1 probe plus the computation. ADR-084 deleted both the probe
	 * and the container, and the guard now receives its ObjectService through
	 * the constructor, so nothing resolves a container at all. That expectation
	 * could never fire again: it was not coverage, it was a guaranteed failure
	 * describing an architecture that no longer exists. Removed rather than
	 * relaxed; the assertion that matters — fail-closed on exception — is
	 * untouched and is what the store's throwing findAll() now exercises.
	 *
	 * @return void
	 */
	public function testRequireZeroBalanceIsFailClosedOnException(): void {
		// Computation call: throws so the outer try-catch returns false (fail-closed).
		$throwStub = $this->buildObjectServiceStubThatThrows();

		$this->guard = $this->buildGuard(store: $throwStub);

		$result = $this->guard->requireZeroBalance(['accountNumber' => '0001', 'administrationId' => 'adm-1']);

		self::assertFalse($result, 'Fail-closed: exception must deny archive');

	}//end testRequireZeroBalanceIsFailClosedOnException()

	/**
	 * requireSingleClosingAccount returns true trivially when account is not a closing account.
	 *
	 * @return void
	 */
	public function testRequireSingleClosingAccountPermitsNonClosingAccount(): void {
		// The data layer must not be touched for non-closing accounts. Asserted
		// against the ObjectService the guard actually holds — the old
		// `container->expects($this->never())->method('get')` was vacuously true
		// after ADR-084, because nothing consults a container any more, so it
		// would have stayed green even if the guard queried on every call.
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects($this->never())->method('findAll');
		$objectService->expects($this->never())->method('find');

		$this->guard = new AccountBalanceGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $objectService,
		);

		$result = $this->guard->requireSingleClosingAccount(['isClosingAccount' => false]);

		self::assertTrue($result);

	}//end testRequireSingleClosingAccountPermitsNonClosingAccount()

	/**
	 * requireSingleClosingAccount permits save when no other closing account exists.
	 *
	 * @return void
	 */
	public function testRequireSingleClosingAccountPermitsFirstClosingAccount(): void {
		$objectService = $this->buildObjectServiceStub(lines: [], closingAccounts: []);
		$this->guard = $this->buildGuard(store: $objectService);

		$result = $this->guard->requireSingleClosingAccount([
			'isClosingAccount' => true,
			'accountNumber' => 'CLOSE',
			'administrationId' => 'adm-1',
		]);

		self::assertTrue($result);

	}//end testRequireSingleClosingAccountPermitsFirstClosingAccount()

	/**
	 * requireSingleClosingAccount denies save when another closing account exists.
	 *
	 * @return void
	 */
	public function testRequireSingleClosingAccountDeniesDuplicateClosingAccount(): void {
		$existingClosing = [
			['id' => 'other-uuid', 'accountNumber' => 'CLOSE-OLD', 'administrationId' => 'adm-1', 'isClosingAccount' => true],
		];
		$objectService = $this->buildObjectServiceStub(lines: [], closingAccounts: $existingClosing);
		$this->guard = $this->buildGuard(store: $objectService);

		$result = $this->guard->requireSingleClosingAccount([
			'isClosingAccount' => true,
			'id' => 'new-uuid',
			'accountNumber' => 'CLOSE-NEW',
			'administrationId' => 'adm-1',
		]);

		self::assertFalse($result, 'A second closing account must be denied');

	}//end testRequireSingleClosingAccountDeniesDuplicateClosingAccount()

	/**
	 * Build an anonymous ObjectService stub that returns the given lines and closingAccounts
	 * for the respective findAll() calls. Implements the fluent setRegister/setSchema interface.
	 *
	 * @param array<mixed> $lines GLLine records to return for balance queries.
	 * @param array<mixed> $closingAccounts Account records to return for uniqueness queries.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $lines, array $closingAccounts): object {
		return new class($lines, $closingAccounts) {
			private array $lines;
			private array $closingAccounts;
			private string $currentSchema = '';

			public function __construct(array $lines, array $closingAccounts) {
				$this->lines = $lines;
				$this->closingAccounts = $closingAccounts;
			}

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				return $this;
			}

			/**
			 * @param array<string,mixed> $params
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				if ($this->currentSchema === 'GLLine') {
					return $this->lines;
				}
				return $this->closingAccounts;
			}
		};
	}//end buildObjectServiceStub()

	/**
	 * Build an ObjectService stub that throws on findAll() only when filters are
	 * present (i.e. the actual balance-computation query), allowing the T1-probe
	 * call (no filters) to succeed so we reach the fail-closed catch branch.
	 *
	 * @return object
	 */
	private function buildObjectServiceStubThatThrows(): object {
		return new class {
			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * @param array<string,mixed> $params
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				// Allow the availability probe (no filters) to succeed so requireZeroBalance
				// enters the balance-computation branch and can test fail-closed behavior.
				if (empty($params['filters']) === true) {
					return [];
				}

				throw new \RuntimeException('DB error');
			}
		};
	}//end buildObjectServiceStubThatThrows()
}//end class
