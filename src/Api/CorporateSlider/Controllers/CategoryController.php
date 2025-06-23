<?php

namespace SpsFW\Api\CorporateSlider\Controllers;

use OpenApi\Attributes as OA;
use Ramsey\Uuid\Uuid;
use SpsFW\Api\CorporateSlider\Model\Dto\Update\CategoryUpdateDto;
use SpsFW\Api\CorporateSlider\Model\Storage\CategoryStorageCorporateSlider;
use Sps\FrontController\CallableControllerException;
use Sps\FrontController\ICallableController;
use Sps\FrontController\UserCallableController;
use SpsFW\Core\Exceptions\BaseException;
use SpsFW\Core\Http\HttpMethod;
use SpsFW\Core\Http\Request;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Route\Controller;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Route\Route;

#[Controller]
class CategoryController extends RestController
{
    private CategoryStorageCorporateSlider $categoryStorage;

    public function __construct()
    {
        $this->categoryStorage = new CategoryStorageCorporateSlider();
        parent::__construct();
    }

    /**
     * @throws CallableControllerException
     */
    #[OA\Post(
        path: "/api/v3/corporate-slider/categories",
        summary: "Создает новую категорию",
        tags: ["CorporateSlider"]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                ref: '#/components/schemas/CategoryCreateDto'
            )
        )
    )]
    #[OA\Response(
        response: 201,
        description: "Категория успешно создана",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'category_uuid', type: 'string')
            ]
        )
    )]
    #[Route(path: '/api/v3/corporate-slider/categories', httpMethods: [HttpMethod::POST])]

    public function createCategory(): Response
    {
        $categoryName = $_POST['category_name'] ?? null;
        if (!$categoryName) {
            throw new CallableControllerException('Не указано название категории');
        }

        $slidesData = $_POST['slides'] ?? [];
        $categoryUuid = Uuid::uuid7()->toString();

        try {
            $this->categoryStorage->createCategory($categoryUuid, $categoryName, $slidesData);
            return Response::created(['category_uuid' => $categoryUuid]);
        } catch (BaseException $e) {
            return Response::error($e);
        }

    }


    #[OA\Post(
        path: "/api/v3/corporate-slider/categories/{category_uuid}",
        summary: "Обновляет категорию по uuid",
        tags: ["CorporateSlider"]
    )]
    #[OA\Parameter(
        name: "category_uuid",
        description: "UUID категории",
        in: "path",
        required: true,
        schema: new OA\Schema(type: "string")
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                ref: CategoryUpdateDto::class
            )
        )
    )]
    #[OA\Response(response: 200, description: "Категория успешно обновлена")]
    #[Route(path: "/api/v3/corporate-slider/categories/{category_uuid}", httpMethods: [HttpMethod::POST])]
    public function updateCategory(string $category_uuid): Response
    {
        $body = $this->request->getJsonData() ?? $this->request->getPost();
        $categoryName = $body['category_name'] ?? null;
        $slideUuidsToRemove = json_decode($body['slide_uuids_to_remove'] ?? '');
        $slidesData = $body['slides'] ?? [];

        try {

            $this->categoryStorage->updateCategory(
                $category_uuid,
                $categoryName,
                $slideUuidsToRemove,
                $slidesData
            );
            return Response::ok();

        } catch (BaseException $e) {
            return Response::error($e);
        }
    }

    #[OA\Get(
        path: "/api/v3/corporate-slider/categories/{category_uuid}",
        summary: "Получает категорию по её UUID",
        tags: ["CorporateSlider"]
    )]
    #[OA\Parameter(
        name: "category_uuid",
        description: "UUID категории",
        in: "path",
        required: true,
        schema: new OA\Schema(type: "string")
    )]
    #[OA\Response(
        response: 200,
        description: "OK",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "category", ref: "#/components/schemas/CategoryResponseDto")
            ]
        )
    )]
    #[Route(path: '/api/v3/corporate-slider/categories/{category_uuid}', httpMethods: [HttpMethod::GET])]
    public function getCategoryByUuidWithSlides(string $category_uuid): array
    {
        $category = $this->categoryStorage->getCategoriesWithSlides($category_uuid)[0];
        return ['category' => $category];
    }

    // Получение всех категорий
    #[OA\Get(
        path: "/api/v3/corporate-slider/categories",
        summary: "Возвращает список всех категорий с их слайдами",
        tags: ["CorporateSlider"],
    )]
    #[OA\Response(
        response: 200,
        description: "Список всех категорий и их слайдов",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: "categories",
                    type: "array",
                    items: new OA\Items(
                        ref: "#/components/schemas/CategoryResponseDto"
                    )
                )
            ]
        )
    )]

    #[Route(path: "/api/v3/corporate-slider/categories", httpMethods: [HttpMethod::GET])]
    public function getAllCategoriesWithSlides(): array
    {
        $categories = $this->categoryStorage->getCategoriesWithSlides();

        return ["categories" => $categories];
    }


    #[OA\Delete(
        path: "/api/v3/corporate-slider/categories/{category_uuid}",
        summary: "Удаляет категорию по её UUID",
        tags: ["CorporateSlider"]
    )]
    #[OA\Parameter(
        name: "category_uuid",
        description: "UUID удаляемой категории",
        in: "path",
        required: true,
        schema: new OA\Schema(type: "string")
    )]
    #[OA\Response(response: 200, description: "Категория успешно удалена")]

    #[Route(path: "/api/v3/corporate-slider/categories/{category_uuid}", httpMethods: [HttpMethod::DELETE])]
    public function deleteCategory(string $category_uuid): Response
    {
        try {
            $this->categoryStorage->deleteManyCategory([$category_uuid]);
            return Response::ok();
        } catch (BaseException $e) {
            return Response::error(exception: $e);
        }
    }



    #[OA\Delete(
        path: "/api/v3/corporate-slider/categories",
        summary: "Удаляет категории по массивам UUID",
        tags: ["CorporateSlider"]
    )]
    #[OA\Parameter(
        name: "category_uuids",
        description: "Json строка с массивом uuids удаляемых категории",
        in: "query",
        required: true,
        example: '["uuid1", "uuid2"]'
    )]
    #[OA\Response(response: 200, description: "Категории успешно удалены")]

    #[Route(path: "/api/v3/corporate-slider/categories", httpMethods: [HttpMethod::DELETE])]
    public function deleteManyCategories(): Response
    {
        try {
            $category_uuids = json_decode($this->request->getGet()["category_uuids"]);
            $this->categoryStorage->deleteManyCategory($category_uuids);
            return Response::ok();
        } catch (BaseException $e) {
            return Response::error(exception: $e);
        }
    }
}
