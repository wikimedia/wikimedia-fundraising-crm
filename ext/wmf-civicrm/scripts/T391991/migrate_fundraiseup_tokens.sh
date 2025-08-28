#!/bin/bash

# Path to the CSV file
csv_file=$1

set -e

do_update_recur_record() {
    # Access the parameters
    local contribution_recur_id=$1
    local payment_token_id=$2
    local processor_contact_id=$3
    local next_sched_contribution_date=$4

    # Print the parameters (just for demonstration)
    echo "Recur ID: $contribution_recur_id"
    echo "Payment token ID: $payment_token_id"
    echo "processor_contact_id: $processor_contact_id"
    echo "Next sched date: $next_sched_contribution_date"

    wmf-cv api4 ContributionRecur.update +v payment_token_id=$payment_token_id +v next_sched_contribution_date="$next_sched_contribution_date" +v contribution_recur_smashpig.processor_contact_id="$processor_contact_id" +v payment_processor_id.name='gravy' +w "id = $contribution_recur_id"
}

do_create_payment_token_record() {
    local token=$1
    local contact_id=$2
    local payment_processor_name='gravy'

    echo "Payment token: $token" >&2
    echo "Processor name: $payment_processor_name" >&2
    echo "Contact ID: $contact_id" >&2

    echo "Creating payment token" >&2

    local result=$(wmf-cv api4 PaymentToken.create +v contact_id=$contact_id +v payment_processor_id.name=$payment_processor_name +v token=$token 2>/dev/null)

    local token_id=$(echo "$result" | grep -o '"id": [0-9]\+' | sed 's/"id": //')

    if [[ -n "$token_id" ]]; then
        echo "Payment token created" >&2
        echo "$token_id"
        return 0
    else
        echo "Error: Failed to create payment token" >&2
        echo "API Response: $result" >&2
        return 1
    fi
}

echo 'reading file'

# Read the CSV file line by line
while IFS=',' read -r processor_contact_id payment_method_id contact_id contribution_recur_id next_sched_contribution_date; do
    payment_token_id="$(do_create_payment_token_record $payment_method_id $contact_id)"
    do_update_recur_record $contribution_recur_id $payment_token_id $processor_contact_id "$next_sched_contribution_date"
    echo "----------"
done < "$csv_file"