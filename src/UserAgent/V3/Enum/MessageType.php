<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Enum;

/**
 * "MessageControlData.messageType".
 */
enum MessageType: string
{
    case Message = 'Message';
    case Evidence = 'Evidence';
    /** Notification about an incoming message (zawiadomienie o nadejściu wiadomości). */
    case Stub = 'Stub';
    /** Grouping element for messages sent in mass mode. */
    case MassMessageGrouped = 'massMessageGrouped';
}
