<?php

namespace SpsFW\Api\CorporateSlider\Model\Storage;

use PDO;
use PDOException;
use Ramsey\Uuid\Uuid;
use Sps\ApplicationError;
use SpsFW\Api\CorporateSlider\Exceptions\BadCountOfImagesException;
use SpsFW\Api\CorporateSlider\Model\CategoryCorporateSlider;
use SpsFW\Api\CorporateSlider\Model\ImageCorporateSlide;
use SpsFW\Api\CorporateSlider\Model\SlideCorporateSlider;
use Sps\DateTimeHelper;
use Sps\Db;
use Sps\FileHelpers\File;
use Sps\FrontController\CallableControllerException;
use Sps\IWriteDb;
use Sps\UploadedFile;
use SpsFW\Core\Exceptions\BaseException;

// todo дописать остальной круд в делит руками удалять зависимые сущности и файлы
class CategoryStorageCorporateSlider implements IWriteDb
{
    /**
     * Объект для работы с базой данных
     * @var PDO
     */
    private PDO $pdo;


    /**
     * @param PDO|null $pdo
     */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::get();
    }


    /**
     * @param string $id
     * @return CategoryCorporateSlider|null
     */
    public function getCategoryByUuid(string $id): ?CategoryCorporateSlider
    {
        $stmt = $this->pdo->prepare(
        /** @lang MariaDB */ "
                    SELECT 
                        BIN_TO_UUID(c.category_uuid) as category_uuid,
                        c.name as category_name,
                        
                        BIN_TO_UUID(s.slide_uuid) as slide_uuid,
                        s.sort as slide_sort,
                        
                        BIN_TO_UUID(i.image_uuid) as image_uuid,
                        i.path as image_path,
                        i.name as image_name,
                        i.sort as image_sort
                    FROM corporate_slider__categories c
                    LEFT JOIN corporate_slider__slides s 
                        ON s.category_uuid = c.category_uuid
                    LEFT JOIN corporate_slider__images i 
                        ON i.slide_uuid = s.slide_uuid
                    
                    WHERE c.category_uuid = UUID_TO_BIN(:id)
                    ORDER BY
                        s.sort, i.sort
                    "
        );

        $stmt->execute([':id' => $id]);
        $rows = $stmt->fetchAll(PDO::FETCH_OBJ);


        return $this->buildCategoriesWithSlides($rows)[0];
    }

    /**
     * @param string|null $id
     * @return array
     */
    public function getCategoriesWithSlides(?string $id = null): array
    {
        if ($id === null) {
            $where = "1";
        } else {
            $where = "c.category_uuid = UUID_TO_BIN(:id)";
        }
        $stmt = $this->pdo->prepare(
        /** @lang MariaDB */ "
                    SELECT 
                        -- Получаем категории
                        BIN_TO_UUID(c.category_uuid) AS category_uuid,
                        c.name AS category_name,
                
                        -- Поля слайда
                        BIN_TO_UUID(s.slide_uuid) AS slide_uuid,
                        s.sort AS slide_sort,
                
                        -- Поля изображения
                        BIN_TO_UUID(i.image_uuid) AS image_uuid,
                        BIN_TO_UUID(i.slide_uuid) AS image_slide_uuid,
                        BIN_TO_UUID(i.category_uuid) AS image_category_uuid,
                        i.path AS image_path,
                        i.name AS image_name,
                        i.sort AS image_sort
                    FROM corporate_slider__categories c
                    LEFT JOIN corporate_slider__slides s 
                        ON s.category_uuid = c.category_uuid
                    LEFT JOIN corporate_slider__images i 
                        ON i.slide_uuid = s.slide_uuid
                    WHERE $where
                    ORDER BY
                        s.sort, i.sort
                    "
        );

        if ($id !== null) {
            $stmt->execute([':id' => $id]);
        } else {
            $stmt->execute();
        }

        $rows = $stmt->fetchAll(PDO::FETCH_OBJ);

        return $this->buildCategoriesWithSlides($rows);
    }

    /**
     * @return CategoryCorporateSlider[]|null
     */
    public function getAllCategoriesLazy(): ?array
    {
        // todo стандартизировать пагинацию
        return $this->pdo->query(
            "SELECT *, BIN_TO_UUID(category_uuid) as category_uuid FROM corporate_slider__categories"
        )
            ->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, CategoryCorporateSlider::class);
    }


    /**
     * Метод не транзакционный!
     * @param string $uuid
     * @return array
     */
    public function deleteCategory(string $uuid): array
    {
        $imagePathsForDel = [];
        try {
            $stmt = $this->pdo->prepare(
            /** @lang MariaDB */ "
                                        SELECT
                                            category_uuid
                                        FROM 
                                            corporate_slider__categories
                                        WHERE 
                                            category_uuid = UUID_TO_BIN(:category_uuid)
"
            );
            $stmt->execute(['category_uuid' => $uuid]);
            $category_uuid = $stmt->fetchColumn();

            if ($category_uuid === false) {
                throw new BaseException("Категория с id=$uuid не найдена", 400);
            }

            $stmt = $this->pdo->prepare(
            /** @lang MariaDB */ "
                                        SELECT
                                            path
                                        FROM 
                                            corporate_slider__images
                                        WHERE 
                                            category_uuid = UUID_TO_BIN(:category_uuid)
"
            );
            $stmt->execute(['category_uuid' => $uuid]);
            $imagePaths = $stmt->fetchColumn();

            foreach ($imagePaths as $item) {
                $imagePathsForDel[] = $item;
            }

            $stmt = $this->pdo->prepare(
            /** @lang MariaDB */ "
            DELETE FROM corporate_slider__categories
            WHERE category_uuid = UUID_TO_BIN(:category_uuid)
        "
            );

            $stmt->execute(array(
                ":category_uuid" => $uuid,
            ));

            $stmt = $this->pdo->prepare(
            /** @lang MariaDB */ "
            DELETE FROM corporate_slider__slides
            WHERE category_uuid = UUID_TO_BIN(:category_uuid)
        "
            );

            $stmt->execute(array(
                ":category_uuid" => $uuid,
            ));

            $stmt = $this->pdo->prepare(
            /** @lang MariaDB */ "
            DELETE FROM corporate_slider__images
            WHERE category_uuid = UUID_TO_BIN(:category_uuid)
        "
            );

            $stmt->execute(array(
                ":category_uuid" => $uuid,
            ));

        } catch (PDOException $e) {
            throw new BaseException("Ошибка при удалении category_uuid={$uuid}", 500, $e);
        }

        return $imagePathsForDel;
    }

    public function deleteManyCategory(array $uuids): bool
    {
        try {
            $this->pdo->beginTransaction();

            foreach ($uuids as $uuid) {
                $imagePathsForDel = $this->deleteCategory($uuid);
            }
            $this->pdo->commit();

            if (!empty($imagePathsForDel)) {
                foreach ($imagePathsForDel as $item) {
                    if (file_exists($_SERVER["DOCUMENT_ROOT"] . $item)) {
                        unlink($_SERVER["DOCUMENT_ROOT"] . $item);
                    } else {
                        continue;
                    }
                }
            }
        } catch (BaseException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new BaseException("Ошибка при удалении категорий. {$e->getMessage()}", 500, $e);
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new BaseException("Ошибка при удалении категорий", 500, $e);
        }

        return true;
    }

    /**
     * Строит из строк в бд все категории со слайдами и фото
     * @param array $rows
     * @return CategoryCorporateSlider[]|null
     */
    public function buildCategoriesWithSlides(array $rows): array|null
    {
        $category_indexes = [];
        $slide_indexes = [];

        foreach ($rows as $row) {
            if (isset($category_indexes[(string)$row->category_uuid])) {
                $category = $category_indexes[(string)$row->category_uuid];
            } else {
                $category = new CategoryCorporateSlider();
                $category->setCategoryUuid($row->category_uuid);
                $category->setName($row->category_name);
                $category_indexes[(string)$row->category_uuid] = $category;
            }
            if (isset($row->slide_uuid)) {
                if (isset($slide_indexes[(string)$row->slide_uuid])) {
                    $slide = $slide_indexes[(string)$row->slide_uuid];
                } else {
                    $slide = new SlideCorporateSlider();
                    $slide
                        ->setSlideUuid($row->slide_uuid)
                        ->setSort($row->slide_sort)
                        ->setCategoryUuid($row->category_uuid);
                    $slide_indexes[(string)$row->slide_uuid] = $slide;
                    $category->addSlide($slide);
                }

                if (isset($row->image_uuid)) {
                    $slide->addImage(
                        new ImageCorporateSlide()
                            ->setImageUuid($row->image_uuid)
                            ->setSlideUuid($row->slide_uuid)
                            ->setSort($row->image_sort)
                            ->setCategoryUuid($row->category_uuid)
                            ->setPath($row->image_path)
                            ->setName($row->image_name)
                    );
                }
            }
        }

        return $category_indexes ? array_map(function ($category) {
            return [
                'category_uuid' => $category->getCategoryUuid(),
                'name' => $category->getName(),
                'slides' => $category->getSlides() ? array_map(function ($slide) {
                    return [
                        'slide_uuid' => $slide->getSlideUuid(),
                        'sort' => $slide->getSort(),
                        'images' => $slide->getImages() ? array_map(function ($image) {
                            return [
                                'image_uuid' => $image->getImageUuid(),
                                'path' => $image->getPath(),
                                'name' => $image->getName(),
                                'sort' => $image->getSort(),
                            ];
                        }, $slide->getImages()) : []
                    ];
                }, $category->getSlides()) : [],
            ];
        }, array_values($category_indexes)) : [];
    }


    /**
     * Удаляет слайд и все связанные с ним изображения
     *
     * @param string $categoryUuid UUID категории
     * @param string $slideUuid UUID слайда для удаления
     * @return bool Результат операции
     */
    private function removeSlide(string $categoryUuid, string $slideUuid): bool
    {
        // Сначала удаляем все изображения слайда
        $this->removeSlideImages($slideUuid);

        // Затем удаляем сам слайд
        $stmt = $this->pdo->prepare(
            "
            DELETE FROM corporate_slider__slides
            WHERE slide_uuid = :slide_uuid AND category_uuid = :category_uuid
        "
        );

        return $stmt->execute([
            ':slide_uuid' => $slideUuid,
            ':category_uuid' => $categoryUuid
        ]);
    }

    /**
     * Удаляет все изображения, связанные со слайдом
     *
     * @param string $slideUuid UUID слайда
     * @return bool Результат операции
     */
    private function removeSlideImages(string $slideUuid): void
    {
        // Получаем список изображений для физического удаления файлов
        $stmt = $this->pdo->prepare(
            "
            SELECT image_uuid, path
            FROM corporate_slider__images
            WHERE slide_uuid = :slide_uuid
        "
        );

        $stmt->execute([':slide_uuid' => $slideUuid]);
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Удаляем физические файлы
//        foreach ($images as $image) {
//            $this->imageStorage->deleteImageFile($image['path']);
//        }

        // Удаляем записи из БД
        $stmt = $this->pdo->prepare(
            "
            DELETE FROM corporate_slider__images
            WHERE slide_uuid = :slide_uuid
        "
        );

        $stmt->execute([':slide_uuid' => $slideUuid]);
    }


    /**
     * Создает новую категорию с её слайдами и изображениями
     *
     * @param string $categoryUuid UUID новой категории
     * @param string $categoryName Название категории
     * @param array $slides Данные слайдов и изображений
     */
    public function createCategory(string $categoryUuid, string $categoryName, array $slides): string
    {
        try {
            $this->pdo->beginTransaction();
            // Создаем запись о новой категории
            $stmt = $this->pdo->prepare(
                "
            INSERT INTO corporate_slider__categories (category_uuid, name)
            VALUES (UUID_TO_BIN(:category_uuid), :name)
        "
            );

            $stmt->execute([
                ':category_uuid' => $categoryUuid,
                ':name' => $categoryName
            ]);

            // Обрабатываем слайды и изображения
            $this->processSlides($categoryUuid, $slides);
            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            throw new BaseException('Не удалось создать категорию', 500, $e);
        }
        return $categoryUuid;
    }


    /**
     * Обновляет существующую категорию и её содержимое
     *
     * @param string $categoryUuid UUID категории
     * @param string $categoryName Название категории
     * @param ?array $slideUuidsToRemove UUID слайдов для удаления
     * @param ?array $slides Данные слайдов и изображений
     */
    public function updateCategory(
        string $categoryUuid,
        string $categoryName,
        ?array $slideUuidsToRemove,
        ?array $slides
    ): void {
        try {
            $stmt = $this->pdo->prepare(
            /** @lang MariaDB */ "
                                        SELECT
                                            category_uuid
                                        FROM 
                                            corporate_slider__categories
                                        WHERE 
                                            category_uuid = UUID_TO_BIN(:category_uuid)
"
            );
            $stmt->execute(['category_uuid' => $categoryUuid]);
            $category_uuid = $stmt->fetchColumn();

            if ($category_uuid === false) {
                throw new BaseException("Категория с id=$categoryUuid не найдена", 400);
            }

            $this->pdo->beginTransaction();
            // Обновляем имя категории
            $stmt = $this->pdo->prepare(
                "
            UPDATE corporate_slider__categories
            SET name = :name
            WHERE category_uuid = UUID_TO_BIN(:uuid)
        "
            );

            $stmt->execute([
                ':name' => $categoryName,
                ':uuid' => $categoryUuid
            ]);

            // Удаляем указанные слайды и их изображения
            if (!empty($slideUuidsToRemove)) {
                // Сначала удаляем связанные изображения


                $placeholders = implode(',', array_fill(0, count($slideUuidsToRemove), 'UUID_TO_BIN(?)'));


                $stmt = $this->pdo->prepare(
                /** @lang MariaDB */ "
                                        SELECT
                                            path
                                        FROM 
                                            corporate_slider__images
                                        WHERE 
                                            slide_uuid IN ($placeholders)
"
                );
                $stmt->execute($slideUuidsToRemove);
                $imagePaths = $stmt->fetchColumn();

                foreach ($imagePaths as $item) {
                    if (file_exists($_SERVER["DOCUMENT_ROOT"] . $item)) {
                        unlink($_SERVER["DOCUMENT_ROOT"] . $item);
                    } else {
                        continue;
                    }
                }
                $stmt = $this->pdo->prepare(
                    "
                DELETE FROM corporate_slider__images
                WHERE slide_uuid IN ($placeholders)
            "
                );

                $stmt->execute($slideUuidsToRemove);

                // Затем удаляем сами слайды
                $stmt = $this->pdo->prepare(
                    "
                DELETE FROM corporate_slider__slides
                WHERE slide_uuid IN ($placeholders)
            "
                );

                $stmt->execute($slideUuidsToRemove);
            }

            // Обрабатываем слайды и изображения
            if (!empty($slides)) {
                $this->processSlides($categoryUuid, $slides);
            }
            $this->pdo->commit();
        } catch (\PDOException|BadCountOfImagesException $e) {
            $this->pdo->rollBack();
            throw new BaseException('Не удалось обновить категорию', 500, $e);
        }
    }

    /**
     * @warning НЕ ТРАНЗАКЦИОННЫЙ
     * Обрабатывает данные слайдов и изображений
     *
     * @param string $categoryUuid UUID категории
     * @param ?array $slides Данные слайдов и изображений
     * @throws BadCountOfImagesException
     */
    private function processSlides(string $categoryUuid, ?array $slides): void
    {
        if (!empty($slides)) {
            foreach ($slides as $slideData) {
                $countOfSlides = sizeof($slideData['images']);
                if (!($countOfSlides == 0 || $countOfSlides == 1 || $countOfSlides == 4)) {
                    throw new BadCountOfImagesException();
                }

                $slideUuid = $slideData['slide_uuid'] ?? null;
                $slideSort = $slideData['sort'] ?? 0;

                if (empty($slideUuid)) {
                    $slideUuid = Uuid::uuid7()->toString();

                    $stmt = $this->pdo->prepare(
                        "
                        INSERT INTO corporate_slider__slides (slide_uuid, category_uuid, sort)
                        VALUES (UUID_TO_BIN(:slide_uuid), UUID_TO_BIN(:category_uuid), :sort)
                    "
                    );

                    $stmt->execute([
                        ':slide_uuid' => $slideUuid,
                        ':category_uuid' => $categoryUuid,
                        ':sort' => $slideSort
                    ]);
                } else {
                    // Обновляем порядок существующего слайда
                    $stmt = $this->pdo->prepare(
                        "
                        UPDATE corporate_slider__slides
                        SET sort = :sort
                        WHERE slide_uuid = UUID_TO_BIN(:slide_uuid) AND category_uuid = UUID_TO_BIN(:category_uuid)
                    "
                    );

                    $stmt->execute([
                        ':sort' => $slideSort,
                        ':slide_uuid' => $slideUuid,
                        ':category_uuid' => $categoryUuid
                    ]);
                }

                // Обрабатываем изображения для слайда
                if (!empty($slideData['images'])) {
                    foreach ($slideData['images'] as $imageData) {
                        $imageUuid = $imageData['image_uuid'] ?? null;
                        $imageSort = $imageData['sort'] ?? 0;

                        if (empty($imageUuid)) {
                            // Это новое изображение, проверяем наличие файла
                            if (isset($_FILES['slides'])) {
                                // Вытаскиваем правильные индексы слайдов и изображений
                                $slideIndex = array_search($slideData, $slides);
                                $imageIndex = array_search($imageData, $slideData['images']);

                                if (isset($_FILES['slides']['name'][$slideIndex]['images'][$imageIndex]['file'])) {
                                    $file = [
                                        'name' => $_FILES['slides']['name'][$slideIndex]['images'][$imageIndex]['file'],
                                        'full_path' => $_FILES['slides']['full_path'][$slideIndex]['images'][$imageIndex]['file'],
                                        'type' => $_FILES['slides']['type'][$slideIndex]['images'][$imageIndex]['file'],
                                        'tmp_name' => $_FILES['slides']['tmp_name'][$slideIndex]['images'][$imageIndex]['file'],
                                        'error' => $_FILES['slides']['error'][$slideIndex]['images'][$imageIndex]['file'],
                                        'size' => $_FILES['slides']['size'][$slideIndex]['images'][$imageIndex]['file']
                                    ];
                                    // Загружаем файл и сохраняем информацию о нем
                                    $imageUuid = Uuid::uuid7()->toString();
                                    try {
                                        $imagePath = $this->uploadImage($file, $imageUuid);
                                    } catch (ApplicationError $e) {
                                        throw new BaseException($e);
                                    }
                                    $imageName = pathinfo($file['name'], PATHINFO_FILENAME);

                                    $stmt = $this->pdo->prepare(
                                        "
                                        INSERT INTO corporate_slider__images (image_uuid, slide_uuid, category_uuid, path, name, sort)
                                        VALUES (UUID_TO_BIN(:image_uuid), UUID_TO_BIN(:slide_uuid), UUID_TO_BIN(:category_uuid), :path, :name, :sort)
                                    "
                                    );

                                    $stmt->execute([
                                        ':image_uuid' => $imageUuid,
                                        ':slide_uuid' => $slideUuid,
                                        ':category_uuid' => $categoryUuid,
                                        ':path' => $imagePath,
                                        ':name' => $imageName,
                                        ':sort' => $imageSort
                                    ]);
                                }
                            }
                        } else {
                            // Это существующее изображение, обновляем его порядок
                            $stmt = $this->pdo->prepare(
                                "
                                UPDATE corporate_slider__images
                                SET sort = :sort
                                WHERE image_uuid = UUID_TO_BIN(:image_uuid)
                            "
                            );

                            $stmt->execute([
                                ':sort' => $imageSort,
                                ':image_uuid' => $imageUuid
                            ]);
                        }
                    }
                }
            }
        }
    }

    /**
     * Загружает изображение в файловую систему
     *
     * @param array $file Информация о файле из $_FILES
     * @return string Путь к загруженному файлу
     * @throws ApplicationError
     */
    private function uploadImage(array $file, string $imageUuid): string
    {
        // Проверяем, что файл был загружен успешно
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new BaseException(
                sprintf("Ошибка загрузки файла %s", strlen($file['name'])) > 0 ? $file['name'] : "Файл не прикреплен"
            );
        }

        // Создаем уникальное имя файла
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);

        $uploadPath = sprintf(
            "%s/corporate_slider/%s/%s.%s",
            rtrim(SPS_UPLOAD_IMAGES_DIRECTORY, "/"),
            DateTimeHelper::now()->format("Y/m/d/H"),
            $imageUuid,
            $extension
        );

        $fileUploaded = new UploadedFile(
            $file['name'] ?? "",
            $file['type'] ?? "",
            $file['size'] ?? 0,
            $file['tmp_name'] ?? "",
            $file['error'] ?? UPLOAD_ERR_NO_FILE,
            $file['full_path'] ?? ""
        );
        $fileHelper = new File($file['name']);

        if (!$fileHelper->isResizableImage()) {
            throw new BaseException(
                sprintf("Ошибка загрузки файла %s: недопустимый тип изображений", $file['name'])
            );
        }

        $fileUploaded->moveTo($uploadPath);

        // Поскольку фактически имя файла и его положение в загруженных файлах отличается и переместили файл
        // пересоздаем объект файла
        $fileHelper = new File($uploadPath);
        if (!$fileHelper->isImage()) {
            // Дополнительная проверка безопасности на изображение библиотекой Imagick (подмена расширения и MimeType).
            throw new BaseException(
                sprintf("Ошибка загрузки файла %s: недопустимый тип изображений", $file['name'])
            );
        }

        // Отлично, файл загружен, получаем относительный путь
        return $fileHelper->getRootRelativePath();
    }

}