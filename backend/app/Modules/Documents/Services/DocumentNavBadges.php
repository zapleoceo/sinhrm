<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Models\User;
use App\Modules\Core\Contracts\NavBadgeProvider;
use App\Modules\People\Services\PeopleScope;

/** "Мої документи" (/me/documents): documents sent to me and not yet signed or rejected. */
final readonly class DocumentNavBadges implements NavBadgeProvider
{
    public function __construct(private DocumentService $documents, private PeopleScope $scope) {}

    public function badges(User $user): array
    {
        return ['my_documents' => $this->documents->countAwaitingMe($this->scope->for($user))];
    }
}
