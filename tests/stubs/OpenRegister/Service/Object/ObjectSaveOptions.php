<?php

/**
 * ObjectSaveOptions stub mirroring OCA\OpenRegister\Service\Object\ObjectSaveOptions (openregister #9123).
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Stub for OCA\OpenRegister\Service\Object\ObjectSaveOptions used by shillinq tests.
 */
final class ObjectSaveOptions {
	/**
	 * Constructor.
	 *
	 * @param ObjectFindTarget $target        The register and schema.
	 * @param string|null      $uuid          Explicit uuid.
	 * @param array|null       $extend        Relations to expand on the result.
	 * @param array|null       $uploadedFiles Files uploaded alongside.
	 * @param SavePolicy       $policy        Events, validation and duplicate handling.
	 * @param SaveActor        $actor         The acting user and owner.
	 */
	public function __construct(
		public readonly ObjectFindTarget $target=new ObjectFindTarget(),
		public readonly ?string $uuid=null,
		public readonly ?array $extend=[],
		public readonly ?array $uploadedFiles=null,
		public readonly SavePolicy $policy=new SavePolicy(),
		public readonly SaveActor $actor=new SaveActor(),
	) {
	}//end __construct()
}//end class
