<?php

/**
 * The in-memory ObjectService double with real OpenRegister's identifier
 * semantics restored.
 *
 * ## Why a second double exists
 *
 * {@see \OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService} matches every
 * filter key against a plain PHP array key, `id` and `uuid` included. Real
 * OpenRegister cannot: `filters` addresses the object's JSON PROPERTIES, while
 * `id`/`uuid` are the ObjectEntity's own COLUMNS, merged into the serialised
 * output only after the query has run. Against the real engine
 * `findAll(['filters' => ['id' => $x]])` therefore matches ZERO rows — for
 * every value, uuids included — and it does so SILENTLY: no exception, nothing
 * logged, an empty array indistinguishable from a genuinely absent record.
 *
 * A production lookup written that way is dead on arrival and still passes
 * against the lenient double. `DunningRunService::transferToIncasso()` shipped
 * exactly that: its sealing lookup could never find the run it was meant to
 * seal, under two green unit tests, on a path a new controller route was about
 * to make reachable.
 *
 * This subclass changes ONE thing — a filter naming an entity column answers
 * empty, the way the engine answers it. Everything else (seeding, upsert-by-id
 * `saveObject()`, `dump()`) is inherited unchanged, so a test can be moved onto
 * it by swapping the constructor and nothing else.
 *
 * ⚠️ It is deliberately NOT the default. The app still carries ~60 live
 * `filters['id']` sites (shillinq#871), and flipping the default red-lines 27
 * tests across six services at once — repairs that are product decisions, not
 * test-side ones. Use this double wherever a test must be ABLE to see the
 * defect; a test whose double cannot fail here proves nothing about the
 * lookup it exercises.
 *
 * 🔑 Look one object up by identity with `find()`, which
 * {@see DuckObjectServiceAdapter::find()} models by scanning for the `id`/`uuid`
 * of the stored rows — the same identifier space OpenRegister's own `find()`
 * resolves.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Support;

use OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService;

require_once __DIR__ . '/../InMemoryObjectService.php';

/**
 * In-memory ObjectService double that refuses identifier-column filters.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class OpenRegisterFaithfulObjectService extends InMemoryObjectService {

	/**
	 * ObjectEntity columns that `filters` cannot address in real OpenRegister.
	 *
	 * @var array<int,string>
	 */
	private const ENTITY_COLUMNS = ['id', 'uuid'];

	/**
	 * Find all records on the active schema matching the filter map.
	 *
	 * A filter naming an entity column can never match, so the whole query
	 * answers empty — the engine's behaviour, not an approximation of it. Any
	 * other filter map is answered by the inherited property matcher.
	 *
	 * @param array<string,mixed> $args ['filters' => ...].
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function findAll(array $args = []): array {
		$filters = (array)($args['filters'] ?? []);
		foreach (self::ENTITY_COLUMNS as $column) {
			if (array_key_exists($column, $filters) === true) {
				return [];
			}
		}

		return parent::findAll($args);

	}//end findAll()
}//end class
