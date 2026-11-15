<?php

/**
 * ObjectService double that also takes OpenRegister main's saveObject() options.
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\IUser;

/**
 * The test stub of ObjectServiceInterface still declares the old saveObject()
 * signature, which the rest of the suite implements. Code already on main's
 * API calls saveObject(object:, options:); this abstract widens the stub's
 * signature with a trailing `$options` so such a call can be mocked, and
 * leaves the stub itself untouched.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 */
abstract class MainApiObjectService implements ObjectServiceInterface {

	/**
	 * Save an object, in either the old named-argument form or main's options form.
	 *
	 * @param array                  $object        The object to store.
	 * @param ?array                 $extend        Relations to expand on the result.
	 * @param string|int|null        $register      Register id, UUID or slug.
	 * @param string|int|null        $schema        Schema id, UUID or slug.
	 * @param ?string                $uuid          The object UUID.
	 * @param bool                   $_rbac         Apply register RBAC.
	 * @param bool                   $_multitenancy Apply organisation scoping.
	 * @param bool                   $silent        Suppress events for this save.
	 * @param bool                   $_validation   Validate against the schema.
	 * @param ?array                 $uploadedFiles Files uploaded alongside the object.
	 * @param ?IUser                 $currentUser   Explicit acting user.
	 * @param bool                   $failIfExists  Fail when the object already exists.
	 * @param ObjectSaveOptions|null $options       Main's options form.
	 *
	 * @return ObjectEntityInterface The stored object.
	 */
	abstract public function saveObject(
		array $object,
		?array $extend=[],
		string|int|null $register=null,
		string|int|null $schema=null,
		?string $uuid=null,
		bool $_rbac=true,
		bool $_multitenancy=true,
		bool $silent=false,
		bool $_validation=true,
		?array $uploadedFiles=null,
		?IUser $currentUser=null,
		bool $failIfExists=false,
		?ObjectSaveOptions $options=null
	): ObjectEntityInterface;
}//end class
