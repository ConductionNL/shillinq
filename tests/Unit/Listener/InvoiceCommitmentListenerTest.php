<?php

/**
 * InvoiceCommitmentListener: which transitions book a commitment, and a failed booking.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
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

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Listener\InvoiceCommitmentListener;
use OCA\Shillinq\Service\Commitment\CommitmentInvoicing;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Only an approval books; a failing booking is logged and swallowed.
 */
final class InvoiceCommitmentListenerTest extends TestCase {

	/**
	 * A transition of a supplier invoice to the given state.
	 *
	 * @param string $to   The target state.
	 * @param string $uuid The invoice uuid.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function transition(string $to, string $uuid): ObjectTransitionedEvent {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject(['invoiceNumber' => 'F-1', 'totalInclVat' => 1000]);

		$event = $this->createStub(ObjectTransitionedEvent::class);
		$event->method('getTo')->willReturn($to);
		$event->method('getObject')->willReturn($entity);
		return $event;
	}//end transition()

	/**
	 * A rejection books nothing; an approval books the invoice under its uuid,
	 * and a booking that throws is logged as a warning instead of escaping.
	 *
	 * @return void
	 */
	public function testOnlyAnApprovalBooksAndAFailureIsLogged(): void {
		$resolver = $this->createStub(ListenerSchemaResolver::class);
		$resolver->method('schemaSlug')->willReturn('SupplierInvoice');

		$invoicing = $this->createMock(CommitmentInvoicing::class);
		$invoicing->expects($this->once())->method('book')
			->with(['invoiceNumber' => 'F-1', 'totalInclVat' => 1000, 'id' => 'inv-uuid-1'])
			->willThrowException(new \RuntimeException('commitment locked'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('InvoiceCommitmentListener: the commitment was not updated', ['invoice' => 'inv-uuid-1', 'exception' => 'commitment locked']);

		$listener = new InvoiceCommitmentListener($invoicing, $resolver, $logger);
		$listener->handle($this->transition('rejected', 'inv-uuid-0'));
		$listener->handle($this->transition('approved', 'inv-uuid-1'));
	}//end testOnlyAnApprovalBooksAndAFailureIsLogged()
}//end class
