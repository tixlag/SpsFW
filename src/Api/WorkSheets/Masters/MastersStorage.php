<?php

namespace SpsFW\Api\WorkSheets\Masters;

use DateTime;
use Sps\DateTimeHelper;
use Sps\Db;
use SpsFW\Api\WorkSheets\Masters\Characteristics\Dto\GetAllMasterCharacteristicsRequestDTO;
use SpsFW\Api\WorkSheets\Masters\Pto\Dto\PtoLikeDislikeMasterDto;
use SpsFW\Core\Db\DbHelper;
use SpsFW\Core\Exceptions\ValidationException;
use SpsFW\Core\Interfaces\RestStorageInterface;
use SpsFW\Core\Validation\Dtos\StartEndDateRequestDTO;

class MastersStorage implements RestStorageInterface
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = DB::get();
    }

    public function getAllMasterCharacteristics(GetAllMasterCharacteristicsRequestDTO $dto): array
    {
//        $dates = DateTimeHelper::createDaysArray($dto->date_start, $dto->date_end);

        list($params, $where_clause) = $this->generateFilters('masterCharacteristic', $dto);

        $stmt = $this->pdo->prepare(
        /** @lang MariaDB */ "
SELECT
    employees.employee_id,
    user_reports.link_id,
    user_reports.include_report_date as report_day,

    -- считаем метрику, все ли работники отчитались. 1 - все, 0,5 - не все, 0 - ни одного
    CASE
        WHEN (COUNT(DISTINCT user_reports.user_code1c) - COUNT(DISTINCT wuc.user_report_id)) = 0 THEN 1
        WHEN COUNT(DISTINCT wuc.user_report_id) = 0 THEN 0
        ELSE 0.5
        END AS exists_reports_of_worker_in_change_by_link_metric,

    -- оценки на комментарии (отчеты работников)
    COUNT(DISTINCT CASE WHEN wuc.grade = 1 THEN wuc.user_report_id END) AS count_of_good_comments,
    COUNT(DISTINCT CASE WHEN wuc.grade = 2 THEN wuc.user_report_id END) AS count_of_bad_comments,

    -- Количество выговоров
    COUNT(DISTINCT rebukes.user_report_id) as rebukes_count,

    -- Открыли смену задним числом - 0, своевременно - 1
    IF(main_report.date_create <  DATE_ADD(main_report.date_start, INTERVAL 1 day)  , 1, 0) AS timely_report_generation,

    -- Получаем, нет ли ошибок при указании факта выполненной задачи.
    -- Если задачи нет или не проверили -> NULL
    IF(tasks_in_report.pto_flag IS NULL OR tasks_in_report.pto_flag = 0, NULL,
        -- Если проверили и количество отличается -> 0
        -- Все совпало -> 1
       IF(tasks_in_report.pto_flag = 1 AND tasks_in_report.pto_volume = tasks_in_report.master_volume , 1, 0))
        AS fact_without_error,

    -- Пропустили Своевременная постановка задач

    -- Получаем, корректность табелирования.
    -- Если не проверили -> NULL
    IF(user_reports.dispatcher_confirmed_datetime_utc IS NULL, NULL,
        -- Если проверили и количество отличается -> 0
        -- Все совпало -> 1
       IF(user_reports.dispatcher_confirmed_datetime_utc IS NOT NULL AND user_reports.dispatcher_hours = user_reports.hours_count , 1, 0))
        AS tabulation_correction

-- Пропустили Оценка правильности заполнения отчета мастером за смену

FROM worksheets__reports_operation AS report_operation
         LEFT JOIN worksheets__reports_by_task main_report on main_report.id = report_operation.report_id
         LEFT JOIN worksheets__tasks_in_report tasks_in_report
              ON main_report.id = tasks_in_report.report_id
          JOIN worksheets__tasks task
              ON task.id = tasks_in_report.task_id AND task.code_task_id != 0


         LEFT JOIN worksheets__users_reports user_reports on main_report.id = user_reports.report_id
         LEFT JOIN worksheets__users_rebukes rebukes on rebukes.user_report_id = user_reports.id
         LEFT JOIN worksheets__user_comments wuc on wuc.user_report_id = user_reports.id
         LEFT JOIN employees on employees.code_1c = report_operation.employee_code1c

    -- Для фильтра
LEFT JOIN worksheets__ciphers_for_tasks on task.chipher = worksheets__ciphers_for_tasks.name



WHERE report_operation.report_operation_type = 2 $where_clause

  AND
    user_reports.include_report_date between :date_start and :date_end
GROUP BY
    employees.employee_id, user_reports.include_report_date;
        "
        );

        $result['masters'] = [];
//        foreach ($dates as $date) {
        $stmt->execute(
            array_merge([":date_start" => $dto->date_start, ":date_end" => $dto->date_end], $params)
        );

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $employeeId = $row["employee_id"];
            $day = $row["report_day"];

            $result['masters'][$employeeId] ??= [
                "employee_id" => $employeeId,
                "link_id" => $row["link_id"],
                "report_days" => []
            ];

            $result['masters'][$employeeId]['report_days'][$day] ??= [
                "day" => $day,
                "metrics" => []
            ];

            $result['masters'][$employeeId]['report_days'][$day]['metrics'] = [
                "exists_reports_metric" => $row["exists_reports_of_worker_in_change_by_link_metric"],
                "count_of_good_comments" => $row["count_of_good_comments"],
                "count_of_bad_comments" => $row["count_of_bad_comments"],
                "rebukes_count" => $row["rebukes_count"],
                "timely_report_generation" => $row["timely_report_generation"],
                "timely_set_task" => null,
                "fact_without_error" => $row["fact_without_error"],
                "tabulation_correction" => $row["tabulation_correction"],
                "score_master_report" => null,

            ];
        }
