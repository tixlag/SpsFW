<?php

namespace SpsFW\Api\WorkSheets\Masters\Reports;

use OpenApi\Attributes as OA;
use SpsFW\Api\WorkSheets\Masters\Reports\Dto\GetAllMasterReportsByDayDto;
use SpsFW\Core\Http\HttpMethod;
use SpsFW\Core\Http\Request;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Route\Controller;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Route\Route;
use SpsFW\Core\Validation\Enums\ParamsIn;


#[Controller]
class MasterReportsController extends RestController
{

    /**
     * В констуркторе можно передать другую имплементацию
     * @var MasterReportsService
     */
    private MasterReportsService $masterReportsService;

    public function __construct()
    {
        $this->masterReportsService = new MasterReportsService(new MasterReportsStorage());

        parent::__construct();
    }

    #[OA\Get(
        path: '/api/worksheets/master/report/all-day',
        summary: 'Получить общую сводку по мастеру за день',
        tags: ['Дашборд диспетчеров'],
        parameters: [
            new OA\Parameter(
                name: 'date',
                description: 'Дата для получения отчетов',
                in: 'query',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    format: 'date',
                    example: '2025-06-05'
                )
            ),
            new OA\Parameter(
                name: 'master_code_1c',
                description: 'Код мастера в 1С',
                in: 'query',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    example: 'УП00024589'
                )
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Ответ пришел',
                content: new OA\JsonContent(
                    example: '[ { "employee_name": "Авдась Николай Федорович", "jobTitle": "Мастер СМР", "class": "2", "img_src": null, "like_from_pto": [ { "pto_code_1c": "УП00040092", "pto_name": "Скородумов Николай Валерьевич", "score": 1, "comment": "Комментарий к оценке", "date": "2025-06-05" } ], "objects": [ { "object_id": 107, "object_name": "Инфраструктура Святогор", "comments": [ { "comment": "" } ], "images": [ { "images": null, "final_images": null } ], "code_tasks": [ { "code_task_id": 13, "tasks": [ { "task_id": 7620, "report_id": 45919, "task_name": "Кладка плитки", "position_name": "8.1.4 Общежитие ИТР", "code_name": "Отделка", "volume": "0.0000", "employee_count": 0, "link_id": 544, "facts": { "plan": "960.0000", "fact": "318.0000", "fact_in_day": "0.0000", "master_fact": "210.5000" } }, { "task_id": 16039, "report_id": 45919, "task_name": "Монтаж панелей СМЛ", "position_name": "8.1.4 Общежитие ИТР", "code_name": "Отделка", "volume": "0.0000", "employee_count": 0, "link_id": 544, "facts": { "plan": "3976.6500", "fact": "2766.2000", "fact_in_day": "0.0000", "master_fact": "1429.2000" } } ] } ] } ] }]'
                )
            )
        ]
    )]
    #[Route(path: '/api/worksheets/master/report/all-day', httpMethods: [HttpMethod::GET])]
    public function getAllReportsByDay(): Response
    {
        $dto = $this->validator->validateLegacy(ParamsIn::Query, new GetAllMasterReportsByDayDto());

        $result = $this->masterReportsService->getReportsByMasterAndDay($dto);


        return Response::json($result);
    }
}

