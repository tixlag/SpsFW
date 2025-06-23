<?php

namespace SpsFW\Api\WorkSheets\Masters;

use DateTime;
use Sps\Auth;
use Sps\DateTimeHelper;
use SpsFW\Api\WorkSheets\Masters\Characteristics\Dto\GetAllMasterCharacteristicsRequestDTO;
use SpsFW\Core\Exceptions\ValidationException;
use SpsFW\Core\Validation\Dtos\StartEndDateRequestDTO;

class MastersService
{
    private MastersStorage $masterStorage;

    public function __construct()
    {
        $this->masterStorage = new MastersStorage();
    }

    /**
     * @param GetAllMasterCharacteristicsRequestDTO $dto
     * @return array
     */
    public function getMasterCharacteristics(GetAllMasterCharacteristicsRequestDTO $dto): array
    {
        return $this->masterStorage->getAllMasterCharacteristics($dto);
    }

    /**
     * @param $master_id
     * @param StartEndDateRequestDTO $dto
     * @return array
     */
    public function getOneMasterCharacteristics($master_id, StartEndDateRequestDTO $dto): array
    {
        return $this->masterStorage->getOneMasterCharacteristics($master_id, $dto);
    }


    /**
     * Обрабатывает лайк/дизлайк мастеру от PTO за день
     * @param $master_code_1c
     * @param $score
     * @param $date
     * @return void
     */
    public function likeFromPtoForDay($master_code_1c, $score, $date): void
    {
        try {
            $pto_code_1c = Auth::get()->getCode1c();
        } catch (\Exception $e) {
            throw new ValidationException('Что-то не то с птошником');
        }
        $exist_like = $this->masterStorage->existLike($master_code_1c, $date);
        if (!$exist_like) {
            $this->masterStorage->createLikeDislike($date, $pto_code_1c, $master_code_1c, $score);
        } else {
            // если с установки оценки прошло больше часа или другой птошник, то запрещаем изменять
            if ($exist_like['pto_code_1c'] !== $pto_code_1c || DateTimeHelper::nowFromDb()->getTimestamp(
                ) - DateTimeHelper::try_create($exist_like['date_created'])->getTimestamp() > 3600) {
                throw new ValidationException("Оценку может изменить только $exist_like[name] в течении одного часа");
            }


            $this->masterStorage->updateLikeDislike($date, $master_code_1c, $score);
        }

        // Отправляем уведомление, если поставлен дизлайк
        if ($score == 0) {
            $this->masterStorage->notifyMasterAboutDislike($master_code_1c, $pto_code_1c, $date);
        }
    }

    /**
     * Обрабатывает комментарий к лайку/дизлайку от ПТО мастеру за день
     * @param $master_code_1c
     * @param $comment
     * @param $date
     * @return void
     */
    public function commentToLikeFromPtoForDay($master_code_1c, $comment, $date): void
    {
        try {
            $pto_code_1c = Auth::get()->getCode1c();
        } catch (\Exception $e) {
            throw new ValidationException('Что-то не то с птошником');
        }

        $exist_like = $this->masterStorage->existLike($master_code_1c, $date);

        if (!$exist_like) {
            throw new ValidationException("Перед добавлением комментария, необходимо поставить оценку");
        }
        if ($exist_like['pto_code_1c'] !== $pto_code_1c) {
            throw new ValidationException("Комментарий может добавить только $exist_like[name]");
        }

        // если с установки оценки прошло больше суток, то запрещаем изменить
        if (!empty($exist_like['comment']) &&
            DateTimeHelper::nowFromDb()->getTimestamp() - DateTimeHelper::try_create($exist_like['date_created'])->getTimestamp() > 3600 * 24) {
            throw new ValidationException("Комментарий можно изменить только в течении суток");
        }

        $this->masterStorage->updateLikeDislikeComment($comment, $master_code_1c, $date);
    }


}