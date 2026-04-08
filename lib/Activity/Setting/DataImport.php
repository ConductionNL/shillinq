<?php

/**
 * Shillinq DataImport Activity Setting
 *
 * Exposes stream and email toggles for DataJob activity events.
 *
 * @category Activity
 * @package  OCA\Shillinq\Activity\Setting
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/core/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\Shillinq\Activity\Setting;

use OCP\Activity\ISetting;
use OCP\IL10N;

/**
 * Activity setting for DataJob import events.
 *
 * @spec openspec/changes/core/tasks.md#task-9
 */
class DataImport implements ISetting
{
    /**
     * Constructor for DataImport setting.
     *
     * @param IL10N $l The localization service
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function __construct(
        private IL10N $l,
    ) {
    }//end __construct()

    /**
     * Get the identifier for this setting.
     *
     * @return string
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function getIdentifier(): string
    {
        return 'shillinq_datajob';
    }//end getIdentifier()

    /**
     * Get the display name for this setting.
     *
     * @return string
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function getName(): string
    {
        return $this->l->t('Shillinq data imports');
    }//end getName()

    /**
     * Get the priority of this setting.
     *
     * @return int
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function getPriority(): int
    {
        return 50;
    }//end getPriority()

    /**
     * Whether this setting can change the stream.
     *
     * @return bool
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function canChangeStream(): bool
    {
        return true;
    }//end canChangeStream()

    /**
     * Whether the stream is enabled by default.
     *
     * @return bool
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function isDefaultEnabledStream(): bool
    {
        return true;
    }//end isDefaultEnabledStream()

    /**
     * Whether this setting can change the email notification.
     *
     * @return bool
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function canChangeMail(): bool
    {
        return true;
    }//end canChangeMail()

    /**
     * Whether email notification is enabled by default.
     *
     * @return bool
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function isDefaultEnabledMail(): bool
    {
        return false;
    }//end isDefaultEnabledMail()
}//end class
