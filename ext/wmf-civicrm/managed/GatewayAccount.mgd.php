<?php

use Civi\Api4\Action\WMFAudit\GenerateBatch;

return [
  [
    'name' => 'GatewayAccount_adyen',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'adyen',
        'label' => 'Adyen',
        'is_endowment' => FALSE,
        'vendor_code_foundation' => 'V01670',
        'vendor_code_endowment' => 'V04988',
        'balancing_account_foundation' => '11250',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'GatewayAccount_braintree',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'braintree',
        'label' => 'Braintree',
        'is_endowment' => FALSE,
        'vendor_code_foundation' => 'V05089',
        'vendor_code_endowment' => 'V04991',
        'balancing_account_foundation' => '11250',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'GatewayAccount_paypal',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'paypal',
        'label' => 'PayPal',
        'is_endowment' => FALSE,
        'vendor_code_foundation' => 'V00282',
        'vendor_code_endowment' => 'V04989',
        'balancing_account_foundation' => GenerateBatch::BALANCING_ACCOUNT_HARD_CODED,
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'GatewayAccount_paypalfrup',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'paypalfrup',
        'label' => 'PayPal FundraiseUp',
        'is_endowment' => FALSE,
        'vendor_code_foundation' => 'V05040',
        'vendor_code_endowment' => 'V05001',
        'balancing_account_foundation' => '10927',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'GatewayAccount_dlocal',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'dlocal',
        'label' => 'dLocal',
        'is_endowment' => FALSE,
        'vendor_code_foundation' => 'V04134',
        'vendor_code_endowment' => 'V04990',
        'balancing_account_foundation' => '10835',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'GatewayAccount_engage',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'engage',
        'label' => 'Engage (Foundation)',
        'is_endowment' => FALSE,
        'vendor_code_foundation' => 'V01948',
        'vendor_code_endowment' => 'V04993',
        'balancing_account_foundation' => '11250',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'GatewayAccount_engage_endowment',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'engageendowment',
        'label' => 'Engage (Endowment)',
        'is_endowment' => TRUE,
        'vendor_code_foundation' => 'V01948',
        'vendor_code_endowment' => 'V04993',
        'balancing_account_foundation' => '11250',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'GatewayAccount_stripe',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'stripe',
        'label' => 'Stripe',
        'is_endowment' => FALSE,
        'vendor_code_foundation' => 'V04137',
        'vendor_code_endowment' => 'V04994',
        'balancing_account_foundation' => '11250',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'GatewayAccount_stripemg',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'stripemg',
        'label' => 'Stripe Major Gifts',
        'is_endowment' => FALSE,
        'vendor_code_foundation' => 'V04137',
        'vendor_code_endowment' => 'V04994',
        'balancing_account_foundation' => '11250',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'GatewayAccount_trustly',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'trustly',
        'label' => 'Trustly',
        'is_endowment' => FALSE,
        'vendor_code_foundation' => 'V05354',
        'vendor_code_endowment' => 'V04995',
        'balancing_account_foundation' => '11250',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'GatewayAccount_chariot',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'chariot',
        'label' => 'Chariot',
        'is_endowment' => FALSE,
        'vendor_code_foundation' => 'V05811',
        'vendor_code_endowment' => 'V05002',
        'balancing_account_foundation' => '10951',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'GatewayAccount_checkoutcom',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'checkoutcom',
        'label' => 'Checkout.com',
        'is_endowment' => FALSE,
        'vendor_code_foundation' => 'V06128',
        // No endowment vendor code exists yet.
        'vendor_code_endowment' => NULL,
        'balancing_account_foundation' => '11250',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'GatewayAccount_overflow',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'overflow',
        'label' => 'Overflow',
        'is_endowment' => FALSE,
        'vendor_code_foundation' => 'V05045',
        'vendor_code_endowment' => 'V04996',
        'balancing_account_foundation' => '11250',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'GatewayAccount_overflow_endowment',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'overflowendowment',
        'label' => 'Overflow (Endowment)',
        'is_endowment' => TRUE,
        'vendor_code_foundation' => 'V05045',
        'vendor_code_endowment' => 'V04996',
        'balancing_account_foundation' => '11250',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'GatewayAccount_wikimedia_de',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'wikimediade',
        'label' => 'Wikimedia Deutschland',
        'is_endowment' => FALSE,
        'vendor_code_foundation' => 'V00343',
        'vendor_code_endowment' => 'V04999',
        'balancing_account_foundation' => '11250',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'GatewayAccount_wikimedia_ch',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'wikimediach',
        'label' => 'Wikimedia CH',
        'is_endowment' => FALSE,
        'vendor_code_foundation' => 'V01729',
        'vendor_code_endowment' => 'V05000',
        'balancing_account_foundation' => '11250',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'GatewayAccount_chisholm',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'chisholm',
        'label' => 'Chisholm Chisholm & Kilpatrick',
        'is_endowment' => FALSE,
        'vendor_code_foundation' => 'V04824',
        // No endowment vendor code given yet.
        'vendor_code_endowment' => NULL,
        'balancing_account_foundation' => '11250',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'GatewayAccount_bankofamerica',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'bankofamerica',
        'label' => 'Bank of America',
        'is_endowment' => FALSE,
        'vendor_code_foundation' => 'V06144',
        // No endowment vendor code given yet.
        'vendor_code_endowment' => NULL,
        'balancing_account_foundation' => '11250',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'GatewayAccount_bankofamerica_endowment',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'bankofamericaendowment',
        'label' => 'Bank of America (Endowment)',
        'is_endowment' => TRUE,
        'vendor_code_foundation' => 'V06144',
        // No endowment vendor code given yet.
        'vendor_code_endowment' => NULL,
        'balancing_account_foundation' => '11250',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  // Placeholder entries for direct Wire/ACH transfers - not much detail known yet
  // beyond the name/label/is_endowment split. Fill in vendor/balancing codes once
  // they're confirmed.
  [
    'name' => 'GatewayAccount_bank',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'bank',
        'label' => 'Wire/ACH',
        'is_endowment' => FALSE,
        'balancing_account_foundation' => '11250',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'GatewayAccount_bank_endowment',
    'entity' => 'GatewayAccount',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'bankendowment',
        'label' => 'Wire/ACH (Endowment)',
        'is_endowment' => TRUE,
        'balancing_account_foundation' => '11250',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
];
