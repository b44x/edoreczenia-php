<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4\Enum;

/**
 * "ReferenceRegistryEnum": registry that issued an official identifier.
 */
enum OfficialIdType: string
{
    case Pesel = 'pesel';
    case Regon = 'regon';
    case Krs = 'krs';
    case Nip = 'nip';
    /** Identifier issued by another EU member state. */
    case Eu = 'ue';
    /** Identifier from the public entities catalogue (Katalog Podmiotów Publicznych). */
    case KppId = 'kppId';
}
