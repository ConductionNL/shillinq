<?php

/**
 * Test stub for OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions.
 *
 * Mirrors the constructor of the real value object on OpenRegister main
 * (lib/Kernel/Contracts/Value/ObjectSaveOptions.php).
 *
 * @category Test
 * @package  OCA\OpenRegister
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

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * How one object save runs.
 */
final class ObjectSaveOptions {

	/**
	 * Constructor.
	 *
	 * @param ObjectFindTarget $target        Register and schema of the save.
	 * @param ?string          $uuid          The object UUID to update.
	 * @param ?array           $extend        Relations to expand on the result.
	 * @param ?array           $uploadedFiles Files uploaded alongside the object.
	 * @param SavePolicy       $policy        Events, validation and existence policy.
	 * @param SaveActor        $actor         On whose behalf the save runs.
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
