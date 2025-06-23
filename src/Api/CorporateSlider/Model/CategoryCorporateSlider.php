<?php

namespace SpsFW\Api\CorporateSlider\Model;

use JsonSerializable;

class CategoryCorporateSlider implements JsonSerializable
{
    /**
     * UUID категории
     * @var string $category_uuid
     */
    protected string $category_uuid;

    /**
     * Наименование категории
     * @var string
     */
    protected string $name;

    /**
     * @return SlideCorporateSlider[]|null
     */
    public function getSlides(): ?array
    {
        return $this->slides;
    }

    /**
     * @param array|null $slides
     * @return CategoryCorporateSlider
     */
    public function setSlides(?array $slides): CategoryCorporateSlider
    {
        $this->slides = $slides;
        return $this;
    }

    /**
     * @param array|null $slides_arrays
     * @return CategoryCorporateSlider
     */
    public function appendSlides(?array $slides_arrays): CategoryCorporateSlider
    {
        foreach ($slides_arrays as $slides) {
            $this->slides =  array_merge($this->slides, $slides);
        }
        return $this;
    }

    /**
     *  Слайды категории
     * @var SlideCorporateSlider[]|null
     */
    private array $slides = [];

    /**
     * @return string
     */
    public function getCategoryUuid(): string
    {
        return $this->category_uuid;
    }

    /**
     * @param string $category_uuid
     * @return CategoryCorporateSlider
     */
    public function setCategoryUuid(string $category_uuid): CategoryCorporateSlider
    {
        $this->category_uuid = $category_uuid;
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
     * @return CategoryCorporateSlider
     */
    public function setName(string $name): CategoryCorporateSlider
    {
        $this->name = $name;
        return $this;
    }

    public function addSlide(SlideCorporateSlider $slide): CategoryCorporateSlider
    {
        $this->slides[] = $slide;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function jsonSerialize(): array
    {
        return array(
            'category_uuid' => $this->category_uuid,
            'name' => $this->name,
            'slides' => $this->slides,
        );
    }
}