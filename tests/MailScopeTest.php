<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Mail\MailException;
use App\Mail\MailScope;

final class MailScopeTest extends TestCase
{
    public function testNoConfigurationMeansNoPolicy(): void
    {
        self::assertNull(MailScope::fromConfig(new Config([])));
        self::assertNull(MailScope::fromConfig(new Config([
            'INFOMANIAK_MAIL_ALIAS' => '',
            'INFOMANIAK_MAIL_ALLOWED_SENDERS' => '   ',
        ])));
    }

    public function testItReadsTheAliasAndBothAllowlists(): void
    {
        $scope = MailScope::fromConfig(new Config([
            'INFOMANIAK_MAIL_ALIAS' => 'bot@example.com',
            'INFOMANIAK_MAIL_ALLOWED_SENDERS' => 'alice@example.com, Bob@Example.com',
            'INFOMANIAK_MAIL_ALLOWED_RECIPIENTS' => 'a@example.com; b@example.com',
        ]));

        self::assertNotNull($scope);
        self::assertSame('bot@example.com', $scope->alias());
        self::assertNull($scope->mailbox());
        self::assertSame('INBOX', $scope->defaultMailbox());
        self::assertTrue($scope->isScoped());

        self::assertTrue($scope->allowsMessage(['from' => 'Alice <alice@example.com>', 'to' => 'bot@example.com']));
        self::assertTrue($scope->allowsMessage(['from' => 'bob@example.com', 'to' => 'someone@example.com', 'cc' => 'Bot@Example.com']));
        self::assertFalse($scope->allowsMessage(['from' => 'spam@example.com', 'to' => 'bot@example.com']));
        self::assertFalse($scope->allowsMessage(['from' => 'alice@example.com', 'to' => 'me@example.com']));

        $scope->assertRecipients(['a@example.com', 'B@Example.com']);
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('is not allowed by the mail access policy');
        $scope->assertRecipients(['c@example.com']);
    }

    public function testEmptyAllowlistsAllowEverything(): void
    {
        $scope = new MailScope(alias: 'bot@example.com');

        $scope->assertRecipients(['anyone@example.com']);
        self::assertTrue($scope->allowsMessage(['from' => 'anyone@example.com', 'to' => 'bot@example.com']));
    }

    public function testAJsonArrayIsAcceptedForAnAllowlist(): void
    {
        $scope = MailScope::fromConfig(new Config([
            'INFOMANIAK_MAIL_ALLOWED_RECIPIENTS' => '["a@example.com", "b@example.com"]',
        ]));

        self::assertNotNull($scope);
        $scope->assertRecipients(['a@example.com', 'b@example.com']);

        $this->expectException(MailException::class);
        $scope->assertRecipients(['c@example.com']);
    }

    public function testAnInvalidAliasIsRejected(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('INFOMANIAK_MAIL_ALIAS');

        MailScope::fromConfig(new Config(['INFOMANIAK_MAIL_ALIAS' => 'not-an-address']));
    }

    public function testAnInvalidAllowlistEntryIsRejected(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('INFOMANIAK_MAIL_ALLOWED_RECIPIENTS');

        MailScope::fromConfig(new Config(['INFOMANIAK_MAIL_ALLOWED_RECIPIENTS' => 'a@example.com, nope']));
    }

    public function testAnAliasOnlyPolicyAllowsJustInbox(): void
    {
        $scope = new MailScope(alias: 'bot@example.com');

        self::assertTrue($scope->allowsMailbox('INBOX'));
        self::assertTrue($scope->allowsMailbox('inbox'));
        self::assertFalse($scope->allowsMailbox('INBOX/Private'));
        self::assertFalse($scope->allowsMailbox('Sent'));
    }

    public function testAConfiguredFolderMatchesItsNameAndPath(): void
    {
        $scope = new MailScope(mailbox: 'Bot');

        self::assertTrue($scope->allowsMailbox('Bot'));
        self::assertTrue($scope->allowsMailbox('INBOX/Bot'));
        self::assertFalse($scope->allowsMailbox('INBOX'));
        self::assertFalse($scope->allowsMailbox('INBOX/Other'));
    }

    public function testADedicatedFolderDoesNotRequireTheAliasInTheHeaders(): void
    {
        $scope = new MailScope(alias: 'bot@example.com', mailbox: 'Bot');

        self::assertFalse($scope->requiresRecipientMatch(), 'the folder rule is the boundary');
        self::assertTrue($scope->allowsMessage(['from' => 'a@example.com', 'to' => 'me@example.com']));
    }

    public function testAliasFilteringStillAppliesInsideARestrictedInbox(): void
    {
        $scope = new MailScope(alias: 'bot@example.com', mailbox: 'INBOX');

        self::assertTrue($scope->requiresRecipientMatch());
        self::assertFalse($scope->allowsMessage(['from' => 'a@example.com', 'to' => 'me@example.com']));
    }

    public function testASenderAllowlistStillAppliesInADedicatedFolder(): void
    {
        $scope = new MailScope(
            alias: 'bot@example.com',
            mailbox: 'Bot',
            allowedSenders: ['alice@example.com'],
        );

        self::assertTrue($scope->allowsMessage(['from' => 'Alice <alice@example.com>', 'to' => 'anyone@example.com']));
        self::assertFalse($scope->allowsMessage(['from' => 'spam@example.com', 'to' => 'bot@example.com']));
    }

    public function testTheSummaryDescribesThePolicy(): void
    {
        $scope = new MailScope(
            alias: 'bot@example.com',
            mailbox: 'Bot',
            allowedSenders: ['alice@example.com'],
            allowedRecipients: ['alice@example.com'],
        );

        $summary = $scope->summary();

        self::assertStringContainsString('bot@example.com', $summary);
        self::assertStringContainsString('Bot', $summary);
        self::assertStringContainsString('senders', $summary);
        self::assertStringContainsString('sent', $summary);
        self::assertStringContainsString('not accessible', $summary);
    }
}
