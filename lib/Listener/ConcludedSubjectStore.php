<?php

/**
 * Concluded Subject Store
 *
 * Finds and updates the finance object a concluded cross-app outcome points
 * at: the subject id prefers the externalReference shillinq sent on the
 * request and falls back to subjectId; the search tries the event's
 * subjectSchema first, then the listener's known subject schemas.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use Throwable;

/**
 * Register access for the concluded-outcome listeners.
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 */
final class ConcludedSubjectStore {

	/**
	 * Hold the settings and the object service.
	 *
	 * @param RegisterLocation        $registerLocation Where the register lives (slug + OpenRegister availability).
	 * @param ObjectServiceInterface $objectService   OpenRegister's published object surface (ADR-084).
	 */
	public function __construct(
		private readonly RegisterLocation $registerLocation,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The subject id of a concluded event — the externalReference we sent on
	 * the request, else subjectId.
	 *
	 * @param array{externalReference: string, subjectId: string} $payload The normalised event payload.
	 *
	 * @return string The subject id, or '' when none.
	 *
	 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function subjectId(array $payload): string {
		if ($payload['externalReference'] !== '') {
			return $payload['externalReference'];
		}

		return $payload['subjectId'];
	}//end subjectId()

	/**
	 * Find the finance object the concluded event belongs to: the hinted
	 * subjectSchema first (when present), then each known schema once.
	 *
	 * @param array{externalReference: string, subjectId: string, subjectSchema: string} $payload      The normalised event payload.
	 * @param list<string>                                                              $knownSchemas The listener's subject schemas.
	 *
	 * @return FoundSubject|null The object, or null when no schema holds it.
	 *
	 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function locate(array $payload, array $knownSchemas): FoundSubject|null {
		$subjectId = $this->subjectId(payload: $payload);
		foreach ($this->searchOrder(hintedSchema: $payload['subjectSchema'], knownSchemas: $knownSchemas) as $schema) {
			$ref = new SubjectRef(schema: $schema, id: $subjectId);
			$object = $this->findObject(ref: $ref);
			if ($object !== null) {
				return new FoundSubject(ref: $ref, object: $object);
			}
		}

		return null;
	}//end locate()

	/**
	 * Persist field updates onto the finance object via OR.
	 *
	 * @param SubjectRef          $ref     The object to write.
	 * @param array<string,mixed> $updates The fields to write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function persist(SubjectRef $ref, array $updates): void {
		$this->objectService
			->setRegister($this->registerLocation->getRegisterSlug())
			->setSchema($ref->schema)
			->updateObject($ref->id, $updates);
	}//end persist()

	/**
	 * The schemas to search, hinted schema first, without repeats.
	 *
	 * @param string       $hintedSchema The event's subjectSchema, or ''.
	 * @param list<string> $knownSchemas The listener's subject schemas.
	 *
	 * @return list<string> The search order.
	 */
	private function searchOrder(string $hintedSchema, array $knownSchemas): array {
		if ($hintedSchema === '') {
			return $knownSchemas;
		}

		array_unshift($knownSchemas, $hintedSchema);

		return array_values(array_unique($knownSchemas));
	}//end searchOrder()

	/**
	 * Find a finance object by id within its schema, as a plain array; a
	 * failing lookup counts as not found.
	 *
	 * @param SubjectRef $ref The object to find.
	 *
	 * @return array<string,mixed>|null The object, or null.
	 */
	private function findObject(SubjectRef $ref): array|null {
		try {
			$result = ObjectIdentifier::findScoped(
				scoped: $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema($ref->schema),
				id: $ref->id
			);

			return $this->toArray(result: $result);
		} catch (Throwable $e) {
			return null;
		}
	}//end findObject()

	/**
	 * Normalise an OR find result to a plain array.
	 *
	 * @param mixed $result OR return value.
	 *
	 * @return array<string,mixed>|null The plain object, or null.
	 */
	private function toArray(mixed $result): array|null {
		if (is_array($result) === true) {
			return $result;
		}

		if (is_object($result) === false || method_exists($result, 'jsonSerialize') === false) {
			return null;
		}

		$serialized = $result->jsonSerialize();
		if (is_array($serialized) === true) {
			return $serialized;
		}

		return null;
	}//end toArray()
}//end class
