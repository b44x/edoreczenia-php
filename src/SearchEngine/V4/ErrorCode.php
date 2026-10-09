<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4;

/**
 * Documented SE API v4 error codes (technical design v1.08, section 4.2).
 *
 * Use with {@see \B4x\Edoreczenia\Exception\ApiException::hasErrorCode()}.
 */
final class ErrorCode
{
    /** 412 – the sender ADE is not active. */
    public const SENDER_INACTIVE = 'SEAPI-00001';
    /** 400 – invalid or missing search category. */
    public const INVALID_CATEGORY = 'SEAPI-00003';
    /** 400 – categories that cannot be combined. */
    public const INVALID_CATEGORY_COMBINATION = 'SEAPI-00004';
    /** 400 – attributes from data sets 1 and 2 mixed. */
    public const MIXED_DATA_SETS = 'SEAPI-00005';
    /** 400 – required parameters missing. */
    public const MISSING_ARGUMENTS = 'SEAPI-00006';
    /** 400 – no complete data subset provided. */
    public const INCOMPLETE_SUBSET = 'SEAPI-00007';
    /** 400 – attribute not allowed for the category. */
    public const UNEXPECTED_ARGUMENT = 'SEAPI-00008';
    /** 400 – PESEL/EU/NIP/KRS/REGON with invalid length or characters. */
    public const INVALID_IDENTIFIER = 'SEAPI-00009';
    /** 404 – no addresses match the criteria. */
    public const NO_RESULTS = 'SEAPI-00010';
    /** 404 – a non-public entity may not search for natural persons. */
    public const CATEGORY_NOT_PERMITTED = 'SEAPI-00011';
    /** 500 – search service unavailable. */
    public const SERVICE_UNAVAILABLE = 'SEAPI-00012';
    /** 504 – timeout in the central search engine. */
    public const TIMEOUT = 'SEAPI-00013';
    /** 404 – "limit" outside the allowed range. */
    public const LIMIT_OUT_OF_RANGE = 'SEAPI-00014';
    /** 404 – "offset" not lower than the number of results. */
    public const OFFSET_OUT_OF_RANGE = 'SEAPI-00015';
    /** 504 – timeout in the designated operator's search proxy. */
    public const PROXY_TIMEOUT = 'SEAPI-00016';
    /** 401 – not authorized. */
    public const NOT_AUTHORIZED = 'SEAPI-00017';
    /** 401 – keystore loading error on the server side. */
    public const KEYSTORE_ERROR = 'SEAPI-00018';
    /** 400 – ADE checksum is invalid. */
    public const INVALID_ADDRESS_CHECKSUM = 'SEAPI-00019';
    /** 400 – entity name shorter than 2 characters. */
    public const ENTITY_NAME_TOO_SHORT = 'SEAPI-00020';
    /** 400 – own-data lookup requires recipient ADE equal to the sender ADE. */
    public const ADDRESS_MISMATCH = 'SEAPI-00021';
    /** 400 – postalCode or city shorter than 2 characters. */
    public const ADDRESS_FIELD_TOO_SHORT = 'SEAPI-00022';
    /** 400 – criteria passed while searching by recipient ADE only. */
    public const CRITERIA_NOT_ALLOWED = 'SEAPI-00023';
    /** 400 – number of recipient ADEs outside the allowed range. */
    public const RECIPIENT_COUNT_OUT_OF_RANGE = 'SEAPI-00024';
    /** 400 – use the own-data operation to look up the sender's own ADE. */
    public const USE_OWN_DATA_OPERATION = 'SEAPI-00025';

    private function __construct() {}
}
