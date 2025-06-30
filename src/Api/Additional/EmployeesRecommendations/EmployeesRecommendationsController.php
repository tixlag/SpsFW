<?php

namespace SpsFW\Api\Additional\EmployeesRecommendations;

use Exception;
use OpenApi\Attributes as OA;
use Sps\ApplicationError;
use Sps\Auth;
use Sps\Employees\GetEmployeeByCode1C;
use Sps\UserAccess\AccessRulesEnum;
use SpsFW\Api\Additional\EmployeesRecommendations\Dto\AddEmployeesRecommendationsDto;
use SpsFW\Core\AccessRules\Attributes\AccessRulesAny;
use SpsFW\Core\Exceptions\ValidationException;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Route\Controller;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Route\Route;
use SpsFW\Core\Validation\Attributes\Validate;
use SpsFW\Core\Validation\Enums\ParamsIn;

#[Controller]

#[OA\Tag(name: 'Сотрудники', description: 'Все endpoints для работы с сотрудниками')]
class EmployeesRecommendationsController extends RestController
{
    private EmployeesRecommendationsService $service;


    public function __construct()
    {
        $this->service = new EmployeesRecommendationsService();
        parent::__construct();
    }

    #[OA\Get(
        path: '/api/employees/recommendations/excel',
        description: 'Скачать таблицу',
        summary: 'По ссылке скачивается таблица с оценками сотрудников сотрудникам',
        tags: ['Сотрудники'],
        responses: [
            new OA\Response(
                response: 200,
                description: "Успешно скачивается"
            )
        ]
    )]
    #[AccessRulesAny(requiredRules: [AccessRulesEnum::Sections_Talents])]
    #[Route(path: '/api/employees/recommendations/excel')]
    public function getExcel(): Response
    {
        try {
            $res = $this->service->getExcel();

            if ($res === 'ok') {
                return Response::ok();
            } else {
                return Response::json($res);
            }
        } catch (Exception $e) {
            return Response::error($e);
        }
    }


    #[OA\Post(
        path: '/api/employees/recommendations',
        description: '1с код рекомендатора берется из сессии, поэтому не передаем.',
        summary: 'Создать рекомендацию сотруднику',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                ref: AddEmployeesRecommendationsDto::class
            )
        ),
        tags: ['Сотрудники'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Успешно добавлено'
            ),
            new OA\Response(
                ref: new OA\JsonContent(
                    example: '
{
    "error": {
        "status": 400,
        "user": "Скородумов Николай Валерьевич-УП00040092-12112",
        "exception": "ValidationException",
        "message": "Голосовать можно один раз в месяц. Вы уже голосовали.",
        "file": "/var/www/html/www/src/src/Api/Additional/EmployeesRecommendations/EmployeesRecommendationsStorage.php",
        "line": 100,
        "previous": null,
        "trace": []
    }
}
                    '
                ),
                response: 400,
                description: 'Подробней об ошибке смотри в ответе'
            )
        ],
    )]

    #[Validate(ParamsIn::Json, AddEmployeesRecommendationsDto::class)]
    #[Route('/api/employees/recommendations', ["POST"])]
    public function addRecommendation(AddEmployeesRecommendationsDto $dto): Response
    {

        try {
            $dto->setRecommenderCode1c(Auth::get()->getCode1c());
            if ($dto->employee_code_1c === $dto->recommender_code_1c)
                throw new ValidationException('Вы не можете голосовать за себя');
        } catch (ApplicationError $e) {
            throw new ValidationException('Вас еще не существует в 1с');
        }

        $employee = new GetEmployeeByCode1C($dto->employee_code_1c)->getEmployee();
        if ($employee === null) {
            throw new ValidationException("Не найден сотрудник с кодом 1с = $dto->employee_code_1c");
        }


        $this->service->addRecommendation($dto);

        return Response::created();
    }


    #[OA\Get(
        path: '/api/employees/{recommender_code_1c}/recommendations',
        description: '',
        summary: 'Возвращает список все рекомендаций пользователя',
        tags: ['Сотрудники'],
        parameters: [
            new OA\Parameter(
                name: "recommender_code_1c",
                in: "path",
                required: true,
                schema: new OA\Schema(
                    schema: "string"
                )
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                content: new OA\JsonContent(
                    example: '
                    [
  {
    "employee_name": "Ларионов Роман Сергеевич",
    "employee_code_1c": "УП00035198",
    "recommender_name": "Скородумов Николай Валерьевич",
    "recommender_code_1c": "УП00040092",
    "leadership_score": "5",
    "skill_score": "5",
    "comment": "Это пример комментария, который содержит более 30 символов и соответствует требованиям."
  }
]
                    '
                )
            )
        ]
    )]
    #[Route(path: '/api/employees/{recommender_code_1c}/recommendations', httpMethods: ["GET"])]
    public function getRecommendation($recommender_code_1c): Response
    {
        $recommendations_list = $this->service->getRecommendationList(urldecode($recommender_code_1c));

        return Response::json($recommendations_list);
    }
}