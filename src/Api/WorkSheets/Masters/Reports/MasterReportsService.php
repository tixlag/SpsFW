<?php

namespace SpsFW\Api\WorkSheets\Masters\Reports;

use Sps\Employees\GetEmployeeByCode1C;
use SpsFW\Api\WorkSheets\Masters\Reports\Dto\GetAllMasterReportsByDayDto;
use SpsFW\Core\Exceptions\CantDoItAgain;
use SpsFW\Api\WorkSheets\Masters\Characteristics\Dto\GetAllMasterCharacteristicsRequestDTO;
use SpsFW\Api\WorkSheets\Masters\Pto\Dto\PtoLikeDislikeMasterDto;
use SpsFW\Core\Interfaces\RestStorageInterface;
use SpsFW\Core\Validation\Dtos\StartEndDateRequestDTO;

class MasterReportsService
{
    public function __construct(private RestStorageInterface $masterStorage)
    {
    }

    public function getReportsByMasterAndDay(GetAllMasterReportsByDayDto $dto): array
    {
        $master = new GetEmployeeByCode1C($dto->master_code_1c)->getEmployee();

        return $this->masterStorage->getReportsByMasterAndDay($master, $dto);
//        return $this->masterStorage->getAllReportsByDay($dto);
    }

    public function getOneMasterCharacteristics($master_id, StartEndDateRequestDTO $dto): array
    {
        return $this->masterStorage->getOneMasterCharacteristics($master_id, $dto);
    }


}