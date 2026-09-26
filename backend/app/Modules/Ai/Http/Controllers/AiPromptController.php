<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Controllers;

use App\Models\User;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Http\Requests\PromptBodyRequest;
use App\Modules\Ai\Repositories\AiPromptVersionRepository;
use App\Modules\Ai\Services\AiPromptAdminService;
use App\Modules\Integrations\Definitions\AiBrokerDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/ai/prompts/{purpose}/* — superadmin prompt editor (routes.php). */
final readonly class AiPromptController
{
    public function __construct(private AiPromptAdminService $prompts, private AiPromptVersionRepository $versions) {}

    public function show(string $purpose): JsonResponse
    {
        return new JsonResponse(['data' => $this->prompts->show(self::purpose($purpose))]);
    }

    public function save(PromptBodyRequest $request, string $purpose): JsonResponse
    {
        $p = self::purpose($purpose);
        $this->prompts->save(self::actor($request), $p, $request->body());

        return new JsonResponse(['data' => $this->prompts->show($p)], 201);
    }

    public function activate(Request $request, string $purpose, int $version): JsonResponse
    {
        $p = self::purpose($purpose);
        $row = $this->versions->find($p, $version) ?? abort(404);
        $this->prompts->activate(self::actor($request), $p, $row);

        return new JsonResponse(['data' => $this->prompts->show($p)]);
    }

    public function builtin(Request $request, string $purpose): JsonResponse
    {
        $p = self::purpose($purpose);
        $this->prompts->restoreBuiltin(self::actor($request), $p);

        return new JsonResponse(['data' => $this->prompts->show($p)]);
    }

    public function capability(Request $request, string $purpose): JsonResponse
    {
        $p = self::purpose($purpose);
        $data = $request->validate(['capability' => ['required', 'string', Rule::in(AiBrokerDefinition::CAPABILITIES)]]);
        $this->prompts->setCapability(self::actor($request), $p, (string) $data['capability']);

        return new JsonResponse(['data' => $this->prompts->show($p)]);
    }

    public function trial(PromptBodyRequest $request, string $purpose): JsonResponse
    {
        return new JsonResponse(['data' => $this->prompts->trial(self::purpose($purpose), $request->body())]);
    }

    private static function purpose(string $value): AiPurpose
    {
        $purpose = AiPurpose::tryFrom($value);

        return $purpose !== null && in_array($purpose, AiPurpose::editable(), true) ? $purpose : abort(404);
    }

    private static function actor(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : abort(401);
    }
}
