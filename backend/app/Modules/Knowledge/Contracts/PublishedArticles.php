<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Contracts;

/**
 * What other modules may learn about knowledge articles: titles of PUBLISHED ones only (Desk links them in replies).
 * Drafts never leak through this port.
 */
interface PublishedArticles
{
    /**
     * @param  list<int>  $ids
     * @return array<int, string> id → title, published articles only
     */
    public function titles(array $ids): array;
}
