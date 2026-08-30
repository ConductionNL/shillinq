<?php

/**
 * Minimal ObjectEntityInterface stub for unit tests that mock/typehint
 * OpenRegister's object entity contract without depending on the
 * OpenRegister app being autoloaded. This is the read surface exercised by
 * the app's own conversions (`jsonSerialize()`/`getObject()` and the
 * ownership getters), sized to the INTERSECTION of the two pre-existing
 * stub implementers already in this repo —
 * `tests/stubs/OpenRegister/Db/ObjectEntity.php` (which additionally
 * declares setters + getId()/setId(), not part of this contract) and
 * `tests/Unit/Service/Support/ObjectEntityStub.php` (the narrower one,
 * read-only, no id accessors) — so both remain valid implementers. Neither
 * of those pre-existing files could actually compile until this interface
 * was added: no `Contract/` directory previously existed under
 * tests/stubs/OpenRegister/, so every test referencing this interface (and
 * `OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter`, wired
 * into 128 test classes per commit ef9c8fa5) fatal'd with "Interface ...
 * not found" before this file was added. When run inside a deployed
 * Nextcloud tree with OpenRegister installed, `lib/base.php` provides the
 * real interface and this stub is simply shadowed (same convention as the
 * sibling stubs registered in tests/bootstrap-unit.php).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests
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

namespace OCA\OpenRegister\Contract;

/**
 * Stub for OCA\OpenRegister\Contract\ObjectEntityInterface used by shillinq tests.
 */
interface ObjectEntityInterface extends \JsonSerializable {

	/**
	 * Return the decoded object payload.
	 *
	 * @return array<string,mixed>
	 */
	public function getObject(): array;

	/**
	 * Return the schema slug.
	 *
	 * @return string|null
	 */
	public function getSchema(): ?string;

	/**
	 * Return the register slug.
	 *
	 * @return string|null
	 */
	public function getRegister(): ?string;

	/**
	 * Return the uuid.
	 *
	 * @return string|null
	 */
	public function getUuid(): ?string;

	/**
	 * Return the owning uid, when the entity carries one.
	 *
	 * @return string|null
	 */
	public function getOwner(): ?string;

	/**
	 * Return the organisation id, when the entity carries one.
	 *
	 * @return string|null
	 */
	public function getOrganisation(): ?string;

}//end interface
