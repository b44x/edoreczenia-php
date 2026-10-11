<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Request;

use B4x\Edoreczenia\Exception\InvalidArgumentException;
use B4x\Edoreczenia\UserAgent\V3\Enum\CategoryService;
use B4x\Edoreczenia\UserAgent\V3\Enum\FormatService;
use B4x\Edoreczenia\UserAgent\V3\Enum\OverprintMode;
use B4x\Edoreczenia\UserAgent\V3\Enum\PrintingMode;
use B4x\Edoreczenia\UserAgent\V3\Enum\ProceedingType;
use B4x\Edoreczenia\UserAgent\V3\Enum\ReturnService;
use B4x\Edoreczenia\UserAgent\V3\Enum\ShippingMode;

/**
 * Print and delivery options of a hybrid (paper, PUH) shipment ("HybridShipment" schema), built immutably.
 *
 * {@see create()} takes every field the contract requires; the optional ones are added with the `with…()` methods.
 * Checked locally: the contract lengths of "sendersInformation" and "caseContent", and that "caseContent" is only
 * given for a registered letter sent under special rules (not {@see ProceedingType::General}), as the contract
 * describes it. Every other combination (e.g. formats, proof of delivery or poste restante for ordinary letters)
 * is validated by the server.
 *
 * "shipmentNumber" is not set by the SDK: the contract does not say whether a sender may provide it.
 */
final class HybridShipment
{
    public const MAX_SENDERS_INFORMATION_LENGTH = 30;
    public const MAX_CASE_CONTENT_LENGTH = 255;

    private ?string $sendersInformation = null;
    private ?FormatService $format = null;
    private ?bool $proofOfDelivery = null;
    private ?bool $posteRestante = null;
    private ?string $caseContent = null;

    private function __construct(
        private readonly ShippingMode $shippingMode,
        private readonly CategoryService $category,
        private readonly ProceedingType $proceedingType,
        private readonly ReturnService $returnService,
        private readonly PrintingMode $printingMode,
        private readonly OverprintMode $overprintMode,
    ) {}

    /**
     * @param ShippingMode    $shippingMode   registered or ordinary letter ("shippingMode")
     * @param CategoryService $category       delivery time ("categoryService")
     * @param ProceedingType  $proceedingType special rules of a registered letter ("procedingType")
     * @param ReturnService   $returnService  handling of an undeliverable registered letter ("returnService")
     * @param PrintingMode    $printingMode   one- or two-sided printing ("printingMode")
     * @param OverprintMode   $overprintMode  black-and-white or colour printing ("overprintMode")
     */
    public static function create(
        ShippingMode $shippingMode,
        CategoryService $category,
        ProceedingType $proceedingType,
        ReturnService $returnService,
        PrintingMode $printingMode,
        OverprintMode $overprintMode,
    ): self {
        return new self($shippingMode, $category, $proceedingType, $returnService, $printingMode, $overprintMode);
    }

    /**
     * Text printed in the sender field of the envelope ("sendersInformation"), at most
     * {@see MAX_SENDERS_INFORMATION_LENGTH} characters.
     */
    public function withSendersInformation(string $text): self
    {
        $shipment = clone $this;
        $shipment->sendersInformation = Field::text('sendersInformation', $text, self::MAX_SENDERS_INFORMATION_LENGTH);

        return $shipment;
    }

    /**
     * Letter format ("formatService").
     */
    public function withFormat(FormatService $format): self
    {
        $shipment = clone $this;
        $shipment->format = $format;

        return $shipment;
    }

    /**
     * Acknowledgement of receipt ("proofOfDelivery").
     */
    public function withProofOfDelivery(bool $proofOfDelivery = true): self
    {
        $shipment = clone $this;
        $shipment->proofOfDelivery = $proofOfDelivery;

        return $shipment;
    }

    /**
     * Poste restante: collected at a post office ("posteRestante").
     */
    public function withPosteRestante(bool $posteRestante = true): self
    {
        $shipment = clone $this;
        $shipment->posteRestante = $posteRestante;

        return $shipment;
    }

    /**
     * Description of the content ("caseContent"), at most {@see MAX_CASE_CONTENT_LENGTH} characters.
     * Only for a registered letter whose proceeding type is not {@see ProceedingType::General}.
     */
    public function withCaseContent(string $text): self
    {
        if ($this->shippingMode !== ShippingMode::Registered || $this->proceedingType === ProceedingType::General) {
            throw new InvalidArgumentException('caseContent is only allowed for registered letters with a proceeding type other than "general".');
        }
        $shipment = clone $this;
        $shipment->caseContent = Field::text('caseContent', $text, self::MAX_CASE_CONTENT_LENGTH);

        return $shipment;
    }

    public function shippingMode(): ShippingMode
    {
        return $this->shippingMode;
    }

    public function category(): CategoryService
    {
        return $this->category;
    }

    public function proceedingType(): ProceedingType
    {
        return $this->proceedingType;
    }

    public function returnService(): ReturnService
    {
        return $this->returnService;
    }

    public function printingMode(): PrintingMode
    {
        return $this->printingMode;
    }

    public function overprintMode(): OverprintMode
    {
        return $this->overprintMode;
    }

    public function sendersInformation(): ?string
    {
        return $this->sendersInformation;
    }

    public function format(): ?FormatService
    {
        return $this->format;
    }

    public function proofOfDelivery(): ?bool
    {
        return $this->proofOfDelivery;
    }

    public function posteRestante(): ?bool
    {
        return $this->posteRestante;
    }

    public function caseContent(): ?string
    {
        return $this->caseContent;
    }

    /**
     * JSON object ("HybridShipment" schema).
     *
     * @internal
     *
     * @return array<string, string|bool>
     */
    public function toPayload(): array
    {
        $payload = ['procedingType' => $this->proceedingType->value];
        if ($this->sendersInformation !== null) {
            $payload['sendersInformation'] = $this->sendersInformation;
        }
        $payload['categoryService'] = $this->category->value;
        if ($this->format !== null) {
            $payload['formatService'] = $this->format->value;
        }
        $payload['printingMode'] = $this->printingMode->value;
        $payload['overprintMode'] = $this->overprintMode->value;
        $payload['returnService'] = $this->returnService->value;
        if ($this->proofOfDelivery !== null) {
            $payload['proofOfDelivery'] = $this->proofOfDelivery;
        }
        if ($this->posteRestante !== null) {
            $payload['posteRestante'] = $this->posteRestante;
        }
        if ($this->caseContent !== null) {
            $payload['caseContent'] = $this->caseContent;
        }
        $payload['shippingMode'] = $this->shippingMode->value;

        return $payload;
    }
}
