<?php

namespace SpsFW\Api\WorkSheets\Masters;

use OpenApi\Attributes as OA;
use Sps\Auth;
use Sps\UserAccess\AccessRulesEnum;
use SpsFW\Api\WorkSheets\Masters\Characteristics\Dto\GetAllMasterCharacteristicsRequestDTO;
use SpsFW\Api\WorkSheets\Masters\Pto\Dto\JsonPtoLikeMasterForDayDto;
use SpsFW\Api\WorkSheets\Masters\Pto\Dto\PtoLikeDislikeMasterCommentDto;
use SpsFW\Api\WorkSheets\Masters\Pto\Dto\PtoLikeDislikeMasterDto;
use SpsFW\Core\AccessRules\Attributes\AccessRulesAny;
use SpsFW\Core\Http\HttpMethod;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Route\Controller;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Route\Route;
use SpsFW\Core\Validation\Attributes\Validate;
use SpsFW\Core\Validation\Dtos\StartEndDateRequestDTO;
use SpsFW\Core\Validation\Enums\ParamsIn;

#[Controller]
class MastersController extends RestController
{
    /**
     * В констуркторе можно передать другую имплементацию
     * @var MastersService
     */
    private MastersService $mastersService;

    public function __construct()
    {
        $this->mastersService = new MastersService();

        parent::__construct();
    }


    #[OA\Get(
        path: '/api/worksheets/master/characteristics',
        description: 'Фильтрация работает! Слишком медленно выполняется запрос к бд',
        summary: 'Получить характеристики мастеров',
        tags: ['Дашборд диспетчеров'],
        parameters: [
            new OA\Parameter(
                name: 'date_start',
                description: 'Дата начальная',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'date'),
                example: '2025-05-01'
            ),
            new OA\Parameter(
                name: 'date_end',
                description: 'Дата конечная',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'date'),
                example: '2025-05-31',
            ),
            new OA\Parameter(
                name: 'object_ids',
                description: 'Id объектов (массив вида 1,2,3)',
                in: 'query',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'employee_name',
                description: 'Подстрока имени мастера',
                in: 'query',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'code_task_name',
                description: 'Код для задачи (?)',
                in: 'query',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'code_task_id',
                description: 'Id кода задачи (?)',
                in: 'query',
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Успешно',
                content: new OA\JsonContent(
                    example: '
                    {
    "masters": [
        {
            "employee_id": 727,
            "link_id": 216,
            "report_days": [
                {
                    "day": "2025-05-01",
                    "metrics": {
                        "exists_reports_metric": "0.0",
                        "count_of_good_comments": 0,
                        "count_of_bad_comments": 0,
                        "rebukes_count": 0,
                        "timely_report_generation": 1,
                        "timely_set_task": null,
                        "fact_without_error": null,
                        "tabulation_correction": 1,
                        "score_master_report": null
                    }
                }
            ]
        }
    ]
}
                    '
                )
            )
        ]
    )]
    #[Route(path: '/api/worksheets/master/characteristics', httpMethods: [HttpMethod::GET])]
    public function getAllMasterCharacteristic(): Response
    {
        $dto = $this->validator->validateLegacy(ParamsIn::Query, new GetAllMasterCharacteristicsRequestDTO());

        $result = $this->mastersService->getMasterCharacteristics($dto);

        return Response::json($result);
    }

    #[OA\Get(
        path: '/api/worksheets/master/characteristics/{master_id}',
        summary: 'Получить характеристики мастера по id',
        tags: ['Дашборд диспетчеров'],
        parameters: [
            new OA\Parameter(
                name: 'date_start',
                description: 'Дата начальная',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'date'),
                example: '2025-05-01'
            ),
            new OA\Parameter(
                name: 'date_end',
                description: 'Дата конечная',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'date'),
                example: '2025-05-31',
            ),

            new OA\Parameter(
                name: 'master_id',
                description: 'Id мастера',
                in: 'path',
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Успешно',
                content: new OA\JsonContent(
                    example: '
                    {
    "masters": [
        {
            "employee_id": 727,
            "link_id": 216,
            "report_days": [
                {
                    "day": "2025-05-01",
                    "metrics": {
                        "exists_reports_metric": "0.0",
                        "count_of_good_comments": 0,
                        "count_of_bad_comments": 0,
                        "rebukes_count": 0,
                        "timely_report_generation": 1,
                        "timely_set_task": null,
                        "fact_without_error": null,
                        "tabulation_correction": 1,
                        "score_master_report": null
                    }
                }
            ]
        }
    ]
}
                    '
                )
            )
        ]
    )]
    #[Route(path: '/api/worksheets/master/characteristics/{master_id}', httpMethods: [HttpMethod::GET])]
    public function getOneMasterCharacteristic($master_id): Response
    {
        $dto = $this->validator->validateLegacy(ParamsIn::Query, new StartEndDateRequestDTO());

        $result = $this->mastersService->getOneMasterCharacteristics($master_id, $dto);

        return Response::json($result);
    }


    #[OA\Post(
        path: '/api/worksheets/master/like-from-pto-for-day',
        summary: 'Поставить оценку мастеру за день работ по коду задачи и объекту',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                ref: PtoLikeDislikeMasterDto::class,
            )
        ),
        tags: ['Дашборд диспетчеров'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Оценка успешно поставлена',
            )
        ]
    )]
    #[AccessRulesAny([AccessRulesEnum::DigitalLinkUnit_ProductionAndTechnicalDepartment_Like_Dislike_Ability])]
    #[Validate(ParamsIn::Json, PtoLikeDislikeMasterDto::class)]
    #[Route(path: '/api/worksheets/master/like-from-pto-for-day', httpMethods: [HttpMethod::POST])]
    public function likeFromPtoForDay(PtoLikeDislikeMasterDto $dto): Response
    {
        $this->mastersService->likeFromPtoForDay($dto->master_code_1c, $dto->score, $dto->date);

        return Response::created();
    }

    #[OA\Post(
        path: '/api/worksheets/master/like-from-pto-for-day/comment',
        description: 'Метод сработает только для того же самого ПТОшника, который поставил оценку',
        summary: 'Отправить комментарий к лайку/дизлайку',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                ref: PtoLikeDislikeMasterDto::class,
            )
        ),
        tags: ['Дашборд диспетчеров'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Комментарий успешно установлен',
            )
        ]
    )]
    #[AccessRulesAny([AccessRulesEnum::DigitalLinkUnit_ProductionAndTechnicalDepartment_Like_Dislike_Ability])]
    #[Validate(ParamsIn::Json, PtoLikeDislikeMasterCommentDto::class)]
    #[Route(path: '/api/worksheets/master/like-from-pto-for-day/comment', httpMethods: [HttpMethod::POST])]
    public function commentToLikeFromPtoForDay(PtoLikeDislikeMasterCommentDto $dto): Response
    {
        $this->mastersService->commentToLikeFromPtoForDay($dto->master_code_1c, $dto->comment, $dto->date);
        return Response::created();
    }
}