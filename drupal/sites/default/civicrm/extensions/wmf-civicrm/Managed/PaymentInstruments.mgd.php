<?php

use Civi\Api4\FinancialAccount;

$depositFinancialAccountID = FinancialAccount::get(FALSE)
  ->addSelect('id')
  ->addWhere('name', '=', 'Deposit Bank Account')->execute()->first()['id'];

$instruments = [
  'Alipay',
  'Amazon',
  'Apple Pay',
  'Apple Pay: Visa',
  'Apple Pay: American Express',
  'Apple Pay: Apple',
  'Apple Pay: Carte Bleue',
  'Apple Pay: Discover',
  'Apple Pay: Visa Electron',
  'Apple Pay: JCB',
  'Apple Pay: MasterCard',
  'Google Pay',
  'Google Pay: American Express',
  'Google Pay: Discover',
  'Google Pay: Visa Electron',
  'Google Pay: Google',
  'Google Pay: MasterCard',
  'Google Pay: Visa',
  'Bitcoin',
  'Bank Transfer',
  'Boleto',
  'Bpay',
  'Cash',
  // Cashu exists on live but has no contributions.
  'Cashu',
  'Check',
  'Citibank France',
  'Citibank International',

  'Credit Card',
  'Credit Card: American Express',
  // Credit Card: Argencard is a latin payment method. Only one contribution.
  'Credit Card: Argencard',
  // Credit Card: Bijenkorf exists on live but has no contributions.
  'Credit Card: Bijenkorf',
  'Credit Card: Carte Bleue',
  // Chilean credit card Credit Card: CMR Falabella exists on live but no contributions.
  'Credit Card: CMR Falabella',
  'Credit Card: Discover',
  'Credit Card: JCB',
  // Credit Card: Laser exists on live but no contributions.
  'Credit Card: Laser',
  // Credit Card: Maestro exists on live but no contributions.
  'Credit Card: Maestro',
  // Chilean credit card Credit Card: Magna exists on live but no contributions.
  'Credit Card: Magna',
  'Credit Card: MasterCard',
  // Chilean credit card Credit Card: Presto exists on live but no contributions
  'Credit Card: Presto',
  // Credit Card: Solo exists on live but no contributions
  'Credit Card: Solo',
  'Credit Card: Visa',
  // Credit Card: Visa Beneficial exists on live but no contributions
  'Credit Card: Visa Beneficial',
  // Credit Card: Visa Electron exists on live but no contributions
  'Credit Card: Visa Electron',
  'Credit Card: Visa Debit',
  'Credit Card: MasterCard Debit',
  'Credit Card: Diners',
  'Direct Debit',
  'Enets',
  'EPS',
  'iDeal',
  'JP Morgan EUR',
  'Moneybookers',
  // Nordea exists on live but no contributions.
  'Nordea',
  'Paypal',
  // Does not exist on live.
  'Sofort',
  'Square Cash',
  'Stripe',
  'Trilogy',
  'Webmoney',

  // Latin payment methods for Astropay
  'Credit Card: Alia',
  'Credit Card: Codenza',
  'Credit Card: Elo',
  'Credit Card: HiperCard',
  'Credit Card: MercadoLivre',
  'Credit Card: Cabal',
  'Credit Card: Cabal Debit',
  'Credit Card: Naranja',
  'Credit Card: Tarjeta Shopping',
  'Credit Card: Nativa',
  'Credit Card: Cencosud',
  'Credit Card: Lider',
  'Credit Card: OCA',
  'Credit Card: Webpay',
  'Abitab',
  'Banamex',
  'Bancomer',
  'Davivienda',
  'Efecty',
  'OXXO',
  'Pago Efectivo',
  'Pago Facil',
  'Bank Transfer: ACH',
  'Bank Transfer: Banco do Brasil',
  'Bank Transfer: BBVA',
  'Bank Transfer: BCP',
  'Bank Transfer: Bradesco',
  'Bank Transfer: Interbank',
  'Bank Transfer: Itau',
  'Bank Transfer: MercadoPago',
  'Bank Transfer: Pix',
  'Bank Transfer: PicPay',
  'Bank Transfer: PSE',
  'Bank Transfer: Santander',
  'Bank Transfer: Webpay',
  'Provencia Pagos',
  'Red Pagos',
  'Rapi Pago',
  'Santander',
  // India
  'Bank Transfer: Netbanking',
  'Bank Transfer: PayTM Wallet',
  'Credit Card: RuPay',
  'Bank Transfer: UPI',
];

$paymentInstruments = [];
foreach ($instruments as $instrument) {
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
        'name' => $instrument,
        'is_active' => TRUE,
      ],
    ],
  ];
}
return $paymentInstruments;
