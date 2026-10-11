<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Testing;

/**
 * Default dataset served by {@see FakeEdoreczenia}.
 *
 * Every value here is SYNTHETIC. It was written by hand from the `example:` values of the official
 * contracts (se-api 4.0.5, uaapi 3.0.8) and was NOT captured from the real e-Doręczenia API. The
 * addresses (ADE), NIP and names are the placeholders used in the official examples; they do not
 * identify real mailboxes or people.
 *
 * @internal the shape may change between releases; use the documented ids in your tests:
 *           mailbox {@see self::MAILBOX}, ADEs {@see self::ADDRESSES}, thread "T-1",
 *           messages {@see self::MESSAGE_ID} and {@see self::SENT_MESSAGE_ID}, evidences "EV-1".."EV-3",
 *           attachments "ATT-1" and "ATT-2" of {@see self::MESSAGE_ID}, pending message {@see self::PENDING_MESSAGE_ID},
 *           recipients for sending {@see self::ACTIVE_RECIPIENT} and {@see self::INACTIVE_RECIPIENT}.
 */
final class SyntheticData
{
    public const MAILBOX = 'AE:PL-12345-67890-ABCDE-12';
    public const MESSAGE_ID = 'D41568F4-7175-42BB-9503-DAA282180D70';
    public const SENT_MESSAGE_ID = '0A1B2C3D-0000-4000-8000-000000000001';
    public const THREAD_ID = 'T-1';

    private const HQ_ADDRESS = [
        'addressType' => ['headquarters', 'correspondence'],
        'countryCode' => 'PL',
        'street' => 'POLNA',
        'typeOfStreet' => 'STREET',
        'postalCode' => '05-569',
        'city' => 'WARSZAWA',
        'buildingNumber' => '12AA',
        'voivodeship' => 'MAZOWIECKIE',
        'district' => 'MIŃSKI',
        'community' => 'HALINÓW',
    ];

    private const NIP = [['id' => '5232958825', 'referenceRegistry' => 'nip']];

    /**
     * Electronic Address Database (BAE) entries, keyed by ADE.
     *
     * "eda" follows the RecipientEda schema, "recipient" the EdaConfirmationRecipient schema and
     * "baeSearchData" the BaeSearchData schema; the fake derives each SE API response from them.
     */
    public const ADDRESSES = [
        self::MAILBOX => [
            'eda' => [
                'recipientEda' => self::MAILBOX,
                'isMainEda' => true,
                'assignmentDegree' => '3',
                'edaStatus' => 'ACTIVE',
                'designatedOperator' => true,
                'dateOfEnteringToBAE' => '2024-01-15',
            ],
            'recipient' => [
                'isPublic' => false,
                'entityName' => 'NAZWA PODMIOTU',
                'legalForm' => 'SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ',
                'officialIds' => self::NIP,
                'nipStatus' => 'ASSIGNED',
                'address' => [[
                    'addressType' => ['headquarters'],
                    'countryCode' => 'PL',
                    'city' => 'WARSZAWA',
                    'street' => 'POLNA',
                    'buildingNumber' => '12AA',
                    'postalCode' => '05-569',
                ]],
            ],
            'baeSearchData' => [[
                'index' => 1,
                'isPublic' => false,
                'entityName' => 'NAZWA PODMIOTU',
                'legalForm' => 'SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ',
                'officialIds' => self::NIP,
                'nipStatus' => 'ASSIGNED',
            ]],
        ],
        'AE:PL-98765-43210-SFVYC-19' => [
            'eda' => [
                'recipientEda' => 'AE:PL-98765-43210-SFVYC-19',
                'isMainEda' => true,
                'assignmentDegree' => '3',
                'edaStatus' => 'ACTIVE',
                'designatedOperator' => true,
                'dateOfEnteringToBAE' => '2024-01-15',
                'warning' => [
                    'code' => 'SEAPI_INFO-00001',
                    'type' => 'NOT_BLOCK_WARNING',
                    'description' => 'Sending may fail between non-public sender or recipient, if public ERDS is in use.',
                ],
                'additionalServiceOW' => ['activationDate' => '2025-02-01'],
            ],
            'recipient' => [
                'isPublic' => true,
                'entityName' => 'MINISTERSTWO POCZT I TELEGRAFÓW',
                'legalForm' => 'ORGAN ADMINISTRACJI RZĄDOWEJ',
                'officialIds' => self::NIP,
                'nipStatus' => 'ASSIGNED',
                'address' => [self::HQ_ADDRESS],
            ],
            'baeSearchData' => [
                [
                    'index' => 2,
                    'isPublic' => true,
                    'entityName' => 'MINISTERSTWO POCZT I TELEGRAFÓW (NAZWA ARCHIWALNA)',
                    'legalForm' => 'ORGAN ADMINISTRACJI RZĄDOWEJ',
                ],
                [
                    'index' => 1,
                    'isPublic' => true,
                    'entityName' => 'MINISTERSTWO POCZT I TELEGRAFÓW',
                    'legalForm' => 'ORGAN ADMINISTRACJI RZĄDOWEJ',
                    'officialIds' => self::NIP,
                    'nipStatus' => 'ASSIGNED',
                    'address' => [self::HQ_ADDRESS],
                ],
            ],
        ],
        'AE:PL-12345-67890-ABCDE-10' => [
            'eda' => [
                'recipientEda' => 'AE:PL-12345-67890-ABCDE-10',
                'isMainEda' => false,
                'assignmentDegree' => '3',
                'edaStatus' => 'ACTIVE',
                'designatedOperator' => true,
                'dateOfEnteringToBAE' => '2024-03-01',
            ],
            'recipient' => [
                'isPublic' => true,
                'entityName' => 'MINISTERSTWO POCZT I TELEGRAFÓW',
                'officialIds' => self::NIP,
            ],
            'baeSearchData' => [[
                'index' => 1,
                'isPublic' => true,
                'entityName' => 'MINISTERSTWO POCZT I TELEGRAFÓW',
                'officialIds' => self::NIP,
            ]],
        ],
        'AE:PL-12345-67890-ABCDE-11' => [
            'eda' => [
                'recipientEda' => 'AE:PL-12345-67890-ABCDE-11',
                'assignmentDegree' => '3',
                'edaStatus' => 'CLOSED_RECOVERABLE',
                'designatedOperator' => false,
                'dateOfEnteringToBAE' => '2024-01-15',
                'dateOfRemovalFromBAE' => '2025-06-30',
                'warning' => [
                    'code' => 'SEAPI_INFO-00004',
                    'type' => 'BLOCK_WARNING',
                    'description' => 'ADE is inactive and not supported by any ERDS service.',
                ],
            ],
            'recipient' => [
                'isPublic' => false,
                'entityName' => 'NAZWA PODMIOTU',
            ],
            'baeSearchData' => [[
                'index' => 1,
                'isPublic' => false,
                'entityName' => 'NAZWA PODMIOTU',
            ]],
        ],
    ];

