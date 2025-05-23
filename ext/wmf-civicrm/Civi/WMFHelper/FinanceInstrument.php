<?php

namespace Civi\WMFHelper;

use Civi;
use Civi\WMFException\WMFException;

class FinanceInstrument {

  public const APPLE_PAY_SUBMETHOD_LIST = [
    'apple' => 'Apple',
    'visa' => 'Visa',
    'amex' => 'American Express',
    'cb' => 'Carte Bleue',
    'discover' => 'Discover',
    'visa-electron' => 'Visa Electron',
    'jcb' => 'JCB',
    'mc' => 'MasterCard'
  ];

  public const GOOGLE_PAY_SUBMETHOD_LIST = [
    'google' => 'Google',
    'visa' => 'Visa',
    'amex' => 'American Express',
    'discover' => 'Discover',
    'visa-electron' => 'Visa Electron',
    'mc' => 'MasterCard'
  ];

  public const BT_SUBMETHOD_LIST = [
    'ach' => 'ACH', // Worldwide, via DLocal
    'bancomer' => 'Bancomer', //Mexico
    'banco_do_brasil' => 'Banco do Brasil', // Brazil
    'bbva' => 'BBVA', // Peru
    'bcp' => 'BCP', // Peru
    'bradesco' => 'Bradesco', // Brazil
    'interbank' => 'Interbank', // Peru
    'itau' => 'Itau', // Brazil
    'mercadopago' => 'MercadoPago', // Latin America
    'netbanking' => 'Netbanking', // India
    'paytmwallet' => 'PayTM Wallet', // India
    'picpay' => 'PicPay', // Brazil
    'pix' => 'Pix', // Brazil
    'pse' => 'PSE', // Colombia
    'santander' => 'Santander', // Brazil
    'santander_rio' => 'Santander', // Argentina (same bank as above)
    'stitch' => 'Stitch', // South Africa
    'upi' => 'UPI', // India
    'webpay_bt' => 'Webpay' // Chile
  ];

  public const CARD_SUBMETHOD_LIST = [
    'alia' => 'Alia', // Ecuador
    'amex' => 'American Express',
    'argen' => 'Argencard',
    'bij' => 'Bijenkorf',
    'cabal' => 'Cabal',
    'cabal-debit' => 'Cabal Debit',
    'carnet_debit' => 'Carnet Debit', // Mexico
    'cb' => 'Carte Bleue',
    'cencosud' => 'Cencosud', // AR
    'cmr' => 'CMR Falabella',
    'codenza' => 'Codenza', // Colombia
    'diners' => 'Diners',
    'discover' => 'Discover',
    'elo' => 'Elo',
    'elo-debit' => 'Elo Debit',
    'hiper' => 'HiperCard',
    'jcb' => 'JCB',
    'laser' => 'Laser',
    'lider' => 'Lider',
    'mach' => 'Mach', // Chile
    'maestro' => 'Maestro',
    'magna' => 'Magna',
    'mc' => 'MasterCard',
    'mc-debit' => 'MasterCard Debit',
    'mercadolivre' => 'MercadoLivre',
    'naranja' => 'Naranja',
    'nativa' => 'Nativa',
    'oca' => 'OCA',
    'presto' => 'Presto',
    'rupay' => 'RuPay', // India
    'shopping' => 'Tarjeta Shopping',
    'solo' => 'Solo',
    'visa' => 'Visa',
    'visa-beneficial' => 'Visa Beneficial',
    'visa-debit' => 'Visa Debit',
    'visa-electron' => 'Visa Electron',
    'webpay' => 'Webpay',
  ];

  public const EW_SUBMETHOD_LIST = [
    'ew_paypal' => 'Paypal',
    'ew_webmoney' => 'Webmoney',
    'ew_moneybookers' => 'Moneybookers',
    'ew_cashu' => 'Cashu',
    'ew_yandex' => 'Yandex',
    'ew_alipay' => 'Alipay',
    'vipps' => 'Vipps',
  ];

  public const OBT_SUBMETHOD_LIST = [
    'bpay' => 'Bpay',
  ];

