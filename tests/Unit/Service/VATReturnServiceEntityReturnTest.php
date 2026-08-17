<?php

/**
 * Regression guard: VATReturnService must accept ObjectEntity returns.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\VATReturnService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * OpenRegister's ObjectService declares
 *
 *   public function saveObject(...): ObjectEntity
 *   public function find(...): ?ObjectEntity
 *
 * Neither ever returns an array. VATReturnService tested `is_array()` on both
 * and threw when it was false, so on a real instance EVERY VAT write threw:
 *
 *   RuntimeException: ObjectService::saveObject(...) did not return an array
 *
 * which VATReturnController converted to HTTP 500 on POST /api/vat-returns.
 *
 * The existing VATReturnServiceTest did not catch this because its fake
 * ObjectService returns plain arrays — it is MORE PERMISSIVE than the thing it
 * stands in for, so the suite was green against a contract the real collaborator
 * does not honour. This guard uses a fake whose return types match the real
 * signatures: an entity object exposing jsonSerialize(), and nothing else.
 *
 * The bug was also invisible end-to-end for as long as the VATReturn/VatReturn
 * slug collision existed, because OpenRegister rejected the payload against the
 * WRONG schema's required list before either return value was inspected. The
 * two defects were stacked; fixing the slug alone just moved the 500 one line
 * down (measured in run 31172863210).
 */
final class VATReturnServiceEntityReturnTest extends TestCase {
	/**
	 * createReturn() succeeds when ObjectService returns entities, not arrays.
	 *
	 * @return void
	 */
	public function testCreateReturnAcceptsObjectEntityReturns(): void {
		$objectService = $this->entityReturningObjectService();
		$service = new VATReturnService(
			appConfig: $this->createMock(IAppConfig::class),
			logger: new NullLogger(),
			objectService: new DuckObjectServiceAdapter($objectService),
		);

		$created = $service->createReturn(
			administrationId: 'adm-smb-1',
			period: 'quarter',
			periodYear: 2024,
			periodNumber: 1,
			regime: 'standard'
		);

		self::assertIsArray($created, 'createReturn() must return the persisted row as an array.');
		self::assertNotSame('', (string)($created['id'] ?? ''), 'The persisted row must carry an id.');
		self::assertSame('draft', $created['statusCode'] ?? null);
		self::assertSame('adm-smb-1', $created['administrationId'] ?? null);
	}//end testCreateReturnAcceptsObjectEntityReturns()

	/**
	 * findReturn() resolves an ObjectEntity, and yields null ONLY when absent.
	 *
	 * This is the exact boundary that produced the "create returns an id the
	 * show endpoint then 404s on" defect: VATReturnController tested
	 * `is_array()` on ObjectService::find()'s return value, which is
	 * `?ObjectEntity` and therefore never an array — so GET
	 * /api/vat-returns/{id} answered 404 for every return that exists, and
	 * DELETE answered 404 where the "only drafts can be deleted" rule owes a
	 * 409. Both directions are asserted here: a present row must come back as a
	 * populated array, and only a genuinely missing row may be null.
	 *
	 * @return void
	 */
	public function testFindReturnResolvesAnEntityAndNullsOnlyWhenAbsent(): void {
		$objectService = $this->entityReturningObjectService();
		$service = new VATReturnService(
			appConfig: $this->createMock(IAppConfig::class),
			logger: new NullLogger(),
			objectService: new DuckObjectServiceAdapter($objectService),
		);

		$created = $service->createReturn(
			administrationId: 'adm-smb-1',
			period: 'quarter',
			periodYear: 2024,
			periodNumber: 1,
			regime: 'standard'
		);
		$id = (string)($created['id'] ?? '');

		$found = $service->findReturn(returnId: $id);
		self::assertIsArray($found, 'findReturn() must normalise the ObjectEntity into an array.');
		self::assertSame($id, (string)($found['id'] ?? ''));
		self::assertSame('draft', $found['statusCode'] ?? null);

		self::assertNull(
			$service->findReturn(returnId: 'no-such-return'),
			'findReturn() may only be null when the row genuinely does not exist.'
		);
	}//end testFindReturnResolvesAnEntityAndNullsOnlyWhenAbsent()

