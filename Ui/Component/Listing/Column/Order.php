<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Order number of the gift cards grid, as a link to the order.
 */
class Order extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $urlBuilder
     * @param Escaper $escaper
     * @param array<string,mixed> $components
     * @param array<string,mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly Escaper $escaper,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Cards issued before 1.1.0 have no order.
     *
     * @param array<string,mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource)
    {
        foreach ($dataSource['data']['items'] ?? [] as $i => $item) {
            if (empty($item['increment_id'])) {
                continue;
            }
            $url = $this->urlBuilder->getUrl('sales/order/view', ['order_id' => $item['order_id']]);
            $dataSource['data']['items'][$i][$this->getData('name')] = sprintf(
                '<a href="%s">%s</a>',
                $this->escaper->escapeUrl($url),
                $this->escaper->escapeHtmlAttr((string) $item['increment_id'])
            );
        }
        return $dataSource;
    }
}
