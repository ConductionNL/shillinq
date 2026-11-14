<?php

/**
 * Gap tests for FidoTreasuryGuard: unresolvable subjects, fail-closed catches,
 * malformed RUDDO amounts, malformed mandate matrices and the register fallback.
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
 * @spec openspec/changes/bookkeeping-wet-fido-treasury/specs/bookkeeping-wet-fido-treasury/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\FidoTreasuryGuard;
use OCA\Shillinq\Lifecycle\GuardObjectLookup;
use OCA\Shillinq\Lifecycle\GuardSubject;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the branches the main FidoTreasuryGuard suite leaves open
 * (REQ-FDO-001 / D5, REQ-FDO-004 / D6, REQ-FDO-006 / D8).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(FidoTreasuryGuard::class)]
#[UsesClass(GuardObjectLookup::class)]
#[UsesClass(GuardSubject::class)]
final class FidoTreasuryGuardGapsTest extends TestCase {

	/**
	 * Error log lines, as [message, context].
	 *
	 * @var list<array{0:string,1:array<string,mixed>}>
	 */
	private array $errors = [];

	/**
	 * The recording duck behind the adapter.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * Build the guard over per-schema rows.
	 *
	 * @param array<string,mixed> $rows     Rows per schema; a Throwable is thrown by findAll.
	 * @param string              $register What IAppConfig answers for the register.
	 *
	 * @return FidoTreasuryGuard
	 */
	private function guard(array $rows, string $register = 'shillinq'): FidoTreasuryGuard {
		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$this->store = new class($rows) {
			/** @var list<array{0:string,1:string,2:array<string,mixed>}> register, schema and query per findAll */
			public array $queries = [];

			private string $register = '';

			private string $schema = '';

			public function __construct(private readonly array $rows) {
			}

			public function setRegister(string $register): static {
				$this->register = $register;
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $params = []): array {
				$this->queries[] = [$this->register, $this->schema, $params];
				$answer = ($this->rows[$this->schema] ?? []);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn($register);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, $context];
			}
		);

		return new FidoTreasuryGuard($appConfig, $logger, new DuckObjectServiceAdapter($this->store));

	}//end guard()

	/**
	 * An adopted statuut with a treasurer kasgeld mandate up to 5M.
	 *
	 * @param mixed $mandates The signingMandates value.
	 *
	 * @return array<string,list<array<string,mixed>>>
	 */
	private static function statuut(mixed $mandates): array {
		return ['Treasurystatuut' => [['id' => 'ts-1', 'status' => 'adopted', 'signingMandates' => $mandates]]];
	}//end statuut()

	/**
	 * A lening for the treasurer.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return array<string,mixed>
	 */
	private static function lening(array $overrides = []): array {
		return $overrides + [
			'signingMandateRole' => 'treasurer',
			'type' => 'kasgeld',
			'principal' => 1000000,
			'treasuryStatuteId' => 'ts-1',
		];
	}//end lening()

	/**
	 * A complete RUDDO-compliant derivaat.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return array<string,mixed>
	 */
	private static function derivaat(array $overrides = []): array {
		return $overrides + [
			'RUDDOJustification' => 'Hedges floating-rate lening L-1',
			'hedgedExposureId' => 'L-1',
			'notional' => 1000,
			'hedgedExposureAmount' => 2000,
			'counterpartyRating' => 'AA',
		];
	}//end derivaat()

	/**
	 * Without an object and without an id nothing is looked up and every guard denies.
	 *
	 * @return void
	 */
	public function testAnUnresolvableSubjectIsDenied(): void {
		$guard = $this->guard([]);

		self::assertFalse($guard->canRecordLening(''));
		self::assertFalse($guard->canRecordDerivaat(''));
		self::assertFalse($guard->canSubmitRapportage(''));
		self::assertSame([], $this->store->queries);

		// An id that matches no row is denied after one lookup per guard.
		self::assertFalse($guard->canRecordDerivaat('d-404'));
		self::assertFalse($guard->canSubmitRapportage('q-404'));
		self::assertSame(
			[
				['shillinq', 'Derivaat', ['filters' => ['id' => 'd-404']]],
				['shillinq', 'QuartaalrapportageFido', ['filters' => ['id' => 'q-404']]],
			],
			$this->store->queries
		);
		self::assertSame([], $this->errors);

	}//end testAnUnresolvableSubjectIsDenied()

	/**
	 * A failing lookup denies each transition and logs the exception with the subject id.
	 *
	 * @return void
	 */
	public function testAFailingLookupFailsClosed(): void {
		$guard = $this->guard(
			[
				'Lening' => new RuntimeException('lening store offline'),
				'Derivaat' => new RuntimeException('derivaat store offline'),
				'QuartaalrapportageFido' => new RuntimeException('report store offline'),
			]
		);

		self::assertFalse($guard->canRecordLening('l-1'));
		self::assertFalse($guard->canRecordDerivaat('d-1'));
		self::assertFalse($guard->canSubmitRapportage('q-1'));
		self::assertSame(
			[
				['FidoTreasuryGuard: lening record check failed — denying transition (fail-closed)', ['leningId' => 'l-1', 'exception' => 'lening store offline']],
				['FidoTreasuryGuard: derivaat RUDDO check failed — denying transition (fail-closed)', ['derivaatId' => 'd-1', 'exception' => 'derivaat store offline']],
				['FidoTreasuryGuard: rapportage submit check failed — denying transition (fail-closed)', ['reportId' => 'q-1', 'exception' => 'report store offline']],
			],
			$this->errors
		);

	}//end testAFailingLookupFailsClosed()

	/**
	 * Non-numeric or non-positive amounts fail the over-hedging check; numeric strings count.
	 *
	 * @return void
	 */
	public function testRuddoAmountsMustBePositiveNumbers(): void {
		$guard = $this->guard([]);

		self::assertTrue($guard->canRecordDerivaat('d-1', self::derivaat()));
		self::assertTrue($guard->canRecordDerivaat('d-1', self::derivaat(['notional' => '1500.50', 'hedgedExposureAmount' => '2000'])));
		self::assertFalse($guard->canRecordDerivaat('d-1', self::derivaat(['notional' => 'a lot'])));
		self::assertFalse($guard->canRecordDerivaat('d-1', self::derivaat(['hedgedExposureAmount' => null])));
		self::assertFalse($guard->canRecordDerivaat('d-1', self::derivaat(['notional' => 0])));
		self::assertFalse($guard->canRecordDerivaat('d-1', self::derivaat(['hedgedExposureAmount' => -5.0])));
		self::assertFalse($guard->canRecordDerivaat('d-1', self::derivaat(['notional' => 2000.01])));

	}//end testRuddoAmountsMustBePositiveNumbers()

	/**
	 * A lening without role, instrument or a non-negative numeric principal is refused before the statuut lookup.
	 *
	 * @return void
	 */
	public function testAnUnsignableLeningShapeIsRefusedWithoutLookup(): void {
		$guard = $this->guard(self::statuut([['role' => 'treasurer', 'instruments' => ['kasgeld'], 'maxAmount' => null]]));

		self::assertFalse($guard->canRecordLening('l-1', self::lening(['signingMandateRole' => ''])));
		self::assertFalse($guard->canRecordLening('l-1', self::lening(['type' => ''])));
		self::assertFalse($guard->canRecordLening('l-1', self::lening(['principal' => 'unknown'])));
		self::assertFalse($guard->canRecordLening('l-1', self::lening(['principal' => -1])));
		self::assertSame([], $this->store->queries);

		self::assertTrue($guard->canRecordLening('l-1', self::lening(['principal' => '250000'])));
		self::assertCount(1, $this->store->queries);

	}//end testAnUnsignableLeningShapeIsRefusedWithoutLookup()

	/**
	 * Without a statuut id or organisation there is no statuut to sign under.
	 *
	 * @return void
	 */
	public function testALeningWithoutStatuutScopeIsDenied(): void {
		$guard = $this->guard(self::statuut([['role' => 'treasurer', 'instruments' => ['kasgeld']]]));

		self::assertFalse($guard->canRecordLening('l-1', self::lening(['treasuryStatuteId' => ''])));
		self::assertSame([], $this->store->queries);

	}//end testALeningWithoutStatuutScopeIsDenied()

	/**
	 * The statuut is looked up by id when given, otherwise by organisation.
	 *
	 * @return void
	 */
	public function testTheStatuutLookupFilters(): void {
		$guard = $this->guard(self::statuut([['role' => 'treasurer', 'instruments' => ['kasgeld']]]));

		self::assertTrue($guard->canRecordLening('l-1', self::lening(['organisationId' => 'org-1'])));
		self::assertTrue($guard->canRecordLening('l-1', self::lening(['organisationId' => 'org-1', 'treasuryStatuteId' => ''])));
		self::assertSame(
			[
				['shillinq', 'Treasurystatuut', ['filters' => ['status' => 'adopted', 'id' => 'ts-1']]],
				['shillinq', 'Treasurystatuut', ['filters' => ['status' => 'adopted', 'organisationId' => 'org-1']]],
			],
			$this->store->queries
		);

	}//end testTheStatuutLookupFilters()

	/**
	 * A statuut whose signingMandates is not a list authorises nothing.
	 *
	 * @return void
	 */
	public function testANonListMandateMatrixAuthorisesNothing(): void {
		self::assertFalse($this->guard(self::statuut('treasurer may sign'))->canRecordLening('l-1', self::lening()));

	}//end testANonListMandateMatrixAuthorisesNothing()

	/**
	 * Malformed matrix entries are skipped; a later valid one still authorises.
	 *
	 * @return void
	 */
	public function testMalformedMandateEntriesAreSkipped(): void {
		$mandates = [
			'not-a-mandate',
			['role' => 'treasurer', 'instruments' => 'kasgeld', 'maxAmount' => null],
			['role' => 'treasurer', 'instruments' => ['kasgeld'], 'maxAmount' => 'unbounded'],
		];

		self::assertFalse($this->guard(self::statuut($mandates))->canRecordLening('l-1', self::lening()));

		$mandates[] = ['role' => 'treasurer', 'instruments' => ['kasgeld'], 'maxAmount' => '1000000'];
		self::assertTrue($this->guard(self::statuut($mandates))->canRecordLening('l-1', self::lening()));
		self::assertFalse($this->guard(self::statuut($mandates))->canRecordLening('l-1', self::lening(['principal' => 1000000.01])));

	}//end testMalformedMandateEntriesAreSkipped()

	/**
	 * An empty configured register falls back to 'shillinq'; a set one is used.
	 *
	 * @return void
	 */
	public function testTheRegisterFallsBackToShillinq(): void {
		$this->guard([], register: '')->canRecordLening('l-1', self::lening());
		self::assertSame('shillinq', $this->store->queries[0][0]);

		$this->guard([], register: 'treasury')->canRecordLening('l-1', self::lening());
		self::assertSame('treasury', $this->store->queries[0][0]);

	}//end testTheRegisterFallsBackToShillinq()
}//end class