//        }

        $result['masters'] = array_values($result['masters']);

        foreach ($result['masters'] as &$master) {
            if (isset($master['report_days']) and is_array($master['report_days'])) {
                $master['report_days'] = array_values($master['report_days']);
            }
        }

        return $result;
    }


    public function getOneMasterCharacteristics(int $master_id, StartEndDateRequestDTO $dto): array
    {
//        $dates = DateTimeHelper::createDaysArray($dto->date_start, $dto->date_end);

//        list($params, $where_clause) = $this->generateFilters('masterCharacteristic', $dto);

        $stmt = $this->pdo->prepare(
        /** @lang MariaDB */ "
SELECT
    employees.employee_id,
    user_reports.link_id,
    user_reports.include_report_date as report_day,

    -- считаем метрику, все ли работники отчитались. 1 - все, 0,5 - не все, 0 - ни одного
    CASE
        WHEN (COUNT(DISTINCT user_reports.user_code1c) - COUNT(DISTINCT wuc.user_report_id)) = 0 THEN 1
        WHEN COUNT(DISTINCT wuc.user_report_id) = 0 THEN 0
        ELSE 0.5
        END AS exists_reports_of_worker_in_change_by_link_metric,

    -- оценки на комментарии (отчеты работников)
    COUNT(DISTINCT CASE WHEN wuc.grade = 1 THEN wuc.user_report_id END) AS count_of_good_comments,
    COUNT(DISTINCT CASE WHEN wuc.grade = 2 THEN wuc.user_report_id END) AS count_of_bad_comments,

    -- Количество выговоров
    COUNT(DISTINCT rebukes.user_report_id) as rebukes_count,

    -- Открыли смену задним числом - 0, своевременно - 1
    IF(main_report.date_create <  DATE_ADD(main_report.date_start, INTERVAL 1 day)  , 1, 0) AS timely_report_generation,

    -- Получаем, нет ли ошибок при указании факта выполненной задачи.
    -- Если задачи нет или не проверили -> NULL
    IF(tasks_in_report.pto_flag IS NULL OR tasks_in_report.pto_flag = 0, NULL,
        -- Если проверили и количество отличается -> 0
        -- Все совпало -> 1
       IF(tasks_in_report.pto_flag = 1 AND tasks_in_report.pto_volume = tasks_in_report.master_volume , 1, 0))
        AS fact_without_error,

    -- Пропустили Своевременная постановка задач

    -- Получаем, корректность табелирования.
    -- Если не проверили -> NULL
    IF(user_reports.dispatcher_confirmed_datetime_utc IS NULL, NULL,
        -- Если проверили и количество отличается -> 0
        -- Все совпало -> 1
       IF(user_reports.dispatcher_confirmed_datetime_utc IS NOT NULL AND user_reports.dispatcher_hours = user_reports.hours_count , 1, 0))
        AS tabulation_correction

-- Пропустили Оценка правильности заполнения отчета мастером за смену

FROM worksheets__reports_operation AS report_operation
         LEFT JOIN worksheets__reports_by_task main_report on main_report.id = report_operation.report_id
         LEFT JOIN worksheets__tasks_in_report tasks_in_report
              ON main_report.id = tasks_in_report.report_id
          JOIN worksheets__tasks task
              ON task.id = tasks_in_report.task_id 


         LEFT JOIN worksheets__users_reports user_reports on main_report.id = user_reports.report_id
         LEFT JOIN worksheets__users_rebukes rebukes on rebukes.user_report_id = user_reports.id
         LEFT JOIN worksheets__user_comments wuc on wuc.user_report_id = user_reports.id
         LEFT JOIN employees on employees.code_1c = report_operation.employee_code1c

WHERE report_operation.report_operation_type = 2 AND employees.employee_id = :master_id

  AND
    user_reports.include_report_date between :date_start and :date_end
GROUP BY
    employees.employee_id, user_reports.include_report_date;
        "
        );

        $result['masters'] = [];
//        foreach ($dates as $date) {
        $stmt->execute(
            [
                ":date_start" => $dto->date_start,
                ":date_end" => $dto->date_end,
                ":master_id" => $master_id,
            ]
        );

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $employeeId = $row["employee_id"];
            $day = $row["report_day"];

            $result['masters'][$employeeId] ??= [
                "employee_id" => $employeeId,
                "link_id" => $row["link_id"],
                "report_days" => []
            ];

            $result['masters'][$employeeId]['report_days'][$day] ??= [
                "day" => $day,
                "metrics" => []
            ];

            $result['masters'][$employeeId]['report_days'][$day]['metrics'] = [
                "exists_reports_metric" => $row["exists_reports_of_worker_in_change_by_link_metric"],
                "count_of_good_comments" => $row["count_of_good_comments"],
                "count_of_bad_comments" => $row["count_of_bad_comments"],
                "rebukes_count" => $row["rebukes_count"],
                "timely_report_generation" => $row["timely_report_generation"],
                "timely_set_task" => null,
                "fact_without_error" => $row["fact_without_error"],
                "tabulation_correction" => $row["tabulation_correction"],
                "score_master_report" => null,

            ];
        }
