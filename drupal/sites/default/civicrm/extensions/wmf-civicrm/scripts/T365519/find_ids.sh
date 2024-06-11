zgrep '"success":"false"' payments-listener-adyen-20240[56]*.gz \
| grep 'AUTHORISATION' \
| grep -o '"pspReference":"................"' \
| cut -d'"' -f4 \
| sort | uniq > ~/T365519/allFailedPspIds

zgrep '"success":"true"' payments-listener-adyen-20240[56]*.gz \
| grep 'AUTHORISATION' \
| grep -o '"pspReference":"................"' \
| cut -d'"' -f4 \
| sort | uniq > ~/T365519/allSucceededPspIds
# check to see if any failed, then succeeded:

grep -F -f allFailedPspIds allSucceededPspIds > idsinbothlists
# Just 1 id! Manually delete it from allFailedPspIds

echo "INSERT INTO T365519 (gateway_txn_id) VALUES" > inserts.sql

sed -e "s/^/('/" -e "s/$/'),/" allFailedPspIds >> inserts.sql
# manually delete the last comma
