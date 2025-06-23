<?php

namespace SpsFW\Api\CorporateSlider\Model;

use JsonSerializable;

class SlideCorporateSlider implements JsonSerializable
{
    /**
     * Слайдер uuid
     * @var string
     */
    protected string $slide_uuid;

    /**
     * Категория uuid
     * @var string
     */
    protected string $category_uuid;

    /**
     * Порядок сортировки слайдов в категории
     * @var int
     */
    protected int $sort;
    /**
     * Загруженные изображения
     * @var ImageCorporateSlide[]|null
     */
    protected ?array $images = [];

    /**
     * @return string
     */
    public function getSlideUuid(): string
    {
        return $this->slide_uuid;
    }

    /**
     * @param string $slide_uuid
     * @return SlideCorporateSlider
     */
    public function setSlideUuid(string $slide_uuid): SlideCorporateSlider
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
     * @return SlideCorporateSlider
     */
    public function setCategoryUuid(string $category_uuid): SlideCorporateSlider
    {
        $this->category_uuid = $category_uuid;
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
     * @return SlideCorporateSlider
     */
    public function setSort(int $sort): SlideCorporateSlider
    {
        $this->sort = $sort;
        return $this;
    }


    /**
     * @inheritDoc
     */
    public function jsonSerialize(): array
    {
        return array(
            'slide_uuid' => $this->slide_uuid,
            'category_uuid' => $this->category_uuid,
            'sort' => $this->sort,
            'images' => $this->images
        );
    }

    /**
     * @return ImageCorporateSlide[]|null
     */
    public function getImages(): ?array
    {
        return $this->images;
    }

    /**
     * Добавление изображения к слайдеру
     * @param ImageCorporateSlide $image
     * @return SlideCorporateSlider
     */
    public function addImage(ImageCorporateSlide $image): self
    {
        $this->images[] = $image;
        return $this;
    }

    /**
     * @param SlideCorporateSlider[]|null $images
     * @return SlideCorporateSlider
     */
    public function setImages(?array $images): SlideCorporateSlider
    {
        $this->images = $images;
        return $this;
    }



}