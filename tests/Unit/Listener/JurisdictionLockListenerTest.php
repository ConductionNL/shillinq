<?php

/**
 * JurisdictionLockListener tests (ledger-foreign-legislation)
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Listener\JurisdictionLockListener;
use OCA\Shillinq\Service\Jurisdiction\AdministrationJurisdiction;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An administration's jurisdiction is fixed once it has postings.
 *
 * @covers \OCA\Shillinq\Listener\JurisdictionLockListener
 * @uses   \OCA\Shillinq\Service\Jurisdiction\AdministrationJurisdiction
 *
 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
 */
final class JurisdictionLockListenerTest extends TestCase {

	/**
	 * Changing a Belgian administration with postings to NL is refused with the reason.
	 *
	 * @return void
	 */
	public function testAChangeAfterPostingIsRefused(): void {
		$asked = [];
		$event = new ObjectUpdatingEvent($this->administration('NL'), $this->administration('BE'));

		$this->listener(postings: ['VDS' => true], asked: $asked)->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['message' => JurisdictionLockListener::DENY_MESSAGE, 'field' => 'jurisdiction'], $event->getErrors());
		$this->assertSame(['VDS'], $asked);
	}//end testAChangeAfterPostingIsRefused()

	/**
	 * Postings recorded under the uuid lock the jurisdiction too.
	 *
	 * @return void
	 */
	public function testPostingsUnderTheUuidCountToo(): void {
		$asked = [];
		$event = new ObjectUpdatingEvent($this->administration('NL'), $this->administration('BE'));

		$this->listener(postings: ['uuid-vds' => true], asked: $asked)->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['VDS', 'uuid-vds'], $asked);
	}//end testPostingsUnderTheUuidCountToo()

	/**
	 * Without postings the jurisdiction may change; an unchanged one is not checked at all.
	 *
	 * @return void
	 */
	public function testAChangeBeforePostingOrNoChangePasses(): void {
		$asked = [];
		$change = new ObjectUpdatingEvent($this->administration('BE'), $this->administration(null));
		$this->listener(postings: [], asked: $asked)->handle($change);
		$this->assertFalse($change->isPropagationStopped());

		$asked = [];
		// An empty jurisdiction is NL, so NL over empty is no change.
		$same = new ObjectUpdatingEvent($this->administration('NL'), $this->administration(null));
		$this->listener(postings: ['VDS' => true], asked: $asked)->handle($same);
		$this->assertFalse($same->isPropagationStopped());
		$this->assertSame([], $asked);
	}//end testAChangeBeforePostingOrNoChangePasses()

	/**
	 * A failed postings lookup refuses the change: postings cannot be ruled out.
	 *
	 * @return void
	 */
	public function testAFailedLookupRefuses(): void {
		$jurisdiction = $this->createMock(AdministrationJurisdiction::class);
		$jurisdiction->method('hasPostings')->willThrowException(new \RuntimeException('store down'));
		$listener = new JurisdictionLockListener(jurisdiction: $jurisdiction, schemaResolver: $this->resolver(), logger: new NullLogger());
		$event = new ObjectUpdatingEvent($this->administration('NL'), $this->administration('BE'));

		$listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testAFailedLookupRefuses()

	/**
	 * Other schemas and creates are not judged.
	 *
	 * @return void
	 */
	public function testOtherObjectsAndCreatesAreNotJudged(): void {
		$asked = [];
		$listener = $this->listener(postings: ['VDS' => true], asked: $asked);
		$other = new ObjectUpdatingEvent($this->administration('NL', 'Account'), $this->administration('BE', 'Account'));
		$listener->handle($other);
		$create = new ObjectCreatingEvent($this->administration('BE'));
		$listener->handle($create);

		$this->assertFalse($other->isPropagationStopped());
		$this->assertFalse($create->isPropagationStopped());
		$this->assertSame([], $asked);
	}//end testOtherObjectsAndCreatesAreNotJudged()

	/**
	 * The listener over a postings map; records which ids were asked about.
	 *
	 * @param array<string, bool> $postings Whether an id has postings.
	 * @param array<int, string>  $asked    The ids asked about, by reference.
	 *
	 * @return JurisdictionLockListener
	 */
	private function listener(array $postings, array &$asked): JurisdictionLockListener {
		$jurisdiction = $this->createMock(AdministrationJurisdiction::class);
		$jurisdiction->method('hasPostings')->willReturnCallback(
			static function (string $administrationId) use ($postings, &$asked): bool {
				$asked[] = $administrationId;
				return ($postings[$administrationId] ?? false);
			}
		);

		return new JurisdictionLockListener(jurisdiction: $jurisdiction, schemaResolver: $this->resolver(), logger: new NullLogger());
	}//end listener()

	/**
	 * A resolver that matches on the entity's schema value.
	 *
	 * @return ListenerSchemaResolver
	 */
	private function resolver(): ListenerSchemaResolver {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('matchesSchema')->willReturnCallback(
			static fn (?object $entity, string $expectedSlug): bool => $entity !== null && $entity->getSchema() === $expectedSlug
		);
		return $resolver;
	}//end resolver()

	/**
	 * The Belgian subsidiary as an entity.
	 *
	 * @param string|null $jurisdiction Its jurisdiction, or null for none.
	 * @param string      $schema       The schema it is stored under.
	 *
	 * @return ObjectEntity
	 */
	private function administration(?string $jurisdiction, string $schema='Administration'): ObjectEntity {
		$object = ['administrationCode' => 'VDS', 'name' => 'Van Dijk Services BV/SRL'];
		if ($jurisdiction !== null) {
			$object['jurisdiction'] = $jurisdiction;
		}

		$entity = new ObjectEntity();
		$entity->setObject($object);
		$entity->setUuid('uuid-vds');
		$entity->setSchema($schema);
		$entity->setRegister('shillinq');
		return $entity;
	}//end administration()
}//end class
