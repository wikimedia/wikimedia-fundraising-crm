for i in {1..5}
do
  cut -d, -f3,4,14 ensr4525_6570_$i*csv \
    | sed -e '/^,,$/d' \
    | sed -e '1d' \
    | sed -e "s/,/','/g" \
    | sed -e "s/^/INSERT INTO T344645 (invoice_id, adyen_token, ingenico_token) VALUES ('/" \
    | sed -e "s/$/');/" >> inserts.sql
done
# ~/T344645$ wc -l inserts.sql
# 4076286 inserts.sql
# (That was from the 'a' files, though it seems too high)
# For the 'b' files we get
# les
