<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Block\Adminhtml;

use MageOS\GiftCard\Model\GiftCards;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\System\Store;

/**
 * Data of the admin gift card forms: the new card form and the "send the code" form of the history page.
 */
class Card extends Template
{
    /**
     * @param Context $context
     * @param GiftCards $giftCards
     * @param OrderRepositoryInterface $orderRepository
     * @param Store $stores
     * @param array<string,mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly GiftCards $giftCards,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Store $stores,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The card of the "giftcard_id" parameter.
     *
     * @return array<string, mixed>|null
     */
    public function getCard(): ?array
    {
        return $this->giftCards->get((int) $this->getRequest()->getParam('giftcard_id'));
    }

    /**
     * Address to send the code to by default: the recipient, else the buyer.
     *
     * @param array<string,mixed> $card
     */
    public function getDefaultEmail(array $card): string
    {
        if ($card['recipient_email']) {
            return (string) $card['recipient_email'];
        }
        try {
            return $card['order_id']
                ? (string) $this->orderRepository->get((int) $card['order_id'])->getCustomerEmail()
                : '';
        } catch (NoSuchEntityException) {
            return '';
        }
    }

    /**
     * Whether the admin user has an ACL resource of the module, to show only the forms they can use.
     *
     * @param string $resource e.g. MageOS_GiftCard::cards_edit
     */
    public function isAllowed(string $resource): bool
    {
        return $this->_authorization->isAllowed($resource);
    }

    /**
     * Store views, grouped by website, for the select of the new card form.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStoreOptions(): array
    {
        return $this->stores->getStoreValuesForForm(false, false);
    }
}
