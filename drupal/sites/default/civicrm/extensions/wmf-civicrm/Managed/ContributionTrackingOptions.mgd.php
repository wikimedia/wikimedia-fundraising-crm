<?php

return [
  [
    'name' => 'contribution_tracking_payment_method',
    'entity' => 'OptionGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'title' => 'Payment Method Family',
        'name' => 'payment_method',
        'description' => 'Payment method (e.g Cash, Card...)',
        'data_type' => 'Integer',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'contribution_tracking_recurring_choice',
    'entity' => 'OptionGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'title' => 'Recurring Choice',
        'name' => 'recurring_choice',
        'description' => 'Reason the recurring option was chosen',
        'data_type' => 'Integer',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'contribution_tracking_device_type',
    'entity' => 'OptionGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'title' => 'Device Type',
        'name' => 'device_type',
        'description' => 'Mobile or Desktop',
        'data_type' => 'Integer',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'contribution_tracking_banner_size',
    'entity' => 'OptionGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'title' => 'Banner Size',
        'name' => 'banner_size',
        'description' => 'Banner Size',
        'data_type' => 'Integer',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'contribution_tracking_opt_in',
    'entity' => 'OptionGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'title' => 'Opt In',
        'name' => 'opt_in',
        'description' => 'Selection made for Opt In',
        'data_type' => 'Integer',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'payment_method_cc',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'payment_method',
        'label' => 'Credit Card',
        'value' => 1,
        'name' => 'cc',
        'description' => 'Credit card',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'payment_method_dd',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'payment_method',
        'label' => 'Direct Debit',
        'value' => 2,
        'name' => 'dd',
        'description' => 'Direct Debit',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'payment_method_bt',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'payment_method',
        'label' => 'Bank Transfer',
        // While we are not exactly syncing with payment_instrument we kinda might as well
        // keep them somewhat in line with the non-specific one in there.
        'value' => 14,
        'name' => 'bt',
        'description' => 'Bank transfer',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'payment_method_cash',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'payment_method',
        'label' => 'Cash',
        'value' => 3,
        'name' => 'cash',
        'description' => 'Cash',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'payment_method_paypal',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'payment_method',
        'label' => 'Paypal',
        'value' => 25,
        'name' => 'paypal',
        'description' => 'Paypal',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'payment_method_obt',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'payment_method',
        'label' => 'Online Bank Transfer',
        // 5 is EFT in payment instrument id - which IS a different group but
        // trying to keep some alignment
        'value' => 5,
        'name' => 'obt',
        'description' => 'Online Bank Transfer',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'payment_method_rtbt',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'payment_method',
        'label' => 'Real-time Bank Transfer',
        // 9 is iDeal in payment instrument list - which is our rtbt in practice
        'value' => 9,
        'name' => 'rtbt',
        'description' => 'Real-time Bank Transfer',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'payment_method_apple',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'payment_method',
        'label' => 'ApplePay',
        'value' => 240,
        'name' => 'apple',
        'description' => 'ApplePay',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'payment_method_google',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'payment_method',
        'label' => 'GooglePay',
        'value' => 243,
        'name' => 'google',
        'description' => 'GooglePay',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'payment_method_amazon',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'payment_method',
        'label' => 'Amazon',
        'value' => 189,
        'name' => 'amazon',
        'description' => 'Amazon',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'payment_method_ew',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'payment_method',
        'label' => 'E-Wallet',
        // 6 is gateway in the payment instrument list - kinda generic
        'value' => 6,
        'name' => 'ew',
        'description' => 'E-Wallet',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'payment_method_venmo',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'payment_method',
        'label' => 'Venmo',
        // 274 is Venmo in payment instrument list on production
        'value' => 274,
        'name' => 'venmo',
        'description' => 'App money transfer',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'recurring_choice_upsell',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'recurring_choice',
        'label' => 'Upsell',
        'value' => 1,
        'name' => 'recurring_choice_upsell',
        'description' => 'Upsell',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'recurring_choice_organic',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'recurring_choice',
        'label' => 'Organic',
        'value' => 2,
        'name' => 'recurring_choice_organic',
        'description' => 'Organic',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'device_type_desktop',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'device_type',
        'label' => 'Desktop',
        'value' => 1,
        'name' => 'device_type_desktop',
        'description' => 'Desktop',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'device_type_mobile',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'device_type',
        'label' => 'Mobile',
        'value' => 2,
        'name' => 'device_type_mobile',
        'description' => 'Mobile',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'banner_size_large',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'banner_size',
        'label' => 'Large',
        'value' => 1,
        'name' => 'banner_size_large',
        'description' => 'Large',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'banner_size_small',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'banner_size',
        'label' => 'Small',
        'value' => 2,
        'name' => 'banner_size_small',
        'description' => 'Small',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'banner_size_unknown',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'banner_size',
        'label' => 'Not specified',
        'value' => 3,
        'name' => 'banner_size_unknown',
        'description' => 'Size is Unknown',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'opt_in_yes',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'opt_in',
        'label' => 'Yes',
        'value' => 1,
        'name' => 'opt_in_yes',
        'description' => 'User opted in',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'opt_in_no',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'opt_in',
        'label' => 'No',
        'value' => 2,
        'name' => 'opt_in_no',
        'description' => 'User chose not to opt in on form',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'opt_in_none',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'opt_in',
        'label' => 'Not offered',
        'value' => 3,
        'name' => 'opt_in_none',
        'description' => 'Opt in option was not presented (generally based on locale data)',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
];
