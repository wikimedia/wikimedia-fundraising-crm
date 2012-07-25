<?php

class CRM_Contact_ContactsAndContributionsExport
{
    static function alterExport(&$table, &$headerRows, &$sqlColumns, &$exportMode)
    {
        require_once 'CRM/Core/DAO.php';

        $sql = <<<EOS
CREATE TEMPORARY TABLE {$table}_rollup (
    contact_id INT UNSIGNED,
    rollup TEXT,
    count INT UNSIGNED
);
EOS;
        CRM_Core_DAO::executeQuery($sql);

        $sql = <<<EOS
INSERT INTO {$table}_rollup (
    SELECT
        civicrm_primary_id AS contact_id,
        GROUP_CONCAT(CONCAT(contribution_id,',',total_amount,',',COALESCE(receive_date, '')) SEPARATOR ';') AS rollup,
        COUNT(*) AS count
    FROM {$table}
    GROUP BY civicrm_primary_id
    ORDER BY civicrm_primary_id, receive_date DESC
)
EOS;
        CRM_Core_DAO::executeQuery($sql);

        $sql = <<<EOS
SELECT MAX(count) FROM {$table}_rollup
EOS;
        $max_contribution_count = CRM_Core_DAO::singleValueQuery($sql);

        $alter_columns = array();
        foreach (range(2, $max_contribution_count) as $index)
        {
            $alter_columns += array(
                "total_amount_{$index}" => array('type' => "DECIMAL(20,2)"),
                "receive_date_{$index}" => array('label' => "Received {$index}", 'type' => "DATETIME"),
            );
        }

        $alter_columns += array(
            'lybunt' => array('label' => 'LYBUNT', 'type' => 'DECIMAL(20,2)'),
            'sybunt' => array('label' => 'SYBUNT', 'type' => 'DECIMAL(20,2)'),
            'notes' => array('type' => 'TEXT'),
            'groups' => array('type' => 'TEXT'),
            'relationships' => array('type' => 'TEXT'),
            'activities' => array('type' => 'TEXT'),
        );
        foreach ($alter_columns as $name => $desc)
        {
            $sql = <<<EOS
ALTER TABLE {$table} ADD {$name} {$desc['type']}
EOS;
            CRM_Core_DAO::executeQuery($sql);
            $sqlColumns[$name] = $name;
            if (!empty($desc['label'])) {
                $label = $desc['label'];
            } else {
                $label = ucfirst(strtr($name, '_', ' '));
            }
            $headerRows[] = $label;
        }

        //TODO add index on contact_id and contribution_id if
        // export jobs are huge

        $sql = "SELECT * FROM {$table}_rollup";
        $dao = CRM_Core_DAO::executeQuery($sql);
        $delete_ids = array();
        while ($dao->fetch())
        {
            $contribution_strs = explode(';', $dao->rollup);
            $contributions = array();
            foreach ($contribution_strs as $str)
            {
                $contributions[] = explode(',', $str);
            }

            $set_clauses = array();

            list ($lybunt, $sybunt) = self::calc_bunts($contributions);
            $set_clauses[] = "lybunt = {$lybunt}";
            $set_clauses[] = "sybunt = {$sybunt}";

            $master_row_contribution = array_shift($contributions);
            if (empty($contributions)) {
                continue;
            }

            $row_index = 2;
            $params_index = 1;
            foreach ($contributions as $contribution)
            {
                $delete_ids[] = $contribution[0];
                $set_clauses[] = "total_amount_{$row_index} = %{$params_index}";
                $params[$params_index++] = array($contribution[1], 'String');
                $set_clauses[] = "receive_date_{$row_index} = %{$params_index}";
                $params[$params_index++] = array($contribution[2], 'String');
                $row_index++;
            }

            $set_clause = implode(", ", $set_clauses);
            $sql = <<<EOS
UPDATE {$table}
    SET {$set_clause}
    WHERE contribution_id = {$master_row_contribution[0]}
EOS;
            CRM_Core_DAO::executeQuery($sql, $params);
        }

        $delete_ids_clause = implode(", ", $delete_ids);
        $sql = <<<EOS
DELETE FROM {$table}
    WHERE contribution_id IN ({$delete_ids_clause})
EOS;
        CRM_Core_DAO::executeQuery($sql);

        $sql = <<<EOS
UPDATE {$table}
    SET notes = (
        SELECT GROUP_CONCAT(CONCAT(subject, ': ', note) SEPARATOR '\n\n')
            FROM civicrm_note
            WHERE civicrm_note.contact_id = {$table}.civicrm_primary_id
            GROUP BY {$table}.civicrm_primary_id
            ORDER BY civicrm_note.id
    )
EOS;
        CRM_Core_DAO::executeQuery($sql);

        $sql = <<<EOS
UPDATE {$table}
    SET groups = (
        SELECT GROUP_CONCAT(civicrm_group.title SEPARATOR ', ')
            FROM civicrm_group
            JOIN civicrm_group_contact
                ON civicrm_group_contact.group_id = civicrm_group.id
            WHERE
                civicrm_group_contact.contact_id = {$table}.civicrm_primary_id
                AND civicrm_group_contact.status = 'Added'
            GROUP BY {$table}.civicrm_primary_id
            ORDER BY civicrm_group.title
    )
EOS;
        CRM_Core_DAO::executeQuery($sql);

        $sql = <<<EOS
UPDATE {$table}
    SET relationships = (
        SELECT
            GROUP_CONCAT(
                CONCAT(civicrm_relationship_type.label_a_b, ' ', target_contact.display_name)
                SEPARATOR ', '
            )
            FROM civicrm_relationship related_ab
            JOIN civicrm_relationship_type
                ON civicrm_relationship_type.id = related_ab.relationship_type_id
            JOIN civicrm_contact target_contact
                ON target_contact.id = related_ab.contact_id_b
            WHERE
                related_ab.contact_id_a = {$table}.civicrm_primary_id
            GROUP BY {$table}.civicrm_primary_id
    )
EOS;
        CRM_Core_DAO::executeQuery($sql);

        $sql = <<<EOS
UPDATE {$table}
    SET relationships = CONCAT_WS(', ', relationships, (
        SELECT
            GROUP_CONCAT(
                CONCAT(civicrm_relationship_type.label_b_a, ' ', target_contact.display_name)
                SEPARATOR ', '
            )
            FROM civicrm_relationship related_ba
            JOIN civicrm_relationship_type
                ON civicrm_relationship_type.id = related_ba.relationship_type_id
            JOIN civicrm_contact target_contact
                ON target_contact.id = related_ba.contact_id_a
            WHERE
                related_ba.contact_id_b = {$table}.civicrm_primary_id
            GROUP BY {$table}.civicrm_primary_id
    ))
EOS;
        CRM_Core_DAO::executeQuery($sql);

        //XXX there are a ton of other funky ways a contact can be related to
        // an activity.  Check whether we need to report on those as well.
        $sql = <<<EOS
UPDATE {$table}
    SET activities = (
        SELECT
            GROUP_CONCAT(
                CONCAT(activity_type.label, ' on ', civicrm_activity.activity_date_time)
                SEPARATOR ', '
            )
            FROM civicrm_activity
            JOIN civicrm_option_group
            JOIN civicrm_option_value activity_type
                ON activity_type.value = civicrm_activity.activity_type_id
                AND activity_type.option_group_id = civicrm_option_group.id
            WHERE
                civicrm_activity.source_contact_id = {$table}.civicrm_primary_id
                AND civicrm_option_group.name = 'activity_type'
            GROUP BY {$table}.civicrm_primary_id
    )
EOS;
        CRM_Core_DAO::executeQuery($sql);

        $drop_columns = array(
            'civicrm_primary_id',
            'contribution_id'
        );
        foreach ($drop_columns as $dropping)
        {
            $column_index = array_search($dropping, array_keys($sqlColumns));
            unset($sqlColumns[$dropping]);
            unset($headerRows[$column_index]);

            $sql = "ALTER TABLE {$table} DROP COLUMN {$dropping}";
            CRM_Core_DAO::executeQuery($sql);
        }
    }

    static function calc_bunts($contributions)
    {
        $config = CRM_Core_Config::singleton();
        $fy = $config->fiscalYearStart;
        $fy_month = $fy['M'] - 1;
        $fy_day = $fy['d'] - 1;
        $current_year = date("Y");
        $previous_year = date("Y", strtotime("-1 year"));
        $lybunt = 0;
        $sybunt = 0;

        foreach ($contributions as $row)
        {
            $date = new DateTime("{$row[2]} -{$fy_month} month {$fy_day} day");
            $year = $date->format("Y");
            if ($year == $current_year) {
                $is_this_year = TRUE;
            } else if ($year == $previous_year) {
                $is_previous_year = TRUE;
            } else {
                $is_any_other_year = TRUE;
            }
        }

        if (!$is_this_year) {
            if ($is_previous_year) {
                $lybunt = 1;
            }
            if ($is_any_other_year) {
                $sybunt = 1;
            }
        }
        return array($lybunt, $sybunt);
    }
}
