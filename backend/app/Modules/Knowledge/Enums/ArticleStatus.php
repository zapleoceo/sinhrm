<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Enums;

enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
