<?php

declare(strict_types=1);

namespace App\Modules\Directory\Contracts;

use App\Models\User;
use App\Modules\Directory\DTO\ImportReport;
use App\Modules\Directory\Exceptions\DirectoryException;

/** Pulls the company dictionaries from an external system and upserts them (never deletes). */
interface DirectoryImporter
{
    /** @throws DirectoryException */
    public function import(User $actor): ImportReport;
}
