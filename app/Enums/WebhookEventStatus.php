<?php

namespace App\Enums;

enum WebhookEventStatus: string
{
    /** Stored but not applied yet, e.g. its generation could not be matched. Reprocessed on redelivery. */
    case PENDING = 'pending';
    case PROCESSED = 'processed';
    /** Valid but needs no action: a ping, an unknown type, or an event for an already final generation. */
    case IGNORED = 'ignored';
    /** Signed but unusable (malformed payload); retrying cannot fix it. */
    case FAILED = 'failed';
}
