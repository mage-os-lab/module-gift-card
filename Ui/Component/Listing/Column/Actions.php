<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * "History" link of the gift cards grid.
 */
class Actions extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $urlBuilder
     * @param array<string,mixed> $components
     * @param array<string,mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
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
        foreach ($dataSource['data']['items'] ?? [] as $i => $item) {
            $dataSource['data']['items'][$i][$this->getData('name')] = [
                'history' => [
                    'href' => $this->urlBuilder->getUrl(
                        'mageos_giftcard/card/history',
                        ['giftcard_id' => $item['giftcard_id']]
                    ),
                    'label' => __('History'),
                ],
            ];
        }
        return $dataSource;
    }
}
