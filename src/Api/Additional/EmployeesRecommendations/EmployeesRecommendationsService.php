<?php

namespace SpsFW\Api\Additional\EmployeesRecommendations;

use DateTime;
use SpsFW\Api\Additional\EmployeesRecommendations\Dto\AddEmployeesRecommendationsDto;
use SpsFW\Core\Interfaces\RestStorageInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class EmployeesRecommendationsService
{
    public EmployeesRecommendationsStorage $storage;

    public function __construct()
    {
        $this->storage = new EmployeesRecommendationsStorage();
    }

    public function getExcel(): string
    {
        $data = $this->storage->getAllRecommendationsOrForOne();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $header = [
            'A' => 'Сотрудник',
            'B' => 'Оценка',
            'C' => 'Голосовавший',
        ];

        // Объединение и установка основных заголовков
        $sheet->mergeCells('A1:H1');
        $sheet->setCellValue('A1', $header['A']);
        $sheet->mergeCells('I1:K1');
        $sheet->setCellValue('I1', $header['B']);
        $sheet->mergeCells('L1:O1');
        $sheet->setCellValue('L1', $header['C']);

        $subHeaders = [
            'A' => 'Дата голосования',
            'B' => 'Имя сотрудника',
            'C' => 'Код 1C сотрудника',
            'D' => 'Участок',
            'E' => 'Должность',
            'F' => 'Стаж',
            'G' => 'Общий стаж',
            'H' => 'Класс',
            'I' => 'Оценка лидерства',
            'J' => 'Оценка навыков',
            'K' => 'Комментарий',
            'L' => 'Имя голосовавшего',
            'M' => 'Код 1C голосовавшего',
            'N' => 'Участок',
            'O' => 'Должность',
        ];

        $mappingForColumns = [];
        $columnDigit = 1;
        foreach ($subHeaders as $columnLetter => $subHeader) {
            $mappingForColumns[$columnDigit++] = $columnLetter;
        }

// Записываем заголовки
        foreach ($subHeaders as $col => $header) {
            $sheet->setCellValue($col . "2", $header);
        }

// Стили для заголовков
        $headerStyle = [
            'font' => ['bold' => true],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_THIN],
                'bottom' => ['borderStyle' => Border::BORDER_THIN]
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ];
        $sheet->getStyle("A1:O2")->applyFromArray($headerStyle);
        $sheet->setAutoFilter("A2:O2");



// Заполнение данными
        $row = 3;
        foreach ($data as $item) {

            $column = 1;
            $itemAssoc = get_object_vars($item);
            foreach ($itemAssoc as $col => $value) {
                $sheet->setCellValue($mappingForColumns[$column++] . $row, $value);
            }
            $row++;
        }

// Стили для данных
        $dataStyle = [
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN]
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_TOP
            ]
        ];
        $sheet->getStyle("A3:O" . ($row - 1))->applyFromArray($dataStyle);

// Автоширина столбцов
        foreach ($mappingForColumns as $digit => $letter) {
            $sheet->getColumnDimension($letter)->setAutoSize(true);
        }
        $sheet->getColumnDimension('K')->setAutoSize(false); // Увеличиваем ширину столбца с комментариями
        $sheet->getColumnDimension('K')->setWidth(50); // Увеличиваем ширину столбца с комментариями

// Выравнивание заголовков
        $sheet->getStyle("A1:O2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // цвет для заголовка и жирные разделители между сущностями
        $sheet->getStyle("A1:O1")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '77bc65']
            ],
        ]);
        $sheet->getStyle("A2:O2")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'afd095']
            ],
        ]);
        $sheet->getStyle("H1:H10000")->applyFromArray([
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_THIN],
                'right' => ['borderStyle' => Border::BORDER_MEDIUM]
            ],
        ]);
        $sheet->getStyle("K1:K10000")->applyFromArray([
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_THIN],
                'right' => ['borderStyle' => Border::BORDER_MEDIUM]
            ],
        ]);
        $writer = new Xlsx($spreadsheet);

        /** Возвращаем ссылку на файл
      * @code $uploadDir = __DIR__ . '/../../../../../../upload';
        $fileName = 'topMasterSurvey/' .
            new DateTime()->format('Y-m-d') .
            '_оценки_сотркудникам_от_сотрудников.xlsx'
        ;
        $filePath = $uploadDir . '/' . $fileName;

        if (!is_dir($uploadDir . '/topMasterSurvey')) {
            mkdir($uploadDir, 0755, true);
        }
        $writer->save($filePath);

        return "https://".$_SERVER['HTTP_HOST'] . "/uploads/" . $fileName;
         *

        /** Скачиваем файл без сохранения */
        // Устанавливаем заголовки для скачивания файла
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header(
            "Content-Disposition: attachment;filename*=UTF-8''" .
            rawurlencode(new DateTime()->format('Y-m-d') .
            '_оценки_сотркудникам_от_сотрудников.xlsx')
        );
        header('Cache-Control: max-age=0');

// Отправляем содержимое файла
        $writer->save('php://output');

        return 'ok';
    }

    public function addRecommendation(AddEmployeesRecommendationsDto $dto): void
    {
        $this->storage->addRecommendation($dto);

    }

    public function getRecommendationList(string $recommender_code_1c): array
    {
        return $this->storage->getAllRecommendationsOrForOne($recommender_code_1c);
    }

}