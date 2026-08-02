<?php

declare(strict_types=1);

namespace App\Enums;

enum AuthorityRemovalReviewStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
}
