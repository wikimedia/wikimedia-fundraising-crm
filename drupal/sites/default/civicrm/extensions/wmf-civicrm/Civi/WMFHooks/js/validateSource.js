  cj("#total_amount").change(function() {
    var totalAmount = parseFloat((cj("#total_amount").val() ? cj("#total_amount").val().replace(',', '') : 0)).toFixed(2);
    var existingSource = cj("#source").val() ? cj("#source").val() : '';
    var existingCurrency = existingSource.match(/[A-Z]*/).toString();
    if (existingCurrency.length !== 3) {
      existingCurrency = 'USD';
    }
    if (existingCurrency === 'USD') {
      // Overwrite is the currency has not been changed to non USD
      // This leaves unchanged in that case - but we could arguably
      // wipe out total amount in this case.
      cj('#source').val(existingCurrency + ' ' + totalAmount);
    }
  });
