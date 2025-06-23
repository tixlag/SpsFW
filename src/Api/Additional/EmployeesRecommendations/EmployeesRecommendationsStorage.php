<?php

namespace SpsFW\Api\Additional\EmployeesRecommendations;

use DateTime;
use PDO;
use Sps\Db;
use SpsFW\Api\Additional\EmployeesRecommendations\Dto\AddEmployeesRecommendationsDto;
use SpsFW\Api\Additional\EmployeesRecommendations\Dto\ResponseEmployeesRecommendationsDto;
use SpsFW\Core\Exceptions\ValidationException;
use SpsFW\Core\Interfaces\RestStorageInterface;
use SpsFW\Core\Storage\RestStorage;

class EmployeesRecommendationsStorage extends RestStorage
{
    /**
     * @return ResponseEmployeesRecommendationsDto[]
     */
    public function getAllRecommendationsOrForOne(?string $recommender_code_1c = null): array
    {
        $where_clause = '';
        if (!empty($recommender_code_1c)) {
            $where_clause .= ' AND recommender_code_1c = :recommender_code_1c';
        }
        $stmt = $this->pdo->prepare(
        /** @lang MariaDB */
            "
       SELECT
            employees__recommendations.day_of_vote as day_of_vote,
            employee.name as employee_name,
            employee.code_1c as employee_code_1c,
            employee.class as employee_class,
            profile.history_unit as employee_history_unit,
            profile.history_tc as employee_history,
            
            employees__recommendations.leadership_score as leadership_score,
            employees__recommendations.skill_score as skill_score,
            employees__recommendations.comment as comment,
            
            recommender.name as recommender_name,
            recommender.code_1c as recommender_code_1c,
            recommender_profile.history_unit as recommender_history_unit,
            recommender_profile.history_tc as recommender_history
        FROM employees__recommendations
        LEFT JOIN employees employee on employee.code_1c = employees__recommendations.employee_code_1c
        LEFT JOIN profile on profile.id = employee.profile_id
        LEFT JOIN employees recommender on recommender.code_1c = employees__recommendations.recommender_code_1c
        LEFT JOIN profile recommender_profile on recommender_profile.id = recommender.profile_id
        
        WHERE 1=1 $where_clause
        
        order by employees__recommendations.employee_code_1c, day_of_vote
        "
        );
        if ($recommender_code_1c) {
            $stmt->bindValue(':recommender_code_1c', $recommender_code_1c);
        }
        $stmt->execute();

//      return $stmt->fetchAll(PDO::FETCH_CLASS,DataEmployeesRecommendationsDto::class);
//      return $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $stmt->fetchAll(PDO::FETCH_FUNC, function () {
            $args = func_get_args();
            $data = [
                'day_of_vote' => $args[0],
                'employee_name' => $args[1],
                'employee_code_1c' => $args[2],
                'employee_class' => $args[3],
                'employee_history_unit' => $args[4],
                'employee_history' => $args[5],
                'leadership_score' => $args[6],
                'skill_score' => $args[7],
                'comment' => $args[8],
                'recommender_name' => $args[9],
                'recommender_code_1c' => $args[10],
                'recommender_history_unit' => $args[11],
                'recommender_history' => $args[12],
            ];
            return $this->createDtoFromDb($data);
        });
    }

    public function addRecommendation(AddEmployeesRecommendationsDto $dto): void
    {
        $mont_year_now = new DateTime();
        $mont_year_now = $mont_year_now->format('Y-m') . '-01';

        $stmt = $this->pdo->prepare(
        /**@lang MariaDB */ "
            SELECT
                employee_code_1c
            FROM
                employees__recommendations
            WHERE
                recommender_code_1c = :recommender_code_1c AND
                year_month_of_vote = :mont_year_now
            "
        );

        $stmt->execute([
            'recommender_code_1c' => $dto->recommender_code_1c,
            'mont_year_now' => $mont_year_now,
        ]);
        $prevRecommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (sizeof($prevRecommendations) >= 2) {
            throw new ValidationException('Голосовать можно два раза в месяц. Вы уже голосовали.');
        }

        if ($prevRecommendations[0]['employee_code_1c'] == $dto->employee_code_1c) {
            throw new ValidationException('Нельзя дважды проголосовать за одного и того же сотрудника');
        }


        $stmt = $this->pdo->prepare(
        /**@lang MariaDB */ "
            INSERT INTO employees__recommendations
            (employee_code_1c, recommender_code_1c, leadership_score, skill_score, comment) 
            VALUE 
            (:employee_code_1c, :recommender_code_1c, :leadership_score, :skill_score, :comment)
        "
        );
        $stmt->execute([
            ':employee_code_1c' => $dto->employee_code_1c,
            ':recommender_code_1c' => $dto->recommender_code_1c,
            ':leadership_score' => $dto->leadership_score,
            ':skill_score' => $dto->skill_score,
            ':comment' => $dto->comment
        ]);
    }

    /**
     * Если Storage большой, для таких операций нужно использовать класс Mapper
     * @return ResponseEmployeesRecommendationsDto
     */
    private function createDtoFromDb(array $data): ResponseEmployeesRecommendationsDto
    {
        // Парсим JSON-поля
        $employee_history = json_decode($data['employee_history'] ?? '', true) ?: [];
        $employee_history_unit = json_decode($data['employee_history_unit'] ?? '', true) ?: [];
        $recommender_history_unit = json_decode($data['recommender_history_unit'] ?? '', true) ?: [];
        $recommender_history = json_decode($data['recommender_history'] ?? '', true) ?: [];

        // Обработка данных
        $employee_office = $employee_history_unit
            ? ($employee_history_unit[0]['unit'] ?? 'Не найдено')
            : 'Не найдено';

        $employee_current_post = $employee_history
            ? ($employee_history[0]['jobtitle'] ?? 'Не найдено')
            : 'Не найдено';

        $employee_current_post_exp = $employee_history
            ? (int)($employee_history[0]['Amountofdays'] ?? 0)
            : 0;

        $employee_common_exp = array_reduce($employee_history, function ($carry, $item) {
            return $carry + (int)($item['Amountofdays'] ?? 0);
        }, 0);

        $recommender_office = $recommender_history_unit
            ? ($recommender_history_unit[0]['unit'] ?? 'Не найдено')
            : 'Не найдено';

        $recommender_current_post = $recommender_history
            ? ($recommender_history[0]['jobtitle'] ?? 'Не найдено')
            : 'Не найдено';

        return new ResponseEmployeesRecommendationsDto(
            day_of_vote: $data['day_of_vote'] ?? '',
            employee_name: $data['employee_name'] ?? '',
            employee_code_1c: $data['employee_code_1c'] ?? '',
            employee_office: $employee_office,
            employee_current_post: $employee_current_post,
            employee_current_post_exp: $employee_current_post_exp,
            employee_common_exp: $employee_common_exp,
            employee_class: $data['employee_class'] ?? '',
            recommender_name: $data['recommender_name'] ?? '',
            recommender_code_1c: $data['recommender_code_1c'] ?? '',
            recommender_office: $recommender_office,
            recommender_current_post: $recommender_current_post,
            leadership_score: $data['leadership_score'] ?? '',
            skill_score: $data['skill_score'] ?? '',
            comment: $data['comment'] ?? ''
        );
    }


}