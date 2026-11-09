<?php

namespace Espo\Modules\StudioManagement\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\StudioManagement\Tools\RegistrationService;

final class PostRegister implements Action
{
    public function __construct(private RegistrationService $service) {}

    public function process(Request $request): Response
    {
        $data = $request->getParsedBody();

        foreach (['token', 'email', 'fullName', 'password'] as $field) {
            if (!isset($data->$field) || !is_string($data->$field)) {
                throw new BadRequest("Field '$field' is required.");
            }
        }

        $user = $this->service->register($data->token, $data->email, $data->fullName, $data->password);

        return ResponseComposer::json([
            'success' => true,
            'mode' => 'pending',
            'userId' => $user->getId(),
            'userName' => $user->getUserName(),
        ]);
    }
}