    /** ADEs returned by every bae_search made by criteria (category, name, identifiers…). */
    public const CRITERIA_SEARCH_RESULT = ['AE:PL-98765-43210-SFVYC-19'];

    public const COUNTRIES = ['POLSKA', 'NIEMCY', 'CZECHY'];

    public const DIRECTORIES = [
        ['directoryId' => '1', 'name' => 'Odebrane', 'label' => 'INBOX', 'type' => 'predefined', 'numberOfUnreadMessages' => 1, 'total' => 1],
        ['directoryId' => '42', 'name' => 'Faktury', 'label' => 'MOJ_NOWY_FOLDER', 'type' => 'defined', 'numberOfUnreadMessages' => 0, 'total' => 1],
    ];

    public const SETTINGS = [
        'phoneNumber' => '+48000000000',
        'email' => 'skrzynka@example.test',
        'settings' => ['totalCapacity' => '1024', 'usedCapacity' => '12.5'],
    ];

    public const MESSAGES = [
        [
            'messageControlData' => [
                'opened' => false,
                'status' => 'Doręczona',
                'messageType' => 'Message',
                'daysToReceive' => 12,
                'labels' => [['label' => 'INBOX']],
            ],
            'messageMetadata' => [
                'from' => [
                    'eDeliveryAddress' => 'AE:PL-98765-43210-SFVYC-19',
                    'contributor' => ['companyName' => 'MINISTERSTWO POCZT I TELEGRAFÓW'],
                ],
                'to' => [
                    ['eDeliveryAddress' => self::MAILBOX, 'contributor' => ['firstName' => 'ADAM', 'lastName' => 'MALINOWSKI']],
                ],
                'subject' => 'Wezwanie',
                'messageId' => self::MESSAGE_ID,
                'threadId' => self::THREAD_ID,
                'timestamp' => '2017-07-21T17:32:28Z',
                'submissionDate' => '2017-07-21T17:32:28Z',
                'eventDate' => '2017-07-21T17:32:28Z',
                'shippingService' => 'electronic',
            ],
        ],
        [
            'messageControlData' => [
                'opened' => true,
                'status' => 'Odrzucona',
                'statusDescription' => 'Nieprawidłowy format pliku;Invalid file format',
                'messageType' => 'Message',
                'labels' => [['label' => 'SENT', 'messageLocation' => 'MOJ_NOWY_FOLDER']],
            ],
            'messageMetadata' => [
                'messageId' => self::SENT_MESSAGE_ID,
                'subject' => 'Odpowiedź',
                'shippingService' => 'hybrid',
            ],
        ],
    ];

