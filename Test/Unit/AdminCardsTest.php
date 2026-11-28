<?php
declare(strict_types=1);

namespace MageOS\GiftCard\Test\Unit;

use DateTime;
use MageOS\GiftCard\Model\AdminCards;
use MageOS\GiftCard\Model\Options;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the cards an admin creates one by one, in bulk, or from a CSV file.
 */
class AdminCardsTest extends TestCase
{
    /**
     * Each line of the file becomes a card with its own balance and recipient; a blank line is skipped,
     * and the form's balance and expiry fill the cells left empty.
     */
    public function testCsvLinesBecomeCards(): void
    {
        $cards = $this->adminCards()->fromCsv([
            ["\u{FEFF}Balance", 'Recipient_Email', 'recipient_name', 'expires_date'],
            ['25', 'zoe@example.com', 'Zoé', '2030-01-31'],
            ['', '', '', ''],
            ['', 'max@example.com', '', ''],
        ], 1, ['balance' => '10.5', 'expires_date' => '2030-06-30']);

        $this->assertSame([25.0, 10.5], array_column($cards, 0));
        $this->assertSame('zoe@example.com', $cards[0][1]['recipient_email']);
        $this->assertSame('Zoé', $cards[0][1]['recipient_name']);
        $this->assertSame('2030-01-31 23:59:59', $cards[0][1]['expires_at']);
        $this->assertSame('2030-06-30 23:59:59', $cards[1][1]['expires_at']);
        $this->assertNull($cards[1][1]['recipient_name']);
    }

    /**
     * Without an expiry in the file or the form, the card takes the configured validity.
     */
    public function testCardWithoutExpiryTakesTheConfiguredValidity(): void
    {
        $cards = $this->adminCards()->fromCsv([['balance'], ['5']], 1);

        $this->assertSame([[5.0, ['store_id' => 1]]], $cards);
    }

    /**
     * One wrong line refuses the whole file, and the message names the line.
     */
    public function testOneWrongLineRefusesTheFile(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Line 3: Enter a balance greater than 0.');

        $this->adminCards()->fromCsv([['balance', 'recipient_email'], ['10', ''], ['0', 'a@example.com']], 1);
    }

    /**
     * Files that create no card, or not the cards the admin meant, are refused.
     *
     * @param array<int, array<int, string>> $rows
     */
    #[DataProvider('wrongFiles')]
    public function testWrongFileIsRefused(array $rows): void
    {
        $this->expectException(LocalizedException::class);

        $this->adminCards()->fromCsv($rows, 1);
    }

    /**
     * Files to refuse.
     *
     * @return array<string, array{array<int, array<int, string>>}>
     */
    public static function wrongFiles(): array
    {
        $tooMany = [['balance']];
        for ($i = 0; $i <= AdminCards::MAX_CARDS; $i++) {
            $tooMany[] = ['1'];
        }
        return [
            'empty file' => [[]],
            'header only' => [[['balance']]],
            'unknown column' => [[['balance', 'amount'], ['10', '10']]],
            'no balance anywhere' => [[['recipient_email'], ['a@example.com']]],
            'balance below a cent' => [[['balance'], ['0.004']]],
            'balance that is not a number' => [[['balance'], ['ten']]],
            'balance with a currency sign' => [[['balance'], ['€10']]],
            'invalid recipient' => [[['balance', 'recipient_email'], ['10', 'not-an-email']]],
            'message without recipient' => [[['balance', 'message'], ['10', 'Hello']]],
            'too many lines' => [$tooMany],
        ];
    }

    /**
     * A number of identical cards: within 1 and the maximum, with a valid balance.
     */
    public function testRepeatCreatesIdenticalCards(): void
    {
        $cards = $this->adminCards()->repeat(3, ['balance' => '20'], 1);

        $this->assertSame([20.0, 20.0, 20.0], array_column($cards, 0));
    }

    /**
     * A number of cards out of bounds is refused.
     *
     * @param int $count
     */
    #[DataProvider('wrongCounts')]
    public function testWrongNumberOfCardsIsRefused(int $count): void
    {
        $this->expectException(LocalizedException::class);

        $this->adminCards()->repeat($count, ['balance' => '20'], 1);
    }

    /**
     * Numbers of cards to refuse.
     *
     * @return array<string, array{int}>
     */
    public static function wrongCounts(): array
    {
        return ['none' => [0], 'negative' => [-3], 'above the maximum' => [AdminCards::MAX_CARDS + 1]];
    }

    /**
     * The new card form keeps its rules: a recipient gets a send date, a balance is rounded to cents.
     */
    public function testSingleCardWithRecipient(): void
    {
        [$balance, $card] = $this->adminCards()->build([
            'balance' => '12.345',
            'recipient_email' => 'zoe@example.com',
            'message' => '<b>Hi</b>',
        ], 2);

        $this->assertSame(12.35, $balance);
        $this->assertSame(2, $card['store_id']);
        $this->assertSame('Hi', $card['message']);
        $this->assertNotEmpty($card['send_at']);
    }

    /**
     * The CSV of the codes gives the expiry in the admin's time zone, as the grid does, not in UTC.
     */
    public function testCodesCsvGivesExpiryInAdminTimeZone(): void
    {
        $adminCards = $this->adminCards('America/Los_Angeles');
        $cards = $adminCards->repeat(1, ['balance' => '25', 'expires_date' => '2027-06-30'], 1);
        $cards[] = [10.0, ['store_id' => 1, 'recipient_email' => 'zoe@example.com']];

        $this->assertSame('2027-07-01 06:59:59', $cards[0][1]['expires_at']);
        $this->assertSame(
            "code,balance,expires_at,recipient_email\n"
            . "AAAA,25.00,2027-06-30 23:59:59,\n"
            . "BBBB,10.00,,zoe@example.com\n",
            $adminCards->codesCsv(['AAAA', 'BBBB'], $cards)
        );
    }

    /**
     * Admin cards on a store and admin time zone.
     *
     * @param string $zone
     * @return AdminCards
     */
    private function adminCards(string $zone = 'UTC'): AdminCards
    {
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn($zone);
        $timezone->method('scopeDate')->willReturn(new DateTime('now', new \DateTimeZone($zone)));
        return new AdminCards(new Options($timezone), $timezone);
    }
}
