<?php

/**
 * ObjectApiRequest: whether the current request is a person saving this object through the object API.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Platform
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

namespace OCA\Shillinq\Service\Platform;

use OCP\IRequest;
use Throwable;

/**
 * Tells a person's own save from a system write.
 *
 * Shillinq's services, listeners, jobs and repair steps write through OpenRegister's
 * ObjectService in PHP, so no flag reaches the pre-save event. What does tell them apart
 * is the request: a form saves with POST, PUT or PATCH on
 * `/apps/openregister/api/objects/{register}/{schema}[/{id}]`, naming the very schema
 * (and, on an update, the very object) being saved. Any other write in that request (a
 * listener booking a transaction, a lifecycle action), in a shillinq controller, in a
 * background job or on the command line is a system write.
 *
 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
 */
class ObjectApiRequest {

	/**
	 * The object API's create and update paths: register, schema, optional id, nothing after.
	 */
	private const PATH = '#/apps/openregister/api/objects/([^/]+)/([^/]+)(?:/([^/]+))?/?$#';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current request.
	 */
	public function __construct(
		private readonly IRequest $request,
	) {

	}//end __construct()

	/**
	 * Whether this request is a person saving this object of this schema through the object API.
	 *
	 * @param list<string> $schemaNames The schema's slug and id, either of which the path may carry.
	 * @param string       $uuid        The object being saved.
	 *
	 * @return bool True for a person's own save.
	 *
	 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
	 */
	public function isPersonSaving(array $schemaNames, string $uuid): bool {
		try {
			$method = strtoupper((string)$this->request->getMethod());
			$uri = (string)$this->request->getRequestUri();
		} catch (Throwable $e) {
			return false;
		}

		if (in_array($method, ['POST', 'PUT', 'PATCH'], true) === false) {
			return false;
		}

		$path = (string)strtok($uri, '?');
		if (preg_match(self::PATH, $path, $parts) !== 1) {
			return false;
		}

		$schema = rawurldecode($parts[2]);
		$named = false;
		foreach ($schemaNames as $name) {
			if ($name !== '' && strcasecmp($name, $schema) === 0) {
				$named = true;
			}
		}

		if ($named === false) {
			return false;
		}

		$id = rawurldecode(($parts[3] ?? ''));

		return $id === '' || $id === $uuid;

	}//end isPersonSaving()
}//end class
