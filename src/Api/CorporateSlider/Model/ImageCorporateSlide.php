<?php

namespace SpsFW\Api\CorporateSlider\Model;

use JsonSerializable;

class ImageCorporateSlide implements JsonSerializable
{
    /**
     *  uuid Изображения
     * @var string
     */
    protected string $image_uuid;

    /**
     *  uuid Слайдера
     * @var string
     */
    protected string $slide_uuid;

    /**
     *  uuid Категории
     * @var string
     */
    protected string $category_uuid;

    /**
     *  Относительный путь к картинке
     * @var string
     */
    protected string $path;

    /**
     *  Название картинки
     * @var string
     */
    protected string $name;

    /**
     *  Порядок картинки в слайдере
     * @var int
     */
    protected int $sort;

    /**
     * @inheritDoc
     */
    public function jsonSerialize(): array
    {
        return array(
            'image_uuid' => $this->image_uuid,
            'slide_uuid' => $this->slide_uuid,
            'category_uuid' => $this->category_uuid,
            'path' => $this->path,
            'name' => $this->name,
            'sort' => $this->sort,
        );
    }

    /**
     * @return string
     */
    public function getImageUuid(): string
    {
        return $this->image_uuid;
    }

    /**
     * @param string $image_uuid
     * @return ImageCorporateSlide
     */
    public function setImageUuid(string $image_uuid): ImageCorporateSlide
    {
        $this->image_uuid = $image_uuid;
        return $this;
    }

    /**
     * @return string
     */
    public function getSliderUuid(): string
    {
        return $this->slide_uuid;
    }

    /**
     * @param string $slide_uuid
     * @return ImageCorporateSlide
     */
    public function setSlideUuid(string $slide_uuid): ImageCorporateSlide
    {
        $this->slide_uuid = $slide_uuid;
        return $this;
    }

    /**
     * @return string
     */
    public function getCategoryUuid(): string
    {
        return $this->category_uuid;
    }

    /**
     * @param string $category_uuid
     * @return ImageCorporateSlide
     */
    public function setCategoryUuid(string $category_uuid): ImageCorporateSlide
    {
        $this->category_uuid = $category_uuid;
        return $this;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @param string $path
     * @return ImageCorporateSlide
     */
    public function setPath(string $path): ImageCorporateSlide
    {
        $this->path = $path;
        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return ImageCorporateSlide
     */
    public function setName(string $name): ImageCorporateSlide
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return int
     */
    public function getSort(): int
    {
        return $this->sort;
    }

    /**
     * @param int $sort
     * @return ImageCorporateSlide
     */
    public function setSort(int $sort): ImageCorporateSlide
    {
        $this->sort = $sort;
        return $this;
    }

}