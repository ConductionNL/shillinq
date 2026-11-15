<?php

/**
 * Signing Conclusion
 *
 * A docudesk signed, declined or expired conclusion for a finance object: the
 * outcome and request id, the optional provider, level and signed document,
 * the accounting consequence and the Activity object type.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Signing
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

namespace OCA\Shillinq\Service\Signing;


/**
 * One concluded signing request, as docudesk reported it.
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 */
final class SigningConclusion {

	/**
	 * eIDAS provider, or null to leave the field unwritten.
	 *
	 * @var string|null
	 */
	public ?string $signingProvider = null;

	/**
	 * eIDAS level, or null to leave the field unwritten.
	 *
	 * @var string|null
	 */
	public ?string $signingLevel = null;

	/**
	 * NC Files ref to the signed artifact, or null to leave the field unwritten.
	 *
	 * @var string|null
	 */
	public ?string $signedDocumentRef = null;

	/**
	 * Called with the updated object on a 'signed' outcome to fire the GL consequence.
	 *
	 * @var callable|null
	 */
	public $consequence = null;

	/**
	 * Finance schema slug used as the Activity object type; empty skips the emit.
	 *
	 * @var string
	 */
	public string $subjectSchema = '';

	/**
	 * Constructor.
	 *
	 * @param string $outcome           'signed' | 'declined' | 'expired'.
	 * @param string $signingRequestRef The docudesk signingRequest id.
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly string $signingRequestRef,
	) {

	}//end __construct()

	/**
	 * The finance object with this conclusion's signing fields written onto it.
	 *
	 * @param array<string,mixed> $financeObject The finance object to update.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function writeOnto(array $financeObject): array {
		$financeObject['signingRequestRef'] = $this->signingRequestRef;
		$financeObject['signingStatus']     = $this->outcome;

		if ($this->signingProvider !== null) {
			$financeObject['signingProvider'] = $this->signingProvider;
		}

		if ($this->signingLevel !== null) {
			$financeObject['signingLevel'] = $this->signingLevel;
		}

		if ($this->signedDocumentRef !== null) {
			$financeObject['signedDocumentRef'] = $this->signedDocumentRef;
		}

		return $financeObject;

	}//end writeOnto()
}//end class
