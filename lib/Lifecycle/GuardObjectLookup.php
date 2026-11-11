<?php

/**
 * Guard Object Lookup
 *
 * Resolves the object a lifecycle guard checks: the in-flight object the
 * engine supplied, else the first array row an ObjectService findAll() with
 * an `id` property filter answers in the configured register (default
 * `shillinq`). An empty id resolves nothing.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IAppConfig;

/**
 * The object lookup the lifecycle guards share.
 *
 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
 */
final class GuardObjectLookup {

	/**
	 * Hold the app config and the object service.
	 *
	 * @param IAppConfig             $appConfig     App config for the register slug.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Resolve the object under transition, preferring the supplied in-flight
	 * object and falling back to an ObjectService lookup by id (ADR-022 real API).
	 *
	 * @param GuardSubject             $subject The schema and id to look up.
	 * @param array<string,mixed>|null $object  The in-flight object, if provided by the engine.
	 *
	 * @return array<string,mixed>|null The resolved object, or null when unavailable.
	 *
	 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
	 */
	public function resolve(GuardSubject $subject, array|null $object): array|null {
		if ($object !== null) {
			return $object;
		}

		if ($subject->id === '') {
			return null;
		}

		return $this->find(subject: $subject);
	}//end resolve()

	/**
	 * The first array row a findAll() by `id` property filter answers.
	 *
	 * @param GuardSubject $subject The schema and id to look up.
	 *
	 * @return array<string,mixed>|null The row, or null.
	 */
	private function find(GuardSubject $subject): array|null {
		$results = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema($subject->schema)
			->findAll(['filters' => ['id' => $subject->id]]);

		foreach ($results as $result) {
			if (is_array($result) === true) {
				return $result;
			}
		}

		return null;
	}//end find()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
	 *
	 * @return string The register slug.
	 */
	private function registerSlug(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end registerSlug()
}//end class
