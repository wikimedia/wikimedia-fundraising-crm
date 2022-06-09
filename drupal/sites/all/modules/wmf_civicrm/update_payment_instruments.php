<?php


/**
 * Add any missing payment instruments from our master array.
 */
function wmf_install_add_missing_payment_instruments() {
  civicrm_initialize();
  wmf_civicrm_create_option_values('payment_instrument', wmf_install_get_payment_instruments());
  $depositFinancialAccountID = civicrm_api3('FinancialAccount', 'getvalue', [
    'return' => 'id',
    'name' => 'Deposit Bank Account',
  ]);
  if (!$depositFinancialAccountID) {
    throw new Exception("Couldn't find Deposit Bank Account");
  }
  foreach(wmf_install_get_payment_instruments() as $instrument) {
    $result = civicrm_api3('OptionValue', 'getSingle', [
      'option_group_id' => 'payment_instrument',
      'name' => $instrument,
      'return' => 'id',
    ]);
    $params = [
      'entity_id' => $result['id'],
      'entity_table' => 'civicrm_option_value',
      'account_relationship' => 'Asset Account is',
    ];
    $count = civicrm_api3('EntityFinancialAccount', 'getCount', $params);
    if ($count === 0) {
      $params['financial_account_id'] = $depositFinancialAccountID;
      civicrm_api3('EntityFinancialAccount', 'create', $params);
    }
  }
}

/**
 * Get an array of payment instruments.
 *
 * @return array
 */
function wmf_install_get_payment_instruments() {
  return array(
    // ActBlue
    'Alipay',
    'Amazon',
    'Apple Pay',
    'Google Pay',
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
    'Credit Card: Elo',
    'Credit Card: HiperCard',
    'Credit Card: MercadoLivre',
    'Credit Card: Cabal',
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
    'Bank Transfer: Pix',
    'Provencia Pagos',
    'Red Pagos',
    'Rapi Pago',
    'Santander',
    // India
    'Bank Transfer: Netbanking',
    'Bank Transfer: PayTM Wallet',
    'Credit Card: RuPay',
    'Bank Transfer: UPI'
  );
}
