<?php

/**
 * Unit tests for SbrFilingStatusListener: a filing and its VAT return follow integriq's reports.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Listener\SbrFilingStatusListener;
use OCA\Shillinq\Service\Sbr\SbrRecords;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(SbrFilingStatusListener::class)]
final class SbrFilingStatusListenerTest extends TestCase {

	private const URI = 'openregister://shillinq/XbrlInstance/xbrl-1';

	/** @var array<string, array<string, array<string,mixed>>> */
	private array $store = [];

	/** @var list<array{0:string,1:array<string,mixed>}> */
	private array $saves = [];

	/** @var list<string> */
	private array $runs = [];

	private bool $schemaMatches = true;

	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function listener(): SbrFilingStatusListener {
		$records = $this->createMock(SbrRecords::class);
		$records->method('find')->willReturnCallback(fn (string $schema, string $id): ?array => $this->store[$schema][$id] ?? null);
		$records->method('save')->willReturnCallback(function (string $schema, array $record): array {
			$this->saves[] = [$schema, $record];
			$this->store[$schema][$record['id']] = $record;
			return $record;
		});
		$transitions = $this->createMock(ObjectTransitionRunnerInterface::class);
		$transitions->expects($this->never())->method('run');
		$transitions->method('runAsSystem')->willReturnCallback(function (string $objectId, string $action): mixed {
			$this->runs[] = $objectId . ':' . $action;
			return null;
		});
		$resolver = $this->createMock(ListenerSchemaResolverInterface::class);
		$resolver->method('matchesRegisterAndSchema')->willReturnCallback(fn (): bool => $this->schemaMatches);

		return new SbrFilingStatusListener($records, $transitions, $resolver, $this->logger);
	}

	private function given(string $state, string $returnStatus = 'draft'): void {
		$this->store['XbrlInstance']['xbrl-1'] = ['id' => 'xbrl-1', 'state' => $state, 'sourceReturnId' => 'ret-1'];
		$this->store['BtwAangifte']['ret-1'] = ['id' => 'ret-1', 'statusCode' => $returnStatus, 'notes' => 'Q3'];
	}

	private static function event(array $data, string $type = SbrFilingStatusListener::CLOUDEVENT_TYPE): ObjectCreatedEvent {
		$entity = new ObjectEntity();
		$entity->setObject(['type' => $type, 'data' => $data]);
		return new ObjectCreatedEvent($entity);
	}

	public function testADeliveryRecordsTheReferenceAndMakesTheFilingAndItsReturnSubmitted(): void {
		$this->given('handed-off');

		$this->listener()->handle(self::event(['objectUri' => self::URI, 'status' => 'Delivered', 'deliveryReference' => 'KN-123']));

		$this->assertSame(['xbrl-1:deliver', 'ret-1:submit'], $this->runs);
		$this->assertSame('KN-123', $this->store['XbrlInstance']['xbrl-1']['digipoortReceiptId']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $this->store['XbrlInstance']['xbrl-1']['submittedAt']);
		$this->assertSame('KN-123', $this->store['BtwAangifte']['ret-1']['filingReference']);
		$this->assertSame(gmdate('Y-m-d'), $this->store['BtwAangifte']['ret-1']['submissionDate']);
	}

	public function testADeliveryWithoutAReferenceIsSkipped(): void {
		$this->given('handed-off');

		$this->assertNull($this->listener()->apply(['objectUri' => self::URI, 'status' => 'delivered']));
		$this->assertSame([], $this->runs);
		$this->assertSame([], $this->saves);
	}

	public function testAcceptanceAfterDeliveryAcceptsAndVerifiesTheReturn(): void {
		$this->given('submitted', 'submitted');

		$this->assertSame('accepted', $this->listener()->apply(['objectUri' => self::URI, 'status' => 'accepted', 'deliveryReference' => 'KN-123']));

		$this->assertSame(['xbrl-1:accept', 'ret-1:verify'], $this->runs);
		$this->assertArrayHasKey('acceptedAt', $this->store['XbrlInstance']['xbrl-1']);
		$this->assertArrayNotHasKey('digipoortReceiptId', $this->store['XbrlInstance']['xbrl-1']);
	}

	public function testAnAcceptanceWhoseDeliveryReportNeverCameDeliversFirst(): void {
		$this->given('handed-off');

		$this->assertSame('accepted', $this->listener()->apply(['objectUri' => self::URI, 'status' => 'accepted', 'deliveryReference' => 'KN-9']));

		$this->assertSame(['xbrl-1:deliver', 'xbrl-1:accept', 'ret-1:submit', 'ret-1:verify'], $this->runs);
		$this->assertSame('KN-9', $this->store['XbrlInstance']['xbrl-1']['digipoortReceiptId']);
		$this->assertCount(2, $this->saves, 'one save of the filing before its transitions, one of the return');
	}

	public function testARejectionRecordsTheErrorsAndReopensTheReturn(): void {
		$this->given('submitted', 'submitted');
		$errors = [['code' => 'OB-0042', 'message' => 'Rubriek 5g klopt niet'], 'Tweede fout', ['code' => '', 'text' => 'Zonder code'], 7];

		$this->assertSame('rejected', $this->listener()->apply(['objectUri' => self::URI, 'status' => 'rejected', 'errors' => $errors]));

		$this->assertSame(['xbrl-1:reject', 'ret-1:rebase'], $this->runs);
		$this->assertSame('OB-0042: Rubriek 5g klopt niet; Tweede fout; Zonder code', $this->store['XbrlInstance']['xbrl-1']['rejectionReason']);
		$this->assertSame("Q3\nRejected by the Belastingdienst: OB-0042: Rubriek 5g klopt niet; Tweede fout; Zonder code", $this->store['BtwAangifte']['ret-1']['notes']);
	}

	public function testAFailedDeliveryRetractsTheFilingAndLeavesTheReturnInDraft(): void {
		$this->given('handed-off');

		$this->assertSame('validated', $this->listener()->apply(['objectUri' => self::URI, 'status' => 'failed', 'errors' => 'certificate expired']));

		$this->assertSame(['xbrl-1:retract'], $this->runs);
		$this->assertSame('certificate expired', $this->store['XbrlInstance']['xbrl-1']['rejectionReason']);
		$this->assertSame('draft', $this->store['BtwAangifte']['ret-1']['statusCode']);
	}

	public function testRepeatedOrUnfittingReportsChangeNothing(): void {
		$this->given('accepted', 'verified');
		$listener = $this->listener();

		$this->assertSame('accepted', $listener->apply(['objectUri' => self::URI, 'status' => 'accepted']));
		$this->assertNull($listener->apply(['objectUri' => self::URI, 'status' => 'rejected']));
		$this->assertNull($listener->apply(['objectUri' => self::URI, 'status' => 'failed']));
		$this->assertNull($listener->apply(['objectUri' => self::URI, 'status' => 'queued']));
		$this->given('validated');
		$this->assertNull($listener->apply(['objectUri' => self::URI, 'status' => 'delivered', 'deliveryReference' => 'KN-1']));
		$this->given('submitted');
		$this->assertSame('submitted', $listener->apply(['objectUri' => self::URI, 'status' => 'delivered', 'deliveryReference' => 'KN-1']));

		$this->assertSame(['ret-1:submit'], $this->runs, 'only the return of a delivered filing still in draft catches up');
	}

	public function testAReportForAnotherObjectOrAnUnknownFilingIsSkipped(): void {
		$this->given('handed-off');
		$listener = $this->listener();

		$this->assertNull($listener->apply(['objectUri' => 'openregister://shillinq/ARInvoice/xbrl-1', 'status' => 'delivered', 'deliveryReference' => 'K']));
		$this->assertNull($listener->apply(['objectUri' => 'openregister://shillinq/XbrlInstance/nope', 'status' => 'delivered', 'deliveryReference' => 'K']));
		$this->assertNull($listener->apply(['status' => 'delivered']));
		$this->assertSame([], $this->runs);
	}

	public function testOnlyIntegriqsFilingStatusEventsAreHeard(): void {
		$this->given('handed-off');
		$listener = $this->listener();

		$listener->handle($this->createMock(Event::class));
		$listener->handle(self::event(['objectUri' => self::URI, 'status' => 'delivered', 'deliveryReference' => 'K'], 'nl.conduction.peppol.delivery.status'));
		$this->schemaMatches = false;
		$listener->handle(self::event(['objectUri' => self::URI, 'status' => 'delivered', 'deliveryReference' => 'K']));

		$this->assertSame([], $this->runs);
	}

	public function testAFailingTransitionIsLoggedNotThrown(): void {
		$this->given('handed-off');
		$records = $this->createMock(SbrRecords::class);
		$records->method('find')->willReturn(['id' => 'xbrl-1', 'state' => 'handed-off']);
		$records->method('save')->willReturnArgument(1);
		$transitions = $this->createMock(ObjectTransitionRunnerInterface::class);
		$transitions->method('runAsSystem')->willThrowException(new RuntimeException('guard refused'));
		$resolver = $this->createMock(ListenerSchemaResolverInterface::class);
		$resolver->method('matchesRegisterAndSchema')->willReturn(true);
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('fail-soft'), ['exception' => 'guard refused']);

		(new SbrFilingStatusListener($records, $transitions, $resolver, $this->logger))
			->handle(self::event(['objectUri' => self::URI, 'status' => 'delivered', 'deliveryReference' => 'K']));
	}

	public function testErrorsReadAsOneLine(): void {
		$this->assertSame('E1: a; b', SbrFilingStatusListener::errors([['code' => 'E1', 'message' => 'a'], 'b', null]));
		$this->assertSame('plain', SbrFilingStatusListener::errors(' plain '));
		$this->assertSame('', SbrFilingStatusListener::errors(null));
	}
}
