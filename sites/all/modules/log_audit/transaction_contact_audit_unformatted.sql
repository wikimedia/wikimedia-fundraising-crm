

select 

substring(contribution_address.trxn_id,18,17) as trxn_id,

from

(select
civicrm.civicrm_contribution.total_amount, 
civicrm.civicrm_contribution.trxn_id, 
civicrm.civicrm_contribution.contact_id as contribution_contact_id, 
civicrm.civicrm_address.contact_id as address_contact_id,
civicrm.civicrm_address.country_id as country_id 

from civicrm.civicrm_contribution left join civicrm.civicrm_address on civicrm.civicrm_contribution.contact_id = civicrm.civicrm_address.contact_id

where DATE_FORMAT(receive_date, '%sY%sm%sd%sH%si%ss') >= '%s' and DATE_FORMAT(receive_date, '%sY%sm%sd%sH%si%ss') < '%s'
and civicrm.civicrm_contribution.trxn_id REGEXP 'RECURRING PAYPAL'
) as contribution_address


where contribution_address.country_id is NULL;