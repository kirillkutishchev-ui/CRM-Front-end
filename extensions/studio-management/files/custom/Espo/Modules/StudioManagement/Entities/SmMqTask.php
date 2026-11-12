<?php

namespace Espo\Modules\StudioManagement\Entities;

use Espo\Core\ORM\Entity;

final class SmMqTask extends Entity
{
    public const ENTITY_TYPE = 'SmMqTask';

    public const STATUS_QUEUED = 'Queued';
    public const STATUS_PUBLISHED = 'Published';
    public const STATUS_PROCESSING = 'Processing';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_FAILED = 'Failed';
}
