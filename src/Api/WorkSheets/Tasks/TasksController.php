<?php

namespace SpsFW\Api\WorkSheets\Tasks;

use OpenApi\Attributes as OA;
use Sps\Worksheets\Task;
use Sps\Worksheets\TaskMethods\TaskStorage;
use Sps\Worksheets\WorkSheetException;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Route\Controller;
use SpsFW\Core\Route\Route;

#[Controller]
class TasksController
{
    /**
     * @throws WorkSheetException
     */


    #[OA\Delete(
        path: '/api/worksheets/task/{id}',
        description: 'Для удаления задача должна быть сперва добавлена в архив',
        summary: 'Удаляет задачу из отчета',
        tags: ['Задачи в отчетах'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Успешно удалено'
            ),
            new OA\Response(
                response: 400,
                description: 'По id не найдена задача'
            )
        ]
    )]
    #[Route(path: '/api/worksheets/task/{id}', httpMethods: ['DELETE'])]
    public function deleteTask(int $id): Response
    {
        $task_info = Task::get($id);
        if (!$task_info) {
            throw new WorkSheetException("Задача не найдена, обратитесь в тех. поддержку");
        }
        if (!$task_info->getArchive()) {
            throw new WorkSheetException("Прежде чем удалить, поместите задачу в архив");
        }
        $task_info->setDeleted(1);

        new TaskStorage($task_info)->delete();

        return Response::ok();
    }


    #[OA\Get(
        path: '/Api/Worksheets/Dispatcher/ShowTasksReport/',
        summary: 'Получить задачи в отчетах',
        tags: ['Задачи в отчетах'],
        parameters: [
            new OA\Parameter(
                name: 'object_id',
                description: 'Id объекта',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'start_date',
                description: 'Дата начальная',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'date')
            ),
            new OA\Parameter(
                name: 'end_date',
                description: 'Дата конечная',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'date')
            ),
            new OA\Parameter(
                name: 'position_name',
                description: 'Название позиции',
                in: 'query',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'task_name',
                description: 'Название задачи',
                in: 'query',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'task_status_confirm',
                description: 'Статус задачи подтвержден (?)',
                in: 'query',
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'task_status_close',
                description: 'Статус задачи закрыт (?)',
                in: 'query',
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'task_types',
                description: 'Типы задач (возможно массив вида 1,2,3)',
                in: 'query',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'code_task',
                description: 'Код для задачи (?)',
                in: 'query',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'min_count',
                description: 'Минимально доступное количество (?)',
                in: 'query',
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'chipher_filter',
                description: 'chipher_filter (?)',
                in: 'query',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'exclude_empty_tasks',
                description: 'Исключить пустые задачи',
                in: 'query',
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'week',
                description: 'Неделя',
                in: 'query',
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'active',
                description: '1 - убрать архивные задачи',
                in: 'query',
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'show_deleted',
                description: '1 - показать удаленные задачи',
                in: 'query',
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'show_only_archived',
                description: '1 - показать только удаленные задачи',
                in: 'query',
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'exist_common_plan',
                description: '1 - показать только задачи с общим планом',
                in: 'query',
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'exist_month_plan',
                description: '1 - показать только задачи с месячным планом',
                in: 'query',
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Вернул список задач',
                content: new OA\JsonContent(
                    example: '
                    {
    "result": {
        "success": true,
        "error": false,
        "error_messages": [],
        "errors": [],
        "errors_as_string": ""
    },
    "payload": {
        "tasks_reports": [
            {
                "position_id": 269,
                "position_name": "Здание главного корпуса",
                "object_id": 98,
                "cipher_id": "",
                "tasks": [
                    {
                        "id": 7570,
                        "name": "Бетонирование фундаментов (оси 41-45)",
                        "measurement_id": 6,
                        "measurement_info": {
                            "id": 6,
                            "name": "м3"
                        },
                        "section_id": 269,
                        "mark_id": 1,
                        "archive": 0,
                        "code_task_id": 0,
                        "min_count": 0,
                        "volume": "553.0000",
                        "cipher": "",
                        "code_1c": null,
                        "fact": {
                            "fact_volume": "0.0000"
                        },
                        "month_value": "",
                        "reports": [
                            {
                                "date": "2025-05-16",
                                "pto_volume": "0.0000",
                                "master_volume": "0.0000",
                                "pto_flag": 0,
                                "count": 1,
                                "links": "871"
                            }
                        ]
                    }
                ]
            }
        ]
    }
}'
                )
            ),
            new OA\Response(
                response: 400,
                description: 'По id не найдена задача'
            )
        ]
    )]
    public function mockController(int $id): Response
    {
        return new Response();
    }



}