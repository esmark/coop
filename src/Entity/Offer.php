<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\StatusTrait;
use Doctrine\ORM\Mapping as ORM;
use Smart\CoreBundle\Doctrine\ColumnTrait;
use Smart\CoreBundle\Doctrine\ColumnTrait\IsEnabled;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Предложение (товар или услуга), они же "Объявления"
 *
 * @ORM\Entity(repositoryClass="App\Repository\OfferRepository")
 * @ORM\Table(name="offers",
 *      indexes={
 *          @ORM\Index(columns={"created_at"}),
 *          @ORM\Index(columns={"is_enabled"}),
 *          @ORM\Index(columns={"price"}),
 *          @ORM\Index(columns={"status"}),
 *          @ORM\Index(columns={"title"}),
 *      },
 *      uniqueConstraints={
 *          @ORM\UniqueConstraint(columns={"user_id", "title"}),
 *      }
 * )
 *
 * @ORM\HasLifecycleCallbacks()
 * @UniqueEntity(fields={"user", "title"}, message="Title must be unique")
 */
class Offer
{
    use ColumnTrait\Uuid;
    use ColumnTrait\CreatedAt;
    use ColumnTrait\UpdatedAt;
    use ColumnTrait\TitleNotBlank;
    use ColumnTrait\Description;
    use ColumnTrait\User;

    use StatusTrait;

    const MEASURE_NONE  = 0;
    const MEASURE_PIECE = 1;
    const MEASURE_GRAM  = 2;
    const MEASURE_KG    = 3;
    const MEASURE_LITRE = 4;
    static protected $measure_values = [
        self::MEASURE_NONE  => 'нет',
        self::MEASURE_PIECE => 'шт',
        self::MEASURE_GRAM  => 'гр',
        self::MEASURE_KG    => 'кг',
        self::MEASURE_LITRE => 'л',
    ];

    const STATUS_NOT_AVAILABLE  = 0;
    const STATUS_AVAILABLE      = 1;
    const STATUS_RESERVE        = 2;
    const STATUS_ON_DEMAND      = 3;
    static protected $status_values = [
        self::STATUS_AVAILABLE      => 'Есть в наличии',
        self::STATUS_ON_DEMAND      => 'Под заказ',
        self::STATUS_RESERVE        => 'Резерв',
        self::STATUS_NOT_AVAILABLE  => 'Нет в наличии', // не участвует в эмиссии
    ];

    /**
     * @ORM\Column(type="boolean", nullable=false, options={"default":1})
     */
    protected bool $is_enabled;

    /**
     * @ORM\Column(type="integer", nullable=false)
     */
    protected int $price;

    /**
     * @ORM\Column(type="text", nullable=true)
     * @Assert\Length(max = 160, allowEmptyString=false)
     */
    protected ?string $short_description;

    /**
     * Еденицы измерения
     *
     * @ORM\Column(type="smallint", nullable=false)
     */
    protected int $measure;

    /**
     * Количество
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    protected ?int $quantity;

    /**
     * Кол-во в резерве
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    protected ?int $quantity_reserved = null;

    /**
     * ИД файла в медиалибе
     *
     * @ORM\Column(type="string", length=36, nullable=true)
     */
    protected ?string $image_id = null;

    /**
     * @var Category
     *
     * @ORM\ManyToOne(targetEntity="Category")
     * @ORM\JoinColumn(nullable=false)
     */
    protected Category $category;

    /**
     * @ORM\ManyToOne(targetEntity="City")
     */
    protected ?City $city = null;

    public function __construct()
    {
        $this->created_at = new \DateTime();
        $this->is_enabled = true;
        $this->measure    = self::MEASURE_NONE;
        $this->status     = self::STATUS_AVAILABLE;
    }

    public function __toString(): string
    {
        return $this->title;
    }

    public function getQuantityAvailable(): ?int
    {
        return $this->quantity - $this->quantity_reserved;
    }

    /**
     * @ORM\PreFlush()
     */
    public function preFlush(): void
    {
        if ($this->getMeasure() == self::MEASURE_NONE) {
            $this->setQuantity(null);
            $this->setQuantityReserved(null);
        }
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function getPriceTotal(): ?int
    {
        if (empty($this->quantity)) {
            return $this->price;
        }

        return $this->price * $this->quantity;
    }

    public function setPrice(?int $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function isStatusAccessToOrder(): bool
    {
        if ($this->status == self::STATUS_AVAILABLE or $this->status == self::STATUS_ON_DEMAND) {
            return true;
        }

        return false;
    }

    public function getMeasure(): int
    {
        return $this->measure;
    }

    public function getMeasureAsText(): string
    {
        return self::$measure_values[$this->measure];
    }

    public function setMeasure(int $measure): self
    {
        $this->measure = $measure;

        return $this;
    }

    static public function getMeasureValues(): array
    {
        return self::$measure_values;
    }

    static public function getMeasureFormChoices(): array
    {
        return array_flip(self::$measure_values);
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(?int $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getQuantityReserved(): ?int
    {
        return $this->quantity_reserved;
    }

    public function setQuantityReserved(?int $quantity_reserved): self
    {
        $this->quantity_reserved = $quantity_reserved;

        return $this;
    }

    public function getImageId(): ?string
    {
        return $this->image_id;
    }

    public function setImageId(?string $image_id): self
    {
        $this->image_id = $image_id;

        return $this;
    }

    public function getShortDescription(): ?string
    {
        return $this->short_description;
    }

    public function setShortDescription(?string $short_description): self
    {
        $this->short_description = $short_description;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function setIsEnabled(bool $is_enabled): self
    {
        $this->is_enabled = $is_enabled;

        return $this;
    }

    public function getIsEnabled(): bool
    {
        return $this->is_enabled;
    }

    public function getIsEnabledAsText(): string
    {
        return $this->is_enabled ? 'Yes' : 'No';
    }

    public function isEnabled(): bool
    {
        return $this->is_enabled;
    }

    public function isDisabled(): bool
    {
        return !$this->is_enabled;
    }

    public function getCity(): ?City
    {
        return $this->city;
    }

    public function setCity(?City $city): self
    {
        $this->city = $city;

        return $this;
    }
}
