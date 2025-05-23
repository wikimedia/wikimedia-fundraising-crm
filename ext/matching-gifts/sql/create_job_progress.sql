CREATE TABLE IF NOT EXISTS civicrm_matching_gift_job_progress (
    job_id int not null,
    company_id varchar(127) not null,
    processed tinyint default 0,
    unique (job_id, company_id)
);
