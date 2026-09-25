<?php

declare(strict_types=1);

namespace App\Modules\Directory\Models;

use App\Modules\Directory\Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

final class Department extends DictionaryItem
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    protected $table = 'departments';

    protected static function newFactory(): DepartmentFactory
    {
        return DepartmentFactory::new();
    }
}
