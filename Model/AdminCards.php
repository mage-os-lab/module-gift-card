<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Model;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Cards created by an admin without an order: one from the "New Gift Card" form, a number of identical ones,
 * or one per line of a CSV file, all checked before any is created.
 */
class AdminCards
{
    /** Cards created by one form or file: they are created during the request. */
    public const MAX_CARDS = 1000;
    /** Fields of the new card form, and columns of the CSV file. */
    public const COLUMNS = [
        'balance',
        'recipient_email',
        'recipient_name',
        'sender_name',
        'message',
        'send_date',
        'expires_date',
    ];

    /**
     * @param Options $options
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        private readonly Options $options,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * Balance and columns of one card, as GiftCards::create() takes them.
     *
     * @param array<string,mixed> $fields among COLUMNS; dates as store-local "Y-m-d"
     * @param int $storeId store view of the e-mails
     * @return array{float, array<string,mixed>}
     * @throws LocalizedException when a field is invalid
     */
    public function build(array $fields, int $storeId): array
    {
        $balance = trim((string) ($fields['balance'] ?? ''));
        if (!is_numeric($balance) || round((float) $balance, 2) <= 0 || (float) $balance >= 1e12) {
            throw new LocalizedException(__('Enter a balance greater than 0.'));
        }
        $gift = new DataObject([
            Options::RECIPIENT_NAME => $fields['recipient_name'] ?? '',
            Options::RECIPIENT_EMAIL => $fields['recipient_email'] ?? '',
            Options::SENDER_NAME => $fields['sender_name'] ?? '',
            Options::MESSAGE => $fields['message'] ?? '',
            Options::SEND_DATE => $fields['send_date'] ?? '',
        ]);
        $this->options->validateGift($gift, $storeId);
        $card = ['store_id' => $storeId];
        $expires = trim((string) ($fields['expires_date'] ?? ''));
        if ($expires !== '') {
            $card['expires_at'] = $this->options->toUtc($expires, $storeId, true);
        }
        $email = trim((string) $gift->getData(Options::RECIPIENT_EMAIL));
        if ($email !== '') {
            $card += [
                'recipient_email' => $email,
                'recipient_name' => $this->options->text($gift->getData(Options::RECIPIENT_NAME)) ?: null,
                'sender_name' => $this->options->text($gift->getData(Options::SENDER_NAME)) ?: null,
                'message' => $this->options->text($gift->getData(Options::MESSAGE)) ?: null,
                'send_at' => $this->options->toUtc(trim((string) $gift->getData(Options::SEND_DATE)), $storeId),
            ];
        }
        return [round((float) $balance, 2), $card];
    }

    /**
     * The same card $count times.
     *
     * @param int $count
     * @param array<string,mixed> $fields see build()
     * @param int $storeId
     * @return array<int, array{float, array<string,mixed>}>
     * @throws LocalizedException
     */
    public function repeat(int $count, array $fields, int $storeId): array
    {
        if ($count < 1 || $count > self::MAX_CARDS) {
            throw new LocalizedException(__('Enter a number of gift cards between 1 and %1.', self::MAX_CARDS));
        }
        return array_fill(0, $count, $this->build($fields, $storeId));
    }

    /**
     * One card per line of a CSV file whose first line names the columns; blank lines are skipped.
     *
     * @param array<int,array<int,mixed>> $rows the file's lines, header first
     * @param int $storeId
     * @param array<string,mixed> $defaults fields of the form, for the cells left empty
     * @return array<int, array{float, array<string,mixed>}>
     * @throws LocalizedException naming the first wrong line: then no card must be created
     */
    public function fromCsv(array $rows, int $storeId, array $defaults = []): array
    {
        $header = array_map(
            fn ($column): string => strtolower(trim(str_replace("\u{FEFF}", '', (string) $column))),
            (array) array_shift($rows)
        );
        if (!$header || array_diff($header, self::COLUMNS)) {
            throw new LocalizedException(
                __('The first line of the file must name its columns, among: %1.', implode(', ', self::COLUMNS))
            );
        }
        $cards = [];
        foreach ($rows as $index => $row) {
            $cells = array_map(fn ($cell): string => trim((string) $cell), array_slice($row, 0, count($header)));
            if (implode('', $cells) === '') {
                continue;
            }
            if (count($cards) >= self::MAX_CARDS) {
                throw new LocalizedException(
                    __('A file can create %1 gift cards at most. Split it.', self::MAX_CARDS)
                );
            }
            $fields = array_filter(
                array_combine(array_slice($header, 0, count($cells)), $cells),
                fn (string $cell): bool => $cell !== ''
            ) + $defaults;
            try {
                $cards[] = $this->build($fields, $storeId);
            } catch (LocalizedException $e) {
                throw new LocalizedException(__('Line %1: %2', $index + 2, $e->getMessage()));
            }
        }
        if (!$cards) {
            throw new LocalizedException(__('The file has no gift card to create.'));
        }
        return $cards;
    }

    /**
     * CSV of the codes created, for the admin who created them; expiry in the admin's time zone, as in the grid.
     *
     * @param string[] $codes as GiftCards::createBatch() returns them
     * @param array<int,array{float,array<string,mixed>}> $cards the cards created, in the same order
     */
    public function codesCsv(array $codes, array $cards): string
    {
        $lines = ['code,balance,expires_at,recipient_email'];
        $zone = new \DateTimeZone((string) $this->timezone->getConfigTimezone());
        foreach ($codes as $i => $code) {
            // codes are hex, balances numbers, e-mails checked by Options::isEmail: nothing to quote
            $lines[] = implode(',', [
                $code,
                sprintf('%.2F', $cards[$i][0]),
                empty($cards[$i][1]['expires_at']) ? '' : (new \DateTimeImmutable(
                    (string) $cards[$i][1]['expires_at'],
                    new \DateTimeZone('UTC')
                ))->setTimezone($zone)->format('Y-m-d H:i:s'),
                $cards[$i][1]['recipient_email'] ?? '',
            ]);
        }
        return implode("\n", $lines) . "\n";
    }
}
