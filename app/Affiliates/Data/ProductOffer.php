<?php

namespace App\Affiliates\Data;

/**
 * A product found in an affiliate program's catalogue.
 */
final readonly class ProductOffer
{
    public function __construct(
        public string $externalId,
        public string $name,
        public string $productUrl,
        public ?string $affiliateUrl = null,
        public ?string $imageUrl = null,
        public ?float $priceMin = null,
        public ?float $priceMax = null,
        public string $currency = '',
        public ?float $commissionRate = null,
        public ?float $rating = null,
        public ?int $sales = null,
        public ?string $shopName = null,
    ) {}

    /**
     * One line of facts for the product notes, which the AI may quote.
     */
    public function summary(): string
    {
        $parts = [];

        if ($this->priceMin !== null) {
            $parts[] = $this->priceMax !== null && $this->priceMax > $this->priceMin
                ? $this->money($this->priceMin).' - '.$this->money($this->priceMax)
                : $this->money($this->priceMin);
        }

        if ($this->rating !== null) {
            $parts[] = number_format($this->rating, 1).' stars';
        }

        if ($this->sales !== null) {
            $parts[] = number_format($this->sales).' sold';
        }

        if ($this->commissionRate !== null) {
            $parts[] = rtrim(rtrim(number_format($this->commissionRate * 100, 1), '0'), '.').'% commission';
        }

        if (filled($this->shopName)) {
            $parts[] = 'Shop: '.$this->shopName;
        }

        return implode(' | ', $parts);
    }

    private function money(float $amount): string
    {
        return $this->currency.number_format($amount, 2);
    }
}