//        }

        $result['masters'] = array_values($result['masters']);

        foreach ($result['masters'] as &$master) {
            if (isset($master['report_days']) and is_array($master['report_days'])) {
                $master['report_days'] = array_values($master['report_days']);
            }
        }

        return $result;
    }


    /**
     * Обрабатывает лайк/дизлайк мастеру от PTO за день
     * @param $pto_code_1c
     * @param $master_code_1c
     * @param $score
     * @param $date
     * @return void
     */
    public function likeFromPtoForDay($pto_code_1c, $master_code_1c, $score, $date): void
    {
        $exist_like = $this->existLike($master_code_1c, $date);
        if ($exist_like) {
            // если с установки оценки прошло больше часа или другой птошник, то запрещаем изменять
            $diff_between_now_and_created = new DateTime()->diff(
                DateTimeHelper::try_create($exist_like['date_created'])
            );
            if ($exist_like['pto_code_1c'] !== $pto_code_1c || $diff_between_now_and_created->d * 1440 + $diff_between_now_and_created->h * 60 + $diff_between_now_and_created->i > 60) {
                throw new ValidationException("Оценку может изменить только $exist_like[name] в течении одного часа");
            }


            $stmt = $this->pdo->prepare(
            /** @lang MariaDB */
                "UPDATE worksheets__like_dislike_master_from_pto t
                            SET score = :score
                            WHERE t.master_code_1c = :master_code_1c
                            AND t.date = :date"
            );

            $stmt->execute([
                ':date' => $date,
                ':master_code_1c' => $master_code_1c,
                ':score' => $score
            ]);
        } else {
            $stmt = $this->pdo->prepare(
            /** @lang MariaDB */ "
                    INSERT INTO worksheets__like_dislike_master_from_pto 
                        (master_code_1c, pto_code_1c, date, score)
                    VALUES
                        (:master_code_1c, :pto_code_1c, :date, :score)
"
            );

            $stmt->execute([
                ':date' => $date,
                ':pto_code_1c' => $pto_code_1c,
                ':master_code_1c' => $master_code_1c,
                ':score' => $score
            ]);
        }

        // Отправляем уведомление, если поставлен дизлайк
        if ($score == 0) {
            $stmt = $this->pdo->prepare(
            /** @lang MariaDB */ "
            SELECT
                e.profile_id as id_master,
                p.profile_id as id_pto

            FROM employees e
            JOIN employees p on p.code_1c = :pto_code_1c
            WHERE e.code_1c = :master_code_1c
            "
            );
            $stmt->execute([
                ":master_code_1c" => $master_code_1c,
                ":pto_code_1c" => $pto_code_1c,
            ]);
            $user_ids = $stmt->fetch(\PDO::FETCH_ASSOC);

            $stmt = $this->pdo->prepare(
            /** @lang MariaDB */ "
            INSERT INTO notification
            (msg, users_id, msg_read, from_to_id, from_to_id1c, users_id1c, important)
            VALUES (:msg, :id_to, :msg_read, :from_to_id, :from_to_id1c, :users_id1c, :important)
            "
            );
            $stmt->execute([
                ':msg' => "Вам поставлен дизлайк за смену на $date",
                ':id_to' => $user_ids['id_master'],
                ':msg_read' => 0,
                ':from_to_id' => $user_ids['id_pto'],
                ':from_to_id1c' => '',
                ':users_id1c' => '',
                ':important' => 0
            ]);
        }

    }


    public function existLike($master_code_1c, $date): mixed
    {
        $stmtCheck = $this->pdo->prepare(
        /** @lang MariaDB */
            "SELECT 
                    pto.name,
                    pto.code_1c as pto_code_1c,
                    score.date_created
                 FROM worksheets__like_dislike_master_from_pto score
                 JOIN employees pto on pto.code_1c = score.pto_code_1c
                 WHERE score.master_code_1c = :master_code_1c
                 AND score.date = :date
                 "
        );

        $stmtCheck->execute([
            ':master_code_1c' => $master_code_1c,
            ':date' => $date
        ]);

        return $stmtCheck->fetch(\PDO::FETCH_ASSOC);
    }


    /**
     * @param GetAllMasterCharacteristicsRequestDTO $dto
     * @return array
     */
    public
    function generateFilters(
        string $type_of_filter,
        GetAllMasterCharacteristicsRequestDTO $dto
    ): array {
        if ($type_of_filter === 'masterCharacteristic') {
            $where_array = [];
            $params = [];

            if (!empty($dto->object_ids) && !empty($dto->object_ids[0])) {
                $where_array[] = sprintf(
                    "tasks_in_report.object_id IN (%s)",
                    implode(
                        ",",
                        array_map(function ($item) {
                            return $this->pdo->quote((string)$item);
                        },
                            $dto->object_ids)
                    )
                );
            }

            // Фильтр по имени сотрудника
            if (!empty($dto->employee_name)) {
                $where_array[] = "employees.name LIKE :employee_name";
                $params[':employee_name'] = '%' . $dto->employee_name . '%';
            }

            // Фильтр по названию задачи
            if (!empty($dto->code_task_name)) {
                $where_array[] = "worksheets__ciphers_for_tasks.name LIKE :code_task_name";
                $params[':code_task_name'] = '%' . $dto->code_task_name . '%';
            }

            if (!empty($dto->code_task_id)) {
                $where_array[] = "worksheets__ciphers_for_tasks.id = :code_task_id";
                $params[':code_task_id'] = $dto->code_task_id;
            }

            // Формируем строку WHERE для дальнейших запросов
            $where_clause = '';
            if (!empty($where_array)) {
                $where_clause = 'AND ' . implode(' AND ', $where_array);
            }
            return array($params, $where_clause);
        }
        return [];
    }


    /**
     * @param $date
     * @param $master_code_1c
     * @param $score
     * @return mixed
     */
    public function updateLikeDislike($date, $master_code_1c, $score)
    {
        $stmt = $this->pdo->prepare(
        /** @lang MariaDB */
            "UPDATE worksheets__like_dislike_master_from_pto t
                            SET score = :score
                            WHERE t.master_code_1c = :master_code_1c
                            AND t.date = :date"
        );

        $stmt->execute([
            ':date' => $date,
            ':master_code_1c' => $master_code_1c,
            ':score' => $score
        ]);
        return $stmt;
    }

    /**
     * @param $date
     * @param $pto_code_1c
     * @param $master_code_1c
     * @param $score
     * @return mixed
     */
    public function createLikeDislike($date, $pto_code_1c, $master_code_1c, $score)
    {
        $stmt = $this->pdo->prepare(
        /** @lang MariaDB */ "
                    INSERT INTO worksheets__like_dislike_master_from_pto 
                        (master_code_1c, pto_code_1c, date, score)
                    VALUES
                        (:master_code_1c, :pto_code_1c, :date, :score)
"
        );

        $stmt->execute([
            ':date' => $date,
            ':pto_code_1c' => $pto_code_1c,
            ':master_code_1c' => $master_code_1c,
            ':score' => $score
        ]);
        return $stmt;
    }

    /**
     * @param $master_code_1c
     * @param $pto_code_1c
     * @param $date
     * @return void
     */
    public function notifyMasterAboutDislike($master_code_1c, $pto_code_1c, $date): void
    {
        $stmt = $this->pdo->prepare(
        /** @lang MariaDB */ "
            SELECT
                e.profile_id as id_master,
                p.profile_id as id_pto

            FROM employees e
            JOIN employees p on p.code_1c = :pto_code_1c
            WHERE e.code_1c = :master_code_1c
            "
        );
        $stmt->execute([
            ":master_code_1c" => $master_code_1c,
            ":pto_code_1c" => $pto_code_1c,
        ]);
        $user_ids = $stmt->fetch(\PDO::FETCH_ASSOC);

        $stmt = $this->pdo->prepare(
        /** @lang MariaDB */ "
            INSERT INTO notification
            (msg, users_id, msg_read, from_to_id, from_to_id1c, users_id1c, important)
            VALUES (:msg, :id_to, :msg_read, :from_to_id, :from_to_id1c, :users_id1c, :important)
            "
        );
        $stmt->execute([
            ':msg' => "Вам поставлен дизлайк за смену на $date",
            ':id_to' => $user_ids['id_master'],
            ':msg_read' => 0,
            ':from_to_id' => $user_ids['id_pto'],
            ':from_to_id1c' => '',
            ':users_id1c' => '',
            ':important' => 0
        ]);
    }

    /**
     * @param $comment
     * @param $master_code_1c
     * @param $date
     * @return void
     */
    public function updateLikeDislikeComment($comment, $master_code_1c, $date): void
    {
        $stmt = $this->pdo->prepare(
        /** @lang MariaDB */
            "UPDATE worksheets__like_dislike_master_from_pto t
                            SET comment = :comment
                            WHERE t.master_code_1c = :master_code_1c
                            AND t.date = :date"
        );

        $stmt->execute([
            ':comment' => $comment,
            ':master_code_1c' => $master_code_1c,
            ':date' => $date
        ]);
    }
}