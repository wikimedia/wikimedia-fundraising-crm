#!/bin/bash
# Find IPNs for failed authorizations with a rescue reference where there
# is no rescue scheduled, then strip out all but the rescue reference and date
zgrep '"retry.rescueScheduled":"false"' payments-listener-adyen-20240*.gz \
| grep AUTHORISATION \
| grep '"success":"false"' \
| grep rescueRef \
| sed -e 's/.*rescueReference":"//' -e 's/","merchantAccount.*//' \
-e 's/".*"/\t/' -e 's/\(.*\-[0-9][0-9]\)T/\1 /' -e 's/\+.*//' > ~/refsAndDates.tsv

# then turn it into a SQL script
echo "INSERT INTO T367451 (rescue_reference, date) VALUES" > ~/T367451.sql
sed -e "s/^/('/" -e "s/\t/','/" -e "s/$/'),/"  ~/refsAndDates.tsv >> ~/T367451.sql
# manually delete the last comma
