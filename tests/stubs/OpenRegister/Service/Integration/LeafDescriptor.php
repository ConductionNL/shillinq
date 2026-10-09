<?php

/**
 * Test stub for OpenRegister's LeafDescriptor.
 *
 * @category Stub
 * @package  OCA\OpenRegister\Service\Integration
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

namespace OCA\OpenRegister\Service\Integration;

final class LeafDescriptor {
	public const KIND_RENDER_SURFACE = 'render-surface';

	public const KIND_DATA_PROVIDER = 'data-provider';

	public const KIND_AGENT_RUNNER = 'agent-runner';

	public const RENDER_MODE_COMPONENT = 'component';

	/**
	 * The mount hand-off mode. Missing from this stub until 2026-09-19, which
	 * made a descriptor that correctly declares it read as an undefined
	 * constant. A stub that lags the real class reports a defect in code that
	 * has none.
	 *
	 * @var string
	 */
	public const RENDER_MODE_MOUNT = 'mount';

	/**
	 * Every surface a descriptor may target. Mirrored here because a leaf that
	 * declares a surface outside this list is filtered out of every grid and
	 * nothing says so.
	 *
	 * @var array<int, string>
	 */
	public const VALID_SURFACES = [
		'user-dashboard',
		'app-dashboard',
		'detail-page',
		'single-entity',
	];

	/**
	 * @param array<int, string> $kinds Kinds.
	 * @param array<int, string> $surfaces Surfaces.
	 */
	public function __construct(
		private string $id,
		private string $label,
		private string $icon,
		private array $kinds,
		private ?string $requiredApp = null,
		private ?string $group = null,
		private array $surfaces = [],
		private ?string $referenceType = null,
		private ?string $requiresPermission = null,
		private string $renderMode = self::RENDER_MODE_COMPONENT,
	) {
	}//end __construct()

	public function getId(): string {
		return $this->id;
	}//end getId()

	public function getLabel(): string {
		return $this->label;
	}//end getLabel()

	public function getIcon(): string {
		return $this->icon;
	}//end getIcon()

	/**
	 * @return array<int, string>
	 */
	public function getKinds(): array {
		return $this->kinds;
	}//end getKinds()

	public function getRequiredApp(): ?string {
		return $this->requiredApp;
	}//end getRequiredApp()

	public function getGroup(): ?string {
		return $this->group;
	}//end getGroup()

	/**
	 * @return array<int, string>
	 */
	public function getSurfaces(): array {
		return $this->surfaces;
	}//end getSurfaces()

	public function getReferenceType(): ?string {
		return $this->referenceType;
	}//end getReferenceType()

	public function requiresPermission(): ?string {
		return $this->requiresPermission;
	}//end requiresPermission()

	public function getRenderMode(): string {
		return $this->renderMode;
	}//end getRenderMode()
}//end class
