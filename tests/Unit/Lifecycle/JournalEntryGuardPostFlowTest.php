<?php

/**
 * Characterisation of JournalEntryGuard::canPost() around its edges.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\JournalEntryGuard;
use OCA\Shillinq\Lifecycle\PostingRefusedException;
use OCA\Shillinq\Lifecycle\LineDimensionCheck;
use OCA\Shillinq\Lifecycle\PostingRestrictionGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the object-only call form, the booking-rule hand-off and both
 * exception paths of canPost().
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class JournalEntryGuardPostFlowTest extends TestCase {

	/**
	 * The booking-rule double.
	 *
	 * @var PostingRestrictionGuard&MockObject
	 */
	private PostingRestrictionGuard&MockObject $restrictions;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the guard over a store returning the given JournalEntry rows.
	 *
	 * @param array<int,mixed> $rows JournalEntry rows.
	 *
	 * @return JournalEntryGuard
	 */
	private function guard(array $rows = []): JournalEntryGuard {
		$store = new class($rows) {
			/**
			 * @param array<int,mixed> $rows Rows.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * @param string $register Register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}

			/**
			 * @param string $schema Schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * @param array<string,mixed> $params Query.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $params = []): array {
				return $this->rows;
			}
		};
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$this->restrictions = $this->createMock(PostingRestrictionGuard::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		return new JournalEntryGuard(
			appConfig: $appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
			restrictions: $this->restrictions,
			dimensions: $this->createMock(LineDimensionCheck::class),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function balanced(): array {
		return [['side' => 'debit', 'amount' => 10, 'accountNumber' => '4500'], ['side' => 'credit', 'amount' => '10.00']];
	}

	/**
	 * The object-only form takes the id from @self and hands the booking rules the entry fields.
	 *
	 * @return void
	 */
	public function testObjectOnlyCallHandsTheBookingRulesTheEntryFields(): void {
		$guard = $this->guard();
		$entry = ['@self' => ['id' => 'je-9'], 'lines' => ['a' => self::balanced()[0], 'b' => self::balanced()[1]],
			'administrationId' => 'adm-1', 'entryDate' => '2026-05-01', 'sourceApp' => 'pos'];
		$this->restrictions->expects($this->once())->method('assertAllowed')
			->with(self::balanced(), 'adm-1', '2026-05-01', 'pos');
		self::assertTrue($guard->canPost($entry));
	}

	/**
	 * Without inline lines, the first stored entry with a line list is used; no id means no lines.
	 *
	 * @return void
	 */
	public function testLinesAreReadFromTheStoreWhenTheObjectCarriesNone(): void {
		$guard = $this->guard([['lines' => 'not-a-list'], ['lines' => self::balanced()]]);
		$this->restrictions->expects($this->once())->method('assertAllowed')->with(self::balanced(), '', '', '');
		self::assertTrue($guard->canPost('je-1'));
		self::assertFalse($this->guard([['lines' => self::balanced()]])->canPost(''));
	}

	/**
	 * Malformed, unbalanced, zero or wrongly-cased lines are refused before the booking rules.
	 *
	 * @return void
	 */
	public function testUnbalancedOrMalformedLinesNeverReachTheBookingRules(): void {
		$guard = $this->guard();
		$this->restrictions->expects($this->never())->method('assertAllowed');
		self::assertFalse($guard->canPost('je-1', ['lines' => [self::balanced()[0], 'oops']]));
		self::assertFalse($guard->canPost('je-1', ['lines' => [self::balanced()[0], ['side' => 'credit', 'amount' => 9.99]]]));
		self::assertFalse($guard->canPost('je-1', ['lines' => [['side' => 'debit'], ['side' => 'credit']]]));
		self::assertFalse($guard->canPost('je-1', ['lines' => [['side' => 'Debit', 'amount' => 1], ['side' => 'credit', 'amount' => 1]]]));
	}

	/**
	 * A booking-rule refusal is rethrown, naming the rule.
	 *
	 * @return void
	 */
	public function testABookingRuleRefusalPropagates(): void {
		$guard = $this->guard();
		$this->restrictions->method('assertAllowed')->willThrowException(new PostingRefusedException('control account'));
		$this->expectException(PostingRefusedException::class);
		$guard->canPost('je-1', ['lines' => self::balanced()]);
	}

	/**
	 * Any other failure is logged with the entry id and denied.
	 *
	 * @return void
	 */
	public function testAnyOtherFailureIsLoggedAndDenied(): void {
		$guard = $this->guard();
		$this->restrictions->method('assertAllowed')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('fail-closed'), ['journalEntryId' => 'je-7', 'exception' => 'boom']);
		self::assertFalse($guard->canPost(['id' => 'je-7', 'lines' => self::balanced()]));
	}
}
