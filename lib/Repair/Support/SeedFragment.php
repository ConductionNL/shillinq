<?php

/**
 * Shillinq repair-step seed fragment reader.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair\Support;

use OCP\Migration\IOutput;

/**
 * A register fragment JSON file that a seed step reads its `objects[]` from.
 * Every reason not to seed is reported on the step's output, prefixed with
 * the step's label.
 *
 * @spec openspec/specs/app-administration/spec.md
 */
final class SeedFragment {
	/**
	 * Constructor.
	 *
	 * @param string $path  Absolute path to the fragment JSON.
	 * @param string $label The seed's name in messages (e.g. `CBS`).
	 */
	public function __construct(
		private readonly string $path,
		private readonly string $label,
	) {
	}//end __construct()

	/**
	 * The fragment's objects, or null (reported) when there is nothing to seed.
	 *
	 * @param IOutput $output Progress output.
	 *
	 * @return array<int, mixed>|null The seed objects, or null to skip.
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	public function objects(IOutput $output): array|null {
		$data = $this->decode(output: $output);
		if ($data === null) {
			return null;
		}

		$objects = ($data['objects'] ?? []);
		if (is_array($objects) === false || $objects === []) {
			$output->info($this->label . ' seed fragment carries no objects; nothing to seed');
			return null;
		}

		return $objects;
	}//end objects()

	/**
	 * Read and decode the fragment, or null (reported) when it is missing, unreadable or not a JSON object.
	 *
	 * @param IOutput $output Progress output.
	 *
	 * @return array<mixed>|null The decoded fragment.
	 */
	private function decode(IOutput $output): array|null {
		if (file_exists($this->path) === false) {
			$output->warning($this->label . ' seed fragment not found at ' . $this->path . ', skipping');
			return null;
		}

		$content = file_get_contents($this->path);
		if ($content === false) {
			$output->warning('Failed to read ' . $this->label . ' seed fragment, skipping');
			return null;
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($data) === false) {
			$output->warning('Failed to parse ' . $this->label . ' seed fragment: ' . json_last_error_msg());
			return null;
		}

		return $data;
	}//end decode()
}//end class
