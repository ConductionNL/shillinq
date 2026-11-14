<?php

/**
 * Characterisation tests for PaymentRunReconciliationService matching, envelopes
 * and the write authority held while the reconciled run is saved.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentRun
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/payment-run-sepa-export/specs/payment-run-sepa-export/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\PaymentRun;

use OCA\Shillinq\Lifecycle\PaymentRunReconcileGuard;
use OCA\Shillinq\PaymentRun\Camt053StatementParser;
use OCA\Shillinq\PaymentRun\PaymentRunReconciliationService;
use OCA\Shillinq\Service\ServerWriteAuthority;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the two matching passes, the partial and full envelopes, the saved
 * fields, the reconcile write authority (held for the full-match save only),
 * the refusal of a run that is not exported, and the fail-soft save.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PaymentRunReconciliationStepsTest extends TestCase {

	/**
	 * Every save: the object saved and whether the reconcile authority was held.
	 *
	 * @var list<array{0: array<string, mixed>, 1: bool}>
	 */
	private array $saves = [];

	/**
	 * Warning lines.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * Build the service over parsed entries.
	 *
	 * @param list<array<string, mixed>> $entries  What the parser returns.
	 * @param string                     $saveMode 'array', 'entity', 'scalar', 'throw' or 'missing'.
	 *
	 * @return PaymentRunReconciliationService The service.
	 */
	private function service(array $entries, string $saveMode = 'array'): PaymentRunReconciliationService {
		$test = $this;
		$objectService = new class($test, $saveMode) {
			public function __construct(
				private readonly PaymentRunReconciliationStepsTest $test,
				private readonly string $mode,
			) {
			}

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				return $this;
			}

			/**
			 * @param array<string, mixed> $object The run.
			 *
			 * @return mixed
			 */
			public function saveObject(array $object): mixed {
				$this->test->recordSave($object);
				if ($this->mode === 'throw') {
					throw new RuntimeException('save down');
				}

				$saved = $object + ['savedBy' => 'store'];
				if ($this->mode === 'entity') {
					return new class($saved) {
						/**
						 * @param array<string, mixed> $saved The payload.
						 */
						public function __construct(
							private readonly array $saved,
						) {
						}

						/**
						 * @return array<string, mixed>
						 */
						public function jsonSerialize(): array {
							return $this->saved;
						}
					};
				}

				return $this->mode === 'scalar' ? 'ok' : $saved;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		if ($saveMode === 'missing') {
			$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		} else {
			$container->method('get')->willReturn($objectService);
		}

		$parser = $this->createMock(Camt053StatementParser::class);
		$parser->expects(self::any())->method('parse')->with('<camt/>')->willReturn($entries);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new PaymentRunReconciliationService($container, $parser, $logger);

	}//end service()

	/**
	 * Record one save and whether the reconcile authority is held.
	 *
	 * @param array<string, mixed> $object The saved run.
	 *
	 * @return void
	 */
	public function recordSave(array $object): void {
		$held = (new ServerWriteAuthority())->holds(PaymentRunReconcileGuard::PURPOSE, (string)($object['id'] ?? ($object['@self']['id'] ?? '')));
		$this->saves[] = [$object, $held];

	}//end recordSave()

	/**
	 * An exported run with three lines.
	 *
	 * @return array<string, mixed> The run.
	 */
	private static function exportedRun(): array {
		return [
			'id' => 'run-1',
			'runNumber' => 'PR-7',
			'lifecycleState' => 'exported',
			'paymentLines' => [
				'a' => ['creditorIban' => ' nl01bank0001 ', 'amount' => '100.004'],
				'b' => ['creditorIban' => 'NL02BANK0002', 'amount' => 50],
				'c' => ['creditorIban' => 'NL02BANK0002', 'amount' => 50],
			],
		];

	}//end exportedRun()

	/**
	 * An entry.
	 *
	 * @param string $endToEnd The EndToEndId.
	 * @param string $iban     The creditor IBAN.
	 * @param float  $amount   The amount.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private static function entry(string $endToEnd, string $iban, float $amount): array {
		return ['endToEndId' => $endToEnd, 'creditorIban' => $iban, 'amount' => $amount];

	}//end entry()

	/**
	 * Pass 1 by EndToEndId, pass 2 by IBAN + amount, each entry used once;
	 * the partial envelope, note and save without authority.
	 *
	 * @return void
	 */
	public function testPartialMatch(): void {
		$entries = [
			self::entry('PR-7-2', 'XX', 1.0),
			self::entry('', 'NL01BANK0001 ', 100.0),
			self::entry('PR-7-2', 'NL02BANK0002', 49.98),
		];
		$result = $this->service($entries)->reconcile(self::exportedRun(), '<camt/>');

		$note = 'Reconciliation partial: 2 of 3 lines matched. Unmatched line(s): 3.';
		self::assertSame('partial', $result['result']);
		self::assertSame([2, 3, [3], $note, 'exported'], [$result['matchedCount'], $result['totalLines'], $result['unmatchedLines'], $result['mismatchNote'], $result['lifecycleState']]);
		self::assertSame(['result', 'matchedCount', 'totalLines', 'unmatchedLines', 'mismatchNote', 'lifecycleState', 'paymentRun'], array_keys($result));
		self::assertCount(1, $this->saves);
		self::assertSame(self::exportedRun() + ['reconciliationNote' => $note], $this->saves[0][0]);
		self::assertFalse($this->saves[0][1]);
		self::assertSame('store', $result['paymentRun']['savedBy']);

	}//end testPartialMatch()

	/**
	 * A run without lines is partial with "none".
	 *
	 * @return void
	 */
	public function testNoLinesIsPartial(): void {
		$run = self::exportedRun();
		$run['paymentLines'] = 'not-a-list';
		$result = $this->service([], 'entity')->reconcile($run, '<camt/>');

		self::assertSame('Reconciliation partial: 0 of 0 lines matched. Unmatched line(s): none.', $result['mismatchNote']);
		self::assertSame([], $result['unmatchedLines']);
		self::assertSame('store', $result['paymentRun']['savedBy']);

	}//end testNoLinesIsPartial()

	/**
	 * Full match: reconciled fields saved under the reconcile authority.
	 *
	 * @return void
	 */
	public function testFullMatchSavesUnderAuthority(): void {
		$run = self::exportedRun();
		unset($run['runNumber']);
		$run['lifecycleState'] = null;
		$run['status'] = 'exported';
		$entries = [self::entry('PR-1', '', 0.0), self::entry('PR-2', '', 0.0), self::entry('PR-3', '', 0.0)];
		$result = $this->service($entries)->reconcile($run, '<camt/>');

		self::assertSame('full', $result['result']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result['reconciledAt']);
		self::assertSame(['result', 'matchedCount', 'totalLines', 'unmatchedLines', 'reconciledAt', 'lifecycleState', 'paymentRun'], array_keys($result));
		self::assertSame([3, 3, [], 'reconciled'], [$result['matchedCount'], $result['totalLines'], $result['unmatchedLines'], $result['lifecycleState']]);
		self::assertTrue($this->saves[0][1]);
		self::assertSame(
			[$result['reconciledAt'], 'reconciled', 'reconciled'],
			[$this->saves[0][0]['reconciledAt'], $this->saves[0][0]['lifecycleState'], $this->saves[0][0]['status']]
		);
		self::assertSame(array_keys($run + ['reconciledAt' => 1]), array_keys($this->saves[0][0]));
		self::assertFalse((new ServerWriteAuthority())->holds(PaymentRunReconcileGuard::PURPOSE, 'run-1'));

	}//end testFullMatchSavesUnderAuthority()

	/**
	 * The authority id falls back to `@self.id`.
	 *
	 * @return void
	 */
	public function testAuthorityUsesSelfIdFallback(): void {
		$run = self::exportedRun();
		unset($run['id']);
		$run['@self'] = ['id' => 'self-9'];
		$run['paymentLines'] = [['creditorIban' => 'NL', 'amount' => 1]];
		$this->service([self::entry('PR-7-1', '', 0.0)])->reconcile($run, '<camt/>');

		self::assertTrue($this->saves[0][1]);

	}//end testAuthorityUsesSelfIdFallback()

	/**
	 * Refusal and fail-soft saves.
	 *
	 * @return void
	 */
	public function testRefusalAndFailSoftSaves(): void {
		$run = self::exportedRun();
		$run['lifecycleState'] = 'draft';
		self::assertSame(['error' => 'not-exported', 'state' => 'draft'], $this->service([])->reconcile($run, '<camt/>'));
		self::assertSame([['PaymentRunReconciliationService: run not exported', ['state' => 'draft']]], $this->warnings);
		self::assertSame([], $this->saves);

		$this->warnings = [];
		$result = $this->service([], 'throw')->reconcile(self::exportedRun(), '<camt/>');
		self::assertSame([['PaymentRunReconciliationService: failed to save PaymentRun', ['exception' => 'save down']]], $this->warnings);
		self::assertArrayNotHasKey('savedBy', $result['paymentRun']);

		$result = $this->service([], 'scalar')->reconcile(self::exportedRun(), '<camt/>');
		self::assertArrayNotHasKey('savedBy', $result['paymentRun']);

		$result = $this->service([], 'missing')->reconcile(self::exportedRun(), '<camt/>');
		self::assertSame('exported', $result['lifecycleState']);

	}//end testRefusalAndFailSoftSaves()
}//end class
