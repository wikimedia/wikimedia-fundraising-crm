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

        foreach (range(2, $max_contribution_count) as $index)
        {
            CRM_Core_DAO::executeQuery("ALTER TABLE {$table} ADD total_amount_{$index} DECIMAL(20,2)");
            CRM_Core_DAO::executeQuery("ALTER TABLE {$table} ADD receive_date_{$index} DATETIME");
            $sqlColumns["total_amount_{$index}"] = "total_amount_{$index}";
            $sqlColumns["receive_date_{$index}"] = "receive_date_{$index}";
            $headerRows[] = "Total Amount {$index}";
            $headerRows[] = "Received {$index}";
        }

        //XXX drop ID columns
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
            $master_row_contribution = array_shift($contributions);
            if (empty($contributions)) {
                continue;
            }

            $set_clauses = array();
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

        foreach (array('civicrm_primary_id', 'contribution_id') as $dropping)
        {
            $column_index = array_search($dropping, array_keys($sqlColumns));
            unset($sqlColumns[$dropping]);
            unset($headerRows[$column_index]);

            $sql = "ALTER TABLE {$table} DROP COLUMN {$dropping}";
            CRM_Core_DAO::executeQuery($sql);
        }
    }
}
