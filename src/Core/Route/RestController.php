<?php

namespace SpsFW\Core\Route;

use SpsFW\Core\Http\Request;
use SpsFW\Core\Validation\Validator;

abstract class RestController
{
    protected  Request $request;
    protected Validator $validator;


    public function __construct()
    {
        $this->request = Request::getInstance();
        $this->validator = new Validator();
    }

}