<?php

namespace SpsFW\Core\Validation\Enum;

enum ParamsIn: string
{
    case Query = 'getGet';
    case Post = 'getPost';
    case Json = 'getJsonData';
//    case FormData = 'getPost';
}