<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Request;

use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\UserAgent\V3\Enum\ShippingService;

/**
 * A message to send or to save as a draft ("Message" schema), built immutably.
 *
 * Every value is checked locally before any request is made: blank or non-UTF-8 text, the 5000-character
 * limit of "textBody", duplicate recipients, attachment names, types and sizes.
 *
 * The SDK fills in "messageMetadata.from" with the mailbox address of the client and "shippingService":
 * "electronic" (RDE, the default) or, after {@see withHybridShipment()}, "hybrid" (PUH: printed and delivered
 * on paper). A hybrid message may also have recipients without an e-Delivery address, given by postal address
 * with {@see withPaperRecipients()}; in "messageMetadata.to" they follow the recipients given by e-Delivery address.
 * Which recipients and options the hybrid service accepts beyond the contract is checked by the server.
 *
 * Reply or forward: copy the "threadId" of the original message with {@see inThread()} and point at it with
 * {@see referringTo()}.
 *
 * Check recipients before sending, e.g. with the Search Engine API
 * (`$client->searchEngine()->findByAddresses([...])`): the SDK does not do it for you.
 */
final class OutgoingMessage
{
    public const MAX_TEXT_BODY_LENGTH = 5000;

    /** @var list<string> */
    private array $recipients = [];

    /** @var list<PaperRecipient> */
    private array $paperRecipients = [];

    private ?HybridShipment $hybridShipment = null;

    private ?string $textBody = null;

    /** @var list<OutgoingAttachment> */
    private array $attachments = [];

    private ?string $threadId = null;
    private ?string $refToMessageId = null;
    private ?string $caseId = null;

    /** @var array<string, string> */
    private array $additionalData = [];

    private function __construct(
        private readonly string $subject,
    ) {}

    public static function create(string $subject): self
    {
        return new self(Field::text('subject', $subject));
    }

    /**
     * Adds recipients by e-Delivery address (ADE), e.g. "AE:PL-12345-67890-ABCDE-12".
     * Each address may appear only once in a message.
     */
    public function withRecipients(string $address, string ...$more): self
    {
        $message = clone $this;
        foreach ([$address, ...$more] as $recipient) {
            Field::id('Recipient address', $recipient);
            if (\in_array($recipient, $message->recipients, true)) {
                throw new InvalidArgumentException('Each recipient address may appear only once in a message.');
            }
            $message->recipients[] = $recipient;
        }

        return $message;
    }

    /**
     * Adds recipients of a hybrid shipment by postal address, without an e-Delivery address.
     * Requires {@see withHybridShipment()}, checked when the request body is built. Each recipient may appear only once.
     */
    public function withPaperRecipients(PaperRecipient $recipient, PaperRecipient ...$more): self
    {
        $message = clone $this;
        foreach ([$recipient, ...$more] as $paperRecipient) {
            foreach ($message->paperRecipients as $known) {
                if ($known->toPayload() === $paperRecipient->toPayload()) {
                    throw new InvalidArgumentException('Each paper recipient may appear only once in a message.');
                }
            }
            $message->paperRecipients[] = $paperRecipient;
        }

        return $message;
    }

    /**
     * Sends the message through the hybrid service (PUH): "shippingService" = "hybrid" with these print and
     * delivery options ("hybridShipment"). Setting it again replaces the options.
     */
    public function withHybridShipment(HybridShipment $shipment): self
    {
        $message = clone $this;
        $message->hybridShipment = $shipment;

        return $message;
    }

    /**
     * Plain-text body ("textBody"), at most {@see MAX_TEXT_BODY_LENGTH} characters.
     */
    public function withTextBody(string $text): self
    {
        $message = clone $this;
        $message->textBody = Field::text('textBody', $text, self::MAX_TEXT_BODY_LENGTH);

        return $message;
    }

    /**
     * Appends an attachment; attachments are numbered ("order") in the order they are added, starting from 1.
     */
    public function withAttachment(OutgoingAttachment $attachment): self
    {
        $message = clone $this;
        $message->attachments[] = $attachment;

        return $message;
    }

