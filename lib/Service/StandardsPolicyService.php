<?php

/**
 * Standards Policy Service
 *
 * Resolves which accounting/reporting framework wins when an administration is
 * subject to several at once (REQ-ASP-003). An administrator declares the
 * applicable frameworks and their order of precedence via the
 * StandardsPolicyEditor settings page, persisted as a single StandardsPolicy
 * object (REQ-ASP-001/002). This service is the single seam future posting and
 * valuation logic consults: resolve(topic) returns the highest-precedence
 * enabled framework key for a conflict topic (revenue, leases, inventory,
 * development costs — see docs/standards/comparisons.md), or null when no
 * framework is enabled.
 *
 * The ranking logic lives in the pure, unit-tested resolveFromPolicy(); resolve()
 * loads the policy through the real OpenRegister ObjectService (ADR-022) and
 * delegates. Per the proposal this resolver is wired but NOT yet applied to any
 * real GL/valuation path — the topic argument is reserved for future per-topic
 * framework applicability.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/accounting-standards-policy/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Resolves the precedence-winning accounting framework from the StandardsPolicy.
 */
class StandardsPolicyService {

	/**
	 * OpenRegister register slug the StandardsPolicy object lives in.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'shillinq';

	/**
	 * StandardsPolicy schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA = 'StandardsPolicy';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is fetched lazily (ADR-022).
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * Resolve the highest-precedence enabled framework for a conflict topic.
	 *
	 * Loads the administration's StandardsPolicy (the default policy when
	 * $administrationId is null) and returns the winning framework key, or null
	 * when the policy is missing or has no enabled framework. The $topic argument
	 * is reserved for future per-topic applicability and does not yet narrow the
	 * result.
	 *
	 * @param string|null $topic Conflict topic (e.g. 'leases', 'revenue'); reserved.
	 * @param string|null $administrationId Optional administration scope.
	 *
	 * @return string|null The winning framework key, or null.
	 */
	public function resolve(?string $topic = null, ?string $administrationId = null): ?string {
		return $this->resolveFromPolicy($this->loadFrameworks($administrationId), $topic);
	}//end resolve()

	/**
	 * Pure ranking logic: pick the enabled framework with the lowest precedence.
	 *
	 * Disabled frameworks are ignored; ties break on the original order. Returns
	 * null when no framework is enabled. Side-effect free and fully unit-tested
	 * (REQ-ASP-003).
	 *
	 * @param array<int, array<string, mixed>> $frameworks The policy's frameworks[] list.
	 * @param string|null $topic Conflict topic; reserved for future use.
	 *
	 * @return string|null The winning framework key, or null.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $topic is explicitly
	 *     reserved for future use per its own docblock line above.
	 */
	public function resolveFromPolicy(array $frameworks, ?string $topic = null): ?string {
		$enabled = array_values(
			array_filter(
				$frameworks,
				static function ($framework): bool {
					return is_array($framework) === true
						&& ($framework['enabled'] ?? false) === true
						&& isset($framework['key']) === true
						&& is_string($framework['key']) === true;
				}
			)
		);

		if (empty($enabled) === true) {
			return null;
		}

		usort(
			$enabled,
			static function (array $left, array $right): int {
				return ((int)($left['precedence'] ?? PHP_INT_MAX)) <=> ((int)($right['precedence'] ?? PHP_INT_MAX));
			}
		);

		return (string)$enabled[0]['key'];
	}//end resolveFromPolicy()

	/**
	 * Load the StandardsPolicy frameworks[] list through the real ObjectService.
	 *
	 * Returns the frameworks of the first matching StandardsPolicy object, or an
	 * empty array when no policy exists (or OpenRegister is unavailable). Read
	 * via the fluent setRegister/setSchema/findAll API per ADR-022.
	 *
	 * @param string|null $administrationId Optional administration scope.
	 *
	 * @return array<int, array<string, mixed>> The frameworks list (possibly empty).
	 */
	private function loadFrameworks(?string $administrationId): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

			$filters = [];
			if ($administrationId !== null) {
				$filters['administrationId'] = $administrationId;
			}

			$rows = $objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::SCHEMA)
				->findAll(
					[
						'filters' => $filters,
						'limit' => 1,
					]
				);
		} catch (Throwable $e) {
			return [];
		}

		if (empty($rows) === true) {
			return [];
		}

		$policy = $this->normalise($rows[0]);
		$frameworks = ($policy['frameworks'] ?? []);

		return is_array($frameworks) === true ? $frameworks : [];
	}//end loadFrameworks()

	/**
	 * Normalise an ObjectService row (ObjectEntity or array) to a plain array.
	 *
	 * @param mixed $row Raw row from ObjectService::findAll().
	 *
	 * @return array<string, mixed> The object as an associative array.
	 */
	private function normalise(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$serialised = $row->jsonSerialize();
			return is_array($serialised) === true ? $serialised : [];
		}

		if (is_object($row) === true) {
			return (array)$row;
		}

		return [];
	}//end normalise()
}//end class
