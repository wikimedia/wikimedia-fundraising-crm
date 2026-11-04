<?php

namespace Civi\WMFBatch;

/**
 * GL account/location constants shared by batch journal generation -
 * used by both WMFAudit.GenerateBatch (settlement batches) and
 * WMFAudit.GenerateAdjustmentBatch (single-contribution corrections).
 */
final class JournalGLConstants {

  // Balancing accounts for a transfer between the wmf (foundation) and
  // endowment instances - one const per instance per direction, for
  // transparency about which is in play, but the values are shared: each
  // instance always uses the same balancing account for the transfer,
  // whichever direction it's moving.
  const GL_BALANCING_ACCOUNT_WMF_TO_ENDOWMENT = 27375;
  const GL_BALANCING_ACCOUNT_ENDOWMENT_INSTANCE = 11110;
  const GL_BALANCING_ACCOUNT_ENDOWMENT_TO_WMF = 11110;
  const GL_BALANCING_ACCOUNT_WMF_INSTANCE = 27375;

  const LOCATION_ID_WMF = '100-WMF';
  const LOCATION_ID_ENDOWMENT = '300-END';

  // Default GL account for a fee row (see getFeeAccountCodeClause()).
  const GL_FEE_DEFAULT = 60917;

  const GL_ENDOWMENT_MEMO_SUFFIX = 'Earmarked for Endowment from WMF';

  /**
   * The GL accounts that are major gifts.
   *
   * These take the major gifts cost centre rather than the online
   * fundraising one - see getDeptIDClause(). The account a row is coded to
   * is what decides that, not the gift's own is_major_gift flag, so that a
   * row coded to a major gifts account is costed as one however it got
   * there.
   */
  const GL_MAJOR_GIFTS_ACCOUNTS = [
    // Unrestricted - Large ($10K+) & Assigned.
    43485,
    // Unrestricted - Donor Advised Funds <$10K.
    43486,
    // Unrestricted - <$10K.
    43487,
    // Unrestricted - Workplace Giving.
    43488,
    // Unrestricted - Alternative and Other.
    43489,
    self::GL_MAJOR_GIFTS_RESTRICTED,
  ];

  /**
   * The one major gifts account that is restricted funding.
   *
   * getAccountClause() reaches it when the gift's fund is restricted, that
   * taking precedence over the unrestricted codes (T409994). Endowment
   * journals also treat every major gift as restricted whatever it was on
   * the foundation side - see writeCrossInstanceTransfer().
   */
  const GL_MAJOR_GIFTS_RESTRICTED = 43428;

  const BALANCING_ACCOUNT_HARD_CODED = 'determined_in_code';

  /**
   * Reversal/balancing account codes, by currency, for gateways whose
   * GatewayAccount.balancing_account_foundation is BALANCING_ACCOUNT_HARD_CODED
   * (varies by currency - not yet representable as a single value on the
   * GatewayAccount record, see GatewayAccount_paypal in GatewayAccount.mgd.php).
   */
  const PAYPAL_REVERSAL_ACCOUNT_CODES_BY_CURRENCY = [
    'USD' => 10900,
    'CAD' => 10901,
    'EUR' => 10902,
    'GBP' => 10903,
    'JPY' => 10904,
    'AUD' => 10906,
    'NZD' => 10907,
    'HKD' => 10908,
    'SGD' => 10909,
    'CHF' => 10910,
    'SEK' => 10911,
    'NOK' => 10912,
    'DKK' => 10913,
    'PLN' => 10914,
    'HUF' => 10915,
    'CZK' => 10916,
    'BRL' => 10917,
    'ILS' => 10918,
    'MXN' => 10919,
    'PHP' => 10920,
    'THB' => 10921,
    'TWD' => 10922,
    'ZAR' => 10925,
    'KRW' => 10926,
  ];

  /**
   * Gateway-keyed override of GL_FEE_DEFAULT, split by whether the batch's
   * own gateway account is a foundation or endowment account.
   *
   * Only CCK (Chisholm Chisholm & Kilpatrick, who process bequests) need this today.
   */
  const GATEWAY_ACCOUNTS_FEE_GL_CODES = [
    'cck' => 60931,
    'cckendowment' => 60932,
  ];

}