  public const RTBT_SUBMETHOD_LIST = [
    'rtbt_nordea_sweden' => 'Nordea',
    'rtbt_ideal' => 'iDeal',
    'rtbt_enets' => 'Enets',
    'rtbt_sofortuberweisung' => 'Sofort',
    'rtbt_eps' => 'EPS',
    'sepadirectdebit' => 'SEPA Direct Debit',
  ];

  public const CASH_SUBMETHOD_LIST = [
    'cash_abitab' => 'Abitab',
    'cash_boleto' => 'Boleto',
    'cash_banamex' => 'Banamex',
    'cash_davivienda' => 'Davivienda',
    'cash_efecty' => 'Efecty',
    'cash_oxxo' => 'OXXO',
    'cash_pago_efectivo' => 'Pago Efectivo',
    'cash_pago_facil' => 'Pago Facil',
    'cash_provencia_pagos' => 'Provencia Pagos',
    'cash_red_pagos' => 'Red Pagos',
    'cash_rapipago' => 'Rapi Pago',
    'cash_santander' => 'Santander'
  ];

  /**
   * Determines which civi-only payment instrument is appropriate for the current
   * message, and returns the civi payment instrument's human-readable display
   * string (if it exists).
   *
   * TODO lookup table
   *
   * @return string payment instrument label
   */
  public static function getPaymentInstrument(array $msg): ?string {
    $payment_instrument = null;
    $payment_method = null;
    if (isset($msg['raw_payment_instrument'])) {
      return $msg['raw_payment_instrument'];
    }
    if (array_key_exists('payment_method', $msg) && trim($msg['payment_method']) !== '') {
      $payment_method = strtolower($msg['payment_method']);
    }

    if ($payment_method) {
      $payment_submethod = null;
      if (isset($msg['payment_submethod'])){
        $payment_submethod = strtolower($msg['payment_submethod']);
      }
      switch ($payment_method) {
        case 'apple':
          $payment_instrument = 'Apple Pay';
          if (!empty($payment_submethod)
            && array_key_exists($payment_submethod, self::APPLE_PAY_SUBMETHOD_LIST)) {
            $payment_instrument .= ': ' . self::APPLE_PAY_SUBMETHOD_LIST[$payment_submethod];
          }
          break;
        case 'google':
          $payment_instrument = 'Google Pay';
          if (!empty($payment_submethod)
            && array_key_exists($payment_submethod, self::GOOGLE_PAY_SUBMETHOD_LIST)) {
            $payment_instrument .= ': ' . self::GOOGLE_PAY_SUBMETHOD_LIST[$payment_submethod];
          }
          break;
        case 'check':
          $payment_instrument = 'Check';
          break;
        case 'bt':
          $payment_instrument = 'Bank Transfer';
          if (!empty($payment_submethod)
            && array_key_exists($payment_submethod, self::BT_SUBMETHOD_LIST)) {
            $payment_instrument .= ': ' . self::BT_SUBMETHOD_LIST[$payment_submethod];
          }
          break;
        case 'cc':
          $payment_instrument = 'Credit Card';
          if (empty($msg['payment_submethod'])) {
            Civi::log('wmf')->warning('wmf_civicrm: No credit card submethod given');
            break;
          }
          if (!empty($payment_submethod)
            && array_key_exists($payment_submethod, self::CARD_SUBMETHOD_LIST)) {
            $payment_instrument .= ': ' . self::CARD_SUBMETHOD_LIST[$payment_submethod];
          }
          break;
        case 'dd':
          if (isset($msg['payment_submethod']) && $msg['payment_submethod'] === 'ach') {
            $payment_instrument = 'ACH';
            break;
          }
          $payment_instrument = 'Direct Debit';
          break;
        case 'paypal':
          $payment_instrument = 'Paypal';
          break;
        case 'venmo':
          $payment_instrument = 'Venmo';
          break;
        case 'eft':
          $payment_instrument = 'EFT';
          break;
        case 'ew':
          if (!empty($payment_submethod)
            && array_key_exists($payment_submethod, self::EW_SUBMETHOD_LIST)) {
            $payment_instrument = self::EW_SUBMETHOD_LIST[$payment_submethod];
          }
          break;
        case 'obt':
          if (!empty($payment_submethod)
            && array_key_exists($payment_submethod, self::OBT_SUBMETHOD_LIST)) {
            $payment_instrument = self::OBT_SUBMETHOD_LIST[$payment_submethod];
          }
          break;
        case 'rtbt':
          if (!empty($payment_submethod)
            && array_key_exists($payment_submethod, self::RTBT_SUBMETHOD_LIST)) {
            $payment_instrument = self::RTBT_SUBMETHOD_LIST[$payment_submethod];
          }
          break;
        case 'stock':
          $payment_instrument = 'Stock';
          break;
        case 'cash':
          $payment_instrument = 'Cash';
          if (empty($msg['payment_submethod'])) {
            Civi::log('wmf')->warning('wmf_civicrm: No cash submethod given');
            break;
          }
          if (!empty($payment_submethod)
            && array_key_exists($payment_submethod, self::CASH_SUBMETHOD_LIST)) {
            $payment_instrument = self::CASH_SUBMETHOD_LIST[$payment_submethod];
          }
          break;
        case 'trilogy':
          $payment_instrument = 'Trilogy';
          break;
      }
    }
    if (!$payment_instrument
      && array_key_exists('gateway', $msg)
    ) {
      switch (strtolower($msg['gateway'])) {
        case 'amazon':
          $payment_instrument = 'Amazon';
          if ($payment_method && $payment_method !== 'amazon') {
            Civi::log('wmf')->debug('payment_method constraint violated: gateway Amazon, but method=@method ; gateway_txn_id=@id', [
              '@method' => $msg['payment_method'],
              '@id' => $msg['gateway_txn_id'],
            ]);
          }
          break;
        case 'paypal':
        case 'paypal_ec':
          // These PayPal flows are distinct gateway classes, but are
          // recorded together.  They might share an account, although the
          // configuration will have to be broken up across gateway globals.
          $payment_instrument = 'Paypal';
          // FIXME: Case is spelled "PayPal", but existing records must be
          // migrated when we do that.

          // Validate method if provided.
          if ($payment_method && $payment_method !== 'paypal') {
            Civi::log('wmf')->debug('payment_method constraint violated: gateway Paypal, but method=@method ; gateway_txn_id=@id', [
              '@method' => $msg['payment_method'],
              '@id' => $msg['gateway_txn_id'],
            ]);
          }
          break;
        case 'braintree':
          $payment_instrument = 'Venmo'; // since we only turn on venmo for braintree for no, so use Venmo as default method
          if ($payment_method && !in_array($payment_method, ['venmo', 'paypal'])) {
            Civi::log('wmf')->debug('payment_method constraint violated: gateway Braintree, but method=@method ; gateway_txn_id=@id', [
              '@method' => $msg['payment_method'],
              '@id' => $msg['gateway_txn_id'],
            ]);
          }
          break;
        case 'square':
          $payment_instrument = 'Square Cash';
          if ($payment_method && $payment_method !== 'square') {
            Civi::log('wmf')->debug('payment_method constraint violated: gateway Square, but method=@method ; gateway_txn_id=@id', [
              '@method' => $msg['payment_method'],
              '@id' => $msg['gateway_txn_id'],
            ]);
          }
          break;
        case 'trilogy':
          $payment_instrument = 'Trilogy';
          if ($payment_method && $payment_method !== 'trilogy') {
            Civi::log('wmf')->debug('payment_method constraint violated: gateway Trilogy, but method=@method ; gateway_txn_id=@id', [
              '@method' => $msg['payment_method'],
              '@id' => $msg['gateway_txn_id'],
            ]);
          }
          break;
      }
    }
    //I was going to check to make sure the target gateway was a real thing, but: Hello, overhead. No.
    return $payment_instrument ?? $msg['payment_method'] ?? null;
  }
}
