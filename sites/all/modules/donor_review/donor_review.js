( function ( $ ) {
    rowAction = function( trObj, action ) {
        if ( action === "confirm" ) {
            trObj.toggleClass( "exclude", false );
            trObj.toggleClass( "rereview", false );
        } else if ( action === "exclude" ) {
            trObj.toggleClass( "exclude", true );
        } else if ( action === "rereview" ) {
            trObj.toggleClass( "exclude", true );
            trObj.toggleClass( "rereview", true );
        }
        sendChange( trObj, action );
    };

    sendChange = function( obj, mode ) {
        var xhr = obj.data( "xhr" );

        if ( xhr ) {
            // Abort stale request
            xhr.abort();
        }

        obj.toggleClass( "loading", true );
        xhr = $.ajax( {
            url: "/admin/donor_review/ajax/" + obj.attr( "id" ) + "/" + mode,
            error: function( event ) {
                obj.toggleClass( "loading", false );
                // TODO: network failure overlay
                obj.data( "xhr", null );
            },
            success: function( event ) {
                //TODO: check response object to confirm success
                obj.toggleClass( "loading", false );
                obj.data( "xhr", null );
            }
        } );
        obj.data( "xhr", xhr );
    };
            
    cycleCell = function( tdObj ) {
        if ( tdObj.is( ".revert" ) ) {
            tdObj.toggleClass( "revert", false );
            sendChange( tdObj, "update" );
        } else {
            tdObj.toggleClass( "revert", true );
            sendChange( tdObj, "revert" );
        }
    };

    $( function() {
        $( ".touch-table .buttons input" ).click( function( event ) {
            event.preventDefault();
            rowAction( $( this ).closest( "tr" ), $( this ).attr( "name" ) );
        } );
        $( ".touch-table td.diff" ).click( function( event ) {
            cycleCell( $( this ) );
        } );
    } );
} )( jQuery );
