<?php

/**
 * DAOs provide an OOP-style facade for reading and writing database records.
 *
 * DAOs are a primary source for metadata in older versions of CiviCRM (<5.74)
 * and are required for some subsystems (such as APIv3).
 *
 * This stub provides compatibility. It is not intended to be modified in a
 * substantive way. Property annotations may be added, but are not required.
 * @property string $id
 * @property string $mailing_provider
 * @property string $job
 * @property string $job_identifier
 * @property string $last_timestamp
 * @property string $progress_end_timestamp
 * @property string $retrieval_parameters
 * @property string $offset
 * @property string $created_date
 */
class CRM_Omnimail_DAO_OmnimailJobProgress extends CRM_Omnimail_DAO_Base {

}
