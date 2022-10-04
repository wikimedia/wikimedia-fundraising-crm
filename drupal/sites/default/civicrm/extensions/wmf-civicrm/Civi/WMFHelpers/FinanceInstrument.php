<?php

namespace Civi\WMFHelpers;

class FinanceInstrument {
  public const APPLE_PAY_SUBMETHOD_LIST = [
    'apple' => 'Apple',
    'visa' => 'Visa',
    'amex' =>  'American Express',
    'cb' => 'Carte Bleue',
    'discover' => 'Discover',
    'visa-electron' => 'Visa Electron',
    'jcb' => 'JCB',
    'mc' => 'MasterCard'
  ];  

  public const GOOGLE_PAY_SUBMETHOD_LIST = [
    'google' => 'Google',
    'visa' => 'Visa',
    'amex' =>  'American Express',
    'discover' => 'Discover',
    'visa-electron' => 'Visa Electron',
    'mc' => 'MasterCard'
  ]; 

  public const BT_SUBMETHOD_LIST = [
    'ach' => 'ACH', // Worldwide, via DLocal
    'banco_do_brasil' => 'Banco do Brasil', // Brazil
    'bbva' =>  'BBVA', // Peru
    'bcp' => 'BCP', // Peru
    'bradesco' => 'Bradesco', // Brazil
    'interbank' => 'Interbank', // Peru
    'itau' => 'Itau', // Brazil
    'mercadopago' => 'MercadoPago', // Latin America
    'netbanking' =>  'Netbanking', // India
    'paytmwallet' => 'PayTM Wallet', // India
    'picpay' => 'PicPay', // Brazil
    'pix' => 'Pix', // Brazil
    'pse' =>  'PSE', // Colombia
    'santander' => 'Santander', // Brazil
    'santander_rio' => 'Santander', // Argentina (same bank as above)
    'upi' => 'UPI', // India
    'webpay_bt' => 'Webpay' // Chile
  ]; 

  public const CARD_SUBMETHOD_LIST = [
    'visa' => 'Visa',
    'visa-beneficial' => 'Visa Beneficial',
    'visa-electron' => 'Visa Electron',
    'visa-debit' => 'Visa Debit',
    'mc' => 'MasterCard',
    'mc-debit' => 'MasterCard Debit',
    'amex' =>  'American Express',
    'maestro' =>  'Maestro',
    'solo' =>  'Solo',
    'laser' =>  'Laser',
    'jcb' => 'JCB',
    'discover' => 'Discover',
    'cb' => 'Carte Bleue',
    'cmr' => 'CMR Falabella',
    'diners' => 'Diners',
    'elo' => 'Elo',
    'hiper' => 'HiperCard',
    'magna' => 'Magna',
    'mercadolivre' => 'MercadoLivre',
    'presto' => 'Presto',
    'cabal' => 'Cabal',
    'cabal-debit' => 'Cabal Debit',
    'naranja' => 'Naranja',
    'shopping' => 'Tarjeta Shopping',
    'nativa' => 'Nativa',
    'cencosud' => 'Cencosud',
    'argen' => 'Argencard',
    'webpay' => 'Webpay',
    'bij' => 'Bijenkorf',
    'lider' => 'Lider',
    'oca' => 'OCA',
    'rupay' => 'RuPay', // India
    'alia' => 'Alia', // Ecuador
    'codenza' => 'Codenza' // Colombia
  ]; 
 
  public const EW_SUBMETHOD_LIST = [
    'ew_paypal' => 'Paypal',
    'ew_webmoney' => 'Webmoney',
    'ew_moneybookers' => 'Moneybookers',
    'ew_cashu' => 'Cashu',
    'ew_yandex' => 'Yandex',
    'ew_alipay' => 'Alipay',
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
  ]; 

  public const CASH_SUBMETHOD_LIST = [
    'cash_abitab' => 'Abitab',
    'cash_boleto' => 'Boleto',
    'cash_banamex' =>  'Banamex',
    'cash_bancomer' => 'Bancomer',
    'cash_davivienda' => 'Davivienda',
    'cash_efecty' => 'Efecty',
    'cash_oxxo' => 'OXXO',
    'cash_pago_efectivo' => 'Pago Efectivo',
    'cash_pago_facil' =>  'Pago Facil',
    'cash_provencia_pagos' => 'Provencia Pagos',
    'cash_red_pagos' => 'Red Pagos',
    'cash_rapipago' => 'Rapi Pago',
    'cash_santander' =>  'Santander'
  ]; 

}