( function ( $ ) {
    var fileType = null;

    $( function() {
        // Disable the submit button until a file is selected for upload
        $( "form#offline2civicrm-import-checks-form input.form-submit" ).attr( "disabled", "disabled" );

        $( "form#offline2civicrm-import-checks-form input.form-file" ).change( function() {
            var uploadFile = $( this ).val(),
                $submitButton = $( "form#offline2civicrm-import-checks-form input.form-submit" );
            if ( uploadFile ) {
                $submitButton.removeAttr( "disabled" );

                if ( /Coinbase|Orders-Report|\(Orders\)/.test( uploadFile ) ) {
                    fileType = "coinbase";
                } else if ( /JPM/.test( uploadFile ) ) {
                    fileType = "jpmorgan";
                } else if ( /Paypal/.test( uploadFile ) ) {
                    fileType = "paypal";
                } else if ( /Organization|Individual/.test( uploadFile ) ) {
                    fileType = "azl";
                } else if ( /Foreign/.test( uploadFile ) ) {
                    fileType = "foreign_checks";
                }

                if ( fileType ) {
                    $( 'form#offline2civicrm-import-checks-form input[name="import_upload_format"][value="' + fileType + '"]' ).attr( "checked", "checked" );
                }
            } else {
                $submitButton.attr( "disabled", "disabled" );
            }
        } );
    } );
} )( jQuery );
