<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Shillinq Activity Setting — DataImport
 *
 * User-level setting for data import activity notifications.
 *
 * @spec openspec/changes/core/tasks.md#task-9.2
 *
 * @category Activity
 * @package  OCA\Shillinq\Activity\Setting
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Activity\Setting;

use OCP\Activity\ISetting;
use OCP\IL10N;

/**
 * Activity setting for data import events.
 *
 * @spec openspec/changes/core/tasks.md#task-9.2
 */
class DataImport implements ISetting
{
    /**
     * Constructor.
     *
     * @param IL10N $l10n The localization service
     *
     * @return void
     */
    public function __construct(
        private IL10N $l10n,
    ) {
    }//end __construct()

    /**
     * Get the unique identifier.
     *
     * @return string
     */
    public function getIdentifier(): string
    {
        return 'shillinq_datajob';
    }//end getIdentifier()

    /**
     * Get the display name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->l10n->t('A data import job completed or failed');
    }//end getName()

    /**
     * Get the priority.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return 50;
    }//end getPriority()

    /**
     * Whether this setting can change the stream.
     *
     * @return bool
     */
    public function canChangeStream(): bool
    {
        return true;
    }//end canChangeStream()

    /**
     * Whether stream is enabled by default.
     *
     * @return bool
     */
    public function isDefaultEnabledStream(): bool
    {
        return true;
    }//end isDefaultEnabledStream()

    /**
     * Whether this setting can change email notifications.
     *
     * @return bool
     */
    public function canChangeMail(): bool
    {
        return true;
    }//end canChangeMail()

    /**
     * Whether email is enabled by default.
     *
     * @return bool
     */
    public function isDefaultEnabledMail(): bool
    {
        return false;
    }//end isDefaultEnabledMail()
}//end class
