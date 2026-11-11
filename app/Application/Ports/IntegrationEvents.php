<?php

declare(strict_types=1);

namespace App\Application\Ports;

interface IntegrationEvents
{
    /** Must be called inside the transaction of the change it describes. */
    public function publish(IntegrationEvent $event): void;
}
