<?php

/**
 * ConcludedSubjectLookupTest — finding the finance object of a concluded outcome.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Listener\ConcludedOutcomeLog;
use OCA\Shillinq\Listener\ConcludedSubjectLookup;
use OCA\Shillinq\Listener\ConcludedSubjectStore;
use OCA\Shillinq\Listener\FoundSubject;
use OCA\Shillinq\Listener\SubjectRef;
use OCA\Shillinq\Util\ObjectIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers OCA\Shillinq\Listener\ConcludedSubjectLookup.
 */
#[CoversClass(ConcludedSubjectLookup::class)]
#[UsesClass(ConcludedOutcomeLog::class)]
#[UsesClass(ConcludedSubjectStore::class)]
#[UsesClass(FoundSubject::class)]
#[UsesClass(SubjectRef::class)]
#[UsesClass(ObjectIdentifier::class)]
final class ConcludedSubjectLookupTest extends TestCase {

	/**
	 * Every info line: message and context.
	 *
	 * @var list<array{0: string, 1: array<string,mixed>}>
	 */
	private array $lines = [];

	/**
	 * Every id the object service was asked to find, with its schema.
	 *
	 * @var list<string>
	 */
	private array $finds = [];

	/**
	 * A lookup over a store holding $objects (schema => id => object).
	 *
	 * @param array<string,array<string,array<string,mixed>>> $objects The stored objects.
	 *
	 * @return ConcludedSubjectLookup The lookup.
	 */
	private function lookup(array $objects): ConcludedSubjectLookup {
		$schema  = '';
		$service = $this->createMock(ObjectServiceInterface::class);
		$service->method('setRegister')->willReturn($service);
		$service->method('setSchema')->willReturnCallback(
			function (string $name) use (&$schema, $service) {
				$schema = $name;
				return $service;
			}
		);
		$service->method('find')->willReturnCallback(
			function (string $id) use (&$schema, $objects) {
				$this->finds[] = $schema . ':' . $id;
				if (isset($objects[$schema][$id]) === false) {
					return null;
				}

				return (new ObjectEntity())->setObject($objects[$schema][$id]);
			}
		);

		$location = $this->createMock(RegisterLocationInterface::class);
		$location->method('getRegisterSlug')->willReturn('books');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->lines[] = [$message, $context];
			}
		);

		return new ConcludedSubjectLookup(
			store: new ConcludedSubjectStore(registerLocation: $location, objectService: $service),
			log: new ConcludedOutcomeLog(logger: $logger, listener: 'DemoListener', refKey: 'decisionRef', idKey: 'decisionId'),
			knownSchemas: ['AnnualReport', 'ACMReport'],
			request: 'decision'
		);

	}//end lookup()

	/**
	 * A normalised payload.
	 *
	 * @param string $externalReference The reference shillinq sent.
	 * @param string $subjectId         The event's subject id.
	 * @param string $subjectSchema     The event's subject schema.
	 *
	 * @return array{decisionId: string, externalReference: string, subjectId: string, subjectSchema: string}
	 */
	private static function payload(string $externalReference, string $subjectId='', string $subjectSchema=''): array {
		return [
			'decisionId'        => 'd-1',
			'externalReference' => $externalReference,
			'subjectId'         => $subjectId,
			'subjectSchema'     => $subjectSchema,
		];

	}//end payload()

	/**
	 * An event without any subject id finds nothing, asks nothing and logs the request.
	 *
	 * @return void
	 */
	public function testNoSubjectIdIsLoggedAndSkipped(): void {
		$found = $this->lookup(objects: [])->find(payload: self::payload(externalReference: ''));

		self::assertNull($found);
		self::assertSame([], $this->finds);
		self::assertSame([['DemoListener: no subject id on concluded decision (skipping)', ['decisionRef' => 'd-1']]], $this->lines);

	}//end testNoSubjectIdIsLoggedAndSkipped()

	/**
	 * A subject id no schema holds is logged as no match, after every known schema was tried.
	 *
	 * @return void
	 */
	public function testNoMatchIsLoggedAfterEveryKnownSchema(): void {
		$found = $this->lookup(objects: [])->find(payload: self::payload(externalReference: 'r-9'));

		self::assertNull($found);
		self::assertSame(['AnnualReport:r-9', 'ACMReport:r-9'], $this->finds);
		self::assertSame([['DemoListener: no matching finance object (skipping)', ['decisionRef' => 'd-1', 'subjectId' => 'r-9']]], $this->lines);

	}//end testNoMatchIsLoggedAfterEveryKnownSchema()

	/**
	 * The subject id falls back to subjectId, the hinted schema is tried first, and a hit logs nothing.
	 *
	 * @return void
	 */
	public function testAHitInTheHintedSchemaIsReturnedSilently(): void {
		$objects = ['ACMReport' => ['s-2' => ['id' => 's-2', 'title' => 'ACM 2026']]];

		$found = $this->lookup(objects: $objects)->find(payload: self::payload(externalReference: '', subjectId: 's-2', subjectSchema: 'ACMReport'));

		self::assertInstanceOf(FoundSubject::class, $found);
		self::assertSame('ACMReport', $found->ref->schema);
		self::assertSame('s-2', $found->ref->id);
		self::assertSame('ACM 2026', $found->object['title']);
		self::assertSame(['ACMReport:s-2'], $this->finds);
		self::assertSame([], $this->lines);

	}//end testAHitInTheHintedSchemaIsReturnedSilently()
}//end class
