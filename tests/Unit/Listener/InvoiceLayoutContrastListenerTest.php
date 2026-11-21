<?php

/**
 * InvoiceLayoutContrastListenerTest — the invoice layout write path refuses a
 * primary colour below 4.5:1 against white.
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
 *
 * @spec openspec/changes/sales-invoice-document/tasks.md#task-4.1
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Listener\InvoiceLayoutContrastListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\Sales\InvoiceDocument\ColourContrast;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Covers OCA\Shillinq\Listener\InvoiceLayoutContrastListener.
 */
final class InvoiceLayoutContrastListenerTest extends TestCase {

	/**
	 * A light primary colour is refused on create, with its ratio and the
	 * requirement in the refusal.
	 *
	 * @return void
	 */
	public function testALightPrimaryColourIsRefusedOnCreate(): void {
		$event = new ObjectCreatingEvent($this->entity(['administrationId' => 'adm-1', 'variant' => 'modern', 'primaryColor' => '#FFD700']));

		$this->listener(matches: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['message', 'requirement'], array_keys($event->getErrors()));
		self::assertSame('REQ-SID-001', $event->getErrors()['requirement']);
		self::assertStringContainsString('#FFD700 has a contrast of 1.4:1', $event->getErrors()['message']);
		self::assertStringContainsString('4.5:1', $event->getErrors()['message']);
	}//end testALightPrimaryColourIsRefusedOnCreate()

	/**
	 * The ratio is rounded down, so a colour just under the line never reads 4.5:1.
	 *
	 * @return void
	 */
	public function testARatioJustUnderTheLineReadsBelowIt(): void {
		$event = new ObjectUpdatingEvent($this->entity(['primaryColor' => '#777777']));

		$this->listener(matches: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringContainsString('4.4:1', $event->getErrors()['message']);
	}//end testARatioJustUnderTheLineReadsBelowIt()

	/**
	 * A value that is not a colour is refused with that reason.
	 *
	 * @return void
	 */
	public function testANonColourIsRefused(): void {
		$event = new ObjectUpdatingEvent($this->entity(['primaryColor' => 'navy']));

		$this->listener(matches: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('The primary colour navy is not a #RRGGBB colour.', $event->getErrors()['message']);
	}//end testANonColourIsRefused()

	/**
	 * The spec's house style #1B4F72 is written.
	 *
	 * @return void
	 */
	public function testAReadablePrimaryColourIsLetThrough(): void {
		$event = new ObjectUpdatingEvent($this->entity(['primaryColor' => '#1B4F72', 'variant' => 'modern']));

		$this->listener(matches: true)->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAReadablePrimaryColourIsLetThrough()

	/**
	 * A layout without a primary colour keeps the default and is written.
	 *
	 * @return void
	 */
	public function testALayoutWithoutAPrimaryColourIsLetThrough(): void {
		$event = new ObjectCreatingEvent($this->entity(['administrationId' => 'adm-1', 'variant' => 'classic']));

		$this->listener(matches: true)->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testALayoutWithoutAPrimaryColourIsLetThrough()

	/**
	 * Another schema's write is untouched, whatever colour it carries.
	 *
	 * @return void
	 */
	public function testOtherSchemasAreUntouched(): void {
		$event = new ObjectCreatingEvent($this->entity(['primaryColor' => '#FFFFFF']));

		$this->listener(matches: false)->handle($event);

		self::assertFalse($event->isPropagationStopped(), 'The listener must be scoped to InvoiceLayout.');
	}//end testOtherSchemasAreUntouched()

	/**
	 * The listener over a resolver that does or does not match InvoiceLayout.
	 *
	 * @param bool $matches Whether the entity is an InvoiceLayout.
	 *
	 * @return InvoiceLayoutContrastListener The listener.
	 */
	private function listener(bool $matches): InvoiceLayoutContrastListener {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('matchesSchema')->willReturn($matches);

		return new InvoiceLayoutContrastListener(
			contrast: new ColourContrast(),
			logger: new NullLogger(),
			schemaResolver: $resolver,
		);
	}//end listener()

	/**
	 * An InvoiceLayout entity carrying the given payload.
	 *
	 * @param array<string, mixed> $data The object payload being written.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setSchema('InvoiceLayout');
		$entity->setRegister('shillinq');

		return $entity;
	}//end entity()
}//end class