    /**
     * Thread of the conversation ("threadId"), e.g. of the message you are replying to.
     */
    public function inThread(string $threadId): self
    {
        $message = clone $this;
        $message->threadId = Field::id('threadId', $threadId);

        return $message;
    }

    /**
     * Message this one refers to ("refToMessageId"), e.g. the message you are replying to.
     */
    public function referringTo(string $messageId): self
    {
        $message = clone $this;
        $message->refToMessageId = Field::id('refToMessageId', $messageId);

        return $message;
    }

    /**
     * Case reference of the sender ("caseId"), e.g. a file reference number.
     */
    public function withCaseId(string $caseId): self
    {
        $message = clone $this;
        $message->caseId = Field::text('caseId', $caseId);

        return $message;
    }

    /**
     * Free-form key/value pair ("additionalData"). Setting a key again replaces its value.
     */
    public function withAdditionalData(string $key, string $value): self
    {
        $message = clone $this;
        $message->additionalData[Field::text('additionalData key', $key)] = Field::text('additionalData value', $value);

        return $message;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        return $this->recipients;
    }

    /**
     * @return list<PaperRecipient>
     */
    public function paperRecipients(): array
    {
        return $this->paperRecipients;
    }

    public function hybridShipment(): ?HybridShipment
    {
        return $this->hybridShipment;
    }

    public function shippingService(): ShippingService
    {
        return $this->hybridShipment === null ? ShippingService::Electronic : ShippingService::Hybrid;
    }

    public function textBody(): ?string
    {
        return $this->textBody;
    }

    /**
     * @return list<OutgoingAttachment>
     */
    public function attachments(): array
    {
        return $this->attachments;
    }

    public function threadId(): ?string
    {
        return $this->threadId;
    }

    public function refToMessageId(): ?string
    {
        return $this->refToMessageId;
    }

    public function caseId(): ?string
    {
        return $this->caseId;
    }

    /**
     * @return array<string, string>
     */
    public function additionalData(): array
    {
        return $this->additionalData;
    }

    /**
     * JSON body ("Message" schema) for sending or saving a draft.
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toPayload(string $senderAddress): array
    {
        if ($this->paperRecipients !== [] && $this->hybridShipment === null) {
            throw new InvalidArgumentException('Recipients given by postal address need a hybrid shipment; call withHybridShipment().');
        }
        $metadata = ['from' => ['eDeliveryAddress' => Field::id('Sender address', $senderAddress)]];
        $to = [
            ...array_map(static fn(string $address): array => ['eDeliveryAddress' => $address], $this->recipients),
            ...array_map(static fn(PaperRecipient $recipient): array => $recipient->toPayload(), $this->paperRecipients),
        ];
        if ($to !== []) {
            $metadata['to'] = $to;
        }
        if ($this->hybridShipment !== null) {
            $metadata['hybridShipment'] = $this->hybridShipment->toPayload();
        }
        $metadata['subject'] = $this->subject;
        foreach (['threadId' => $this->threadId, 'refToMessageId' => $this->refToMessageId, 'caseId' => $this->caseId] as $name => $value) {
            if ($value !== null) {
                $metadata[$name] = $value;
            }
        }
        $metadata['shippingService'] = $this->shippingService()->value;
        if ($this->additionalData !== []) {
            $metadata['additionalData'] = $this->additionalData;
        }

        $payload = ['messageMetadata' => $metadata];
        if ($this->textBody !== null) {
            $payload['textBody'] = $this->textBody;
        }
        if ($this->attachments !== []) {
            $payload['attachments'] = array_map(
                static fn(OutgoingAttachment $attachment, int $index): array => ['order' => $index + 1, 'file' => $attachment->toFileData()],
                $this->attachments,
                array_keys($this->attachments),
            );
        }

        return $payload;
    }
}
