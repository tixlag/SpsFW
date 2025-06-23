<?php

namespace SpsFW\Api\Secrets;

use Sps\Db;
use SpsFW\Core\Http\HttpMethod;
use SpsFW\Core\Route\Controller;
use SpsFW\Core\Route\Route;

#[Controller]
class SecretController
{
    private $pdo;

    public function __construct()
    {
        $this->pdo = Db::get();
    }

    #[Route(path: '/api/v3/secrets/drop-dining-room', httpMethods: [HttpMethod::POST])]
    public function dropDiningRoom()
    {
        $this->pdo->exec('
        DELETE FROM d_dining_room_update
        WHERE date BETWEEN "2025-05-17" AND NOW();

        DELETE FROM dining_room
        WHERE date BETWEEN "2025-05-17" AND NOW();
        ');
        
    }
}