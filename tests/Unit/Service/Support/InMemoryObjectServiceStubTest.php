<?php

/**
 * Unit tests for the raw append and purge pair on InMemoryObjectServiceStub.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude contract-shift follow-up for openregister#3407; no shillinq spec owns the raw append path
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Support;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * The stub must declare appendObjectsRaw() and purgeExpiredObjectsRaw()
 * (openregister#3407, contract-shift openregister#3406) or it stops
 * implementing the contract at class load. Declaring is the half PHP checks;
 * this covers the other half, that the pair behaves like a store.
 *
 * PHPUnit assertions take positional arguments; the named-parameter sniff does
 * not apply to them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InMemoryObjectServiceStubTest extends TestCase {

	/**
	 * Both the stub and the local contract copy declare the pair.
	 *
	 * @return void
	 */
	public function testStubAndContractCopyDeclareThePair(): void {
		$this->assertInstanceOf(ObjectServiceInterface::class, new InMemoryObjectServiceStub());
		$this->assertTrue(method_exists(ObjectServiceInterface::class, 'appendObjectsRaw'));
		$this->assertTrue(method_exists(ObjectServiceInterface::class, 'purgeExpiredObjectsRaw'));

	}//end testStubAndContractCopyDeclareThePair()

	/**
	 * Appended rows land on the schema, get a uuid when they carry none, and
	 * never touch the audit sink.
	 *
	 * @return void
	 */
	public function testAppendStampsAUuidAndSkipsTheSaveSink(): void {
		$stub = new InMemoryObjectServiceStub();

		$written = $stub->appendObjectsRaw(
			objects: [
				['event' => 'view'],
				['uuid' => 'fixed-1', 'event' => 'click'],
			],
			register: 'shillinq',
			schema: 'traffic-event'
		);

		$this->assertSame(2, $written);
		$rows = $stub->setSchema(schema: 'traffic-event')->findAll();
		$this->assertSame('raw-1', $rows[0]['uuid']);
		$this->assertSame('fixed-1', $rows[1]['uuid']);
		$this->assertSame([], $stub->saved);

	}//end testAppendStampsAUuidAndSkipsTheSaveSink()

	/**
	 * The sweep removes rows whose expires has passed, in either accepted form,
	 * keeps the rest, leaves other schemas alone, and reports what it removed.
	 *
	 * @return void
	 */
	public function testPurgeDropsOnlyExpiredRowsOnTheSchema(): void {
		$stub = new InMemoryObjectServiceStub();
		$stub->appendObjectsRaw(
			objects: [
				['uuid' => 'gone-iso', 'expires' => '2000-01-01T00:00:00+00:00'],
				['uuid' => 'gone-datetime', 'expires' => new DateTimeImmutable('-1 day')],
				['uuid' => 'stays-future', 'expires' => '2999-01-01T00:00:00+00:00'],
				['uuid' => 'stays-never'],
				['uuid' => 'stays-unparseable', 'expires' => 'not a date'],
			],
			register: 'shillinq',
			schema: 'traffic-event'
		);
		$stub->appendObjectsRaw(
			objects: [['uuid' => 'other-schema', 'expires' => '2000-01-01T00:00:00+00:00']],
			register: 'shillinq',
			schema: 'telemetry'
		);

		$this->assertSame(2, $stub->purgeExpiredObjectsRaw(register: 'shillinq', schema: 'traffic-event'));
		$this->assertSame(
			['stays-future', 'stays-never', 'stays-unparseable'],
			array_column($stub->setSchema(schema: 'traffic-event')->findAll(), 'uuid')
		);
		$this->assertSame(0, $stub->purgeExpiredObjectsRaw(register: 'shillinq', schema: 'traffic-event'));
		$this->assertCount(1, $stub->setSchema(schema: 'telemetry')->findAll());
		$this->assertSame(0, $stub->purgeExpiredObjectsRaw(register: 'shillinq', schema: 'empty'));

	}//end testPurgeDropsOnlyExpiredRowsOnTheSchema()
}//end class
