<?php

namespace SpsFW\Api\WorkSheets\Masters\Reports;

use DateTime;
use Sps\Db;
use SpsFW\Api\WorkSheets\Masters\Reports\Dto\GetAllMasterReportsByDayDto;
use SpsFW\Core\Interfaces\RestStorageInterface;

class MasterReportsStorage implements RestStorageInterface
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = DB::get();
    }


    /**
     * Этот формирует по всем мастерам
     * @param GetAllMasterReportsByDayDto $dto
     * @return array
     * @throws \DateMalformedStringException
     */
    public function getAllReportsByDay(GetAllMasterReportsByDayDto $dto): array
    {
        //Получение фактов на задачи
        $stmt = $this->pdo->prepare(
        /**@lang MariaDB */ "
                    SELECT 
                        wt.code_task_id,
                        SUM(wt.volume + wt.min_count) AS plan
                  -- возможно лишнее      fact_tbl.fact_volume AS fact
                    FROM worksheets__tasks wt
                    JOIN worksheets__ciphers_for_tasks wct
                        ON wct.id = wt.code_task_id
                    JOIN (
                        SELECT
                            wt2.code_task_id, 
                            SUM(IF(r.pto_flag, r.pto_volume, r.master_volume)) AS fact_volume,
                            r.include_report_date
                        FROM worksheets__tasks_in_report r
                        JOIN worksheets__tasks wt2 ON r.task_id = wt2.id
                        WHERE wt2.archive = 0 AND wt2.code_task_id != 0
                        GROUP BY wt2.code_task_id
                    ) AS fact_tbl ON fact_tbl.code_task_id = wt.code_task_id
                    WHERE wt.archive = 0 
                   -- возможно лишнее    and fact_tbl.include_report_date = :date
                    GROUP BY wt.code_task_id, fact_tbl.fact_volume
        "
        );
        $stmt->execute(/** возможно лишнее ['date' => $dto->date] */);

        $facts = array();
        while ($row = $stmt->fetchObject()) {
            $facts[$row->code_task_id] = [
                'plan' => $row->plan,
                'fact' => $row->fact
            ];
        }

        //Получение факта по мастеру
        $stmt = $this->pdo->prepare(
        /**@lang MariaDB */ "
            SELECT
                ro.employee_code1c,
                wt.code_task_id AS code_task_id,
                worksheets__ciphers_for_tasks.name,
                SUM(
                        IF(wtr.pto_flag, wtr.pto_volume, wtr.master_volume)
                ) AS volume
            FROM worksheets__reports_operation ro
                     JOIN worksheets__tasks_in_report wtr
                          ON wtr.report_id = ro.report_id
                     JOIN worksheets__tasks wt
                          ON wt.id = wtr.task_id
                     join worksheets__ciphers_for_tasks on worksheets__ciphers_for_tasks.id = wt.code_task_id
            -- возможно лишнее WHERE include_report_date = :date
            group by ro.employee_code1c, wt.code_task_id
        "
        );
        $stmt->execute(/** возможно лишнее ['date' => $dto->date] */);

        $master_facts = array();

        while ($row = $stmt->fetchObject()) {
            if (!isset($master_facts[$row->employee_code1c])) {
                $master_facts[$row->employee_code1c] = [
                    'facts' => [
                        $row->code_task_id => $row->volume
                    ]
                ];
            } elseif (!isset($master_facts[$row->employee_code1c]['facts'][$row->code_task_id])) {
                $master_facts[$row->employee_code1c]['facts'][$row->code_task_id] = $row->volume;
            }
        }

        $stmt = $this->pdo->prepare(
        /**@lang MariaDB */ "
           select
                employees.employee_id,
                employees.code_1c,
                employees.name as employee_name,
                worksheets__tasks_in_report.link_id,
                worksheets__tasks_in_report.report_id,
                worksheets__tasks_in_report.object_id,
                worksheets__objects.name as object_name,
                worksheets__tasks_in_report.include_report_date as report_day,
                like_pto.score as like_from_pto_score,
                like_pto.comment as like_from_pto_comment,
                like_pto.date as like_from_pto_date,
                like_pto_employee.code_1c as like_from_pto_code_1c,
                like_pto_employee.name as like_from_pto_name,
                worksheets__reports_operation.change_id,
                worksheets__tasks.code_task_id,
                worksheets__ciphers_for_tasks.name as code_task_name,
                count(DISTINCT worksheets__tasks_in_report.task_id) as tasks_count,
                sum(distinct worksheets__tasks.volume) as tasks_plan_sum,
                sum(distinct worksheets__task_monthly_plan.value) as month_plan_sum,
                sum(if(worksheets__tasks_in_report.pto_flag, worksheets__tasks_in_report.pto_volume, worksheets__tasks_in_report.master_volume)) as volume,
                (
                    SELECT COUNT(DISTINCT user_code_1c)
                    FROM worksheets__task_for_user
                    WHERE report_id = worksheets__tasks_in_report.report_id
                      AND task_id = worksheets__tasks_in_report.task_id
                ) as users_count,
                (
                    SELECT COUNT(DISTINCT worksheets__users_reports.user_code1c)
                    FROM worksheets__users_reports
                    WHERE worksheets__users_reports.report_id = worksheets__tasks_in_report.report_id
                ) as users_in_report_count,
                worksheets__measurement.name as measurement_name
            from worksheets__reports_operation
            
                     left join employees on employees.code_1c = worksheets__reports_operation.employee_code1c
                     left join worksheets__reports_by_task on worksheets__reports_by_task.id = worksheets__reports_operation.report_id
            
                     join worksheets__tasks_in_report
                          on worksheets__reports_by_task.id = worksheets__tasks_in_report.report_id
                              and worksheets__tasks_in_report.include_report_date = :date
                              and worksheets__tasks_in_report.is_active = 1
            
                     join worksheets__objects on worksheets__objects.id = worksheets__reports_operation.object_id
                     join worksheets__tasks on worksheets__tasks.id = worksheets__tasks_in_report.task_id and worksheets__tasks.archive = 0
                     join worksheets__measurement on worksheets__measurement.id = worksheets__tasks.measurement_id
                     left join worksheets__ciphers_for_tasks on worksheets__ciphers_for_tasks.id = worksheets__tasks.code_task_id
                     left join worksheets__task_monthly_plan on worksheets__task_monthly_plan.task_id = worksheets__tasks_in_report.task_id and worksheets__task_monthly_plan.month = :month and year = :year
                     LEFT JOIN worksheets__like_dislike_master_from_pto like_pto  on employees.code_1c = like_pto.master_code_1c  and like_pto.date = worksheets__tasks_in_report.include_report_date
                    JOIN employees like_pto_employee on like_pto_employee.code_1c = like_pto.pto_code_1c 
            where employees.code_1c = :master_code_1c
            group by employees.employee_id, employees.code_1c, employees.name,
                     worksheets__tasks_in_report.link_id, worksheets__tasks_in_report.report_id,
                     worksheets__tasks_in_report.object_id, worksheets__objects.name,
                     worksheets__tasks_in_report.include_report_date, worksheets__reports_operation.change_id,
                     worksheets__tasks.code_task_id, worksheets__ciphers_for_tasks.name,
                     worksheets__measurement.name
            order by employees.name, worksheets__tasks_in_report.include_report_date;   
        "
        );
        $date = new DateTime($dto->date);
        $stmt->execute([
            'master_code_1c' => $dto->master_code_1c,
            ':date' => $dto->date,
            ':month' => $date->format('m'),
            ':year' => $date->format('Y'),
        ]);

        /**
         * Структура
         * array (
         *     employee_id (int)
         *     employee_name (string)
         *     objects [
         *         object_id (int)
         *         employee_name (string)
         *     ]
         *     tasks [
         *         code_task_id (int)
         *         code_task_name (string)
         *         measurement_name (string)
         *         facts [
         *             tasks_plan_sum (float)
         *             month_plan_sum (float)
         *         ]
         *         reports [
         *             report_day (string)
         *             volume (float)
         *             users_count (int)
         *             users_in_report_count (int)
         *             tasks_count (int)
         *             change_id (int)
         *         ]
         *     ]
         * )
         */
        $return = array();


        while ($row = $stmt->fetchObject()) {
            if (!isset($return[$row->employee_id])) {
                $return[$row->employee_id] = [
                    'employee_id' => $row->employee_id,
                    'employee_name' => $row->employee_name,
                    'employee_code_1c' => $row->code_1c
                ];
                $return[$row->employee_id]['objects'][$row->object_id] = [
                    'object_id' => $row->object_id,
                    'object_name' => $row->object_name,
                ];
                $return[$row->employee_id]['tasks'][$row->code_task_id] = [
                    'code_task_id' => $row->code_task_id,
                    'code_task_name' => $row->code_task_name,
                    'measurement_name' => $row->measurement_name,
                ];
                $return[$row->employee_id]['tasks'][$row->code_task_id]['facts'] = [
                    'tasks_plan_sum' => $facts[$row->code_task_id]['plan'],
                    'fact_sum' => $facts[$row->code_task_id]['fact'],
                    'month_plan_sum' => $row->month_plan_sum,
                    'master_fact' => $master_facts[$row->code_1c]['facts'][$row->code_task_id]
                ];
                $return[$row->employee_id]['tasks'][$row->code_task_id]['reports'][$row->report_day] = [
                    'report_day' => $row->report_day,
                    'volume' => $row->volume,
                    'users_count' => $row->users_count,
                    'users_in_report_count' => $row->users_in_report_count,
                    'tasks_count' => $row->tasks_count,
                    'change_id' => $row->change_id,
                ];
                $return[$row->employee_id]['tasks'][$row->code_task_id]['reports'][$row->report_day]['like_from_pto'][$row->like_from_pto_code_1c] = [
                    'pto_code_1c' => $row->like_from_pto_code_1c,
                    'pto_name' => $row->like_from_pto_name,
                    'score' => $row->like_from_pto_score,
                    'comment' => $row->like_from_pto_comment,
                    'date' => $row->like_from_pto_date,
                ];
            } else {
                if (!isset($return[$row->employee_id]['objects'][$row->object_id])) {
                    $return[$row->employee_id]['objects'][$row->object_id] = [
                        'object_id' => $row->object_id,
                        'object_name' => $row->object_name,
                    ];
                }
                if (!isset($return[$row->employee_id]['tasks'][$row->code_task_id])) {
                    $return[$row->employee_id]['tasks'][$row->code_task_id] = [
                        'code_task_id' => $row->code_task_id,
                        'code_task_name' => $row->code_task_name,
                        'measurement_name' => $row->measurement_name,
                        'facts' => [
                            'tasks_plan_sum' => $facts[$row->code_task_id]['plan'],
                            'fact_sum' => $facts[$row->code_task_id]['fact'],
                            'month_plan_sum' => $row->month_plan_sum,
                            'master_fact' => $master_facts[$row->code_1c]['facts'][$row->code_task_id]
                        ]
                    ];
                }
                if (!isset($return[$row->employee_id]['tasks'][$row->code_task_id]['reports'][$row->report_day])) {
                    $return[$row->employee_id]['tasks'][$row->code_task_id]['reports'][$row->report_day] = [
                        'report_day' => $row->report_day,
                        'volume' => $row->volume,
                        'users_count' => $row->users_count,
                        'users_in_report_count' => $row->users_in_report_count,
                        'tasks_count' => $row->tasks_count,
                        'change_id' => $row->change_id,
                    ];
                }
                if (!isset($return[$row->employee_id]['tasks'][$row->code_task_id]['reports'][$row->report_day])) {
                    $return[$row->employee_id]['tasks'][$row->code_task_id]['reports'][$row->report_day]['like_from_pto'][$row->like_from_pto_code_1c] = [
                        'pto_code_1c' => $row->like_from_pto_code_1c,
                        'pto_name' => $row->like_from_pto_name,
                        'score' => $row->like_from_pto_score,
                        'comment' => $row->like_from_pto_comment,
                        'date' => $row->like_from_pto_date,
                    ];
                }
            }
        }

        $return = array_values($return);

        array_walk($return, function (&$item) {
            $item['objects'] = array_values($item['objects']);
            $item['tasks'] = array_values($item['tasks']);

            array_walk($item['tasks'], function (&$item) {
                $item['reports'] = array_values($item['reports']);
                array_walk($item['reports'], function (&$item) {
                    $item['like_from_pto'] = array_values($item['like_from_pto']);
                });
            });
        });

        return $return;
    }


    /**
     * Формирует отчеты по всем задачам по одному мастеру за день
     * @param $master
     * @param GetAllMasterReportsByDayDto $dto
     * @return array
     */
    public function getReportsByMasterAndDay($master, GetAllMasterReportsByDayDto $dto): array
    {
        //Заберем факты по задачам
        $stmt = $this->pdo->prepare(
        /**@lang MariaDB */ "
            SELECT
                wt.id,
                wt.name,
                wt.section_id,
                wt.date_create,
                wt.volume + wt.min_count AS plan,
                SUM(IF(wtr.pto_flag, wtr.pto_volume, wtr.master_volume)) AS fact
            FROM worksheets__tasks wt
                     LEFT JOIN worksheets__tasks_in_report wtr
                               ON wtr.task_id = wt.id
            GROUP BY
                wt.code_task_id,
                wt.id,
                wt.name,
                wt.section_id,
                wt.date_create,
                wt.volume,
                wt.min_count
        "
        );
        $stmt->execute();

        $tasks_facts = array();

        while ($row = $stmt->fetchObject()) {
            $tasks_facts[$row->id] = array(
                'plan' => $row->plan,
                'fact' => $row->fact,
                'master_fact' => null
            );
        }

        //Получение факта на мастера
        $stmt = $this->pdo->prepare(
        /**@lang MariaDB */ "
            SELECT
                wt.id AS task_id,
                SUM(IF(wtr.pto_flag, wtr.pto_volume, wtr.master_volume)) AS volume
            FROM worksheets__reports_operation ro
            JOIN worksheets__tasks_in_report wtr
                ON wtr.report_id = ro.report_id
            JOIN worksheets__tasks wt
                ON wt.id = wtr.task_id 
            WHERE ro.employee_code1c = :master_code_1c
            GROUP BY wt.code_task_id, wt.id
        "
        );
        $stmt->execute([
            ':master_code_1c' => $dto->master_code_1c
        ]);
        while ($row = $stmt->fetchObject()) {
            $tasks_facts[$row->task_id]['master_fact'] = $row->volume;
        }

        //Заберем информацию о задачах
        $stmt = $this->pdo->prepare(
        /**@lang MariaDB */ "
            SELECT 
                ro.employee_code1c,
                e.employee_id,
                e.name,
                ro.object_id,
                o.name AS object_name,
                rbt.link_id,
                ro.report_id,
                wur.include_report_date AS report_date,
                ro.change_id,
                ro.report_operation_type,
                tir.task_id,
                t.section_id,
                t.name AS task_name,
                s.name AS position_name,
                cft.id AS code_task_id,
                cft.name AS code_name,
                SUM(DISTINCT IF(tir.pto_flag, tir.pto_volume, tir.master_volume)) AS volume,
                MAX(rbt.comment) AS comment, 
                GROUP_CONCAT(DISTINCT uc.img_src) AS images,
                GROUP_CONCAT(wfri.img_src) as final_images,
                COUNT(DISTINCT tfu.user_code_1c) AS employee_count,
                like_pto.score as like_from_pto_score,
                like_pto.comment as like_from_pto_comment,
                like_pto.date as like_from_pto_date,
                like_pto_employee.code_1c as like_from_pto_code_1c,
                like_pto_employee.name as like_from_pto_name
            FROM worksheets__reports_operation ro
            JOIN employees e ON e.code_1c = ro.employee_code1c
            JOIN worksheets__users_reports wur ON wur.report_id = ro.report_id and wur.include_report_date = :date
                
            left JOIN worksheets__tasks_in_report tir ON tir.report_id = ro.report_id 
                AND tir.is_active = 1
            left JOIN worksheets__tasks t ON t.id = tir.task_id or t.id = wur.task_id
            LEFT JOIN worksheets__ciphers_for_tasks cft ON cft.id = t.code_task_id
            left JOIN worksheets__sections s ON s.id = t.section_id
                
                
                
            JOIN worksheets__reports_by_task rbt ON rbt.id = ro.report_id
            left JOIN worksheets__objects o ON o.id = ro.object_id
            LEFT JOIN worksheets__user_comments uc ON uc.report_id = ro.report_id
            LEFT JOIN worksheets__task_for_user tfu ON tfu.report_id = ro.report_id AND tfu.task_id = t.id
            LEFT JOIN worksheets__final_report_img wfri on wfri.report_id = ro.report_id
            LEFT JOIN worksheets__like_dislike_master_from_pto like_pto  on e.code_1c = like_pto.master_code_1c  and like_pto.date = wur.include_report_date
            LEFT JOIN employees like_pto_employee on like_pto_employee.code_1c = like_pto.pto_code_1c 
            
            WHERE e.code_1c = :master_code_1c
            GROUP BY t.code_task_id, ro.report_id, t.id, ro.employee_code1c, e.employee_id, e.name, ro.object_id, o.name, 
                     rbt.link_id, wur.include_report_date, ro.change_id, ro.report_operation_type,
                     tir.task_id, t.section_id, t.name, s.name, cft.id, cft.name
            ORDER BY t.code_task_id, t.id
        "
        );
        $stmt->execute([
            ':date' => $dto->date,
            ':master_code_1c' => $dto->master_code_1c,
        ]);

        $return = array();

        while ($row = $stmt->fetchObject()) {
            if (!isset($return[$row->employee_id])) {
                $return[$row->employee_id] = [
                    'employee_name' => $master->getName(),
                    'jobTitle' => $master->getPosition()->getName(),
                    'class' => $master->getClass(),
                    'img_src' => $master->getSourcePhotoPath()
                    && $master->getPhotoHelper()->getImage(null)
                        ? $master->getPhotoHelper()->getImage()->getUri(150, 150, true)
                        : null,
                ];
                $return[$row->employee_id]['like_from_pto'][$row->like_from_pto_code_1c] = [
                    'pto_code_1c' => $row->like_from_pto_code_1c,
                    'pto_name' => $row->like_from_pto_name,
                    'score' => $row->like_from_pto_score,
                    'comment' => $row->like_from_pto_comment,
                    'date' => $row->like_from_pto_date,
                ];
                $return[$row->employee_id]['objects'][$row->object_id] = [
                    'object_id' => $row->object_id,
                    'object_name' => $row->object_name,
                ];
                $return[$row->employee_id]['objects'][$row->object_id]['comments'][$row->report_id] = [
                    'comment' => $row->comment
                ];
                $return[$row->employee_id]['objects'][$row->object_id]['images'][$row->report_id] = [
                    'images' => $row->images,
                    'final_images' => $row->final_images
                ];

                $return[$row->employee_id]['objects'][$row->object_id]['code_tasks'][$row->code_task_id] = [
                    'code_task_id' => $row->code_task_id,
                ];

                $return[$row->employee_id]['objects'][$row->object_id]['code_tasks'][$row->code_task_id]['tasks'][$row->task_id] = [
                    'task_id' => $row->task_id,
                    'report_id' => $row->report_id,
                    'task_name' => $row->task_name,
                    'position_name' => $row->position_name,
                    'code_name' => $row->code_name,
                    'volume' => $row->volume,
                    'employee_count' => $row->employee_count,
                    'link_id' => $row->link_id,
                    'facts' => [
                        'plan' => $tasks_facts[$row->task_id]['plan'],
                        'fact' => $tasks_facts[$row->task_id]['fact'],
                        'fact_in_day' => $row->volume,
                        'master_fact' => $tasks_facts[$row->task_id]['master_fact']
                    ]
                ];
            } else {
                if (!isset($return[$row->employee_id]['like_from_pto'][$row->like_from_pto_code_1c])) {
                    $return[$row->employee_id]['like_from_pto'][$row->like_from_pto_code_1c] = [
                        'pto_code_1c' => $row->like_from_pto_code_1c,
                        'pto_name' => $row->like_from_pto_name,
                        'score' => $row->like_from_pto_score,
                        'comment' => $row->like_from_pto_comment,
                        'date' => $row->like_from_pto_date,
                    ];
                }
                if (!isset($return[$row->employee_id]['objects'][$row->object_id])) {
                    $return[$row->employee_id]['objects'][$row->object_id] = [
                        'object_id' => $row->object_id,
                        'object_name' => $row->object_name,
                    ];
                }

                if (!isset($return[$row->employee_id]['objects'][$row->object_id]['comments'][$row->report_id])) {
                    $return[$row->employee_id]['objects'][$row->object_id]['comments'][$row->report_id] = [
                        'comment' => $row->comment
                    ];
                }

                if (!isset($return[$row->employee_id]['objects'][$row->object_id]['images'][$row->report_id])) {
                    $return[$row->employee_id]['objects'][$row->object_id]['images'][$row->report_id] = [
                        'images' => $row->images,
                        'final_images' => $row->final_images
                    ];
                }

                if (!isset($return[$row->employee_id]['objects'][$row->object_id]['code_tasks'][$row->code_task_id])) {
                    $return[$row->employee_id]['objects'][$row->object_id]['code_tasks'][$row->code_task_id] = [
                        'code_task_id' => $row->code_task_id,
                    ];
                }

                if (!isset($return[$row->employee_id]['objects'][$row->object_id]['code_tasks'][$row->code_task_id]['tasks'][$row->task_id])) {
                    $return[$row->employee_id]['objects'][$row->object_id]['code_tasks'][$row->code_task_id]['tasks'][$row->task_id] = [
                        'task_id' => $row->task_id,
                        'report_id' => $row->report_id,
                        'task_name' => $row->task_name,
                        'position_name' => $row->position_name,
                        'code_name' => $row->code_name,
                        'volume' => $row->volume,
                        'employee_count' => $row->employee_count,
                        'link_id' => $row->link_id,
                        'facts' => [
                            'plan' => $tasks_facts[$row->task_id]['plan'],
                            'fact' => $tasks_facts[$row->task_id]['fact'],
                            'fact_in_day' => $row->volume,
                            'master_fact' => $tasks_facts[$row->task_id]['master_fact']
                        ]
                    ];
                }
            }
        }

        //Собираем собранный ответ в массив json
        $return = array_values($return);
        array_walk($return, function (&$item) {
            $item['like_from_pto'] = array_values($item['like_from_pto'])[0];
            $item['objects'] = array_values($item['objects']);

            array_walk($item['objects'], function (&$item) {
                $item['code_tasks'] = array_values($item['code_tasks']);
                array_walk($item['code_tasks'], function (&$item) {
                    $item['tasks'] = array_values($item['tasks']);
                });
                $item['comments'] = array_values($item['comments']);
                $item['images'] = array_values($item['images']);
            });
        });

        return $return;
    }


}