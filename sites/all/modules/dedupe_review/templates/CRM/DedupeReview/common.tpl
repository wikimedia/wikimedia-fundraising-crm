{literal}
<style type="text/css" media="screen">
#donor_review_table.touch-table td.revert ins {
    text-decoration: line-through;
    color: #e43d2b;
}
#donor_review_table.touch-table td.revert del {
    color: black !important;
    text-decoration: none;
}
#donor_review_table.touch-table td.revert {
    /* red-green sadness */
    background-color: #99ff99;
}

#donor_review_table.touch-table tr.rereview {
    background-color: #ff9999;
}

#donor_review_table.touch-table tr.exclude td:not(.buttons) {
    opacity: 0.25;
}

#donor_review_table.touch-table td.buttons {
    white-space: nowrap;
}
#donor_review_table.touch-table td.buttons input {
    margin: 2px;
}

.loading {
    background-image: url("{/literal}{crmResURL ext="civicrm" file="i/loading.gif"}{literal}");
    background-repeat: no-repeat;
    background-position: right top;
}
.loading td {
    /* The tr style prevents a spinner, so we provide alternative feedback. */
    font-style: oblique;
}
</style>

<script language="javascript">
( function ( $ ) {
    rowAction = function( trObj, action ) {
        sendChange( trObj, action );

        if ( action === "include" ) {
            trObj.toggleClass( "exclude", false );
            trObj.toggleClass( "rereview", false );
        } else if ( action === "exclude" ) {
            trObj.toggleClass( "exclude", true );
        } else if ( action === "rereview" ) {
            trObj.toggleClass( "exclude", true );
            trObj.toggleClass( "rereview", true );
        }
    };

    sendChange = function( obj, mode ) {
        var xhr = obj.data( "xhr" );

        if ( xhr ) {
            // Abort stale request
            xhr.abort();
        }

        obj.toggleClass( "loading", true );
        xhr = $.ajax( {
            data: {
                "item": obj.attr( "id" ),
                "operation": mode
            },
            dataType: "json",
            url: CRM.url( "civicrm/dedupe_review/ajax" ),
            error: function( event, status, error ) {
                obj.toggleClass( "loading", false );
                netFailure();
                obj.data( "xhr", null );
            },
            success: function( data, status, event ) {
                obj.toggleClass( "loading", false );
                obj.data( "xhr", null );
                if ( !data.success || data.success !== true ) {
                    netFailure();
                }
            }
        } );
        obj.data( "xhr", xhr );
    };

    netFailure = function() {
        //TODO: overlay network failure warning
        alert("Cannot make background calls to Civi!");
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
        $( ".touch-table .buttons input" ).change( function() {
            var input = $( this ),
                row = $( this ).closest( "tr" );

            if ( input.attr( "checked" ) ) {
                rowAction( row, input.val() );
            }
        } );
        $( ".touch-table td.diff" ).click( function( event ) {
            cycleCell( $( this ) );
        } );
    } );
} )( cj );
</script>
{/literal}
