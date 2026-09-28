<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Enums;

/** Who executes a tool call in the in-app chat (over MCP every call runs on the server). */
enum ToolRunner: string
{
    /** Executed right away by the turn endpoint; the result goes back with the turn (server_results). */
    case Server = 'server';
    /** Returned to the SPA (client_calls): it calls the API with the user's session, writes only after a click. */
    case Client = 'client';
}
