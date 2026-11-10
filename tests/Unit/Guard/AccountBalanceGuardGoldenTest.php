<?php

/**
 * Golden tests for AccountBalanceGuard: queries, paging, row shapes and log lines.
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
 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\AccountBalanceGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every lookup, answer and log line of the two preconditions.
 */
class AccountBalanceGuardGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * Build a guard over a recording store.
	 *
	 * @param array<int,mixed>|\Throwable $rows All rows (paged by limit/offset), or the failure.
	 *
	 * @return AccountBalanceGuard
	 */
	private function guard(array|\Throwable $rows): AccountBalanceGuard {
		$store     = new class($this, $rows) {
			/**
			 * Construct.
			 *
			 * @param AccountBalanceGuardGoldenTest $test Recorder.
			 * @param array<int,mixed>|\Throwable   $rows Rows.
			 */
			public function __construct(private AccountBalanceGuardGoldenTest $test, private array|\Throwable $rows) {
			}//end __construct()

			/**
			 * Record.
			 *
			 * @param string $register Slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->test->calls[] = ['setRegister', $register];
				return $this;
			}//end setRegister()

			/**
			 * Record.
			 *
			 * @param string $schema Slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->test->calls[] = ['setSchema', $schema];
				return $this;
			}//end setSchema()

			/**
			 * Record and answer.
			 *
			 * @param array<string,mixed> $params Query.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $params=[]): array {
				$this->test->calls[] = ['findAll', $params];
				if ($this->rows instanceof \Throwable) {
					throw $this->rows;
				}

				if (isset($params['limit']) === false) {
					return $this->rows;
				}

				return array_slice($this->rows, (int)$params['offset'], (int)$params['limit']);
			}//end findAll()
		};
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('reg-c');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		return new AccountBalanceGuard(appConfig: $appConfig, logger: $logger, objectService: new DuckObjectServiceAdapter(inner: $store));
	}//end guard()

	/**
	 * A serialisable row.
	 *
	 * @param mixed $payload What jsonSerialize() answers.
	 *
	 * @return \JsonSerializable
	 */
	private static function entity(mixed $payload): \JsonSerializable {
		return new class($payload) implements \JsonSerializable {
			/**
			 * Construct.
			 *
			 * @param mixed $payload Payload.
			 */
			public function __construct(private mixed $payload) {
			}//end __construct()

			/**
			 * Serialise.
			 *
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return $this->payload;
			}//end jsonSerialize()
		};
	}//end entity()

	/**
	 * GLLines are paged by 500 and summed in cents across row shapes.
	 *
	 * @return void
	 */
	public function testBalancePagingAndCents(): void {
		$rows = [];
		for ($i = 0; $i < 499; $i++) {
			$rows[] = ['debit' => '0.10'];
		}

		$rows[] = self::entity(payload: ['credit' => 49.9]);
		$rows[] = self::entity(payload: 'scalar');
		$rows[] = ['debit' => 0.1, 'credit' => 0.1];
		self::assertTrue(condition: $this->guard(rows: $rows)->requireZeroBalance(['accountNumber' => '1000', 'administrationId' => 'a1']));
		$filters = ['accountNumber' => '1000', 'administrationId' => 'a1'];
		self::assertSame(
			expected: [
				['setRegister', 'reg-c'],
				['setSchema', 'GLLine'],
				['findAll', ['filters' => $filters, 'limit' => 500, 'offset' => 0]],
				['setRegister', 'reg-c'],
				['setSchema', 'GLLine'],
				['findAll', ['filters' => $filters, 'limit' => 500, 'offset' => 500]],
			],
			actual: $this->calls
		);
		self::assertFalse(condition: $this->guard(rows: [['debit' => '0.004'], ['debit' => '0.006']])->requireZeroBalance([]));
		$blank = ['filters' => ['accountNumber' => '', 'administrationId' => ''], 'limit' => 500, 'offset' => 0];
		self::assertSame(expected: $blank, actual: end($this->calls)[1]);
	}//end testBalancePagingAndCents()

	/**
	 * A failed lookup denies the archive and logs the error.
	 *
	 * @return void
	 */
	public function testBalanceFailureLogged(): void {
		self::assertFalse(condition: $this->guard(rows: new \RuntimeException('db'))->requireZeroBalance([]));
		self::assertSame(
			expected: ['error', 'AccountBalanceGuard: balance computation failed — denying archive (fail-closed)', ['exception' => 'db']],
			actual: end($this->calls)
		);
	}//end testBalanceFailureLogged()

	/**
	 * Only an account marked closing is checked; the query is exact.
	 *
	 * @return void
	 */
	public function testClosingQuery(): void {
		self::assertTrue(condition: $this->guard(rows: [['id' => 'x']])->requireSingleClosingAccount(['isClosingAccount' => 'true']));
		self::assertSame(expected: [], actual: $this->calls);
		self::assertTrue(condition: $this->guard(rows: [])->requireSingleClosingAccount(['isClosingAccount' => true]));
		self::assertSame(
			expected: [['setRegister', 'reg-c'], ['setSchema', 'Account'], ['findAll', ['filters' => ['administrationId' => '', 'isClosingAccount' => true]]]],
			actual: $this->calls
		);
	}//end testClosingQuery()

	/**
	 * Other administrations and the record itself (by id, else by number) are not a second closing account.
	 *
	 * @return void
	 */
	public function testClosingExclusions(): void {
		$byId = ['isClosingAccount' => true, 'id' => 'me', 'accountNumber' => '9999', 'administrationId' => 'a1'];
		$selfAndForeign = [['id' => 'me', 'administrationId' => 'a1'], ['id' => 'o', 'administrationId' => 'a2']];
		self::assertTrue(condition: $this->guard(rows: $selfAndForeign)->requireSingleClosingAccount($byId));
		$sameNumber = [['id' => 'o', 'accountNumber' => '9999', 'administrationId' => 'a1']];
		self::assertFalse(condition: $this->guard(rows: $sameNumber)->requireSingleClosingAccount($byId));
		$byNumber = ['isClosingAccount' => true, 'accountNumber' => '9999', 'administrationId' => 'a1'];
		$sameNumberEntity = [self::entity(payload: $sameNumber[0])];
		self::assertTrue(condition: $this->guard(rows: $sameNumberEntity)->requireSingleClosingAccount($byNumber));
		self::assertFalse(condition: $this->guard(rows: [['accountNumber' => '9998', 'administrationId' => 'a1']])->requireSingleClosingAccount($byNumber));
		$noAdmin = ['isClosingAccount' => true, 'id' => 'me'];
		self::assertFalse(condition: $this->guard(rows: [self::entity(payload: 'scalar')])->requireSingleClosingAccount($noAdmin));
		self::assertTrue(condition: $this->guard(rows: [['id' => 'me', 'administrationId' => 'zz']])->requireSingleClosingAccount($noAdmin));
	}//end testClosingExclusions()

	/**
	 * A failed closing-account lookup denies the save and logs the error.
	 *
	 * @return void
	 */
	public function testClosingFailureLogged(): void {
		self::assertFalse(condition: $this->guard(rows: new \RuntimeException('gone'))->requireSingleClosingAccount(['isClosingAccount' => true]));
		self::assertSame(
			expected: ['error', 'AccountBalanceGuard: closing-account uniqueness check failed — denying save (fail-closed)', ['exception' => 'gone']],
			actual: end($this->calls)
		);
	}//end testClosingFailureLogged()
}//end class
