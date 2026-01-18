<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'EqiFacture SAT CFDI Proxy API',
    description: 'Stateless proxy API for downloading CFDIs from SAT portal. No data stored.',
    contact: new OA\Contact(
        name: 'API Support',
        email: 'support@eqifacture.com'
    )
)]
#[OA\Server(
    url: '/api/v1',
    description: 'API Server'
)]
abstract class Controller
{
    //
}
