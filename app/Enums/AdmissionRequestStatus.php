<?php

namespace App\Enums;

enum AdmissionRequestStatus: string
{
    case PENDING = 'pending';
    case CONTACTED = 'contacted';
    case ARCHIVED = 'archived';
}