	/*
	 * REMOVED: testUnconvertibleRowRaisesRatherThanReturningEmpty().
	 *
	 * It asserted that normaliseRow() raises, rather than returning [], for a
	 * row it cannot convert. ADR-084 moved that guarantee from RUNTIME to the
	 * TYPE SYSTEM: ObjectServiceInterface::find() / ::saveObject() can only
	 * hand back an ObjectEntityInterface, and that interface DECLARES
	 * getObject(): array. So a conforming implementation normalises by
	 * construction and the branch this test covered is unreachable — its
	 * fixture (an entity exposing neither jsonSerialize() nor getObject())
	 * can no longer be passed to the service at all.
	 *
	 * It is deleted rather than retargeted because the only ways to make it
	 * pass would be to change the expected exception class or to rewrite the
	 * fixture, either of which leaves a test asserting something other than
	 * what its name claims.
	 *
	 * Measured, and the reason this is not a loss of coverage: it was GREEN on
	 * `development` for the WRONG REASON. The unconfigured ObjectService mock
	 * made the service throw `RuntimeException: VATReturn save did not return
	 * an identifier` before normaliseRow() was ever reached, and a bare
	 * expectException(RuntimeException::class) accepts any RuntimeException.
	 * The guard under test never ran. Deleting it removes a false signal.
	 *
	 * See lib/Service/VATReturnService.php::normaliseRow(), where the
	 * corresponding throw is kept as a deliberate type-boundary tripwire.
	 */

	/**
	 * Build a PSR-11 container that yields the given fake ObjectService.
	 *
	 * @param object $objectService The fake.
	 *
	 * @return ContainerInterface
	 */
	private function containerFor(object $objectService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService): object {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objectService;
				}

				throw new \RuntimeException('Unexpected container id ' . $id);
			}
		);

		return $container;
	}//end containerFor()

	/**
	 * A fake ObjectService whose return types match OpenRegister's real ones.
	 *
	 * `saveObject()` returns an entity object (never an array) and `find()`
	 * returns an entity object or null — exactly as declared on
	 * OCA\OpenRegister\Service\ObjectService.
	 *
	 * The `serialisable: false` variant was dropped together with
	 * testUnconvertibleRowRaisesRatherThanReturningEmpty() — under ADR-084 an
	 * entity that exposes neither jsonSerialize() nor getObject() cannot
	 * satisfy ObjectEntityInterface, so it is not a shape the service can
	 * receive and the branch had no remaining caller.
	 *
	 * @return object The fake ObjectService.
	 */
	private function entityReturningObjectService(): object {
		return new class {
			/**
			 * Rows keyed by schema slug.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data = [];

			/**
			 * Active schema slug.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Auto-increment id counter.
			 *
			 * @var integer
			 */
			private int $counter = 0;

			/**
			 * Fluent register setter (no-op).
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return rows for the active schema (arrays, as OR's findAll does).
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				return ($this->data[$this->schema] ?? []);
			}//end findAll()

			/**
			 * Find one row, returned as an ENTITY (never an array), or null.
			 *
			 * @param string $id Row id.
			 *
			 * @return object|null
			 */
			public function find(string $id): ?object {
				foreach (($this->data[$this->schema] ?? []) as $row) {
					if (((string)($row['id'] ?? '')) === $id) {
						return $this->wrap(row: $row);
					}
				}

				return null;
			}//end find()

			/**
			 * Persist a row and return it as an ENTITY (never an array).
			 *
			 * @param array<string,mixed> $data Row body.
			 *
			 * @return object
			 */
			public function saveObject(array $data): object {
				if (isset($data['id']) === false || $data['id'] === '') {
					$this->counter++;
					$data['id'] = $this->schema . '-' . $this->counter;
				}

				foreach (($this->data[$this->schema] ?? []) as $idx => $row) {
					if (((string)($row['id'] ?? '')) === ((string)$data['id'])) {
						$this->data[$this->schema][$idx] = $data;
						return $this->wrap(row: $data);
					}
				}

				$this->data[$this->schema][] = $data;
				return $this->wrap(row: $data);
			}//end saveObject()

			/**
			 * Delete a row by id.
			 *
			 * @param string $id Row id.
			 *
			 * @return void
			 */
			public function deleteObject(string $id): void {
				$this->data[$this->schema] = array_values(
					array_filter(
						($this->data[$this->schema] ?? []),
						static fn (array $row): bool => ((string)($row['id'] ?? '')) !== $id
					)
				);
			}//end deleteObject()

			/**
			 * Wrap a row in an ObjectEntity-shaped object.
			 *
			 * @param array<string,mixed> $row The row.
			 *
			 * @return object
			 */
			private function wrap(array $row): object {
				return new class($row) implements \JsonSerializable {
					/**
					 * The wrapped row.
					 *
					 * @var array<string,mixed>
					 */
					private array $row;

					/**
					 * Constructor.
					 *
					 * @param array<string,mixed> $row The row.
					 */
					public function __construct(array $row) {
						$this->row = $row;
					}//end __construct()

					/**
					 * Serialise to the plain row.
					 *
					 * @return array<string,mixed>
					 */
					public function jsonSerialize(): array {
						return $this->row;
					}//end jsonSerialize()
				};
			}//end wrap()
		};
	}//end entityReturningObjectService()
}//end class
