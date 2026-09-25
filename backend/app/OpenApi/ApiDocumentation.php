<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '0.1.0',
    title: 'Tessera POS API',
    description: 'Laravel API for the Tessera wines & spirits POS. All endpoints return the ApiResponse envelope {success, message, statusCode, data|errors}.',
)]
#[OA\Server(url: L5_SWAGGER_CONST_HOST, description: 'API host')]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'apiKey',
    name: 'X-XSRF-TOKEN',
    in: 'header',
    description: 'Sanctum SPA cookie session. Call GET /sanctum/csrf-cookie, then send the XSRF-TOKEN cookie value in this header.',
)]
final class ApiDocumentation {}
