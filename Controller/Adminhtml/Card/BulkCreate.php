<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Controller\Adminhtml\Card;

use MageOS\GiftCard\Model\AdminCards;
use MageOS\GiftCard\Model\GiftCards;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\File\Csv;

/**
 * Creates the cards of the bulk form, all or none, and downloads their codes as CSV for admins allowed to
 * see full codes; the others find the cards in the grid.
 */
class BulkCreate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_GiftCard::cards_create';
    /** Largest CSV file read, in bytes. */
    private const MAX_FILE_SIZE = 2097152;

    /**
     * @param Context $context
     * @param GiftCards $giftCards
     * @param AdminCards $adminCards
     * @param Csv $csv
     * @param Http $request
     */
    public function __construct(
        Context $context,
        private readonly GiftCards $giftCards,
        private readonly AdminCards $adminCards,
        private readonly Csv $csv,
        private readonly Http $request
    ) {
        parent::__construct($context);
    }

    /**
     * Back to the form on invalid input; else the CSV of the codes, or the grid.
     */
    public function execute()
    {
        $request = $this->getRequest();
        $storeId = (int) $request->getParam('store_id');
        $defaults = array_filter([
            'balance' => trim((string) $request->getParam('balance')),
            'expires_date' => trim((string) $request->getParam('expires_date')),
        ], fn (string $value): bool => $value !== '');
        try {
            $rows = $this->rows();
            $cards = $rows === null
                ? $this->adminCards->repeat((int) $request->getParam('count'), $defaults, $storeId)
                : $this->adminCards->fromCsv($rows, $storeId, $defaults);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->resultRedirectFactory->create()->setPath('*/*/bulk');
        }
        $cards = $this->giftCards->withValidity($cards);
        $user = $this->_auth->getUser();
        $codes = $this->giftCards->createBatch(
            $cards,
            $user instanceof DataObject ? (string) $user->getData('username') : null
        );
        $this->messageManager->addSuccessMessage(__('%1 gift cards were created.', count($codes)));
        if (!$this->_authorization->isAllowed(GiftCards::ACL_CODES)) {
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }
        /** Raw result: the codes are never written to a file on the server */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        return $result->setHeader('Content-Type', 'text/csv; charset=utf-8', true)
            ->setHeader('Content-Disposition', 'attachment; filename="gift-cards-' . gmdate('Ymd-His') . '.csv"', true)
            ->setHeader('Cache-Control', 'no-store', true)
            ->setContents($this->adminCards->codesCsv($codes, $cards));
    }

    /**
     * Lines of the uploaded CSV file, comma or semicolon separated; null when no file was sent.
     *
     * @return array<int, array<int, mixed>>|null
     * @throws LocalizedException when the file cannot be read
     */
    private function rows(): ?array
    {
        $file = $this->request->getFiles('csv_file');
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK
            || !str_ends_with(strtolower((string) $file['name']), '.csv')
            || (int) $file['size'] > self::MAX_FILE_SIZE
        ) {
            throw new LocalizedException(__('Upload a CSV file of 2 MB at most.'));
        }
        try {
            $rows = $this->csv->setDelimiter(',')->getData((string) $file['tmp_name']);
            if (count($rows[0] ?? []) === 1 && str_contains((string) $rows[0][0], ';')) {
                // spreadsheets in many languages save CSV with semicolons
                $rows = $this->csv->setDelimiter(';')->getData((string) $file['tmp_name']);
            }
        } catch (\Exception) {
            throw new LocalizedException(__('Upload a CSV file of 2 MB at most.'));
        }
        return $rows;
    }
}
