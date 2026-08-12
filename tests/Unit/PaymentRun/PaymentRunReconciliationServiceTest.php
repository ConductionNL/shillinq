<?php

/**
 * Unit tests for PaymentRunReconciliationService + Camt053StatementParser.
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

use OCA\Shillinq\PaymentRun\Camt053StatementParser;
use OCA\Shillinq\PaymentRun\PaymentRunReconciliationService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests REQ-SEPA-007 — CAMT.053 parse + match + transition.
 */
class PaymentRunReconciliationServiceTest extends TestCase {
	/**
	 * The seeded exported PR-2026-001 fixture (SAFE placeholders).
	 *
	 * @return array<string, mixed>
	 */
	private function exportedRun(): array {
		return [
			'id' => 'pr-2026-001',
			'runNumber' => 'PR-2026-001',
			'administrationId' => 'adm-consultancy',
			'status' => 'exported',
			'lifecycleState' => 'exported',
			'totalAmount' => 1497.50,
			'currency' => 'EUR',
			'paymentLines' => [
				['payeeName' => 'Eneco Energie B.V.', 'creditorIban' => 'NL00BANK0123456789', 'amount' => 892.50, 'apTransactionRef' => 'a'],
				['payeeName' => 'Jan de Vries (ZZP)', 'creditorIban' => 'NL00TEST0222222222', 'amount' => 605.00, 'apTransactionRef' => 'b'],
			],
		];
	}//end exportedRun()

	/**
	 * Read a fixture from the fixtures directory.
	 *
	 * @param string $name The fixture file name.
	 *
	 * @return string
	 */
	private function fixture(string $name): string {
		return (string)file_get_contents(__DIR__ . '/fixtures/' . $name);
	}//end fixture()

	/**
	 * Build the service with a fake fluent ObjectService capturing the save.
	 *
	 * @param array<string, mixed> $captured Capture slot.
	 *
	 * @return PaymentRunReconciliationService
	 */
	private function service(array &$captured): PaymentRunReconciliationService {
		$objectService = new class($captured) {
			/**
			 * @param array<string,mixed> $captured Capture slot.
			 */
			public function __construct(
				private array &$captured,
			) {
			}//end __construct()

			public function setRegister(string $r): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $s): self {
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $object Saved object.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				$this->captured = $object;
				return $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new PaymentRunReconciliationService(
			$container,
			new Camt053StatementParser(),
			$this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * HAPPY: a full CAMT.053 statement matches both lines, sets reconciledAt
	 * and transitions the run to reconciled.
	 *
	 * @return void
	 */
	public function testFullMatchTransitionsToReconciled(): void {
		$captured = [];
		$service = $this->service($captured);

		$result = $service->reconcile($this->exportedRun(), $this->fixture('PR-2026-001.camt053.xml'));

		$this->assertSame('full', $result['result']);
		$this->assertSame(2, $result['matchedCount']);
		$this->assertSame(2, $result['totalLines']);
		$this->assertNotEmpty($result['reconciledAt']);
		$this->assertSame('reconciled', $result['lifecycleState']);

		$this->assertSame('reconciled', $captured['lifecycleState']);
		$this->assertNotEmpty($captured['reconciledAt']);
	}//end testFullMatchTransitionsToReconciled()

	/**
	 * PARTIAL: a partial statement matches one line, leaves the run exported,
	 * sets a mismatch note and does NOT set reconciledAt.
	 *
	 * @return void
	 */
	public function testPartialMatchStaysExported(): void {
		$captured = [];
		$service = $this->service($captured);

		$result = $service->reconcile($this->exportedRun(), $this->fixture('PR-2026-001.partial.camt053.xml'));

		$this->assertSame('partial', $result['result']);
		$this->assertSame(1, $result['matchedCount']);
		$this->assertSame([2], $result['unmatchedLines']);
		$this->assertStringContainsString('Unmatched line(s): 2', $result['mismatchNote']);
		$this->assertSame('exported', $result['lifecycleState']);
		$this->assertArrayNotHasKey('reconciledAt', $result);

		// The run was saved with the note but never set reconciledAt / reconciled.
		$this->assertArrayNotHasKey('reconciledAt', $captured);
		$this->assertSame('exported', (string)($captured['lifecycleState'] ?? 'exported'));
		$this->assertStringContainsString('Unmatched line(s): 2', $captured['reconciliationNote']);
	}//end testPartialMatchStaysExported()

	/**
	 * FALLBACK: when statement entries omit the EndToEndId, the
	 * (amount + creditor IBAN) fallback still matches both lines (full).
	 *
	 * @return void
	 */
	public function testAmountIbanFallbackMatches(): void {
		$captured = [];
		$service = $this->service($captured);

		$result = $service->reconcile($this->exportedRun(), $this->fixture('PR-2026-001.fallback.camt053.xml'));

		$this->assertSame('full', $result['result']);
		$this->assertSame(2, $result['matchedCount']);
		$this->assertSame('reconciled', $result['lifecycleState']);
	}//end testAmountIbanFallbackMatches()

	/**
	 * ERROR: reconciliation is rejected for a non-exported run.
	 *
	 * @return void
	 */
	public function testNonExportedRunIsRejected(): void {
		$captured = [];
		$service = $this->service($captured);

		$run = $this->exportedRun();
		$run['lifecycleState'] = 'approved';
		$run['status'] = 'approved';

		$result = $service->reconcile($run, $this->fixture('PR-2026-001.camt053.xml'));

		$this->assertSame('not-exported', $result['error']);
		$this->assertSame('approved', $result['state']);
		$this->assertSame([], $captured, 'No run should be persisted for a rejected reconcile');
	}//end testNonExportedRunIsRejected()

	/**
	 * EDGE: the parser only yields booked outgoing (DBIT/BOOK) entries.
	 *
	 * @return void
	 */
	public function testParserYieldsBookedOutgoingEntries(): void {
		$parser = new Camt053StatementParser();
		$entries = $parser->parse($this->fixture('PR-2026-001.camt053.xml'));

		$this->assertCount(2, $entries);
		$this->assertSame('PR-2026-001-1', $entries[0]['endToEndId']);
		$this->assertSame(892.50, $entries[0]['amount']);
		$this->assertSame('NL00BANK0123456789', $entries[0]['creditorIban']);
		$this->assertSame('EUR', $entries[0]['currency']);
	}//end testParserYieldsBookedOutgoingEntries()
}//end class
