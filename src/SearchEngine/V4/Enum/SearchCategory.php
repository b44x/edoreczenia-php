<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4\Enum;

/**
 * SE API v4 "SearchCategoryEnum" (rule R.SEAPI.01).
 *
 * Only PUBLIC_INSTITUTION, COMPANY and ORGANISATION may be combined in one request;
 * the server enforces this (SEAPI-00004).
 */
enum SearchCategory: string
{
    case Individual = 'INDIVIDUAL';
    case Advocate = 'ADVOCATE';
    case LegalAdvisor = 'LEGAL_ADVISOR';
    case TaxAdvisor = 'TAX_ADVISOR';
    case RestructuringAdvisor = 'RESTRUCTURING_ADVISOR';
    case PatentAttorney = 'PATENT_ATTORNEY';
    case Notary = 'NOTARY';
    case CounsellorOfGcrp = 'COUNSELLOR_OF_GCRP';
    case PublicInstitution = 'PUBLIC_INSTITUTION';
    case CourtEnforcementOfficer = 'COURT_ENFORCEMENT_OFFICER';
    case Company = 'COMPANY';
    case Organisation = 'ORGANISATION';
}
