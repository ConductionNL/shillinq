<?php

/**
 * ConcludedOutcomeLogTest — the listener-named log lines of a concluded outcome.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\Shillinq\Listener\ConcludedOutcomeLog;
use OCA\Shillinq\Listener\FoundSubject;
use OCA\Shillinq\Listener\SubjectRef;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers OCA\Shillinq\Listener\ConcludedOutcomeLog.
 */
#[CoversClass(ConcludedOutcomeLog::class)]
#[UsesClass(FoundSubject::class)]
#[UsesClass(SubjectRef::class)]
final class ConcludedOutcomeLogTest extends TestCase {

	/**
	 * Every log line: level, message, context.
	 *
	 * @var list<array{0: string, 1: string, 2: array<string,mixed>}>
	 */
	private array $lines = [];

	/**
	 * A log for a decision listener that records into $lines.
	 *
	 * @return ConcludedOutcomeLog The log.
	 */
	private function log(): ConcludedOutcomeLog {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->lines[] = [$level, $message, $context];
				}
			);
		}

		return new ConcludedOutcomeLog(logger: $logger, listener: 'DemoListener', refKey: 'decisionRef', idKey: 'decisionId');

	}//end log()

	/**
	 * A failed projection is a warning carrying the error message.
	 *
	 * @return void
	 */
	public function testAFailedProjectionIsAWarning(): void {
		$this->log()->projectionFailed(error: new RuntimeException('boom'));

		self::assertSame([['warning', 'DemoListener: projection failed (fail-soft)', ['exception' => 'boom']]], $this->lines);

	}//end testAFailedProjectionIsAWarning()

	/**
	 * A missing subject id names the request and logs the reference under the ref key.
	 *
	 * @return void
	 */
	public function testAMissingSubjectIdNamesTheRequest(): void {
		$this->log()->noSubjectId(payload: ['decisionId' => 'd-7'], request: 'decision');

		self::assertSame([['info', 'DemoListener: no subject id on concluded decision (skipping)', ['decisionRef' => 'd-7']]], $this->lines);

	}//end testAMissingSubjectIdNamesTheRequest()

	/**
	 * No matching object logs the reference and the subject id.
	 *
	 * @return void
	 */
	public function testNoMatchLogsReferenceAndSubject(): void {
		$this->log()->noMatch(payload: ['decisionId' => 'd-7'], subjectId: 's-1');

		self::assertSame(
			[['info', 'DemoListener: no matching finance object (skipping)', ['decisionRef' => 'd-7', 'subjectId' => 's-1']]],
			$this->lines
		);

	}//end testNoMatchLogsReferenceAndSubject()

	/**
	 * A consumed outcome logs schema, subject, outcome and reference in that order.
	 *
	 * @return void
	 */
	public function testAConsumedOutcomeLogsItsSubject(): void {
		$found = new FoundSubject(ref: new SubjectRef(schema: 'AnnualReport', id: 'ar-1'), object: []);

		$this->log()->consumed(found: $found, conclusion: ['decisionId' => 'd-7', 'outcome' => 'approved']);

		self::assertSame(
			[['info', 'DemoListener: outcome consumed', ['schema' => 'AnnualReport', 'subjectId' => 'ar-1', 'outcome' => 'approved', 'decisionRef' => 'd-7']]],
			$this->lines
		);

	}//end testAConsumedOutcomeLogsItsSubject()

	/**
	 * An applied consequence logs the schema and outcome.
	 *
	 * @return void
	 */
	public function testAnAppliedConsequenceIsLogged(): void {
		$this->log()->consequenceApplied(schema: 'ACMReport', outcome: 'approved');

		self::assertSame([['info', 'DemoListener: accounting consequence applied', ['schema' => 'ACMReport', 'outcome' => 'approved']]], $this->lines);

	}//end testAnAppliedConsequenceIsLogged()
}//end class