    public const THREADS = [
        [
            'opened' => false,
            'threadId' => self::THREAD_ID,
            'caseIDs' => ['ZNAK-SPRAWY.1.2026'],
            'senders' => [['companyName' => 'MINISTERSTWO POCZT I TELEGRAFÓW']],
            'recipients' => [['firstName' => 'ADAM', 'lastName' => 'MALINOWSKI']],
            'firstMessageTitle' => 'Wezwanie',
            'firstMessageSubmissionDate' => '2017-07-21T17:32:28Z',
            'firstMessagEventDate' => '2017-07-21T17:32:28Z',
            'lastMessageSubmissionDate' => '2017-07-21T17:32:28Z',
            'lastMessagEventDate' => '2017-07-21T17:32:28Z',
            'messagesCount' => 1,
        ],
    ];

    public const EVIDENCES = [
        [
            'evidenceId' => 'EV-1',
            'messageId' => self::MESSAGE_ID,
            'type' => 'BP.OP',
            'createDate' => '2017-07-21T17:32:28Z',
            'eventDate' => '2017-07-21T17:32:28Z',
            'downloaded' => false,
            'from' => ['eDeliveryAddress' => 'AE:PL-98765-43210-SFVYC-19'],
            'to' => ['eDeliveryAddress' => self::MAILBOX],
        ],
        [
            'evidenceId' => 'EV-2',
            'messageId' => self::MESSAGE_ID,
            'type' => 'E1',
            'eventDate' => '2017-07-22T08:00:00Z',
            'downloaded' => true,
        ],
        [
            'evidenceId' => 'EV-3',
            'messageId' => self::SENT_MESSAGE_ID,
            'type' => 'A.2',
            'reasonId' => 'R-01',
            'reasonDetails' => ['Nieprawidłowy format pliku', 'Invalid file format'],
        ],
    ];

    // --- Receiving ---

    /** Not listed; opening it answers HTTP 202 (transfer pending) once, then returns {@see self::PENDING_MESSAGE}. */
    public const PENDING_MESSAGE_ID = '0A1B2C3D-0000-4000-8000-000000000002';

    public const PENDING_MESSAGE = [
        'messageControlData' => [
            'opened' => false,
            'status' => 'Doręczona',
            'messageType' => 'Message',
            'labels' => [['label' => 'INBOX']],
        ],
        'messageMetadata' => [
            'from' => [
                'eDeliveryAddress' => 'AE:PL-98765-43210-SFVYC-19',
                'contributor' => ['companyName' => 'MINISTERSTWO POCZT I TELEGRAFÓW'],
            ],
            'to' => [['eDeliveryAddress' => self::MAILBOX]],
            'subject' => 'Zawiadomienie',
            'messageId' => self::PENDING_MESSAGE_ID,
            'timestamp' => '2017-07-23T09:00:00Z',
            'shippingService' => 'electronic',
        ],
    ];

    /**
     * Parts returned only when a message is opened, keyed by message id: "textBody" and "attachments"
     * ("Attachment" without "file.file"; the content is taken from {@see self::ATTACHMENT_FILES}).
     */
    public const MESSAGE_CONTENTS = [
        self::MESSAGE_ID => [
            'textBody' => 'Syntetyczna treść wiadomości. Synthetic message body.',
            'attachments' => [
                [
                    'order' => 1,
                    'attachmentId' => 'ATT-1',
                    'file' => ['fileMetadata' => [
                        'fileId' => 'F-1',
                        'filename' => 'wezwanie.pdf',
                        'contentType' => 'application/pdf',
                        'size' => 81,
                        'description' => 'Wezwanie',
                    ]],
                ],
                [
                    'order' => 2,
                    'attachmentId' => 'ATT-2',
                    'file' => ['fileMetadata' => [
                        'fileId' => 'F-2',
                        'filename' => 'dane.xml',
                        'contentType' => 'application/xml',
                        'size' => 104,
                    ]],
                ],
            ],
        ],
        self::SENT_MESSAGE_ID => ['textBody' => 'Synthetic reply body.', 'attachments' => []],
        self::PENDING_MESSAGE_ID => ['textBody' => 'Synthetic body of a message delivered after a transfer.', 'attachments' => []],
    ];

    /** Attachment bytes, keyed by attachment id; "size" in {@see self::MESSAGE_CONTENTS} matches their length. */
    public const ATTACHMENT_FILES = [
        'ATT-1' => "%PDF-1.4\n% Synthetic attachment served by FakeEdoreczenia, not a document.\n%%EOF\n",
        'ATT-2' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!-- Synthetic attachment served by FakeEdoreczenia. -->\n<dane/>\n",
    ];

    /** Recipient that FakeEdoreczenia accepts when sending (ACTIVE in {@see self::ADDRESSES}). */
    public const ACTIVE_RECIPIENT = 'AE:PL-98765-43210-SFVYC-19';

    /** Recipient that FakeEdoreczenia rejects in the send task (CLOSED_RECOVERABLE in {@see self::ADDRESSES}). */
    public const INACTIVE_RECIPIENT = 'AE:PL-12345-67890-ABCDE-11';

    private function __construct() {}
}
