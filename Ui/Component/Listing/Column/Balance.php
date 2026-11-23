<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Ui\Component\Listing\Column;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Amount of the gift card grids, in the base currency; empty when unknown (cards issued before 1.1.0).
 *
 * Not core's sales Price column: it looks up the store of each row, and fails on a deleted one.
 */
class Balance extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param PriceCurrencyInterface $priceCurrency
     * @param array<string,mixed> $components
     * @param array<string,mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly PriceCurrencyInterface $priceCurrency,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @inheritdoc
     *
     * @param array<string,mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource)
    {
        $name = $this->getData('name');
        foreach ($dataSource['data']['items'] ?? [] as $i => $item) {
            if (isset($item[$name])) {
                $dataSource['data']['items'][$i][$name] = $this->priceCurrency->format((float) $item[$name], false);
            }
        }
        return $dataSource;
    }
}
