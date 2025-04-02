<?php

use Civi\Api4\FinancialAccount;

$depositFinancialAccountID = FinancialAccount::get(FALSE)
  ->addSelect('id')
  ->addWhere('name', '=', 'Deposit Bank Account')->execute()->first()['id'];

// These are indexed name => label
$instruments = [
  'ACH' => 'ACH',
  'Alipay' => 'Alipay',
  'Amazon' => 'Amazon',
  'Apple Pay' => 'Apple Pay',
  'Apple Pay: Visa' => 'Apple Pay: Visa',
  'Apple Pay: American Express' => 'Apple Pay: American Express',
  'Apple Pay: Apple' => 'Apple Pay: Apple',
  'Apple Pay: Carte Bleue' => 'Apple Pay: Carte Bleue',
  'Apple Pay: Discover' => 'Apple Pay: Discover',
  'Apple Pay: Visa Electron' => 'Apple Pay: Visa Electron',
  'Apple Pay: JCB' => 'Apple Pay: JCB',
  'Apple Pay: MasterCard' => 'Apple Pay: MasterCard',
  'Google Pay' => 'Google Pay',
  'Google Pay: American Express' => 'Google Pay: American Express',
  'Google Pay: Discover' => 'Google Pay: Discover',
  'Google Pay: Visa Electron' => 'Google Pay: Visa Electron',
  'Google Pay: Google' => 'Google Pay: Google',
  'Google Pay: MasterCard' => 'Google Pay: MasterCard',
  'Google Pay: Visa' => 'Google Pay: Visa',
  'Bitcoin' => 'Bitcoin',
  'Bank Transfer' => 'Bank Transfer',
  'Boleto' => 'Boleto',
  'Bpay' => 'Bpay',
  'Cash' => 'Cash',
  'Stock' => 'Stock',
  // Cashu exists on live but has no contributions.
  'Cashu' =>  'Cashu',
  'Check' => 'Check',
  'Citibank France' => 'Citibank France',
  'Citibank International' => 'Citibank International',
  'Credit Card' => 'Credit Card',
  'Credit Card: American Express' => 'Credit Card: American Express',
  // Credit Card: Argencard is a latin payment method. Only one contribution.
  'Credit Card: Argencard' => 'Credit Card: Argencard',
  // Credit Card: Bijenkorf exists on live but has no contributions.
  'Credit Card: Bijenkorf' => 'Credit Card: Bijenkorf',
  'Credit Card: Carte Bleue' => 'Credit Card: Carte Bleue',
  // Chilean credit card Credit Card: CMR Falabella exists on live but no contributions.
  'Credit Card: CMR Falabella' => 'Credit Card: CMR Falabella',
  'Credit Card: Discover' => 'Credit Card: Discover',
  'Credit Card: JCB' => 'Credit Card: JCB',
  // Credit Card: Laser exists on live but no contributions.
  'Credit Card: Laser' => 'Credit Card: Laser',
  // Credit Card: Maestro exists on live but no contributions.
  'Credit Card: Maestro' => 'Credit Card: Maestro',
  // Chilean credit card Credit Card: Magna exists on live but no contributions.
  'Credit Card: Magna' => 'Credit Card: Magna',
  'Credit Card: MasterCard' => 'Credit Card: MasterCard',
  // Chilean credit card Credit Card: Presto exists on live but no contributions
  'Credit Card: Presto' => 'Credit Card: Presto',
  // Credit Card: Solo exists on live but no contributions
  'Credit Card: Solo' => 'Credit Card: Solo',
  'Credit Card: Visa' => 'Credit Card: Visa',
  // Credit Card: Visa Beneficial exists on live but no contributions
  'Credit Card: Visa Beneficial' => 'Credit Card: Visa Beneficial',
  // Credit Card: Visa Electron exists on live but no contributions
  'Credit Card: Visa Electron' => 'Credit Card: Visa Electron',
  'Credit Card: Visa Debit' => 'Credit Card: Visa Debit',
  'Credit Card: MasterCard Debit' => 'Credit Card: MasterCard Debit',
  'Credit Card: Diners' => 'Credit Card: Diners',
  'Direct Debit' => 'Direct Debit',
  'Enets' => 'Enets',
  'EPS' => 'EPS',
  'iDeal' => 'iDeal',
  'SEPA Direct Debit' => 'SEPA Direct Debit',
  'JP Morgan EUR' => 'JP Morgan EUR',
  'Moneybookers' => 'Moneybookers',
  // Nordea exists on live but no contributions.
  'Nordea' => 'Nordea',
  'Paypal' => 'Paypal',
  // Does not exist on live.
  'Sofort' => 'Sofort',
  'Square Cash' => 'Square Cash',
  'Stripe' => 'Stripe',
  'Trilogy' => 'Trilogy',
  'Webmoney' => 'Webmoney',

  // Latin payment methods for Dlocal
  'Credit Card: Alia' => 'Credit Card: Alia',
  'Credit Card: Codenza' => 'Credit Card: Codenza',
  'Credit Card: Elo' => 'Credit Card: Elo',
  'Credit Card: HiperCard' => 'Credit Card: HiperCard',
  'Credit Card: MercadoLivre' => 'Credit Card: MercadoLivre',
  'Credit Card: Cabal' => 'Credit Card: Cabal',
  'Credit Card: Cabal Debit' => 'Credit Card: Cabal Debit',
  'Credit Card: Naranja' => 'Credit Card: Naranja',
  'Credit Card: Tarjeta Shopping' => 'Credit Card: Tarjeta Shopping',
  'Credit Card: Nativa' => 'Credit Card: Nativa',
  'Credit Card: Mach' => 'Credit Card: Mach',
  'Credit Card: Cencosud' => 'Credit Card: Cencosud',
  'Credit Card: Lider' => 'Credit Card: Lider',
  'Credit Card: OCA' => 'Credit Card: OCA',
  'Credit Card: Webpay' => 'Credit Card: Webpay',
  'Abitab' => 'Abitab',
  'Banamex' => 'Banamex',
  'Davivienda' => 'Davivienda',
  'Efecty' => 'Efecty',
  'OXXO' => 'OXXO',
  'Pago Efectivo' => 'Pago Efectivo',
  'Pago Facil' => 'Pago Facil',
  'Bank Transfer: ACH' => 'Bank Transfer: ACH',
  'Bank Transfer: Banco do Brasil' => 'Bank Transfer: Banco do Brasil',
  'Bank Transfer: Bancomer' => 'Bank Transfer: Bancomer',
  'Bank Transfer: BBVA' => 'Bank Transfer: BBVA',
  'Bank Transfer: BCP' => 'Bank Transfer: BCP',
  'Bank Transfer: Bradesco' => 'Bank Transfer: Bradesco',
  'Bank Transfer: Interbank' => 'Bank Transfer: Interbank',
  'Bank Transfer: Itau' => 'Bank Transfer: Itau',
  'Bank Transfer: MercadoPago' => 'Bank Transfer: MercadoPago',
  'Bank Transfer: Pix' => 'Bank Transfer: Pix',
  'Bank Transfer: PicPay' => 'Bank Transfer: PicPay',
  'Bank Transfer: PSE' => 'Bank Transfer: PSE',
  'Bank Transfer: Santander' => 'Bank Transfer: Santander',
  'Bank Transfer: Webpay' =>'Bank Transfer: Webpay',
  'Provencia Pagos' => 'Provencia Pagos',
  'Red Pagos' => 'Red Pagos',
  'Rapi Pago' => 'Rapi Pago',
  'Santander' => 'Santander',
  // India
  'Bank Transfer: Netbanking' => 'Bank Transfer: Netbanking',
  'Bank Transfer: PayTM Wallet' => 'Bank Transfer: PayTM Wallet',
  'Credit Card: RuPay' => 'Credit Card: RuPay',
  'Bank Transfer: UPI' => 'Bank Transfer: UPI',
  // Mobile payment methods
  'Venmo' => 'Venmo',
  'Vipps' => 'Vipps'
];

$paymentInstruments = [];
foreach ($instruments as $name => $instrument) {
  $paymentInstruments[] =   [
    'name' => 'OptionValue_'  . $instrument,
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'match' => ['option_group_id', 'name'],
      'values' => [
        'option_group_id.name' => 'payment_instrument',
        'label' => $instrument,
        'financial_account_id' => $depositFinancialAccountID,
        'name' => $name,
        'is_active' => TRUE,
      ],
    ],
  ];
}
return $paymentInstruments;
