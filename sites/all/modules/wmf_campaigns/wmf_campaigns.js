( function ( $ ) {
    $( function() {
        $( '#edit-notification-email' ).change( function() {
            console.debug($(this).val());
            $( '#edit-notification-enabled' ).attr( 'checked',
                $(this).val() !== ''
            );
        } );
    } );
} )( jQuery );
