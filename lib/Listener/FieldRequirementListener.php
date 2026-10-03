<?php

/**
 * FieldRequirementListener: refuse a person's save that leaves a field empty which the administration requires.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\Platform\FieldRequirements;
use OCA\Shillinq\Service\Platform\ObjectApiRequest;
use OCA\Shillinq\Service\Platform\SchemaDefinitions;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Pre-save veto for administration-required fields, and for requirements that name no field.
 *
 * The errors are keyed by field, so the form shows each one under its field, and carry a
 * `message` that becomes the 422 response's `error`.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
 */
class FieldRequirementListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param FieldRequirements      $requirements   The administration's requirements.
	 * @param SchemaDefinitions      $schemas        The saved object's schema.
	 * @param ObjectApiRequest       $request        Tells a person's save from a system write.
	 * @param ListenerSchemaResolver $schemaResolver Tells shillinq's register from others.
	 * @param IL10N                  $l10n           Translator.
	 * @param LoggerInterface        $logger         Logger for refusals.
	 */
	public function __construct(
		private readonly FieldRequirements $requirements,
		private readonly SchemaDefinitions $schemas,
		private readonly ObjectApiRequest $request,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Veto the save when a required field is empty or a requirement names no field.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
	 */
	public function handle(Event $event): void {
		$entity = null;
		if ($event instanceof ObjectCreatingEvent === true) {
			$entity = $event->getObject();
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$entity = $event->getNewObject();
		}

		if ($entity === null || $this->schemaResolver->isOwnRegister(entity: $entity) === false) {
			return;
		}

		$schemaId = (string)$entity->getSchema();
		$definition = $this->schemas->definition(schema: $schemaId);
		if ($definition === null) {
			return;
		}

		$data = ($entity->getObject() ?? []);
		$errors = $this->errors(definition: $definition, data: $data, schemaId: $schemaId, uuid: (string)$entity->getUuid());
		if ($errors === []) {
			return;
		}

		$this->logger->info('Shillinq: refused a save that misses a required field', ['schema' => $definition['slug'], 'errors' => $errors]);
		$event->setErrors($errors);
		$event->stopPropagation();

	}//end handle()

	/**
	 * The refusal for this save, keyed by field plus `message`; empty to let it through.
	 *
	 * @param array{slug: string, title: string, properties: array<string, mixed>, required: list<string>} $definition The object's schema.
	 * @param array<string, mixed>                                                                          $data       The object.
	 * @param string                                                                                        $schemaId   The schema id on the entity.
	 * @param string                                                                                        $uuid       The object's uuid.
	 *
	 * @return array<string, string> The errors.
	 */
	private function errors(array $definition, array $data, string $schemaId, string $uuid): array {
		// A requirement is configuration: it is checked on every write, whoever makes it.
		if (strcasecmp($definition['slug'], FieldRequirements::SCHEMA) === 0) {
			$refusal = $this->requirements->refusal(requirement: $data);
			if ($refusal === []) {
				return [];
			}

			return array_merge(['message' => $this->l10n->t('This required field cannot be saved.')], $refusal);
		}

		if ($this->request->isPersonSaving(schemaNames: [$definition['slug'], $schemaId], uuid: $uuid) === false) {
			return [];
		}

		$missing = $this->requirements->missing(definition: $definition, object: $data);
		if ($missing === []) {
			return [];
		}

		$errors = ['message' => $this->l10n->t('This administration requires: %s.', [implode(', ', array_column($missing, 'title'))])];
		foreach ($missing as $field) {
			$errors[$field['field']] = $this->l10n->t('%s is required in this administration.', [$field['title']]);
			if ($field['reason'] !== '') {
				$errors[$field['field']] = $this->l10n->t('%1$s is required in this administration: %2$s', [$field['title'], $field['reason']]);
			}
		}

		return $errors;

	}//end errors()
}//end class
