<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
}
